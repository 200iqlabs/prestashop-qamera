## MODIFIED Requirements

### Requirement: requestUpload accepts file metadata and serializes presigned upload body

`QameraApiClient::requestUpload(string $filename, string $contentType, int $sizeBytes): PresignedUploadResponse` SHALL POST to `/assets/upload` a JSON body of shape:

```json
{
  "mode": "presigned",
  "filename": "<string, 1..256 chars>",
  "content_type": "<string, non-empty>",
  "size_bytes": <positive int, ≤ 52428800>
}
```

The method SHALL throw `\InvalidArgumentException` before any HTTP call when arguments violate upstream constraints (empty/oversize filename, empty content_type, non-positive or oversize size_bytes). The response SHALL deserialize into `PresignedUploadResponse` carrying `assetId`, `bucket`, `storagePath`, `?uploadUrl`, `?uploadToken`, `?expiresAt`. The last three SHALL be nullable in the DTO because upstream returns null for them in multipart mode — even though this client only uses `mode=presigned`, the DTO MUST honestly reflect the server's contract.

#### Scenario: Presigned upload request body matches upstream zod

- **WHEN** `$client->requestUpload('cover.jpg', 'image/jpeg', 12345)` is called
- **THEN** the dispatched HTTP request body is exactly `{"mode":"presigned","filename":"cover.jpg","content_type":"image/jpeg","size_bytes":12345}`

#### Scenario: Oversize filename rejected before HTTP call

- **WHEN** the caller passes a 257-char `$filename`
- **THEN** the method throws `InvalidArgumentException` and dispatches no HTTP request

#### Scenario: Response with non-null upload_url is preserved

- **GIVEN** upstream returns `{"asset_id":"<uuid>","bucket":"plugin_assets","storage_path":"acct/inst/asset/cover.jpg","upload_url":"https://...","upload_token":"<jwt>","expires_at":"2026-05-26T14:00:00Z"}`
- **THEN** the returned DTO has `assetId`, `bucket`, `storagePath`, `uploadUrl`, `uploadToken`, `expiresAt` all populated

#### Scenario: Response with null fields parses without error

- **GIVEN** upstream returns the multipart variant `{"asset_id":"<uuid>","bucket":"plugin_assets","storage_path":"acct/inst/asset/cover.jpg","upload_url":null,"upload_token":null,"expires_at":null}`
- **THEN** the returned DTO has `assetId`, `bucket`, `storagePath` populated; `uploadUrl`, `uploadToken`, `expiresAt` are `null`

### Requirement: registerImage wraps single request in upstream bulk envelope and unwraps response

`QameraApiClient::registerImage(RegisterImageRequest $request): ImageResponse` SHALL POST to `/images` a JSON body of shape `{"images": [<RegisterImageRequest->toPayload()>]}` — the upstream endpoint accepts only the bulk wrapper, never a bare single object. The single-in API is preserved at the PHP level for caller ergonomics; the bulk-of-1 wrapper is an implementation detail of the client.

`RegisterImageRequest` constructor parameters (in this order): `string $externalRef` (1..200, caller-supplied stable identifier — upstream uses it as the idempotency key per `(installation_id, external_ref)`), `string $productRef` (1..200, serialized as `product_ref`), `string $assetId` (UUID; the `assetId` returned by a prior `requestUpload` — serialized as `asset_id`), `?ProductMetadata $productMetadata = null` (optional, triggers upstream cascade-create when present).

The method SHALL parse the response as `{"results": [<RegisterImageResult>]}` and extract the first (and only expected) item. `ImageResponse` SHALL carry `externalRef`, `productId` (UUID), `imageId` (UUID), `status` (`'created'` | `'existing'`). If `results` is empty OR contains more than 1 item, the client SHALL throw a `ValidationException` whose message identifies the unexpected `results` size (e.g. `"unexpected results size: 0, expected 1"` or `"unexpected results size: 2, expected 1"`). The bulk-of-1 contract is non-negotiable — we sent 1 item, upstream guarantees 1 item back; any other size is a real bug, not something to sweep into a take-first-and-log. (The existing `ValidationException::malformedResponse()` factory formats its message as "missing required field …", which is misleading for the "too many" case, so the implementation MAY introduce a dedicated factory such as `ValidationException::unexpectedResultsSize(int $got, int $expected)`.)

#### Scenario: Single-image request is wrapped in bulk array

