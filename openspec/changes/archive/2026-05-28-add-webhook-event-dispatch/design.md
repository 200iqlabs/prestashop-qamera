## Context

Phase 4.1 (`webhook-handler`) terminates after `WebhookDeliveryRepository::recordDelivery()` writes a row with `status='accepted'` and the controller returns `200`. The verified payload sits in `qamera_webhook_delivery.raw_payload` as inert text — nothing reads it. This change adds the read-and-act layer.

Current state (verified against `src/Install/Installer.php` lines 78–107):

- `ps_qamera_product_link` is **per-product** bookkeeping: unique on `(id_product, id_shop)`, status ENUM `('pending','registered','error')`, with `qamera_product_id`, `qamera_product_ref`, `display_name_snapshot`, `last_error_message`, `last_synced_at`, `updated_at`. Owned by Phases 2 and 3 — Phase 4.2 must NOT clobber `status` or `qamera_product_id`.
- `ps_qamera_packshot_link` is **per-packshot** bookkeeping: unique on `qamera_packshot_ref`, status ENUM `('pending','ready','archived')`, with `qamera_packshot_id CHAR(36)`, `qamera_job_id CHAR(36) NULL`, `created_at`. **No code inserts into this table today** — the installer creates it but `product-image-sync` does not populate it. Phase 4.2 is the first writer.
- `ps_qamera_webhook_delivery` is the dispatch input log (Phase 4.1). Persisting is already done before dispatch runs.

Upstream `external_ref` shape, from `product-image-sync/spec.md` line 18: `ps:<shopId>:<productId>:image:<imageId>`. That is the per-image registration reference, NOT a packshot reference — the upstream `job` produces one or more packshots from that image.

The upstream webhook payload contract is set by the saas-platform side (not by this repo). The Phase 4.1 spec ("Event-type tolerance") commits to four known event types: `job.completed`, `job.failed`, `job.cancelled`, `job.retried`. Payload field names below are the contract this change relies on; the saas-platform side is the source of truth and any mismatch must surface during the §11 smoke test before merge.

Constraints (from CLAUDE.md and existing specs):

- PHP 8.1+, `declare(strict_types=1)`, PSR-12, PHPStan level 5.
- Synchronous dispatch only — PS has no native job queue. ≤5 ms per dispatch is the target.
- Dispatch failures NEVER propagate to the HTTP response — webhook must ACK `200` so upstream stops retrying. The delivery row is the durable record.
- Secrets never appear in logs (already enforced by 4.1's logging contract; this change inherits it).

## Goals / Non-Goals

**Goals:**

- Route verified deliveries into a side-effecting handler keyed on `event_type`.
- Persist packshot lifecycle state (`pending → ready / failed / cancelled`) so the BO operator sees real status.
- Touch `ps_qamera_product_link.last_synced_at` as a "the upstream is still alive" heartbeat without disturbing its `status` / `qamera_product_id` columns owned by Phase 3.
- Keep the receive-and-verify layer (4.1) byte-identical from the wire's perspective — same response codes, same headers, same logging shape.
- Test-first: every handler, the parser, and the updater ship with a PHPUnit suite that covers happy path + every documented failure mode.

**Non-Goals:**

- Marketplace presets UI, packshot operator UI, retry UI, bulk-backfill cron, delivery-history BO panel — all explicitly Phase 4.3+.
- Asynchronous dispatch / job queue — synchronous is correct for ≤5 ms work, see "Synchronous, not async" below.
- Storing the full packshot asset URL or thumbnail. Out of scope for 4.2; can be added in a follow-up additive migration if Phase 4.3 needs it.
- Front-office display of packshot status — the data lands in `ps_qamera_packshot_link` and is queryable; no rendering work here.

## Decisions

### D1 — Dispatch target table for `job.*` events is `ps_qamera_packshot_link`, not `ps_qamera_product_link`

The original brief assumed updates on `ps_qamera_product_link` with status values `synced/failed/cancelled`. That table tracks **product registration** (Phase 3 territory: status `pending/registered/error`, owned by `product-image-sync`). Reusing it for job lifecycle would (a) require expanding its ENUM, (b) risk clobbering Phase-3-owned columns, (c) conflate two distinct concerns. `ps_qamera_packshot_link` already exists, is currently unwritten, and has the natural shape for job lifecycle (`qamera_job_id`, status ENUM, per-packshot uniqueness).

Alternatives considered:

- *Update `ps_qamera_product_link` only* — rejected: collides with Phase 3 ownership of `status` and `qamera_product_id`.
- *Create a new `ps_qamera_job_event` table* — rejected: redundant with the existing `ps_qamera_packshot_link` table the installer already provisions and with `ps_qamera_webhook_delivery` which already logs raw deliveries.

### D2 — Match strategy: payload's `external_ref` is canonical; `qamera_packshot_id` is the upsert key

Parser: `ExternalRefParser::parse(string): array{shopId:int, productId:int, imageId:int}`. Only `ps:` prefix accepted. Five malformed shapes (non-`ps:` prefix, truncated, non-numeric, negative, leading/trailing whitespace) all throw `InvalidExternalRefException` — caught by the handler, logged at WARNING, no DB write, still `200` ACK.

For the actual UPSERT, the key is `qamera_packshot_id` from the payload (a CHAR(36) UUID minted upstream). `external_ref` shapes the `qamera_packshot_ref` natural key on insert: `ps:<shopId>:<productId>:packshot:<qamera_packshot_id_short>` or similar — design will pin the exact format in the spec scenarios. The parsed `(shopId, productId)` from `external_ref` is recorded on the row's `id_shop` / `id_product` columns and cross-checked: if `external_ref`'s shop/product don't match a known `ps_qamera_product_link` row, the dispatch logs a WARNING and skips the upsert (defensive — never create orphaned packshot rows for products this shop doesn't own).

