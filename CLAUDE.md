# CLAUDE.md

PrestaShop module that talks to the Qamera AI Plugin API (`https://qamera.ai/api/v1/plugin`). Separate repo from `qamera-ai/saas-platform` — different language, different release cadence, different deploy.

## Stack constraints (non-negotiable)

- **PHP 8.1+** (lock in `composer.json` `platform.php`). Every PHP file MUST start `<?php` + `declare(strict_types=1);`.
- **PrestaShop 8.0+ — 9.x** compatibility. Avoid PS-9-only APIs unless guarded.
- **PSR-12** enforced by `phpcs.xml.dist`. PSR-4 autoload namespace `QameraAi\Module\` → `src/`.
- **PHPStan level 5**. `src/Install/*` is excluded (heavy PS globals); restore coverage there only with `php-stubs/prestashop-stubs` as dev dep.
- **No Node, no TypeScript, no React.** Reflex from work in adjacent saas-platform monorepo — do not import its patterns.

## Workflow

- **OpenSpec spec-driven from day 1.** Any non-trivial change starts with `/opsx:new <kebab-name>`. Implementation only happens after proposal + design + specs + tasks are committed.
- **Branch per change.** `add-foo`, `fix-bar`. Direct commits to `main` are reserved for chore tweaks (renumber, lint).
- **CI on every push** — PHPCS + PHPStan + PHPUnit on PHP 8.1/8.2/8.3. Red CI blocks merge.
- **Composer install BEFORE `make up`** — PS installer scans modules/ on first boot and tries to load `QameraAi\Module\…`; without `vendor/`, the container exits 1. Run `composer install` in this directory before triggering `make up` from the parent `qameraai-prestashop/` shell.
- **Dev environment lives in the parent shell**, not in this repo. `qameraai-prestashop/` (parent of `modules/qameraai/`) owns `docker-compose.yml` + `Makefile`. Do NOT reintroduce a `docker/` directory here — duplicates risk port collisions (parent binds `qameraai-ps` on 8080).

## Credentials for smoke testing

Production Qamera AI install bound to the `pracownia-qamery-ai` account.

- **API base:** `https://qamera.ai/api/v1/plugin`
- **Installation id:** look it up at runtime from the Qamera panel (`/home/pracownia-qamery-ai/settings/plugin-installations`) or read it back from the live `/me` response after the module is configured. The UUID is per-install state, not a constant — do NOT hardcode it here, in tests, or in fixtures.
- **API key + webhook HMAC secret:** rotate from the Qamera AI panel (`/home/pracownia-qamery-ai/settings/api-keys` and `/home/pracownia-qamery-ai/settings/plugin-installations/<id>`), then paste into the module's Back Office Configuration page (`QAMERAAI_API_KEY`, `QAMERAAI_WEBHOOK_SECRET`). These values must never live in source files, fixtures, or this document — the BO Configuration store is the only legitimate home.

Historical note: prior versions of this file embedded live API key + HMAC values inline. Those credentials were rotated on 2026-05-27; the literal strings remain in git history (commits `56fbd80`, `976ff12`) but are dead and have no upstream effect.

<important if="touching configuration controller">
Secrets MUST be masked on render and skip-persisted on submit when the field still starts with the masking prefix. The Phase 1 spec (`prestashop-module-bootstrap`, Requirement "Secrets never leave the server in cleartext on render") is load-bearing — breaking it is a security regression, not a refactor.
</important>

## Out of scope (v1) — do not implement unprompted

Decisions made deliberately, with explicit `OQ-PS*` markers in `docs/decisions/` upstream:

- Multistore per-shop API key — global key per install only (v2 follow-up).
- Webhook secret rotation initiated from PS UI — Qamera panel only, PS surfaces read-only.
- Marketplace styles browser embedded in PS — dropdown list from `/plugin/presets` is the v1 affordance.
- Front-office display of generated assets — default PS product images flow handles it.

If a task asks for any of the above, surface the OQ-PS marker before implementing and confirm with the user.

## Safety + agent boundaries

- **No `docker compose down -v`** (drops volumes, kills any in-progress install) without an explicit request.
- **No editing `vendor/`, `composer.lock`** by hand. `composer require` / `composer update` only.
- **No live calls against `https://qamera.ai/api/v1/plugin` in CI.** Unit tests use Guzzle `MockHandler`. Smoke is operator-driven, local-or-staging only.
- **Never bypass `is_account_owner` on the Qamera side.** If a server response surfaces a permission error, surface it back; do not work around by switching credentials.

## Things you do NOT need to write here

The agent discovers these via `glob` / `grep` / `Read` faster than you can summarise them:

- File layout under `src/` — read `composer.json` autoload + `ls src/` for the live picture.
- Available endpoints — `find apps/web/app/api/v1/plugin -name "route.ts"` upstream or `openspec/specs/qamera-api-client/spec.md` here.
- Phase plan — `README.md` "Phase plan" section is authoritative.
- Architecture decisions — `docs/decisions/prestashop-plugin-{design,repo-bootstrap}.md` in the **upstream** `qamera-ai/saas-platform` repo, not here.
