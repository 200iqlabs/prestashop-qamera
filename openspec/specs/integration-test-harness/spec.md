## Purpose

Defines the contract for the PS9-kernel-booted integration test tier under tests/Integration/: how the kernel is bootstrapped, how HTTP transport vs PS internals are layered, how fixtures are isolated per test, and what regression coverage the suite is obligated to retain.

## Requirements

### Requirement: Integration test bootstrap initializes a real PrestaShop kernel equivalent to a back-office HTTP request

The integration test harness SHALL boot a real PrestaShop 9 kernel before any integration test executes, exposing the same set of PrestaShop globals that a back-office HTTP request would initialize. After bootstrap completes, production code paths under `QameraAi\Module\*` SHALL run unchanged against real PrestaShop internals — there is no integration-only branch or fake. The bootstrap SHALL be idempotent: invoking it twice in the same process MUST be a no-op rather than a re-boot, so ad-hoc scripts and PHPUnit can share the same entry point.

The harness MUST NOT depend on environment-conditional logic at the call site (i.e. tests do not need to check `if (kernel_booted)` themselves) — the bootstrap is responsible for being safe to call repeatedly.

#### Scenario: Bootstrap exposes the minimum global surface needed by production code

- **GIVEN** the integration harness bootstrap has run for the current PHPUnit process
- **WHEN** a test resolves any of `Db::getInstance()`, `Context::getContext()->shop`, `Configuration::get('QAMERAAI_API_BASE_URL', ...)`, or the constants `_PS_PRODUCT_IMG_DIR_` / `_PS_IMG_DIR_`
- **THEN** each call SHALL return the real value backed by the dev container (not a stub or null) — equivalent to what the same call returns inside a real back-office controller invocation
- **AND** `Module::getInstanceByName('qameraai')` SHALL return the live module instance with its Symfony container resolvable via `SymfonyContainer::getInstance()`

#### Scenario: Bootstrap is idempotent across repeated invocation

- **GIVEN** the bootstrap has already run once in the current process
- **WHEN** the bootstrap is invoked a second time (e.g. an ad-hoc script `require`s it after PHPUnit already did)
- **THEN** the second call SHALL be a no-op — the kernel is NOT re-booted, the container is NOT re-instantiated, and no global state is reset

### Requirement: Test tier discipline separates HTTP transport (stubbed) from PrestaShop internals (real in integration, stubbed in unit)

The harness SHALL enforce a strict layering rule: HTTP transport — the Guzzle client wired into `QameraApiClient` and `qameraai.upload_http_client` — is the ONLY dependency that integration tests MAY stub. All other PrestaShop core classes (`Db`, `Image`, `Product`, `Configuration`, `Context`, `Shop`, etc.) MUST be the real classes loaded by the booted kernel. Inversely, unit tests MUST stub every PrestaShop core class (via `tests/Stubs/PrestaShopStubs.php`) and MUST NOT depend on a real kernel — preserving unit-tier speed and hermeticity.

A test that stubs `Db` while living in `tests/Integration/` violates the harness contract; a test that requires a real `Db` while living in `tests/Unit/` likewise violates it. The decision of which tier a new test belongs in is driven by what it needs: stub-only → unit, real PS → integration.

#### Scenario: Integration test that stubs Db is a spec violation

- **GIVEN** a test file under `tests/Integration/`
- **WHEN** the test class calls `$this->createMock(Db::class)` or otherwise substitutes the `Db` instance with a test double
- **THEN** the test violates the harness contract — code review MUST reject the change OR move the test to `tests/Unit/`
- **AND** the spec rationale is that integration tier exists precisely to exercise real `Db` semantics (e.g. the auto-appended `LIMIT 1` that `Db::getRow()` adds — see the smoke regression scenarios in the suite execution requirement below)

#### Scenario: HTTP transport stubbing via Guzzle MockHandler is the supported pattern

- **GIVEN** an integration test that needs to assert on the request body sent to upstream `POST /images`
- **WHEN** the test rebinds `QameraAi\Module\Api\QameraApiClient` in the container with a constructor that wraps a `GuzzleHttp\Client(['handler' => HandlerStack::create($mockHandler)])`
- **THEN** the rebind is the supported, spec-blessed pattern for transport substitution
- **AND** production code that resolves `QameraApiClient::class` from the container automatically picks up the test double for the duration of the test

### Requirement: Accidental live HTTP traffic to qamera.ai SHALL fail loudly, not silently