- **WHEN** the caller invokes `registerImage(new RegisterImageRequest('ext-1', 'ps:1:42', '<asset-uuid>'))`
- **THEN** the dispatched body is `{"images":[{"external_ref":"ext-1","product_ref":"ps:1:42","asset_id":"<asset-uuid>"}]}`
- **AND** the request does NOT include `source_url`, `title`, or `product_metadata` keys

#### Scenario: Request with product_metadata cascade-creates upstream

- **WHEN** the caller passes a non-null `ProductMetadata`
- **THEN** the single bulk item carries `product_metadata: {display_name: "...", sku?: "...", description?: "..."}` alongside `external_ref`, `product_ref`, `asset_id`

#### Scenario: Response bulk-of-1 unwrapped to ImageResponse

- **GIVEN** upstream returns `{"results":[{"external_ref":"ext-1","product_id":"<prod-uuid>","image_id":"<img-uuid>","status":"created"}]}`
- **THEN** `registerImage` returns an `ImageResponse` with `externalRef='ext-1'`, `productId='<prod-uuid>'`, `imageId='<img-uuid>'`, `status='created'`

#### Scenario: Empty results array surfaces as ValidationException

- **GIVEN** upstream returns `{"results":[]}`
- **THEN** `registerImage` throws `ValidationException` whose message identifies the unexpected `results` size (0, expected 1)

#### Scenario: More than one result also throws ValidationException

- **GIVEN** upstream returns `{"results":[<first>, <second>]}` (contract violation — we sent 1)
- **THEN** `registerImage` throws `ValidationException` whose message identifies the unexpected `results` size (2, expected 1)
- **AND** the client does NOT silently return the first element

### Requirement: registerPackshot mirrors registerImage shape

`QameraApiClient::registerPackshot(RegisterPackshotRequest $request): PackshotResponse` SHALL behave symmetrically to `registerImage`: wraps the single request as `{"packshots": [<RegisterPackshotRequest->toPayload()>]}` and unwraps the response from `{"results": [<RegisterPackshotResult>]}`.

`RegisterPackshotRequest` constructor parameters: `string $externalRef`, `string $productRef`, `string $assetId`, `?ProductMetadata $productMetadata = null`, `?string $sourceImageRef = null` (optional, ≤200 chars; references an upstream image as the source of the packshot when applicable).

`PackshotResponse` SHALL carry `externalRef`, `productId`, `packshotId`, `status` (`'created'` | `'existing'`).

#### Scenario: Single-packshot request is wrapped in bulk array

- **WHEN** the caller invokes `registerPackshot(new RegisterPackshotRequest('pk-ext-1', 'ps:1:42', '<asset-uuid>'))`
- **THEN** the dispatched body is `{"packshots":[{"external_ref":"pk-ext-1","product_ref":"ps:1:42","asset_id":"<asset-uuid>"}]}`

#### Scenario: source_image_ref serialized when provided

- **WHEN** the caller passes `sourceImageRef: 'ext-img-1'`
- **THEN** the single bulk item carries `source_image_ref: 'ext-img-1'`

### Requirement: List endpoints decode their per-endpoint wrapper key

Every list endpoint the client consumes SHALL decode the response from the upstream-defined wrapper key (no shared `items` assumption). Whether this happens through a shared `sendList()` helper or a per-endpoint DTO with a matching ctor parameter is an implementation choice — what the spec constrains is the observable wire-to-DTO mapping. When the upstream response is missing the expected wrapper key, the client SHALL surface it as `ValidationException::malformedResponse(<expected_key>)`. Bare-list responses (root JSON is an array, not an object) are NOT supported — every upstream list endpoint wraps the array in an object.

Endpoint → wrapper key mapping required by this change:

| Method | Endpoint | Root keys |
|---|---|---|
| `listAiModels()` | `GET /ai-models` | `ai_models` |
| `listSceneries()` | `GET /sceneries` | `sceneries` |
| `listPresets()` | `GET /presets` | `presets` |
| `listAspectRatios()` | `GET /aspect-ratios` | `aspect_ratios` |
| `listJobs()` | `GET /jobs` | `jobs` + `next_cursor` |
| `listProducts()` | `GET /products` | `items` + `next_cursor` (kept from Phase 1) |
| `getPricing()` | `GET /pricing` | `pricing` + `currency` (literal `"credits"`) |

