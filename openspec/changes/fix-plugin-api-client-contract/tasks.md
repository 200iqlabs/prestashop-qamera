# Implementation tasks — fix-plugin-api-client-contract

## 1. Branch + skeleton

- [x] 1.1. Branch `fix-plugin-api-client-contract` off latest `main`
- [ ] 1.2. Create `tests/Contract/` + `tests/Contract/Fixtures/`
- [ ] 1.3. Capture upstream `_commit` SHA — operator runs `git -C C:/Projects/saas-platform rev-parse --short HEAD` and we paste the value into every fixture's `_commit` header in §11.x

## 2. `MeResponse.installation.scopes` minor add

- [ ] 2.1. Test first: `testMeResponseInstallationCarriesScopes` — `JsonDecoder->decode(MeResponse::class, [...full me payload with installation.scopes...])` returns DTO with `installation->scopes === [...]`
- [ ] 2.2. Implement: add `array<string> $scopes` to `InstallationInfo` ctor (after existing required fields, NOT nullable)
- [ ] 2.3. Verify TestConnectionController still works — no signature change needed, it only reads `accountName/creditsBalance`. Smoke regression check at §15.1

## 3. `PresignedUploadResponse` DTO refactor

- [ ] 3.1. Test first: `testFromArrayWithAllPresignedFieldsPopulated`
- [ ] 3.2. Test first: `testFromArrayWithNullUploadFieldsAcceptedAsMultipartShape`
- [ ] 3.3. Implement: ctor `(string $assetId, string $bucket, string $storagePath, ?string $uploadUrl, ?string $uploadToken, ?string $expiresAt)`. Drop old non-null `expiresAt`.

## 4. `requestUpload()` rewrite

- [ ] 4.1. Test first: `testRequestUploadSerializesPresignedMode`
- [ ] 4.2. Test first: `testRequestUploadRejectsEmptyFilenameBeforeHttpCall`
- [ ] 4.3. Test first: `testRequestUploadRejectsOversizeFilename` (257 chars)
- [ ] 4.4. Test first: `testRequestUploadRejectsEmptyContentType`
- [ ] 4.5. Test first: `testRequestUploadRejectsNonPositiveSize` (0, -1)
- [ ] 4.6. Test first: `testRequestUploadRejectsOversize` (52428801)
- [ ] 4.7. Implement: signature `requestUpload(string $filename, string $contentType, int $sizeBytes): PresignedUploadResponse`. Ctor-side validation. Serialize as spec §"requestUpload accepts file metadata".

## 5. `RegisterImageRequest` + `ImageResponse` DTO rewrite

- [ ] 5.1. Test first: `RegisterImageRequest::testToPayloadCarriesExternalRefProductRefAssetId`
- [ ] 5.2. Test first: `testToPayloadIncludesProductMetadataWhenSet`
- [ ] 5.3. Test first: `testToPayloadOmitsLegacyFields` (no `source_url`/`title`)
- [ ] 5.4. Test first: `testExternalRefRequiredAtConstruction` (empty/oversize → `InvalidArgumentException`)
- [ ] 5.5. Implement `RegisterImageRequest`: drop `sourceUrl`/`title`; add `externalRef` (1..200), `productRef` (1..200), `assetId` (non-empty), `?ProductMetadata`
- [ ] 5.6. Test first: `ImageResponse::testDecodesUpstreamResultShape` — `{external_ref, product_id, image_id, status:'created'|'existing'}`
- [ ] 5.7. Implement `ImageResponse`: ctor `(string $externalRef, string $productId, string $imageId, string $status)`. Drop `id`, `productRef`, `sourceUrl`.

## 6. `registerImage()` bulk wrapping

- [ ] 6.1. Test first: `testRegisterImageWrapsSingleInBulkArray`
- [ ] 6.2. Test first: `testRegisterImageUnwrapsBulkResponse`
- [ ] 6.3. Test first: `testRegisterImageEmptyResultsArrayThrowsMalformedResponse`
- [ ] 6.4. Test first: `testRegisterImageMultiResultsTakesFirstAndLogs` — defensive; `markTestIncomplete` if logger isn't injected at client layer (note in test)
- [ ] 6.5. Implement: wrap `$request->toPayload()` as `['images' => [$payload]]`. Parse `results`, assert size==1, decode first into `ImageResponse`.

