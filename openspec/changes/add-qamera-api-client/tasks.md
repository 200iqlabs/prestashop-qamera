# Implementation tasks — add-qamera-api-client

## 1. Branch + deps

- [x] 1.1. Branch `add-qamera-api-client` off latest `main`
- [ ] 1.2. `composer require --dev php-stubs/prestashop-stubs` to unblock PHPStan on `src/Controller/Admin`
- [ ] 1.3. Remove `src/Controller/Admin/*` from `phpstan.neon` `excludePaths`; keep `src/Install/*` excluded

## 2. Exception hierarchy (`src/Api/Exception/`)

- [ ] 2.1. Abstract `ApiException` extending `\RuntimeException` with `getEnvelope(): ?ErrorEnvelope`, `getStatusCode(): ?int`, `getCorrelationId(): ?string`
- [ ] 2.2. `TransportException` (network-level, no envelope)
- [ ] 2.3. `AuthException` (401/403)
- [ ] 2.4. `NotFoundException` (404)
- [ ] 2.5. `ValidationException` (400/409/422) + static `malformedResponse(string $missingField)` factory
- [ ] 2.6. `RateLimitException` (429) with `getRetryAfter(): int`
- [ ] 2.7. `ServerException` (5xx after retries exhausted)
- [ ] 2.8. `ErrorEnvelope` value object — `code`, `messageI18n` (assoc array), `retryable`, `docUrl`

## 3. Internal helpers (`src/Api/Internal/`)

- [ ] 3.1. `HeaderBuilder` — composes `X-Api-Key`, `User-Agent` (`QameraAi-PrestaShop-Module/<version> (<ps-version>)`), `Accept-Language` (from PS context with `en` fallback), `Accept: application/json`
- [ ] 3.2. `IdempotencyKeyGenerator` — wraps `ramsey/uuid` `Uuid::uuid7()->toString()`; constructor-injected so tests can stub
- [ ] 3.3. `RetryDecider` — `shouldRetry(int $retries, RequestInterface, ?ResponseInterface, ?Throwable): bool` + `delayMs(int $retries, ?ResponseInterface): int` (honours `Retry-After`, clamps at 60 s)
- [ ] 3.4. `ErrorEnvelopeParser` — `parse(ResponseInterface): ?ErrorEnvelope`; tolerates non-JSON / missing-field bodies returning `null`
- [ ] 3.5. `JsonDecoder` — `decode<T>(class-string<T>, array): T` via constructor reflection; throws `ValidationException::malformedResponse` on missing required field

## 4. DTOs (`src/Api/Dto/`)

- [ ] 4.1. `MeResponse` + nested `InstallationInfo`, `DataProcessor`
- [ ] 4.2. `AiModel`, `Scenery`, `Preset`, `AspectRatio`, `Pricing`
- [ ] 4.3. `RegisterImageRequest`, `ImageResponse`
- [ ] 4.4. `RegisterPackshotRequest`, `PackshotResponse`
- [ ] 4.5. `PresignedUploadResponse`
- [ ] 4.6. `SubmitJobRequest`, `JobResponse`, `JobsListFilters`, `JobsListResponse`
- [ ] 4.7. `ProductResponse`, `ProductsListFilters`, `ProductsListResponse`

## 5. Client (`src/Api/QameraApiClient.php`)

- [ ] 5.1. Constructor accepts `string $baseUrl`, `string $apiKey`, optional `HandlerStack` (DI factory wires Guzzle + middleware stack from the helpers in §3)
- [ ] 5.2. Private `send<T>(string $method, string $path, ?array $body, ?class-string<T>): T` — composes URL, attaches body, runs through middleware, decodes via `JsonDecoder`
- [ ] 5.3. Endpoint methods exactly as in spec Requirement "Endpoint methods are strongly typed"
- [ ] 5.4. Idempotency-Key generation is wired in `send` for `POST /jobs|/images|/packshots` only (not GET, not other POSTs)

