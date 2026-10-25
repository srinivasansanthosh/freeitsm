<?php
/**
 * AssetLogisticsService — shared persistence and validation for asset movements.
 *
 * A movement owns one or more shipments; each asset in a movement belongs to one
 * shipment. The ordinary create path creates shipment 1 automatically. A shipment's
 * cost_centre_id is an OPTIONAL override: NULL means use the movement's current cost
 * centre, so changes to the movement are reflected without a synchronisation job.
 *
 * UI and REST adapters should call this service rather than write these tables directly.
 * The service never emits HTTP and throws ServiceError for expected business failures.
 */
require_once __DIR__ . '/../service_context.php';
require_once __DIR__ . '/../tenancy.php';
require_once __DIR__ . '/../cost_centre_assign.php';
require_once __DIR__ . '/notifications.php';

class AssetLogisticsService
{
    private const MOVEMENT_TYPES = ['outbound_dispatch', 'inbound_return'];

    private static function assertTenantScope(ActorContext $ctx, int $tenantId): void
    {
        if ($ctx->companyScope !== null && !in_array($tenantId, $ctx->companyScope, true)) {
            throw new ServiceError('not_found', 'not_found', 'Movement not found.');
        }
    }

    private static function storedTenantId(int $tenantId, PDO $conn): ?int
    {
        return $tenantId === getDefaultTenantId($conn) ? null : $tenantId;
    }

    private static function recordEvent(PDO $conn, int $movementId, ?int $shipmentId, string $type, ActorContext $ctx, ?string $old = null, ?string $new = null, ?string $notes = null): void
    {
        $st = $conn->prepare('INSERT INTO asset_movement_events
            (movement_id, shipment_id, event_type, old_value, new_value, notes, actor_analyst_id, actor_name)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $st->execute([
            $movementId,
            $shipmentId,
            $type,
            $old,
            $new,
            $notes,
            $ctx->actorId > 0 ? $ctx->actorId : null,
            $ctx->actorName !== '' ? $ctx->actorName : null,
        ]);
    }

    /** Queue an in-app portal notification after the business transaction commits. */
    private static function notifyPortal(PDO $conn, ?int $userId, int $shipmentId, string $title, string $body, string $eventType = 'asset_logistics.updated'): void
    {
        if (!$userId || $userId < 1) return;
        NotificationsService::notify($conn, [
            'portal_user_id' => $userId,
            'event_type' => $eventType,
            'entity_type' => 'asset_shipment',
            'entity_id' => $shipmentId,
            'entity_ref' => 'Shipment #' . $shipmentId,
            'title' => $title,
            'body' => $body,
            'actor_name' => 'FreeITSM Asset Logistics',
        ]);
    }

    private static function notifyShipmentRecipient(PDO $conn, int $shipmentId, string $title, string $body): void
    {
        $st = $conn->prepare('SELECT recipient_user_id FROM asset_shipments WHERE id = ?');
        $st->execute([$shipmentId]);
        $userId = $st->fetchColumn();
        self::notifyPortal($conn, $userId === false || $userId === null ? null : (int)$userId, $shipmentId, $title, $body);
    }

    /** Return true only when every linked asset has its own inspection event. */
    private static function shipmentInspectionComplete(PDO $conn, int $shipmentId): bool
    {
        $assets = $conn->prepare('SELECT asset_id FROM asset_movement_assets WHERE shipment_id = ? AND asset_id IS NOT NULL');
        $assets->execute([$shipmentId]);
        $assetIds = array_map('intval', $assets->fetchAll(PDO::FETCH_COLUMN));
        if (!$assetIds) return false;
        $event = $conn->prepare("SELECT 1 FROM asset_movement_events WHERE shipment_id = ? AND event_type = 'asset_receipt_inspected' AND notes LIKE ? LIMIT 1");
        foreach ($assetIds as $assetId) {
            $event->execute([$shipmentId, 'Asset ' . $assetId . ';%']);
            if (!$event->fetchColumn()) return false;
        }
        return true;
    }

    /** Assign outbound assets to their recipient only when that policy is enabled and receipt is acknowledged. */
    private static function assignShipmentAssetsToRecipient(PDO $conn, ActorContext $ctx, int $shipmentId, int $movementId, int $recipientUserId): void
    {
        $movement = $conn->prepare('SELECT movement_type, tenant_id FROM asset_movements WHERE id = ?');
        $movement->execute([$movementId]);
        $movementRow = $movement->fetch(PDO::FETCH_ASSOC);
        if (!$movementRow) return;
        $tenantId = $movementRow['tenant_id'] === null ? getDefaultTenantId($conn) : (int)$movementRow['tenant_id'];
        $policy = $conn->prepare('SELECT assign_to_recipient_on_ack FROM asset_logistics_policies WHERE movement_type = ? AND (tenant_id = ? OR tenant_id IS NULL) ORDER BY tenant_id IS NOT NULL DESC LIMIT 1');
        $policy->execute([$movementRow['movement_type'], $tenantId]);
        $assignOnAck = $policy->fetchColumn();
        if ($assignOnAck !== false && (int)$assignOnAck === 0) return;
        $assets = $conn->prepare('SELECT a.id FROM asset_movement_assets ma JOIN assets a ON a.id = ma.asset_id WHERE ma.shipment_id = ? ORDER BY a.id FOR UPDATE');
        $assets->execute([$shipmentId]);
        foreach ($assets->fetchAll(PDO::FETCH_COLUMN) as $assetIdValue) {
            $assetId = (int)$assetIdValue;
            $holders = $conn->prepare('SELECT ua.user_id, ua.analyst_id FROM users_assets ua WHERE ua.asset_id = ? FOR UPDATE');
            $holders->execute([$assetId]);
            $rows = $holders->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $holder) {
                if ($holder['analyst_id'] !== null) {
                    throw new ServiceError('conflict', 'asset_assigned_to_analyst', 'An asset assigned to an analyst must be unassigned by staff before employee receipt can be acknowledged.');
                }
            }
            $otherUsers = array_values(array_filter(array_map(static fn($h) => $h['user_id'] === null ? null : (int)$h['user_id'], $rows), static fn($id) => $id !== null && $id !== $recipientUserId));
            if (count($otherUsers) > 1) {
                throw new ServiceError('conflict', 'asset_multiple_holders', 'An asset with multiple current employee assignments must be resolved by staff before receipt can be acknowledged.');
            }
            $same = $conn->prepare('SELECT 1 FROM users_assets WHERE asset_id = ? AND user_id = ?');
            $same->execute([$assetId, $recipientUserId]);
            if ($same->fetchColumn()) continue;
            $oldUserId = $otherUsers[0] ?? null;
            $oldName = null;
            if ($oldUserId !== null) {
                $old = $conn->prepare('SELECT display_name FROM users WHERE id = ?');
                $old->execute([$oldUserId]); $oldName = $old->fetchColumn() ?: (string)$oldUserId;
                $conn->prepare('DELETE FROM users_assets WHERE asset_id = ? AND user_id = ?')->execute([$assetId, $oldUserId]);
                $conn->prepare("INSERT INTO asset_checkout_log (asset_id, user_id, user_name, action, analyst_id, notes, action_datetime) VALUES (?, ?, ?, 'checkin', ?, ?, UTC_TIMESTAMP())")
                    ->execute([$assetId, $oldUserId, $oldName, $ctx->actorId > 0 ? $ctx->actorId : null, 'Custody transferred on acknowledged shipment #' . $shipmentId]);
                $conn->prepare('INSERT INTO asset_history (asset_id, analyst_id, field_name, old_value, new_value, created_datetime) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())')
                    ->execute([$assetId, $ctx->actorId > 0 ? $ctx->actorId : null, 'assigned_user', $oldName, null]);
            }
            $recipient = $conn->prepare('SELECT display_name FROM users WHERE id = ? AND is_active = 1');
            $recipient->execute([$recipientUserId]); $recipientName = $recipient->fetchColumn();
            if (!$recipientName) throw new ServiceError('conflict', 'recipient_inactive', 'The delivery recipient is no longer active.');
            $conn->prepare('INSERT INTO users_assets (asset_id, user_id, assigned_by_analyst_id, notes, assigned_datetime) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())')
                ->execute([$assetId, $recipientUserId, $ctx->actorId > 0 ? $ctx->actorId : null, 'Assigned on acknowledged asset shipment #' . $shipmentId]);
            $conn->prepare("INSERT INTO asset_checkout_log (asset_id, user_id, user_name, action, analyst_id, notes, action_datetime) VALUES (?, ?, ?, 'checkout', ?, ?, UTC_TIMESTAMP())")
                ->execute([$assetId, $recipientUserId, $recipientName, $ctx->actorId > 0 ? $ctx->actorId : null, 'Acknowledged asset shipment #' . $shipmentId]);
            $conn->prepare('INSERT INTO asset_history (asset_id, analyst_id, field_name, old_value, new_value, created_datetime) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())')
                ->execute([$assetId, $ctx->actorId > 0 ? $ctx->actorId : null, 'assigned_user', $oldName, (string)$recipientName]);
            self::recordEvent($conn, $movementId, $shipmentId, 'asset_assigned_to_recipient', $ctx, $oldUserId === null ? null : (string)$oldUserId, (string)$recipientUserId, 'Asset ' . $assetId);
        }
    }

    /** Apply configured completion behaviour only after the whole movement satisfies its completion gate. */
    private static function applyCompletionPolicy(PDO $conn, ActorContext $ctx, int $movementId, bool $forceClose = false): void
    {
        $m = $conn->prepare('SELECT id, movement_type, tenant_id, status FROM asset_movements WHERE id = ? FOR UPDATE');
        $m->execute([$movementId]);
        $movement = $m->fetch(PDO::FETCH_ASSOC);
        if (!$movement) return;
        $tenantId = $movement['tenant_id'] === null ? getDefaultTenantId($conn) : (int)$movement['tenant_id'];
        $p = $conn->prepare('SELECT close_movement_on_completion, assign_to_recipient_on_ack, update_asset_status_on_completion, completion_asset_status_id, post_receipt_action FROM asset_logistics_policies WHERE movement_type = ? AND (tenant_id = ? OR tenant_id IS NULL) ORDER BY tenant_id IS NOT NULL DESC LIMIT 1');
        $p->execute([$movement['movement_type'], $tenantId]);
        $policy = $p->fetch(PDO::FETCH_ASSOC) ?: ['close_movement_on_completion' => 1, 'assign_to_recipient_on_ack' => 1, 'update_asset_status_on_completion' => 0, 'completion_asset_status_id' => null, 'post_receipt_action' => 'none'];
        $shipments = $conn->prepare('SELECT id, status, recipient_acknowledged_at, recipient_user_id FROM asset_shipments WHERE movement_id = ? FOR UPDATE');
        $shipments->execute([$movementId]);
        $rows = $shipments->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return;
        foreach ($rows as $shipment) {
            if ($shipment['status'] === 'cancelled') continue;
            if ($shipment['status'] !== 'delivered') return;
            if ($movement['movement_type'] === 'outbound_dispatch' && empty($shipment['recipient_acknowledged_at'])) return;
            if ($policy['post_receipt_action'] === 'inspection') {
                if (!self::shipmentInspectionComplete($conn, (int)$shipment['id'])) return;
            }
        }
        if ($movement['movement_type'] === 'outbound_dispatch' && !empty($policy['update_asset_status_on_completion']) && !empty($policy['completion_asset_status_id'])) {
            $assets = $conn->prepare("SELECT a.id, a.asset_status_id FROM asset_movement_assets ma JOIN assets a ON a.id = ma.asset_id JOIN asset_shipments s ON s.id = ma.shipment_id WHERE ma.movement_id = ? AND s.status = 'delivered' FOR UPDATE");
            $assets->execute([$movementId]);
            $up = $conn->prepare('UPDATE assets SET asset_status_id = ? WHERE id = ?');
            foreach ($assets->fetchAll(PDO::FETCH_ASSOC) as $asset) {
                if ((int)($asset['asset_status_id'] ?? 0) === (int)$policy['completion_asset_status_id']) continue;
                $up->execute([(int)$policy['completion_asset_status_id'], (int)$asset['id']]);
                self::recordEvent($conn, $movementId, null, 'asset_status_changed_on_completion', $ctx,
                    $asset['asset_status_id'] === null ? null : (string)$asset['asset_status_id'], (string)(int)$policy['completion_asset_status_id'], 'Asset ' . (int)$asset['id']);
            }
        }
        if ($movement['movement_type'] === 'inbound_return') {
            // Once the company has received the returned asset and any required inspection is complete,
            // close the employee custody assignment only if it still belongs to the named return recipient.
            $assetRows = $conn->prepare("SELECT ma.asset_id, s.id AS shipment_id, s.recipient_user_id FROM asset_movement_assets ma JOIN asset_shipments s ON s.id = ma.shipment_id WHERE ma.movement_id = ? AND s.status = 'delivered' AND s.recipient_user_id IS NOT NULL ORDER BY ma.asset_id FOR UPDATE");
            $assetRows->execute([$movementId]);
            foreach ($assetRows->fetchAll(PDO::FETCH_ASSOC) as $assetRow) {
                $holder = $conn->prepare('SELECT u.display_name, ua.expected_return_date FROM users_assets ua JOIN users u ON u.id = ua.user_id WHERE ua.asset_id = ? AND ua.user_id = ? FOR UPDATE');
                $holder->execute([(int)$assetRow['asset_id'], (int)$assetRow['recipient_user_id']]);
                $holderRow = $holder->fetch(PDO::FETCH_ASSOC);
                if ($holderRow) {
                    $conn->prepare('DELETE FROM users_assets WHERE asset_id = ? AND user_id = ?')->execute([(int)$assetRow['asset_id'], (int)$assetRow['recipient_user_id']]);
                    $conn->prepare("INSERT INTO asset_checkout_log (asset_id, user_id, user_name, action, expected_return_date, analyst_id, notes, action_datetime) VALUES (?, ?, ?, 'checkin', ?, ?, ?, UTC_TIMESTAMP())")
                        ->execute([(int)$assetRow['asset_id'], (int)$assetRow['recipient_user_id'], $holderRow['display_name'], $holderRow['expected_return_date'], $ctx->actorId > 0 ? $ctx->actorId : null, 'Return received on shipment #' . (int)$assetRow['shipment_id']]);
                    $conn->prepare('INSERT INTO asset_history (asset_id, analyst_id, field_name, old_value, new_value, created_datetime) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())')
                        ->execute([(int)$assetRow['asset_id'], $ctx->actorId > 0 ? $ctx->actorId : null, 'assigned_user', $holderRow['display_name'], null]);
                    self::recordEvent($conn, $movementId, null, 'asset_unassigned_on_return', $ctx, (string)$assetRow['recipient_user_id'], null, 'Asset ' . (int)$assetRow['asset_id']);
                }
            }
        }
        $newStatus = ($forceClose || !empty($policy['close_movement_on_completion'])) ? 'completed' : 'in_progress';
        if ((string)$movement['status'] !== $newStatus) {
            $conn->prepare('UPDATE asset_movements SET status = ? WHERE id = ?')->execute([$newStatus, $movementId]);
            self::recordEvent($conn, $movementId, null, 'movement_status_changed', $ctx, (string)$movement['status'], $newStatus, 'Completion policy applied.');
        }
    }

