# Implementation tasks — add-qamera-api-client

## 1. Branch + deps + PHPStan reconfig

- [x] 1.1. Branch `add-qamera-api-client` off latest `main`
- [x] 1.2. Create `tests/phpstan/phpstan.neon` based on `vendor/prestashop/php-dev-tools/templates/phpstan/phpstan.neon` — paths `../../src` (excluding `Install/*`), include `vendor/prestashop/php-dev-tools/phpstan/ps-module-extension.neon`, `level: 5`. (No `php-stubs/prestashop-stubs` — package does not exist; use the official `prestashop/php-dev-tools` setup that loads real PS core via `_PS_ROOT_DIR_`.)
- [x] 1.3. Remove root `phpstan.neon` (or repoint it at the new tests/phpstan one); update `composer.json` `analyse` script to `phpstan analyse --configuration=tests/phpstan/phpstan.neon`
- [x] 1.4. Update `.github/workflows/ci.yml`: shallow-clone PrestaShop source (cached by version), export `_PS_ROOT_DIR_` for the PHPStan step

## 2. Exception hierarchy (`src/Api/Exception/`)

- [x] 2.1. Abstract `ApiException` extending `\RuntimeException` with `getEnvelope(): ?ErrorEnvelope`, `getStatusCode(): ?int`, `getCorrelationId(): ?string`
- [x] 2.2. `TransportException` (network-level, no envelope)
- [x] 2.3. `AuthException` (401/403)
- [x] 2.4. `NotFoundException` (404)
- [x] 2.5. `ValidationException` (400/409/422) + static `malformedResponse(string $missingField)` factory
- [x] 2.6. `RateLimitException` (429) with `getRetryAfter(): int`
- [x] 2.7. `ServerException` (5xx after retries exhausted)
- [x] 2.8. `ErrorEnvelope` value object — `code`, `messageI18n` (assoc array), `retryable`, `docUrl`

## 3. Internal helpers (`src/Api/Internal/`)

- [x] 3.1. `HeaderBuilder` — composes `X-Api-Key`, `User-Agent` (`QameraAi-PrestaShop-Module/<version> (<ps-version>)`), `Accept-Language` (resolved by factory from PS context with `en` fallback), `Accept: application/json`
- [x] 3.2. `IdempotencyKeyGenerator` — wraps `ramsey/uuid` `Uuid::uuid7()->toString()`; non-final so tests can stub
- [x] 3.3. `RetryDecider` — `shouldRetry(int $retries, RequestInterface, ?ResponseInterface, ?Throwable): bool` + `delayMs(int $retries, ?ResponseInterface): int` (honours `Retry-After`, clamps at 60 s). Non-final so tests can override `delayMs` to zero.
- [x] 3.4. `ErrorEnvelopeParser` — `parse(ResponseInterface): ?ErrorEnvelope`; tolerates non-JSON / missing-field bodies returning `null`
- [x] 3.5. `JsonDecoder` — `decode<T>(class-string<T>, array): T` via constructor reflection; throws `ValidationException::malformedResponse` on missing required field; collection fields decoded via `#[ArrayOf(X::class)]` attribute

## 4. DTOs (`src/Api/Dto/`)

- [x] 4.1. `MeResponse` + nested `InstallationInfo`, `DataProcessor`
- [x] 4.2. `AiModel`, `Scenery`, `Preset`, `AspectRatio`, `Pricing`
- [x] 4.3. `RegisterImageRequest`, `ImageResponse`
- [x] 4.4. `RegisterPackshotRequest`, `PackshotResponse`
- [x] 4.5. `PresignedUploadResponse`
- [x] 4.6. `SubmitJobRequest`, `JobResponse`, `JobsListFilters`, `JobsListResponse`
- [x] 4.7. `ProductResponse`, `ProductsListFilters`, `ProductsListResponse`

## 5. Client (`src/Api/QameraApiClient.php`)

- [x] 5.1. Constructor accepts `string $baseUrl`, `HeaderBuilder`, `RetryDecider`, `ErrorEnvelopeParser`, `IdempotencyKeyGenerator`, `JsonDecoder`, and an optional `HandlerStack` (the factory in §6 wires PS state through `QameraApiClientFactory`)
- [x] 5.2. Private `send<T>(string $method, string $path, ?array $body, class-string<T>): T` plus `sendList<T>(string $method, string $path, class-string<T>): T[]` — composes URL, attaches body, runs through middleware, decodes via `JsonDecoder`
- [x] 5.3. Endpoint methods exactly as in spec Requirement "Endpoint methods are strongly typed"
- [x] 5.4. Idempotency-Key generation is wired in `dispatch` for `POST /jobs|/images|/packshots` only (not GET, not other POSTs)