Endpoints that return root keys beyond the element-list wrapper (`listJobs`, `listProducts`, `getPricing`) deserialise the full response into a dedicated DTO (`JobsListResponse`, `ProductsListResponse`, `Pricing`) so the sibling fields (`next_cursor`, `currency`) are preserved. The other four catalog endpoints return only the element list, so the helper-extracts-array path is appropriate.

#### Scenario: Wrong wrapper key surfaces malformed-response error

- **GIVEN** the client calls `listAiModels()` but upstream returns `{"items": [...]}` (e.g. server bug)
- **THEN** the client throws `ValidationException` whose message identifies `ai_models` as the missing key

### Requirement: List endpoints expose element DTOs matching upstream zod

The PHP DTOs for each list endpoint's elements SHALL carry exactly the fields defined by the corresponding upstream zod schema, with snake_case → camelCase mapping via `JsonDecoder`.

| Endpoint | PHP DTO | Required fields (camelCase) |
|---|---|---|
| `GET /ai-models` | `AiModel` | `id, provider, model, outputType, supportedAspectRatios[], baseCreditCost` |
| `GET /sceneries` | `Scenery` | `id, name, thumbnail, voting, status, source, createdAt` |
| `GET /presets` | `Preset` | `id, slug, name, descriptionI18n, creditCost, outputType, isFree, coverUrl, quantityGuidelines, qualityGuidelines, gallery` |
| `GET /aspect-ratios` | `AspectRatio` | `value, label, default` |
| `GET /pricing` | `PricingEntry` (elements of `Pricing.entries`) | `jobType, provider, model, creditCost` |

Phase-1 PHP fields that no longer exist (`name`/`description` on `AiModel`, `previewUrl` on `Scenery`, `category` on `Preset`, `id`/`ratio` on `AspectRatio`, the entire flat `Pricing` shape) SHALL be removed. Adding them back is a regression.

#### Scenario: AiModel decoded with upstream field names

- **GIVEN** upstream returns `{"ai_models":[{"id":"openai/gpt-image-1","provider":"openai","model":"gpt-image-1","output_type":"image","supported_aspect_ratios":["1:1","4:5"],"base_credit_cost":5}]}`
- **THEN** the decoded `AiModel` has `id='openai/gpt-image-1'`, `provider='openai'`, `model='gpt-image-1'`, `outputType='image'`, `supportedAspectRatios=['1:1','4:5']`, `baseCreditCost=5`

#### Scenario: Pricing parsed as list-with-currency

- **GIVEN** upstream returns `{"pricing":[{"job_type":"packshot","provider":"openai","model":"gpt-image-1","credit_cost":5}],"currency":"credits"}`
- **THEN** `getPricing()` returns a `Pricing` DTO with `entries: [PricingEntry{jobType:'packshot', provider:'openai', model:'gpt-image-1', creditCost:5}]` and `currency='credits'`

### Requirement: submitJob accepts session-lifecycle shape with nested session_config and subjects

`QameraApiClient::submitJob(SubmitJobRequest $request): SubmitJobResponse` SHALL POST to `/jobs` a JSON body shaped as:

```json
{
  "session_config": { "aspect_ratio": "4:5", "model_id": "...", "scenery_id": "...", "preset_id": "...", "suggestions": "free-text guidance ≤ 2000 chars" },
  "subjects": [
    { "packshot_asset_id": "<uuid>", "product_label": "...", "product_ref": "...", "images_count": <int>, "ai_model": "provider/model", "reference_asset_ids": [...], "provider_settings": {...}, "product_name": "...", "product_specific_category": "...", "product_side": "...", "product_general_category": "...", "auto_register_packshot": false, "packshot_external_ref": "..." }
  ],
  "callback_url": "...",
  "external_metadata": {...},
  "priority": 0
}
```

PHP DTO structure (matches upstream zod at `schemas.ts@abee4e7f`):
- `SubmitJobRequest` constructor: `SessionConfig $sessionConfig`, `array<Subject> $subjects` (1..100; upstream `.max(100)`), `?string $callbackUrl = null`, `?array<string,mixed> $externalMetadata = null`, `?int $priority = null` (-100..100; upstream `z.number().int().min(-100).max(100)`)
- `SessionConfig` constructor: `string $aspectRatio` (validated against `ALLOWED_ASPECT_RATIOS` allowlist), `?string $modelId = null`, `?string $sceneryId = null`, `?string $presetId = null`, `?string $suggestions = null` (≤ 2000 chars; upstream `z.string().max(2000)` — NOT an array)
- `Subject` constructor (13 fields total): required `string $packshotAssetId`, `string $productLabel` (1..200), `string $productRef` (1..200), `int $imagesCount` (1..50), `string $aiModel` (regex `provider/model`); optional `?array<string> $referenceAssetIds`, `?array<string,mixed> $providerSettings`, `?string $productName`, `?string $productSpecificCategory`, `?string $productSide`, `?string $productGeneralCategory`, `?bool $autoRegisterPackshot`, `?string $packshotExternalRef` (≤ 200)

