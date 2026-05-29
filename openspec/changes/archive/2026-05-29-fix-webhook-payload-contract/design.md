# Design — fix-webhook-payload-contract

## Context

The plugin webhook stack was built (archived changes `add-webhook-handler` 2026-05-27, `add-webhook-event-dispatch` 2026-05-28) against an envelope `{delivery_id, event_type, installation_id, payload:{external_ref, packshot_id, job_id, output_url, …}}` that the server has never sent. The real wire body and headers are fully captured in `[[project-webhook-payload-contract-mismatch]]` and the proposal. Evidence chain (all in `C:/Projects/saas-platform`):

- `apps/cg-worker/src/consumers/webhook-dispatcher/dispatcher.ts:222` — `const rawBody = JSON.stringify(row.payload)` (wire body == `webhook_deliveries.payload`, no wrapper).
- `dispatcher.ts:237` — `requestId: row.id` → `X-Qamera-Request-Id` is the stable delivery id (retries reuse the row).
- `transport.ts:42-46` — only `X-Qamera-Signature` + `X-Qamera-Request-Id` headers.
- `webhook-delivery-enqueue.service.ts:136-160` — payload = `{event, delivered_at, job:{…,job_type,product_ref,voting}, outputs:[{url}], external_metadata, callback_url}`.
- `webhook-protocol.mdoc` — documents the same body; HMAC signs `t.rawBody` (plugin `HmacVerifier:18` already matches — no change there).

## Decision log

### D1: Delivery-id source — RESOLVED → `X-Qamera-Request-Id` header

**Decision**: idempotency keys on the `X-Qamera-Request-Id` request header. Drop the `X-Qamera-Delivery-Id` header lookup and the body `delivery_id` cross-check.

**Rationale**: `dispatcher.ts:237` sets `requestId: row.id` (the `webhook_deliveries` PK), and a retry re-claims the *same* row (`dispatcher.ts:205-215` flips status pending→delivering on the same id), so the value is stable per delivery — exactly the dedup semantics the `qamera_webhook_delivery` PK needs. The body carries no `delivery_id` and there is no `X-Qamera-Delivery-Id` header, so the existing cross-check is unsatisfiable. A missing `X-Qamera-Request-Id` → 400 (replaces the old `MISSING_DELIVERY_ID`).

**Trade-off**: the dedup table's PK column is still named `delivery_id`; we keep the column name (it's the right concept) and just feed it from `X-Qamera-Request-Id`. No schema change to `qamera_webhook_delivery`.

### D2: Event-type source — RESOLVED → body `event`

**Decision**: read the event type from the body `event` field. Keep the `^[a-z][a-z0-9_.-]{0,63}$` shape gate and the forward-compat "unknown well-formed type is accepted + dispatched as no-op" behaviour. Values are unchanged (`job.completed` etc.), only the field name differs (`event` not `event_type`).

### D3: Payload shape into handlers — RESOLVED → whole body, nested access

**Decision**: `WebhookEvent.payload` is the entire decoded body. Handlers read nested paths: `payload['job']['product_ref']`, `payload['job']['id']`, `payload['job']['status']`, `payload['job']['error']`, `payload['outputs'][0]['url']`, `payload['job']['job_type']`. `PayloadExtractor` gains nested-path helpers (e.g. `string($payload, 'job', 'product_ref')` or a small `job()` / `firstOutputUrl()` accessor) rather than scattering `isset()` chains across four handlers.

`installation_id` is absent from the body; set `WebhookEvent.installationId = null` (the plugin is single-install; nothing consumes it today).

### D4: product_ref parsing — RESOLVED → new `ps:shop:product` parser

**Decision**: the webhook identifies the product via `payload.job.product_ref` of shape `ps:<shopId>:<productId>` (produced by `ProductRefBuilder`). The existing `ExternalRefParser` only accepts the image-suffixed `ps:shop:product:image:id` shape (used at `registerImage` time, never in webhooks). Replace the webhook-side parse with a product-ref parser:

- New parser accepting `^ps:([1-9][0-9]*):([1-9][0-9]*)$` → `(shopId, productId)`.
- `ExternalRefParser` (image-suffixed) is no longer referenced by the webhook path. Grep confirms it has no other caller → remove it with its `ExternalRef`/`InvalidExternalRefException` if nothing else uses them, or repurpose the file to the 2-segment parser. Implementation picks the lower-churn option; the spec only mandates the `ps:shop:product` parse + reject-everything-else behaviour.

### D5: `ps_qamera_packshot_link` fate — RESOLVED → drop the table

**Decision**: remove `ps_qamera_packshot_link`, `PackshotLinkUpdater`, the four handlers' upsert calls, the installer create/migrate for it, and add `DROP TABLE` to `upgrade-1.6.0.php`. The webhook updates only `ps_qamera_packshot_job` (keyed on `payload.job.id`).

**Rationale**: the table is keyed on `qamera_packshot_id`, which the real payload never carries, and a grep shows **nothing reads it** (only the webhook handlers + `PackshotLinkUpdater` write it; `SyncedProductLinkLookup` mentions it only in a comment). It is a write-only table fed by a path that never fired. `add-packshot-acceptance-flow` will store voting on `ps_qamera_packshot_job` (accept/reject is per `job.id`), so dropping `packshot_link` costs 4.4 nothing. Keeping it would be exactly the "garbage" the operator asked to avoid.

**FK / heartbeat survive**: `ps_qamera_packshot_job` keys on `job.id` (present) and FKs to `ps_qamera_product_link` via the parsed `(shopId, productId)`; the product-link heartbeat is unchanged. So the per-job mirror that actually feeds the BO grid keeps working — better than before, since it now receives real data.

### D6: error field — RESOLVED → `payload.job.error` (object) → message

**Decision**: `job.failed` reads `payload.job.error` (an `Error`-shaped object per OpenAPI, e.g. `{code, message}`), extracts a message string, truncates to TEXT capacity (65535 bytes), and writes `last_error_message`. The old flat `error_message` string field does not exist.

## Out of scope

- Storing `job.job_type` / `job.voting` locally and branching packshot vs photo-shoot → `add-packshot-acceptance-flow` (this fix makes the data *reach* the handler correctly; 4.4 adds the columns and the branch).
- Any change to HMAC signing, replay window, or the `qamera_webhook_delivery` table shape — all already correct.
- The webhook *replay* admin endpoint and circuit-breaker behaviour (server-side).

## Migration shape

`upgrade-1.6.0.php` (module 1.5.0 → 1.6.0, INFORMATION_SCHEMA-guarded like prior scripts): `DROP TABLE IF EXISTS {prefix}qamera_packshot_link`. `Installer::createTables()` stops creating it and `dropSchema()` drops it on uninstall (already lists it — keep that line so uninstall stays clean during the transition). No data migration — the table is unread and webhook-only.

## Verification note

Static evidence is strong but not runtime-confirmed. Task 1 triggers one real delivery against the live container and inspects BO Logs to confirm the current `400`/no-op before the rewrite, and re-runs after to confirm a `job.completed` updates `ps_qamera_packshot_job`. This mirrors the operator-driven smoke tail of `fix-packshot-asset-id-mismatch`.
