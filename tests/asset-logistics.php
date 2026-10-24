<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * Asset Logistics core service tests. Run after Database Verification has created
 * the logistics schema and seeded the shared purposes. All fixtures are created
 * inside one transaction and rolled back unconditionally.
 */
chdir(dirname(__DIR__));
require 'config.php';
require 'includes/functions.php';
require 'includes/services/asset_logistics.php';

$conn = connectToDatabase();
$pass = 0; $fail = 0;
function logisticsCheck(string $label, $got, $want): void {
    global $pass, $fail;
    if ($got === $want) { ++$pass; echo "  ok    $label\n"; }
    else { ++$fail; echo "  FAIL  $label\n        got  " . json_encode($got) . "\n        want " . json_encode($want) . "\n"; }
}
function logisticsThrows(callable $fn): string {
    try { $fn(); return 'ok'; } catch (ServiceError $e) { return $e->errorCode; }
}

$requiredTables = ['asset_movement_purposes','asset_movements','asset_shipments','asset_movement_assets','asset_movement_events','asset_logistics_policies','asset_logistics_outcome_statuses'];
$missing = [];
foreach ($requiredTables as $table) {
    $st = $conn->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $st->execute([$table]);
    if (!(int)$st->fetchColumn()) $missing[] = $table;
}
if ($missing) { fwrite(STDERR, 'Run Database Verification first; missing: ' . implode(', ', $missing) . "\n"); exit(1); }

