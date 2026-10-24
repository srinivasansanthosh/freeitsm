<?php
/**
 * Self-service outbound delivery tracking.
 * GET lists only shipments assigned to the signed-in portal user.
 * POST {action: acknowledge|return_acknowledge|report_issue|report_return_issue, shipment_id, issue?} records that user's action.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/service_context.php';
require_once '../../includes/services/asset_logistics.php';
header('Content-Type: application/json; charset=utf-8');

$userId = (int)($_SESSION['ss_user_id'] ?? 0);
if ($userId < 1) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
try {
    $conn = connectToDatabase();
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'GET') {
        echo json_encode(['success' => true] + AssetLogisticsService::listPortalShipments($conn, $userId));
        exit;
    }
    if ($method !== 'POST') {
        http_response_code(405);
        header('Allow: GET, POST');
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) throw new ServiceError('bad_request', 'invalid_json', 'Request body must be a JSON object.');
    $shipmentId = (int)($input['shipment_id'] ?? 0);
    $action = trim((string)($input['action'] ?? ''));
    $issue = isset($input['issue']) ? (string)$input['issue'] : null;
    $result = AssetLogisticsService::portalShipmentAction($conn, $userId, $shipmentId, $action, $issue);
    echo json_encode(['success' => true] + $result);
} catch (ServiceError $e) {
    $status = serviceErrorHttpStatus($e->kind);
    http_response_code($status);
    echo json_encode(['success' => false, 'code' => $e->errorCode, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Self-service asset delivery error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'code' => 'internal_error', 'error' => 'The delivery action could not be completed.']);
}