The integration harness SHALL configure the booted kernel such that any HTTP request that escapes Guzzle mock substitution and reaches a real network address fails immediately with a transport error — never reaching `qamera.ai` and never succeeding with credentials that may be present in the dev container's `ps_configuration`. The mechanism is bootstrap-level override of `QAMERAAI_API_BASE_URL` (in `ps_configuration`) to the reserved invalid TLD `http://qamera-test.invalid` (RFC 2606 reserves `.invalid` for guaranteed non-resolution). A forgotten container rebind in a new test therefore surfaces as `ConnectException` ("could not resolve host"), not as a successful production hit.

The override SHALL be applied at bootstrap time, before any test runs, and SHALL be restored to the production-time value on harness teardown (suite end). Per-test reads of `Configuration::get('QAMERAAI_API_BASE_URL')` SHALL observe the invalid URL.

#### Scenario: Test forgets to rebind QameraApiClient and accidentally constructs a real one

- **GIVEN** the harness has run bootstrap and overridden `QAMERAAI_API_BASE_URL` to `http://qamera-test.invalid`
- **WHEN** a test resolves `QameraApiClient` from the container without first rebinding it, and the production code path issues an HTTP request via that client
- **THEN** Guzzle raises `ConnectException` (DNS resolution fails for `qamera-test.invalid`) within milliseconds
- **AND** no traffic reaches `qamera.ai`; no credentials in `ps_configuration` are exercised against the real upstream

### Requirement: Fixture lifecycle uses a reserved namespace and isolates global state across tests

Every fixture entity that an integration test inserts into the database SHALL be identifiable by a reserved naming prefix that allows deterministic suite-wide cleanup. The harness SHALL reserve the prefix `TEST-` for integration-test fixtures across all PrestaShop tables that carry user-supplied identifiers (`ps_product.reference`, derived `ps_qamera_product_link.qamera_product_ref` entries that descend from a `TEST-`-prefixed product, etc.). No production code path, no seed data, no demo content SHALL produce identifiers with the `TEST-` prefix — the prefix is exclusively the property of the integration harness.

Each test SHALL generate a per-test marker (`bin2hex(random_bytes(4))` or equivalent unique-per-test token) and incorporate it into every fixture it creates, in the form `TEST-{markerId}-{purpose}`. The test's `tearDown` SHALL delete every row tagged with its marker. The harness bootstrap SHALL perform a suite-wide `DELETE` for all `TEST-`-prefixed rows on startup to catch any leak from previously interrupted runs.

Test isolation SHALL extend beyond DB fixtures to all mutable global state — `Configuration::updateValue()` writes, container service rebinds, static state on PS legacy classes, and the in-memory dedup cache on `ProductImageSyncService`. Any mutation a test makes to such state MUST be restored in `tearDown`. Tests MUST NOT depend on execution order; the suite SHALL pass under any permutation, and CI SHOULD enable PHPUnit's `executionOrder="random"` mode to catch order coupling early.

#### Scenario: Per-test marker enables targeted cleanup

- **GIVEN** an integration test that creates `Product` with `reference = 'TEST-{markerId}-001'` and an associated `Image`
- **WHEN** the test completes (pass or fail) and `tearDown` runs
- **THEN** `tearDown` deletes all `ps_product` rows with `reference LIKE 'TEST-{markerId}-%'` and the `ps_qamera_product_link` rows that joined to them, plus any orphan `ps_image` rows whose parent product is now gone
- **AND** no test fixture survives into the next test's setUp

#### Scenario: Suite startup sweeps leftovers from an interrupted prior run

- **GIVEN** a previous PHPUnit run crashed mid-test, leaving products with `reference` like `TEST-abc12345-001` in the dev container's database
- **WHEN** the harness bootstrap runs at the start of a fresh PHPUnit invocation
- **THEN** the bootstrap deletes every row whose identifier matches the reserved `TEST-` prefix across the relevant tables, before any test setUp executes

#### Scenario: Test rebinds a container service and tearDown restores it

- **GIVEN** an integration test that rebinds `QameraApiClient` in the Symfony container during `setUp` (e.g. to inject a `MockHandler` with specific expectations)
- **WHEN** the test completes and `tearDown` runs
- **THEN** `tearDown` restores the original container binding so the next test resolves the unmodified service
- **AND** the same isolation rule applies to `Configuration::updateValue` writes and any mutation to `ProductImageSyncService`'s injected dedup cache

