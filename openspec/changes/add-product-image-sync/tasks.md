# Implementation tasks — add-product-image-sync

## 1. Branch + skeleton

- [x] 1.1. Branch `add-product-image-sync` off latest `main`
- [ ] 1.2. Bump `composer.json` `version` to `1.2.0-dev` (released as `1.2.0` at merge time)
- [ ] 1.3. Create empty file skeletons for the new sync surface:
  - `src/Api/Dto/ProductMetadata.php`
  - `src/Sync/PrimaryImageResolver.php`
  - `src/Sync/ImageUploadStrategy.php` (interface)
  - `src/Sync/PresignedImageUploadStrategy.php` (implementation)
  - `src/Sync/ProductImageSyncService.php`
  - `tests/Unit/Api/Dto/ProductMetadataTest.php`
  - `tests/Unit/Sync/PrimaryImageResolverTest.php`
  - `tests/Unit/Sync/ProductImageSyncServiceTest.php`

## 2. `ProductMetadata` DTO (`src/Api/Dto/ProductMetadata.php`)

- [ ] 2.1. Test first: `testConstructorAcceptsValidValues` — `new ProductMetadata('Widget', 'WDG-001', 'desc')` round-trips through `toPayload()` to `['display_name'=>'Widget', 'sku'=>'WDG-001', 'description'=>'desc']`
- [ ] 2.2. Test first: `testOmittedSkuAndDescriptionAreAbsentFromPayload` — `new ProductMetadata('Widget')->toPayload() === ['display_name'=>'Widget']` (no null keys)
- [ ] 2.3. Test first: `testDisplayNameAt500CharsAccepted` / `testDisplayNameAt501CharsRejected` — boundary on `display_name` max length
- [ ] 2.4. Test first: `testSkuAt100CharsAccepted` / `testSkuAt101CharsRejected`
- [ ] 2.5. Test first: `testDescriptionAt5000CharsAccepted` / `testDescriptionAt5001CharsRejected`
- [ ] 2.6. Test first: `testEmptyDisplayNameRejected` — `new ProductMetadata('')` throws (upstream `min(1)`)
- [ ] 2.7. Implement: `final` class with readonly properties + constructor validation + `toPayload(): array<string, string>`

## 3. `RegisterImageRequest` modification (`src/Api/Dto/RegisterImageRequest.php`)

- [ ] 3.1. Test first: `testPayloadIncludesProductMetadataWhenSet` — existing `RegisterImageRequest` test file gains a case for the new constructor arg
- [ ] 3.2. Test first: `testPayloadOmitsProductMetadataKeyWhenNull` — verifies absence (not null) when ctor arg omitted
- [ ] 3.3. Add `?ProductMetadata $productMetadata = null` as a new constructor parameter (last position to keep backward compat with existing call sites in tests)
- [ ] 3.4. `toPayload()` appends `'product_metadata' => $this->productMetadata->toPayload()` only when not null
- [ ] 3.5. Update PHPDoc on `toPayload()` to reflect the new optional key

## 4. `PrimaryImageResolver` (`src/Sync/PrimaryImageResolver.php`)

- [ ] 4.0. Extend `tests/Stubs/PrestaShopStubs.php` with a minimal `Image` class stub exposing `getCover(int $idProduct): array|false` and `getImages(int $idLang, int $idProduct): array` as static methods (matching the PS 9 core signatures). Phase-2 stubs covered `Db`, `Product`, `Configuration`, `PrestaShopLogger`, `PrestaShopDatabaseException`, `Context`; `Image` is the new one this change needs. Without the stub, `PrimaryImageResolver` tests would fail to load under the unit-only PHPUnit runner.
- [ ] 4.1. Test first: `testCoverImageWinsOverHint` — cover exists; hint points to a non-cover image; resolver returns cover
- [ ] 4.2. Test first: `testHintUsedWhenNoCover` — `Image::getCover` returns false; hint is valid image id for the product; resolver returns hint
- [ ] 4.3. Test first: `testFirstByPositionFallback` — no cover, no hint, but `Image::getImages` returns 2 images; resolver returns first
- [ ] 4.4. Test first: `testNullReturnedForProductWithNoImages` — all three sources empty; resolver returns null
- [ ] 4.5. Test first: `testHintForDifferentProductIgnored` — hint points to an image that belongs to a different product; resolver ignores it and falls through
- [ ] 4.6. Implement using a static-ish class with `resolve(int $idProduct, ?int $hintIdImage): ?Image` — wraps `Image::getCover` and `Image::getImages` calls with stub-friendly indirection so unit tests can mock

## 5. `ImageUploadStrategy` interface + `PresignedImageUploadStrategy`