## 7. `RegisterPackshotRequest` + `PackshotResponse` DTO rewrite

- [ ] 7.1. Test first: `RegisterPackshotRequest::testToPayloadCarriesExternalRefProductRefAssetId`
- [ ] 7.2. Test first: `testToPayloadSerializesSourceImageRefWhenSet`
- [ ] 7.3. Test first: `testToPayloadOmitsSourceImageRefWhenNull`
- [ ] 7.4. Implement `RegisterPackshotRequest`: `(string $externalRef, string $productRef, string $assetId, ?ProductMetadata $productMetadata = null, ?string $sourceImageRef = null)`. Drop legacy `sourceUrl`.
- [ ] 7.5. Test first: `PackshotResponse::testDecodesUpstreamResultShape`
- [ ] 7.6. Implement `PackshotResponse`: `(string $externalRef, string $productId, string $packshotId, string $status)`

## 8. `registerPackshot()` bulk wrapping

- [ ] 8.1. Test first: `testRegisterPackshotWrapsSingleInBulkArray`
- [ ] 8.2. Test first: `testRegisterPackshotUnwrapsBulkResponse`
- [ ] 8.3. Implement: symmetric to §6.5.

## 9. `sendList` parametryzacja + list endpointy

- [ ] 9.1. Test first: `testSendListWithWrapperKeyExtractsElements` — feed `{"ai_models":[…]}` to `sendList('GET', '/ai-models', 'ai_models', AiModel::class)`, get back array of `AiModel`
- [ ] 9.2. Test first: `testSendListWithMissingWrapperKeyThrowsMalformedResponse` — feed `{"items":[…]}` to `sendList(..., 'ai_models', …)`, get `ValidationException::malformedResponse('ai_models')`
- [ ] 9.3. Implement: new sygnatura `sendList(string $method, string $path, string $wrapperKey, string $elementClass)`. Wszystkie callery zaktualizowane w §10.

### 10. Per-endpoint list method updates + element DTO regen

- [ ] 10.1. **`/ai-models`**: rewrite `AiModel` DTO to `(string $id, string $provider, string $model, string $outputType, array $supportedAspectRatios, int $baseCreditCost)`. Update `listAiModels()` to pass `'ai_models'`. Add unit test for decoder + endpoint call.
- [ ] 10.2. **`/sceneries`**: rewrite `Scenery` to `(string $id, string $name, string $thumbnail, string $voting, string $status, string $source, string $createdAt)`. Update `listSceneries()`. Tests.
- [ ] 10.3. **`/presets`**: rewrite `Preset` to `(string $id, string $slug, string $name, array $descriptionI18n, int $creditCost, string $outputType, bool $isFree, ?string $coverUrl, array $quantityGuidelines, array $qualityGuidelines, array $gallery)`. Update `listPresets()`. Tests.
- [ ] 10.4. **`/aspect-ratios`**: rewrite `AspectRatio` to `(string $value, string $label, bool $default)`. Update `listAspectRatios()`. Tests.
- [ ] 10.5. **`/pricing`**: introduce `PricingEntry` DTO `(string $jobType, string $provider, string $model, int $creditCost)`. Rewrite `Pricing` DTO to `(array<PricingEntry> $entries, string $currency)`. `getPricing()` does NOT use `sendList` — dedicated parsing path. Tests for both DTO + endpoint.

## 11. `/jobs` POST — session-lifecycle DTO

- [ ] 11.1. Create `SessionConfig` DTO with constructor and tests
- [ ] 11.2. Create `Subject` DTO with constructor (9 fields per design.md §4) and tests including required-field validation
- [ ] 11.3. Test first: `SubmitJobRequest::testToPayloadSerializesNestedShape` — full upstream-matching shape
- [ ] 11.4. Implement `SubmitJobRequest`: ctor `(SessionConfig $sessionConfig, array<Subject> $subjects, ?string $callbackUrl = null, ?array $externalMetadata = null, ?string $priority = null)`. Validation: `subjects` 1..1000.
- [ ] 11.5. Create `SubmitJobResponseSubject` DTO
- [ ] 11.6. Rewrite `SubmitJobResponse` to `(string $orderId, string $status, array<SubmitJobResponseSubject> $subjects)`. Drop Phase-1 `JobResponse` reuse.
- [ ] 11.7. Test: `submitJob()` end-to-end with mocked Guzzle returns `SubmitJobResponse` shape

