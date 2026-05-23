# Implementation tasks — bootstrap-prestashop-module

## 1. Repo + dependencies

- [x] 1.1. Create local repo at `C:\Users\pawel\source\repos\200iqlabs\prestashop-qamera`, `git init -b main`
- [x] 1.2. `openspec init --tools claude` to seed the spec workflow
- [x] 1.3. Compose `composer.json` with PHP 8.1 / Guzzle 7 / ramsey-uuid 4 runtime deps and phpunit / phpstan / phpcs / prestashop-dev-tools dev deps
- [x] 1.4. Top-level `.gitignore` excluding `/vendor`, lockfiles, IDE folders, secret env files

## 2. Module entry surface

- [x] 2.1. `qameraai.php` main `Module` subclass with constructor metadata, `install()`/`uninstall()` delegating to `Installer`, and `getContent()` redirect to the Symfony route
- [x] 2.2. `config.xml` PS module manifest

## 3. Install lifecycle (`src/Install/Installer.php`)

- [x] 3.1. `createSchema()` — `CREATE TABLE IF NOT EXISTS` for `ps_qamera_product_link` and `ps_qamera_packshot_link` with the full Phase 2/3 column set
- [x] 3.2. `dropSchema()` — `DROP TABLE IF EXISTS` for both link tables
- [x] 3.3. `registerHooks()` — bind the four hooks declared in `Installer::HOOKS`
- [x] 3.4. `seedDefaults()` — `Configuration::updateValue` for the five `QAMERAAI_*` keys, gated by `Configuration::get(...) === false` so re-install preserves user-supplied values
- [x] 3.5. `purgeConfiguration()` — `Configuration::deleteByName` for every `QAMERAAI_*` key
- [x] 3.6. `installAdminTabs()` / `uninstallAdminTabs()` for the hidden `AdminQameraAiConfiguration` tab

## 4. Symfony admin route + controller

- [x] 4.1. `config/services.yml` registering the `QameraAi\Module\*` namespace
- [x] 4.2. `config/admin/services.yml` registering admin controllers with `controller.service_arguments` tag
- [x] 4.3. `config/routes.yml` defining `_qameraai_admin_configuration` at `/qameraai/configuration` with `_legacy_link: AdminQameraAiConfiguration`
- [x] 4.4. `ConfigurationController::indexAction` — GET renders template with masked secrets, POST persists via `Configuration::updateValue`, skipping any field that still starts with the masking prefix
- [x] 4.5. `views/templates/admin/configuration.html.twig` — Symfony-style form rendering all five fields, with `Test Connection` disabled (Phase 2 wires it)

## 5. i18n

- [x] 5.1. `translations/modules/qameraai/Admin.en.xlf` — every visible label as a `<trans-unit>` under `Modules.Qameraai.Admin`
- [x] 5.2. `translations/modules/qameraai/Admin.pl.xlf` — Polish translations
- [x] 5.3. `translations/modules/qameraai/Admin.uk.xlf` — Ukrainian translations

## 6. Local dev (Docker)

- [x] 6.1. `docker/docker-compose.ps9.yml` — PS 9.0 + MySQL 8.0, host port 8080, source mounted into `/var/www/html/modules/qameraai`
- [x] 6.2. `docker/docker-compose.ps8.yml` — PS 8.1 + MySQL 5.7, host port 8081, same mount
- [x] 6.3. `docker/README.md` — quickstart and tear-down

## 7. CI

- [x] 7.1. `.github/workflows/ci.yml` — matrix on PHP 8.1 / 8.2 / 8.3 running PHPCS PSR-12, PHPStan level 5, PHPUnit
- [x] 7.2. `phpunit.xml.dist` configured to bootstrap from `tests/bootstrap.php` and pick up `tests/Unit`
- [x] 7.3. `phpstan.neon` at level 5 with PrestaShop core class-not-found errors ignored for `src/Install` and `src/Controller/Admin`
- [x] 7.4. `phpcs.xml.dist` PSR-12 ruleset across `src` and `tests`
- [x] 7.5. `tests/bootstrap.php` requiring the Composer autoload
- [x] 7.6. `tests/Unit/SmokeTest.php` — autoload smoke test so the suite has at least one passing test

## 8. Docs

- [x] 8.1. `README.md` — install path, Docker dev, phase plan, build-from-source recipe
- [x] 8.2. `LICENSE` (MIT)

## 9. OpenSpec bootstrap

- [x] 9.1. `openspec init` ran, repo contains `openspec/` directory
- [x] 9.2. Change `bootstrap-prestashop-module` created with proposal + design + specs + tasks

## 10. Repo creation + first push

- [ ] 10.1. `gh repo create 200iqlabs/prestashop-qamera --public --source=. --remote=origin --description="Qamera AI integration for PrestaShop — AI-powered product photography"`
- [ ] 10.2. First commit on `main` (`feat: bootstrap PrestaShop module — install lifecycle, configuration page, CI`) + push
- [ ] 10.3. Verify CI run passes on the bootstrap commit (PHP 8.1 / 8.2 / 8.3 matrix)

## 11. Verification

- [ ] 11.1. `composer install` succeeds locally
- [ ] 11.2. `vendor/bin/phpcs --standard=PSR12 src/ tests/` clean
- [ ] 11.3. `vendor/bin/phpstan analyse src/ --level=5` clean
- [ ] 11.4. `vendor/bin/phpunit` runs the smoke test
- [ ] 11.5. `docker compose -f docker/docker-compose.ps9.yml up` brings up PS 9.x, the module appears in Module Manager → Designer view, and clicking Install succeeds without errors

## 12. Archive

- [ ] 12.1. After Phase 1 sign-off, `openspec archive bootstrap-prestashop-module` to roll the spec into `openspec/specs/prestashop-module-bootstrap/`