- [ ] 5.1. Define interface `ImageUploadStrategy::uploadImage(string $localPath): string` returning the canonical `source_url` the upstream `registerImage` will use. For `PresignedImageUploadStrategy` that value is the `assetId` from `PresignedUploadResponse` (an opaque upstream handle), NOT the `uploadUrl` itself — `uploadUrl` is a short-TTL PUT target with query-string credentials and is not safe to forward.
- [ ] 5.2. Test first: `testHappyPathReturnsAssetId` — mocked `QameraApiClient::requestUpload` returns `PresignedUploadResponse('https://qamera-uploads.example/PUT?sig=...', 'asset-uuid', '2026-05-26T12:00:00Z')`; mock PUT succeeds; strategy returns `'asset-uuid'`
- [ ] 5.3. Test first: `testExpiredPresignedTriggersRefresh` — first `requestUpload` returns a `PresignedUploadResponse` whose `$expiresAt` parses to `now() - 1s` (DTO camelCase property; upstream JSON field is `expires_at`); strategy calls `requestUpload` again before PUT; new URL used
- [ ] 5.4. Test first: `testPutFailureRaisesTransportException` — Guzzle `ConnectException` from PUT; strategy re-raises as `TransportException`
- [ ] 5.5. Test first: `testUpstreamUploadEndpointFailureBubbles` — `requestUpload` throws `ServerException`; strategy re-raises
- [ ] 5.6. Implement `PresignedImageUploadStrategy` taking `QameraApiClient` + `\GuzzleHttp\ClientInterface` (for the PUT, separate from the API client since this hits S3 / Qamera CDN)

## 6. `ProductImageSyncService` (`src/Sync/ProductImageSyncService.php`)

- [ ] 6.1. Test first: `testPendingRowGetsRegisteredOnSuccess` — mocked dependencies; row goes `pending` → `registered` with `qamera_product_id` from response, `last_error_message=NULL`, `last_synced_at` bumped
- [ ] 6.2. Test first: `testRegisteredRowSkipsProductMetadataInRequest` — pre-seeded `status='registered'`; verify the captured `RegisterImageRequest` has `productMetadata=null`
- [ ] 6.3. Test first: `testValidationExceptionMapsToError` — upstream throws `ValidationException`; row goes `pending` → `error` with `last_error_message` starting `Upstream validation:`
- [ ] 6.4. Test first: `testAuthExceptionMapsToError` — `AuthException` (401) → `error` with the documented message
- [ ] 6.5. Test first: `testRateLimitExceptionMapsToError` — `RateLimitException` (429) → `error`
- [ ] 6.6. Test first: `testServerExceptionMapsToError` — exhausted retries → `error` with documented message
- [ ] 6.7. Test first: `testTransportExceptionMapsToError` — connect failure → `error` with documented message including underlying message (truncated to 500)
- [ ] 6.8. Test first: `testGenericThrowableMapsToError` — unknown `\Throwable` → `error` with `Unexpected:` prefix
- [ ] 6.9. Test first: `testErrorRowRecoversToRegisteredOnSuccess` — pre-seeded `status='error'` + `last_error_message`; success path clears the error and sets `registered`
- [ ] 6.10. Test first: `testNoBookkeepingRowIsNoop` — `qamera_product_link` does not have the `(id_product, id_shop)` row; service early-returns without HTTP calls, without DB writes
- [ ] 6.11. Test first: `testToggleOffIsNoop` — `QAMERAAI_AUTO_REGISTER_PRODUCTS=0`; service early-returns
- [ ] 6.12. Test first: `testPrimaryImageResolverReturningNullIsNoop` — product has no images; service does NOT change `status` (a missing image is missing input, not an error)
- [ ] 6.13. Test first: `testHookFiresMultipleTimesForResizeThumbnailsDeduplicated` — three calls with same `(id_product, id_image)`; only one upstream call issued
- [ ] 6.14. Test first: `testNonCoverThumbnailHookSkipsUpstreamCall` — hint `id_image` differs from resolved primary; service skips
- [ ] 6.15. Test first: `testLastErrorMessageTruncatedAt500Chars` — exception message 1000 chars; stored value is exactly 500 chars
- [ ] 6.16. Implement constructor: `__construct(Db $db, string $tablePrefix, ProductRefBuilder $refBuilder, QameraApiClient $apiClient, ImageUploadStrategy $uploadStrategy, PrimaryImageResolver $resolver, PrestaShopLoggerWrapper $logger)`
- [ ] 6.17. Implement `syncOnImageAdded(int $idProduct, int $idImage): void` with the full flow + state transitions + error mapping
- [ ] 6.18. Implement private `mapExceptionToLastError(\Throwable $e): string` with the exact mapping from design §7

## 7. Hook wiring (`qameraai.php`, `src/Install/Installer.php`)

- [ ] 7.1. Add `actionWatermark` to `Installer::HOOKS` constant
- [ ] 7.2. Add `public function hookActionWatermark(array $params): void` to `qameraai.php` — extract `id_product` and `id_image` from `$params`, type-guard, delegate to `ProductImageSyncService::syncOnImageAdded`, wrap in the same try/catch + `PrestaShopLogger::addLog` pattern as `writeProductSnapshot`
- [ ] 7.3. Reuse `writeProductSnapshot`'s error logging shape — severity 2, `object_type='QameraAiModule'`, `object_id=id_product`

## 8. Container wiring (`config/services.yml`)

