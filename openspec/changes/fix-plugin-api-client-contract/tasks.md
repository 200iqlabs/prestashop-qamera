# Implementation tasks — fix-plugin-api-client-contract

## 1. Branch + skeleton

- [x] 1.1. Branch `fix-plugin-api-client-contract` off latest `main`
- [x] 1.2. Create `tests/Contract/` + `tests/Contract/Fixtures/`
- [x] 1.3. Capture upstream `_commit` SHA — operator runs `git -C <path-to-local-saas-platform-checkout> rev-parse --short HEAD` (path-agnostic; in this operator's environment that's `C:/Projects/saas-platform`, in CI / another machine it would be wherever the repo is cloned). Paste the value into every fixture's `_commit` header in §14.x — SHA captured: `abee4e7f`

## 2. `MeResponse.installation.scopes` minor add

- [x] 2.1. Test first: `testMeResponseInstallationCarriesScopes` — `JsonDecoder->decode(MeResponse::class, [...full me payload with installation.scopes...])` returns DTO with `installation->scopes === [...]`
- [x] 2.2. Implement: add `array<string> $scopes` to `InstallationInfo` ctor (after existing required fields, NOT nullable)
- [x] 2.3. Verify TestConnectionController still works — no signature change needed, it only reads `accountName/creditsBalance`. Smoke regression check at §15.1

## 3. `PresignedUploadResponse` DTO refactor

- [x] 3.1. Test first: `testFromArrayWithAllPresignedFieldsPopulated`
- [x] 3.2. Test first: `testFromArrayWithNullUploadFieldsAcceptedAsMultipartShape`
- [x] 3.3. Implement: ctor `(string $assetId, string $bucket, string $storagePath, ?string $uploadUrl, ?string $uploadToken, ?string $expiresAt)`. Drop old non-null `expiresAt`.

## 4. `requestUpload()` rewrite

- [x] 4.1. Test first: `testRequestUploadSerializesPresignedMode`
- [x] 4.2. Test first: `testRequestUploadRejectsEmptyFilenameBeforeHttpCall`
- [x] 4.3. Test first: `testRequestUploadRejectsOversizeFilename` (257 chars)
- [x] 4.4. Test first: `testRequestUploadRejectsEmptyContentType`
- [x] 4.5. Test first: `testRequestUploadRejectsNonPositiveSize` (0, -1)
- [x] 4.6. Test first: `testRequestUploadRejectsOversize` (52428801)
- [x] 4.7. Implement: signature `requestUpload(string $filename, string $contentType, int $sizeBytes): PresignedUploadResponse`. Ctor-side validation. Serialize as spec §"requestUpload accepts file metadata".

## 5. `RegisterImageRequest` + `ImageResponse` DTO rewrite

- [x] 5.1. Test first: `RegisterImageRequest::testToPayloadCarriesExternalRefProductRefAssetId`
- [x] 5.2. Test first: `testToPayloadIncludesProductMetadataWhenSet`
- [x] 5.3. Test first: `testToPayloadOmitsLegacyFields` (no `source_url`/`title`)
- [x] 5.4. Test first: `testExternalRefRequiredAtConstruction` (empty/oversize → `InvalidArgumentException`)
- [x] 5.5. Implement `RegisterImageRequest`: drop `sourceUrl`/`title`; add `externalRef` (1..200), `productRef` (1..200), `assetId` (non-empty), `?ProductMetadata`
- [x] 5.6. Test first: `ImageResponse::testDecodesUpstreamResultShape` — `{external_ref, product_id, image_id, status:'created'|'existing'}`
- [x] 5.7. Implement `ImageResponse`: ctor `(string $externalRef, string $productId, string $imageId, string $status)`. Drop `id`, `productRef`, `sourceUrl`.

## 6. `registerImage()` bulk wrapping

- [x] 6.1. Test first: `testRegisterImageWrapsSingleInBulkArray`
- [x] 6.2. Test first: `testRegisterImageUnwrapsBulkResponse`
- [x] 6.3. Test first: `testRegisterImageEmptyResultsArrayThrowsValidationException` — `{"results":[]}` → exception whose message identifies the unexpected size (0, expected 1)
- [x] 6.4. Test first: `testRegisterImageMultipleResultsAlsoThrowsValidationException` — `{"results":[<a>, <b>]}` → exception whose message identifies the unexpected size (2, expected 1). NOT a "take first + log warning" path — we sent 1, upstream guarantees 1; any other size is a real bug.
- [x] 6.5. Implement: wrap `$request->toPayload()` as `['images' => [$payload]]`. Parse `results`, enforce `count($results) === 1` (throw on 0 or >1), decode the single item into `ImageResponse`. Implementation MAY introduce a dedicated `ValidationException::unexpectedResultsSize(int $got, int $expected)` factory rather than reusing `malformedResponse()` whose message is misleading for the "too many" case.

