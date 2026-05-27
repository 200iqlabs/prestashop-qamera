## Context

The module ships three test tiers today:

- **`unit`** (`tests/Unit/`) — fast, hermetic, every PS dependency stubbed. Runs in CI on every push across PHP 8.1/8.2/8.3.
- **`contract`** (`tests/Contract/`) — Pact-style JSON fixtures asserting upstream API shape. Pure data — no kernel needed.
- **`integration`** (`tests/Integration/`) — declared in `phpunit.xml.dist` but currently **excluded** via `<groups><exclude><group>integration</group></exclude></groups>`. The handful of files there (`tests/Integration/Sync/ProductImageSyncIntegrationTest.php` and siblings) are skeletons that bail in `setUp()` because no PS kernel is available. They were left dormant in Phase 3 explicitly because the bootstrap they need does not exist yet.

Phase 3's smoke caught three bugs (see proposal) that all sat squarely in this gap: real `Db` helper semantics, real PS9 constants, real autoloader composition with sibling modules. The integration tier is the right home — we already have files and a testsuite name. What we need is the kernel bootstrap that lets those files actually do something.

The parent `qameraai-prestashop/` repo already runs PrestaShop 9 + MySQL 8 via `docker-compose.yml`, with the module bind-mounted at `/var/www/html/modules/qameraai`. The smoke flow proved out the kernel-boot incantation in `smoke/test_connection.php` (`AdminKernel::boot()` → expose container to `SymfonyContainer::setInstance()` → shop context). That working pattern is what the integration bootstrap will formalise.

## Goals / Non-Goals

**Goals:**
- A real PS9 kernel is booted before integration tests run, exposing real `Db`, real `_PS_PRODUCT_IMG_DIR_`, real `Module::getInstanceByName('qameraai')` autoloader composition.
- Identical test execution locally (`docker compose exec` against the dev container) and in CI (compose-up + same command) — same bootstrap file, same suite name, same fixtures, no environment-conditional logic in tests.
- All HTTP transport is mocked via Guzzle `MockHandler` regardless of tier — no integration test ever reaches `qamera.ai`. The "real" things the tier exercises are PS internals + module wiring, not upstream.
- Per-test fixture lifecycle that does not depend on DB transactions (legacy PS classes commit mid-call; we tried in Phase 2 and burnt time on it).
- The three skeletons in `tests/Integration/Sync/` become real tests that would have failed on each of the Phase-3 smoke bugs.

