# Implementation tasks — fix-plugin-api-client-contract

## 1. Branch + skeleton

- [x] 1.1. Branch `fix-plugin-api-client-contract` off latest `main`
- [ ] 1.2. Create `tests/Contract/` directory + `tests/Contract/Fixtures/` for snapshot files
- [ ] 1.3. Capture upstream commit SHA of `qamera-ai/saas-platform` schemas.ts (operator runs `git -C C:/Projects/saas-platform rev-parse --short HEAD`) — this is the `_commit` field for every fixture in this round

## 2. `PresignedUploadResponse` DTO refactor (`src/Api/Dto/PresignedUploadResponse.php`)

- [ ] 2.1. Test first: `testFromArrayWithAllPresignedFieldsPopulated` — JsonDecoder turns `{asset_id, bucket, storage_path, upload_url, upload_token, expires_at}` into DTO with every field set
- [ ] 2.2. Test first: `testFromArrayWithNullUploadFieldsAcceptedAsMultipartShape` — `{asset_id, bucket, storage_path, upload_url: null, upload_token: null, expires_at: null}` parses cleanly (multipart variant)
- [ ] 2.3. Implement: constructor takes `string $assetId, string $bucket, string $storagePath, ?string $uploadUrl, ?string $uploadToken, ?string $expiresAt`. Remove the old non-null `$expiresAt` signature.
- [ ] 2.4. Update all existing callers (search: `new PresignedUploadResponse(`) to pass the new positional args. The Phase-3 PR #9 callers will be addressed in PR #9's rebase, not here.

## 3. `requestUpload()` rewrite (`src/Api/QameraApiClient.php`)

- [ ] 3.1. Test first: `testRequestUploadSerializesPresignedMode` — captures the JSON body dispatched and asserts `==={"mode":"presigned","filename":"cover.jpg","content_type":"image/jpeg","size_bytes":12345}`
- [ ] 3.2. Test first: `testRequestUploadRejectsEmptyFilenameBeforeHttpCall` — `''` → `InvalidArgumentException`, no Guzzle send
- [ ] 3.3. Test first: `testRequestUploadRejectsOversizeFilename` — 257-char filename → `InvalidArgumentException`
- [ ] 3.4. Test first: `testRequestUploadRejectsEmptyContentType` — `''` → `InvalidArgumentException`
- [ ] 3.5. Test first: `testRequestUploadRejectsNonPositiveSize` — `0`, `-1` → `InvalidArgumentException`
- [ ] 3.6. Test first: `testRequestUploadRejectsOversize` — `52428801` (1 byte over 50 MiB) → `InvalidArgumentException`
- [ ] 3.7. Implement: new signature `requestUpload(string $filename, string $contentType, int $sizeBytes): PresignedUploadResponse`. Constructor-side validation. Serialize as documented in proposal §2.

## 4. `RegisterImageRequest` DTO rewrite (`src/Api/Dto/RegisterImageRequest.php`)

- [ ] 4.1. Test first: `testToPayloadCarriesExternalRefProductRefAssetId` — `(new RegisterImageRequest('ext-1', 'ps:1:42', '<uuid>'))->toPayload() === ['external_ref' => 'ext-1', 'product_ref' => 'ps:1:42', 'asset_id' => '<uuid>']`
- [ ] 4.2. Test first: `testToPayloadIncludesProductMetadataWhenSet` — adds `product_metadata` key only when non-null
- [ ] 4.3. Test first: `testToPayloadOmitsLegacyFields` — payload has NO `source_url`, NO `title` keys (these are removed)
- [ ] 4.4. Test first: `testExternalRefRequired` — empty string → `InvalidArgumentException`
- [ ] 4.5. Implement: drop `sourceUrl`, `title`. Add `externalRef` (first), `productRef`, `assetId`, `?ProductMetadata $productMetadata`. Constructor validates lengths matching upstream (`external_ref ≤ 200`, `product_ref ≤ 200`, `asset_id` non-empty UUID-shaped).

## 5. `ImageResponse` DTO rewrite (`src/Api/Dto/ImageResponse.php`)

