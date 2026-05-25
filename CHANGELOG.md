# Changelog

All notable changes to the Qamera AI PrestaShop module are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Translations: [polski](CHANGELOG.pl.md) · [українська](CHANGELOG.uk.md)

## [1.1.0] — 2026-05-25

Phase 2 lazy bookkeeping: the module now records a local snapshot of every product the operator saves in the back office. No upstream Qamera AI API calls happen yet — those land in Phase 3 (image-sync). PrestaShop 8.0–9.x, PHP 8.1+.

### Added

- **`actionProductSave` hook handler.** Fires on both `Product::add()` and `Product::update()` in PS 8/9 — the primary entry point for capturing newly-created products. The legacy `actionProductAdd` hook is dispatched only by `ProductDuplicator` in PS 9, so registering Save was necessary to cover the BO "create product" flow.
- **`ps_qamera_product_link` snapshot columns.** Six new columns: `display_name_snapshot VARCHAR(500) NOT NULL`, `sku_snapshot VARCHAR(100) NULL`, `description_snapshot TEXT NULL`, `status ENUM('pending','registered','error') NOT NULL DEFAULT 'pending'`, `last_error_message TEXT NULL`, `last_synced_at DATETIME NULL`. The existing `qamera_product_id` column was relaxed from `NOT NULL` to `NULL` — it stays empty until Phase-3 upstream registration succeeds.
- **Idempotent schema migration.** `Installer::createSchema` introspects `INFORMATION_SCHEMA.COLUMNS` and only runs `ALTER` statements for columns that are missing or non-matching, so repeated installs / Phase-1 upgrades both no-op cleanly. A failed introspection probe now aborts the install rather than silently leaving a Phase-1 schema in place.
- **`QameraAi\Module\Sync\ProductSnapshotWriter`** — single `INSERT … ON DUPLICATE KEY UPDATE` keyed on `UNIQUE(id_product, id_shop)`. The UPDATE clause refreshes only snapshot columns and `updated_at`; `status`, `qamera_product_id`, `last_error_message`, `last_synced_at`, `qamera_product_ref`, and `created_at` are preserved across upserts so downstream sync state is never regressed.
- **`QameraAi\Module\Sync\ProductRefBuilder`** — deterministic `qamera_product_ref` formatted as `ps:{id_shop}:{id_product}`. Multistore safe (different shops yield distinct refs); rejects non-positive ids.

### Behaviour

- Hook bookkeeping is gated on the existing `QAMERAAI_AUTO_REGISTER_PRODUCTS` toggle (default off from Phase 1). Toggle OFF is a true no-op.
- All `\Throwable` from the writer is caught in the hook and logged via `PrestaShopLogger::addLog` at severity 2 with `object_type='QameraAiModule'`. BO "Save product" always succeeds from the operator's point of view, regardless of bookkeeping state.
- Snapshot reads use the shop's default language (`Configuration::get('PS_LANG_DEFAULT', null, null, $idShop)`); when that translation is missing, the writer falls back to the first non-empty language value and logs a warning.

### Changed

- **No upstream API impact.** The `QameraApiClient` surface, the upstream `/plugin/*` endpoints, and the webhook handler are untouched by this release.

### Known limitations

- New-product creation still requires the BO "Save" action; orphan rows from `Product::delete()` are not cleaned up (`actionProductDelete` lands in a follow-up change).
- `status='error'` rows refresh their snapshot on update but do not auto-retry — operator-driven retry lands with the Phase-4 product-tab UI.

## [1.0.0] — 2026-05-24

Inaugural release. Brings credential storage, an installable lifecycle, and a tested HTTP client to the Qamera AI Plugin API. PrestaShop 8.0–9.x, PHP 8.1+.

### Added

- **Back-office configuration page** under *Ulepszanie → Qamera AI*. Stores the API base URL, API key, webhook secret, an auto-register-new-products toggle, and a sync batch size. Secrets render masked; submitting the form without editing a masked field leaves the stored value untouched.
- **Module installer** — creates two MySQL tables (`qamera_product_link`, `qamera_packshot_link`), registers four PrestaShop hooks (`actionProductAdd`, `actionProductUpdate`, `displayAdminProductsExtra`, `displayBackOfficeHeader`) and seeds five configuration defaults. The uninstaller mirrors every step.
- **Typed HTTP client to the Qamera AI Plugin API.** One method per consumed endpoint (`me`, catalog reads, image and packshot register, presigned upload, job submission, product reads). Authentication, retry, idempotency-key generation on writes, and error-envelope decoding are baked in — callers never see a raw Guzzle exception.
- **Test connection** button on the configuration page. Posts to a CSRF-protected admin route, calls `GET /me` with the stored credentials, and renders the result inline (account name, credits balance, subscription plan, installation platform and status). The Save form is untouched by this action.
- **Polish and Ukrainian translations** for the back-office strings.
- **CI matrix** on PHP 8.1 / 8.2 / 8.3 (PHPCS PSR-12, PHPStan level 5 with PrestaShop core loaded via `_PS_ROOT_DIR_`, PHPUnit).

### Known limitations

- Hook handlers are no-op stubs; product synchronization, packshot submission flows, and webhook handling land in subsequent phases.
- Multistore is single-key (one API key per install). Per-shop credentials are a v2 follow-up.
- The configuration page edits secrets but cannot rotate the webhook HMAC — that lives in the Qamera AI panel.

[1.1.0]: https://github.com/200iqlabs/prestashop-qamera/releases/tag/v1.1.0
[1.0.0]: https://github.com/200iqlabs/prestashop-qamera/releases/tag/v1.0.0
