<?php
/**
 * FreeITSM REST API v1 — asset dispatch and reverse logistics.
 * All persistence and company-scope checks remain in AssetLogisticsService.
 */
require_once dirname(__DIR__, 3) . '/includes/service_context.php';
require_once dirname(__DIR__, 3) . '/includes/services/asset_logistics.php';

function apiAssetLogisticsCreateMovement(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        $result = AssetLogisticsService::createMovement($conn, ActorContext::fromApiKey($apiKey), $body);
        apiRespond($result, 201);
    } catch (ServiceError $e) { apiFailFromService($e); }
}
function apiAssetLogisticsGetMovement(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        $result = AssetLogisticsService::getMovement($conn, ActorContext::fromApiKey($apiKey), (int)$params[0]);
        apiRespond($result);
    } catch (ServiceError $e) { apiFailFromService($e); }
}
function apiAssetLogisticsSplitShipment(PDO $conn, array $apiKey, array $params, array $body): void {
    if (!isset($body['asset_ids']) || !is_array($body['asset_ids'])) apiError(400, 'invalid_parameter', "'asset_ids' must be an array.");
    try {
        apiRespond(AssetLogisticsService::splitShipment($conn, ActorContext::fromApiKey($apiKey), (int)$params[0], $body['asset_ids']), 201);
    } catch (ServiceError $e) { apiFailFromService($e); }
}
function apiAssetLogisticsUpdateShipment(PDO $conn, array $apiKey, array $params, array $body): void {
    $fields = $body['fields'] ?? $body;
    if (!is_array($fields)) apiError(400, 'invalid_parameter', "'fields' must be an object.");
    try {
        apiRespond(AssetLogisticsService::updateShipmentDetails($conn, ActorContext::fromApiKey($apiKey), (int)$params[0], $fields));
    } catch (ServiceError $e) { apiFailFromService($e); }
}
function apiAssetLogisticsTransitionShipment(PDO $conn, array $apiKey, array $params, array $body): void {
    $status = trim((string)($body['status'] ?? ''));
    if ($status === '') apiError(400, 'invalid_parameter', "'status' is required.");
    try {
        apiRespond(AssetLogisticsService::transitionShipment($conn, ActorContext::fromApiKey($apiKey), (int)$params[0], $status, isset($body['notes']) ? (string)$body['notes'] : null));
    } catch (ServiceError $e) { apiFailFromService($e); }
}
function apiAssetLogisticsSetCostCentre(PDO $conn, array $apiKey, array $params, array $body): void {
    if (!array_key_exists('cost_centre_id', $body)) apiError(400, 'invalid_parameter', "'cost_centre_id' is required; use null to restore inheritance.");
    try {
        $effective = AssetLogisticsService::setShipmentCostCentreOverride($conn, ActorContext::fromApiKey($apiKey), (int)$params[0], $body['cost_centre_id']);
        apiRespond(['effective_cost_centre_id' => $effective]);
    } catch (ServiceError $e) { apiFailFromService($e); }
}

function apiAssetLogisticsReplaceCancelledShipment(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        apiRespond(AssetLogisticsService::replaceCancelledShipment($conn, ActorContext::fromApiKey($apiKey), (int)$params[0]), 201);
    } catch (ServiceError $e) { apiFailFromService($e); }
}