Response parses into `SubmitJobResponse { string $orderId, string $status, SubmitJobResponseSubject[] $subjects }`. `SubmitJobResponseSubject { string $productRef, string[] $jobIds }`.

#### Scenario: SubmitJobRequest serializes nested session_config and subjects

- **GIVEN** a `SubmitJobRequest` with `session_config.aspect_ratio='4:5'`, one subject with `product_ref='ps:1:42'`, `images_count=5`, `ai_model='openai/gpt-image-1'`, `packshot_asset_id='<uuid>'`, `product_label='Widget'`
- **THEN** the dispatched body matches the upstream zod shape exactly (nested objects, correct snake_case field names)

#### Scenario: SubmitJobResponse parses order_id and subjects

- **GIVEN** upstream returns `{"order_id":"<uuid>","status":"in_progress","subjects":[{"product_ref":"ps:1:42","job_ids":["<uuid-1>","<uuid-2>"]}]}`
- **THEN** the returned DTO has `orderId='<uuid>'`, `status='in_progress'`, `subjects[0]` is a `SubmitJobResponseSubject` with the right fields

### Requirement: JobDto carries full upstream job shape including outputs

`getJob(string $id): JobDto` and `listJobs(JobsListFilters $filters): JobsListResponse` SHALL parse jobs into a `JobDto` that exposes every upstream field. Fields (camelCase): `id, orderId: ?string (upstream `.nullable()`), jobType, provider, model, status, unitCost, attemptCount, outputs: JobOutput[], error: ?ErrorBody, externalMetadata: ?array, packshotAssetId: ?string, productLabel: ?string, productRef: ?string, voting: ?string ('accepted'|'rejected'), votingAt: ?string, createdAt, updatedAt, completedAt: ?string`.

`JobOutput` constructor: `string $url, string $type, ?int $width, ?int $height, ?int $sizeBytes`. Upstream `JobOutputSchema` has no `mime_type` field — callers SHALL read `JobOutput.type` for the MIME-ish discriminator. The Phase-1 `JobResponse.resultUrls: string[]` SHALL be removed; callers SHALL iterate `JobDto.outputs[].url` instead.

`JobsListFilters` SHALL accept `?string $status, ?string $createdAfter, ?string $createdBefore, int $limit (1..200, default 50), ?string $cursor`. Phase-1 `JobsListFilters` without `created_after`/`created_before` is replaced.

`JobsListResponse` SHALL wrap a list of `JobDto` plus a `?string $nextCursor`.

#### Scenario: getJob returns outputs as objects

- **GIVEN** upstream returns a `JobDto` with `outputs: [{url:'https://...', type:'image', width:1024, height:1280}]`
- **THEN** the PHP `JobDto.outputs[0]` is a `JobOutput` with `url`, `type`, `width=1024`, `height=1280`

#### Scenario: listJobs paginates with next_cursor

- **GIVEN** upstream returns `{"jobs":[...],"next_cursor":"<opaque>"}`
- **THEN** the returned `JobsListResponse` has `nextCursor='<opaque>'`

### Requirement: getProduct and listProducts use upstream product DTO shape

`getProduct(string $idOrRef): ProductDetailResponse` SHALL parse a response carrying `id, externalRef, displayName, sku, description, sourceMetadata, deletedAt, createdAt, updatedAt, images: ProductImageDto[], imagesTruncated, packshots: ProductPackshotDto[], packshotsTruncated`. The Phase-1 fields `title`, `status`, `ref` SHALL be removed.

`listProducts(ProductsListFilters $filters): ProductsListResponse` SHALL paginate with `{items: ProductListItem[], next_cursor: ?string}`. `ProductListItem` constructor: `string $id, ?string $externalRef, string $displayName, ?string $sku, ?string $description, ?array $sourceMetadata, int $imageCount, int $packshotCount, ?string $deletedAt, string $createdAt, string $updatedAt`.

