# qamera-api-client Specification

## Purpose
TBD - created by archiving change add-qamera-api-client. Update Purpose after archive.
## Requirements
### Requirement: Client carries authentication and traceability headers on every request

`QameraApiClient` SHALL attach the following request headers on every outbound call, with no per-call opt-out:

- `X-Api-Key: <api_key>` — the `mk_live_…` value read from `Configuration::get('QAMERAAI_API_KEY')` at construction time
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
- `requestUpload(): PresignedUploadResponse`
- `submitJob(SubmitJobRequest $request): JobResponse`
- `getJob(string $id): JobResponse`
- `listJobs(JobsListFilters $filters): JobsListResponse`
- `listProducts(ProductsListFilters $filters): ProductsListResponse`
- `getProduct(string $idOrRef): ProductResponse`
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

### Requirement: Unit tests assert retry, header, error mapping behaviour without live HTTP

The change SHALL ship Vitest-style colocated unit tests using Guzzle's `MockHandler` for transport. The test suite SHALL cover at minimum:

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

