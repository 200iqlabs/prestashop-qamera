# qamera-api-client Specification

## Purpose

Typed PHP client for the Qamera AI Plugin API (`https://qamera.ai/api/v1/plugin`). Owns authentication and traceability headers, idempotency-key generation for write endpoints, exponential-backoff retry of transient failures, and the mapping from non-2xx error envelopes to a typed exception hierarchy. Consumed by the back-office configuration page (Test Connection), the catalog/job/product flows, and the webhook handler — every outbound call to the Qamera AI Plugin API goes through this client.

## Requirements
### Requirement: Client carries authentication and traceability headers on every request

`QameraApiClient` SHALL attach the following request headers on every outbound call, with no per-call opt-out:

- `X-Api-Key: <api_key>` — the value read from `Configuration::get('QAMERAAI_API_KEY')` at construction time
- `User-Agent: QameraAi-PrestaShop-Module/<module-version> (<ps-version>)` — module version from the `QameraAi::$version` property, PS version from `_PS_VERSION_`
- `Accept-Language: <iso>` — derived from the PS request context's current language, defaulting to `en`
- `Accept: application/json`

#### Scenario: X-Api-Key present on a catalog read

- **WHEN** the client issues `listAiModels()`
- **THEN** the dispatched HTTP request includes `X-Api-Key` with the configured value

#### Scenario: User-Agent identifies the module and PS version

- **WHEN** the client issues any request
- **THEN** the `User-Agent` header matches the regex `^QameraAi-PrestaShop-Module/\d+\.\d+\.\d+ \([^)]+\)$`

#### Scenario: Accept-Language reflects the PS context locale

- **WHEN** the PS context language is Polish (`pl`)
- **THEN** the dispatched request carries `Accept-Language: pl`

### Requirement: Write endpoints carry an idempotency key reused across retries

For `POST /jobs`, `POST /images`, `POST /packshots`, the client SHALL generate a UUIDv7 once at the start of the logical call and reuse that exact key value on every retry attempt of the same call. The key SHALL be sent in the `Idempotency-Key` header. The client MUST NOT accept an `Idempotency-Key` from the caller — it is always generated internally.

#### Scenario: Idempotency key generated on submitJob

- **WHEN** the caller invokes `submitJob(...)` with a fresh request DTO
- **THEN** the dispatched HTTP request carries `Idempotency-Key` matching the UUIDv7 grammar

#### Scenario: Same key reused on retry

- **WHEN** the first attempt of `submitJob` receives HTTP 503 and the retry middleware reattempts
- **THEN** the second attempt carries the same `Idempotency-Key` value as the first

#### Scenario: Distinct keys across distinct calls

- **WHEN** the caller invokes `submitJob` twice in sequence
- **THEN** the two dispatched requests carry different `Idempotency-Key` values

#### Scenario: GET endpoints carry no idempotency key

- **WHEN** the client issues `getJob(...)` or any other GET
- **THEN** the dispatched request does NOT carry an `Idempotency-Key` header

### Requirement: Transient failures are retried with exponential backoff up to four attempts

The retry middleware SHALL classify the following as transient and retryable: `ConnectException`, HTTP `502`, HTTP `503`, HTTP `504`, HTTP `429`. The backoff schedule between attempt N and N+1 SHALL be `250 ms × 2^N` (resulting in 250 ms, 500 ms, 1000 ms, 2000 ms), unless the response carries a `Retry-After` header (HTTP 429), in which case the value of `Retry-After` clamped to 60 seconds SHALL be used instead. The total number of attempts SHALL be capped at 4 (one initial + three retries).

#### Scenario: Single 503 then success

- **WHEN** the first attempt receives 503 and the second receives 200
- **THEN** the client returns the decoded response, and the retry middleware records exactly one retry

#### Scenario: 429 honours Retry-After

- **WHEN** a 429 response carries `Retry-After: 2`
- **THEN** the next attempt fires after a minimum 2 second delay

#### Scenario: Retry-After clamped at 60 seconds

- **WHEN** a 429 response carries `Retry-After: 600`
- **THEN** the next attempt fires after a 60 second delay (not 600)

#### Scenario: Four total attempts then exception

- **WHEN** every attempt up to the cap returns 503
- **THEN** the client throws `ServerException` after the fourth attempt; the exception carries the last response's parsed error envelope

#### Scenario: Non-retryable 4xx returns immediately

- **WHEN** the first attempt receives HTTP 400
- **THEN** the client throws `ValidationException` without retrying

### Requirement: Non-2xx responses are decoded into the typed exception hierarchy

For every non-2xx response, the client SHALL parse the body as the standard plugin error envelope (`{ error: { code, message_i18n, retryable, doc_url } }`) and SHALL raise an exception whose concrete subclass is determined by HTTP status:

- `401` or `403` → `AuthException`
- `404` → `NotFoundException`
- `400`, `409`, `422` → `ValidationException`
- `429` → `RateLimitException` with `retryAfter` populated from the response header
- any `5xx` after retries exhausted → `ServerException`