Alternatives considered:

- *Match by `(id_shop, id_product, id_image)` on `ps_qamera_product_link`* — rejected, no `id_image` column exists; the table is per-product.
- *Key the upsert on `external_ref`* — rejected, `external_ref` is per-image (one per `registerImage` call), but one image can produce multiple packshots upstream; `qamera_packshot_id` is the canonical 1:1 handle.

### D3 — Schema migration: extend `ps_qamera_packshot_link` (idempotent ALTER)

`ps_qamera_packshot_link` today is missing: `last_synced_at DATETIME NULL`, `last_error_message TEXT NULL`, `updated_at DATETIME NOT NULL`. Its `status` ENUM is `('pending','ready','archived')` and is missing values needed for `job.failed` / `job.cancelled`.

Migration plan (executed inside `Installer::createSchema()`, idempotent — pattern reused from 4.1):

1. `SHOW COLUMNS FROM {prefix}qamera_packshot_link` — probe for each new column; emit `ALTER TABLE … ADD COLUMN` only if missing.
2. `INFORMATION_SCHEMA.COLUMNS` — probe the `status` column's `COLUMN_TYPE`; emit `ALTER TABLE … MODIFY COLUMN status ENUM('pending','ready','failed','cancelled','archived') NOT NULL DEFAULT 'pending'` only if the current ENUM definition is narrower.
3. No data backfill needed — new columns are nullable / defaulted; new ENUM values are additive.

