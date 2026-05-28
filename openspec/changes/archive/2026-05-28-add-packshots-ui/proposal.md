## Why

Phases 1–4.2 wired up the plumbing: products sync to qamera.ai, webhook deliveries are verified and dispatched
to bookkeeping. But nothing in the plugin actually **creates** a generation request — operators have no way to
say "render this product as a beauty shot with model X, scene Y, preset Z." Phase 4.3 closes that loop and ships
the first user-visible feature in the module: a BO surface to submit AI packshot jobs against synced products
and watch them complete.

## What Changes

- **New BO area** under `AdminCatalog → Qamera AI` with two pages:
  - Products grid (synced rows from `ps_qamera_product_link`, with single + bulk "Generate" actions)
  - Jobs history (newly-submitted jobs and their lifecycle status, fed by the local job table)
- **New "Generate packshots" form**: per-subject inputs (one subject per selected product, MVP) plus
  session-level config (AI model, scenery, mannequin model, preset, aspect ratio, suggestions, images count),
  with a pre-flight credit cost display.
- **New API endpoint wrappers** in `QameraApiClient`:
  - `POST /jobs` (session-envelope payload, with `Idempotency-Key`)
  - `GET /jobs`, `GET /jobs/{id}`
  - Reference data: `GET /ai-models`, `/sceneries`, `/models`, `/presets`, `/aspect-ratios`, `/pricing`
    (with per-endpoint TTL caching keyed by API key)
- **New local mirror table** `ps_qamera_packshot_job` (job_id PK, FK to `ps_qamera_product_link`, order_id,
  status, output_url, error, timestamps) — separate from the per-image link row so a single product can have
  many job rows over its lifetime (cost auditing, history, "undo" UX later).
- **Extension of Phase 4.2 webhook handlers** to additionally update `ps_qamera_packshot_job` rows by `job_id`
  on `job.completed` / `job.failed` payloads. The link-row `last_synced_at` bump stays as a secondary signal.
- **`auto_register_packshot=true` on every submit**, with a client-generated `packshot_external_ref` of shape
  `ps:<shopId>:<productId>:packshot:<client-uuid>`, mapped back to `order_id`/`job_ids` once the response lands.
- **Disabled "Generate" action** for rows whose `qamera_image_id IS NULL` (never synced) — UI surfaces the
  prerequisite instead of letting the operator hit a server-side 422.

## Capabilities

### New Capabilities

- `packshot-jobs`: Local mirror of upstream generation jobs — table schema, repository, submitter that turns
  selected product link rows + form values into a `POST /jobs` payload and persists one row per returned
  `job_id`. Includes the cost calculator (sum of `images_count × unit_cost` from cached `/pricing`).
- `qamera-bo-ui`: BO admin controllers, Smarty templates, vanilla JS, and i18n strings (PL primary, EN
  fallback) for the products grid, generate form, and jobs history pages. PS Symfony admin controller patterns,
  no React.

### Modified Capabilities

- `qamera-api-client`: Adds `JobsEndpoint` (submit/get/list) and `ReferenceEndpoint` (the six reference data
  endpoints with TTL caching). The DTO surface grows: `SubmitJobRequest`, `SessionConfig`, `Subject`,
  `SubmitJobResponse`, `JobDto`, `AiModelDto`, `SceneryDto`, `ModelDto`, `PresetDto`, `AspectRatioDto`,
  `PricingDto`. Adds typed exceptions for 422 `ApiValidationException` and 409 idempotency conflicts.
- `webhook-handler`: Existing `JobCompletedHandler` / `JobFailedHandler` (Phase 4.2) extended to additionally
  update `ps_qamera_packshot_job` by `job_id` from the payload. `ps_qamera_product_link` updates remain
  unchanged — the new table is an additive sink, not a replacement.
- `prestashop-module-bootstrap`: Installer creates the new `ps_qamera_packshot_job` table on install and drops
  it on uninstall. Registers the two new admin tabs.

## Impact

- **Database**: new table `ps_qamera_packshot_job` with FK to `ps_qamera_product_link.id_qamera_product_link`.
  Idempotent install/uninstall via `Install/Installer.php`.
- **Code**: new `src/Api/Endpoint/JobsEndpoint.php`, `src/Api/Endpoint/ReferenceEndpoint.php`,
  `src/Api/Dto/*` additions; new `src/Packshot/PackshotJobRepository.php`,
  `src/Packshot/PackshotJobSubmitter.php`, `src/Packshot/CostCalculator.php`; two new admin controllers under
  `controllers/admin/`; templates under `views/templates/admin/`; minimal `views/css/` + `views/js/`.
- **Configuration / services.yml**: register new endpoint, repository, submitter, and calculator services;
  inject into webhook handlers and admin controllers.
- **Upstream API surface used**: `POST /jobs` (session envelope — relies on the upstream
  `add-plugin-session-lifecycle` change being deployed), plus six reference data endpoints. No outbound HMAC
  changes; inbound webhook verification stays as Phase 4.1.
- **CI**: PHPCS / PHPStan level 5 / PHPUnit on 8.1/8.2/8.3 stays green. New unit tests via Guzzle
  `MockHandler`; no live calls.
- **Out of scope** (deferred to Phase 4.4+, marked with `OQ-PS*`): manual retry from BO, "save output back as
  product image", accept/reject voting UI, bulk backfill cron, multi-shop session expansion.
