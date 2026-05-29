## Why

The plugin's inbound webhook stack is built against an **envelope that the server never sends**. Verified against the saas-platform checkout (`C:/Projects/saas-platform`) on 2026-05-29:

- **Wire body** (authoritative: `webhook-protocol.mdoc`, OpenAPI `WebhookPayload`, and `apps/cg-worker/src/consumers/webhook-dispatcher/dispatcher.ts:222` `const rawBody = JSON.stringify(row.payload)`, body built in `webhook-delivery-enqueue.service.ts:136-160`):
  ```json
  { "event": "job.completed", "delivered_at": "…",
    "job": { "id","status","job_type","order_id","completed_at","error",
             "packshot_asset_id","product_label","product_ref","voting","voting_at" },
    "outputs": [ { "url","type","width","height" } ],
    "external_metadata": {…}, "callback_url": "…" }
  ```
- **Headers** sent (`transport.ts:42-46`): `X-Qamera-Signature: t=<unix>,v1=<hmac sha256 of "t.rawBody">` and `X-Qamera-Request-Id: <webhook_deliveries.id>` (`dispatcher.ts:237` `requestId: row.id` — stable across retries; the row is re-used).

What the plugin assumes instead (`src/Webhook/WebhookRequestHandler.php`, the four `src/Webhook/Event/Handler/*`, `controllers/front/webhook.php` passes the raw body unmodified):

1. Requires a body `delivery_id` equal to an `X-Qamera-Delivery-Id` header — **neither exists** → 400 `MISSING_DELIVERY_ID` / `DELIVERY_ID_MISMATCH` before dispatch.
2. Reads `event_type` from the body — the real field is `event` → 400 `MALFORMED_EVENT_TYPE`.
3. Handlers read flat `payload.external_ref` (shape `ps:shop:product:image:id`), `payload.packshot_id`, `payload.job_id`, `payload.output_url`, `payload.error_message` — the real payload has `job.product_ref` (shape `ps:shop:product`, **no** `:image:` segment), `job.id`, `outputs[0].url`, `job.error`, and **no `packshot_id` at all**.

