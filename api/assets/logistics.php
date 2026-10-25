<?php
/**
 * Asset Logistics JSON adapter. Business rules and writes live in AssetLogisticsService.
 * POST actions: create_movement, split_shipment, update_shipment, transition_shipment,
 * set_shipment_cost_centre, record_receipt_outcomes. GET with movement_id returns one tenant-scoped movement.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/capabilities.php';
require_once '../../includes/service_context.php';
require_once '../../includes/services/asset_logistics.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['analyst_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('assets');
$conn = connectToDatabase();
$ctx = ActorContext::fromSession($conn);

try {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'GET') {
        requireActionJson(in_array($action, $configurationActions, true) ? Cap::ASSETS_MANAGE : Cap::ASSETS_LOGISTICS, $conn);
        $movementId = filter_input(INPUT_GET, 'movement_id', FILTER_VALIDATE_INT);
        if (!$movementId || $movementId < 1) {
            throw new ServiceError('validation', 'missing_field', "'movement_id' is required.");
        }
        echo json_encode(['success' => true] + AssetLogisticsService::getMovement($conn, $ctx, (int)$movementId));
        exit;
    }
    if ($method !== 'POST') {
        http_response_code(405);
        header('Allow: GET, POST');
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) throw new ServiceError('bad_request', 'invalid_json', 'Request body must be a JSON object.');
    $action = (string)($data['action'] ?? '');
    $configurationActions = ['list_carriers', 'get_configuration', 'save_purpose', 'save_receipt_policy', 'save_outcome_status', 'save_carrier'];
    requireActionJson(Cap::ASSETS_LOGISTICS, $conn);
    $tenantId = getActiveTenantId($conn, (int)$_SESSION['analyst_id']);
    if (!$tenantId) $tenantId = getDefaultTenantId($conn);
    switch ($action) {
        case 'list_carriers':
            echo json_encode(['success' => true] + AssetLogisticsService::listCarriers($conn, $ctx, (int)$tenantId));
            break;
        case 'get_configuration':
            echo json_encode(['success' => true] + AssetLogisticsService::getConfiguration($conn, $ctx, (int)$tenantId));
            break;
        case 'save_purpose':
            echo json_encode(['success' => true] + AssetLogisticsService::savePurpose($conn, $ctx, (int)$tenantId, $data));
            break;
        case 'save_receipt_policy':
            echo json_encode(['success' => true] + AssetLogisticsService::saveReceiptPolicy($conn, $ctx, (int)$tenantId, $data));
            break;
        case 'save_outcome_status':
            echo json_encode(['success' => true] + AssetLogisticsService::saveOutcomeStatus($conn, $ctx, (int)$tenantId, $data));
            break;
        case 'save_carrier':
            echo json_encode(['success' => true] + AssetLogisticsService::saveCarrier($conn, $ctx, (int)$tenantId, $data));
            break;
        case 'create_movement':
            $result = AssetLogisticsService::createMovement($conn, $ctx, $data);
            http_response_code(201);
            echo json_encode(['success' => true] + $result);
            break;
        case 'split_shipment':
            $shipmentId = (int)($data['shipment_id'] ?? 0);
            $assetIds = $data['asset_ids'] ?? null;
            if ($shipmentId < 1 || !is_array($assetIds)) throw new ServiceError('validation', 'missing_field', "'shipment_id' and 'asset_ids' are required.");
            echo json_encode(['success' => true] + AssetLogisticsService::splitShipment($conn, $ctx, $shipmentId, $assetIds));
            break;
        case 'replace_cancelled_shipment':
            $shipmentId = (int)($data['shipment_id'] ?? 0);
            if ($shipmentId < 1) throw new ServiceError('validation', 'missing_field', "'shipment_id' is required.");
            echo json_encode(['success' => true] + AssetLogisticsService::replaceCancelledShipment($conn, $ctx, $shipmentId));
            break;
        case 'update_shipment':
            $shipmentId = (int)($data['shipment_id'] ?? 0);
            $fields = $data['fields'] ?? null;
            if ($shipmentId < 1 || !is_array($fields)) throw new ServiceError('validation', 'missing_field', "'shipment_id' and 'fields' are required.");
            echo json_encode(['success' => true] + AssetLogisticsService::updateShipmentDetails($conn, $ctx, $shipmentId, $fields));
            break;
        case 'transition_shipment':
            $shipmentId = (int)($data['shipment_id'] ?? 0);
            $status = (string)($data['status'] ?? '');
            if ($shipmentId < 1 || $status === '') throw new ServiceError('validation', 'missing_field', "'shipment_id' and 'status' are required.");
            echo json_encode(['success' => true] + AssetLogisticsService::transitionShipment($conn, $ctx, $shipmentId, $status, isset($data['notes']) ? (string)$data['notes'] : null));
            break;
        case 'close_movement':
            $movementId = (int)($data['movement_id'] ?? 0);
            if ($movementId < 1) throw new ServiceError('validation', 'missing_field', "'movement_id' is required.");
            echo json_encode(['success' => true] + AssetLogisticsService::closeMovement($conn, $ctx, $movementId));
            break;
        case 'record_receipt_outcomes':
            $shipmentId = (int)($data['shipment_id'] ?? 0);
            $outcomes = $data['outcomes'] ?? null;
            if ($shipmentId < 1 || !is_array($outcomes)) throw new ServiceError('validation', 'missing_field', "'shipment_id' and 'outcomes' are required.");
            echo json_encode(['success' => true] + AssetLogisticsService::recordReceiptOutcomes($conn, $ctx, $shipmentId, $outcomes, isset($data['notes']) ? (string)$data['notes'] : null));
            break;
        case 'set_shipment_cost_centre':
            $shipmentId = (int)($data['shipment_id'] ?? 0);
            if ($shipmentId < 1 || !array_key_exists('cost_centre_id', $data)) throw new ServiceError('validation', 'missing_field', "'shipment_id' and 'cost_centre_id' are required.");
            $effective = AssetLogisticsService::setShipmentCostCentreOverride($conn, $ctx, $shipmentId, $data['cost_centre_id']);
            echo json_encode(['success' => true, 'effective_cost_centre_id' => $effective]);
            break;
        default:
            throw new ServiceError('validation', 'invalid_action', 'Unsupported asset logistics action.');
    }
} catch (ServiceError $e) {
    http_response_code(serviceErrorHttpStatus($e->kind));
    echo json_encode(['success' => false, 'code' => $e->errorCode, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Asset logistics API failure: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'code' => 'internal_error', 'error' => 'The asset logistics operation could not be completed.']);
}