Each exception SHALL expose `getEnvelope(): ?ErrorEnvelope` returning the parsed body, `getStatusCode(): ?int`, and `getCorrelationId(): ?string` (read from the response's `X-Correlation-Id` header when present). Connection-level failures with no HTTP response SHALL raise `TransportException` instead.

#### Scenario: 401 maps to AuthException

- **WHEN** any request returns 401
- **THEN** the client throws `AuthException` whose `getEnvelope()->code` matches the server-supplied code

#### Scenario: 422 maps to ValidationException with code

- **WHEN** a `submitJob` call returns 422 with `error.code = "invalid_aspect_ratio"`
- **THEN** the client throws `ValidationException` whose `getEnvelope()->code === 'invalid_aspect_ratio'`

#### Scenario: 429 carries Retry-After

- **WHEN** any request returns 429 with `Retry-After: 5`
- **THEN** the (post-retry-exhaustion) `RateLimitException` exposes `getRetryAfter() === 5`

#### Scenario: Network-level failure maps to TransportException

- **WHEN** Guzzle raises `ConnectException` for every attempt up to the cap
- **THEN** the client throws `TransportException`, not `ServerException` or `ApiException`

#### Scenario: Malformed envelope still raises a typed exception

- **WHEN** a 500 response body is not valid JSON
- **THEN** the client still throws `ServerException` with `getEnvelope() === null`, never leaking a raw Guzzle exception to the caller

### Requirement: Endpoint methods are strongly typed against request and response DTOs

`QameraApiClient` SHALL expose exactly one method per consumed endpoint, with parameter types matching the corresponding request DTO (where applicable) and return types matching the response DTO. The minimum surface required by Phase 2/3 is:

- `me(): MeResponse`
- `listAiModels(): array` (of `AiModel`)
- `listSceneries(): array` (of `Scenery`)
- `listPresets(): array` (of `Preset`)
- `listAspectRatios(): array` (of `AspectRatio`)
- `getPricing(): Pricing`
- `registerImage(RegisterImageRequest $request): ImageResponse`
- `registerPackshot(RegisterPackshotRequest $request): PackshotResponse`
- `requestUpload(string $filename, string $contentType, int $sizeBytes): PresignedUploadResponse`
- `submitJob(SubmitJobRequest $request): SubmitJobResponse`
- `getJob(string $id): JobDto`
- `listJobs(JobsListFilters $filters): JobsListResponse`
- `listProducts(ProductsListFilters $filters): ProductsListResponse`
- `getProduct(string $idOrRef): ProductDetailResponse`
- `deleteProduct(string $idOrRef): void`

Response DTOs SHALL be `final readonly` classes; missing required fields on decode SHALL throw `ValidationException::malformedResponse(...)`. Unknown server-side fields SHALL be ignored.

#### Scenario: me() returns the parsed MeResponse

- **WHEN** the client issues `me()` against a healthy installation
- **THEN** the returned object is a `MeResponse` whose fields match the documented `/me` response: `account_id`, `account_name`, `account_slug`, `credits_balance`, `subscription_plan`, `rate_limit_per_min`, `installation`, `data_processors`

#### Scenario: Decode tolerates new server fields

- **WHEN** the server returns a `/me` response with an additional `experimental_feature_flag` field
- **THEN** the client returns a `MeResponse` ignoring the unknown field, without throwing

#### Scenario: Missing required field surfaces clearly

- **WHEN** the server returns a `/me` response missing `account_id`
- **THEN** the client throws `ValidationException` whose message identifies the missing field

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

#### Scenario: ProductMetadata rejects oversize display_name

- **WHEN** `new ProductMetadata(str_repeat('a', 501))` is called
- **THEN** the constructor throws `InvalidArgumentException` with a message identifying the field and the max length (display_name max 500 chars per upstream `ProductMetadataSchema`)

#### Scenario: ProductMetadata rejects oversize sku

- **WHEN** `new ProductMetadata('Widget', str_repeat('a', 101))` is called
- **THEN** the constructor throws `InvalidArgumentException` (sku max 100 chars per upstream `ProductMetadataSchema`)

#### Scenario: ProductMetadata rejects oversize description

- **WHEN** `new ProductMetadata('Widget', 'WDG', str_repeat('a', 5001))` is called
- **THEN** the constructor throws `InvalidArgumentException` (description max 5000 chars per upstream `ProductMetadataSchema`)

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

### Requirement: Unit tests assert retry, header, error mapping behaviour without live HTTP

The change SHALL ship PHPUnit unit tests under `tests/` using Guzzle's `MockHandler` for transport. The test suite SHALL cover at minimum:

- Retry decision matrix (`ConnectException`, `502`/`503`/`504`, `429` with and without `Retry-After`, non-retryable 4xx)
- Header construction (`X-Api-Key`, `User-Agent` format, `Accept-Language`, `Idempotency-Key` presence on writes and absence on reads)
- Idempotency key stability across retries
- Error mapping for each subclass (`AuthException`, `NotFoundException`, `ValidationException`, `RateLimitException`, `ServerException`, `TransportException`)
- DTO decode happy path + missing-field path + unknown-field tolerance

#### Scenario: MockHandler replays a 503-then-200 sequence

- **WHEN** the test stack returns `[Response(503), Response(200, body=meResponseFixture)]` and the suite invokes `me()`
- **THEN** the call returns successfully and the suite asserts the mock recorded exactly two requests

#### Scenario: Idempotency key stability test

- **WHEN** the test stack returns `[Response(503), Response(503), Response(503), Response(200)]` for a `submitJob` call
- **THEN** the suite asserts all four recorded requests carry the same `Idempotency-Key` header value
