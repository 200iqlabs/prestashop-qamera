## Why

A PrestaShop module that integrates with the Qamera AI Plugin API needs an empty but installable shell before any feature work can start: the module class, the install/uninstall lifecycle, the back-office configuration screen, and the DB tables that subsequent phases will write into. Bootstrap is committed as its own change so reviewers can vet the wiring without it being entangled with the first feature PR (`ProductSyncService`).

## What Changes

- **Module shell** — `qameraai.php` (main class), `config.xml`, `composer.json` with PSR-4 autoload (`QameraAi\Module\`), MIT LICENSE, README.
- **Install lifecycle** — `Installer::install` registers four hooks (`actionProductAdd`, `actionProductUpdate`, `displayAdminProductsExtra`, `displayBackOfficeHeader`), creates two DB tables (`ps_qamera_product_link`, `ps_qamera_packshot_link`), seeds five `Configuration` keys with sensible defaults, and installs a hidden `AdminQameraAiConfiguration` tab. `Installer::uninstall` reverses every step.
- **Back-office configuration screen** — Symfony controller + Twig template at route `/qameraai/configuration`. Persists API base URL, API key, webhook secret, auto-register toggle, and sync batch size via `Configuration::updateValue`. Secrets are masked on render so a saved key never leaves the server in cleartext form. A disabled "Test Connection" button stubs the Phase 2 hook.
- **i18n** — XLIFF translations for `Modules.Qameraai.Admin` in EN, PL, UK.
- **Local dev** — Docker Compose files for PS 9.x and PS 8.x with the module source mounted into the container.
- **CI** — GitHub Actions matrix (PHP 8.1 / 8.2 / 8.3) running PHPCS PSR-12, PHPStan level 5, and PHPUnit.
- **OpenSpec** — initialised in this repo for downstream phases.

Out of scope for this change: any HTTP traffic against Qamera AI, hook handler bodies, the "Qamera AI" product tab, session UI, CLI commands, webhook front controller.

## Capabilities

### New Capabilities

- `prestashop-module-bootstrap`: install / uninstall lifecycle, configuration persistence, and the back-office configuration UI contract for the Qamera AI module.

### Modified Capabilities

None — this is a greenfield repo.

## Impact

- **Code:** ~12 files spanning module class, installer, controller, Twig template, services.yml, routes.yml, XLIFF, Docker Compose, CI workflow, phpunit/phpcs/phpstan configs, smoke test.
- **Compatibility:** PrestaShop 8.0+ — 9.x, PHP 8.1+, MySQL 5.7+ / MariaDB 10.3+.
- **External services:** none — the module ships with empty credentials; the operator pastes them via the configuration page.
- **Dependencies:** Guzzle 7 (HTTP client used in Phase 2), ramsey/uuid 4, phpunit 10, phpstan 1.10, squizlabs/php_codesniffer 3.7, prestashop/php-dev-tools 4.
- **Repository:** new repo `github.com/200iqlabs/prestashop-qamera`, public, MIT.
- **Docs:** README covers installation, Docker dev env, phase plan. Architecture decisions stay in `qamera-ai/saas-platform` under `docs/decisions/prestashop-plugin-{design,repo-bootstrap}.md`.
