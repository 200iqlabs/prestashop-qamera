# Qamera AI for PrestaShop

AI-powered product photography for PrestaShop stores. Generate packshots, scenes, and full content sessions from your store products using the [Qamera AI](https://qamera.ai) platform.

**Status:** Phase 4.1 (done — webhook receive-and-verify). Inbound deliveries from `qamera.ai` are authenticated (HMAC-SHA256), deduplicated, and persisted to the local delivery log. Phase 4.2 will translate verified deliveries into product/image state. See the [Phase plan](#phase-plan) below.

## Compatibility

| Item | Range |
|---|---|
| PrestaShop | 8.0+ — 9.x |
| PHP | 8.1+ |
| MySQL / MariaDB | 5.7+ / 10.3+ |

## Installation

1. Download the latest release ZIP from the [Releases page](https://github.com/200iqlabs/prestashop-qamera/releases) (or build from source — see "Build from source" below).
2. In PrestaShop back office, go to **Modules → Module Manager → Upload a module**, drop the ZIP, install.
3. Open the module configuration. Paste the **API key** and **webhook secret** provided by your Qamera AI operator (rotate them in the Qamera panel — never store them in source).
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

### Running tests

Three tiers, in increasing cost:

- **Unit** — fast (~3s in container), hermetic, PS stubbed:
  ```bash
  docker compose exec prestashop bash -c 'cd /var/www/html/modules/qameraai && vendor/bin/phpunit --testsuite=unit'
  ```
  This is the inner loop. Add `--testsuite=unit,contract` to also run the Pact-style fixture suite.

- **Integration** — boots a real PS9 kernel inside the container, exercises real `Db`, `Image`, `_PS_PRODUCT_IMG_DIR_`. Requires `make up` to be running:
  ```bash
  docker compose exec prestashop bash -c 'cd /var/www/html/modules/qameraai && vendor/bin/phpunit -c phpunit.integration.xml.dist'
  ```
  HTTP transport to `qamera.ai` is always mocked via Guzzle `MockHandler`; the kernel is rebound at boot to `http://qamera-test.invalid` so any forgotten rebind fails with a DNS error instead of leaking real credentials. See `openspec/specs/integration-test-harness/spec.md` for the full contract.

- **Smoke** — operator-driven end-to-end against a real Qamera AI install. Lives outside CI and Git history. Run it before a release; see the internal runbook (do NOT embed credentials in this repo).

## Phase plan

| Phase | Scope | Status |
|---|---|---|
| 1 | Repo bootstrap (module class, install hooks, configuration page skeleton, CI, Docker) | Done |
| 2 | Local product-sync bookkeeping (`actionProductSave` snapshot writer, `qamera_product_link` state columns) | Done |
| 3 | First upstream sync (`actionWatermark` image hook → presigned upload → `POST /images` with `product_metadata` → state transitions) | Done — first upstream sync |
| 4.1 | Inbound webhook handler (HMAC-SHA256 verification, replay guard, delivery-id idempotency, persisted delivery log) | Done |
| 4.2 | Product-tab UI in BO (Qamera AI tab on the product card, packshot generation, manual retry, dispatch of persisted deliveries) | Pending |
| 5 | CLI + bulk (`bin/console qamera:sync-products`, cron-friendly batches) | Pending |
| 6 | Marketplace prep (PS marketplace compliance validator, submission package) | Optional |

The architecture decisions live in the upstream `qamera-ai/saas-platform` repository under `docs/decisions/prestashop-plugin-design.md` and `docs/decisions/prestashop-plugin-repo-bootstrap.md`.

## Phase 4.1 — Webhook handler

The module exposes an unauthenticated storefront endpoint that accepts inbound webhook deliveries from `qamera.ai`. HMAC-SHA256 verification of the request body IS the authentication; the endpoint is intentionally CSRF-exempt and ignores admin sessions.

**Callback URL** — set this in the Qamera AI panel at `Settings → Plugin installations → <your install> → callback_url`:

```
https://<your-shop>/module/qameraai/webhook
```

(Without URL rewriting enabled, fall back to `https://<your-shop>/index.php?fc=module&module=qameraai&controller=webhook`.)

The shared **webhook secret** must be pasted into Back Office → **Modules → Qamera AI → Configuration → Webhook secret**, matching the value Qamera AI displays when you generate or rotate it. The plugin never logs into the Qamera AI panel directly.

**Where deliveries are logged** — every decision lands in the `QameraAiModule` log channel (`Advanced parameters → Logs`):

- `info` — delivery accepted
- `warning` — duplicate `delivery_id` re-acknowledged (200 returned, no re-processing)
- `error` — rejection with a structured reason code (`missing_signature`, `signature_mismatch`, `replay_window`, …)

Accepted deliveries are persisted to `ps_qamera_webhook_delivery` (PK = `delivery_id`). Rejected deliveries are NOT persisted — the endpoint is unauthenticated, so persisting every invalid request would let an attacker fill the table.

**Troubleshooting** — if you see rejection spikes:

- `replay_window` → server clock is drifting. Check NTP (`timedatectl status` on Linux hosts).
- `missing_signature` / `malformed_signature` → some Apache + PHP-FPM stacks strip custom headers. Add the following to your vhost so the `X-Qamera-*` headers reach PHP:

  ```apache
  SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
  SetEnvIfNoCase ^X-Qamera-Signature$ "(.*)" HTTP_X_QAMERA_SIGNATURE=$1
  SetEnvIfNoCase ^X-Qamera-Delivery-Id$ "(.*)" HTTP_X_QAMERA_DELIVERY_ID=$1
  ```

- `signature_mismatch` → the secret in BO Configuration no longer matches Qamera AI. During the upstream 48 h dual-sign rotation grace window the plugin accepts the delivery if **either** signed value verifies against your local secret; once the window closes you must paste the new secret into Configuration.

The handler does NOT yet dispatch verified deliveries to product/image state — that lands in Phase 4.2 (`add-webhook-event-dispatch`), which consumes the persisted rows as its input queue.

## License

MIT — see [LICENSE](./LICENSE).
