# Asset Logistics REST API (v1)

These endpoints use the standard FreeITSM REST API v1 authentication and response format. Send an API key in `Authorization: Bearer fitsm_...`. Grant the key the **Asset dispatch & reverse logistics** permissions under `asset_logistics`; permissions start denied. Company scope is enforced by the shared service using the API key's actor context.

The API follows the same single-shipment-first model as the UI: creating a movement creates one shipment automatically. Use the split endpoint only when assets must travel in separate physical shipments.

| Method | Path | Permission | Purpose |
|---|---|---|---|
| POST | `/api/v1/asset-movements` | `asset_logistics.create` | Create movement, default shipment and asset assignments |
| GET | `/api/v1/asset-movements/{id}` | `asset_logistics.read` | Read movement, shipments, assigned assets and events |
| POST | `/api/v1/asset-shipments/{id}/split` | `asset_logistics.update` | Split selected assets into a new shipment |
| POST | `/api/v1/asset-shipments/{id}/replace` | `asset_logistics.update` | Create a replacement for a cancelled shipment while retaining its movement audit trail |
| PATCH | `/api/v1/asset-shipments/{id}` | `asset_logistics.update` | Update shipment details |
| POST | `/api/v1/asset-shipments/{id}/transition` | `asset_logistics.update` | Advance shipment status |
| PUT | `/api/v1/asset-shipments/{id}/cost-centre` | `asset_logistics.update` | Set or clear cost-centre override |

## Create movement

```json
{
  "movement_type": "outbound_dispatch",
  "purpose_id": 1,
  "asset_ids": [42, 43],
  "cost_centre_id": 7,
  "recipient_user_id": 55,
  "notes": "Laptop delivery batch"
}
```

`movement_type` is `outbound_dispatch` or `inbound_return`. The company is derived from the selected assets; mixed-company movements are rejected. `cost_centre_id`, `recipient_user_id`, and `notes` are optional. `recipient_user_id` may identify the outbound recipient or the employee whose assets are being returned. It must identify an active portal user in the same company. For `inbound_return`, every selected asset must currently be assigned to that employee; returns are staff-initiated only.

## Split a shipment

```json
{
  "asset_ids": [43]
}
```

Only eligible draft movements can be split. The original shipment and new shipment must both retain at least one asset. Shipment detail fields are validated by the shared service.

## Update shipment and status

```json
{"fields": {"carrier_name": "DHL", "tracking_number": "123456789"}}
```

```json
{"status": "dispatched", "notes": "Collected by courier"}
```

Valid transitions are determined by the service's shipment lifecycle rules. Delivered or cancelled shipments cannot have tracking details edited. The recipient can be changed only while no tracking number/reference exists. Once recorded, the tracking number/reference cannot be changed or cleared to bypass the recipient lock. If an tracking number/reference exists, cancel the shipment and use the replacement endpoint; the replacement shipment starts without courier tracking details.

## Cost-centre inheritance

```json
{"cost_centre_id": 7}
```

A non-null value creates an explicit shipment override. Pass `{"cost_centre_id": null}` to clear it. When there is no override, the shipment dynamically inherits the movement's current cost centre.

Success responses use the API v1 `{"data": ...}` envelope; errors use `{"error":{"code":"...","message":"..."}}` and standard HTTP status codes.


### Record receipt inspection outcomes

`POST /api/assets/logistics.php` with action `record_receipt_outcomes` records per-asset receipt inspection results for a shipment already marked `delivered`. This action is available only when inspection is configured for that movement type. Each asset must belong to the selected shipment. A configured company-specific or shared status mapping is optional; without one, the outcome is recorded and the current asset status is preserved.

```json
{
  "action": "record_receipt_outcomes",
  "shipment_id": 42,
  "outcomes": [
    {"asset_id": 1001, "outcome_code": "passed"},
    {"asset_id": 1002, "outcome_code": "minor_wear"}
  ],
  "notes": "Received and inspected"
}
```

Supported outcomes are `passed`, `minor_wear`, `damaged`, and `scrap`. The service updates each asset's `asset_status_id` using the effective mapping and writes an `asset_receipt_inspected` movement event in the same transaction. When no outcome status mapping exists, the outcome is still recorded and the current asset status is preserved. This is a manual inspection workflow. Checklist-backed inspection is not exposed as an available setting until its adapter is implemented.