## 12. `/jobs/{id}` GET + `/jobs` GET — full JobDto

- [ ] 12.1. Create `JobOutput` DTO `(string $url, string $type, ?int $width, ?int $height, ?int $sizeBytes, ?string $mimeType)`
- [ ] 12.2. Create `ErrorBody` DTO (matching upstream `ErrorBodySchema`) or reuse existing one if present
- [ ] 12.3. Rewrite `JobDto` (currently `JobResponse`) with 16 fields per spec §"JobDto carries full upstream job shape"
- [ ] 12.4. Update `getJob()` return type. Tests.
- [ ] 12.5. Rewrite `JobsListFilters` to include `?createdAfter, ?createdBefore`. Drop nothing — `status, limit, cursor` already there.
- [ ] 12.6. Rewrite `JobsListResponse` wrapper key to `jobs`. Tests.
- [ ] 12.7. Update `listJobs()` to use `sendList(..., 'jobs', JobDto::class)` plus separate `next_cursor` extraction.

## 13. `/products` GET + `/products/{idOrRef}` GET — full product DTOs

- [ ] 13.1. Create `ProductImageDto` (matching `schemas.ts:731`)
- [ ] 13.2. Create `ProductPackshotDto` (matching `schemas.ts:745`)
- [ ] 13.3. Rewrite `ProductListItem` (currently mis-named `ProductResponse`?): `(string $id, ?string $externalRef, string $displayName, ?string $sku, ?string $description, ?array $sourceMetadata, int $imageCount, int $packshotCount, ?string $deletedAt, string $createdAt, string $updatedAt)`
- [ ] 13.4. Rewrite `ProductsListResponse` wrapper to `items` + `next_cursor`. Tests.
- [ ] 13.5. Rewrite `ProductsListFilters` to `(?string $ref = null, bool $includeDeleted = false, int $limit = 50, ?string $cursor = null)`. Serialize `includeDeleted` as `'true'`/`'false'` string literal. Tests.
- [ ] 13.6. Rewrite `getProduct` return type to `ProductDetailResponse` with 13 fields including `images: ProductImageDto[]`, `packshots: ProductPackshotDto[]`. Tests.
- [ ] 13.7. `deleteProduct(string $idOrRef): void` — already OK, just add a smoke note that response body is discarded.

## 14. Contract fixtures + runner

- [ ] 14.1. `tests/Contract/Fixtures/me.fixture.json` — `_source`, `_commit` (from §1.3), `_captured_at`, `request: null` (GET, no body), `response_2xx: <full /me body>`
- [ ] 14.2. `tests/Contract/Fixtures/assets-upload.fixture.json` — `request: {mode, filename, content_type, size_bytes}`, `response_2xx: {asset_id, bucket, storage_path, upload_url, upload_token, expires_at}` (presigned variant)
- [ ] 14.3. `tests/Contract/Fixtures/assets-upload-multipart-response.fixture.json` — `request: null`, `response_2xx` with null `upload_url`/`upload_token`/`expires_at` (multipart variant for DTO null-handling test)
- [ ] 14.4. `tests/Contract/Fixtures/images.fixture.json`
- [ ] 14.5. `tests/Contract/Fixtures/packshots.fixture.json`
- [ ] 14.6. `tests/Contract/Fixtures/ai-models.fixture.json`
- [ ] 14.7. `tests/Contract/Fixtures/sceneries.fixture.json`
- [ ] 14.8. `tests/Contract/Fixtures/presets.fixture.json`
- [ ] 14.9. `tests/Contract/Fixtures/aspect-ratios.fixture.json`
- [ ] 14.10. `tests/Contract/Fixtures/pricing.fixture.json`
- [ ] 14.11. `tests/Contract/Fixtures/jobs-submit.fixture.json`
- [ ] 14.12. `tests/Contract/Fixtures/jobs-get.fixture.json`
- [ ] 14.13. `tests/Contract/Fixtures/jobs-list.fixture.json`
- [ ] 14.14. `tests/Contract/Fixtures/products-list.fixture.json`
- [ ] 14.15. `tests/Contract/Fixtures/products-detail.fixture.json`
- [ ] 14.16. `tests/Contract/QameraApiContractTest.php` — generic runner: enumerate fixtures, validate headers, deserialize response → DTO, serialize request DTO → JSON, deep-compare both halves. Test per fixture (data provider).

