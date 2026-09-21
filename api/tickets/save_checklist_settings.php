<?php
/**
 * API Endpoint: save the SOP-checklist closure mode (PR #141).
 *
 * POST { mode: "warn" | "block" }
 *
 * Install-wide for now. The value is read through tenantSetting(), which already
 * resolves a per-company override over this default, so a Companies column can
 * be added to the tab later without touching the reading side or migrating
 * anything — the same shape the three classification switches use.
 *
 * ⚠️ This decides whether closing with outstanding mandatory steps is ALLOWED.
 * It never decides whether it is recorded: ChecklistsService writes the override
 * note either way, so turning this to "warn" loosens the gate and loses no audit.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/tenant_settings.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');
requireCapabilityJson(Cap::TICKETS_CHECKLISTS);

$in   = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$mode = (string)($in['mode'] ?? '');

if ($mode !== 'per_template' && $mode !== 'block_all') {
    echo json_encode(['success' => false, 'error' => 'Mode must be "per_template" or "block_all".']);
    exit;
}

try {
    $conn = connectToDatabase();
    // setting_key is the PRIMARY KEY of system_settings, so the upsert is safe.
    $conn->prepare(
        "INSERT INTO system_settings (setting_key, setting_value, updated_datetime)
              VALUES (?, ?, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                                 updated_datetime = UTC_TIMESTAMP()"
    )->execute([SETTING_TICKET_CHECKLIST_CLOSURE, $mode]);

    // Read it back rather than echoing the input: the stored value is what the
    // next page load will show, and the two must not be able to disagree.
    echo json_encode(['success' => true, 'mode' => ticketChecklistClosureMode($conn, null)]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
