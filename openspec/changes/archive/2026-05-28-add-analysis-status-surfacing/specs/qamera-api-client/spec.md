## MODIFIED Requirements

### Requirement: getProduct and listProducts use upstream product DTO shape

`getProduct(string $idOrRef): ProductDetailResponse` SHALL parse a response carrying `id, externalRef, displayName, sku, description, sourceMetadata, deletedAt, createdAt, updatedAt, images: ProductImageDto[], imagesTruncated, packshots: ProductPackshotDto[], packshotsTruncated`. The Phase-1 fields `title`, `status`, `ref` SHALL be removed.

`listProducts(ProductsListFilters $filters): ProductsListResponse` SHALL paginate with `{items: ProductListItem[], next_cursor: ?string}`. `ProductListItem` constructor: `string $id, ?string $externalRef, string $displayName, ?string $sku, ?string $description, ?array $sourceMetadata, int $imageCount, int $packshotCount, ?string $deletedAt, string $createdAt, string $updatedAt`.

`ProductsListFilters` SHALL accept `?string $ref, bool $includeDeleted = false, int $limit (1..200, default 50), ?string $cursor`. Phase-1 `status` filter SHALL be removed (upstream does not have it).

`ProductImageDto` constructor (in this order, all required unless noted nullable): `string $id, ?string $externalRef, string $productId, string $assetId, int $byteSize, string $contentType, ?int $width, ?int $height, string $sha256, string $analysisStatus, ?string $analyzedAt, string $createdAt`. `analysisStatus` is the upstream `analysis_status` enum (`pending|processing|described|error`) — decode SHALL throw `ValidationException::malformedResponse('analysis_status')` if the field is missing OR carries a value outside the enum. `analyzedAt` is the upstream `analyzed_at` ISO8601 string, nullable.

`ProductPackshotDto` SHALL match the upstream zod shape exactly (fields documented in `schemas.ts:745`).

#### Scenario: listProducts decodes upstream product list shape

- **GIVEN** upstream returns `{"items":[{"id":"<uuid>","external_ref":"ps:1:42","display_name":"Widget","sku":"WDG-001","description":null,"source_metadata":null,"image_count":3,"packshot_count":0,"deleted_at":null,"created_at":"...","updated_at":"..."}],"next_cursor":null}`
- **THEN** the returned `ProductsListResponse` carries one `ProductListItem` with `externalRef='ps:1:42'`, `displayName='Widget'`, `imageCount=3`

#### Scenario: includeDeleted filter serialized as string literal

- **WHEN** `listProducts(new ProductsListFilters(includeDeleted: true))` is called
- **THEN** the dispatched query string contains `include_deleted=true` (upstream uses `z.enum(['true','false']).transform(...)`, so the string literal matters)

#### Scenario: getProduct decodes analysis_status and analyzed_at on every image

- **GIVEN** upstream returns a product detail with `images: [{"id":"<img-uuid>","external_ref":"ps:1:42:image:99","product_id":"<prod-uuid>","asset_id":"<asset-uuid>","byte_size":12345,"content_type":"image/jpeg","width":1024,"height":1280,"sha256":"<hex>","analysis_status":"described","analyzed_at":"2026-05-28T10:00:00Z","created_at":"2026-05-28T09:58:00Z"}]`
- **WHEN** the client decodes the response
- **THEN** the resulting `ProductImageDto` has `analysisStatus='described'` and `analyzedAt='2026-05-28T10:00:00Z'`

#### Scenario: getProduct accepts null analyzed_at for not-yet-analysed images

- **GIVEN** upstream returns an image with `"analysis_status":"pending","analyzed_at":null`
- **WHEN** the client decodes the response
- **THEN** the resulting `ProductImageDto` has `analysisStatus='pending'` and `analyzedAt=null`

#### Scenario: Missing analysis_status surfaces as ValidationException

- **GIVEN** upstream returns an image where the `analysis_status` field is absent (backend regression)
- **WHEN** the client decodes the response
- **THEN** the client throws `ValidationException` whose message identifies `analysis_status` as the missing field

#### Scenario: Unknown analysis_status value surfaces as ValidationException

- **GIVEN** upstream returns an image with `"analysis_status":"unknown_value"`
- **WHEN** the client decodes the response
- **THEN** the client throws `ValidationException` whose message identifies the invalid `analysis_status` value


### Requirement: Contract test fixtures snapshot the upstream zod shape per endpoint

The repository SHALL keep frozen JSON fixtures in `tests/Contract/Fixtures/` for every Plugin API endpoint the client actively calls in this change. Each fixture file SHALL include a header object with `_source` (path inside `qamera-ai/saas-platform`), `_commit` (short git ref of the upstream commit the snapshot reflects), and `_captured_at` (YYYY-MM-DD). The PHPUnit suite `tests/Contract/QameraApiContractTest.php` SHALL load the fixtures and assert that the client produces the captured request body and decodes the captured response body without loss.

Fixtures required by this change (15 files total — one per endpoint plus a dedicated `assets-upload-multipart-response` variant for the nullable upload fields): `me`, `assets-upload`, `assets-upload-multipart-response`, `images`, `packshots`, `ai-models`, `sceneries`, `presets`, `aspect-ratios`, `pricing`, `jobs-submit`, `jobs-get`, `jobs-list`, `products-list`, `products-detail`. The `products-detail` fixture's `images[]` entries SHALL include `analysis_status` and `analyzed_at` fields, snapshotting the post-PR-204 upstream shape; the `_commit` header SHALL reference the saas-platform commit that landed the analysis-status surfacing.

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

#### Scenario: products-detail fixture surfaces analysis_status into ProductImageDto

- **GIVEN** the `products-detail` fixture defines `response_2xx.images[0]` with `analysis_status="described"` and `analyzed_at="2026-05-28T10:00:00Z"`
- **WHEN** the contract test decodes the body through `QameraApiClient::getProduct`
- **THEN** the resulting `ProductDetailResponse.images[0]` has `analysisStatus="described"` and `analyzedAt="2026-05-28T10:00:00Z"`