Net effect: every real delivery is rejected `400` (or, if the envelope checks somehow passed, no-op'd because the flat fields are absent). The bug went undetected for the same reason as `[[project-packshot-asset-id-mismatch]]`: webhook unit fixtures are self-consistent with the invented shape and never round-trip a real delivery or the mdoc; `add-analysis-status-surfacing` deliberately used polling (`AnalysisStatusRefresher` → `GET /products/{ref}`), so inbound delivery was never exercised end-to-end. The `{event, job, outputs}` contract has been stable since the mdoc's 2026-05-09 publish — it predates the plugin webhook handler — so this is a wrong plugin assumption, not a server version skew. PR #203 only **added** `job.job_type` to the existing nested shape.

This is a hard prerequisite for `add-packshot-acceptance-flow`: stage 2→3 (packshot completed → open review modal) depends on receiving `job.completed` with `job.job_type='packshot'`. It also currently breaks `job.failed`/`job.cancelled`/`job.retried` handling — the operator never sees failures or completions arriving by webhook.

## What Changes

The plugin is **not yet commercial — no clients, no backward-compat constraint** — so we realign to the real contract cleanly ("zero garbage", same ethos as the asset_id fix).

**Envelope (`WebhookRequestHandler`):**
- Source the idempotency/delivery id from the `X-Qamera-Request-Id` header (the stable `webhook_deliveries.id`). Drop the `X-Qamera-Delivery-Id` header lookup and the body `delivery_id` cross-check (neither is part of the contract). A missing `X-Qamera-Request-Id` → 400.
- Source the event type from the body `event` field (not `event_type`); the `^[a-z][a-z0-9_.-]{0,63}$` shape check still applies.
- Build `WebhookEvent` with the **whole decoded body** as `payload` (so handlers read `payload['job']`, `payload['outputs']`). `installation_id` is not in the body — set null (single-install plugin).
- HMAC verification, replay window, and the `qamera_webhook_delivery` dedup table are unchanged except for the id source.

**Dispatch handlers (`JobCompletedHandler`, `JobFailedHandler`, `JobCancelledHandler`, `JobRetriedHandler`):**
- Parse `payload.job.product_ref` (shape `ps:<shopId>:<productId>`) via a new product-ref parser; refresh the `ps_qamera_product_link` heartbeat for `(shopId, productId)`.
- Update **only** `ps_qamera_packshot_job`, keyed on `payload.job.id`: status per the existing event→status map, `output_url` from `payload.outputs[0].url`, `last_error_message` from `payload.job.error` (object → message string), `last_synced_at`.
- Read `order_id` from `payload.job.order_id` for the pre-submit-race recovery path (FK resolved via the parsed product_ref, not the old `packshot_external_ref`).

**Drop the phantom-keyed table (decision: full cleanup):**
- Remove `ps_qamera_packshot_link` (keyed on `qamera_packshot_id`, which the webhook never carries and which nothing reads). Drop `PackshotLinkUpdater`, the four handlers' upsert calls, `Installer::createTables()` + `migratePackshotLinkSchema()` for it, and `DROP TABLE` in `upgrade-1.6.0.php`. Voting/packshot state for `add-packshot-acceptance-flow` will live on `ps_qamera_packshot_job` (accept/reject is per `job.id`).
- Retire/repurpose `ExternalRefParser` (the `ps:…:image:id` parser) — the webhook path no longer parses image-suffixed external_refs; replace with a `ps:shop:product` product-ref parser.

**Tests:** rebuild webhook fixtures from the **real** wire body (`{event, job, outputs}` + `X-Qamera-Request-Id`/`X-Qamera-Signature` headers); add a fixture captured/derived from the mdoc so the contract can't silently drift again.

## Capabilities

### New Capabilities

(none — fix-in-place across two existing capabilities)

### Modified Capabilities

- `webhook-handler`: envelope realignment — delivery id from `X-Qamera-Request-Id`, event from body `event`, payload = whole body. Removes the body `delivery_id`/`X-Qamera-Delivery-Id` contract.
- `webhook-event-dispatch`: handlers parse `job.product_ref`, update only `ps_qamera_packshot_job` by `job.id`, read `outputs[].url`/`job.error`; the `ps_qamera_packshot_link` table and its keyed-upsert requirement are removed.

## Impact

- **Code**: `src/Webhook/WebhookRequestHandler.php`; `src/Webhook/Event/Handler/{JobCompleted,JobFailed,JobCancelled,JobRetried}Handler.php`; `src/Webhook/Event/Handler/PayloadExtractor.php` (nested-path access for `job.*` / `outputs[]`); `src/Webhook/Event/ExternalRefParser.php` → product-ref parser; **delete** `src/Webhook/Event/PackshotLinkUpdater.php`; `src/Packshot/PackshotJobUpdater.php` (FK by product_ref, order_id source); `src/Install/Installer.php`; new `upgrade/upgrade-1.6.0.php`; `controllers/front/webhook.php` (header passthrough already fine); tests under `tests/Unit/Webhook/` + `tests/Unit/Packshot/`.
- **Schema**: `DROP TABLE ps_qamera_packshot_link`. No other destruction (table is unread and webhook-only).
- **Upstream contract**: none — consumes the already-shipped `{event, job, outputs}` body + `X-Qamera-Request-Id` header.
- **Sequencing**: lands after `fix-packshot-asset-id-mismatch`, before `add-packshot-acceptance-flow`. See `[[project-webhook-payload-contract-mismatch]]`.
- **Runtime confirmation**: the static evidence is strong but not yet runtime-confirmed — task 1 is a single live delivery against the container to observe the actual `400`/no-op before the rewrite.
