## Why

Phase 4.1 (`add-webhook-handler`, merged in `f91a02a` / `ad0be90`) ships HMAC verification, replay protection, delivery-id idempotency, and an ACK contract — but the controller persists the row and returns `200` without doing anything with the payload. The point of the webhook is to flip local bookkeeping state in response to upstream events: a `job.completed` for a packshot must move the corresponding `ps_qamera_packshot_link` row from `pending → ready`, a `job.failed` must surface the error to the BO operator, and so on. Until this dispatch layer lands, the operator-visible state is permanently stale — packshots and product links sit at `pending` forever and there is no way for upstream completions to influence local state.

This change is Phase 4.2 of the plugin roadmap (`README.md` "Phase plan"). It consumes the `qamera_webhook_delivery` rows produced by 4.1 as its input and performs the side-effecting updates on the existing `qamera_product_link` / `qamera_packshot_link` tables.

## What Changes

- Introduce a synchronous **event dispatcher** that runs inside the webhook HTTP response, between `WebhookDeliveryRepository::recordDelivery()` and the `200` ACK in the existing webhook controller. Dispatch failures NEVER propagate — the handler still ACKs `200` so upstream stops retrying (the delivery row is the durable record; replay is an operator-driven, not protocol-driven, concern).
- Add per-event-type **handlers** for the four known upstream events: `job.completed`, `job.failed`, `job.cancelled`, `job.retried`. Unknown but well-formed event types remain a no-op (already accepted by 4.1, here they just don't trigger any update — forward-compatible with future upstream additions).
- Add an **external-ref parser** that recognises the canonical `ps:<shopId>:<productId>:image:<imageId>` format used by `product-image-sync` when calling `registerImage`. Unknown prefixes and malformed refs are logged at WARNING and produce no DB writes (still `200` ACK).
- Add a thin **link-row updater** that owns every `UPDATE` issued by handlers, keyed strictly on `(id_shop, id_product)` plus the natural-key column appropriate to the target table — so concurrent deliveries serialise on InnoDB row locks rather than at the application layer. `affectedRows = 0` is a logged warning, never a hard failure.
- Modify the **webhook controller** (from 4.1) to depend on the dispatcher and call it after the delivery row is persisted. The constructor signature gains one parameter; the existing 4.1 acceptance tests stay green because dispatch results are observable only through DB state and logs, not the HTTP response.

**OPEN QUESTION → resolve in design.md before tasks.md** — the original task brief assumes columns (`id_image`, `external_ref`, `qamera_image_id`, `last_error_code`) and `status` values (`synced`, `failed`, `cancelled`) that do **not** exist in the current schema (`Installer.php` lines 78–107). The real candidates are:

| Brief assumed | Actually present |
|---|---|
| `ps_qamera_product_link.status synced/failed/cancelled` | `pending/registered/error` |
| `ps_qamera_product_link.qamera_image_id` | not present — closest is `qamera_product_id CHAR(36)` |
| `ps_qamera_product_link.last_error_code` | not present — only `last_error_message TEXT` |
| `ps_qamera_product_link.id_image` | not present — table is per-product, unique on `(id_product, id_shop)` |
| dispatch target table | `ps_qamera_packshot_link` is the better fit for `job.*` events: it carries `qamera_job_id CHAR(36)` and `status ENUM('pending','ready','archived')` — exactly the "job lifecycle" shape |

Design will lock the routing: most likely `job.completed/failed/cancelled` write to `ps_qamera_packshot_link` (status transitions inside its existing ENUM, with `last_error_message` added if needed), and `job.retried` is a no-op or a timestamp-only refresh. Whether any column additions are required (e.g. an `error_message` column on `ps_qamera_packshot_link`, or relaxing its `status` ENUM) is a sub-decision for design — and if any are needed, the **first task** is an idempotent `ALTER TABLE` in `Installer::createSchema()` guarded by `INFORMATION_SCHEMA.COLUMNS` probe, matching the pattern already used in 4.1.

## Capabilities

### New Capabilities

- `webhook-event-dispatch`: routing of verified deliveries (event_type + parsed `external_ref` + payload) into the correct bookkeeping row update. Owns the dispatcher, the per-event handlers, the external-ref parser, and the link-row updater. Does NOT own the receive-and-verify layer (`webhook-handler`) or the table schemas (`prestashop-module-bootstrap`).

### Modified Capabilities

- `webhook-handler`: one new requirement — after a delivery is recorded with `status='accepted'`, the controller SHALL invoke the dispatcher and SHALL ignore its return value when deciding the HTTP response (the response contract from Phase 4.1 is unchanged; dispatch outcomes are observable only via DB state and logs). The existing rejection paths, signature scenarios, replay-window scenarios, idempotency scenarios, and `200/duplicate` ACK shape remain byte-identical.
- `prestashop-module-bootstrap`: only if design concludes a column addition is necessary on `ps_qamera_packshot_link` (e.g. an error-message column). If no schema change is needed, this capability is NOT modified and this bullet is dropped before tasks.md is written.

## Impact

- **Code**: new files under `src/Webhook/Event/` (dispatcher, handlers, parser, updater, value object). One-line edit + constructor change in the Phase 4.1 webhook controller. Possible idempotent `ALTER TABLE` in `Installer::createSchema()` (decided in design).
- **Schema**: no breaking change. At most one additive, idempotent column (TBD in design). Existing rows survive untouched.
- **Tests**: PHPUnit suites for dispatcher (event-type routing), each handler (row-found / row-missing / malformed-ref / payload-field-missing / DB-error), parser (happy + 5 malformed shapes), updater (1-row / 0-row / DB-error). One integration test wiring HMAC sig → real DB row update → `200` ACK. Smoke documented in `design.md §11`.
- **CI**: no matrix changes — PHP 8.1 / 8.2 / 8.3, PHPCS, PHPStan level 5, PHPUnit.
- **Runtime**: each dispatch is 1–2 `UPDATE` statements (≤5 ms typical). Synchronous within the HTTP response, no queue introduced. If a single event ever needs >100 ms work, revisit then; not now (YAGNI per CLAUDE.md).
- **Out of scope** (Phase 4.3+): packshots UI for operator-triggered job creation; manual retry UI for failed rows; bulk-backfill cron; BO panel showing delivery history.