## 7. `RegisterPackshotRequest` + `PackshotResponse` DTO rewrite

- [x] 7.1. Test first: `RegisterPackshotRequest::testToPayloadCarriesExternalRefProductRefAssetId`
- [x] 7.2. Test first: `testToPayloadSerializesSourceImageRefWhenSet`
- [x] 7.3. Test first: `testToPayloadOmitsSourceImageRefWhenNull`
- [x] 7.4. Implement `RegisterPackshotRequest`: `(string $externalRef, string $productRef, string $assetId, ?ProductMetadata $productMetadata = null, ?string $sourceImageRef = null)`. Drop legacy `sourceUrl`.
- [x] 7.5. Test first: `PackshotResponse::testDecodesUpstreamResultShape`
- [x] 7.6. Implement `PackshotResponse`: `(string $externalRef, string $productId, string $packshotId, string $status)`

## 8. `registerPackshot()` bulk wrapping

- [x] 8.1. Test first: `testRegisterPackshotWrapsSingleInBulkArray`
- [x] 8.2. Test first: `testRegisterPackshotUnwrapsBulkResponse`
- [x] 8.3. Test first: `testRegisterPackshotEmptyResultsArrayThrowsValidationException` — symmetric to §6.3
- [x] 8.4. Test first: `testRegisterPackshotMultipleResultsAlsoThrowsValidationException` — symmetric to §6.4
- [x] 8.5. Implement: symmetric to §6.5 — `count($results) === 1` enforced; same factory choice.

## 9. `sendList` parametryzacja + list endpointy

- [x] 9.1. Test first: `testSendListWithWrapperKeyExtractsElements` — feed `{"ai_models":[…]}` to `sendList('GET', '/ai-models', 'ai_models', AiModel::class)`, get back array of `AiModel`
- [x] 9.2. Test first: `testSendListWithMissingWrapperKeyThrowsMalformedResponse` — feed `{"items":[…]}` to `sendList(..., 'ai_models', …)`, get `ValidationException::malformedResponse('ai_models')`
- [x] 9.3. Implement: new sygnatura `sendList(string $method, string $path, string $wrapperKey, string $elementClass)`. Wszystkie callery zaktualizowane w §10.

### 10. Per-endpoint list method updates + element DTO regen

- [x] 10.1. **`/ai-models`**: rewrite `AiModel` DTO to `(string $id, string $provider, string $model, string $outputType, array $supportedAspectRatios, int $baseCreditCost)`. Update `listAiModels()` to pass `'ai_models'`. Add unit test for decoder + endpoint call.
- [x] 10.2. **`/sceneries`**: rewrite `Scenery` to `(string $id, string $name, ?string $thumbnail, ?string $voting, ?string $status, string $source, string $createdAt)`. Update `listSceneries()`. Tests. *(thumbnail/voting/status nullable per upstream zod — deviation §20.)*
- [x] 10.3. **`/presets`**: rewrite `Preset` to `(string $id, ?string $slug, string $name, array $descriptionI18n, int $creditCost, ?string $outputType, bool $isFree, ?string $coverUrl, string $quantityGuidelines, string $qualityGuidelines, array $gallery)`. Update `listPresets()`. Tests. *(slug/outputType nullable; quantity/quality guidelines are upstream `z.string()` free-text, NOT arrays — deviation §20.)*
- [x] 10.4. **`/aspect-ratios`**: rewrite `AspectRatio` to `(string $value, string $label, bool $default)`. Update `listAspectRatios()`. Tests.
- [x] 10.5. **`/pricing`**: introduce `PricingEntry` DTO `(string $jobType, string $provider, string $model, int $creditCost)`. Rewrite `Pricing` DTO to `(array<PricingEntry> $entries, string $currency)`. `getPricing()` does NOT use `sendList` — dedicated parsing path. Tests for both DTO + endpoint.

## 11. `/jobs` POST — session-lifecycle DTO

