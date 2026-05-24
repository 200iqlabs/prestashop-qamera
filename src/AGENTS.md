# src/AGENTS.md

Per-layer conventions that live here because they shape *how* code is written, not *what* it does.

## `Api/` — HTTP client to Qamera AI

- **One method per consumed endpoint.** Never expose a generic `request(string $method, string $path, array $body)` — that defeats the typing the rest of the module relies on.
- **Typed DTOs only.** Request and response shapes are `final readonly` classes in `Api/Dto/`. Decoded via `Api/Internal/JsonDecoder`. Unknown server-side fields ignored (forward compat); missing required fields throw `ValidationException::malformedResponse(...)`.
- **Exception hierarchy is contract.** Map every non-2xx to `Transport|Auth|NotFound|Validation|RateLimit|ServerException`. Callers do `catch (RateLimitException $e)`, not `catch (ApiException $e) if ($e->getStatusCode() === 429)`.
- **Idempotency keys are generated inside the client, never accepted from callers.** Reuse the same UUIDv7 across retries of the same logical call. POST `/jobs`, `/images`, `/packshots` only — never on GET or other POSTs.
- **No `Configuration::get(...)` calls inside the client.** Inject `baseUrl` + `apiKey` once at construction (DI factory). The client is unit-testable without PS context.

## `Controller/Admin/` — back-office Symfony controllers

- **Extend `PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController`** — that gives `addFlash`, `redirectToRoute`, `render`, `trans`. PHPStan resolves it because `php-stubs/prestashop-stubs` is a dev dep.
- **Secrets never leave the server in cleartext on render.** Mask as 12 bullets + last 4 chars. Skip-persist on submit when the field starts with the masking prefix. Verify against `prestashop-module-bootstrap` spec scenarios before changing.
- **Test Connection ≠ Save.** Live-validation actions get their own admin route + CSRF token. Mixing them risks accidentally overwriting stored config with stale form values.
- **No business logic in controllers.** Controllers compose service calls, render templates, and translate exceptions to flash messages. Anything else moves to `Service/`.

## `Service/` — orchestration (Phase 2+)

- **Pure constructor injection.** Services accept their dependencies as ctor params; no `Module::getInstance()`, no static lookups. DI container (`config/services.yml`) wires them.
- **One service per feature, not per endpoint.** `ProductSyncService` orchestrates `registerImage` + `registerPackshot` + repository writes; it is not a 1:1 wrapper around the API client.
- **Services raise typed exceptions or return DTOs.** No `array` returns — those rot fast.

## `Repository/` — Doctrine ORM access (Phase 2+)

- **One repository per table** (`ProductLinkRepository`, `PackshotLinkRepository`).
- **Methods named by intent, not by SQL** — `findByQameraProductRef`, `markPackshotReady`. Never `findOneBy(['…'])` leaked to callers.
- **No `Db::getInstance()` in `Repository/`.** The Phase 1 `Installer` uses it for `CREATE TABLE` migrations; runtime data access goes through Doctrine.

## `Install/` — module lifecycle (excluded from PHPStan)

- **Idempotent.** Every `install` step is `IF NOT EXISTS` / `Configuration::get(...) === false` gated so re-install on partial-failure state converges.
- **Uninstall mirrors install.** If you add a hook in `Installer::HOOKS`, the uninstaller drops it automatically. If you add a `Configuration` key to `DEFAULTS`, `purgeConfiguration` removes it. No drift.
- **Schema bumps require `upgrade/upgrade-X.Y.Z.php`** + module version bump in `qameraai.php` constructor. Do NOT mutate the Phase 1 `CREATE TABLE` statements in place after v1.0.0 ships.

## `Hook/` — PS hook handlers (Phase 2+)

- **Thin handlers.** A hook method composes service calls and returns; no inline DB queries, no inline HTTP.
- **Fail open, not closed.** A failing hook MUST NOT block PS's primary flow (product create, product update). Log and swallow; surface to operator via the back-office dashboard, not by aborting the merchant's action.