Existing rows survive untouched (there shouldn't be any in production yet — packshot_link has no writers before this change).

Alternatives considered:

- *Skip the migration, write to existing columns only* — rejected, no place to store `last_error_message` for `job.failed`. The status ENUM also can't represent `failed/cancelled` without modification.
- *New `error_message` column with no length cap (`LONGTEXT`)* — rejected, `TEXT` (65 KB) is consistent with `ps_qamera_product_link.last_error_message` and well over upstream's diagnostic-message size.
- *Add the columns in a separate `upgrade/upgrade-X.Y.Z.php` script* — rejected for first install; the installer is idempotent so a single `createSchema` path is simpler. (If we later ship a release that needs to upgrade existing installs, the bootstrap capability already commits to versioned upgrade scripts per its "No subsequent phase may introduce a breaking schema change" clause — but additive ALTERs inside `createSchema()` are not breaking.)

### D4 — Per-event-type actions

| Event type | Action on `ps_qamera_packshot_link` | Action on `ps_qamera_product_link` |
|---|---|---|
| `job.completed` | UPSERT by `qamera_packshot_id`: `status='ready'`, `qamera_job_id=<payload.job_id>`, `last_synced_at=NOW()`, `updated_at=NOW()`, `last_error_message=NULL` | `last_synced_at=NOW()` (heartbeat only; status untouched) |
| `job.failed` | UPSERT by `qamera_packshot_id`: `status='failed'`, `qamera_job_id=<payload.job_id>`, `last_synced_at=NOW()`, `updated_at=NOW()`, `last_error_message=<payload.error_message truncated to TEXT capacity>` | `last_synced_at=NOW()` (heartbeat only; product registration is still valid even if a downstream packshot failed) |
| `job.cancelled` | UPSERT by `qamera_packshot_id`: `status='cancelled'`, `qamera_job_id=<payload.job_id>`, `last_synced_at=NOW()`, `updated_at=NOW()`, `last_error_message=NULL` | `last_synced_at=NOW()` |
| `job.retried` | If row exists by `qamera_packshot_id`: `last_synced_at=NOW()`, `updated_at=NOW()`, status untouched (upstream is still working). If row does not exist yet: no-op (the eventual `job.completed/failed` will create it). | `last_synced_at=NOW()` |
| unknown but well-formed | no-op | no-op |

**Rationale on heartbeat**: `ps_qamera_product_link.last_synced_at` already exists as the "last upstream contact" timestamp owned by Phase 3. Refreshing it on any verified webhook is a useful liveness signal for the BO without modifying Phase 3 columns the dispatcher must not touch (`status`, `qamera_product_id`, `last_error_message`).

### D5 — Wiring point: `WebhookController` constructor + one new call site

Phase 4.1's controller (`src/Controller/Front/WebhookController.php` — confirmed in §10 below before implementing) accepts the dispatcher as a constructor dependency. One new call site between `recordDelivery` and the `JsonResponse('status' => 'ok')`:

```php
try {
    $this->eventDispatcher->dispatch($webhookEvent);
} catch (\Throwable $e) {
    $this->logger->error(...);  // swallow — see D7
}
```

Wiring: PS modules don't use a Symfony DI container by default. The dispatcher is instantiated in `qameraai.php`'s `getContent` / controller-bootstrap path the same way 4.1 wires `HmacVerifier`, `ReplayGuard`, `WebhookDeliveryRepository`. If 4.1 introduced `config/services.yml`, use that; otherwise direct `new` in the module's controller-construction code. The exact wiring file is named in `tasks.md` after a code probe.

### D6 — File layout under `src/Webhook/Event/`

- `WebhookEvent.php` — immutable VO: `eventType`, `deliveryId`, `installationId`, `payload` (assoc array), `rawPayload` (raw string for diagnostics).
- `EventDispatcher.php` — public `dispatch(WebhookEvent $event): void`. Routes on `$event->eventType` to one handler. Unknown type → log INFO, return. Handler throws → log ERROR with `event_type` + `delivery_id`, swallow.
- `Handler/JobCompletedHandler.php`, `Handler/JobFailedHandler.php`, `Handler/JobCancelledHandler.php`, `Handler/JobRetriedHandler.php` — implement `EventHandlerInterface::handle(WebhookEvent $event): void`. Each owns its payload-field validation (e.g., `JobCompletedHandler` requires `payload.packshot_id`, `payload.external_ref`).
- `ExternalRefParser.php` — static `parse(string $ref): ExternalRef` where `ExternalRef` is a tiny VO with `shopId`, `productId`, `imageId`. Throws `InvalidExternalRefException` on every malformed shape.
- `PackshotLinkUpdater.php` — thin DB wrapper. One public method `upsertByPackshotId(array $row): bool` (true = 1 row affected by INSERT, false = 1 row affected by UPDATE; the unhandled 0 case throws `QameraDbException` because the unique-key upsert can't legitimately return 0).
- `ProductLinkHeartbeat.php` — separate thin wrapper for the `last_synced_at` touch on `ps_qamera_product_link`. Single public method `touch(int $idShop, int $idProduct): bool` (true = updated 1 row; false = no matching row → caller logs WARNING).

Each class is independently unit-testable with a mocked `Db` and a stub logger. Composition over inheritance — no abstract `BaseHandler`.

### D7 — Error handling (all paths terminate in `200`)

- Malformed `external_ref` → WARNING with `event_type`, `delivery_id`, `external_ref` (safe to log, not a secret), no DB write, return.
- Payload missing required field (e.g. `job.completed` without `packshot_id`) → ERROR with `event_type`, `delivery_id`, missing-field name, no DB write, return.
- Product-link row missing for parsed `(shopId, productId)` → WARNING with `event_type`, `delivery_id`, `external_ref`, no packshot upsert, no heartbeat, return. (Could be: delivery for a different shop on a shared installation, row deleted locally after job dispatch, malformed upstream worker.)
- DB error during UPSERT or heartbeat → ERROR with `event_type`, `delivery_id`, exception class name (NEVER the SQL string or stack trace), no further work, return. Retry would fail identically on the next delivery.
- Concurrent UPSERT from another delivery → MySQL row-lock serialises; last-write-wins on `last_synced_at` is correct semantics (the timestamps are advisory, not authoritative).
- `dispatch()` itself catches everything (`\Throwable`), logs once, returns — the controller never sees the exception.

### D8 — Test strategy

PHPUnit, no live HTTP, no live DB. Mock `Db` via the same fixture pattern Phase 4.1 used for `WebhookDeliveryRepository` tests. Each handler test instantiates the handler with a stub updater + stub heartbeat + stub logger; assertions cover (a) which updater method was called with which array, (b) what the logger received, (c) that no exception propagates.

Coverage matrix:

- `EventDispatcherTest` — 6 cases: each known event_type routes to its handler; unknown → no handler called; handler throws → swallowed + ERROR log.
- `JobCompletedHandlerTest` (and analogous for failed / cancelled / retried) — 5 cases each: valid ref + product row exists → upsert + heartbeat; valid ref + product row missing → WARNING, no upsert; malformed ref → WARNING, no upsert; payload missing required field → ERROR, no upsert; updater throws → ERROR, no propagation.
- `ExternalRefParserTest` — 6 cases: happy path; non-`ps:` prefix; truncated; non-numeric segment; negative integer; whitespace.
- `PackshotLinkUpdaterTest` — 3 cases: insert path (returns true); update-existing path (returns false); DB error → `QameraDbException`.
- `ProductLinkHeartbeatTest` — 3 cases: row present → updates `last_synced_at`, returns true; row absent → returns false; DB error → throws.
- One integration test (`WebhookDispatchIntegrationTest`): real HMAC sig over real raw body → controller invocation → assert `ps_qamera_packshot_link` row has `status='ready'` + `ps_qamera_product_link.last_synced_at` bumped, and response is `200 {"status":"ok"}`. Uses the same DB harness 4.1's persistence tests use.

### D9 — Defensive guard: external_ref shop/product must match a known `ps_qamera_product_link` row

Before any packshot upsert, the handler resolves the parsed `(shopId, productId)` against `ps_qamera_product_link`. If no row exists, the dispatch is treated as "delivery for an unknown product" — WARNING, no writes, `200` ACK. This guards against the multi-shop-on-shared-installation case where the upstream installation_id is shared across PS instances and a delivery arrives that's actually meant for a different PS.

### D10 — File-name probe before writing tasks.md

The exact Phase 4.1 wiring file names (controller file, services config file if any, repository constructor file) are not pinned here. `tasks.md` will start with a one-shot Glob/Grep step to enumerate them, then commit to the exact paths.

### D11 — Smoke procedure (operator-driven, post-merge, manual)

To verify in the live PS container after merge:

1. From the parent shell: `cd qameraai-prestashop && make up && make install` to bring up PS 8.0 + module fresh.
2. In BO, enable `QAMERAAI_AUTO_REGISTER_PRODUCTS`, save the API key + webhook secret from the Qamera AI panel into the module's Configuration page.
3. In BO, edit any product image and trigger the `actionWatermark` hook (or use the BO image upload flow that PS dispatches it on).
4. Confirm in the qamera.ai panel that a registration call landed and a job dispatched.
5. Wait ≤30 s for the upstream `job.completed` webhook delivery.
6. In the PS container shell: `mysql -e "SELECT delivery_id, event_type, status FROM ps_qamera_webhook_delivery ORDER BY received_at DESC LIMIT 5;"` — confirm the row exists with `status='accepted'`.
7. `mysql -e "SELECT qamera_packshot_id, status, last_synced_at FROM ps_qamera_packshot_link;"` — confirm a new row exists with `status='ready'` and a recent `last_synced_at`.
8. `mysql -e "SELECT id_product, status, qamera_product_id, last_synced_at FROM ps_qamera_product_link;"` — confirm the corresponding product row's `last_synced_at` was bumped and its `status` / `qamera_product_id` are unchanged from before.
9. Trigger a `job.failed` (force an error upstream — design will reference the saas-platform staging knob for this) and confirm `ps_qamera_packshot_link.status='failed'` + `last_error_message` populated.

Pass criteria: every step above produces the expected DB state and no `error`-level lines in PS logs for the success-path scenarios.

## Risks / Trade-offs

- [Payload field names diverge from upstream] → Mitigation: §11 smoke before merge — a wire mismatch will surface as ERROR-level "payload missing required field" lines on the very first real delivery; the change can land + be patched in hours since the controller still ACKs `200` (delivery rows accumulate, no upstream backpressure).
- [Schema migration runs on existing installs in field] → Mitigation: every ALTER is idempotent (probe via `SHOW COLUMNS` / `INFORMATION_SCHEMA.COLUMNS` before emitting). Pattern proven in 4.1 — no regression risk. Rollback = previous module version, additive columns survive harmlessly.
- [Concurrent deliveries clobber each other's heartbeat] → Accepted. `last_synced_at` semantics are "any recent delivery", not "specific delivery"; last-write-wins is correct. Real conflicts (status flap from `ready` to `failed` and back) are upstream bugs that should be debugged at the source — local code does what the wire said.
- [Synchronous dispatch adds latency to the ACK] → Measured budget: ≤5 ms per dispatch (1–2 UPDATEs + 1 UPSERT). PS already takes ~50 ms to bootstrap a request; +5 ms is in the noise. If ever exceeded, the table to revisit is `ps_qamera_webhook_delivery.status` — flip to `dispatch_pending` here, drain via cron later.
- [`product-image-sync` not yet creating packshot_link rows] → Accepted. Phase 4.2's `JobCompletedHandler` is the first writer (via UPSERT). If Phase 3 later starts creating "pending" packshot rows synchronously when `registerImage` returns, the UPSERT key (`qamera_packshot_id`) still works correctly: the existing row is updated in place rather than inserted.
- [Test setup for integration test is heavy (real DB)] → Accepted. The signal it produces (full wire → DB) is high-value enough to justify; Phase 4.1 already paid this cost for `WebhookDeliveryRepository`. Reuse that harness.

## Migration Plan

This is an additive, idempotent migration. Deploy = git pull + `composer install --no-dev` + module upgrade via PS BO (or first BO page load that triggers `Installer::createSchema()`). Rollback = revert to the previous module version; new columns and ENUM values remain but are harmless to older code (which doesn't read them).

No data backfill: there are no existing `ps_qamera_packshot_link` rows in any environment (the table has no writers pre-4.2).

## Open Questions

- **Exact upstream payload shape for `job.completed`** — does it carry `packshot_id`, `job_id`, `external_ref`, `asset_url`? Source of truth is `qamera-ai/saas-platform` (not this repo). Specs will assume `{event_type, delivery_id, installation_id, payload: {external_ref, packshot_id, job_id, error_message?}}` and the §11 smoke will verify. If the real wire differs, the value-object construction in `WebhookController` is the only edit point.
- **`qamera_packshot_ref` natural-key format** — proposed `ps:<shopId>:<productId>:packshot:<qamera_packshot_id>` but could also be a server-minted ref. Lock in specs scenarios; trivial to change since this is the first writer.
- **Should `job.cancelled` for a row currently `ready` overwrite the status?** Proposed YES (the row reflects "the latest authoritative state from upstream"). Reject reasonable; specs will pin it. Operator-facing semantics matter more than internal consistency here — discuss in spec review.
- **Asset URL storage** — not in 4.2. If Phase 4.3 needs to render the packshot, it can add `asset_url VARCHAR(2048)` to `ps_qamera_packshot_link` as another additive migration. Out of scope here.