- [x] 11.1. Create `SessionConfig` DTO with constructor and tests
- [x] 11.2. Create `Subject` DTO with constructor (13 fields — 9 from design.md §4 + 4 extras per upstream `SubjectSchema`: `product_side`, `product_general_category`, `auto_register_packshot`, `packshot_external_ref`) and tests including required-field validation. *(deviation §20.)*
- [x] 11.3. Test first: `SubmitJobRequest::testToPayloadSerializesNestedShape` — full upstream-matching shape
- [x] 11.4. Implement `SubmitJobRequest`: ctor `(SessionConfig $sessionConfig, array<Subject> $subjects, ?string $callbackUrl = null, ?array $externalMetadata = null, ?int $priority = null)`. Validation: `subjects` 1..100 (upstream `.max(100)`), `priority` in -100..100. *(`priority` is int not string; `subjects` cap is 100 not 1000 — deviation §20.)*
- [x] 11.5. Create `SubmitJobResponseSubject` DTO
- [x] 11.6. Rewrite `SubmitJobResponse` to `(string $orderId, string $status, array<SubmitJobResponseSubject> $subjects)`. Drop Phase-1 `JobResponse` reuse.
- [x] 11.7. Test: `submitJob()` end-to-end with mocked Guzzle returns `SubmitJobResponse` shape

## 12. `/jobs/{id}` GET + `/jobs` GET — full JobDto

