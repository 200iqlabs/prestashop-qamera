## Context

The Qamera AI Plugin API is HTTP/JSON, gated by `X-Api-Key`, with a single uniform error envelope (`{ error: { code, message_i18n, retryable, doc_url } }`) documented in the upstream `error-codes.ts`. The server enforces per-key rate limits (default 60 req/min, returns HTTP 429 + `Retry-After`), runs `idempotency-key` deduplication on the three write endpoints (`/jobs`, `/images`, `/packshots`), and returns standard 5xx envelopes on transient failures.

On the PrestaShop side, every feature in Phase 2–4 (per-product sync, packshot register, session submission, webhook handler, CLI bulk) needs to call this API. Without a client, each feature reinvents auth, retry, error decoding, and DTO shapes — guaranteed inconsistency and duplicated tests. The client is also where we centralise `User-Agent`, locale negotiation, and the connection-pool defaults so an account-wide misbehaviour is fixable in one place.

PHP 8.1 is the floor (matches the `composer.json` `platform.php` lock). Guzzle 7 is already a Phase 1 runtime dep.

## Goals / Non-Goals

**Goals**

- One typed method per consumed endpoint; impossible to call an endpoint without the request DTO and impossible to receive an untyped response.
- Every transient failure retried within a bounded budget; every non-transient failure mapped to a typed exception whose subclass tells the caller whether the operation can be repeated.
- A "Test connection" affordance on the configuration page that runs end-to-end against production with the saved credentials and reports the result in plain language.
- Unit tests cover the retry decision matrix, header construction, and error envelope decoding without any live HTTP.

**Non-Goals**

- No persistent client-side cache for catalog responses. The caller decides what to cache and where (Phase 3 will likely cache `/ai-models` for the lifetime of a session edit, but that lives in `ProductSyncService`, not here).
- No streaming, no chunked uploads. Asset upload uses the presigned URL flow — the client returns the presigned URL and the caller `PUT`s the file directly to storage.
- No webhook handling. Inbound `/module/qameraai/webhook` lives in `WebhookHandlerService` — separate change.
- No CLI exposure. CLI commands (Phase 4) call the same service layer that wraps the client; the client itself is interface-agnostic.

## Decisions

### D1. Read configuration once at construction; do not re-poll `Configuration`

**Decision.** `QameraApiClient` is instantiated by the Symfony DI container per request with `$_REQUEST`-time values of `QAMERAAI_API_BASE_URL` + `QAMERAAI_API_KEY`. The constructor stores them and never re-reads `Configuration`.

**Why.** Requests are short-lived in PS; mid-request credential rotation is impossible. Reading `Configuration` once avoids a hot path through the PS `Configuration::get` static which hits the DB-backed cache on every call.

**Alternative considered.** Pass `Configuration` as a dependency and read per-call. Rejected — adds coupling for no benefit, and makes the client harder to unit-test (you would need to mock the PS class).

### D2. Guzzle's `HandlerStack` carries retry + auth + UA middleware; the client only knows how to compose URL paths and decode responses

**Decision.** The `HandlerStack` is built once per client:

1. `Middleware::mapRequest` adds `X-Api-Key`, `User-Agent`, `Accept-Language`, and (on POSTs to `/jobs`, `/images`, `/packshots`) `Idempotency-Key`.
2. `Middleware::retry` uses `RetryDecider::shouldRetry($retries, $request, $response, $exception)` with exponential backoff `250 * 2^retries` ms capped at 4 attempts. On `Retry-After` (HTTP 429), honour the header value (clamped at 60 s).
3. `Middleware::mapResponse` parses the body once if 2xx; on non-2xx, throws — Guzzle's `http_errors` middleware is replaced by our own so the error envelope is decoded before it bubbles.

**Why.** Keeps the client thin: each endpoint method is `$this->json('GET', '/me', null, MeResponse::class)`. All the cross-cutting policy lives in middleware, isolated and individually testable.

**Alternative considered.** Subclass `GuzzleHttp\Client` and override `send`. Rejected — fights the framework; middleware is the supported extension point.

### D3. Typed DTOs as `readonly` classes with named-arg constructors, decoded via a single `JsonDecoder::decode` helper

**Decision.** Every request/response shape is a `final readonly class` in `src/Api/Dto/`. Decoding is centralised in `JsonDecoder::decode(string $class, array $payload): object` which uses constructor parameter reflection. Unknown server-side fields are ignored (forward compatibility); missing required fields throw `ValidationException::malformedResponse(...)`.