### Requirement: Integration suite executes deterministically with zero skipped tests, and covers regressions for every Phase-3 smoke bug

The PHPUnit integration suite SHALL execute every test to a pass/fail verdict on every run — no test MAY call `$this->markTestSkipped(...)` in steady-state. The harness SHALL configure PHPUnit with `failOnSkipped="true"` so an accidental skip is a CI failure, not a silent pass. A test that genuinely cannot be made deterministic SHALL NOT be merged in the first place; the integration tier is not a graveyard for "might-work" tests.

Beyond the steady-state discipline, the suite SHALL contain at least one regression scenario for every production bug that the Phase-3 operator smoke caught (the three bugs the unit tier could not see because of stub-driven blindness). The specific Phase-3 regression coverage is:

- **PrestaShop Db helper semantics**: a test SHALL exercise `ProductImageSyncService::syncOnImageAdded` end-to-end such that `loadBookkeepingRow` issues its real `SELECT ... FROM ps_qamera_product_link` against the booted MySQL — covering the auto-appended `LIMIT 1` that `Db::getRow()` adds and that mocked unit tests could not see (bug fixed in commit `fd6bca9`).
- **PrestaShop image directory constant**: a test SHALL exercise `resolveImagePath` such that it reads the real `_PS_PRODUCT_IMG_DIR_` constant and resolves to a real file on the dev container's filesystem — covering the typo class of bugs (`_PS_PROD_IMG_DIR_` vs `_PS_PRODUCT_IMG_DIR_`) that unit stubs would not catch (bug fixed in commit `fd6bca9`).
- **Ramsey UUID autoloader fallback**: a test SHALL exercise `IdempotencyKeyGenerator::generate` such that the returned value is a valid UUID — covering the `Uuid::uuid7()` / `Uuid::uuid4()` fallback path against the real autoloader composition (note: this test alone does not reproduce the `ps_checkout`/`ps_accounts` autoloader clash that the smoke caught, because those modules are not loaded in the integration container; the regression test SHALL assert the fallback's existence and correctness, while the cross-module-clash case remains the responsibility of operator smoke — documented in design.md Non-Goals).

Removing or weakening any of these regression scenarios in a future change requires explicit acknowledgment in the change's proposal — not a silent edit.

#### Scenario: Suite fails CI when a test is silently skipped

- **GIVEN** the integration suite has `failOnSkipped="true"` configured
- **WHEN** a test method body begins with `$this->markTestSkipped('TODO')` (a careless commit by a contributor)
- **THEN** PHPUnit exits non-zero and CI marks the integration job as failed
- **AND** the offending test SHALL NOT reach `main`

#### Scenario: Smoke regression — Db::getRow LIMIT 1 duplication

- **GIVEN** a bookkeeping row exists for a `TEST-`-prefixed product in `ps_qamera_product_link`
- **WHEN** an integration test invokes `ProductImageSyncService::syncOnImageAdded` for that product, causing `loadBookkeepingRow` to execute its `SELECT` via real `Db::getRow()`
- **THEN** the query returns the expected row without raising `PrestaShopException("syntax error near 'LIMIT 1'")` — i.e. the query MUST NOT carry an explicit `LIMIT 1` because `Db::getRow()` auto-appends one (this is the contract the smoke surfaced; this test ensures it never regresses)

#### Scenario: Smoke regression — `_PS_PRODUCT_IMG_DIR_` constant resolution

- **GIVEN** a `TEST-`-prefixed product with an attached `Image` whose file lives at the path constructed by `ProductImageSyncService::resolveImagePath`
- **WHEN** the production code reads that path and calls `filesize($localPath)`
- **THEN** `filesize` returns a positive integer (the real file is present at the resolved path) — i.e. `resolveImagePath` MUST reference the correct PrestaShop constant `_PS_PRODUCT_IMG_DIR_` and not a typo variant

#### Scenario: Smoke regression — UUID generator falls back when uuid7 is unavailable

- **GIVEN** the harness has booted with the module's vendor `ramsey/uuid` available
- **WHEN** an integration test calls `IdempotencyKeyGenerator::generate()`
- **THEN** the returned value is a syntactically valid UUID string (matches the canonical 8-4-4-4-12 hex format)
- **AND** the test additionally exercises the fallback branch by inducing a state where `Uuid::uuid7` is unavailable (e.g. via temporary autoload manipulation or by exercising the `method_exists` branch directly) and asserting the call still returns a valid UUID