- [ ] 5.1. Test first: `testDecodesUpstreamResultShape` — JsonDecoder turns `{external_ref, product_id, image_id, status: 'created'}` into DTO; `productId` is required (UUID), not nullable
- [ ] 5.2. Test first: `testStatusEnumAcceptsCreatedAndExisting` — both `'created'` and `'existing'` round-trip; other values are NOT rejected at DTO level (this is the client; upstream is the validator)
- [ ] 5.3. Implement: drop `id`, `sourceUrl`. Add `externalRef`, `productId`, `imageId`, `status`. Keep `productRef` (still in some response shapes? — verify against upstream schema; if not present in `RegisterImageResultSchema`, drop it too)

## 6. `registerImage()` bulk wrapping (`src/Api/QameraApiClient.php`)

- [ ] 6.1. Test first: `testRegisterImageWrapsSingleInBulkArray` — captures dispatched body and asserts top-level shape `{"images":[…]}` with exactly 1 element
- [ ] 6.2. Test first: `testRegisterImageUnwrapsBulkResponse` — mock returns `{"results":[{external_ref, product_id, image_id, status: 'created'}]}` → returned `ImageResponse` has matching fields
- [ ] 6.3. Test first: `testRegisterImageEmptyResultsArrayThrowsMalformedResponse` — mock returns `{"results":[]}` → `ValidationException::malformedResponse('results[0]')`
- [ ] 6.4. Test first: `testRegisterImageMultiResultsTakesFirstAndLogs` — defensive: 2 results, return first, log a warning (no exception). Skip-test for now if logger isn't injected at this layer; mark `markTestIncomplete` with the gap.
- [ ] 6.5. Implement: wrap `$request->toPayload()` as `['images' => [$payload]]`. Parse response: extract `results` (array), assert size==1, decode first into `ImageResponse`.

## 7. `RegisterPackshotRequest` DTO rewrite (`src/Api/Dto/RegisterPackshotRequest.php`)

- [ ] 7.1. Test first: `testToPayloadCarriesExternalRefProductRefAssetId` — analogous to §4.1 but for packshots
- [ ] 7.2. Test first: `testToPayloadSerializesSourceImageRefWhenSet`
- [ ] 7.3. Test first: `testToPayloadOmitsSourceImageRefWhenNull`
- [ ] 7.4. Implement: signature `__construct(string $externalRef, string $productRef, string $assetId, ?ProductMetadata $productMetadata = null, ?string $sourceImageRef = null)`. Drop legacy `sourceUrl`.

## 8. `PackshotResponse` DTO rewrite (`src/Api/Dto/PackshotResponse.php`)

- [ ] 8.1. Test first: `testDecodesUpstreamResultShape` — `{external_ref, product_id, packshot_id, status}` round-trips
- [ ] 8.2. Implement: drop legacy fields; add `externalRef`, `productId`, `packshotId`, `status`.

## 9. `registerPackshot()` bulk wrapping (`src/Api/QameraApiClient.php`)

- [ ] 9.1. Test first: `testRegisterPackshotWrapsSingleInBulkArray` — body `{"packshots":[…]}`
- [ ] 9.2. Test first: `testRegisterPackshotUnwrapsBulkResponse`
- [ ] 9.3. Implement: symmetric to §6.

## 10. Contract test fixtures (`tests/Contract/Fixtures/`)

- [ ] 10.1. Capture `assets-upload.fixture.json` — `_source`, `_commit`, `_captured_at`, `request` (presigned mode body), `response` (presigned mode 201 body with all fields)
- [ ] 10.2. Capture `assets-upload-multipart-response.fixture.json` — same source/commit, `request: null` (we do not call this mode), `response` with null `upload_url`/`upload_token`/`expires_at`. Documents the variant DTO must support
- [ ] 10.3. Capture `images-register.fixture.json` — `request` = `{images: [{external_ref, product_ref, asset_id, product_metadata}]}`, `response` = `{results: [{external_ref, product_id, image_id, status: 'created'}]}`
- [ ] 10.4. Capture `packshots-register.fixture.json` — analogous to §10.3

## 11. Contract test runner (`tests/Contract/QameraApiContractTest.php`)