## 15. Audit existing Phase-1 unit tests

- [ ] 15.1. `tests/Unit/Api/QameraApiClientTest.php` — rewrite every `registerImage`/`requestUpload`/`registerPackshot`/`listAiModels`/`listSceneries`/`listPresets`/`listAspectRatios`/`getPricing`/`submitJob`/`getJob`/`listJobs`/`listProducts`/`getProduct` case under new signatures. Drop assertions on now-removed fields.
- [ ] 15.2. `tests/Unit/Api/Internal/JsonDecoderTest.php` — likely add cases for nested DTO + `array<DTO>` via `#[ArrayOf]`.
- [ ] 15.3. Phase-3 PR #9 tests are NOT touched here. Documented in design.md §5 for the rebase.

## 16. PHPStan + PHPCS

- [ ] 16.1. `vendor/bin/phpcs` clean on `src/Api/*` and `tests/Contract/*`
- [ ] 16.2. `vendor/bin/phpstan analyse --configuration=tests/phpstan/phpstan.neon` clean at level 5 with real PS core loaded
- [ ] 16.3. CI matrix (PHP 8.1 / 8.2 / 8.3) green

## 17. Manual smoke (operator)

- [ ] 17.1. `tests/Smoke/connection_check.php` (untracked) — call `me()`, assert account/credits/installation populated (regression)
- [ ] 17.2. Call `requestUpload('cover.jpg', 'image/jpeg', 12345)` against real `/assets/upload` → 201 with non-null `assetId`, `uploadUrl`, `expiresAt`. PUT a 12345-byte dummy to `uploadUrl` → 200.
- [ ] 17.3. Call `registerImage(new RegisterImageRequest('smoke-ext-1', 'ps:1:9999', $assetIdFromStep2, new ProductMetadata('Smoke', 'SMK-1', 'desc')))` → `ImageResponse{productId, imageId, status:'created'}`
- [ ] 17.4. Re-run step 3 with same `external_ref` → `status:'existing'` (idempotency check)
- [ ] 17.5. Call `getPricing()` → non-empty `entries`, `currency='credits'`
- [ ] 17.6. Call `listAiModels()`, `listSceneries()`, `listPresets()`, `listAspectRatios()` → each returns ≥1 element of correct DTO shape
- [ ] 17.7. Call `listProducts(new ProductsListFilters(limit: 5))` → `items` populated with `ProductListItem` DTO (assert `displayName` populated for the synthetic smoke product from §17.3)
- [ ] 17.8. Call `getProduct($productIdFrom17.3)` → `ProductDetailResponse` with embedded `images: [...]` matching the one registered in §17.3
- [ ] 17.9. (Phase-4 territory, optional now) Call `submitJob(...)` against `/jobs` POST with a minimal session_config + 1 subject → `SubmitJobResponse` with `orderId`. Decide whether to actually run real jobs against operator-held account or stop at request-shape-verified.
- [ ] 17.10. Cleanup: delete the smoke product upstream via `deleteProduct($productId)` — verifies the only Phase-1-verified working endpoint still works post-refactor.

## 18. PR + merge

- [ ] 18.1. PR is already open (#10 draft). Mark as ready when implementation lands.
- [ ] 18.2. Address Copilot + manual review comments
- [ ] 18.3. Merge after green CI + smoke (§17) signed off
- [ ] 18.4. **Trigger Phase-3 PR #9 rebase** (separate operator action) — design.md §5 lists the conflicts and resolutions

## 19. Archive

- [ ] 19.1. `/opsx:archive fix-plugin-api-client-contract` rolling deltas into `openspec/specs/qamera-api-client/spec.md`
- [ ] 19.2. CHANGELOG (en/pl/uk) — merge into Phase-3 `[1.2.0]` entry at archive time, OR cut a `[1.2.0-rc1]` for the fix alone (operator decides based on Phase-3 timeline)