**Why.** Static typing across the boundary. `RegisterImageRequest` cannot accidentally be passed where `RegisterPackshotRequest` is expected. `readonly` prevents downstream code from mutating a response and forgetting to refetch.

**Alternative considered.** Untyped associative arrays. Rejected — defeats the point of having a client; every consumer reinvents shape validation.

### D4. Retry policy — only on transient classes, exponential 250 ms × 2^n, cap 4 attempts

**Decision.** `RetryDecider::shouldRetry` returns `true` only for:

- `ConnectException` (network-level)
- HTTP `502`, `503`, `504`
- HTTP `429` (honour `Retry-After` instead of pure exponential)

Other 4xx and the body shape `error.retryable === false` (when present) are never retried.

**Why.** The `error.retryable` flag is the server's declaration. We listen to it. Mirroring the server's contract means a future change to retry behaviour on a specific code requires no client edit.

**Alternative considered.** Retry every 5xx + idempotent verbs. Rejected — `POST /jobs` is the most common write and is exactly the place where double-submission risk is real; we lean on the server's idempotency-key dedup rather than blind verb-based retry.

### D5. Idempotency keys generated client-side via `ramsey/uuid` v7, per-request

**Decision.** Every POST to `/jobs`, `/images`, `/packshots` carries `Idempotency-Key: <uuid7>`. The key is generated once per logical request and reused across all retry attempts of that request — so the retry middleware MUST capture the key in the middleware stack, not regenerate it on retry.

**Why.** UUIDv7 is timestamp-sortable (good for server-side dedup-cache eviction) and not collision-prone across PrestaShop installations. Reusing the key across retries lets the server's dedup window match retries to the original write.

**Alternative considered.** Hash of request body. Rejected — body may include image bytes (huge), and hashing per retry is wasted CPU. UUIDv7 is constant-cost.

### D6. Exception hierarchy — one type per actionable bucket

**Decision.** `ApiException` is the abstract base; subclasses:

- `TransportException` — connection-level, no HTTP response (network down, DNS fail, TLS)
- `AuthException` — 401, 403; caller likely cannot recover without ops action
- `NotFoundException` — 404; resource genuinely not there
- `ValidationException` — 400, 409, 422; carries `code` + `message_i18n`, caller has the info to render to the operator
- `RateLimitException` — 429; carries `retryAfter` so the caller can decide to surface "try again in X seconds" or queue
- `ServerException` — 5xx after retries exhausted; carries `code` + `correlation_id` from response headers if present

Every subclass exposes `getEnvelope(): ?ErrorEnvelope` returning the parsed body when one existed.

**Why.** `catch (RateLimitException $e)` is more readable than `catch (ApiException $e) if ($e->getStatusCode() === 429)`. Each subclass corresponds to a distinct caller decision tree.

### D7. `Test connection` is a separate admin action posting to a dedicated route

