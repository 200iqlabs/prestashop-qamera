## Why

Phase 1 left a credential editor and an empty installer. The next move is the thin layer of code that actually talks to the Qamera AI Plugin API so every subsequent feature — product sync, packshot generation, webhook handling, session UI — has a single, tested HTTP boundary instead of re-implementing `X-Api-Key`, retry, and error-envelope decoding piecemeal.

The upstream API is now feature-complete (`/me`, the catalog endpoints, jobs, presigned uploads, webhooks delivery) and the plugin installation row on `pracownia-qamery-ai` is already provisioned for smoke testing — there is no longer any API-side dependency blocking work on the client.

## What Changes

- **`QameraApiClient`** — Guzzle 7 wrapper with one method per endpoint we use, typed return DTOs, and an exception hierarchy. Reads `QAMERAAI_API_BASE_URL` + `QAMERAAI_API_KEY` from `Configuration` once per instance.
- **Auth + headers** — every request carries `X-Api-Key: mk_live_…`, `Accept-Language` from PS context, an `Idempotency-Key` on POSTs that the server is supposed to deduplicate (`/jobs`, `/images`, `/packshots`), and a stable `User-Agent` (`QameraAi-PrestaShop-Module/<module-version> (<ps-version>)`) so server-side logs can attribute traffic.
- **Retry** — exponential backoff (250 ms × 2^n, max 4 attempts) on transient classes only: connection errors, HTTP 502 / 503 / 504, HTTP 429 honouring `Retry-After`. No retry on 4xx other than 429.
- **Error mapping** — every non-2xx response is parsed against the plugin error envelope (`{ error: { code, message_i18n, retryable, doc_url } }`) and surfaced as a typed exception:
  - `RateLimitException` (`429`, exposes `retryAfter` seconds)
  - `AuthException` (`401`, `403`)
  - `NotFoundException` (`404`)
  - `ValidationException` (`400`, `409`, `422`, exposes `code` + `message_i18n`)
  - `ServerException` (`5xx` after retries exhausted)
  - `TransportException` (Guzzle connection-level)
- **Endpoint coverage** — only the methods the rest of Phase 2 needs:
  - `me(): MeResponse` (`GET /me`) — drives the configuration page "Test connection" button
  - `listAiModels()`, `listSceneries()`, `listPresets()`, `listAspectRatios()`, `getPricing()` — catalog dropdowns
  - `registerImage(RegisterImageRequest)`, `registerPackshot(RegisterPackshotRequest)` (`POST /images`, `POST /packshots`)
  - `requestUpload(): PresignedUploadResponse` (`POST /assets/upload`)
  - `submitJob(SubmitJobRequest)` / `getJob(string id)` / `listJobs(...)` for Phase 3
  - `listProducts()`, `getProduct(string idOrRef)`, `deleteProduct(string idOrRef)`
  - Out of scope for this change: `/jobs/batch`, `/installations/{id}/rotate-hmac`, `/webhooks/{id}/replay` (operational only; no plugin-side need yet)
- **Configuration controller wire-up** — the disabled "Test connection" button enables, posts to a new admin route that calls `QameraApiClient::me()` and surfaces `{account_name, credits_balance, subscription_plan, installation.platform, installation.status}` in a results panel under the save form.
- **PHPStan restored on the controller** — adopting the official `prestashop/php-dev-tools` PHPStan setup (config at `tests/phpstan/phpstan.neon` including `ps-module-extension.neon`, CI exports `_PS_ROOT_DIR_` from a cached PS source clone) lets us drop the `excludePaths` rule for `src/Controller/Admin` introduced in the Phase 1 hotfix; `src/Install` stays excluded for now (heavy use of globals). Note: no separate stubs package — `php-stubs/prestashop-stubs` does not exist on Packagist; the upstream-supported path is to load actual PS sources.

## Capabilities

### New Capabilities

- `qamera-api-client`: the contract for how the PrestaShop module talks to the Qamera AI Plugin API — what gets retried, how errors are mapped to typed exceptions, what headers are sent, and which endpoint methods the client exposes.

### Modified Capabilities

- `prestashop-module-bootstrap`: the configuration page's "Test connection" button moves from stubbed (Phase 1) to functional, and the page renders the `/me` response when the connection succeeds.

## Impact

- **Code (new)**
  - `src/Api/QameraApiClient.php`
  - `src/Api/Dto/{MeResponse,AiModel,Scenery,Preset,AspectRatio,Pricing,RegisterImageRequest,RegisterPackshotRequest,SubmitJobRequest,JobResponse,ProductResponse,PresignedUploadResponse}.php`
  - `src/Api/Exception/{ApiException,RateLimitException,AuthException,NotFoundException,ValidationException,ServerException,TransportException}.php`
  - `src/Api/Internal/{HeaderBuilder,RetryDecider,ErrorEnvelopeParser}.php` (small focused helpers)
  - `src/Controller/Admin/TestConnectionController.php` (or extend `ConfigurationController` with a second action — TBD in design)
  - `tests/Unit/Api/` — coverage on retry, error mapping, header construction
- **Code (modified)**
  - `composer.json` — no new deps (Guzzle + ramsey-uuid + prestashop/php-dev-tools already locked); only the `analyse` script repointed at `tests/phpstan/phpstan.neon`
  - `tests/phpstan/phpstan.neon` (NEW) — official `ps-module-extension.neon` include; drops `src/Controller/Admin/*` from analysis exclude; root `phpstan.neon` removed
  - `.github/workflows/ci.yml` — shallow-clone PrestaShop source (cached), export `_PS_ROOT_DIR_` for the PHPStan step
  - `config/services.yml` — explicit binding for `QameraApiClient` so it picks up `Configuration` values
  - `views/templates/admin/configuration.html.twig` — enable the Test Connection button, add a results panel
  - English / Polish / Ukrainian XLIFF — new strings for Test Connection result states
- **Compatibility:** no DB changes, no schema bumps. The Phase 1 install routine is unchanged.
- **Dependencies:** Guzzle 7 already declared in Phase 1 `composer.json`. New dev dep `php-stubs/prestashop-stubs`.
- **External services:** every test against `https://qamera.ai/api/v1/plugin` uses the `mk_live_…` credential bound to the `pracownia-qamery-ai` installation. Unit tests use Guzzle's `MockHandler` — no live HTTP in CI.
- **Docs:** README "Phase plan" gets Phase 2 marked done after merge; a new `docs/api-client.md` snippet describes the exception hierarchy for downstream contributors.