    /**
     * Create a movement, its default single shipment and the initial asset links atomically.
     *
     * Required input: movement_type, purpose_id, asset_ids. Optional: cost_centre_id, notes.
     * The company is derived from the selected assets; mixed-company movements are refused.
     *
     * @return array{movement_id:int,shipment_id:int,shipment_number:int}
     */
    public static function createMovement(PDO $conn, ActorContext $ctx, array $in): array
    {
        $type = trim((string)($in['movement_type'] ?? ''));
        if (!in_array($type, self::MOVEMENT_TYPES, true)) {
            throw new ServiceError('validation', 'invalid_field', "'movement_type' must be outbound_dispatch or inbound_return.");
        }

        $assetIds = $in['asset_ids'] ?? null;
        if (!is_array($assetIds) || !$assetIds) {
            throw new ServiceError('validation', 'missing_field', "'asset_ids' must contain at least one asset id.");
        }
        $normalisedIds = [];
        foreach ($assetIds as $value) {
            if (!is_numeric($value) || (int)$value < 1) {
                throw new ServiceError('validation', 'invalid_field', "Every 'asset_ids' entry must be a positive asset id.");
            }
            $normalisedIds[] = (int)$value;
        }
        $normalisedIds = array_values(array_unique($normalisedIds));
        if (count($normalisedIds) !== count($assetIds)) {
            throw new ServiceError('validation', 'duplicate_asset', 'An asset may only be included once in a movement.');
        }
        $purposeId = $in['purpose_id'] ?? null;
        if (!is_numeric($purposeId) || (int)$purposeId < 1) {
            throw new ServiceError('validation', 'missing_field', "'purpose_id' is required.");
        }
        $notes = isset($in['notes']) ? trim((string)$in['notes']) : '';
        if (mb_strlen($notes) > 10000) {
            throw new ServiceError('validation', 'invalid_field', "'notes' must be at most 10000 characters.");
        }

        // Validate the selected assets, purpose and cost centre in the same transaction
        // as the writes, so concurrent deletion/deactivation cannot split the operation.
        $ownsTransaction = !$conn->inTransaction();
        if ($ownsTransaction) $conn->beginTransaction();
        try {
            $placeholders = implode(',', array_fill(0, count($normalisedIds), '?'));
            $assetStmt = $conn->prepare("SELECT id, hostname, asset_tag, tenant_id FROM assets WHERE id IN ($placeholders) ORDER BY id FOR UPDATE");
            $assetStmt->execute($normalisedIds);
            $assets = $assetStmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($assets) !== count($normalisedIds)) {
                throw new ServiceError('validation', 'invalid_asset', 'One or more selected assets do not exist.');
            }

            // An asset may participate in only one open logistics movement at a time.
            // Completed/cancelled history is retained, so a later return movement remains valid.
            $activeStmt = $conn->prepare("SELECT ma.asset_id, m.id AS movement_id
                FROM asset_movement_assets ma
                JOIN asset_movements m ON m.id = ma.movement_id
                WHERE ma.asset_id IN ($placeholders) AND m.status IN ('draft', 'in_progress')
                ORDER BY ma.asset_id, m.id FOR UPDATE");
            $activeStmt->execute($normalisedIds);
            $activeMovement = $activeStmt->fetch(PDO::FETCH_ASSOC);
            if ($activeMovement) {
                throw new ServiceError('conflict', 'asset_in_active_movement',
                    'Asset ' . (int)$activeMovement['asset_id'] . ' already belongs to open movement ' . (int)$activeMovement['movement_id'] . '.');
            }

            $tenantId = null;
            $defaultTenantId = getDefaultTenantId($conn);
            foreach ($assets as $asset) {
                $assetTenant = $asset['tenant_id'] === null ? $defaultTenantId : (int)$asset['tenant_id'];
                self::assertTenantScope($ctx, $assetTenant);
                if ($tenantId !== null && $tenantId !== $assetTenant) {
                    throw new ServiceError('validation', 'mixed_companies', 'A movement can only contain assets belonging to one company.');
                }
                $tenantId = $assetTenant;
            }
            $tenantId = $tenantId ?? $defaultTenantId;
            self::assertTenantScope($ctx, $tenantId);
            $storedTenant = self::storedTenantId($tenantId, $conn);

            $purposeStmt = $conn->prepare('SELECT p.id, p.movement_type, p.is_active, p.tenant_id FROM asset_movement_purposes p WHERE p.id = ? AND (p.tenant_id = ? OR (p.tenant_id IS NULL AND NOT EXISTS (SELECT 1 FROM asset_movement_purposes o WHERE o.tenant_id = ? AND o.override_of_id = p.id))) FOR UPDATE');
            $purposeStmt->execute([(int)$purposeId, $tenantId, $tenantId]);
            $purpose = $purposeStmt->fetch(PDO::FETCH_ASSOC);
            if (!$purpose || $purpose['movement_type'] !== $type || !(int)$purpose['is_active']
                || ($purpose['tenant_id'] !== null && (int)$purpose['tenant_id'] !== $tenantId)) {
                throw new ServiceError('validation', 'invalid_purpose', 'The selected purpose is not available for this movement type and company.');
            }

            $costCentreId = costCentreCheck($conn, $tenantId, $in['cost_centre_id'] ?? null, null);
            $recipientUserId = null;
            if ($type === 'inbound_return' && (!isset($in['recipient_user_id']) || $in['recipient_user_id'] === '' || $in['recipient_user_id'] === null)) {
                throw new ServiceError('validation', 'return_recipient_required', 'Select the employee whose assigned assets are being returned.');
            }
            if (isset($in['recipient_user_id']) && $in['recipient_user_id'] !== '' && $in['recipient_user_id'] !== null) {
                if (!is_numeric($in['recipient_user_id']) || (int)$in['recipient_user_id'] < 1) {
                    throw new ServiceError('validation', 'invalid_recipient', 'The employee must be an active portal user in the same company.');
                }
                $recipientStmt = $conn->prepare('SELECT id, tenant_id, is_active FROM users WHERE id = ? FOR UPDATE');
                $recipientStmt->execute([(int)$in['recipient_user_id']]);
                $recipient = $recipientStmt->fetch(PDO::FETCH_ASSOC);
                $recipientTenant = $recipient && $recipient['tenant_id'] !== null ? (int)$recipient['tenant_id'] : $defaultTenantId;
                if (!$recipient || !(int)$recipient['is_active'] || $recipientTenant !== $tenantId) {
                    throw new ServiceError('validation', 'invalid_recipient', 'The recipient must be an active portal user in the same company as the assets.');
                }
                $recipientUserId = (int)$recipient['id'];
                if ($type === 'inbound_return') {
                    $assigned = $conn->prepare("SELECT COUNT(*) FROM users_assets WHERE user_id = ? AND asset_id IN ($placeholders)");
                    $assigned->execute(array_merge([$recipientUserId], $normalisedIds));
                    if ((int)$assigned->fetchColumn() !== count($normalisedIds)) {
                        throw new ServiceError('validation', 'recipient_asset_mismatch', 'Every asset in a return movement must currently be assigned to the selected employee.');
                    }
                }
            }

            $insertMovement = $conn->prepare("INSERT INTO asset_movements
                (movement_type, purpose_id, tenant_id, status, cost_centre_id, notes, created_by_analyst_id)
                VALUES (?, ?, ?, 'draft', ?, ?, ?)");
            $insertMovement->execute([$type, (int)$purposeId, $storedTenant, $costCentreId, $notes !== '' ? $notes : null, $ctx->actorId > 0 ? $ctx->actorId : null]);
            $movementId = (int)$conn->lastInsertId();

            $carrierId = isset($in['carrier_supplier_id']) && $in['carrier_supplier_id'] !== '' ? (int)$in['carrier_supplier_id'] : null;
            if ($carrierId !== null && $carrierId < 1) throw new ServiceError('validation', 'invalid_carrier', 'Choose a configured carrier.');
            if ($carrierId !== null) {
                $carrierStmt = $conn->prepare("SELECT c.supplier_id, COALESCE(NULLIF(s.trading_name, ''), s.legal_name) AS carrier_name FROM asset_logistics_carriers c JOIN suppliers s ON s.id = c.supplier_id WHERE c.supplier_id = ? AND c.tenant_id = ? AND c.is_active = 1 AND s.is_active = 1");
                $carrierStmt->execute([$carrierId, $tenantId]); $defaultCarrier = $carrierStmt->fetch(PDO::FETCH_ASSOC);
                if (!$defaultCarrier) throw new ServiceError('validation', 'invalid_carrier', 'The selected carrier is not configured and active.');
            } else {
                $defaultCarrierStmt = $conn->prepare("SELECT c.supplier_id, COALESCE(NULLIF(s.trading_name, ''), s.legal_name) AS carrier_name
                    FROM asset_logistics_carriers c JOIN suppliers s ON s.id = c.supplier_id
                    WHERE c.tenant_id = ? AND c.is_active = 1 AND c.is_default = 1 AND s.is_active = 1 LIMIT 1");
                $defaultCarrierStmt->execute([$tenantId]); $defaultCarrier = $defaultCarrierStmt->fetch(PDO::FETCH_ASSOC);
            }
            $insertShipment = $conn->prepare("INSERT INTO asset_shipments
                (movement_id, shipment_number, status, recipient_user_id, carrier_supplier_id, carrier_name, transport_mode, created_by_analyst_id)
                VALUES (?, 1, 'pending', ?, ?, ?, 'surface', ?)");
            $insertShipment->execute([$movementId, $recipientUserId, $defaultCarrier ? (int)$defaultCarrier['supplier_id'] : null, $defaultCarrier['carrier_name'] ?? null, $ctx->actorId > 0 ? $ctx->actorId : null]);
            $shipmentId = (int)$conn->lastInsertId();

            $insertAsset = $conn->prepare('INSERT INTO asset_movement_assets
                (movement_id, shipment_id, asset_id, asset_tag_snapshot, hostname_snapshot)
                VALUES (?, ?, ?, ?, ?)');
            foreach ($assets as $asset) {
                $insertAsset->execute([$movementId, $shipmentId, (int)$asset['id'], $asset['asset_tag'], $asset['hostname']]);
            }
            self::recordEvent($conn, $movementId, null, 'movement_created', $ctx, null, $type, null);
            self::recordEvent($conn, $movementId, $shipmentId, 'shipment_created', $ctx, null, '1', null);
            if ($ownsTransaction) $conn->commit();
            if ($ownsTransaction && $recipientUserId !== null) {
                $title = $type === 'inbound_return' ? 'Asset return arrangements created' : 'Asset delivery shipment created';
                $body = $type === 'inbound_return'
                    ? 'Staff has initiated an asset return. Open My Assets to review the arrangements as they are provided.'
                    : 'An asset shipment has been created for you. Open My Assets to view delivery details when available.';
                self::notifyShipmentRecipient($conn, $shipmentId, $title, $body);
            }
            return ['movement_id' => $movementId, 'shipment_id' => $shipmentId, 'shipment_number' => 1];
        } catch (Throwable $e) {
            if ($ownsTransaction && $conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
    }

    /** Return the effective cost centre; a shipment override wins, otherwise movement value follows dynamically. */
    public static function effectiveShipmentCostCentre(PDO $conn, ActorContext $ctx, int $shipmentId): ?int
    {
        $st = $conn->prepare('SELECT m.tenant_id, COALESCE(s.cost_centre_id, m.cost_centre_id) AS effective_cost_centre_id
            FROM asset_shipments s JOIN asset_movements m ON m.id = s.movement_id WHERE s.id = ?');
        $st->execute([$shipmentId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new ServiceError('not_found', 'not_found', 'Shipment not found.');
        $tenantId = $row['tenant_id'] === null ? getDefaultTenantId($conn) : (int)$row['tenant_id'];
        self::assertTenantScope($ctx, $tenantId);
        return $row['effective_cost_centre_id'] === null ? null : (int)$row['effective_cost_centre_id'];
    }

    /** Set an explicit shipment cost-centre override; null clears it and restores inheritance. */
    public static function setShipmentCostCentreOverride(PDO $conn, ActorContext $ctx, int $shipmentId, $costCentreValue): ?int
    {
        $ownsTransaction = !$conn->inTransaction();
        if ($ownsTransaction) $conn->beginTransaction();
        try {
            // Lock the parent movement and shipment before validating the cost centre.
            // This prevents a concurrent update from changing the tenant or override
            // between validation and write, and serialises this mutation with splits.
            $st = $conn->prepare('SELECT m.id AS movement_id, m.tenant_id, s.cost_centre_id AS current_override
                FROM asset_shipments s JOIN asset_movements m ON m.id = s.movement_id
                WHERE s.id = ? FOR UPDATE');
            $st->execute([$shipmentId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new ServiceError('not_found', 'not_found', 'Shipment not found.');
            $tenantId = $row['tenant_id'] === null ? getDefaultTenantId($conn) : (int)$row['tenant_id'];
            self::assertTenantScope($ctx, $tenantId);
            $current = $row['current_override'] === null ? null : (int)$row['current_override'];
            $new = costCentreCheck($conn, $tenantId, $costCentreValue, $current);
            if ($new !== $current) {
                $upd = $conn->prepare('UPDATE asset_shipments SET cost_centre_id = ? WHERE id = ?');
                $upd->execute([$new, $shipmentId]);
                self::recordEvent($conn, (int)$row['movement_id'], $shipmentId, 'shipment_cost_centre_override_changed', $ctx,
                    $current === null ? null : (string)$current,
                    $new === null ? null : (string)$new,
                    $new === null ? 'Shipment now inherits the movement cost centre.' : 'Shipment cost centre override set.');
            }
            $effective = $conn->prepare('SELECT COALESCE(s.cost_centre_id, m.cost_centre_id)
                FROM asset_shipments s JOIN asset_movements m ON m.id = s.movement_id WHERE s.id = ?');
            $effective->execute([$shipmentId]);
            $value = $effective->fetchColumn();
            if ($ownsTransaction) $conn->commit();
            return $value === false || $value === null ? null : (int)$value;
        } catch (Throwable $e) {
            if ($ownsTransaction && $conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
    }

    /**
     * Split selected assets out of an existing shipment into a new shipment.
     * Both the original and new shipment must retain at least one asset.
     * New shipment tracking details start blank; the existing shipment is untouched.
     *
     * @return array{shipment_id:int,shipment_number:int}
     */
    public static function splitShipment(PDO $conn, ActorContext $ctx, int $shipmentId, array $assetIds): array
    {
        if (!$assetIds) {
            throw new ServiceError('validation', 'missing_field', "'asset_ids' must contain at least one asset id to split.");
        }
        $ids = [];
        foreach ($assetIds as $value) {
            if (!is_numeric($value) || (int)$value < 1) {
                throw new ServiceError('validation', 'invalid_field', "Every 'asset_ids' entry must be a positive asset id.");
            }
            $ids[] = (int)$value;
        }
        $ids = array_values(array_unique($ids));
        if (count($ids) !== count($assetIds)) {
            throw new ServiceError('validation', 'duplicate_asset', 'An asset may only be selected once for a shipment split.');
        }

        $ownsTransaction = !$conn->inTransaction();
        if ($ownsTransaction) $conn->beginTransaction();
        try {
            $shipmentStmt = $conn->prepare('SELECT s.id, s.movement_id, s.shipment_number, s.status, s.tracking_number,
                    m.tenant_id, m.status AS movement_status
                FROM asset_shipments s JOIN asset_movements m ON m.id = s.movement_id
                WHERE s.id = ? FOR UPDATE');
            $shipmentStmt->execute([$shipmentId]);
            $shipment = $shipmentStmt->fetch(PDO::FETCH_ASSOC);
            if (!$shipment) throw new ServiceError('not_found', 'not_found', 'Shipment not found.');
            $tenantId = $shipment['tenant_id'] === null ? getDefaultTenantId($conn) : (int)$shipment['tenant_id'];
            self::assertTenantScope($ctx, $tenantId);
            if ($shipment['movement_status'] !== 'draft' || $shipment['status'] !== 'pending') {
                throw new ServiceError('conflict', 'shipment_not_splittable', 'Only a pending shipment on a draft movement can be split.');
            }
            if (trim((string)($shipment['tracking_number'] ?? '')) !== '') {
                throw new ServiceError('conflict', 'shipment_awb_exists', 'A shipment cannot be split after a tracking number/reference has been recorded. Cancel it and create a replacement shipment.');
            }

            $ph = implode(',', array_fill(0, count($ids), '?'));
            $assetStmt = $conn->prepare("SELECT id, shipment_id FROM asset_movement_assets WHERE movement_id = ? AND asset_id IN ($ph) FOR UPDATE");
            $assetStmt->execute(array_merge([(int)$shipment['movement_id']], $ids));
            $links = $assetStmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($links) !== count($ids)) {
                throw new ServiceError('validation', 'asset_not_in_movement', 'Every selected asset must belong to this movement.');
            }
            foreach ($links as $link) {
                if ((int)$link['shipment_id'] !== $shipmentId) {
                    throw new ServiceError('validation', 'asset_not_in_source_shipment', 'Every selected asset must currently belong to the shipment being split.');
                }
            }
            $countStmt = $conn->prepare('SELECT COUNT(*) FROM asset_movement_assets WHERE shipment_id = ?');
            $countStmt->execute([$shipmentId]);
            if ((int)$countStmt->fetchColumn() <= count($ids)) {
                throw new ServiceError('validation', 'empty_source_shipment', 'A split must leave at least one asset in the original shipment.');
            }

            // Serialize shipment-number allocation for this movement. The unique
            // movement/number index remains the final integrity guard.
            $movementLock = $conn->prepare('SELECT id FROM asset_movements WHERE id = ? FOR UPDATE');
            $movementLock->execute([(int)$shipment['movement_id']]);
            $numStmt = $conn->prepare('SELECT COALESCE(MAX(shipment_number), 0) + 1 FROM asset_shipments WHERE movement_id = ?');
            $numStmt->execute([(int)$shipment['movement_id']]);
            $newNumber = (int)$numStmt->fetchColumn();
            $newStmt = $conn->prepare("INSERT INTO asset_shipments
                (movement_id, shipment_number, status, recipient_user_id, collection_method, pickup_address, pickup_scheduled_at, dropoff_location, return_instructions, created_by_analyst_id)
                SELECT movement_id, ?, 'pending', recipient_user_id, collection_method, pickup_address, pickup_scheduled_at, dropoff_location, return_instructions, ?
                FROM asset_shipments WHERE id = ?");
            $newStmt->execute([$newNumber, $ctx->actorId > 0 ? $ctx->actorId : null, $shipmentId]);
            $newShipmentId = (int)$conn->lastInsertId();
            $moveStmt = $conn->prepare("UPDATE asset_movement_assets SET shipment_id = ? WHERE movement_id = ? AND asset_id IN ($ph) AND shipment_id = ?");
            $moveStmt->execute(array_merge([$newShipmentId, (int)$shipment['movement_id']], $ids, [$shipmentId]));
            if ($moveStmt->rowCount() !== count($ids)) {
                throw new ServiceError('conflict', 'split_race', 'The asset assignments changed while the shipment was being split. Please reload and try again.');
            }
            self::recordEvent($conn, (int)$shipment['movement_id'], $shipmentId, 'shipment_split_out', $ctx, null, (string)$newNumber,
                count($ids) . ' asset(s) moved to shipment ' . $newNumber . '.');
            self::recordEvent($conn, (int)$shipment['movement_id'], $newShipmentId, 'shipment_split_created', $ctx, null, (string)$newNumber,
                'Split from shipment ' . (int)$shipment['shipment_number'] . '.');
            if ($ownsTransaction) $conn->commit();
            return ['shipment_id' => $newShipmentId, 'shipment_number' => $newNumber];
        } catch (Throwable $e) {
            if ($ownsTransaction && $conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
    }

    /** Recreate a cancelled shipment within the same movement, preserving its assets and audit history. */
    public static function replaceCancelledShipment(PDO $conn, ActorContext $ctx, int $shipmentId): array
    {
        if ($shipmentId < 1) throw new ServiceError('validation', 'invalid_shipment', 'A valid shipment is required.');
        $ownsTransaction = !$conn->inTransaction();
        if ($ownsTransaction) $conn->beginTransaction();
        try {
            $st = $conn->prepare('SELECT s.*, m.tenant_id, m.status AS movement_status, m.movement_type FROM asset_shipments s JOIN asset_movements m ON m.id = s.movement_id WHERE s.id = ? FOR UPDATE');
            $st->execute([$shipmentId]);
            $old = $st->fetch(PDO::FETCH_ASSOC);
            if (!$old) throw new ServiceError('not_found', 'not_found', 'Shipment not found.');
            $tenantId = $old['tenant_id'] === null ? getDefaultTenantId($conn) : (int)$old['tenant_id'];
            self::assertTenantScope($ctx, $tenantId);
            if ($old['status'] !== 'cancelled') throw new ServiceError('conflict', 'shipment_not_cancelled', 'Only a cancelled shipment can be replaced.');
            if ($old['movement_status'] === 'completed') throw new ServiceError('conflict', 'movement_completed', 'A completed movement cannot be reopened; create a new movement instead.');
            $assetStmt = $conn->prepare('SELECT id, asset_id FROM asset_movement_assets WHERE shipment_id = ? ORDER BY id FOR UPDATE');
            $assetStmt->execute([$shipmentId]);
            $links = $assetStmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$links) throw new ServiceError('conflict', 'shipment_has_no_assets', 'The cancelled shipment has no assets to replace.');
            $movementLock = $conn->prepare('SELECT id FROM asset_movements WHERE id = ? FOR UPDATE');
            $movementLock->execute([(int)$old['movement_id']]);
            $numStmt = $conn->prepare('SELECT COALESCE(MAX(shipment_number), 0) + 1 FROM asset_shipments WHERE movement_id = ?');
            $numStmt->execute([(int)$old['movement_id']]);
            $newNumber = (int)$numStmt->fetchColumn();
            $ins = $conn->prepare("INSERT INTO asset_shipments (movement_id, shipment_number, status, recipient_user_id, cost_centre_id, collection_method, pickup_address, pickup_scheduled_at, dropoff_location, return_instructions, notes, created_by_analyst_id) VALUES (?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $ins->execute([
                (int)$old['movement_id'], $newNumber, $old['recipient_user_id'], $old['cost_centre_id'],
                $old['collection_method'], $old['pickup_address'], $old['pickup_scheduled_at'], $old['dropoff_location'],
                $old['return_instructions'], $old['notes'], $ctx->actorId > 0 ? $ctx->actorId : null,
            ]);
            $newId = (int)$conn->lastInsertId();
            $move = $conn->prepare('UPDATE asset_movement_assets SET shipment_id = ? WHERE shipment_id = ?');
            $move->execute([$newId, $shipmentId]);
            if ($move->rowCount() !== count($links)) throw new ServiceError('conflict', 'replacement_asset_race', 'Shipment assets changed during replacement. Reload and try again.');
            self::recordEvent($conn, (int)$old['movement_id'], $shipmentId, 'shipment_replaced', $ctx, (string)$old['shipment_number'], (string)$newNumber, 'Cancelled shipment replaced by shipment ' . $newNumber . '.');
            foreach ($links as $link) {
                self::recordEvent($conn, (int)$old['movement_id'], $shipmentId, 'asset_moved_to_replacement_shipment', $ctx, (string)$shipmentId, (string)$newId, 'Asset ' . (int)$link['asset_id']);
            }
            self::recordEvent($conn, (int)$old['movement_id'], $newId, 'replacement_shipment_created', $ctx, null, (string)$newNumber, 'Replacement for cancelled shipment ' . (int)$old['shipment_number'] . '.');
            if ($old['movement_status'] === 'cancelled') {
                $conn->prepare("UPDATE asset_movements SET status = 'in_progress' WHERE id = ?")->execute([(int)$old['movement_id']]);
                self::recordEvent($conn, (int)$old['movement_id'], null, 'movement_reopened_for_replacement', $ctx, 'cancelled', 'in_progress');
            }
            if ($ownsTransaction) $conn->commit();
            if ($ownsTransaction) self::notifyShipmentRecipient($conn, $newId, 'Replacement asset shipment created', 'A replacement shipment has been created. Open My Assets to review the updated delivery or return details.');
            return ['shipment_id' => $newId, 'shipment_number' => $newNumber, 'movement' => self::getMovement($conn, $ctx, (int)$old['movement_id'])];
        } catch (Throwable $e) {
            if ($ownsTransaction && $conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
    }

    /** Update shipment-level carrier, tracking number and notes without changing lifecycle state. */
    public static function updateShipmentDetails(PDO $conn, ActorContext $ctx, int $shipmentId, array $in): array
    {
        $allowed = ['carrier_supplier_id', 'carrier_name', 'transport_mode', 'tracking_number', 'notes', 'recipient_user_id', 'collection_method', 'pickup_address', 'pickup_scheduled_at', 'dropoff_location', 'return_instructions'];
        foreach (array_keys($in) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new ServiceError('validation', 'invalid_field', "Unsupported shipment field '$key'.");
            }
        }
        if (!$in) throw new ServiceError('validation', 'missing_field', 'At least one shipment field must be supplied.');
        $values = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $in)) continue;
            if ($key === 'transport_mode') {
                $value = trim((string)($in[$key] ?? 'surface'));
                if (!in_array($value, ['air', 'surface', 'other'], true)) throw new ServiceError('validation', 'invalid_transport_mode', 'Transport mode must be air, surface, or other.');
            } elseif ($key === 'carrier_supplier_id') {
                if ($in[$key] === null || $in[$key] === '') $value = null;
                elseif (!is_numeric($in[$key]) || (int)$in[$key] < 1) throw new ServiceError('validation', 'invalid_carrier', 'Choose a configured carrier.');
                else $value = (string)(int)$in[$key];
            } elseif ($key === 'recipient_user_id') {
                if ($in[$key] === null || $in[$key] === '') $value = null;
                elseif (!is_numeric($in[$key]) || (int)$in[$key] < 1) throw new ServiceError('validation', 'invalid_recipient', 'The recipient must be an active portal user in the same company.');
                else $value = (string)(int)$in[$key];
            } else {
                $value = $in[$key] === null ? null : trim((string)$in[$key]);
            }
            $max = in_array($key, ['notes', 'pickup_address', 'dropoff_location', 'return_instructions'], true) ? 10000 : (in_array($key, ['pickup_scheduled_at'], true) ? 30 : 150);
            if ($value !== null && mb_strlen($value) > $max) {
                throw new ServiceError('validation', 'invalid_field', "'$key' must be at most $max characters.");
            }
            $values[$key] = ($value === '') ? null : $value;
        }
        if (array_key_exists('collection_method', $values) && $values['collection_method'] !== null && !in_array($values['collection_method'], ['home_pickup', 'drop_off'], true)) {
            throw new ServiceError('validation', 'invalid_collection_method', 'Collection method must be home_pickup or drop_off.');
        }
        if (array_key_exists('pickup_scheduled_at', $values) && $values['pickup_scheduled_at'] !== null) {
            $dt = date_create_from_format('Y-m-d\TH:i', $values['pickup_scheduled_at']) ?: date_create($values['pickup_scheduled_at']);
            if (!$dt) throw new ServiceError('validation', 'invalid_pickup_datetime', 'Pickup date/time is invalid.');
            $values['pickup_scheduled_at'] = $dt->format('Y-m-d H:i:s');
        }

        $ownsTransaction = !$conn->inTransaction();
        if ($ownsTransaction) $conn->beginTransaction();
        try {
            $currentStmt = $conn->prepare('SELECT s.*, m.tenant_id, m.status AS movement_status, m.movement_type
                FROM asset_shipments s JOIN asset_movements m ON m.id = s.movement_id
                WHERE s.id = ? FOR UPDATE');
            $currentStmt->execute([$shipmentId]);
            $current = $currentStmt->fetch(PDO::FETCH_ASSOC);
            if (!$current) throw new ServiceError('not_found', 'not_found', 'Shipment not found.');
            $tenantId = $current['tenant_id'] === null ? getDefaultTenantId($conn) : (int)$current['tenant_id'];
            self::assertTenantScope($ctx, $tenantId);
            if ($current['status'] === 'cancelled' || $current['status'] === 'delivered') {
                throw new ServiceError('conflict', 'shipment_closed', 'Shipment details cannot be changed on a delivered or cancelled shipment.');
            }
            $isReturn = (($current['movement_type'] ?? '') === 'inbound_return');
            if (array_key_exists('transport_mode', $values) && trim((string)($current['tracking_number'] ?? '')) !== ''
                && (string)$values['transport_mode'] !== (string)($current['transport_mode'] ?? 'surface')) {
                throw new ServiceError('conflict', 'transport_mode_locked_after_tracking', 'Transport mode cannot be changed after a tracking reference is recorded. Cancel the shipment and create a replacement instead.');
            }
            if (array_key_exists('carrier_supplier_id', $values) && trim((string)($current['tracking_number'] ?? '')) !== ''
                && (string)($values['carrier_supplier_id'] ?? '') !== (string)($current['carrier_supplier_id'] ?? '')) {
                throw new ServiceError('conflict', 'carrier_locked_after_tracking', 'The carrier cannot be changed after a tracking number is recorded. Cancel the shipment and create a replacement instead.');
            }
            if (array_key_exists('carrier_name', $values) && trim((string)($current['tracking_number'] ?? '')) !== ''
                && (string)($values['carrier_name'] ?? '') !== (string)($current['carrier_name'] ?? '')) {
                throw new ServiceError('conflict', 'carrier_locked_after_tracking', 'The carrier cannot be changed after a tracking number is recorded. Cancel the shipment and create a replacement instead.');
            }
            if (array_key_exists('tracking_number', $values)) {
                $oldTracking = trim((string)($current['tracking_number'] ?? ''));
                $newTracking = trim((string)($values['tracking_number'] ?? ''));
                if ($oldTracking !== '' && $oldTracking !== $newTracking) {
                    throw new ServiceError('conflict', 'tracking_number_immutable', 'A tracking number/reference cannot be changed or cleared after it has been recorded. Cancel the shipment and create a replacement instead.');
                }
            }
            if (array_key_exists('recipient_user_id', $values)) {
                $oldRecipient = $current['recipient_user_id'] === null ? null : (int)$current['recipient_user_id'];
                $newRecipient = $values['recipient_user_id'] === null ? null : (int)$values['recipient_user_id'];
                if ($oldRecipient !== $newRecipient && trim((string)($current['tracking_number'] ?? '')) !== '') {
                    throw new ServiceError('conflict', 'recipient_locked_after_awb', 'The recipient cannot be changed after a tracking number is recorded. Cancel the shipment and create a replacement instead.');
                }
                if ($oldRecipient !== $newRecipient && $isReturn) {
                    throw new ServiceError('validation', 'return_recipient_locked', 'A return shipment must remain assigned to the employee whose assets are being returned.');
                }
                if ($newRecipient !== null) {
                    $recipientStmt = $conn->prepare('SELECT id, tenant_id, is_active FROM users WHERE id = ? FOR UPDATE');
                    $recipientStmt->execute([$newRecipient]);
                    $recipient = $recipientStmt->fetch(PDO::FETCH_ASSOC);
                    $recipientTenant = $recipient && $recipient['tenant_id'] !== null ? (int)$recipient['tenant_id'] : getDefaultTenantId($conn);
                    if (!$recipient || !(int)$recipient['is_active'] || $recipientTenant !== $tenantId) {
                        throw new ServiceError('validation', 'invalid_recipient', 'The recipient must be an active portal user in the same company as the shipment.');
                    }
                }
            }
            if (array_key_exists('carrier_supplier_id', $values)) {
                if ($values['carrier_supplier_id'] === null) {
                    if (!array_key_exists('carrier_name', $values) || trim((string)$values['carrier_name']) === '') $values['carrier_name'] = null;
                } else {
                    $carrierStmt = $conn->prepare("SELECT COALESCE(NULLIF(s.trading_name, ''), s.legal_name) FROM asset_logistics_carriers c JOIN suppliers s ON s.id = c.supplier_id WHERE c.supplier_id = ? AND c.tenant_id = ? AND c.is_active = 1 AND s.is_active = 1");
                    $carrierStmt->execute([(int)$values['carrier_supplier_id'], $tenantId]); $carrierName = $carrierStmt->fetchColumn();
                    if (!$carrierName) throw new ServiceError('validation', 'invalid_carrier', 'The selected carrier is not configured and active.');
                    $values['carrier_name'] = (string)$carrierName;
                }
            }
            if (!$isReturn && array_intersect(array_keys($values), ['collection_method', 'pickup_address', 'pickup_scheduled_at', 'dropoff_location', 'return_instructions'])) {
                throw new ServiceError('validation', 'return_fields_only', 'Collection arrangements can only be set on inbound return shipments.');
            }
            if ($isReturn && array_key_exists('collection_method', $values)) {
                $method = $values['collection_method'];
                $pickupAddress = array_key_exists('pickup_address', $values) ? $values['pickup_address'] : $current['pickup_address'];
                $dropoffLocation = array_key_exists('dropoff_location', $values) ? $values['dropoff_location'] : $current['dropoff_location'];
                if ($method === 'home_pickup' && trim((string)$pickupAddress) === '') throw new ServiceError('validation', 'pickup_address_required', 'A home pickup requires a pickup address.');
                if ($method === 'drop_off' && trim((string)$dropoffLocation) === '') throw new ServiceError('validation', 'dropoff_location_required', 'A drop-off return requires a drop-off location.');
                // Do not retain stale, potentially sensitive details from the other collection method.
                if ($method === 'home_pickup') $values['dropoff_location'] = null;
                if ($method === 'drop_off') $values['pickup_address'] = null;
            }
            $set = [];
            $args = [];
            $changedAny = false;
            foreach ($values as $key => $value) {
                $set[] = "$key = ?"; $args[] = $value;
                $oldValue = $current[$key] === null ? null : (string)$current[$key];
                $newValue = $value === null ? null : (string)$value;
                if ($oldValue !== $newValue) $changedAny = true;
            }
            $args[] = $shipmentId;
            $args[] = (int)$current['movement_id'];
            $update = $conn->prepare('UPDATE asset_shipments SET ' . implode(', ', $set) . ' WHERE id = ? AND movement_id = ?');
            $update->execute($args);
            $arrangementChanged = false;
            foreach ($values as $key => $value) {
                $old = $current[$key] === null ? null : (string)$current[$key];
                $new = $value === null ? null : (string)$value;
                if ($old !== $new) {
                    self::recordEvent($conn, (int)$current['movement_id'], $shipmentId, 'shipment_' . $key . '_changed', $ctx, $old, $new);
                    if ($isReturn && in_array($key, ['collection_method', 'pickup_address', 'pickup_scheduled_at', 'dropoff_location', 'return_instructions'], true)) $arrangementChanged = true;
                }
            }
            if ($arrangementChanged && $current['return_acknowledged_at'] !== null) {
                $conn->prepare('UPDATE asset_shipments SET return_acknowledged_at = NULL WHERE id = ?')->execute([$shipmentId]);
                self::recordEvent($conn, (int)$current['movement_id'], $shipmentId, 'employee_return_acknowledgement_reset', $ctx, 'acknowledged', 'pending', 'Return arrangements changed; employee acknowledgement is required again.');
            }
            if ($ownsTransaction) $conn->commit();
            if ($ownsTransaction && array_key_exists('recipient_user_id', $values)) {
                $oldRecipient = $current['recipient_user_id'] === null ? null : (int)$current['recipient_user_id'];
                $newRecipient = $values['recipient_user_id'] === null ? null : (int)$values['recipient_user_id'];
                if ($oldRecipient !== null && $oldRecipient !== $newRecipient) {
                    self::notifyPortal($conn, $oldRecipient, $shipmentId, 'Shipment recipient changed', 'This shipment is no longer assigned to you. Open My Assets or contact the logistics team if you need clarification.');
                }
            }
            if ($ownsTransaction && $changedAny) {
                $isReturn = (($current['movement_type'] ?? '') === 'inbound_return');
                $hasArrangement = in_array($values['collection_method'] ?? $current['collection_method'] ?? null, ['home_pickup', 'drop_off'], true);
                $body = $isReturn && $hasArrangement
                    ? 'Your asset return arrangements have been updated. Open My Assets to view pickup/drop-off details.'
                    : 'Your asset shipment details have been updated. Open My Assets to view tracking information.';
                self::notifyShipmentRecipient($conn, $shipmentId, $isReturn ? 'Asset return arrangements updated' : 'Asset shipment updated', $body);
            }
            return self::getMovement($conn, $ctx, (int)$current['movement_id']);
        } catch (Throwable $e) {
            if ($ownsTransaction && $conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
    }

    /**
     * Advance a shipment through the logistics state machine and derive movement status.
     * Asset status is intentionally not modified here; receipt policy is a separate concern.
     */
    public static function transitionShipment(PDO $conn, ActorContext $ctx, int $shipmentId, string $newStatus, ?string $notes = null): array
    {
        $transitions = [
            'pending' => ['dispatched', 'cancelled'],
            'dispatched' => ['in_transit', 'delivered', 'exception', 'cancelled'],
            'in_transit' => ['delivered', 'exception'],
            'exception' => ['in_transit', 'delivered', 'cancelled'],
            'delivered' => [],
            'cancelled' => [],
        ];
        if (!array_key_exists($newStatus, $transitions)) {
            throw new ServiceError('validation', 'invalid_status', 'The requested shipment status is not supported.');
        }
        if ($notes !== null) {
            $notes = trim($notes);
            if (mb_strlen($notes) > 10000) throw new ServiceError('validation', 'invalid_field', "'notes' must be at most 10000 characters.");
        }
        $ownsTransaction = !$conn->inTransaction();
        if ($ownsTransaction) $conn->beginTransaction();
        try {
            $st = $conn->prepare('SELECT s.status, s.movement_id, s.collection_method, s.pickup_address, s.dropoff_location, m.tenant_id, m.status AS movement_status, m.movement_type
                FROM asset_shipments s JOIN asset_movements m ON m.id = s.movement_id WHERE s.id = ? FOR UPDATE');
            $st->execute([$shipmentId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new ServiceError('not_found', 'not_found', 'Shipment not found.');
            $tenantId = $row['tenant_id'] === null ? getDefaultTenantId($conn) : (int)$row['tenant_id'];
            self::assertTenantScope($ctx, $tenantId);
            $oldStatus = (string)$row['status'];
            if ($oldStatus === $newStatus) {
                if ($ownsTransaction) $conn->commit();
                return self::getMovement($conn, $ctx, (int)$row['movement_id']);
            }
            if (!in_array($newStatus, $transitions[$oldStatus] ?? [], true)) {
                throw new ServiceError('conflict', 'invalid_transition', "Shipment status cannot change from '$oldStatus' to '$newStatus'.");
            }
            if ($newStatus === 'dispatched' && $row['movement_type'] === 'inbound_return') {
                if ($row['collection_method'] === 'home_pickup' && trim((string)$row['pickup_address']) !== '') { /* complete */ }
                elseif ($row['collection_method'] === 'drop_off' && trim((string)$row['dropoff_location']) !== '') { /* complete */ }
                else throw new ServiceError('conflict', 'return_arrangement_incomplete', 'Set a home pickup address or drop-off location before dispatching a return shipment.');
            }
            $up = $conn->prepare('UPDATE asset_shipments SET status = ? WHERE id = ?');
            $up->execute([$newStatus, $shipmentId]);
            self::recordEvent($conn, (int)$row['movement_id'], $shipmentId, 'shipment_status_changed', $ctx, $oldStatus, $newStatus, $notes);
            $statusStmt = $conn->prepare('SELECT status FROM asset_shipments WHERE movement_id = ?');
            $statusStmt->execute([(int)$row['movement_id']]);
            $statuses = $statusStmt->fetchAll(PDO::FETCH_COLUMN);
            $movementStatus = 'draft';
            if (count($statuses) > 0) {
                $allClosed = true;
                $anyStarted = false;
                $allCancelled = true;
                foreach ($statuses as $status) {
                    if (!in_array($status, ['delivered', 'cancelled'], true)) $allClosed = false;
                    if ($status !== 'pending') $anyStarted = true;
                    if ($status !== 'cancelled') $allCancelled = false;
                }
                if ($allClosed) {
                    if ($allCancelled) $movementStatus = 'cancelled';
                    else {
                        $movementStatus = 'in_progress';
                        self::applyCompletionPolicy($conn, $ctx, (int)$row['movement_id']);
                        $refresh = $conn->prepare('SELECT status FROM asset_movements WHERE id = ?');
                        $refresh->execute([(int)$row['movement_id']]);
                        $movementStatus = (string)$refresh->fetchColumn();
                    }
                } elseif ($anyStarted) $movementStatus = 'in_progress';
            }
            $currentMovement = $conn->prepare('SELECT status FROM asset_movements WHERE id = ? FOR UPDATE');
            $currentMovement->execute([(int)$row['movement_id']]);
            $oldMovementStatus = (string)$currentMovement->fetchColumn();
            if ($oldMovementStatus !== $movementStatus) {
                $mu = $conn->prepare('UPDATE asset_movements SET status = ? WHERE id = ?');
                $mu->execute([$movementStatus, (int)$row['movement_id']]);
                self::recordEvent($conn, (int)$row['movement_id'], null, 'movement_status_changed', $ctx, $oldMovementStatus, $movementStatus);
            }
            if ($ownsTransaction) $conn->commit();
            if ($ownsTransaction) {
                $isReturn = $row['movement_type'] === 'inbound_return';
                if ($newStatus === 'cancelled') {
                    $title = $isReturn ? 'Asset return shipment cancelled' : 'Asset delivery shipment cancelled';
                    $body = $isReturn
                        ? 'The return shipment has been cancelled. Please check My Assets for updated return arrangements.'
                        : 'The delivery shipment has been cancelled. Please check My Assets for updated delivery information.';
                } else {
                    $title = $isReturn ? 'Asset return status updated' : 'Asset delivery status updated';
                    $body = 'Shipment status is now ' . str_replace('_', ' ', $newStatus) . '. Open My Assets for details.';
                }
                self::notifyShipmentRecipient($conn, $shipmentId, $title, $body);
            }
            return self::getMovement($conn, $ctx, (int)$row['movement_id']);
        } catch (Throwable $e) {
            if ($ownsTransaction && $conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
    }

    /** Record receipt inspection outcomes for assets in a delivered shipment and apply configured status mappings. */
    public static function recordReceiptOutcomes(PDO $conn, ActorContext $ctx, int $shipmentId, array $outcomes, ?string $notes = null): array
    {
        if (!$outcomes) throw new ServiceError('validation', 'missing_field', 'At least one asset outcome is required.');
        if ($notes !== null) {
            $notes = trim($notes);
            if (mb_strlen($notes) > 10000) throw new ServiceError('validation', 'invalid_field', "'notes' must be at most 10000 characters.");
        }
        $normalised = [];
        foreach ($outcomes as $item) {
            if (!is_array($item) || !isset($item['asset_id'], $item['outcome_code']) || !is_numeric($item['asset_id']) || (int)$item['asset_id'] < 1) {
                throw new ServiceError('validation', 'invalid_outcome', 'Each outcome must contain a positive asset_id and outcome_code.');
            }
            $assetId = (int)$item['asset_id'];
            $code = trim((string)$item['outcome_code']);
            if (!in_array($code, ['passed', 'minor_wear', 'damaged', 'scrap'], true)) {
                throw new ServiceError('validation', 'invalid_outcome', 'Outcome must be passed, minor_wear, damaged or scrap.');
            }
            if (isset($normalised[$assetId])) throw new ServiceError('validation', 'duplicate_asset', 'Each asset may only have one outcome per request.');
            $normalised[$assetId] = $code;
        }

        $ownsTransaction = !$conn->inTransaction();
        if ($ownsTransaction) $conn->beginTransaction();
        try {
            $st = $conn->prepare('SELECT s.id, s.status, s.movement_id, m.tenant_id, m.movement_type
                FROM asset_shipments s JOIN asset_movements m ON m.id = s.movement_id WHERE s.id = ? FOR UPDATE');
            $st->execute([$shipmentId]);
            $shipment = $st->fetch(PDO::FETCH_ASSOC);
            if (!$shipment) throw new ServiceError('not_found', 'not_found', 'Shipment not found.');
            $tenantId = $shipment['tenant_id'] === null ? getDefaultTenantId($conn) : (int)$shipment['tenant_id'];
            self::assertTenantScope($ctx, $tenantId);
            if ($shipment['status'] !== 'delivered') throw new ServiceError('conflict', 'shipment_not_received', 'Receipt outcomes can only be recorded after the shipment is marked delivered.');

            $policy = $conn->prepare('SELECT post_receipt_action, inspection_provider FROM asset_logistics_policies WHERE movement_type = ? AND (tenant_id = ? OR tenant_id IS NULL) ORDER BY tenant_id IS NOT NULL DESC LIMIT 1');
            $policy->execute([$shipment['movement_type'], $tenantId]);
            $policyRow = $policy->fetch(PDO::FETCH_ASSOC) ?: ['post_receipt_action' => 'none', 'inspection_provider' => null];
            if ((string)$policyRow['post_receipt_action'] !== 'inspection') {
                throw new ServiceError('conflict', 'inspection_not_configured', 'Receipt inspection is not configured for this movement type.');
            }
            // The manual endpoint must not silently bypass the configured checklist
            // provider. Checklist-backed inspection needs its own adapter/workflow.
            if (($policyRow['inspection_provider'] ?? null) === 'ticket_checklists') {
                throw new ServiceError('conflict', 'checklist_provider_unavailable', 'This movement type is configured for ticket checklist inspection; manual outcomes are disabled until that integration is available.');
            }

            $ids = array_keys($normalised);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $assetStmt = $conn->prepare("SELECT ma.asset_id, a.asset_status_id FROM asset_movement_assets ma JOIN assets a ON a.id = ma.asset_id WHERE ma.shipment_id = ? AND ma.asset_id IN ($ph) ORDER BY ma.asset_id FOR UPDATE");
            $assetStmt->execute(array_merge([$shipmentId], $ids));
            $assets = $assetStmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($assets) !== count($ids)) throw new ServiceError('validation', 'asset_not_in_shipment', 'Every inspected asset must belong to this shipment and still exist.');
            $countStmt = $conn->prepare('SELECT COUNT(*) FROM asset_movement_assets WHERE shipment_id = ? AND asset_id IS NOT NULL');
            $countStmt->execute([$shipmentId]);
            if ((int)$countStmt->fetchColumn() !== count($ids)) {
                throw new ServiceError('validation', 'incomplete_inspection', 'Record an outcome for every asset in the shipment in one submission.');
            }

            $mapStmt = $conn->prepare('SELECT asset_status_id FROM asset_logistics_outcome_statuses WHERE outcome_code = ? AND (tenant_id = ? OR tenant_id IS NULL) ORDER BY tenant_id IS NOT NULL DESC LIMIT 1');
            $update = $conn->prepare('UPDATE assets SET asset_status_id = ? WHERE id = ?');
            foreach ($assets as $asset) {
                $assetId = (int)$asset['asset_id'];
                $code = $normalised[$assetId];
                $mapStmt->execute([$code, $tenantId]);
                $mappedStatus = $mapStmt->fetchColumn();
                $oldStatus = $asset['asset_status_id'] === null ? null : (string)$asset['asset_status_id'];
                // Outcome recording is valid even when no status mapping is configured;
                // in that case retain the current status and record the inspection only.
                $newStatus = $mappedStatus === false ? $oldStatus : (string)(int)$mappedStatus;
                if ($mappedStatus !== false && $oldStatus !== $newStatus) $update->execute([(int)$mappedStatus, $assetId]);
                self::recordEvent($conn, (int)$shipment['movement_id'], $shipmentId, 'asset_receipt_inspected', $ctx,
                    $oldStatus, $newStatus, 'Asset ' . $assetId . '; outcome=' . $code . ($mappedStatus === false ? '; status unchanged (no outcome mapping)' : '') . ($notes !== null && $notes !== '' ? '; ' . $notes : ''));
            }
            self::applyCompletionPolicy($conn, $ctx, (int)$shipment['movement_id']);
            if ($ownsTransaction) $conn->commit();
            if ($ownsTransaction) {
                $title = $shipment['movement_type'] === 'inbound_return' ? 'Returned asset received and inspected' : 'Delivery inspection completed';
                $body = $shipment['movement_type'] === 'inbound_return'
                    ? 'Your returned asset has been received and inspected. Open My Assets to view the final status.'
                    : 'Receipt inspection has been recorded for your delivery. Open My Assets for details.';
                self::notifyShipmentRecipient($conn, $shipmentId, $title, $body);
            }
            return self::getMovement($conn, $ctx, (int)$shipment['movement_id']);
        } catch (Throwable $e) {
            if ($ownsTransaction && $conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
    }

    /** Return a tenant-scoped movement, shipments, asset assignments and event history. */
    /** Return only shipments explicitly assigned to this authenticated portal user, including staff-initiated returns. */
    public static function listPortalShipments(PDO $conn, int $userId): array
    {
        if ($userId < 1) throw new ServiceError('forbidden', 'not_authenticated', 'Not authenticated.');
        $st = $conn->prepare("SELECT s.id AS shipment_id, m.tenant_id, s.shipment_number, s.status AS shipment_status,
                s.carrier_name, s.carrier_supplier_id, s.transport_mode, s.tracking_number, s.recipient_acknowledged_at, s.recipient_issue, s.recipient_issue_at,
                s.return_acknowledged_at, s.return_issue, s.return_issue_at, s.collection_method, s.pickup_address,
                s.pickup_scheduled_at, s.dropoff_location, s.return_instructions,
                m.id AS movement_id, m.movement_type, p.name AS purpose_name, m.created_datetime,
                GROUP_CONCAT(COALESCE(NULLIF(ma.asset_tag_snapshot, ''), NULLIF(ma.hostname_snapshot, ''), CONCAT('Asset #', ma.asset_id)) ORDER BY ma.id SEPARATOR ', ') AS assets
            FROM asset_shipments s
            JOIN asset_movements m ON m.id = s.movement_id
            LEFT JOIN asset_movement_purposes p ON p.id = m.purpose_id
            LEFT JOIN asset_movement_assets ma ON ma.shipment_id = s.id
            JOIN users u ON u.id = s.recipient_user_id
            WHERE s.recipient_user_id = ? AND u.is_active = 1
              AND m.movement_type IN ('outbound_dispatch', 'inbound_return')
            GROUP BY s.id ORDER BY s.created_datetime DESC, s.id DESC LIMIT 100");
        $st->execute([$userId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $carrierLookup = $conn->prepare("SELECT COALESCE(NULLIF(CASE WHEN ? = 'air' THEN tracking_url_air WHEN ? = 'surface' THEN tracking_url_surface ELSE NULL END, ''), tracking_url_template) FROM asset_logistics_carriers WHERE supplier_id = ? AND tenant_id = ? AND is_active = 1");
        foreach ($rows as &$row) {
            $row['tracking_url'] = null;
            if (!empty($row['carrier_supplier_id'])) {
                $rowTenantId = $row['tenant_id'] === null ? getDefaultTenantId($conn) : (int)$row['tenant_id'];
                $mode = (string)($row['transport_mode'] ?? 'surface');
                $carrierLookup->execute([$mode, $mode, (int)$row['carrier_supplier_id'], $rowTenantId]); $template = $carrierLookup->fetchColumn();
                if (is_string($template) && $template !== '') {
                    if (strpos($template, '{tracking_number}') !== false) {
                        if (trim((string)($row['tracking_number'] ?? '')) !== '') $row['tracking_url'] = str_replace('{tracking_number}', rawurlencode((string)$row['tracking_number']), $template);
                    } else { $row['tracking_url'] = $template; }
                }
            }
        }
        unset($row);
        return ['shipments' => $rows];
    }

    /** Employees can acknowledge outbound receipt or return arrangements, and report shipment issues; they cannot initiate returns. */
    public static function portalShipmentAction(PDO $conn, int $userId, int $shipmentId, string $action, ?string $issue = null): array
    {
        if ($userId < 1 || $shipmentId < 1) throw new ServiceError('validation', 'invalid_request', 'A valid shipment is required.');
        if (!in_array($action, ['acknowledge', 'return_acknowledge', 'report_issue', 'report_return_issue'], true)) throw new ServiceError('validation', 'invalid_action', 'Unsupported delivery/return action.');
        if (in_array($action, ['report_issue', 'report_return_issue'], true)) {
            $issue = trim((string)$issue);
            if ($issue === '' || mb_strlen($issue) > 5000) throw new ServiceError('validation', 'invalid_issue', 'Describe the issue (1–5000 characters).');
        }
        $ownsTransaction = !$conn->inTransaction();
        if ($ownsTransaction) $conn->beginTransaction();
        try {
            $st = $conn->prepare("SELECT s.id, s.status, s.recipient_acknowledged_at, s.recipient_issue,
                    s.return_acknowledged_at, s.return_issue, s.collection_method, s.pickup_address, s.dropoff_location, s.recipient_user_id, s.created_by_analyst_id, m.id AS movement_id, m.movement_type
                FROM asset_shipments s JOIN asset_movements m ON m.id = s.movement_id
                JOIN users u ON u.id = s.recipient_user_id
                WHERE s.id = ? AND s.recipient_user_id = ? AND u.is_active = 1 FOR UPDATE");
            $st->execute([$shipmentId, $userId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new ServiceError('not_found', 'not_found', 'Shipment not found.');
            $isReturn = $row['movement_type'] === 'inbound_return';
            if (!$isReturn && $row['movement_type'] !== 'outbound_dispatch') throw new ServiceError('not_found', 'not_found', 'Shipment not found.');
            $ctx = ActorContext::fromPortalUser('Portal user #' . $userId);
            $acknowledgementChanged = false;
            if ($action === 'acknowledge') {
                if ($isReturn) throw new ServiceError('validation', 'invalid_action', 'Use return_acknowledge for return instructions.');
                if ($row['status'] !== 'delivered') throw new ServiceError('conflict', 'not_delivered', 'Delivery can only be acknowledged after the shipment is marked delivered.');
                if ($row['recipient_acknowledged_at'] === null) {
                    self::assignShipmentAssetsToRecipient($conn, $ctx, $shipmentId, (int)$row['movement_id'], (int)$row['recipient_user_id']);
                    $conn->prepare('UPDATE asset_shipments SET recipient_acknowledged_at = NOW() WHERE id = ?')->execute([$shipmentId]);
                    self::recordEvent($conn, (int)$row['movement_id'], $shipmentId, 'employee_delivery_acknowledged', $ctx, null, 'acknowledged');
                    $acknowledgementChanged = true;
                    self::applyCompletionPolicy($conn, $ctx, (int)$row['movement_id']);
                }
            } elseif ($action === 'return_acknowledge') {
                if (!$isReturn) throw new ServiceError('validation', 'invalid_action', 'Return instructions can only be acknowledged on a return shipment.');
                if (in_array($row['status'], ['delivered', 'cancelled'], true)) throw new ServiceError('conflict', 'return_closed', 'Return instructions cannot be acknowledged on a closed shipment.');
                if ($row['collection_method'] === 'home_pickup' && trim((string)$row['pickup_address']) === '') throw new ServiceError('conflict', 'return_arrangement_incomplete', 'Staff must provide the home pickup address before the employee can acknowledge the return.');
                if ($row['collection_method'] === 'drop_off' && trim((string)$row['dropoff_location']) === '') throw new ServiceError('conflict', 'return_arrangement_incomplete', 'Staff must provide the drop-off location before the employee can acknowledge the return.');
                if (!in_array($row['collection_method'], ['home_pickup', 'drop_off'], true)) throw new ServiceError('conflict', 'return_arrangement_incomplete', 'Staff must arrange the return before the employee can acknowledge it.');
                if ($row['return_acknowledged_at'] === null) {
                    $conn->prepare('UPDATE asset_shipments SET return_acknowledged_at = NOW() WHERE id = ?')->execute([$shipmentId]);
                    self::recordEvent($conn, (int)$row['movement_id'], $shipmentId, 'employee_return_instructions_acknowledged', $ctx, null, 'acknowledged');
                    $acknowledgementChanged = true;
                }
            } else {
                if ($isReturn !== ($action === 'report_return_issue')) throw new ServiceError('validation', 'invalid_action', 'The issue action does not match this shipment type.');
                if (!in_array($row['status'], ($isReturn ? ['pending', 'dispatched', 'in_transit', 'delivered', 'exception'] : ['dispatched', 'in_transit', 'delivered', 'exception']), true)) {
                    throw new ServiceError('conflict', 'invalid_issue_stage', 'An issue cannot be reported at this shipment stage.');
                }
                if ($isReturn) {
                    $newStatus = in_array($row['status'], ['pending', 'delivered'], true) ? $row['status'] : 'exception';
                    $conn->prepare('UPDATE asset_shipments SET return_issue = ?, return_issue_at = NOW(), status = ? WHERE id = ?')->execute([$issue, $newStatus, $shipmentId]);
                } else {
                    $newStatus = $row['status'] === 'delivered' ? 'delivered' : 'exception';
                    $conn->prepare('UPDATE asset_shipments SET recipient_issue = ?, recipient_issue_at = NOW(), status = ? WHERE id = ?')->execute([$issue, $newStatus, $shipmentId]);
                }
                if ($newStatus !== $row['status']) self::recordEvent($conn, (int)$row['movement_id'], $shipmentId, 'shipment_status_changed', $ctx, (string)$row['status'], $newStatus, 'Status changed after employee issue report.');
                self::recordEvent($conn, (int)$row['movement_id'], $shipmentId, $isReturn ? 'employee_return_issue_reported' : 'employee_delivery_issue_reported', $ctx,
                    (($isReturn ? $row['return_issue'] : $row['recipient_issue']) !== null) ? 'issue_reported' : null, 'issue_reported', $issue);
            }
            if ($ownsTransaction) $conn->commit();
            if ($ownsTransaction && !empty($row['created_by_analyst_id'])) {
                $isIssue = in_array($action, ['report_issue', 'report_return_issue'], true);
                $isAck = in_array($action, ['acknowledge', 'return_acknowledge'], true);
                if ($isIssue || ($isAck && $acknowledgementChanged)) {
                    NotificationsService::notify($conn, [
                        'analyst_id' => (int)$row['created_by_analyst_id'],
                        'event_type' => $isIssue ? 'asset_logistics.issue_reported' : 'asset_logistics.acknowledged',
                        'entity_type' => 'asset_shipment', 'entity_id' => $shipmentId,
                        'entity_ref' => 'Shipment #' . $shipmentId,
                        'title' => $isIssue
                            ? ($isReturn ? 'Employee reported a return issue' : 'Employee reported a delivery issue')
                            : ($isReturn ? 'Employee acknowledged return instructions' : 'Employee acknowledged delivery'),
                        'body' => $isIssue ? mb_substr($issue, 0, 450) : 'Employee action: ' . str_replace('_', ' ', $action) . '.',
                        'actor_user_id' => $userId, 'actor_name' => 'Portal user #' . $userId,
                    ]);
                }
            }
            return ['shipment_id' => $shipmentId, 'action' => $action, 'success' => true];
        } catch (Throwable $e) {
            if ($ownsTransaction && $conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
    }

    /** Staff can explicitly close a movement when its policy disables automatic closure. */
    public static function closeMovement(PDO $conn, ActorContext $ctx, int $movementId): array
    {
        if ($movementId < 1) throw new ServiceError('validation', 'invalid_movement', 'A valid movement is required.');
        $ownsTransaction = !$conn->inTransaction();
        if ($ownsTransaction) $conn->beginTransaction();
        try {
            $st = $conn->prepare('SELECT id, movement_type, tenant_id, status FROM asset_movements WHERE id = ? FOR UPDATE');
            $st->execute([$movementId]);
            $movement = $st->fetch(PDO::FETCH_ASSOC);
            if (!$movement) throw new ServiceError('not_found', 'not_found', 'Movement not found.');
            $tenantId = $movement['tenant_id'] === null ? getDefaultTenantId($conn) : (int)$movement['tenant_id'];
            self::assertTenantScope($ctx, $tenantId);
            if (in_array($movement['status'], ['completed', 'cancelled'], true)) throw new ServiceError('conflict', 'movement_closed', 'The movement is already closed.');
            $shipments = $conn->prepare('SELECT id, status, recipient_acknowledged_at FROM asset_shipments WHERE movement_id = ? FOR UPDATE');
            $shipments->execute([$movementId]);
            $rows = $shipments->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) throw new ServiceError('conflict', 'no_shipments', 'A movement without shipments cannot be closed.');
            foreach ($rows as $shipment) {
                if ($shipment['status'] === 'cancelled') continue;
                if ($shipment['status'] !== 'delivered') throw new ServiceError('conflict', 'shipments_pending', 'All shipments must be delivered or cancelled before the movement can be closed.');
                if ($movement['movement_type'] === 'outbound_dispatch' && empty($shipment['recipient_acknowledged_at'])) throw new ServiceError('conflict', 'acknowledgement_pending', 'Every delivered outbound shipment must be acknowledged before the movement can be closed.');
            }
            $policy = $conn->prepare('SELECT post_receipt_action FROM asset_logistics_policies WHERE movement_type = ? AND (tenant_id = ? OR tenant_id IS NULL) ORDER BY tenant_id IS NOT NULL DESC LIMIT 1');
            $policy->execute([$movement['movement_type'], $tenantId]);
            if ((string)$policy->fetchColumn() === 'inspection') {
                foreach ($rows as $shipment) {
                    if ($shipment['status'] === 'cancelled') continue;
                    if (!self::shipmentInspectionComplete($conn, (int)$shipment['id'])) throw new ServiceError('conflict', 'inspection_pending', 'Complete receipt inspection for every delivered shipment before closing the movement.');
                }
            }
            // Use the same completion path as automatic closure so custody, configured
            // outbound asset-status changes, return unassignment, and audit events cannot
            // diverge when an administrator has disabled automatic movement closure.
            self::applyCompletionPolicy($conn, $ctx, $movementId, true);
            if ($ownsTransaction) $conn->commit();
            return self::getMovement($conn, $ctx, $movementId);
        } catch (Throwable $e) {
            if ($ownsTransaction && $conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
    }

    public static function getMovement(PDO $conn, ActorContext $ctx, int $movementId): array
    {
        $st = $conn->prepare('SELECT m.*, p.name AS purpose_name FROM asset_movements m
            LEFT JOIN asset_movement_purposes p ON p.id = m.purpose_id WHERE m.id = ?');
        $st->execute([$movementId]);
        $movement = $st->fetch(PDO::FETCH_ASSOC);
        if (!$movement) throw new ServiceError('not_found', 'not_found', 'Movement not found.');
        $tenantId = $movement['tenant_id'] === null ? getDefaultTenantId($conn) : (int)$movement['tenant_id'];
        self::assertTenantScope($ctx, $tenantId);
        $ship = $conn->prepare('SELECT s.*, COALESCE(s.cost_centre_id, m.cost_centre_id) AS effective_cost_centre_id
            FROM asset_shipments s JOIN asset_movements m ON m.id = s.movement_id
            WHERE s.movement_id = ? ORDER BY s.shipment_number');
        $ship->execute([$movementId]);
        $shipments = $ship->fetchAll(PDO::FETCH_ASSOC);
        $carrierLookup = $conn->prepare("SELECT COALESCE(NULLIF(CASE WHEN ? = 'air' THEN tracking_url_air WHEN ? = 'surface' THEN tracking_url_surface ELSE NULL END, ''), tracking_url_template) FROM asset_logistics_carriers WHERE supplier_id = ? AND tenant_id = ? AND is_active = 1");
        $movementTenantId = $movement['tenant_id'] === null ? getDefaultTenantId($conn) : (int)$movement['tenant_id'];
        foreach ($shipments as &$shipmentRow) {
            $shipmentRow['tracking_url'] = null;
            if (!empty($shipmentRow['carrier_supplier_id'])) {
                $mode = (string)($shipmentRow['transport_mode'] ?? 'surface');
                $carrierLookup->execute([$mode, $mode, (int)$shipmentRow['carrier_supplier_id'], $movementTenantId]);
                $template = $carrierLookup->fetchColumn();
                if (is_string($template) && $template !== '') {
                    if (strpos($template, '{tracking_number}') !== false) {
                        if (trim((string)($shipmentRow['tracking_number'] ?? '')) !== '') $shipmentRow['tracking_url'] = str_replace('{tracking_number}', rawurlencode((string)$shipmentRow['tracking_number']), $template);
                    } else { $shipmentRow['tracking_url'] = $template; }
                }
            }
        }
        unset($shipmentRow);
        $assets = $conn->prepare('SELECT ma.id, ma.shipment_id, ma.asset_id, ma.asset_tag_snapshot, ma.hostname_snapshot
            FROM asset_movement_assets ma WHERE ma.movement_id = ? ORDER BY ma.id');
        $assets->execute([$movementId]);
        $assetLinks = $assets->fetchAll(PDO::FETCH_ASSOC);
        $events = $conn->prepare('SELECT id, shipment_id, event_type, old_value, new_value, notes, actor_analyst_id, actor_name, created_datetime
            FROM asset_movement_events WHERE movement_id = ? ORDER BY id');
        $events->execute([$movementId]);
        $policyStmt = $conn->prepare('SELECT post_receipt_action, inspection_provider, close_movement_on_completion, assign_to_recipient_on_ack, update_asset_status_on_completion, completion_asset_status_id FROM asset_logistics_policies WHERE movement_type = ? AND (tenant_id = ? OR tenant_id IS NULL) ORDER BY tenant_id IS NOT NULL DESC LIMIT 1');
        $policyStmt->execute([$movement['movement_type'], (int)$tenantId]);
        $policy = $policyStmt->fetch(PDO::FETCH_ASSOC) ?: ['post_receipt_action' => 'none', 'inspection_provider' => null, 'close_movement_on_completion' => 1, 'assign_to_recipient_on_ack' => 1, 'update_asset_status_on_completion' => 0, 'completion_asset_status_id' => null];
        return ['movement' => $movement, 'shipments' => $shipments, 'assets' => $assetLinks, 'events' => $events->fetchAll(PDO::FETCH_ASSOC), 'policy' => $policy];
    }

    /** List purpose configuration (including inactive rows) for the settings UI. */
    public static function listPurposeConfiguration(PDO $conn, ActorContext $ctx, int $tenantId): array
    {
        self::assertTenantScope($ctx, $tenantId);
        $st = $conn->prepare('SELECT p.id, p.movement_type, p.name, p.default_key, p.is_active, p.display_order, p.tenant_id, p.override_of_id
            FROM asset_movement_purposes p WHERE p.tenant_id = ? OR (p.tenant_id IS NULL AND NOT EXISTS (SELECT 1 FROM asset_movement_purposes o WHERE o.tenant_id = ? AND o.override_of_id = p.id))
            ORDER BY p.movement_type, p.display_order, p.name');
        $st->execute([$tenantId, $tenantId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Create a company-specific purpose or edit/deactivate an existing purpose. */
    public static function savePurpose(PDO $conn, ActorContext $ctx, int $tenantId, array $in): array
    {
        self::assertTenantScope($ctx, $tenantId);
        $id = (int)($in['id'] ?? 0);
        $type = trim((string)($in['movement_type'] ?? ''));
        $name = trim((string)($in['name'] ?? ''));
        $active = !empty($in['is_active']) ? 1 : 0;
        $order = filter_var($in['display_order'] ?? 0, FILTER_VALIDATE_INT);
        if (!in_array($type, self::MOVEMENT_TYPES, true) || $name === '' || mb_strlen($name) > 100 || $order === false || $order < 0) {
            throw new ServiceError('validation', 'invalid_purpose', 'Purpose type, name (1–100 characters), and non-negative display order are required.');
        }
        if ($id > 0) {
            $st = $conn->prepare('SELECT id, tenant_id, default_key, movement_type FROM asset_movement_purposes WHERE id = ?');
            $st->execute([$id]); $existing = $st->fetch(PDO::FETCH_ASSOC);
            if (!$existing || ($existing['tenant_id'] !== null && (int)$existing['tenant_id'] !== $tenantId)) {
                throw new ServiceError('not_found', 'not_found', 'Purpose not found.');
            }
            // Editing a shared seed creates or updates a tenant override. Never mutate
            // the shared row, and keep the override tied to its seed for effective-listing.
            if ($existing['tenant_id'] === null) {
                $override = $conn->prepare('SELECT id FROM asset_movement_purposes WHERE tenant_id = ? AND override_of_id = ? FOR UPDATE');
                $override->execute([$tenantId, $id]);
                $overrideId = $override->fetchColumn();
                if ($overrideId) {
                    $existing = ['id' => (int)$overrideId, 'tenant_id' => $tenantId, 'default_key' => null, 'movement_type' => $existing['movement_type']];
                    $id = (int)$overrideId;
                } else {
                    $dup = $conn->prepare('SELECT id FROM asset_movement_purposes WHERE tenant_id = ? AND movement_type = ? AND name = ? LIMIT 1');
                    $dup->execute([$tenantId, $type, $name]);
                    if ($dup->fetchColumn()) throw new ServiceError('conflict', 'purpose_duplicate', 'A company purpose with that name already exists.');
                    $ins = $conn->prepare('INSERT INTO asset_movement_purposes (movement_type, name, default_key, is_active, display_order, tenant_id, override_of_id) VALUES (?, ?, NULL, ?, ?, ?, ?)');
                    $ins->execute([$type, $name, $active, $order, $tenantId, (int)$existing['id']]);
                    return ['id' => (int)$conn->lastInsertId(), 'created_override' => true];
                }
            }
            // A purpose already used by a movement cannot change movement type.
            $used = $conn->prepare('SELECT 1 FROM asset_movements WHERE purpose_id = ? LIMIT 1');
            $used->execute([$id]);
            if ($used->fetchColumn() && $type !== (string)($existing['movement_type'] ?? $type)) {
                throw new ServiceError('conflict', 'purpose_in_use', 'A purpose used by existing movements cannot change movement type.');
            }
            // MySQL UNIQUE indexes permit multiple NULL tenant_id values, so
            // explicitly enforce duplicate names for shared defaults as well.
            $duplicate = $conn->prepare('SELECT id FROM asset_movement_purposes WHERE tenant_id <=> ? AND movement_type = ? AND name = ? AND id <> ? LIMIT 1');
            $duplicate->execute([$existing['tenant_id'], $type, $name, $id]);
            if ($duplicate->fetchColumn()) {
                throw new ServiceError('conflict', 'purpose_duplicate', 'A purpose with that name already exists in this scope.');
            }
            $up = $conn->prepare('UPDATE asset_movement_purposes SET movement_type = ?, name = ?, is_active = ?, display_order = ? WHERE id = ?');
            try { $up->execute([$type, $name, $active, $order, $id]); }
            catch (PDOException $e) { if ((string)$e->getCode() === '23000') throw new ServiceError('conflict', 'purpose_duplicate', 'A purpose with that name already exists in this scope.'); throw $e; }
            return ['id' => $id, 'updated' => true];
        }
        $storedTenant = $tenantId; // Configuration is company-scoped, including the default company.
        $duplicate = $conn->prepare('SELECT id FROM asset_movement_purposes WHERE tenant_id <=> ? AND movement_type = ? AND name = ? LIMIT 1');
        $duplicate->execute([$storedTenant, $type, $name]);
        if ($duplicate->fetchColumn()) {
            throw new ServiceError('conflict', 'purpose_duplicate', 'A purpose with that name already exists in this scope.');
        }
        $st = $conn->prepare('INSERT INTO asset_movement_purposes (movement_type, name, is_active, display_order, tenant_id) VALUES (?, ?, ?, ?, ?)');
        try { $st->execute([$type, $name, $active, $order, $storedTenant]); }
        catch (PDOException $e) { if ((string)$e->getCode() === '23000') throw new ServiceError('conflict', 'purpose_duplicate', 'A purpose with that name already exists in this scope.'); throw $e; }
        return ['id' => (int)$conn->lastInsertId(), 'created' => true];
    }

    /** Load receipt policies, configured statuses, and outcome mappings for settings. */
    public static function getConfiguration(PDO $conn, ActorContext $ctx, int $tenantId): array
    {
        self::assertTenantScope($ctx, $tenantId);
        $purposes = self::listPurposeConfiguration($conn, $ctx, $tenantId);
        // Merge shared defaults first, then company-specific values. Returning one
        // effective row per key prevents the settings UI from depending on duplicate
        // rows or SQL ordering to decide which policy is active.
        $pol = $conn->prepare('SELECT tenant_id, movement_type, post_receipt_action, inspection_provider, close_movement_on_completion, assign_to_recipient_on_ack, update_asset_status_on_completion, completion_asset_status_id
            FROM asset_logistics_policies WHERE tenant_id IS NULL OR tenant_id = ?
            ORDER BY tenant_id IS NOT NULL, movement_type');
        $pol->execute([$tenantId]);
        $policyMap = [];
        foreach ($pol->fetchAll(PDO::FETCH_ASSOC) as $row) $policyMap[$row['movement_type']] = $row;
        $statuses = $conn->prepare('SELECT id, name FROM asset_status_types WHERE is_active = 1 AND (tenant_id IS NULL OR tenant_id = ?) ORDER BY display_order, name');
        $statuses->execute([$tenantId]);
        $out = $conn->prepare('SELECT tenant_id, outcome_code, asset_status_id FROM asset_logistics_outcome_statuses
            WHERE tenant_id IS NULL OR tenant_id = ? ORDER BY tenant_id IS NOT NULL, outcome_code');
        $out->execute([$tenantId]);
        $outcomeMap = [];
        foreach ($out->fetchAll(PDO::FETCH_ASSOC) as $row) $outcomeMap[$row['outcome_code']] = $row;
        $carrierStmt = $conn->prepare("SELECT s.id AS supplier_id, COALESCE(NULLIF(s.trading_name, ''), s.legal_name) AS name,
                s.is_active AS supplier_active, c.is_active AS carrier_active, c.is_default,
                c.tracking_url_template, c.tracking_url_air, c.tracking_url_surface
            FROM suppliers s LEFT JOIN asset_logistics_carriers c ON c.supplier_id = s.id AND c.tenant_id = ?
            WHERE s.is_active = 1 ORDER BY c.is_default DESC, name");
        $carrierStmt->execute([$tenantId]);
        return ['purposes' => $purposes, 'policies' => array_values($policyMap), 'statuses' => $statuses->fetchAll(PDO::FETCH_ASSOC), 'outcome_statuses' => array_values($outcomeMap), 'carriers' => $carrierStmt->fetchAll(PDO::FETCH_ASSOC)];
    }

    /** Active carrier choices for shipment editors, scoped to the movement tenant. */
    public static function listCarriers(PDO $conn, ActorContext $ctx, int $tenantId): array
    {
        self::assertTenantScope($ctx, $tenantId);
        $st = $conn->prepare("SELECT s.id AS supplier_id, COALESCE(NULLIF(s.trading_name, ''), s.legal_name) AS name, 1 AS carrier_active, c.is_default
            FROM asset_logistics_carriers c JOIN suppliers s ON s.id = c.supplier_id
            WHERE c.tenant_id = ? AND c.is_active = 1 AND s.is_active = 1 ORDER BY c.is_default DESC, name");
        $st->execute([$tenantId]);
        return ['carriers' => $st->fetchAll(PDO::FETCH_ASSOC)];
    }

    /** Configure a tenant-specific carrier entry using the existing Suppliers registry. */
    public static function saveCarrier(PDO $conn, ActorContext $ctx, int $tenantId, array $in): array
    {
        self::assertTenantScope($ctx, $tenantId);
        $supplierId = (int)($in['supplier_id'] ?? 0);
        if ($supplierId < 1) throw new ServiceError('validation', 'invalid_supplier', 'Choose a supplier to configure as a carrier.');
        $supplier = $conn->prepare('SELECT id FROM suppliers WHERE id = ? AND is_active = 1');
        $supplier->execute([$supplierId]);
        if (!$supplier->fetchColumn()) throw new ServiceError('validation', 'invalid_supplier', 'The selected supplier is not active.');
        $enabled = !empty($in['is_active']) ? 1 : 0;
        $isDefault = !empty($in['is_default']) ? 1 : 0;
        $tracking = trim((string)($in['tracking_url_template'] ?? ''));
        $trackingAir = trim((string)($in['tracking_url_air'] ?? ''));
        $trackingSurface = trim((string)($in['tracking_url_surface'] ?? ''));
        foreach ([$tracking, $trackingAir, $trackingSurface] as $url) {
            if ($url !== '' && (!preg_match('#^https://#i', $url) || mb_strlen($url) > 1000 || preg_match('/\s/', $url))) {
            throw new ServiceError('validation', 'invalid_carrier_url', 'Tracking page URLs must be valid HTTPS URLs (the {tracking_number} placeholder is optional).');
            }
        }
        if ($isDefault && !$enabled) throw new ServiceError('validation', 'default_carrier_disabled', 'The default carrier must be enabled.');
        $conn->beginTransaction();
        try {
            if ($isDefault) $conn->prepare('UPDATE asset_logistics_carriers SET is_default = 0 WHERE tenant_id = ?')->execute([$tenantId]);
            $q = $conn->prepare('SELECT id, tracking_url_template FROM asset_logistics_carriers WHERE tenant_id = ? AND supplier_id = ? FOR UPDATE');
            $q->execute([$tenantId, $supplierId]); $existingCarrier = $q->fetch(PDO::FETCH_ASSOC);
            $id = $existingCarrier['id'] ?? null;
            if ($id) {
                if (!array_key_exists('tracking_url_template', $in)) $tracking = (string)($existingCarrier['tracking_url_template'] ?? '');
                $st = $conn->prepare('UPDATE asset_logistics_carriers SET is_active = ?, is_default = ?, tracking_url_template = ?, tracking_url_air = ?, tracking_url_surface = ?, last_updated_by_analyst_id = ? WHERE id = ? AND tenant_id = ?');
                $st->execute([$enabled, $isDefault, $tracking ?: null, $trackingAir ?: null, $trackingSurface ?: null, $ctx->actorId > 0 ? $ctx->actorId : null, (int)$id, $tenantId]);
            } else {
                $st = $conn->prepare('INSERT INTO asset_logistics_carriers (tenant_id, supplier_id, is_active, is_default, tracking_url_template, tracking_url_air, tracking_url_surface, last_updated_by_analyst_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $st->execute([$tenantId, $supplierId, $enabled, $isDefault, $tracking ?: null, $trackingAir ?: null, $trackingSurface ?: null, $ctx->actorId > 0 ? $ctx->actorId : null]);
            }
            if (!$enabled) $conn->prepare('UPDATE asset_logistics_carriers SET is_default = 0 WHERE tenant_id = ? AND supplier_id = ?')->execute([$tenantId, $supplierId]);
            $conn->commit();
        } catch (Throwable $e) { if ($conn->inTransaction()) $conn->rollBack(); throw $e; }
        return ['saved' => true, 'supplier_id' => $supplierId, 'tenant_id' => $tenantId];
    }

    /** Save one movement type's receipt policy. Checklist integration is optional and never inferred. */
    public static function saveReceiptPolicy(PDO $conn, ActorContext $ctx, int $tenantId, array $in): array
    {
        self::assertTenantScope($ctx, $tenantId);
        $type = trim((string)($in['movement_type'] ?? ''));
        $action = trim((string)($in['post_receipt_action'] ?? 'none'));
        $provider = trim((string)($in['inspection_provider'] ?? ''));
        $closeOnCompletion = !array_key_exists('close_movement_on_completion', $in) || !empty($in['close_movement_on_completion']) ? 1 : 0;
        $assignOnAck = $type === 'outbound_dispatch' && (!array_key_exists('assign_to_recipient_on_ack', $in) || !empty($in['assign_to_recipient_on_ack'])) ? 1 : 0;
        $updateStatusOnCompletion = !empty($in['update_asset_status_on_completion']) ? 1 : 0;
        $completionStatusValue = $in['completion_asset_status_id'] ?? null;
        if (!in_array($type, self::MOVEMENT_TYPES, true) || !in_array($action, ['none', 'inspection'], true)) {
            throw new ServiceError('validation', 'invalid_policy', 'Select a valid movement type and post-receipt action.');
        }
        if ($action === 'inspection' && !in_array($provider, ['manual'], true)) {
            throw new ServiceError('validation', 'invalid_policy', 'Inspection requires the available manual inspection provider.');
        }
        if ($action !== 'inspection') $provider = '';
        $completionStatusId = null;
        if ($type === 'inbound_return' && $updateStatusOnCompletion) {
            throw new ServiceError('validation', 'return_status_uses_inspection_mapping', 'Inbound return asset statuses are determined by the configured inspection outcome mappings.');
        }
        if ($updateStatusOnCompletion) {
            if ($completionStatusValue === null || $completionStatusValue === '' || !is_numeric($completionStatusValue) || (int)$completionStatusValue < 1) {
                throw new ServiceError('validation', 'completion_status_required', 'Choose an existing asset status when status updates are enabled.');
            }
            $checkCompletion = $conn->prepare('SELECT id FROM asset_status_types WHERE id = ? AND is_active = 1 AND (tenant_id IS NULL OR tenant_id = ?)');
            $checkCompletion->execute([(int)$completionStatusValue, $tenantId]);
            if (!$checkCompletion->fetchColumn()) throw new ServiceError('validation', 'invalid_status', 'The selected completion status is not available for this company.');
            $completionStatusId = (int)$completionStatusValue;
        }
        $storedTenant = $tenantId;
        $existing = $conn->prepare('SELECT id FROM asset_logistics_policies WHERE tenant_id <=> ? AND movement_type = ?');
        $existing->execute([$storedTenant, $type]);
        $rowId = $existing->fetchColumn();
        if ($rowId) {
            $st = $conn->prepare('UPDATE asset_logistics_policies SET post_receipt_action = ?, inspection_provider = ?, close_movement_on_completion = ?, assign_to_recipient_on_ack = ?, update_asset_status_on_completion = ?, completion_asset_status_id = ?, updated_by_analyst_id = ? WHERE id = ?');
            $st->execute([$action, $provider !== '' ? $provider : null, $closeOnCompletion, $assignOnAck, $updateStatusOnCompletion, $completionStatusId, $ctx->actorId > 0 ? $ctx->actorId : null, (int)$rowId]);
        } else {
            $st = $conn->prepare('INSERT INTO asset_logistics_policies (tenant_id, movement_type, post_receipt_action, inspection_provider, close_movement_on_completion, assign_to_recipient_on_ack, update_asset_status_on_completion, completion_asset_status_id, updated_by_analyst_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $st->execute([$storedTenant, $type, $action, $provider !== '' ? $provider : null, $closeOnCompletion, $assignOnAck, $updateStatusOnCompletion, $completionStatusId, $ctx->actorId > 0 ? $ctx->actorId : null]);
        }
        return ['saved' => true, 'movement_type' => $type];
    }

    /** Save or clear a configurable inspection outcome-to-status mapping. */
    public static function saveOutcomeStatus(PDO $conn, ActorContext $ctx, int $tenantId, array $in): array
    {
        self::assertTenantScope($ctx, $tenantId);
        $code = trim((string)($in['outcome_code'] ?? ''));
        $statusValue = $in['asset_status_id'] ?? null;
        if (!preg_match('/^[a-z][a-z0-9_]{0,39}$/', $code)) throw new ServiceError('validation', 'invalid_outcome', 'Outcome code must be a lowercase identifier.');
        $storedTenant = $tenantId;
        if ($statusValue === null || $statusValue === '') {
            $del = $conn->prepare('DELETE FROM asset_logistics_outcome_statuses WHERE tenant_id <=> ? AND outcome_code = ?');
            $del->execute([$storedTenant, $code]);
            return ['saved' => true, 'cleared' => true];
        }
        if (!is_numeric($statusValue) || (int)$statusValue < 1) throw new ServiceError('validation', 'invalid_status', 'Select a valid asset status.');
        $check = $conn->prepare('SELECT id FROM asset_status_types WHERE id = ? AND is_active = 1 AND (tenant_id IS NULL OR tenant_id = ?)');
        $check->execute([(int)$statusValue, $tenantId]);
        if (!$check->fetchColumn()) throw new ServiceError('validation', 'invalid_status', 'The selected asset status is not available for this company.');
        $existing = $conn->prepare('SELECT id FROM asset_logistics_outcome_statuses WHERE tenant_id <=> ? AND outcome_code = ?');
        $existing->execute([$storedTenant, $code]);
        $rowId = $existing->fetchColumn();
        if ($rowId) {
            $st = $conn->prepare('UPDATE asset_logistics_outcome_statuses SET asset_status_id = ?, updated_by_analyst_id = ? WHERE id = ?');
            $st->execute([(int)$statusValue, $ctx->actorId > 0 ? $ctx->actorId : null, (int)$rowId]);
        } else {
            $st = $conn->prepare('INSERT INTO asset_logistics_outcome_statuses (tenant_id, outcome_code, asset_status_id, updated_by_analyst_id) VALUES (?, ?, ?, ?)');
            $st->execute([$storedTenant, $code, (int)$statusValue, $ctx->actorId > 0 ? $ctx->actorId : null]);
        }
        return ['saved' => true, 'outcome_code' => $code];
    }

    /** List active purposes available to a company for a movement type. */
    public static function listPurposes(PDO $conn, ActorContext $ctx, int $tenantId, string $movementType): array
    {
        if (!in_array($movementType, self::MOVEMENT_TYPES, true)) {
            throw new ServiceError('validation', 'invalid_field', "'movement_type' must be outbound_dispatch or inbound_return.");
        }
        self::assertTenantScope($ctx, $tenantId);
        // Config-list tenant_id is not normalised like movement data: NULL is the
        // shared default, while an explicit tenant id is a company-specific option.
        $st = $conn->prepare('SELECT p.id, p.movement_type, p.name, p.is_active, p.display_order, p.tenant_id
            FROM asset_movement_purposes p
            WHERE p.movement_type = ? AND p.is_active = 1 AND (p.tenant_id = ? OR (p.tenant_id IS NULL AND NOT EXISTS (SELECT 1 FROM asset_movement_purposes o WHERE o.tenant_id = ? AND o.override_of_id = p.id)))
            ORDER BY p.display_order, p.name');
        $st->execute([$movementType, $tenantId, $tenantId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}