## Employee delivery and return self-service

`GET /api/self-service/asset_deliveries.php` lists only shipments assigned to the authenticated portal user. In the UI, this information is embedded under the existing My Assets tab. There is no separate employee Deliveries navigation item or redirect page.

Outbound movements may include `recipient_user_id` for delivery tracking and acknowledgement. Staff initiate `inbound_return` movements and assign the employee whose asset(s) are being returned. Return initiation is not exposed to employees.

For inbound returns, staff set shipment-level collection details using `update_shipment` fields:

- `collection_method`: `home_pickup` or `drop_off`
- `pickup_address`: required for `home_pickup`
- `pickup_scheduled_at`: optional scheduled date/time
- `dropoff_location`: required for `drop_off`
- `return_instructions`: employee-visible instructions

The assigned employee can see the collection method, address/location, schedule, courier and tracking details, acknowledge return instructions once the arrangement is complete, and report a return issue. Changing the collection method clears the now-inapplicable address/location and resets the employee acknowledgement so changed instructions must be acknowledged again. An issue before dispatch is recorded without prematurely changing a pending shipment to an exception; after dispatch, the shipment may move to `exception`.

Outbound self-service actions:

- `{"action":"acknowledge","shipment_id":123}` — allowed only after delivery is marked `delivered`.
- `{"action":"report_issue","shipment_id":123,"issue":"Package arrived damaged"}` — allowed after dispatch.

Return self-service actions:

- `{"action":"return_acknowledge","shipment_id":123}` — allowed only after staff have provided a valid home-pickup or drop-off arrangement.
- `{"action":"report_return_issue","shipment_id":123,"issue":"Pickup time does not work"}` — records a return issue for staff follow-up.

The staff API also exposes `close_movement` for the cases where automatic movement closure is disabled; it verifies shipment completion, employee acknowledgement and any required inspection before closing.

The API does not accept a portal user ID from the request body. Ownership is derived exclusively from the authenticated session and checked again in the service. An unassigned shipment is returned as not found.


## Completion policies and notifications

Administrators configure completion behaviour per movement type in Asset Logistics Settings:

- Whether the movement is closed automatically when its completion gate is met. If disabled, it remains `in_progress` for staff follow-up.
- For outbound dispatch only, whether to assign the asset to the selected employee on delivery acknowledgement. This is enabled by default; a single previous employee assignment is transferred, while analyst assignments or multiple conflicting holders must be resolved by staff.
- For outbound dispatch only, whether to apply a configured active asset status after all non-cancelled shipments are delivered and acknowledged. When disabled, the current asset status is retained.
- For inbound returns, asset status is driven by the inspection outcome mapping; the outbound completion-status setting is not used.
- Whether receipt inspection is required before completion. Only the manual inspection provider is exposed as available until a checklist adapter exists.

The existing in-app notification service is used. Employees receive portal notifications when shipment tracking/status or return arrangements change. Staff who created a shipment receive analyst notifications when the employee acknowledges delivery/return instructions or reports an issue. Notifications are written after the underlying business transaction commits and link to the relevant logistics or My Assets view.

## Replace a cancelled shipment

`POST /api/v1/asset-shipments/{id}/replace` creates a new pending shipment within the same movement for a cancelled shipment. The assets are reassociated with the replacement shipment; the cancelled shipment and per-asset replacement events remain in movement history. The replacement receives a new shipment number and does not inherit the cancelled shipment's carrier or AWB. For outbound shipments, staff can set a new recipient before recording the replacement AWB. Return shipments retain the employee whose assets are being returned.


## Carriers and automatic tracking

Carrier choices are managed from the existing active Suppliers registry in Logistics Settings. The enabled default carrier is preselected when a movement is created. A carrier may have an HTTPS tracking page URL with or without `{tracking_number}`. When the placeholder is present, FreeITSM URL-encodes and substitutes the shipment reference; otherwise, Track opens the configured URL unchanged in a new tab. Tracking is user-initiated: no courier API integration, polling, scraping, or external status synchronization is performed.
