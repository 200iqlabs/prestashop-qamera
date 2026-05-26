# Copilot review instructions

PrestaShop module that integrates with the Qamera AI Plugin API (`https://qamera.ai/api/v1/plugin`). Backend-only PHP code — there is intentionally no Node, TypeScript, React, or JSX in this repository. Treat any such files in a diff as a mistake.

When reviewing a pull request, prioritise the rules below in this order: stack, security, scope/OpenSpec, tests. Flag violations explicitly rather than rewording them as suggestions.

## Stack rules (must enforce)

- Every `.php` file MUST start with `<?php` followed by `declare(strict_types=1);`. Flag any new PHP file that omits either.
- PHP 8.1+ syntax only. The platform PHP version is pinned in `composer.json` under `platform.php` — do not propose code that requires a newer minor version (e.g. PHP 8.4-only features).
- PSR-12 coding style and PSR-4 autoload. Production classes live under namespace `QameraAi\Module\` mapped to `src/`. New classes outside that namespace are a red flag unless they are tests (`QameraAi\Module\Tests\` under `tests/`).
- PHPStan level 5 must pass on `src/` except for `src/Install/*`, which is excluded because of heavy PrestaShop globals. Do not silently widen the exclusion list; flag added entries.
- PrestaShop 8.0–9.x compatibility. PS-9-only APIs are only acceptable when guarded by a version check. Flag unguarded usage of APIs that did not exist in PS 8.0.
- No Node, TypeScript, React, JSX, or `package.json`/`package-lock.json` in this repo. Adjacent monorepo patterns do not apply here.
- Do not edit `vendor/` or `composer.lock` by hand — those changes must come from `composer require` / `composer update`.

## Security rules (must enforce)

- Never accept hardcoded API keys, HMAC secrets, installation IDs, or any `mk_live_*` / `mk_test_*` style credentials in source files, tests, fixtures, or documentation. The only legitimate home for these values is the module's configuration store, populated at runtime by an operator.
- In the configuration controller, secret fields (API key, webhook HMAC secret) MUST be masked on render and MUST skip-persist when the submitted value still starts with the masking prefix. Removing or weakening this behaviour is a security regression against the Phase 1 spec, not a refactor — flag it as blocking.
- HMAC and token comparisons MUST use `hash_equals()` (constant-time). Flag any use of `==`/`===` for comparing secrets, signatures, or HMAC digests.
- No logging of API keys, HMAC secrets, or webhook payloads in cleartext. Redact before logging.
- Do not bypass the `is_account_owner` check on responses from the Qamera API. If a permission error comes back, it must be surfaced — not worked around by switching credentials or retrying with elevated context.

## Scope and OpenSpec compliance (must flag)

- Non-trivial changes are expected to correspond to a proposal under `openspec/changes/<kebab-name>/`. If a PR introduces a new feature without a matching change directory, flag it and ask for the OpenSpec link.
- The following are explicitly out of scope for v1. If a PR implements any of them, flag it and require an `OQ-PS*` decision marker in the upstream `docs/decisions/` before approving:
  - Multistore per-shop API key (v1 uses a single global key per install).
  - Webhook secret rotation initiated from the PrestaShop UI (rotation lives on the Qamera panel only).
  - Embedded marketplace styles browser in PS (v1 uses a dropdown sourced from `/plugin/presets`).
  - Front-office display of generated assets (default PS product image flow handles it).
- Direct commits to `main` are reserved for chore tweaks (renumber, lint, dependency bumps). Feature/fix work belongs on branches named `add-*` / `fix-*` opened as PRs.

## Testing rules (must enforce)

- New code paths under `src/` are expected to ship with PHPUnit coverage. Flag substantial logic additions that arrive without tests.
- HTTP traffic to `https://qamera.ai/api/v1/plugin` in tests MUST go through Guzzle `MockHandler`. Live network calls in CI are forbidden — flag any test that constructs a real HTTP client against the Qamera host.
- Production credentials (any `mk_live_*` key, the installation UUID, the HMAC secret) MUST NOT appear in tests, fixtures, snapshots, or CI configuration.
- CI runs PHPCS + PHPStan + PHPUnit on PHP 8.1 / 8.2 / 8.3. A green CI is required for merge — do not approve PRs that disable jobs, skip matrices, or `continue-on-error` around quality gates without an explicit justification in the PR description.