## 6. DI wiring

- [ ] 6.1. `config/services.yml` — `QameraAi\Module\Api\QameraApiClient` factory binding pulling `QAMERAAI_API_BASE_URL` + `QAMERAAI_API_KEY` from `Configuration`
- [ ] 6.2. Verify autowire picks up `HeaderBuilder` + `RetryDecider` + `ErrorEnvelopeParser` + `IdempotencyKeyGenerator`

## 7. Test connection admin route

- [ ] 7.1. `_qameraai_admin_test_connection` route in `config/routes.yml` (POST, CSRF token required)
- [ ] 7.2. `TestConnectionController::indexAction(Request): JsonResponse` calls `QameraApiClient::me()` and returns `{ok: true, account_name, credits_balance, subscription_plan, installation: {platform, status}}` on success or `{ok: false, message, code}` on caught `ApiException`
- [ ] 7.3. Inline JS in `configuration.html.twig` — disables the button while in-flight, renders the response in the results panel, NO full-page reload, NO save-form submit
- [ ] 7.4. Enable the Test Connection button (drop the Phase 1 `disabled` attribute)

## 8. i18n

- [ ] 8.1. EN XLIFF: `apiClient.testConnection.success`, `.failure`, `.network`, `.fields.{accountName,credits,plan,platform,status}`
- [ ] 8.2. PL XLIFF (formal tone matching Phase 1 voice)
- [ ] 8.3. UK XLIFF

## 9. Tests

- [ ] 9.1. `tests/Unit/Api/RetryDeciderTest.php` — every branch of the retry decision matrix (transient 502/503/504/429, non-retryable 4xx, `ConnectException`, attempt cap)
- [ ] 9.2. `tests/Unit/Api/HeaderBuilderTest.php` — `X-Api-Key` presence, `User-Agent` regex, `Accept-Language` from PS context with `en` fallback
- [ ] 9.3. `tests/Unit/Api/QameraApiClientTest.php` — Guzzle `MockHandler` covering:
  - happy path on `me()`
  - 503-then-200 retry success with idempotency key stability across attempts
  - 4-attempt cap raises `ServerException` with parsed envelope
  - 429 with `Retry-After` honoured (mock clock injectable in `RetryDecider`)
  - each status-to-exception mapping (401→Auth, 404→NotFound, 422→Validation, 429→RateLimit)
  - `ConnectException` raises `TransportException`
  - GET methods carry no `Idempotency-Key`, write methods do
- [ ] 9.4. `tests/Unit/Api/JsonDecoderTest.php` — happy path, unknown-field tolerance, missing-field raises `ValidationException::malformedResponse`
- [ ] 9.5. `tests/Unit/Api/ErrorEnvelopeParserTest.php` — well-formed envelope, malformed body, empty body

## 10. Verification

- [ ] 10.1. `vendor/bin/phpcs --standard=PSR12 src/ tests/` clean
- [ ] 10.2. `vendor/bin/phpstan analyse src/ --level=5` clean (with `src/Controller/Admin/*` back in scope)
- [ ] 10.3. `vendor/bin/phpunit` all green
- [ ] 10.4. CI matrix green on PHP 8.1 / 8.2 / 8.3
- [ ] 10.5. Manual smoke: Docker PS9 up, configuration page → paste `mk_live_5d21e5b26d221297.AbGQs3qIHgg8IEEetZHMWwX17AR6xpEmeRnjtdkd+ds=` → Test Connection → results panel shows `account_name=Pracownia Qamery AI`, `installation.platform=prestashop`

## 11. PR + merge

- [ ] 11.1. PR against `main` linking this OpenSpec change
- [ ] 11.2. Address review (Copilot + manual)
- [ ] 11.3. Merge after green CI + smoke

## 12. Archive

- [ ] 12.1. `openspec archive add-qamera-api-client` to roll deltas into `openspec/specs/qamera-api-client/spec.md` and updated `prestashop-module-bootstrap/spec.md`