**Non-Goals:**
- Replacing or thinning the unit suite. Unit stays the inner loop (~30s in container); integration is the slower outer loop (~2 min in CI).
- Browser / functional tests through the BO admin UI. Out of scope — different tooling (Symfony Panther / Playwright), different value, separate change.
- Live `qamera.ai` calls in any automated tier. Smoke remains operator-driven and gitignored.
- Auto-running integration tests on fork PRs (would require secrets we don't want exposed). Decision deferred — initial CI job runs on internal pushes + same-repo PRs only.
- Replacing the smoke flow. Smoke catches autoloader-clash class of bugs that integration can't (because integration only loads our vendor, not `ps_checkout`/`ps_accounts`). Both tiers serve different blast-radius questions.
- php-scoper or vendor isolation. The `Uuid::uuid7` autoloader clash got a runtime fallback in v1.2.0; integration tests will not regress on it but will not prevent the next analogous clash either. Separate concern.

## Decisions

### 1. Kernel boot strategy: one-shot per PHPUnit process, not per-test

PS kernel boot is ~3-5s (Symfony container compilation + PS legacy bootstrap). Per-test boot would push a 10-test class to ~50s of overhead before any assertion. Booting once in `tests/Integration/bootstrap.php` and reusing across tests in the same process is the only practical option.

Tests SHARE the kernel but reset state per test:
- Shop context (`Shop::setContext`) is re-asserted in each `setUp` — cheap.
- Container services are NOT reset by default; tests that need to override a service (e.g. rebind `QameraApiClient` with `MockHandler`) do so explicitly in `setUp` and restore in `tearDown`.
- The `seen` dedup cache in `ProductImageSyncService` is per-request and per-instance — tests that need a clean cache instantiate a fresh service via the container's `get` (which is shared by default — we may need a test-only factory to force a fresh instance; see Open Questions).

Alternatives considered:
- *Per-test boot* — rejected on cost.
- *Process isolation per test class* (`@runInSeparateProcess`) — PHPUnit's separate-process mode is fragile with the PS legacy autoloader and would multiply the boot cost by class count instead of by test count. Rejected.

### 2. CI execution: reuse the parent docker-compose, not GH service containers

The parent `qameraai-prestashop/docker-compose.yml` already produces a working PS9 + MySQL pair with the module bind-mounted. CI does `docker compose up -d` against the same file, waits for healthy, then runs `docker compose exec prestashop bash -c "cd /var/www/html/modules/qameraai && composer install && vendor/bin/phpunit --testsuite=integration"`. The PHPUnit invocation is byte-identical to what a developer runs locally.

Alternatives considered:
- *GH Actions service containers (`services:` block) with `mysql:8` + `prestashop:9.0-apache`* — would work but reproduces PS install state from scratch (~2 min) inside the runner job, and the host runner's `vendor/bin/phpunit` would need to connect to PS's bundled MySQL via published ports. More moving parts; environment skew with local. Rejected.
- *Dedicated test image baked with PS pre-installed* — fastest CI but biggest maintenance burden (rebuild on every PS bump). Rejected for v1; revisit if CI runtime becomes a pain point.
- *Run PHPUnit on the runner host without booting PS at all, stubbing differently* — defeats the point. Rejected.

Trade-off accepted: CI integration job runtime ~3-4 min (compose up + install + tests). Within budget for a per-PR check.

### 3. Fixture lifecycle: per-test marker + targeted DELETE, no transactions

Each test inserts fixtures (products, images, bookkeeping rows) tagged with a per-test marker — `Product::reference = "TEST-{$markerId}-001"`, where `$markerId = bin2hex(random_bytes(4))` set in `setUp`. `tearDown` runs:

```sql
DELETE pl FROM ps_qamera_product_link pl
  INNER JOIN ps_product p ON p.id_product = pl.id_product
  WHERE p.reference LIKE 'TEST-{$markerId}-%';
DELETE FROM ps_product WHERE reference LIKE 'TEST-{$markerId}-%';
DELETE FROM ps_image WHERE id_product NOT IN (SELECT id_product FROM ps_product);
```

A suite-wide `tests/Integration/cleanup.php` (called from `bootstrap.php` once) also runs a `TEST-%` sweep to catch fixtures left behind by previous interrupted runs. Idempotent — the prefix is stable across runs.

Alternatives considered:
- *Wrap each test in a DB transaction* — `Db::execute` commits autonomously on some code paths; legacy `Product::add()` runs multi-statement inserts that don't compose with userland transactions. Tried in Phase 2 spike; abandoned. Rejected.
- *Truncate ps_qamera_product_link + ps_product before each test* — cleans up TEST-prefixed AND any PS sample products, breaking neighbouring tests. Rejected.
- *Spin up a fresh PS instance per test class via separate compose project* — too slow. Rejected.

### 4. Guzzle mock injection: rebind container service in setUp

`QameraApiClient` is wired in `config/services.yml` with `qameraai.upload_http_client` as the Guzzle client arg. Integration tests:

```php
$mockHandler = new MockHandler([...]);
$mockClient = new \GuzzleHttp\Client(['handler' => HandlerStack::create($mockHandler)]);
$this->container->set(\QameraAi\Module\Api\QameraApiClient::class, new QameraApiClient(
    $mockClient,
    /* ...other deps from container... */
));
```

After the rebind, production code that resolves `QameraApiClient::class` from the container gets the test double automatically.

Alternatives considered:
- *Construct `QameraApiClient` directly, bypass container* — works but means the test isn't exercising the container wiring (which is one of the layers we want to cover). Rejected.
- *Override via Symfony's `kernel.test` env + `services_test.yml`* — too heavy for v1; would require boot-time env-var dance. Rejected.

### 5. Same testsuite, removed exclude — no parallel suite definition

`phpunit.xml.dist` already has `<testsuite name="integration">` pointing at `tests/Integration`. The current `<groups><exclude><group>integration</group></exclude></groups>` blocks it from running by default. We REMOVE the `@group integration` annotations on the skeletons (so they're not auto-excluded) and REMOVE the `<exclude>` block. CI then explicitly runs `vendor/bin/phpunit --testsuite=integration` in the integration job and `vendor/bin/phpunit --testsuite=unit,contract` in the static-analysis job.

Alternatives considered:
- *Keep the `@group integration` and run `--group integration` in CI* — works but couples test selection to annotation discipline. A new test added without the annotation would silently skip the integration job. Rejected.
- *Create a separate `phpunit.integration.xml`* — duplication for no win. Rejected.

## Risks / Trade-offs

- **CI runtime grows by ~3 min per push** → mitigation: integration job runs in parallel with static-analysis matrix, so wall-clock impact on PR feedback is the longer of the two (still <5 min total). Caches Composer + PS source between runs.
- **PHP version drift: container is 8.4, static matrix is 8.1/8.2/8.3** → integration job pins one version (8.3, PS9 default). Static matrix remains the primary cross-version gate. Accept that integration-only bugs specific to 8.1 may slip — they're rare and unit-suite already covers PS-independent 8.1 surface.
- **Per-test DB cleanup leaks if tearDown crashes** → mitigation: suite-wide `TEST-%` sweep in bootstrap catches stragglers. Worst case, accumulated TEST products are visible in the dev container's BO; cheap to clean.
- **Kernel sharing across tests creates ordering coupling** → mitigation: lint rule (or just review attention) that tests MUST NOT mutate global state without restoring it in tearDown. The container rebind pattern (Decision 4) makes this explicit for the most common case.
- **Fork PRs can't run integration job (no secrets)** → mitigation: same-repo PRs cover the contributor cases that matter; fork PRs still get unit + static. Document in CONTRIBUTING when fork PRs land.
- **`ps_checkout` / `ps_accounts` autoloader clashes won't surface in integration** because they're not in the dev container's modules dir → mitigation: smoke still catches them; integration is honest about what it does and doesn't cover. Documented in Non-Goals.

## Migration Plan

Single PR, no flag, no staged rollout:

1. Land `tests/Integration/bootstrap.php` + cleanup helper + fleshed-out skeletons in one commit.
2. Land `phpunit.xml.dist` changes (remove `<exclude>` block) + CI workflow changes (new `integration` job) in the same PR.
3. Land Makefile + README docs in the same PR.
4. Verify locally (`docker compose exec ... vendor/bin/phpunit --testsuite=integration`) and in CI before merging.

Rollback: revert the PR — the integration tier returns to "declared but excluded" and the rest of CI continues to work.

## Open Questions

- **`ProductImageSyncService::seen` cache and container sharing**: PHP services in Symfony container are shared by default. If a test relies on a clean dedup cache, we may need either (a) a test-only `prototype` scope override, or (b) tests that explicitly `unset` the cache via reflection, or (c) factor the cache into a separate injected dependency that tests can swap. Decide during specs.
- **Cache `ps_data` Docker volume across CI runs?** Would shave ~1 min off cold runs (PS install) but introduces a stale-data risk. Default: no cache for v1; revisit if runtime hurts.
- **Should the integration job run on `pull_request_target` for fork PRs?** Trade-off: lets external contributors get full feedback, but `pull_request_target` runs against the base branch's secrets and is a known supply-chain footgun. Default: no — fork PRs get unit + static only.
