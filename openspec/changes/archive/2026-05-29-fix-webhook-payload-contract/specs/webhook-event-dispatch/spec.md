## ADDED Requirements

### Requirement: product_ref parser accepts ps:shop:product and rejects everything else

The module SHALL parse the `payload.job.product_ref` field with format `ps:<shopId>:<productId>` into an immutable value containing `shopId` and `productId` as positive integers. Refs that do not match this exact shape SHALL cause the parser to throw an invalid-ref exception. The handler invoking the parser SHALL catch it, log at `warning` level, and skip the dispatch (no DB writes). This replaces the former image-suffixed `external_ref` parser — the webhook payload identifies the product by `job.product_ref` (shape `ps:shop:product`), never by an `:image:`-suffixed external_ref.

#### Scenario: Canonical product_ref parses to two positive integers
- **WHEN** the parser is given `'ps:1:42'`
- **THEN** it SHALL return a value with `shopId=1`, `productId=42`

#### Scenario: Image-suffixed ref is rejected
- **WHEN** the parser is given `'ps:1:42:image:7'`
- **THEN** it SHALL throw the invalid-ref exception (the webhook contract sends `ps:shop:product`, not the registration-time external_ref)

#### Scenario: Non-ps prefix is rejected
- **WHEN** the parser is given `'qamera:1:42'`
- **THEN** it SHALL throw the invalid-ref exception

#### Scenario: Non-numeric, negative, or whitespace-padded segments are rejected
- **WHEN** the parser is given `'ps:abc:42'`, `'ps:-1:42'`, `' ps:1:42'`, or `'ps:1:42 '`
- **THEN** it SHALL throw the invalid-ref exception

### Requirement: Job events refresh the product-link heartbeat via job.product_ref

For every routed `job.*` delivery (`completed`, `failed`, `cancelled`, `retried`), the handler SHALL parse `payload.job.product_ref` into `(shopId, productId)` and refresh the `ps_qamera_product_link` heartbeat for `(id_shop=shopId, id_product=productId)` by bumping `last_synced_at` and `updated_at` to `NOW()`. The heartbeat MUST NOT modify the Phase-3-owned columns `status`, `qamera_product_id`, or `last_error_message`. If no `ps_qamera_product_link` row matches, the handler SHALL log a `warning` and return without further writes. The per-job mirror update on `ps_qamera_packshot_job` (keyed on `payload.job.id`) is owned by the webhook-handler capability's "Job-event handlers update the local packshot_job table by qamera_job_id" requirement. No handler writes to `ps_qamera_packshot_link` — that table is removed.

#### Scenario: Heartbeat bumped for a known product
- **GIVEN** a `ps_qamera_product_link` row for `(id_shop=1, id_product=42)` with `status='registered'`, `qamera_product_id='abc'`, `last_synced_at='2026-05-27 10:00:00'`
- **WHEN** the handler processes a `job.completed` with `payload.job.product_ref='ps:1:42'`
- **THEN** the row's `last_synced_at` is bumped to the dispatch instant
- **AND** `status` stays `'registered'` and `qamera_product_id` stays `'abc'`

#### Scenario: Unknown product is logged and skipped
- **GIVEN** no `ps_qamera_product_link` row for `(id_shop=99, id_product=42)`
- **WHEN** the handler processes a delivery with `payload.job.product_ref='ps:99:42'`
- **THEN** no heartbeat write occurs and a `warning` is logged with `delivery_id` and `event_type`

#### Scenario: Malformed product_ref is logged and skipped
- **WHEN** `payload.job.product_ref` is absent or malformed
- **THEN** no DB writes occur and a `warning` is logged

## REMOVED Requirements

### Requirement: external_ref parser accepts the canonical ps prefix and rejects everything else

**Reason**: the webhook payload never carries an `external_ref` (image-suffixed `ps:shop:product:image:id`). Identification is via `job.product_ref` (`ps:shop:product`) — see the new "product_ref parser" requirement. The image-suffixed `ExternalRefParser` had no other caller and is removed.

### Requirement: job.completed upserts a ready packshot row and refreshes the product heartbeat

**Reason**: `ps_qamera_packshot_link` is removed (keyed on a `packshot_id` the wire body never carries, and read by nothing). The heartbeat behaviour moves to "Job events refresh the product-link heartbeat via job.product_ref"; the per-job mirror update moves to the webhook-handler "Job-event handlers…" requirement.

### Requirement: job.failed upserts a failed packshot row with sanitized error message

**Reason**: `ps_qamera_packshot_link` removed. The `last_error_message` now lands on the `ps_qamera_packshot_job` mirror, sourced from `payload.job.error` (object → message) — see the webhook-handler "Job-event handlers…" requirement.

### Requirement: job.cancelled upserts a cancelled packshot row

**Reason**: `ps_qamera_packshot_link` removed. Cancellation status lands on the `ps_qamera_packshot_job` mirror.

### Requirement: job.retried refreshes timestamps but never changes status

**Reason**: `ps_qamera_packshot_link` removed. `job.retried` maps the mirror status to `in_progress` (webhook-handler requirement) and refreshes the product heartbeat (new heartbeat requirement).

### Requirement: Packshot upsert is keyed on qamera_packshot_id

**Reason**: `ps_qamera_packshot_link` and `PackshotLinkUpdater` are deleted. The webhook never carries `packshot_id`; the per-job mirror is keyed on `qamera_job_id` (`payload.job.id`) instead.
