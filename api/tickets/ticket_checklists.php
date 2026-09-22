<?php
/**
 * API: Ticket Checklists / SOP
 * Adheres to FreeITSM standard naming (created_datetime, completed_datetime, completed_by_id, completed_by_name).
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . "/../../config.php";
require_once __DIR__ . "/../../includes/functions.php";
require_once __DIR__ . "/../../includes/tenant_settings.php";
require_once __DIR__ . "/../../includes/services/checklists.php";

$conn = connectToDatabase();

header("Content-Type: application/json");

if (!isset($_SESSION["analyst_id"])) {
    echo json_encode(["success" => false, "error" => "Not authenticated"]);
    exit;
}

$analystId = (int)$_SESSION["analyst_id"];
$analystName = $_SESSION["analyst_name"] ?? ($_SESSION["username"] ?? "Analyst");
$action = $_GET["action"] ?? ($_POST["action"] ?? "");

if (!$action) {
    $raw = file_get_contents("php://input");
    if ($raw) {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $action = $json["action"] ?? "";
            $_POST = array_merge($_POST, $json);
        }
    }
}

try {
    switch ($action) {
        case "get_ticket_checklists":
            $ticketId = (int)($_GET["ticket_id"] ?? 0);
            if ($ticketId <= 0) throw new Exception("ticket_id is required");

            // 🔴 These used to read COALESCE(created_datetime, created_at). `created_at`
            // is not created by the module bootstrap, by database/freeitsm.sql, or by
            // includes/db_verify_schema.php - so on any install that did not grow from
            // the author's earlier naming, MySQL raised "Unknown column 'created_at'"
            // and this endpoint returned an error for every ticket. See the wiki:
            // Checklists-Module-House-Style, "the fallback to a column that never existed".
            require_once __DIR__ . "/../../includes/tenant_settings.php";
            require_once __DIR__ . "/../../includes/services/checklists.php";

            // Resolve the ticket's tenant so company policy overrides propagate
            $tktTenantStmt = $conn->prepare("SELECT tenant_id FROM tickets WHERE id = ? LIMIT 1");
            $tktTenantStmt->execute([$ticketId]);
            $rawTenant = $tktTenantStmt->fetchColumn();
            $ticketTenantId = ($rawTenant !== false && $rawTenant !== null) ? (int)$rawTenant : null;

            $stmt = $conn->prepare("SELECT id, template_id, title, closure_mode, created_datetime
                                    FROM ticket_checklists
                                    WHERE ticket_id = ?
                                    ORDER BY id ASC");
            $stmt->execute([$ticketId]);
            $checklists = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($checklists as &$chk) {
                $chk["closure_mode"] = $chk["closure_mode"] ?? "warn";
                $chk["effective_closure_mode"] = ChecklistsService::effectiveClosureMode($chk["closure_mode"], $conn, $ticketTenantId);
            }
            unset($chk);

            foreach ($checklists as &$chk) {
                // Same fix as above: completed_by and completed_at never existed either.
                $itemStmt = $conn->prepare("SELECT id, title, suggested_role, is_mandatory, requires_input, input_placeholder,
                                                   response_value, is_completed,
                                                   completed_by_id, completed_by_name, completed_datetime
                                             FROM ticket_checklist_items
                                             WHERE ticket_checklist_id = ?
                                             ORDER BY sort_order ASC, id ASC");
                $itemStmt->execute([(int)$chk["id"]]);
                $chk["items"] = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

                $total = count($chk["items"]);
                $done = 0;
                foreach ($chk["items"] as $it) {
                    if (!empty($it["is_completed"])) $done++;
                }
                $chk["total_items"] = $total;
                $chk["completed_items"] = $done;
                $chk["percent"] = $total > 0 ? round(($done / $total) * 100) : 0;
            }

            echo json_encode(["success" => true, "checklists" => $checklists, "empty_closure_mode" => ticketChecklistEmptyClosureMode($conn, $ticketTenantId ?? ($tenantId ?? null))]);
            exit;

        case "suggest_template":
            $ticketId = (int)($_GET["ticket_id"] ?? 0);
            if ($ticketId <= 0) throw new Exception("ticket_id required");

            $tStmt = $conn->prepare("SELECT id, subject FROM tickets WHERE id = ? LIMIT 1");
            $tStmt->execute([$ticketId]);
            $ticket = $tStmt->fetch(PDO::FETCH_ASSOC);
            if (!$ticket) throw new Exception("Ticket not found");

            $notesStmt = $conn->prepare("SELECT note_text FROM ticket_notes WHERE ticket_id = ? ORDER BY id ASC LIMIT 5");
            $notesStmt->execute([$ticketId]);
            $notes = $notesStmt->fetchAll(PDO::FETCH_COLUMN);

            $ticketText = strtolower(($ticket['subject'] ?? '') . ' ' . strip_tags(implode(' ', $notes)));

            $tpls = $conn->query("SELECT id, title, category, description, keywords FROM checklist_templates WHERE scope IN ('ticket', 'both') AND (is_active = 1 OR is_active IS NULL)")->fetchAll(PDO::FETCH_ASSOC);

            $scored = [];

            foreach ($tpls as $tpl) {
                $score = 0;
                $titleLower = strtolower($tpl['title']);
                $hasTitleMatch = false;
                $matchedKeywordsCount = 0;

                if (strpos($ticketText, $titleLower) !== false) {
                    $score += 5;
                    $hasTitleMatch = true;
                }

                if (!empty($tpl['keywords'])) {
                    $kwList = array_map('trim', explode(',', strtolower($tpl['keywords'])));
                    foreach ($kwList as $kw) {
                        if ($kw !== '' && strpos($ticketText, $kw) !== false) {
                            $score += 2;
                            $matchedKeywordsCount++;
                        }
                    }
                }

                if ($score >= 2) {
                    // Calculate intuitive Confidence %
                    if ($hasTitleMatch && $matchedKeywordsCount >= 1) {
                        $confidence = min(98, 85 + ($matchedKeywordsCount * 4));
                    } elseif ($hasTitleMatch) {
                        $confidence = 90;
                    } elseif ($matchedKeywordsCount >= 3) {
                        $confidence = 88;
                    } elseif ($matchedKeywordsCount == 2) {
                        $confidence = 80;
                    } else {
                        $confidence = 72;
                    }

                    $tpl['match_score'] = $score;
                    $tpl['confidence_percent'] = $confidence;
                    $scored[] = $tpl;
                }
            }

            usort($scored, function($a, $b) {
                return $b['confidence_percent'] <=> $a['confidence_percent'];
            });

            $topSuggestions = array_slice($scored, 0, 3);

            echo json_encode([
                "success" => true,
                "suggested" => !empty($topSuggestions) ? $topSuggestions[0] : null,
                "suggestions" => $topSuggestions
            ]);
            exit;

        case "list_templates_for_ticket":
            $ticketId = (int)($_GET["ticket_id"] ?? $_POST["ticket_id"] ?? 0);
            $tenantId = null;
            if ($ticketId > 0) {
                $tktTenantStmt = $conn->prepare("SELECT tenant_id FROM tickets WHERE id = ? LIMIT 1");
                $tktTenantStmt->execute([$ticketId]);
                $rawTenant = $tktTenantStmt->fetchColumn();
                $tenantId = ($rawTenant !== false && $rawTenant !== null) ? (int)$rawTenant : null;
            }

            $stmt = $conn->query("SELECT id, title, category, description, keywords, closure_mode FROM checklist_templates WHERE scope IN ('ticket', 'both') AND (is_active = 1 OR is_active IS NULL) ORDER BY category ASC, title ASC");
            $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($templates as &$tpl) {
                $tpl["closure_mode"] = $tpl["closure_mode"] ?? "warn";
                $tpl["effective_closure_mode"] = ChecklistsService::effectiveClosureMode($tpl["closure_mode"], $conn, $tenantId);
            }
            unset($tpl);

            echo json_encode(["success" => true, "templates" => $templates]);
            exit;

        case "attach_template":
            $ticketId = (int)($_POST["ticket_id"] ?? 0);
            $templateId = (int)($_POST["template_id"] ?? 0);
            if ($ticketId <= 0 || $templateId <= 0) throw new Exception("ticket_id and template_id required");

            $tplStmt = $conn->prepare("SELECT title, closure_mode FROM checklist_templates WHERE id = ?");
            $tplStmt->execute([$templateId]);
            $tpl = $tplStmt->fetch(PDO::FETCH_ASSOC);
            if (!$tpl) throw new Exception("Template not found");

            $closureMode = (($tpl["closure_mode"] ?? "") === "block") ? "block" : "warn";
            $ins = $conn->prepare("INSERT INTO ticket_checklists (ticket_id, template_id, title, closure_mode, created_by_id, created_datetime) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())");
            $ins->execute([$ticketId, $templateId, $tpl["title"], $closureMode, $analystId]);
            $chkId = $conn->lastInsertId();

            $itemsStmt = $conn->prepare("SELECT title, suggested_role, is_mandatory, requires_input, input_placeholder, sort_order FROM checklist_template_items WHERE template_id = ? ORDER BY sort_order ASC, id ASC");
            $itemsStmt->execute([$templateId]);
            $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

            $insItem = $conn->prepare("INSERT INTO ticket_checklist_items (ticket_checklist_id, title, suggested_role, is_mandatory, requires_input, input_placeholder, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
            foreach ($items as $idx => $item) {
                $insItem->execute([
                    $chkId,
                    $item["title"],
                    $item['suggested_role'] ?? null,
                    !empty($item["is_mandatory"]) ? 1 : 0,
                    !empty($item["requires_input"]) ? 1 : 0,
                    $item["input_placeholder"] ?? null,
                    $item["sort_order"] ?? ($idx + 1)
                ]);
            }

            echo json_encode(["success" => true, "checklist_id" => $chkId]);
            exit;

        case "toggle_item":
            $itemId = (int)($_POST["item_id"] ?? 0);
            $completed = (!empty($_POST["completed"]) || !empty($_POST["is_completed"])) ? 1 : 0;
            $responseValue = isset($_POST["response_value"]) ? trim((string)$_POST["response_value"]) : null;

            if ($itemId <= 0) throw new Exception("item_id is required");

            // 🔴 UTC at rest (GH #126). date() renders the SERVER's wall clock, so a
            // step ticked at 09:00 in London was stored as 09:00 and then displayed
            // through the viewer's timezone offset a second time. Every other write
            // in api/tickets/ uses UTC_TIMESTAMP() - 54 of them against a single NOW().
            if ($completed) {
                $stmt = $conn->prepare("UPDATE ticket_checklist_items
                                        SET is_completed      = 1,
                                            response_value    = ?,
                                            completed_by_id   = ?,
                                            completed_by_name = ?,
                                            completed_datetime = UTC_TIMESTAMP()
                                        WHERE id = ?");
                $stmt->execute([$responseValue, $analystId, $analystName, $itemId]);
            } else {
                $stmt = $conn->prepare("UPDATE ticket_checklist_items
                                        SET is_completed      = 0,
                                            response_value    = NULL,
                                            completed_by_id   = NULL,
                                            completed_by_name = NULL,
                                            completed_datetime = NULL
                                        WHERE id = ?");
                $stmt->execute([$itemId]);
            }

            // Read the stored value back rather than echoing what we think we wrote:
            // the column is the single source of truth for what the next GET will show.
            $back = $conn->prepare("SELECT completed_datetime FROM ticket_checklist_items WHERE id = ?");
            $back->execute([$itemId]);
            $storedAt = $back->fetchColumn() ?: null;

            echo json_encode([
                "success"            => true,
                "is_completed"       => $completed,
                "response_value"     => $responseValue,
                "completed_by_id"    => $completed ? $analystId : null,
                "completed_by_name"  => $completed ? $analystName : null,
                "completed_datetime" => $storedAt,
            ]);
            exit;

        case "remove_checklist":
            $chkId = (int)($_POST["checklist_id"] ?? 0);
            if ($chkId <= 0) throw new Exception("checklist_id is required");

            // 🔴 Remove the children EXPLICITLY. There is no foreign key on
            // ticket_checklist_items.ticket_checklist_id - none of the three schema
            // definitions declares one, and tables created by Database Verification
            // never get FKs at all, so a cascade cannot be relied on even where the
            // fresh-install dump would have provided one. Deleting only the parent
            // left the steps behind forever; two runs of the review harness stranded
            // twelve rows. House rule: Database-Integrity, "delete children yourself".
            $conn->beginTransaction();
            try {
                $delItems = $conn->prepare("DELETE FROM ticket_checklist_items WHERE ticket_checklist_id = ?");
                $delItems->execute([$chkId]);
                $del = $conn->prepare("DELETE FROM ticket_checklists WHERE id = ?");
                $del->execute([$chkId]);
                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollBack();
                throw $e;
            }
            echo json_encode(["success" => true]);
            exit;

        default:
            echo json_encode(["success" => false, "error" => "Unknown action: " . $action]);
            exit;
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
    exit;
}
