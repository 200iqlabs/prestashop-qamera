# Tasks — fix-packshot-asset-id-mismatch

## 1. Schema + migration

- [x] 1.1 In `Installer::createTables()`, rename the fresh-install column `qamera_image_id` → `qamera_asset_id CHAR(36) NULL` (update the inline comment to describe it as the storage asset id from `requestUpload()`).
- [x] 1.2 In `Installer::migrateProductLinkSchema()`, rename the `$additions` array key `qamera_image_id` → `qamera_asset_id` (same `CHAR(36) NULL` definition) so the idempotent ADD path uses the new name.
- [x] 1.3 Add `upgrade/upgrade-1.5.0.php` (`upgrade_module_1_5_0`) mirroring the `upgrade-1.4.0.php` INFORMATION_SCHEMA-guarded pattern: (a) if `qamera_image_id` present AND `qamera_asset_id` absent → `ALTER TABLE ... CHANGE COLUMN qamera_image_id qamera_asset_id CHAR(36) NULL`; (b) `UPDATE ... SET qamera_asset_id = NULL`. Log at severity 3 + return false on failure.

## 2. Sync service persistence

- [x] 2.1 Change `ProductImageSyncService::persistSuccess()` to accept the storage `asset_id` (the `$assetId` local already in scope at the call site in `syncOnImageAdded()`) and write it into `qamera_asset_id`. Stop reading/writing `ImageResponse.imageId`. Keep using `$response->productId` for the `registered` transition.
- [x] 2.2 Update the call site in `syncOnImageAdded()` to pass `$assetId`.
- [x] 2.3 Leave the `ImageResponse` DTO unchanged (it keeps `imageId` as a faithful upstream-contract mirror; it is simply no longer persisted).

## 3. Link value object + lookup

- [x] 3.1 Rename `SyncedProductLink::$qameraImageId` → `$qameraAssetId` (and any docblock references).
- [x] 3.2 In `SyncedProductLinkLookup`, rename the SELECTed column to `qamera_asset_id` and the hydration key on all three query paths (`listForGrid()`, `loadByProductIds()`, and the single-row path) and the `(string) $row['qamera_asset_id']` mapping.

## 4. Submitter

- [x] 4.1 In `PackshotJobSubmitter::submitChunk()`, source `Subject.packshotAssetId` from `$link->qameraAssetId`.
- [x] 4.2 Confirm the eligibility filter (`canGenerate()`) guarantees no empty `packshot_asset_id` is built; add a defensive note/test rather than new branching if already covered.

## 5. Generate-readiness gate + controllers

- [x] 5.1 Change `SyncedProductLink::canGenerate()` to require `qameraAssetId !== null && qameraAssetId !== '' && analysisStatus === 'described'`.
- [x] 5.2 Update `getDisabledHint()` precedence so NULL/empty `qameraAssetId` yields "Sync this product first".
- [x] 5.3 Rename the accessor usage in `ProductsGridController`, `ProductStatusController`, `GenerateFormController` (`qameraImageId` → `qameraAssetId`); confirm any array keys exposed to templates (`'qamera_image_id' => ...`) are renamed or intentionally kept for the view (rename for consistency — no client compat to preserve).

## 6. Version bump

- [x] 6.1 Bump module version 1.4.0 → 1.5.0 in `config.xml`, `config_pl.xml`, and `qameraai.php` (`$this->version`).

## 7. Tests

- [x] 7.1 Update `tests/Unit/Sync/*` (and any `ProductImageSyncService` test double) to assert `persistSuccess` stores the presigned `assetId`, distinct from the response `imageId`. Round-trip two distinct UUIDs.
- [x] 7.2 Update `tests/Unit/Packshot/SyncedProductLinkTest.php` and `FakeSyncedProductLinkLookup` to use `qameraAssetId`; add a case proving `Subject.packshot_asset_id` equals the asset id, not the image id.
- [x] 7.3 Update `tests/Unit/Packshot/PackshotJobSubmitterTest.php` and `SubmitWebhookEndToEndTest.php` fixtures (`qameraImageId:` → `qameraAssetId:`); assert empty-asset-id links are skipped by `canGenerate()`.
- [x] 7.4 Add a `canGenerate()` truth-table case: NULL asset id + `described` → false with "Sync this product first".

## 8. Static analysis + lint

- [x] 8.1 `vendor/bin/phpcs` clean (PSR-12).
- [x] 8.2 `vendor/bin/phpstan analyse` clean at level 5 (run across 8.1/8.2/8.3 matrix via the docker pattern).
- [x] 8.3 `vendor/bin/phpunit` green.

## 9. Smoke (operator-driven, main checkout on this branch)

- [x] 9.1 Install/upgrade on the live container; verify `ps_qamera_product_link` has `qamera_asset_id` and no `qamera_image_id`, all values NULL.
- [ ] 9.2 Re-save a smoke product → confirm `actionWatermark` writes a real `asset_id` (UUID matching the presigned response, not the `POST /images` imageId).
- [ ] 9.3 Submit one job for that product → confirm upstream no longer returns `generation_failed / "No source upload found"`; the job reaches a terminal success state.
- [ ] 9.4 Confirm the BO grid Generate gate: nulled (un-resynced) rows disabled with "Sync this product first"; re-synced + described rows enabled.
