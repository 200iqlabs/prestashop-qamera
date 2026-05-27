## ADDED Requirements

### Requirement: Bookkeeping row state transitions are driven by the upstream image-sync flow

The `qamera_product_link.status` column SHALL transition between `pending`, `registered`, and `error` driven by the Phase-3 product-image-sync flow. The Phase-2 snapshot writer is NOT a transition driver — it only inserts new rows at `pending` and refreshes snapshot columns without touching `status`. The transitions allowed in Phase 3 are:

- `pending → registered`: `QameraApiClient::registerImage` returns 2xx and supplies a `product_id`. The sync service SHALL set `status='registered'`, `qamera_product_id` to the upstream value, `last_synced_at=NOW()`, and `last_error_message=NULL`.
- `pending → error`: any failure in the upstream registration path (presigned upload, PUT, or `registerImage`). The sync service SHALL set `status='error'`, `last_error_message` to a sanitized diagnostic, `last_synced_at=NOW()`, and leave `qamera_product_id` as NULL.
- `error → registered`: a subsequent `actionWatermark` triggers a retry that succeeds. The sync service SHALL clear `last_error_message` to NULL and apply the same column writes as `pending → registered`.
- `registered → registered` (no-op for status): subsequent image uploads keep `status='registered'`; only `last_synced_at` is refreshed and `qamera_product_id` is reasserted (not overwritten with a different value — that would be a logic bug).

The following transitions are deliberately NOT implemented in Phase 3 (out of scope, tracked for follow-up):
- `error → pending`: manual operator reset (requires Phase-4 UI in the product card).
- `registered → error`: regression after a previously successful registration (requires cron reconciliation).

#### Scenario: Pending row is registered after successful image upload

- **GIVEN** a row with `status='pending'`, `qamera_product_id=NULL`, `last_error_message=NULL`
- **WHEN** the image-sync flow completes successfully with upstream returning `product_id='abc-uuid'`
- **THEN** the row has `status='registered'`, `qamera_product_id='abc-uuid'`, `last_synced_at=NOW()`, `last_error_message=NULL`

#### Scenario: Pending row enters error state on upstream failure

- **GIVEN** a row with `status='pending'`
- **WHEN** the image-sync flow fails (e.g. validation error from upstream)
- **THEN** the row has `status='error'`, `last_error_message` populated with the sanitized diagnostic, `last_synced_at=NOW()`, `qamera_product_id=NULL`

#### Scenario: Error row recovers to registered without manual reset

- **GIVEN** a row with `status='error'`, `last_error_message='Upstream validation: display_name_too_long'`, `qamera_product_id=NULL`
- **WHEN** the image-sync flow retries (triggered by a subsequent `actionWatermark`) and the upstream returns 2xx
- **THEN** the row has `status='registered'`, `qamera_product_id` populated, `last_error_message=NULL`, `last_synced_at=NOW()`

#### Scenario: Subsequent image upload on a registered row preserves status and id

- **GIVEN** a row with `status='registered'`, `qamera_product_id='abc-uuid'`
- **WHEN** the operator uploads another image and the image-sync flow handles the new image
- **THEN** the row keeps `status='registered'` and `qamera_product_id='abc-uuid'`; only `last_synced_at` is refreshed

### Requirement: Snapshot writer does not interfere with sync-driven state

The Phase-2 `ProductSnapshotWriter` (driven by `actionProductSave` / `actionProductUpdate` / `actionProductAdd`) SHALL continue to ignore `status`, `qamera_product_id`, `last_error_message`, and `last_synced_at` in its upsert `ON DUPLICATE KEY UPDATE` clause. Phase 3 introduces upstream sync as a separate concern that writes those exact columns; the two concerns MUST NOT clobber each other. This is the same contract as Phase 2 — restated here because Phase 3 makes the sync-driven columns actually load-bearing.

#### Scenario: Product save during a registered row only touches snapshot columns

- **GIVEN** a row with `status='registered'`, `qamera_product_id='abc-uuid'`, `last_error_message=NULL`
- **WHEN** the operator edits the product name and `actionProductSave` fires
- **THEN** `display_name_snapshot` is refreshed and `updated_at` is bumped; `status`, `qamera_product_id`, `last_error_message`, `last_synced_at` are unchanged

#### Scenario: Product save during an error row preserves the error diagnostic

- **GIVEN** a row with `status='error'`, `last_error_message='Upstream validation: ...'`
- **WHEN** the operator edits the product reference and saves
- **THEN** `sku_snapshot` is refreshed; `status='error'` and `last_error_message` are preserved (operator must trigger another image upload — or wait for Phase-4 retry UI — to clear the error)