- [ ] 11.1. Test: `testEveryFixtureHasRequiredHeaders` — every `.fixture.json` has `_source`, `_commit`, `_captured_at` non-empty strings
- [ ] 11.2. Test: `testAssetsUploadRequestBodyMatchesFixture` — build a client call that produces the body in `assets-upload.fixture.json::request`; assert deep-equal
- [ ] 11.3. Test: `testAssetsUploadResponseDeserializesToFullDto` — feed `assets-upload.fixture.json::response` to JsonDecoder; assert every DTO field is the captured value
- [ ] 11.4. Test: `testAssetsUploadMultipartResponseDeserializesNullsCorrectly` — feed the multipart fixture's response; assert `?uploadUrl`/`?uploadToken`/`?expiresAt` are all `null`, the rest are populated
- [ ] 11.5. Test: `testImagesRegisterRequestBodyMatchesFixture` — wrap a `RegisterImageRequest`; assert the dispatched bulk-of-1 body deep-equals fixture's `request`
- [ ] 11.6. Test: `testImagesRegisterResponseDeserializes` — fixture response → `ImageResponse` with all fields populated
- [ ] 11.7. Test: `testPackshotsRegisterRequestBodyMatchesFixture` — analogous to §11.5
- [ ] 11.8. Test: `testPackshotsRegisterResponseDeserializes` — analogous to §11.6

## 12. Update existing tests + integration with rest of suite

- [ ] 12.1. Audit `tests/Unit/Api/QameraApiClientTest.php` — every `registerImage`/`requestUpload`/`registerPackshot` case must be updated to new signatures. Likely 5–10 test rewrites. Drop any case that asserted now-removed fields (`source_url`, `title`).
- [ ] 12.2. Phase-3 PR #9 unit tests (`tests/Unit/Sync/Presigned*Test.php`, `tests/Unit/Sync/ProductImageSyncServiceTest.php`) — NOT touched in this change, but flagged for the PR #9 rebase. Documented in design.md §5.

## 13. PHPStan + PHPCS

- [ ] 13.1. `vendor/bin/phpcs` clean on change scope (`src/Api/QameraApiClient.php`, all modified DTOs, new `tests/Contract/*`)
- [ ] 13.2. `vendor/bin/phpstan analyse --configuration=tests/phpstan/phpstan.neon` clean at level 5 with real PS core loaded
- [ ] 13.3. CI matrix (PHP 8.1 / 8.2 / 8.3) green

## 14. Manual smoke (operator)

This change is verifiable end-to-end WITHOUT the Phase-3 hook wiring — `tests/Smoke/` scripts can call the client directly against `https://qamera.ai/api/v1/plugin` once the fixes are in place. No `actionWatermark` simulation needed at this stage.

- [ ] 14.1. Operator runs untracked `tests/Smoke/connection_check.php` (or equivalent) that exercises `me()` (regression check) + `requestUpload(filename, contentType, sizeBytes)`. Expected: 201 from `/assets/upload`, DTO populated with `assetId`, `uploadUrl`, etc.
- [ ] 14.2. Operator extends the smoke script to call `registerImage(new RegisterImageRequest('smoke-ext-1', 'ps:1:9999', '<assetId from 14.1>', new ProductMetadata('Smoke', 'SMK-1', 'desc')))`. Expected: 200 from `/images`, `ImageResponse` with `productId`, `imageId`, `status='created'`.
- [ ] 14.3. Re-run §14.2 with the same `external_ref='smoke-ext-1'`. Expected: same response but `status='existing'`. (Idempotency check.)
- [ ] 14.4. Cleanup: smoke artifacts (asset upload bytes, product, image upstream) — leave in place for now (we have no `deleteProduct` smoke yet; operator's manual cleanup via Qamera AI panel if needed).

## 15. PR + merge

- [ ] 15.1. PR against `main` referencing this OpenSpec change
- [ ] 15.2. Address Copilot + manual review comments
- [ ] 15.3. Merge after green CI + smoke checklist (§14) signed off
- [ ] 15.4. **Trigger Phase-3 PR #9 rebase** (separate operator action) — design.md §5 lists the merge conflicts and their resolutions

## 16. Archive

- [ ] 16.1. `/opsx:archive fix-plugin-api-client-contract` rolling deltas into:
  - `openspec/specs/qamera-api-client/spec.md` (modified)
- [ ] 16.2. CHANGELOG (en/pl/uk) — NEW `[1.2.0-fix1]` or merge into `[1.2.0]` (depending on Phase-3 release timing; operator decides at archive time)
