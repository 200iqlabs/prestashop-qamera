# Qamera AI for PrestaShop

AI-powered product photography for PrestaShop stores. Generate packshots, scenes, and full content sessions from your store products using the [Qamera AI](https://qamera.ai) platform.

**Status:** Phase 2 (in progress — local product-sync bookkeeping landed; upstream API sync still pending). See the [Phase plan](#phase-plan) below.

## Compatibility

| Item | Range |
|---|---|
| PrestaShop | 8.0+ — 9.x |
| PHP | 8.1+ |
| MySQL / MariaDB | 5.7+ / 10.3+ |

## Installation

1. Download the latest release ZIP from the [Releases page](https://github.com/200iqlabs/prestashop-qamera/releases) (or build from source — see "Build from source" below).
2. In PrestaShop back office, go to **Modules → Module Manager → Upload a module**, drop the ZIP, install.
3. Open the module configuration. Paste the **API key** (`mk_live_…`) and **webhook secret** provided by your Qamera AI operator.
4. Click "Test Connection" — a green check means you are wired up.

The plugin never logs into Qamera AI directly. Your operator at Qamera AI issues credentials bound to a specific plugin installation and hands them to you; rotation goes through the Qamera AI panel.

## Build from source

```bash
git clone https://github.com/200iqlabs/prestashop-qamera.git
cd prestashop-qamera
composer install --no-dev
zip -r qameraai.zip . -x ".git/*" "tests/*" ".github/*" ".gitignore" "*.md" "phpunit.xml.dist" "phpcs.xml.dist" "phpstan.neon"
```

## Local development

This repository ships only the module source. The preferred dev environment is the sibling `qameraai-prestashop` shell which hosts the PrestaShop container, MySQL, phpMyAdmin, and a `Makefile` with `make up` / `make install` / `make logs` shortcuts. Clone this repo as a subdirectory of that shell:

```bash
git clone https://github.com/200iqlabs/prestashop-qamera.git modules/qameraai
cd modules/qameraai && composer install
cd ..
make up
make install   # runs `prestashop:module install qameraai` inside the container
```

PS back office is at `http://localhost:8080/admin-dev`. The module source under `./modules/qameraai/` is bind-mounted into the container so edits show up after a back-office refresh.

Point the module's configuration page at your local Qamera AI:

- API base: `http://host.docker.internal:3000/api/v1/plugin`
- API key + webhook secret: from `Settings → API Keys` on a local pracownia-style account.

## Phase plan

| Phase | Scope | Status |
|---|---|---|
| 1 | Repo bootstrap (module class, install hooks, configuration page skeleton, CI, Docker) | Done |
| 2 | Core flow (Qamera API client, per-product sync, webhook handler, "Qamera AI" product tab) | In progress (bookkeeping done) |
| 3 | Session UI (multi-product session form, dashboard, polling) | Pending |
| 4 | CLI + bulk (`bin/console qamera:sync-products`, cron-friendly batches) | Pending |
| 5 | Marketplace prep (PS marketplace compliance validator, submission package) | Optional |

The architecture decisions live in the upstream `qamera-ai/saas-platform` repository under `docs/decisions/prestashop-plugin-design.md` and `docs/decisions/prestashop-plugin-repo-bootstrap.md`.

## License

MIT — see [LICENSE](./LICENSE).
