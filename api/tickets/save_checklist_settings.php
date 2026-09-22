<?php
/**
 * API Endpoint: save the SOP-checklist closure mode (PR #141) and empty checklist closure mode.
 *
 * POST { mode: "per_template" | "block_all", empty_mode: "off" | "warn" | "block" }
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
$emptyMode = (string)($in['empty_mode'] ?? 'off');

if ($mode !== 'per_template' && $mode !== 'block_all') {
    echo json_encode(['success' => false, 'error' => 'Mode must be "per_template" or "block_all".']);
    exit;
}
if (!in_array($emptyMode, ['off', 'warn', 'block'], true)) {
    $emptyMode = 'off';
}

try {
    $conn = connectToDatabase();

    $upsert = $conn->prepare(
        "INSERT INTO system_settings (setting_key, setting_value, updated_datetime) 
              VALUES (?, ?, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                                 updated_datetime = UTC_TIMESTAMP()"
    );
    $upsert->execute([SETTING_TICKET_CHECKLIST_CLOSURE, $mode]);
    $upsert->execute([SETTING_TICKET_CHECKLIST_EMPTY_CLOSURE, $emptyMode]);

    echo json_encode([
        'success'    => true,
        'mode'       => ticketChecklistClosureMode($conn, null),
        'empty_mode' => ticketChecklistEmptyClosureMode($conn, null)
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
