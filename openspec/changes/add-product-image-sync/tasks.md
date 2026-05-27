# Implementation tasks — add-product-image-sync

## 1. Branch + skeleton

- [x] 1.1. Branch `add-product-image-sync` off latest `main`
- [x] 1.2. Bump `composer.json` `version` to `1.2.0-dev` (released as `1.2.0` at merge time)
- [x] 1.3. Create empty file skeletons for the new sync surface:
  - `src/Api/Dto/ProductMetadata.php`
  - `src/Sync/PrimaryImageResolver.php`
  - `src/Sync/ImageUploadStrategy.php` (interface)
  - `src/Sync/PresignedImageUploadStrategy.php` (implementation)
  - `src/Sync/ProductImageSyncService.php`
  - `tests/Unit/Api/Dto/ProductMetadataTest.php`
  - `tests/Unit/Sync/PrimaryImageResolverTest.php`
  - `tests/Unit/Sync/ProductImageSyncServiceTest.php`

## 2. `ProductMetadata` DTO (`src/Api/Dto/ProductMetadata.php`)

- [x] 2.1. Test first: `testConstructorAcceptsValidValues` — `new ProductMetadata('Widget', 'WDG-001', 'desc')` round-trips through `toPayload()` to `['display_name'=>'Widget', 'sku'=>'WDG-001', 'description'=>'desc']`
- [x] 2.2. Test first: `testOmittedSkuAndDescriptionAreAbsentFromPayload` — `new ProductMetadata('Widget')->toPayload() === ['display_name'=>'Widget']` (no null keys)
- [x] 2.3. Test first: `testDisplayNameAt500CharsAccepted` / `testDisplayNameAt501CharsRejected` — boundary on `display_name` max length
- [x] 2.4. Test first: `testSkuAt100CharsAccepted` / `testSkuAt101CharsRejected`
- [x] 2.5. Test first: `testDescriptionAt5000CharsAccepted` / `testDescriptionAt5001CharsRejected`
- [x] 2.6. Test first: `testEmptyDisplayNameRejected` — `new ProductMetadata('')` throws (upstream `min(1)`)
- [x] 2.7. Implement: `final` class with readonly properties + constructor validation + `toPayload(): array<string, string>`

## 3. `RegisterImageRequest` modification (`src/Api/Dto/RegisterImageRequest.php`)

- [x] 3.1. Test first: `testPayloadIncludesProductMetadataWhenSet` — existing `RegisterImageRequest` test file gains a case for the new constructor arg
- [x] 3.2. Test first: `testPayloadOmitsProductMetadataKeyWhenNull` — verifies absence (not null) when ctor arg omitted
- [x] 3.3. Add `?ProductMetadata $productMetadata = null` as a new constructor parameter (last position to keep backward compat with existing call sites in tests)
- [x] 3.4. `toPayload()` appends `'product_metadata' => $this->productMetadata->toPayload()` only when not null
- [x] 3.5. Update PHPDoc on `toPayload()` to reflect the new optional key

## 4. `PrimaryImageResolver` (`src/Sync/PrimaryImageResolver.php`)