- [x] 12.1. Create `JobOutput` DTO `(string $url, string $type, ?int $width = null, ?int $height = null, ?int $sizeBytes = null)`. *(No `mimeType` — upstream `JobOutputSchema` has no such field; callers read `JobOutput.type` instead — deviation §20.)*
- [x] 12.2. Create `ErrorBody` DTO (matching upstream `ErrorBodySchema`) or reuse existing one if present
- [x] 12.3. Rewrite `JobDto` (currently `JobResponse`) with 16 fields per spec §"JobDto carries full upstream job shape"
- [x] 12.4. Update `getJob()` return type. Tests.
- [x] 12.5. Rewrite `JobsListFilters` to include `?createdAfter, ?createdBefore`. Drop nothing — `status, limit, cursor` already there.
- [x] 12.6. Rewrite `JobsListResponse` so `JsonDecoder` maps the upstream `jobs` wrapper into `JobDto[]` plus the sibling `next_cursor` field. Tests.
- [x] 12.7. Update `listJobs()` to return `JobsListResponse` directly via `send()`. (Original plan was `sendList(..., 'jobs', JobDto::class)` + separate `next_cursor` extraction; settled on the dedicated wrapper DTO because the response carries the `next_cursor` sibling that `sendList`'s array-extraction path would lose — same approach as `listProducts()`.)

## 13. `/products` GET + `/products/{idOrRef}` GET — full product DTOs

- [x] 13.1. Create `ProductImageDto` (matching `schemas.ts:731`)
- [x] 13.2. Create `ProductPackshotDto` (matching `schemas.ts:745`)
- [x] 13.3. Rewrite `ProductListItem` (currently mis-named `ProductResponse`?): `(string $id, ?string $externalRef, string $displayName, ?string $sku, ?string $description, ?array $sourceMetadata, int $imageCount, int $packshotCount, ?string $deletedAt, string $createdAt, string $updatedAt)`
- [x] 13.4. Rewrite `ProductsListResponse` wrapper to `items` + `next_cursor`. Tests.
- [x] 13.5. Rewrite `ProductsListFilters` to `(?string $ref = null, bool $includeDeleted = false, int $limit = 50, ?string $cursor = null)`. Serialize `includeDeleted` as `'true'`/`'false'` string literal. Tests.
- [x] 13.6. Rewrite `getProduct` return type to `ProductDetailResponse` with 13 fields including `images: ProductImageDto[]`, `packshots: ProductPackshotDto[]`. Tests.
- [x] 13.7. `deleteProduct(string $idOrRef): void` — already OK, just add a smoke note that response body is discarded.

## 14. Contract fixtures + runner

- [x] 14.1. `tests/Contract/Fixtures/me.fixture.json` — `_source`, `_commit` (from §1.3), `_captured_at`, `request: null` (GET, no body), `response_2xx: <full /me body>`
- [x] 14.2. `tests/Contract/Fixtures/assets-upload.fixture.json` — `request: {mode, filename, content_type, size_bytes}`, `response_2xx: {asset_id, bucket, storage_path, upload_url, upload_token, expires_at}` (presigned variant)
- [x] 14.3. `tests/Contract/Fixtures/assets-upload-multipart-response.fixture.json` — `request: null`, `response_2xx` with null `upload_url`/`upload_token`/`expires_at` (multipart variant for DTO null-handling test)
- [x] 14.4. `tests/Contract/Fixtures/images.fixture.json`
- [x] 14.5. `tests/Contract/Fixtures/packshots.fixture.json`
- [x] 14.6. `tests/Contract/Fixtures/ai-models.fixture.json`
- [x] 14.7. `tests/Contract/Fixtures/sceneries.fixture.json`
- [x] 14.8. `tests/Contract/Fixtures/presets.fixture.json`
- [x] 14.9. `tests/Contract/Fixtures/aspect-ratios.fixture.json`
- [x] 14.10. `tests/Contract/Fixtures/pricing.fixture.json`
- [x] 14.11. `tests/Contract/Fixtures/jobs-submit.fixture.json`
- [x] 14.12. `tests/Contract/Fixtures/jobs-get.fixture.json`
- [x] 14.13. `tests/Contract/Fixtures/jobs-list.fixture.json`
- [x] 14.14. `tests/Contract/Fixtures/products-list.fixture.json`
- [x] 14.15. `tests/Contract/Fixtures/products-detail.fixture.json`
- [x] 14.16. `tests/Contract/QameraApiContractTest.php` — generic runner: enumerate fixtures, validate headers, deserialize response → DTO, serialize request DTO → JSON, deep-compare both halves. Test per fixture (data provider).

## 15. Audit existing Phase-1 unit tests

- [x] 15.1. `tests/Unit/Api/QameraApiClientTest.php` — rewrite every `registerImage`/`requestUpload`/`registerPackshot`/`listAiModels`/`listSceneries`/`listPresets`/`listAspectRatios`/`getPricing`/`submitJob`/`getJob`/`listJobs`/`listProducts`/`getProduct` case under new signatures. Drop assertions on now-removed fields.
- [x] 15.2. `tests/Unit/Api/Internal/JsonDecoderTest.php` — likely add cases for nested DTO + `array<DTO>` via `#[ArrayOf]`.
- [x] 15.3. Phase-3 PR #9 tests are NOT touched here. Documented in design.md §5 for the rebase.

## 16. PHPStan + PHPCS

- [x] 16.1. `vendor/bin/phpcs` clean on `src/Api/*` and `tests/Contract/*`
- [x] 16.2. `vendor/bin/phpstan analyse --configuration=tests/phpstan/phpstan.neon` clean at level 5 with real PS core loaded
- [x] 16.3. CI matrix (PHP 8.1 / 8.2 / 8.3) green

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

## 20. Implementation notes — deviations from spec/design (upstream truth wins)

Captured against `qamera-ai/saas-platform@abee4e7f` `schemas.ts` while implementing. These follow the design's own principle ("PHP DTOs match upstream zod exactly"); the spec/design text is wrong in places. Operator should fold these into spec.md at `/opsx:archive` time:

- **`SessionConfig.suggestions`** — design.md §4 said `?array<string>`. Upstream is `z.string().max(2000)`. Implemented as `?string`.
- **`subjects` bulk cap** — spec.md and design.md said `subjects: 1..1000`. Upstream is `.min(1).max(100)`. Implemented as 1..100.
- **`SubmitJobRequest.priority`** — spec.md said `?string`. Upstream is `z.number().int().min(-100).max(100)`. Implemented as `?int` with range validation.
- **`JobOutput.mimeType`** — design.md mentioned `?string $mimeType`. Upstream `JobOutputSchema` has no `mime_type` field. Dropped; callers read `JobOutput.type`.
- **`JobDto.orderId`** — implied required by spec. Upstream is `.nullable()` (pre-session-lifecycle orders). Implemented as `?string`.
- **`Scenery.thumbnail`, `Scenery.voting`, `Scenery.status`** — spec said `string` for all three. Upstream marks all three `.nullable()`. Implemented as `?string`.
- **`Preset.slug`, `Preset.outputType`** — spec said `string`. Upstream `.nullable()`. Implemented as `?string`.
- **`Preset.quantityGuidelines`, `Preset.qualityGuidelines`** — spec said `array`. Upstream is `z.string()` (free-text). Implemented as `string`.
- **`ProductListItem.sourceMetadata` / `ProductDetailResponse.sourceMetadata`** — spec said `?array`. Upstream is `z.record(z.string(), z.unknown())` — required, may be `{}`. Implemented as required `array<string, mixed>` (default to empty object on the wire).
- **`Subject` extras** — design.md §4 listed 9 fields. Upstream `SubjectSchema` has 13: also `product_side`, `product_general_category`, `auto_register_packshot`, `packshot_external_ref`. All four added as optional ctor params for contract parity.
- **`Pricing` field naming** — design.md proposed `Pricing.entries`. JsonDecoder maps snake_case → camelCase; upstream key is `pricing`, so the ctor param is `$pricing`. A convenience `getEntries()` getter is exposed for spec-vocabulary access.
- **`POST /assets/upload` not in `IDEMPOTENT_WRITE_PATHS`** — Phase-1 list (`/jobs`, `/images`, `/packshots`) is preserved; upstream `/assets/upload` does not require Idempotency-Key per the route handler. Not adding it to avoid a behavior change beyond this change's scope.

None of these change the public method signatures specified in spec.md; they affect DTO field types and counts only.

