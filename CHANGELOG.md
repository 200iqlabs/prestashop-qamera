# Changelog

All notable changes to the Qamera AI PrestaShop module are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Translations: [polski](CHANGELOG.pl.md) · [українська](CHANGELOG.uk.md)

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

[1.0.0]: https://github.com/200iqlabs/prestashop-qamera/releases/tag/v1.0.0