- [x] 4.0. Extend `tests/Stubs/PrestaShopStubs.php` with a minimal `Image` class stub. PS 9 core's `Image::getCover(int $idProduct)` returns `array|false` (associative array with at least `id_image` and `cover` keys, or `false` when no cover exists). PS 9 core's `Image::getImages(int $idLang, int $idProduct): array` returns a list of associative arrays each containing `id_image`, `cover`, `position`. The stub MUST mirror those signatures so unit tests can drive the resolver without booting PS. No constructor / instance methods are needed — the resolver consumes the array shapes directly and returns `?int $idImage`, never an `Image` instance. Phase-2 stubs covered `Db`, `Product`, `Configuration`, `PrestaShopLogger`, `PrestaShopDatabaseException`, `Context`; `Image` is the new one this change needs.
- [x] 4.1. Test first: `testCoverImageWinsOverHint` — cover exists; hint points to a non-cover image; resolver returns cover
- [x] 4.2. Test first: `testHintUsedWhenNoCover` — `Image::getCover` returns false; hint is valid image id for the product; resolver returns hint
- [x] 4.3. Test first: `testFirstByPositionFallback` — no cover, no hint, but `Image::getImages` returns 2 images; resolver returns first
- [x] 4.4. Test first: `testNullReturnedForProductWithNoImages` — all three sources empty; resolver returns null
- [x] 4.5. Test first: `testHintForDifferentProductIgnored` — hint points to an image that belongs to a different product; resolver ignores it and falls through
- [x] 4.6. Implement using a static-ish class with `resolve(int $idProduct, ?int $hintIdImage): ?int` (returns the resolved `id_image` int, NOT a PS `Image` instance — PS's `Image::getCover`/`Image::getImages` return arrays). Wraps the calls with stub-friendly indirection so unit tests can mock. The resolver also takes the shop's default `$idLang` (resolved by the caller via `Configuration::get('PS_LANG_DEFAULT', null, null, $idShop)`) for the `Image::getImages` fallback — same convention as Phase-2 snapshot writer

## 5. `ImageUploadStrategy` interface + `PresignedImageUploadStrategy`

- [x] 5.1. Define interface `ImageUploadStrategy::uploadImage(string $localPath): string` returning the canonical `source_url` the upstream `registerImage` will use. For `PresignedImageUploadStrategy` that value is the `assetId` from `PresignedUploadResponse` (an opaque upstream handle), NOT the `uploadUrl` itself — `uploadUrl` is a short-TTL PUT target with query-string credentials and is not safe to forward.
- [x] 5.2. Test first: `testHappyPathReturnsAssetId` — mocked `QameraApiClient::requestUpload` returns `PresignedUploadResponse('https://qamera-uploads.example/PUT?sig=...', 'asset-uuid', $expiresAt)` where `$expiresAt` is built relative to the test clock (e.g. `(new \DateTimeImmutable())->modify('+15 minutes')->format(\DateTimeInterface::ATOM)`), so the test does not become stale or flaky as wall-clock drifts past a hardcoded ISO timestamp; mock PUT succeeds; strategy returns `'asset-uuid'`
- [x] 5.3. Test first: `testExpiredPresignedTriggersRefresh` — first `requestUpload` returns a `PresignedUploadResponse` whose `$expiresAt` parses to `now() - 1s` (DTO camelCase property; upstream JSON field is `expires_at`); strategy calls `requestUpload` again before PUT; new URL used
- [x] 5.4. Test first: `testPutFailureRaisesTransportException` — Guzzle `ConnectException` from PUT; strategy re-raises as `TransportException`
- [x] 5.5. Test first: `testUpstreamUploadEndpointFailureBubbles` — `requestUpload` throws `ServerException`; strategy re-raises
- [x] 5.6. Implement `PresignedImageUploadStrategy` taking `QameraApiClient` + `\GuzzleHttp\ClientInterface` (for the PUT, separate from the API client since this hits S3 / Qamera CDN)

## 6. `ProductImageSyncService` (`src/Sync/ProductImageSyncService.php`)

- [x] 6.1. Test first: `testPendingRowGetsRegisteredOnSuccess` — mocked dependencies; row goes `pending` → `registered` with `qamera_product_id` from response, `last_error_message=NULL`, `last_synced_at` bumped
- [x] 6.2. Test first: `testRegisteredRowSkipsProductMetadataInRequest` — pre-seeded `status='registered'`; verify the captured `RegisterImageRequest` has `productMetadata=null`
- [x] 6.3. Test first: `testValidationExceptionMapsToError` — upstream throws `ValidationException`; row goes `pending` → `error` with `last_error_message` starting `Upstream validation:`
- [x] 6.4. Test first: `testAuthExceptionMapsToError` — `AuthException` (401) → `error` with the documented message
- [x] 6.5. Test first: `testRateLimitExceptionMapsToError` — `RateLimitException` (429) → `error`
- [x] 6.6. Test first: `testServerExceptionMapsToError` — exhausted retries → `error` with documented message
- [x] 6.7. Test first: `testTransportExceptionMapsToError` — connect failure → `error` with documented message including underlying message (truncated to 500)
- [x] 6.8. Test first: `testGenericThrowableMapsToError` — unknown `\Throwable` → `error` with `Unexpected:` prefix
- [x] 6.9. Test first: `testErrorRowRecoversToRegisteredOnSuccess` — pre-seeded `status='error'` + `last_error_message`; success path clears the error and sets `registered`
- [x] 6.10. Test first: `testNoBookkeepingRowIsNoop` — `qamera_product_link` does not have the `(id_product, id_shop)` row; service early-returns without HTTP calls, without DB writes
- [x] 6.11. Test first: `testToggleOffIsNoop` — `QAMERAAI_AUTO_REGISTER_PRODUCTS=0`; service early-returns
- [x] 6.12. Test first: `testPrimaryImageResolverReturningNullIsNoop` — product has no images; service does NOT change `status` (a missing image is missing input, not an error)
- [x] 6.13. Test first: `testHookFiresMultipleTimesForResizeThumbnailsDeduplicated` — three calls with same `(id_product, id_image)`; only one upstream call issued
- [x] 6.14. Test first: `testPendingRowUsesResolvedPrimaryNotHintForCascadeCreate` — pre-seeded `status='pending'`; mocked `PrimaryImageResolver::resolve(42, 99)` returns `100` (cover wins over hint); service uploads image 100 (NOT image 99) and registers it with `product_metadata`. Upstream call IS issued (not skipped). Row transitions to `registered`. Image 99 is NOT separately registered in this invocation — it will register later when its own `actionWatermark` invocation runs (by then the row is `registered`, bare-image path)
- [x] 6.14b. Test first: `testRegisteredRowNeverSkipsHintImage` — pre-seeded `status='registered'`, `qamera_product_id` set; hook fires with `id_image=99`; resolver is NOT consulted; service uploads image 99 directly and calls `POST /images` with `product_ref` + `source_url` but NO `product_metadata`
- [x] 6.15. Test first: `testLastErrorMessageTruncatedAt500Chars` — exception message 1000 chars; stored value is exactly 500 chars
- [x] 6.16. Implement constructor: `__construct(Db $db, string $tablePrefix, ProductRefBuilder $refBuilder, QameraApiClient $apiClient, ImageUploadStrategy $uploadStrategy, PrimaryImageResolver $resolver, PrestaShopLoggerWrapper $logger)`
- [x] 6.17. Implement `syncOnImageAdded(int $idProduct, int $idImage): void` with the full flow + state transitions + error mapping
- [x] 6.18. Implement private `mapExceptionToLastError(\Throwable $e): string` with the exact mapping from design §7

## 7. Hook wiring (`qameraai.php`, `src/Install/Installer.php`)

- [x] 7.1. Add `actionWatermark` to `Installer::HOOKS` constant
- [x] 7.2. Add `public function hookActionWatermark(array $params): void` to `qameraai.php` — extract `id_product` and `id_image` from `$params`, type-guard, delegate to `ProductImageSyncService::syncOnImageAdded`, wrap in the same try/catch + `PrestaShopLogger::addLog` pattern as `writeProductSnapshot`
- [x] 7.3. Reuse `writeProductSnapshot`'s error logging shape — severity 2, `object_type='QameraAiModule'`, `object_id=id_product`

## 8. Container wiring (`config/services.yml`)

- [x] 8.1. Register `QameraAi\Module\Sync\PrimaryImageResolver` as public service (no constructor args beyond optional autowire of Image facade if extracted)
- [x] 8.2. Register `QameraAi\Module\Sync\PresignedImageUploadStrategy` as public; explicit args: `$apiClient` (existing `QameraApiClient` service), `$httpClient` (factory for a vanilla `GuzzleHttp\Client` — distinct from the API client's instance so timeouts/headers can differ for raw PUT)
- [x] 8.3. Register `QameraAi\Module\Sync\ProductImageSyncService` as public with explicit args binding all dependencies from §6.16

## 9. Integration tests (`tests/Integration/Sync/`)

- [x] 9.1. `ProductImageSyncIntegrationTest::testRegistersPendingProductOnFirstImage` — skeleton with `@group integration`, skipped when PS core not bootstrapped (mirrors Phase 2 integration test stubs)
- [x] 9.2. `testSubsequentImageOnRegisteredProductSkipsMetadata` — skeleton
- [x] 9.3. `testErrorPathPersistsLastErrorMessage` — skeleton (uses a deliberately broken API base URL to force a transport error)

## 10. PHPStan + PHPCS

- [x] 10.1. `vendor/bin/phpcs` clean on the full change scope (`src/Api/Dto/ProductMetadata.php`, modified `RegisterImageRequest`, all new `src/Sync/*`, modified `qameraai.php`, modified `Installer.php`)
- [x] 10.2. `vendor/bin/phpstan analyse` at level 5 — clean. CI loads the real PrestaShop core via `prestashop/php-dev-tools`'s `ps-module-extension.neon` bootstrap (`_PS_ROOT_DIR_` env var pointed at a checked-out PS source), so `Image` and friends resolve to the actual core classes (not stubs). Unit-test stubs live separately in `tests/Stubs/PrestaShopStubs.php`
- [x] 10.3. CI matrix (PHP 8.1 / 8.2 / 8.3) stays green

## 11. Manual smoke (operator, with live Qamera AI credentials)

**First Phase-3 smoke is the first real upstream traffic from this plugin** — proceed carefully.

> **CRITICAL — pre-existing security incident, not introduced by this change**: the repository currently tracks `CLAUDE.md` and that file contains live production credentials (`mk_live_…` API key + installation UUID + an instruction to rotate the webhook HMAC). This is a leak: anyone with read access to the repo can lift the keys. The Phase-3 smoke MUST NOT proceed before the operator: (a) **rotates** the leaked API key and webhook HMAC in the Qamera AI panel, (b) **removes** the credential lines from `CLAUDE.md` (and rewrites repo history if required), (c) **adds** a local untracked secrets file (e.g. `.env.smoke` listed in `.gitignore`) or uses an external secret manager, (d) updates `CLAUDE.md` to reference that untracked source instead of inlining values. Treat this as a blocker for §11 and a separate, higher-priority hardening task.

Once the leak is rotated and remediated, the smoke procedure is: read credentials from the (now untracked) operator-held source, paste them ONLY into the BO configuration form, never into commit messages, PR descriptions, screenshots, or chat threads. Mid-smoke rotation: do it from the Qamera AI panel — the plugin only reads from `ps_configuration`.

- [x] 11.1. `make up` + `make install` on local Docker; module installs cleanly (no DB schema changes vs Phase 2)
- [x] 11.2. http://localhost:8080/admin-dev → Modules → Qamera AI → configuration → paste the operator-held API base / API key / webhook secret into the BO form; enable "Automatically register new products"; click "Test connection" → must show `account_name`, `credits_balance`, `installation.status=active` <!-- smoke 2026-05-27: account_name="Pracownia Qamery AI", credits_balance=10413, installation.platform=prestashop, installation.status=active -->
- [x] 11.3. Catalog → New product → name "Smoke Image v1", reference "SMOKE-IMG-001", short description "test"; save; upload one image (cover); save
- [x] 11.4. phpMyAdmin → `ps_qamera_product_link` — assert row has `status='registered'`, `qamera_product_id` is a UUID (not NULL), `last_synced_at` populated, `last_error_message` NULL <!-- product 25: qamera_product_id=7dfc163f-d7e0-4e1d-97b4-c11605f48645 after fd6bca9 -->
- [x] 11.5. Catalog → same product → upload a second image; assert `last_synced_at` bumped but `qamera_product_id` and `status='registered'` unchanged
- [x] 11.6. Force an error: temporarily set API key in configuration to a bogus value, create a new product + image; assert row has `status='error'`, `last_error_message` starts `API credentials invalid (HTTP 401)` <!-- product 26 -->
- [x] 11.7. Restore the API key, upload another image to the errored product; assert row recovers to `status='registered'` and `last_error_message=NULL` <!-- product 26 recovered: qamera_product_id=9b1a2b83-eab8-4f9c-bce3-c1eb06ac56cd -->
- [x] 11.8. Inspect BO Advanced parameters → Logs — confirm severity-2 entries match the error scenarios (no false positives on the happy path) <!-- happy path produced zero QameraAiModule entries; 401 error is stored on the bookkeeping row (per design — recoverable per-row failures do not spam ps_log) -->

> Smoke shook out three real bugs in the actionWatermark path that mocked unit tests could not have caught (Db helper `LIMIT 1` duplication, `_PS_PROD_IMG_DIR_` constant typo, `Uuid::uuid7()` clash with older ramsey/uuid bundled by `ps_checkout`/`ps_accounts`). All three are fixed in commit `fd6bca9` with CI green on PHP 8.1/8.2/8.3.

## 12. PR + merge

- [ ] 12.1. PR against `main` referencing this OpenSpec change
- [ ] 12.2. Address Copilot + manual review comments
- [ ] 12.3. Merge after green CI + smoke checklist signed off

## 13. Archive

- [ ] 13.1. `/opsx:archive add-product-image-sync` rolling deltas into:
  - `openspec/specs/product-image-sync/spec.md` (new)
  - `openspec/specs/product-sync-bookkeeping/spec.md` (state-transition additions)
  - `openspec/specs/qamera-api-client/spec.md` (`registerImage` `product_metadata` addition)
- [x] 13.2. README Phase plan — Phase 2 row: "Done"; Phase 3 row: "Done — first upstream sync"
- [x] 13.3. CHANGELOG (en/pl/uk) `[1.2.0]` entry — actionWatermark hook, ProductImageSyncService, state transitions, RegisterImageRequest product_metadata extension
- [ ] 13.4. Bump `composer.json` + `config.xml` + `config_pl.xml` to `1.2.0`; tag `v1.2.0` after merge <!-- composer.json + config.xml + config_pl.xml + qameraai.php all bumped to 1.2.0; tag v1.2.0 is post-merge operator action -->