**Decision.** A new admin route `_qameraai_admin_test_connection` (POST only, CSRF-protected by Symfony's default form token) calls `QameraApiClient::me()` and returns a JSON envelope rendered into a panel below the save form. The button uses a small inline JS handler — no full form submit, no page reload — so the saved credentials never round-trip through the form's masked-secret submit path.

**Why.** Mixing "save settings" and "test connection" into one POST is the recipe for accidentally overwriting one with the other (the user clicks Test, sees a mistake, fixes the API key, clicks Save — but Test already wrote stale state). Keeping them in separate routes makes the intent explicit and the audit trail clean.

**Alternative considered.** Live-validate the field on blur. Rejected — too many round-trips while the user is typing; explicit button is friendlier.

### D8. Adopt official `prestashop/php-dev-tools` PHPStan setup; PHPStan re-includes `src/Controller/Admin`

**Decision.** Phase 1 had to exclude `src/Controller/Admin/*` from PHPStan because `FrameworkBundleAdminController` is not Composer-autoloaded. The PrestaShop project does NOT ship a `php-stubs/prestashop-stubs` package (an earlier draft of this design assumed one existed — it does not, on Packagist or elsewhere). The official upstream recommendation is `prestashop/php-dev-tools` (already in our `require-dev`) combined with `ps-module-extension.neon` and a `_PS_ROOT_DIR_` env var pointing at a real PrestaShop source checkout — the analyser loads the actual core classes, not stubs.

We adopt this approach: configuration moves to `tests/phpstan/phpstan.neon` (canonical location per `php-dev-tools` template) which includes `vendor/prestashop/php-dev-tools/phpstan/ps-module-extension.neon`. CI clones PrestaShop source once per matrix job (cached via `actions/cache` on the `_PS_VERSION` lock) and exports `_PS_ROOT_DIR_` before running PHPStan. `src/Install/*` stays excluded for now — heavy use of globals (`Db`, `_DB_PREFIX_`, `Tab`, `Configuration`) is still surfaced as undefined-method warnings even with the core loaded, because the install code touches private internals. Restoring it is a follow-up.

**Why.** The controller is where the API client actually fires from. Keeping it static-analysed catches mistakes a unit test would not. Adopting the upstream setup means we inherit PS-version-specific guarantees (e.g., the `FrameworkBundleAdminController` deprecation in PS 9.0) without maintaining a parallel stub set ourselves.

**Alternative considered.** Hand-rolled minimal stubs in `tests/phpstan/stubs/`. Rejected — would diverge from upstream at every PS major version bump, and we already pay for `prestashop/php-dev-tools` for the coding-standards CLI.

**Trade-off accepted.** CI matrix adds ~30–60 s per PHP-version job for the PrestaShop source clone (cached after the first run). Worth it for the level-5 coverage on the controller layer.

**Local dev note.** Running `composer analyse` locally requires `_PS_ROOT_DIR_` to point at a PrestaShop source tree (e.g., the parent shell's Docker bind mount). Without it, fall back to `vendor/bin/phpstan analyse src/Api src/Service src/Repository --level=5` which exercises the pure-PHP layer and needs no stubs.

## Risks / Trade-offs

- **[Risk] Retry storm under partial outage.** If Qamera AI returns 503 for a sustained period, every retry attempt costs us request budget. → **Mitigation:** 4-attempt cap × 250 ms × 2^n max delay ≈ 4 s total; combined with the rate-limit headers from the server we are well inside the per-key budget. The CLI bulk path in Phase 4 will add an outer circuit breaker, not relevant here.
- **[Risk] Idempotency-key reuse across distinct logical operations.** If a caller calls `submitJob(...)` twice with intentionally different payloads but the client reused the same key, the second call would be a no-op. → **Mitigation:** the key is generated inside `submitJob`/`registerImage`/`registerPackshot` themselves, never passed in. Callers cannot influence it. Tests assert this.
- **[Risk] DTO drift from server.** Adding a required field on the server breaks our decode. → **Mitigation:** unknown fields are silently ignored; missing required fields throw `ValidationException::malformedResponse(...)` which surfaces clearly in the admin "Test connection" panel and in CLI output. Phase 4 adds a `bin/console qamera:test-connection` command that flags this proactively.
- **[Trade-off] Guzzle vs Symfony HttpClient.** Guzzle is the more common pick in PS-module ecosystem and we already require it in Phase 1. Symfony HttpClient would integrate with Symfony's Profiler more cleanly but adds a dep with no obvious upside.
- **[Trade-off] Hand-rolled DTOs vs `symfony/serializer`.** Hand-rolled stays cheap and predictable for the < 20 shapes we have. Symfony/serializer would add abstraction (and dependency weight) for no measurable gain at this scale.

## Migration Plan

- **Deploy:** new files only, no DB changes, no schema migrations. PHPStan reintroduces `src/Controller/Admin` to analysis — surfaced issues fail CI, which is the desired behaviour.
- **Rollback:** revert the PR. Phase 1 install lifecycle remains intact (the new code is purely additive).
- **Smoke after merge:** click "Test connection" on the PS9 dev environment pointing at `https://qamera.ai/api/v1/plugin` with the stored `mk_live_…` key; expect the `/me` panel to render `account_name=Pracownia Qamery AI`, `credits_balance=N`, `subscription_plan=active`, `installation.platform=prestashop`.

## Open Questions

- Do we want a structured access log for every API call (file-based or stderr) from the start, or wait until Phase 4 surfaces a real diagnostic need? Leaning towards "wait" — Guzzle's middleware makes it trivial to add later, and shipping unlogged code is a smaller surface to maintain.
- Locale negotiation for `Accept-Language` — should we use `Context::getContext()->language->iso_code` (PS-side current locale) or the merchant's stored "default language for Qamera responses" preference (which we do not have yet)? Default to PS-side for v1; introduce a configuration knob only if a merchant asks.