- [ ] 8.1. Register `QameraAi\Module\Sync\PrimaryImageResolver` as public service (no constructor args beyond optional autowire of Image facade if extracted)
- [ ] 8.2. Register `QameraAi\Module\Sync\PresignedImageUploadStrategy` as public; explicit args: `$apiClient` (existing `QameraApiClient` service), `$httpClient` (factory for a vanilla `GuzzleHttp\Client` — distinct from the API client's instance so timeouts/headers can differ for raw PUT)
- [ ] 8.3. Register `QameraAi\Module\Sync\ProductImageSyncService` as public with explicit args binding all dependencies from §6.16

## 9. Integration tests (`tests/Integration/Sync/`)

- [ ] 9.1. `ProductImageSyncIntegrationTest::testRegistersPendingProductOnFirstImage` — skeleton with `@group integration`, skipped when PS core not bootstrapped (mirrors Phase 2 integration test stubs)
- [ ] 9.2. `testSubsequentImageOnRegisteredProductSkipsMetadata` — skeleton
- [ ] 9.3. `testErrorPathPersistsLastErrorMessage` — skeleton (uses a deliberately broken API base URL to force a transport error)

## 10. PHPStan + PHPCS

- [ ] 10.1. `vendor/bin/phpcs` clean on the full change scope (`src/Api/Dto/ProductMetadata.php`, modified `RegisterImageRequest`, all new `src/Sync/*`, modified `qameraai.php`, modified `Installer.php`)
- [ ] 10.2. `vendor/bin/phpstan analyse` at level 5 — clean. CI loads the real PrestaShop core via `prestashop/php-dev-tools`'s `ps-module-extension.neon` bootstrap (`_PS_ROOT_DIR_` env var pointed at a checked-out PS source), so `Image` and friends resolve to the actual core classes (not stubs). Unit-test stubs live separately in `tests/Stubs/PrestaShopStubs.php`
- [ ] 10.3. CI matrix (PHP 8.1 / 8.2 / 8.3) stays green

## 11. Manual smoke (operator, with live Qamera AI credentials)

**First Phase-3 smoke is the first real upstream traffic from this plugin** — proceed carefully. Credentials are operator-provisioned: read from `CLAUDE.md` (which is `.gitignore`d and stays on the operator's workstation — it is NOT a tracked artifact, and these tasks deliberately do NOT inline keys or installation IDs) or from the Qamera AI panel directly. Paste them only into the BO configuration form; never into commit messages, PR descriptions, screenshots, or chat threads. If credentials need to be rotated mid-smoke, do so from the Qamera AI panel — the plugin only reads them from `ps_configuration`.

> **Pre-existing risk**: the current `CLAUDE.md` in the operator's workstation may contain `mk_live_…` keys in plaintext. That is a separate hardening task (move into the operator's secret manager / `.env` outside of any agent-readable file). This change does not modify `CLAUDE.md`.

- [ ] 11.1. `make up` + `make install` on local Docker; module installs cleanly (no DB schema changes vs Phase 2)
- [ ] 11.2. http://localhost:8080/admin-dev → Modules → Qamera AI → configuration → paste the operator-held API base / API key / webhook secret into the BO form; enable "Automatically register new products"; click "Test connection" → must show `account_name`, `credits_balance`, `installation.status=active`
- [ ] 11.3. Catalog → New product → name "Smoke Image v1", reference "SMOKE-IMG-001", short description "test"; save; upload one image (cover); save
- [ ] 11.4. phpMyAdmin → `ps_qamera_product_link` — assert row has `status='registered'`, `qamera_product_id` is a UUID (not NULL), `last_synced_at` populated, `last_error_message` NULL
- [ ] 11.5. Catalog → same product → upload a second image; assert `last_synced_at` bumped but `qamera_product_id` and `status='registered'` unchanged
- [ ] 11.6. Force an error: temporarily set API key in configuration to a bogus value, create a new product + image; assert row has `status='error'`, `last_error_message` starts `API credentials invalid (HTTP 401)`
- [ ] 11.7. Restore the API key, upload another image to the errored product; assert row recovers to `status='registered'` and `last_error_message=NULL`
- [ ] 11.8. Inspect BO Advanced parameters → Logs — confirm severity-2 entries match the error scenarios (no false positives on the happy path)

## 12. PR + merge

- [ ] 12.1. PR against `main` referencing this OpenSpec change
- [ ] 12.2. Address Copilot + manual review comments
- [ ] 12.3. Merge after green CI + smoke checklist signed off

## 13. Archive

- [ ] 13.1. `/opsx:archive add-product-image-sync` rolling deltas into:
  - `openspec/specs/product-image-sync/spec.md` (new)
  - `openspec/specs/product-sync-bookkeeping/spec.md` (state-transition additions)
  - `openspec/specs/qamera-api-client/spec.md` (`registerImage` `product_metadata` addition)
- [ ] 13.2. README Phase plan — Phase 2 row: "Done"; Phase 3 row: "Done — first upstream sync"
- [ ] 13.3. CHANGELOG (en/pl/uk) `[1.2.0]` entry — actionWatermark hook, ProductImageSyncService, state transitions, RegisterImageRequest product_metadata extension
- [ ] 13.4. Bump `composer.json` + `config.xml` + `config_pl.xml` to `1.2.0`; tag `v1.2.0` after merge
