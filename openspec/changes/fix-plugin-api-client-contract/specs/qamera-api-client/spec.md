## MODIFIED Requirements

### Requirement: requestUpload accepts file metadata and serializes presigned upload body

`QameraApiClient::requestUpload(string $filename, string $contentType, int $sizeBytes): PresignedUploadResponse` SHALL POST to `/assets/upload` a JSON body of shape:

```json
{
  "mode": "presigned",
  "filename": "<string, 1..256 chars>",
  "content_type": "<string, e.g. image/jpeg>",
  "size_bytes": <positive int, ≤ 52428800>
}
```

The method SHALL throw `\InvalidArgumentException` before any HTTP call when `$filename` is empty or > 256 chars, `$contentType` is empty, or `$sizeBytes` ≤ 0 or > 50 MiB — matching the upstream zod constraints so callers see the error locally without burning a round-trip. The response SHALL deserialize into `PresignedUploadResponse` carrying `assetId`, `bucket`, `storagePath`, `?uploadUrl`, `?uploadToken`, `?expiresAt` (the last three nullable because upstream returns null for them when called in multipart mode — even though this client only uses `mode=presigned`, the DTO MUST honestly reflect the server's nullable contract).

#### Scenario: Presigned upload request body matches upstream zod

- **WHEN** `$client->requestUpload('cover.jpg', 'image/jpeg', 12345)` is called
- **THEN** the dispatched HTTP request body is exactly `{"mode":"presigned","filename":"cover.jpg","content_type":"image/jpeg","size_bytes":12345}`

#### Scenario: Oversize filename rejected before HTTP call

- **WHEN** the caller passes a 257-char `$filename`
- **THEN** the method throws `InvalidArgumentException` and dispatches no HTTP request

#### Scenario: Response with non-null upload_url is preserved

- **GIVEN** upstream returns `{"asset_id":"<uuid>","bucket":"plugin_assets","storage_path":"acct/inst/asset/cover.jpg","upload_url":"https://...","upload_token":"<jwt>","expires_at":"2026-05-26T14:00:00Z"}`
- **THEN** the returned DTO has `assetId`, `bucket`, `storagePath`, `uploadUrl`, `uploadToken`, `expiresAt` all populated

#### Scenario: Response with null fields is parsed without error

- **GIVEN** upstream returns the multipart variant `{"asset_id":"<uuid>","bucket":"plugin_assets","storage_path":"acct/inst/asset/cover.jpg","upload_url":null,"upload_token":null,"expires_at":null}`
- **THEN** the returned DTO has `assetId`, `bucket`, `storagePath` populated; `uploadUrl`, `uploadToken`, `expiresAt` are `null`

### Requirement: registerImage wraps single request in upstream bulk envelope and unwraps response

`QameraApiClient::registerImage(RegisterImageRequest $request): ImageResponse` SHALL POST to `/images` a JSON body of shape `{"images": [<RegisterImageRequest->toPayload()>]}` — the upstream endpoint accepts only the bulk wrapper, never a bare single object. The single-in API is preserved at the PHP level for caller ergonomics; the bulk-of-1 wrapper is an implementation detail of the client.

`RegisterImageRequest` SHALL carry the following constructor parameters (in this order):

- `string $externalRef` — caller-supplied stable identifier per `(installation, external_ref)`; required, 1..200 chars; upstream uses it as the idempotency key for the `(installation_id, external_ref)` unique constraint
- `string $productRef` — required, 1..200 chars; serialized as `product_ref`
- `string $assetId` — required, UUID; the `assetId` returned by a prior `requestUpload` call. Serialized as `asset_id`. NOT the legacy `source_url` (which does not exist in the upstream schema)
- `?ProductMetadata $productMetadata = null` — optional; when present, triggers upstream cascade-create of the product row

The method SHALL parse the response as `{"results": [<RegisterImageResult>]}` and extract the first (and only expected) item. The first result SHALL deserialize into `ImageResponse` carrying `externalRef`, `productId` (UUID), `imageId` (UUID), `status` (`'created'` | `'existing'`). If `results` is empty or has more than 1 item, the client SHALL throw `ValidationException::malformedResponse('results[0]')` — the bulk-of-1 contract is non-negotiable.

#### Scenario: Single-image request is wrapped in bulk array

- **WHEN** the caller invokes `registerImage(new RegisterImageRequest('ext-1', 'ps:1:42', '<asset-uuid>'))`
- **THEN** the dispatched body is `{"images":[{"external_ref":"ext-1","product_ref":"ps:1:42","asset_id":"<asset-uuid>"}]}`
- **AND** the request does NOT include `source_url`, `title`, or `product_metadata` keys

#### Scenario: Request with product_metadata cascade-creates upstream

- **WHEN** the caller passes a non-null `ProductMetadata`
- **THEN** the single bulk item carries `product_metadata: {display_name: "...", sku?: "...", description?: "..."}` next to `external_ref`, `product_ref`, `asset_id`

#### Scenario: Response bulk-of-1 unwrapped to ImageResponse

- **GIVEN** upstream returns `{"results":[{"external_ref":"ext-1","product_id":"<prod-uuid>","image_id":"<img-uuid>","status":"created"}]}`
- **THEN** `registerImage` returns an `ImageResponse` with `externalRef='ext-1'`, `productId='<prod-uuid>'`, `imageId='<img-uuid>'`, `status='created'`

#### Scenario: Empty results array surfaces as ValidationException

- **GIVEN** upstream returns `{"results":[]}`
- **THEN** `registerImage` throws `ValidationException` with a message identifying `results[0]` as missing

### Requirement: registerPackshot mirrors registerImage shape

`QameraApiClient::registerPackshot(RegisterPackshotRequest $request): PackshotResponse` SHALL behave symmetrically to `registerImage`: wraps the single request as `{"packshots": [<RegisterPackshotRequest->toPayload()>]}` and unwraps the response from `{"results": [<RegisterPackshotResult>]}`.

`RegisterPackshotRequest` constructor parameters (in this order):

- `string $externalRef` — required, 1..200 chars
- `string $productRef` — required, 1..200 chars
- `string $assetId` — required, UUID; from a prior `requestUpload`
- `?ProductMetadata $productMetadata = null` — optional cascade-create payload
- `?string $sourceImageRef = null` — optional, ≤200 chars; lets the caller reference an upstream image as the source of the packshot when applicable

`PackshotResponse` SHALL carry `externalRef`, `productId`, `packshotId`, `status` (`'created'` | `'existing'`).

#### Scenario: Single-packshot request is wrapped in bulk array

- **WHEN** the caller invokes `registerPackshot(new RegisterPackshotRequest('pk-ext-1', 'ps:1:42', '<asset-uuid>'))`
- **THEN** the dispatched body is `{"packshots":[{"external_ref":"pk-ext-1","product_ref":"ps:1:42","asset_id":"<asset-uuid>"}]}`

#### Scenario: source_image_ref serialized when provided

- **WHEN** the caller passes `sourceImageRef: 'ext-img-1'`
- **THEN** the single bulk item carries `source_image_ref: 'ext-img-1'`

### Requirement: Contract test fixtures snapshot the upstream zod shape

The repository SHALL keep frozen JSON fixtures in `tests/Contract/Fixtures/` capturing the request and response shape of every Plugin API endpoint the client actively calls. Each fixture file SHALL include a header object with `_source` (path inside `qamera-ai/saas-platform`), `_commit` (short git ref of the upstream commit the snapshot reflects), and `_captured_at` (YYYY-MM-DD). The PHPUnit suite `tests/Contract/QameraApiContractTest.php` SHALL load the fixtures and assert that:

- The request body produced by the client matches the example request body in the fixture (deep equality on JSON-decoded structures).
- The response DTO populated by the client from the example response body in the fixture has every field set with the expected values (including `null` where the fixture has `null`).

For this change, fixtures SHALL be present for: `POST /assets/upload`, `POST /images`, `POST /packshots`. Fixtures for other endpoints are not required by this change and are listed as a follow-up in the proposal.

#### Scenario: Fixture missing _source field fails the contract test

- **GIVEN** a fixture JSON file under `tests/Contract/Fixtures/` lacks the `_source` header
- **WHEN** `QameraApiContractTest` runs
- **THEN** the test fails with an assertion message identifying the missing header and the offending file

#### Scenario: Request body mismatch fails the contract test

- **GIVEN** a fixture defines `request.images[0].asset_id` and `RegisterImageRequest` were refactored to send a differently-named field
- **WHEN** `QameraApiContractTest` runs
- **THEN** the test fails with a diff between the captured fixture body and the body the client actually produced