## 6. DI wiring

- [x] 6.1. `config/services.yml` — `QameraAi\Module\Api\QameraApiClient` factory binding via `QameraApiClientFactory::create`, which reads `QAMERAAI_API_BASE_URL` + `QAMERAAI_API_KEY` from `Configuration`
- [x] 6.2. Verify autowire picks up `HeaderBuilder` + `RetryDecider` + `ErrorEnvelopeParser` + `IdempotencyKeyGenerator` (factory injection)

## 7. Test connection admin route

- [x] 7.1. `_qameraai_admin_test_connection` route in `config/routes.yml` (POST only, CSRF token validated via Symfony's `isCsrfTokenValid('qamera_test_connection', $token)`)
- [x] 7.2. `TestConnectionController::indexAction(Request, QameraApiClient): JsonResponse` calls `QameraApiClient::me()` and returns `{ok: true, account_name, credits_balance, subscription_plan, installation: {platform, status}}` on success or `{ok: false, message, code}` on caught `ApiException`
- [x] 7.3. Inline JS in `configuration.html.twig` — disables the button while in-flight, renders the response in the results panel via DOM methods (no innerHTML), NO full-page reload, NO save-form submit
- [x] 7.4. Enable the Test Connection button (drop the Phase 1 `disabled` attribute)

## 8. i18n

- [x] 8.1. EN XLIFF — testConnection.{testing,success,failure,network}, fields.{accountName,credits,plan,platform,status}, errors.{csrf,transport,auth}
- [x] 8.2. PL XLIFF (formal tone matching Phase 1 voice)
- [x] 8.3. UK XLIFF

## 9. Tests

- [x] 9.1. `tests/Unit/Api/Internal/RetryDeciderTest.php` — every branch of the retry decision matrix (transient 502/503/504/429, non-retryable 4xx, `ConnectException`, attempt cap, Retry-After parsing + clamp + zero-fallback)
- [x] 9.2. `tests/Unit/Api/Internal/HeaderBuilderTest.php` — `X-Api-Key` presence, `User-Agent` regex
- [x] 9.3. `tests/Unit/Api/QameraApiClientTest.php` — Guzzle `MockHandler` covering:
  - happy path on `me()`
  - 503-then-200 retry success
  - 4-attempt cap raises `ServerException` with parsed envelope
  - 429 cap exhausted raises `RateLimitException` with `Retry-After` value
  - each status-to-exception mapping (401→Auth, 404→NotFound, 422→Validation, 429→RateLimit)
  - `ConnectException` raises `TransportException`
  - GET methods carry no `Idempotency-Key`, write methods do; idempotency key stable across retries; distinct keys across distinct calls
- [x] 9.4. `tests/Unit/Api/Internal/JsonDecoderTest.php` — happy path (nested + `#[ArrayOf]` collection), unknown-field tolerance, missing-field raises `ValidationException::malformedResponse`
- [x] 9.5. `tests/Unit/Api/Internal/ErrorEnvelopeParserTest.php` — well-formed envelope, malformed body, empty body, missing `error` key, missing `message_i18n`

## 10. Verification

- [x] 10.1. `vendor/bin/phpcs --standard=PSR12 src/ tests/` clean (after `phpcbf` fixed CRLF in 4 pre-existing files)
- [x] 10.2. `vendor/bin/phpstan analyse src/Api/{Dto,Exception,Internal} src/Api/QameraApiClient.php --level=5` clean locally; full `tests/phpstan/phpstan.neon` (incl. controllers + factory) runs only in CI with `_PS_ROOT_DIR_`
- [x] 10.3. `vendor/bin/phpunit` — 41/41 green
- [x] 10.4. CI matrix green on PHP 8.1 / 8.2 / 8.3 (merge run 26368360066, 34 s)
- [ ] 10.5. Manual smoke: PrestaShop 9.x up, configuration page → paste operator-supplied API key (rotated 2026-05-27, no longer in this document) → Test Connection → results panel shows `account_name=Pracownia Qamery AI`, `installation.platform=prestashop`

## 11. PR + merge

- [x] 11.1. PR against `main` linking this OpenSpec change (PR #1)
- [x] 11.2. Address review (Copilot + manual) — adds `MissingConfigurationException`, CI install of PS core composer deps before PHPStan
- [x] 11.3. Merge after green CI + smoke

## 12. Archive

- [x] 12.1. `openspec archive add-qamera-api-client` to roll deltas into `openspec/specs/qamera-api-client/spec.md` and updated `prestashop-module-bootstrap/spec.md`