$ctx = ActorContext::system('Asset Logistics test');
$defaultTenant = getDefaultTenantId($conn);
$token = 'ZZLOG' . getmypid() . bin2hex(random_bytes(3));
$conn->beginTransaction();
try {
    $purpose = $conn->prepare("SELECT id FROM asset_movement_purposes WHERE tenant_id IS NULL AND movement_type = 'outbound_dispatch' AND is_active = 1 ORDER BY display_order, id LIMIT 1");
    $purpose->execute();
    $purposeId = (int)$purpose->fetchColumn();
    $effectivePurposeId = $purposeId;
    logisticsCheck('a shared outbound purpose is seeded', $purposeId > 0, true);
    if ($purposeId > 0) {
        $override = AssetLogisticsService::savePurpose($conn, $ctx, $defaultTenant, [
            'id' => $purposeId, 'movement_type' => 'outbound_dispatch', 'name' => $token . ' overridden purpose',
            'display_order' => 90, 'is_active' => 1,
        ]);
        logisticsCheck('shared purpose edits create a company-scoped override', !empty($override['created_override']), true);
        $effectivePurposeId = (int)($override['id'] ?? 0);
        $effectivePurposes = AssetLogisticsService::listPurposes($conn, $ctx, $defaultTenant, 'outbound_dispatch');
        logisticsCheck('purpose list returns the company override instead of the shared seed', in_array($effectivePurposeId, array_map(static fn($p) => (int)$p['id'], $effectivePurposes), true) && !in_array($purposeId, array_map(static fn($p) => (int)$p['id'], $effectivePurposes), true), true);
        $shared = $conn->prepare('SELECT name FROM asset_movement_purposes WHERE id = ?');
        $shared->execute([$purposeId]);
        logisticsCheck('company override leaves the shared seed unchanged', (string)$shared->fetchColumn() !== $token . ' overridden purpose', true);
    }

    $customPurpose = AssetLogisticsService::savePurpose($conn, $ctx, $defaultTenant, [
        'movement_type' => 'outbound_dispatch', 'name' => $token . ' custom purpose',
        'display_order' => 90, 'is_active' => 1,
    ]);
    logisticsCheck('custom purpose is created for the active company', (int)$customPurpose['id'] > 0, true);
    logisticsCheck('duplicate company purpose names are refused', logisticsThrows(function () use ($conn, $ctx, $defaultTenant, $token) {
        AssetLogisticsService::savePurpose($conn, $ctx, $defaultTenant, [
            'movement_type' => 'outbound_dispatch', 'name' => $token . ' custom purpose',
            'display_order' => 91, 'is_active' => 1,
        ]);
    }), 'purpose_duplicate');

    $assetInsert = $conn->prepare('INSERT INTO assets (hostname, asset_tag, tenant_id) VALUES (?, ?, NULL)');
    $assetInsert->execute([$token . '-A', $token . '-TAG-A']); $assetA = (int)$conn->lastInsertId();
    $assetInsert->execute([$token . '-B', $token . '-TAG-B']); $assetB = (int)$conn->lastInsertId();
    $assetInsert->execute([$token . '-C', $token . '-TAG-C']); $assetC = (int)$conn->lastInsertId();
    $assetInsert->execute([$token . '-D', $token . '-TAG-D']); $assetD = (int)$conn->lastInsertId();

    if ($purposeId > 0) {
        logisticsCheck('the overridden shared purpose cannot be selected directly', logisticsThrows(function () use ($conn, $ctx, $purposeId, $assetD) {
            AssetLogisticsService::createMovement($conn, $ctx, ['movement_type' => 'outbound_dispatch', 'purpose_id' => $purposeId, 'asset_ids' => [$assetD]]);
        }), 'invalid_purpose');
        $created = AssetLogisticsService::createMovement($conn, $ctx, [
            'movement_type' => 'outbound_dispatch',
            'purpose_id' => $effectivePurposeId,
            'asset_ids' => [$assetA, $assetB],
            'notes' => 'ZZ fixture',
        ]);
        logisticsCheck('movement creates one default shipment', (int)$created['shipment_number'], 1);
        $count = $conn->prepare('SELECT COUNT(*) FROM asset_movement_assets WHERE movement_id = ? AND shipment_id = ?');
        $count->execute([$created['movement_id'], $created['shipment_id']]);
        logisticsCheck('all selected assets are assigned to the default shipment', (int)$count->fetchColumn(), 2);
        logisticsCheck('an asset cannot enter a second open movement', logisticsThrows(function () use ($conn, $ctx, $effectivePurposeId, $assetA) {
            AssetLogisticsService::createMovement($conn, $ctx, [
                'movement_type' => 'inbound_return', 'purpose_id' => $effectivePurposeId, 'asset_ids' => [$assetA],
            ]);
        }), 'asset_in_active_movement');

        logisticsCheck('duplicate asset ids are refused', logisticsThrows(function () use ($conn, $ctx, $effectivePurposeId, $assetA) {
            AssetLogisticsService::createMovement($conn, $ctx, [
                'movement_type' => 'outbound_dispatch', 'purpose_id' => $effectivePurposeId, 'asset_ids' => [$assetA, $assetA],
            ]);
        }), 'duplicate_asset');

        // Prove inheritance is dynamic without needing an update endpoint yet.
        $cc1 = $conn->prepare('INSERT INTO cost_centres (tenant_id, code, name) VALUES (?, ?, ?)');
        $cc1->execute([$defaultTenant, $token . '1', 'ZZ Logistics Centre 1']); $cc1Id = (int)$conn->lastInsertId();
        $cc1->execute([$defaultTenant, $token . '2', 'ZZ Logistics Centre 2']); $cc2Id = (int)$conn->lastInsertId();
        $updateMovement = $conn->prepare('UPDATE asset_movements SET cost_centre_id = ? WHERE id = ?');
        $updateMovement->execute([$cc1Id, $created['movement_id']]);
        logisticsCheck('shipment inherits movement cost centre', AssetLogisticsService::effectiveShipmentCostCentre($conn, $ctx, $created['shipment_id']), $cc1Id);
        $updateMovement->execute([$cc2Id, $created['movement_id']]);
        logisticsCheck('inherited shipment cost centre follows movement changes', AssetLogisticsService::effectiveShipmentCostCentre($conn, $ctx, $created['shipment_id']), $cc2Id);
        logisticsCheck('explicit shipment override takes precedence', AssetLogisticsService::setShipmentCostCentreOverride($conn, $ctx, $created['shipment_id'], $cc1Id), $cc1Id);
        $updateMovement->execute([$cc2Id, $created['movement_id']]);
        logisticsCheck('explicit shipment override remains stable', AssetLogisticsService::effectiveShipmentCostCentre($conn, $ctx, $created['shipment_id']), $cc1Id);
        logisticsCheck('clearing override restores dynamic inheritance', AssetLogisticsService::setShipmentCostCentreOverride($conn, $ctx, $created['shipment_id'], null), $cc2Id);

        $split = AssetLogisticsService::splitShipment($conn, $ctx, $created['shipment_id'], [$assetB]);
        logisticsCheck('split creates shipment number two', (int)$split['shipment_number'], 2);
        $count->execute([$created['movement_id'], $created['shipment_id']]);
        logisticsCheck('split leaves an asset in the source shipment', (int)$count->fetchColumn(), 1);
        $count->execute([$created['movement_id'], $split['shipment_id']]);
        logisticsCheck('split moves the selected asset to the new shipment', (int)$count->fetchColumn(), 1);
        logisticsCheck('split refuses to empty the source shipment', logisticsThrows(function () use ($conn, $ctx, $created, $assetA) {
            AssetLogisticsService::splitShipment($conn, $ctx, $created['shipment_id'], [$assetA]);
        }), 'empty_source_shipment');

        $detailResult = AssetLogisticsService::updateShipmentDetails($conn, $ctx, $split['shipment_id'], [
            'carrier_name' => 'ZZ Manual Courier', 'tracking_number' => 'ZZ-AWB-123', 'notes' => 'ZZ shipment note',
        ]);
        logisticsCheck('shipment detail update returns the parent movement', (int)$detailResult['movement']['id'], (int)$created['movement_id']);
        logisticsCheck('shipment detail update persists tracking number', $detailResult['shipments'][1]['tracking_number'], 'ZZ-AWB-123');
        logisticsCheck('shipment with an AWB cannot be split', logisticsThrows(function () use ($conn, $ctx, $split, $assetB) {
            AssetLogisticsService::splitShipment($conn, $ctx, $split['shipment_id'], [$assetB]);
        }), 'shipment_awb_exists');
        logisticsCheck('invalid shipment transition is rejected', logisticsThrows(function () use ($conn, $ctx, $created) {
            AssetLogisticsService::transitionShipment($conn, $ctx, $created['shipment_id'], 'delivered');
        }), 'invalid_transition');
        AssetLogisticsService::transitionShipment($conn, $ctx, $split['shipment_id'], 'dispatched');
        AssetLogisticsService::transitionShipment($conn, $ctx, $split['shipment_id'], 'in_transit');
        $finished = AssetLogisticsService::transitionShipment($conn, $ctx, $split['shipment_id'], 'delivered');
        logisticsCheck('delivered shipment is recorded', $finished['shipments'][1]['status'], 'delivered');
        $statusInsert = $conn->prepare('INSERT INTO asset_status_types (name, description, is_active, display_order, tenant_id) VALUES (?, ?, 1, 999, NULL)');
        $statusInsert->execute([$token . ' inspected', 'ZZ logistics inspection test']); $inspectionStatusId = (int)$conn->lastInsertId();
        $policyInsert = $conn->prepare("INSERT INTO asset_logistics_policies (tenant_id, movement_type, post_receipt_action, inspection_provider) VALUES (NULL, 'outbound_dispatch', 'inspection', 'manual')");
        $policyInsert->execute();
        $mapInsert = $conn->prepare("INSERT INTO asset_logistics_outcome_statuses (tenant_id, outcome_code, asset_status_id) VALUES (NULL, 'passed', ?)");
        $mapInsert->execute([$inspectionStatusId]);
        $inspected = AssetLogisticsService::recordReceiptOutcomes($conn, $ctx, $split['shipment_id'], [
            ['asset_id' => $assetB, 'outcome_code' => 'passed'],
        ], 'ZZ receipt inspection');
        $assetStatus = $conn->prepare('SELECT asset_status_id FROM assets WHERE id = ?');
        $assetStatus->execute([$assetB]);
        logisticsCheck('receipt outcome applies configured asset status', (int)$assetStatus->fetchColumn(), $inspectionStatusId);
        logisticsCheck('receipt inspection is recorded in movement history', in_array('asset_receipt_inspected', array_column($inspected['events'], 'event_type'), true), true);

        // The manual outcome endpoint must not bypass a configured checklist provider.
        $policyUpdate = $conn->prepare("UPDATE asset_logistics_policies SET inspection_provider = 'ticket_checklists' WHERE tenant_id IS NULL AND movement_type = 'outbound_dispatch'");
        $policyUpdate->execute();
        logisticsCheck('manual inspection is blocked when checklist provider is configured', logisticsThrows(function () use ($conn, $ctx, $split, $assetB) {
            AssetLogisticsService::recordReceiptOutcomes($conn, $ctx, $split['shipment_id'], [
                ['asset_id' => $assetB, 'outcome_code' => 'passed'],
            ]);
        }), 'checklist_provider_unavailable');
        $policyUpdate = $conn->prepare("UPDATE asset_logistics_policies SET inspection_provider = 'manual' WHERE tenant_id IS NULL AND movement_type = 'outbound_dispatch'");
        $policyUpdate->execute();

        // A valid inspection outcome without a status mapping is still recorded;
        // the existing asset status must remain unchanged.
        AssetLogisticsService::transitionShipment($conn, $ctx, $created['shipment_id'], 'dispatched');
        AssetLogisticsService::transitionShipment($conn, $ctx, $created['shipment_id'], 'in_transit');
        AssetLogisticsService::transitionShipment($conn, $ctx, $created['shipment_id'], 'delivered');
        $assetStatus->execute([$assetA]); $beforeUnmapped = $assetStatus->fetchColumn();
        $unmapped = AssetLogisticsService::recordReceiptOutcomes($conn, $ctx, $created['shipment_id'], [
            ['asset_id' => $assetA, 'outcome_code' => 'minor_wear'],
        ], 'ZZ unmapped outcome');
        $assetStatus->execute([$assetA]);
        logisticsCheck('unmapped outcome preserves existing asset status', $assetStatus->fetchColumn(), $beforeUnmapped);
        $unmappedEvent = array_values(array_filter($unmapped['events'], fn($event) => $event['event_type'] === 'asset_receipt_inspected' && str_contains((string)$event['notes'], 'outcome=minor_wear')));
        logisticsCheck('unmapped outcome is still audited', count($unmappedEvent) > 0, true);

        $finished = AssetLogisticsService::transitionShipment($conn, $ctx, $created['shipment_id'], 'cancelled');
        logisticsCheck('movement completes when every shipment is closed', $finished['movement']['status'], 'completed');

        // Replacement preserves the cancelled booking as an audit event and moves its asset links to a fresh pending shipment.
        $replacementSource = AssetLogisticsService::createMovement($conn, $ctx, [
            'movement_type' => 'outbound_dispatch', 'purpose_id' => $effectivePurposeId, 'asset_ids' => [$assetD], 'notes' => 'ZZ replacement fixture',
        ]);
        AssetLogisticsService::updateShipmentDetails($conn, $ctx, $replacementSource['shipment_id'], ['tracking_number' => 'ZZ-OLD-AWB']);
        AssetLogisticsService::transitionShipment($conn, $ctx, $replacementSource['shipment_id'], 'cancelled', 'ZZ replace AWB');
        $replacement = AssetLogisticsService::replaceCancelledShipment($conn, $ctx, $replacementSource['shipment_id']);
        logisticsCheck('replacement receives next shipment number', (int)$replacement['shipment_number'], 2);
        $oldLinks = $conn->prepare('SELECT COUNT(*) FROM asset_movement_assets WHERE shipment_id = ?');
        $oldLinks->execute([(int)$replacementSource['shipment_id']]);
        logisticsCheck('cancelled shipment is retained without active asset links', (int)$oldLinks->fetchColumn(), 0);
        $newLinks = $conn->prepare('SELECT COUNT(*) FROM asset_movement_assets WHERE shipment_id = ?');
        $newLinks->execute([(int)$replacement['shipment_id']]);
        logisticsCheck('replacement retains its assets', (int)$newLinks->fetchColumn(), 1);
        logisticsCheck('replacement shipment starts pending', $replacement['movement']['shipments'][1]['status'] ?? null, 'pending');
        logisticsCheck('replacement reopens a fully cancelled movement', $replacement['movement']['status'], 'in_progress');

        // Employee self-service is limited to the explicitly assigned recipient.
        $userInsert = $conn->prepare('INSERT INTO users (email, display_name, tenant_id, is_active) VALUES (?, ?, NULL, 1)');
        $userInsert->execute([$token . '@example.invalid', $token . ' recipient']); $portalUserId = (int)$conn->lastInsertId();
        $delivery = AssetLogisticsService::createMovement($conn, $ctx, [
            'movement_type' => 'outbound_dispatch', 'purpose_id' => $effectivePurposeId,
            'asset_ids' => [$assetA], 'recipient_user_id' => $portalUserId,
        ]);
        $portalList = AssetLogisticsService::listPortalShipments($conn, $portalUserId);
        logisticsCheck('assigned portal recipient sees their delivery', count(array_filter($portalList['shipments'], fn($r) => (int)$r['shipment_id'] === (int)$delivery['shipment_id'])) === 1, true);
        logisticsCheck('unassigned portal user cannot acknowledge delivery', logisticsThrows(function () use ($conn, $delivery) {
            AssetLogisticsService::portalShipmentAction($conn, 999999999, (int)$delivery['shipment_id'], 'acknowledge');
        }), 'not_found');
        $otherUserInsert = $conn->prepare('INSERT INTO users (email, display_name, tenant_id, is_active) VALUES (?, ?, NULL, 1)');
        $otherUserInsert->execute([$token . '-other@example.invalid', $token . ' other recipient']); $otherPortalUserId = (int)$conn->lastInsertId();
        AssetLogisticsService::updateShipmentDetails($conn, $ctx, $delivery['shipment_id'], ['recipient_user_id' => $otherPortalUserId]);
        logisticsCheck('recipient can be changed before AWB creation', (int)AssetLogisticsService::getMovement($conn, $ctx, $delivery['movement_id'])['shipments'][0]['recipient_user_id'], $otherPortalUserId);
        AssetLogisticsService::updateShipmentDetails($conn, $ctx, $delivery['shipment_id'], ['recipient_user_id' => $portalUserId]);
        AssetLogisticsService::updateShipmentDetails($conn, $ctx, $delivery['shipment_id'], ['tracking_number' => 'ZZ-LOCK-AWB']);
        logisticsCheck('recipient cannot change after AWB creation', logisticsThrows(function () use ($conn, $ctx, $delivery, $otherPortalUserId) {
            AssetLogisticsService::updateShipmentDetails($conn, $ctx, $delivery['shipment_id'], ['recipient_user_id' => $otherPortalUserId]);
        }), 'recipient_locked_after_awb');
        logisticsCheck('recorded AWB cannot be cleared to bypass recipient lock', logisticsThrows(function () use ($conn, $ctx, $delivery) {
            AssetLogisticsService::updateShipmentDetails($conn, $ctx, $delivery['shipment_id'], ['tracking_number' => null]);
        }), 'tracking_number_immutable');
        AssetLogisticsService::transitionShipment($conn, $ctx, $delivery['shipment_id'], 'dispatched');
        AssetLogisticsService::portalShipmentAction($conn, $portalUserId, $delivery['shipment_id'], 'report_issue', 'ZZ delivery arrived damaged');
        $deliveryStatus = $conn->prepare('SELECT status FROM asset_shipments WHERE id = ?');
        $deliveryStatus->execute([$delivery['shipment_id']]);
        logisticsCheck('employee issue report flags shipment as exception', $deliveryStatus->fetchColumn(), 'exception');
        AssetLogisticsService::transitionShipment($conn, $ctx, $delivery['shipment_id'], 'delivered');
        AssetLogisticsService::portalShipmentAction($conn, $portalUserId, $delivery['shipment_id'], 'acknowledge');
        $ack = $conn->prepare('SELECT recipient_acknowledged_at FROM asset_shipments WHERE id = ?');
        $ack->execute([$delivery['shipment_id']]);
        $acknowledgedAt = $ack->fetchColumn();
        logisticsCheck('assigned portal recipient can acknowledge delivered shipment', $acknowledgedAt !== false && $acknowledgedAt !== null, true);

        // Staff-initiated inbound return: employee sees collection details but cannot create the movement.
        $inPurpose = $conn->prepare("SELECT id FROM asset_movement_purposes WHERE tenant_id IS NULL AND movement_type = 'inbound_return' AND is_active = 1 ORDER BY display_order, id LIMIT 1");
        $inPurpose->execute(); $inPurposeId = (int)$inPurpose->fetchColumn();
        $assign = $conn->prepare('INSERT INTO users_assets (user_id, asset_id) VALUES (?, ?)');
        $assign->execute([$portalUserId, $assetC]);
        logisticsCheck('an inbound return purpose is seeded', $inPurposeId > 0, true);
        if ($inPurposeId > 0) {
            logisticsCheck('staff cannot create a return without selecting its employee', logisticsThrows(function () use ($conn, $ctx, $inPurposeId, $assetC) {
            AssetLogisticsService::createMovement($conn, $ctx, [
                'movement_type' => 'inbound_return', 'purpose_id' => $inPurposeId, 'asset_ids' => [$assetC],
            ]);
        }), 'return_recipient_required');
        $return = AssetLogisticsService::createMovement($conn, $ctx, [
                'movement_type' => 'inbound_return', 'purpose_id' => $inPurposeId,
                'asset_ids' => [$assetC], 'recipient_user_id' => $portalUserId,
                'notes' => 'ZZ staff initiated return',
            ]);
            logisticsCheck('return cannot be dispatched before collection details are set', logisticsThrows(function () use ($conn, $ctx, $return) {
                AssetLogisticsService::transitionShipment($conn, $ctx, $return['shipment_id'], 'dispatched');
            }), 'return_arrangement_incomplete');
            AssetLogisticsService::updateShipmentDetails($conn, $ctx, $return['shipment_id'], [
                'collection_method' => 'home_pickup', 'pickup_address' => 'ZZ test address',
                'pickup_scheduled_at' => '2026-10-25T10:30', 'return_instructions' => 'ZZ pack charger too',
            ]);
            $returnList = AssetLogisticsService::listPortalShipments($conn, $portalUserId)['shipments'];
            $returnRow = array_values(array_filter($returnList, fn($r) => (int)$r['shipment_id'] === (int)$return['shipment_id']));
            logisticsCheck('employee sees staff-initiated return', count($returnRow), 1);
            logisticsCheck('employee sees home pickup method and instructions', $returnRow ? $returnRow[0]['collection_method'] . '|' . $returnRow[0]['return_instructions'] : '', 'home_pickup|ZZ pack charger too');
            AssetLogisticsService::portalShipmentAction($conn, $portalUserId, $return['shipment_id'], 'return_acknowledge');
            $returnAck = $conn->prepare('SELECT return_acknowledged_at FROM asset_shipments WHERE id = ?');
            $returnAck->execute([$return['shipment_id']]);
            logisticsCheck('employee can acknowledge return instructions', $returnAck->fetchColumn() !== false, true);
            AssetLogisticsService::portalShipmentAction($conn, $portalUserId, $return['shipment_id'], 'report_return_issue', 'ZZ pickup time does not work');
            $returnIssue = $conn->prepare('SELECT return_issue, status FROM asset_shipments WHERE id = ?');
            $returnIssue->execute([$return['shipment_id']]); $returnIssueRow = $returnIssue->fetch(PDO::FETCH_ASSOC);
            logisticsCheck('pre-dispatch return issue is recorded without prematurely changing shipment state', $returnIssueRow['return_issue'] . '|' . $returnIssueRow['status'], 'ZZ pickup time does not work|pending');
            AssetLogisticsService::transitionShipment($conn, $ctx, $return['shipment_id'], 'dispatched');
            AssetLogisticsService::portalShipmentAction($conn, $portalUserId, $return['shipment_id'], 'report_return_issue', 'ZZ courier missed pickup');
            $returnIssue->execute([$return['shipment_id']]); $returnIssueRow = $returnIssue->fetch(PDO::FETCH_ASSOC);
            logisticsCheck('post-dispatch return issue flags shipment as exception', $returnIssueRow['status'], 'exception');
        }
    }
} finally {
    if ($conn->inTransaction()) $conn->rollBack();
}
echo "$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