`ProductsListFilters` SHALL accept `?string $ref, bool $includeDeleted = false, int $limit (1..200, default 50), ?string $cursor`. Phase-1 `status` filter SHALL be removed (upstream does not have it).

`ProductImageDto` and `ProductPackshotDto` SHALL match the upstream zod shape exactly (fields documented in `schemas.ts:731` and `:745`).

#### Scenario: listProducts decodes upstream product list shape

- **GIVEN** upstream returns `{"items":[{"id":"<uuid>","external_ref":"ps:1:42","display_name":"Widget","sku":"WDG-001","description":null,"source_metadata":null,"image_count":3,"packshot_count":0,"deleted_at":null,"created_at":"...","updated_at":"..."}],"next_cursor":null}`
- **THEN** the returned `ProductsListResponse` carries one `ProductListItem` with `externalRef='ps:1:42'`, `displayName='Widget'`, `imageCount=3`

#### Scenario: includeDeleted filter serialized as string literal

- **WHEN** `listProducts(new ProductsListFilters(includeDeleted: true))` is called
- **THEN** the dispatched query string contains `include_deleted=true` (upstream uses `z.enum(['true','false']).transform(...)`, so the string literal matters)

### Requirement: MeResponse exposes installation scopes

`InstallationInfo` (nested in `MeResponse`) SHALL carry `scopes: array<string>` as a required field. This is the only change to `MeResponse`; other fields remain stable. The `TestConnectionController` does not consume `scopes` — the field is documented for future scope-aware features (e.g. UI graying out actions the install lacks).

#### Scenario: MeResponse.installation.scopes parsed from upstream

- **GIVEN** upstream returns `{"...":"...","installation":{"id":"<uuid>","platform":"prestashop","status":"active","scopes":["plugin.assets:upload","plugin.catalog:write"]}}`
- **THEN** the parsed `MeResponse.installation.scopes` is `['plugin.assets:upload','plugin.catalog:write']`

### Requirement: Contract test fixtures snapshot the upstream zod shape per endpoint

The repository SHALL keep frozen JSON fixtures in `tests/Contract/Fixtures/` for every Plugin API endpoint the client actively calls in this change. Each fixture file SHALL include a header object with `_source` (path inside `qamera-ai/saas-platform`), `_commit` (short git ref of the upstream commit the snapshot reflects), and `_captured_at` (YYYY-MM-DD). The PHPUnit suite `tests/Contract/QameraApiContractTest.php` SHALL load the fixtures and assert that the client produces the captured request body and decodes the captured response body without loss.

Fixtures required by this change (15 files total — one per endpoint plus a dedicated `assets-upload-multipart-response` variant for the nullable upload fields): `me`, `assets-upload`, `assets-upload-multipart-response`, `images`, `packshots`, `ai-models`, `sceneries`, `presets`, `aspect-ratios`, `pricing`, `jobs-submit`, `jobs-get`, `jobs-list`, `products-list`, `products-detail`.

Fixtures for the 11 currently-unimplemented upstream endpoints (`/jobs/batch`, `/jobs/{id}/accept`, `/jobs/{id}/reject`, `/jobs/{id}/refresh-url`, `/orders/{id}`, `/orders/{id}/clone`, `/packshots` list, `/packshots/{idOrRef}`, `/models`, `/installations/{id}/rotate-hmac`, `/webhooks/{delivery_id}/replay`) are NOT required by this change.

#### Scenario: Fixture missing _source field fails the contract test

- **GIVEN** a fixture JSON file under `tests/Contract/Fixtures/` lacks the `_source` header
- **WHEN** `QameraApiContractTest` runs
- **THEN** the test fails with an assertion message identifying the missing header and the offending file

#### Scenario: Request body mismatch fails the contract test

- **GIVEN** a fixture defines `request.images[0].asset_id` and `RegisterImageRequest` is refactored to send a differently-named field
- **WHEN** `QameraApiContractTest` runs
- **THEN** the test fails with a diff between the captured fixture body and the body the client actually produced

#### Scenario: Response 2xx fixture deserializes into populated DTO

- **GIVEN** a fixture defines `response_2xx` for `GET /pricing` as `{"pricing":[…],"currency":"credits"}`
- **WHEN** the contract test feeds the body through the client's parsing path
- **THEN** the resulting `Pricing` DTO has `entries` populated and `currency='credits'`
