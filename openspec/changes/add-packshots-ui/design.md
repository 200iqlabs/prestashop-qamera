## Context

Phases 1–4.2 already left a lot of this surface area in place; the design has to thread the new code through
existing seams rather than building greenfield.

**What already exists** (verified 2026-05-28):

- `QameraApiClient` exposes `submitJob`, `getJob`, `listJobs`, `listAiModels`, `listSceneries`,
  `listPresets`, `listAspectRatios`, `getPricing`. Their DTOs (`SubmitJobRequest`, `SessionConfig`, `Subject`,
  `SubmitJobResponse`, `JobDto`, `JobsListResponse`, `AiModel`, `Scenery`, `Preset`, `AspectRatio`, `Pricing`,
  `PricingEntry`) are already in `src/Api/Dto/`.
- `ps_qamera_packshot_link` already exists (keyed on `qamera_packshot_id`, FK to product link). The Phase 4.2
  `JobCompletedHandler` upserts a row there with `status='ready'` for each delivered packshot. `qamera_job_id`
  is stored on the packshot row as a non-key column.
- `JobCompletedHandler`, `JobFailedHandler`, `JobRetriedHandler`, `JobCancelledHandler` route deliveries via
  `PackshotLinkUpdater` + `ProductLinkHeartbeat`. Defensive guards (unknown product, missing fields) already
  in place.
- Admin BO uses Symfony `FrameworkBundleAdminController` + **Twig**, not Smarty. Existing example:
  `ConfigurationController` + `views/templates/admin/configuration.html.twig`.

**What's still missing for Phase 4.3**:

- A "job-in-flight" state table — packshot_link only exists *after* a webhook lands. The operator submitting a
  job needs to see "pending, submitted 30s ago" in the BO before the webhook arrives, and failed jobs never
  produce a packshot row at all.
- TTL caching for the six reference data endpoints. Current methods hit the wire each call; for the BO form
  rendered on every load this would be both slow and rate-limit-prone.
- `listMannequinModels()` — the upstream `GET /models` endpoint isn't wrapped yet (distinct from `/ai-models`).
- A submitter + cost calculator + repository on the local side.
- Two BO controllers + Twig templates + vanilla JS for the form modal.
- Webhook handler wiring to update the new job-state table on `job.*` deliveries.

**Constraints**:

- PHP 8.1+, PSR-12, PHPStan level 5 (excluding `src/Install/*`).
- No React, no Node — PS admin's jQuery + Bootstrap 4 + Twig only.
- PrestaShop 8.0–9.x compatibility.
- Upstream contract is `add-plugin-session-lifecycle` envelope shape (verified 2026-05-28).

## Goals / Non-Goals

**Goals:**

- Operator can select 1..N synced products in a BO grid, fill a form (AI model, scenery, mannequin,
  preset, aspect ratio, images_count, suggestions), see pre-flight credit cost, and submit a single
  `POST /jobs` call.
- Each submitted job creates one local `ps_qamera_packshot_job` row per `job_id` returned, in status `pending`.
- Phase 4.2 webhook handlers additionally update the corresponding `ps_qamera_packshot_job` row's status,
  `output_url`, and `last_error_message` keyed by `qamera_job_id` from the payload.
- Operator sees a jobs history page filtered by status, with cursor-paginated list backed by both local rows
  and (on demand) `GET /jobs` for cross-check.
- Rows with `qamera_image_id IS NULL` (never synced) render with a disabled "Generate" action and a "Sync
  first" hint.
- Reference data is cached server-side with per-endpoint TTLs, keyed by API key hash.

**Non-Goals (deferred, marked with `OQ-PS*` upstream):**

- Manual retry of failed jobs from BO — Phase 4.4.
- "Save output back as PS product image" — Phase 4.4+.
- Accept/reject voting UI from BO — Phase 4.5 (`POST /jobs/{id}/accept|reject` already exist upstream).
- Bulk backfill cron for unsynced products — Phase 4.6.
- Multistore session expansion (different shops, different default configs) — v2.
- Webhook secret rotation initiated from PS — Qamera panel only (OQ-PS6).
- Marketplace styles browser — dropdown from `/presets` is the v1 affordance (OQ-PS5).
- Front-office display of generated assets — standard PS product images flow handles it.

## Decisions

### D1. Separate `ps_qamera_packshot_job` table, not an extension of `ps_qamera_packshot_link`

A job and a packshot are not 1:1. One job request can produce N output packshots (`images_count` × subjects).
A failed job produces zero packshots — there'd be nothing to key the existing `packshot_link` row on. And we
want history across re-runs (cost auditing, "what did the operator try last week"), which requires per-job
durability independent of packshot lifecycle.

**Alternative considered**: extend `ps_qamera_packshot_link` with `last_order_id`, `last_job_ids` JSON. Tight
coupling, loses history, fails on `job.failed` deliveries.

**Schema** (verbose for design review; concrete DDL goes in tasks.md):

```
ps_qamera_packshot_job
  id_qamera_packshot_job  INT UNSIGNED AUTO_INCREMENT PK
  qamera_job_id           CHAR(36)  NOT NULL UNIQUE
  qamera_order_id         CHAR(36)  NOT NULL
  id_qamera_product_link  INT UNSIGNED NOT NULL  (FK → ps_qamera_product_link)
  id_shop                 INT UNSIGNED NOT NULL
  id_product              INT UNSIGNED NOT NULL
  packshot_external_ref   VARCHAR(100) NOT NULL UNIQUE  (ps:<shop>:<product>:packshot:<client-uuid>)
  status                  ENUM('pending','in_progress','completed','failed','cancelled') NOT NULL DEFAULT 'pending'
  output_url              TEXT NULL
  output_url_expires_at   DATETIME NULL
  last_error_message      TEXT NULL
  ai_model                VARCHAR(100) NOT NULL
  aspect_ratio            VARCHAR(8)   NOT NULL
  images_count            SMALLINT UNSIGNED NOT NULL
  session_config_json     JSON NOT NULL    (snapshot for audit / re-submit)
  submitted_at            DATETIME NOT NULL
  last_synced_at          DATETIME NULL
  INDEX (id_shop, id_product)
  INDEX (status, submitted_at)
```

Note: the packshot_external_ref is generated client-side at submit time so it can be embedded in the
`Subject.packshot_external_ref` field of the `POST /jobs` request. That way, upstream's
`auto_register_packshot=true` produces a `product_packshots` row keyed by a ref that we already have local
context for.

### D2. Write-after-API-success, no DB transaction wrapping the HTTP call

The proposal initially suggested a transactional rollback if upstream returned 503 — but that requires holding
a DB connection across an unbounded network call (seconds), which under PS's mod_php/FPM model is a footgun
on connection pools.

Instead:

1. Generate `packshot_external_ref` per subject (client-side UUID v4) and assemble the `SubmitJobRequest`.
2. Call `QameraApiClient::submitJob()`. On failure, **no DB writes happen** — the user gets a flash-error and
   can retry. The upstream rejection means no order was created.
3. On success, iterate `SubmitJobResponse.subjects[].job_ids[]` and insert one `ps_qamera_packshot_job` row per
   `job_id`, all in a single batch (one statement, ON DUPLICATE KEY UPDATE for idempotent retry).
4. If the local insert fails after a successful API call: log ERROR with the order_id and job_ids returned, so
   the operator can reconcile manually. The webhook handler (next decision) will additionally create the row
   on `job.completed` if it doesn't already exist — eventual consistency.

**Alternative considered**: optimistic insert with status='draft' before the call, flip to 'pending' on
success, DELETE on failure. Adds a state column value with no meaning to webhook consumers, and a network
failure between API success and DB flip leaves stranded 'draft' rows. Rejected as more complex with no
benefit.

### D3. Webhook handlers gain a `PackshotJobUpdater` injection

The four existing job handlers (`JobCompletedHandler`, `JobFailedHandler`, `JobRetriedHandler`,
`JobCancelledHandler`) currently take `PackshotLinkUpdater` + `ProductLinkHeartbeat`. Add a new
`PackshotJobUpdater` (parallel structure) and inject it into all four. It performs an idempotent UPSERT keyed
on `qamera_job_id`, mapping payload status → enum, copying `output_url` / `last_error_message` /
`output_url_expires_at` when present.

If the row doesn't exist (race condition: webhook arrived before submitter persisted), insert a stub row
with what the payload provides — `id_qamera_product_link` is recovered by parsing the `external_ref`
(`ps:<shop>:<product>`) and looking up the link row, `packshot_external_ref` from the payload if present
(upstream echoes it back).

**Alternative considered**: have each handler do its own job-table update via raw SQL. Rejected — repository
encapsulation matches `PackshotLinkUpdater` pattern and keeps unit tests symmetrical.

### D4. Reference data cache: filesystem-backed, per-endpoint TTL, keyed by `sha256(api_key)`

Six endpoints × multiple BO loads = wasteful round-trips and an easy way to hit upstream rate limits during
a busy operator session. But we can't share a cache across API keys — `Vary: X-Api-Key` on `/ai-models` means
the response is account-scoped.

Cache implementation: a small `ReferenceCache` service wrapping `\Cache::getInstance()` (PS's pluggable
cache backend) with a fallback to filesystem under `_PS_CACHE_DIR_ . 'qameraai/reference/'`. Key shape:
`qameraai:ref:<endpoint>:<sha256(api_key)[0:16]>`. TTL per endpoint:

| Endpoint        | TTL    | Rationale                                        |
|-----------------|--------|--------------------------------------------------|
| `/ai-models`    | 300s   | Provider catalog changes infrequently             |
| `/sceneries`    | 300s   | Marketplace + account scenes, user-extensible    |
| `/models`       | 300s   | Mannequin models, same shape as scenes           |
| `/presets`      | 300s   | Style presets                                     |
| `/aspect-ratios`| 3600s  | Effectively static enum                           |
| `/pricing`      | 300s   | Brief explicitly calls out 5min cache here       |

The wrapper goes around `QameraApiClient` calls in a `CachedReferenceClient` decorator so the underlying
client stays uncached and unit-testable. BO controllers depend on `CachedReferenceClient` via the container.

Cache busting: on API key change in `ConfigurationController::handleSubmit`, fire a small invalidator that
deletes the previous key's cache entries (or just lets them TTL out — operator pain is bounded by 5 minutes).
MVP picks the TTL-out route; explicit busting is one line of follow-up if it matters.

**Alternative considered**: in-memory cache only (PS request lifetime). Rejected — BO operator clicks Generate,
form loads, cache populated, operator submits, redirected to history, reopens form → all reference data
fetched again. Filesystem cache survives request boundaries.

### D5. New `listMannequinModels()` method + DTO

`GET /models` exists upstream but isn't wrapped. Add `QameraApiClient::listMannequinModels(): array` returning
`MannequinModel[]` (new DTO with at least `id`, `label`, `thumbnail_url`, plus whatever marketplace flags
upstream returns).

Mirror the existing `listSceneries()` pattern verbatim — same retry decider, same array-of decoder, same
exception types.

### D6. BO surface: two Symfony admin controllers, Twig templates, parent tab "Qamera AI"

Routes (registered via `config/routes.yml`):

- `GET /modules/qameraai/products` → `ProductsGridController::indexAction` — list synced rows, paginated,
  with bulk-select checkboxes. Bulk action button → opens generate form modal/page seeded with selected
  product ids.
- `GET /modules/qameraai/generate` → `GenerateFormController::showAction` (or modal partial rendered by the
  grid). `POST /modules/qameraai/generate` → `GenerateFormController::submitAction` calls submitter, flashes
  result, redirects to history.
- `GET /modules/qameraai/jobs` → `JobsHistoryController::indexAction` — list local rows joined with product
  names, status filter, cursor pagination. Optional sync button on a row to refresh from `GET /jobs/{id}`.

Tabs (`Installer::installTabs`):

- Parent: `AdminQameraAi` under IMPROVE → Catalog (route: a redirect to products grid)
- Children: `AdminQameraAiProducts`, `AdminQameraAiJobs`

Templates under `views/templates/admin/`:

- `products_grid.html.twig`
- `generate_form.html.twig`
- `jobs_history.html.twig`

Vanilla JS (`views/js/generate_form.js`) handles: dynamic cost recalc on form field change, disabled subjects
without `qamera_image_id`, max-subjects guard, and the modal open/close. jQuery + Bootstrap 4 components are
available (PS admin global). **No React.**

i18n: translation domain `Modules.Qameraai.Admin`. PL is primary (matches the existing
`ConfigurationController` pattern); EN is the fallback Symfony resolves by default. Strings live in
`translations/`.

### D7. `auto_register_packshot=true` always, client-side UUID embedded as `packshot_external_ref`

Saves a round-trip to call `/register-packshot` after each job. The client-side UUID is generated by a small
helper (`Symfony\Component\Uid\Uuid::v4()` is available in PS 8+ vendor tree; fallback `bin2hex(random_bytes())`
if not). Format: `ps:<shopId>:<productId>:packshot:<uuid-v4>`.

This same ref is stored on the local job row and on the upstream-created `product_packshots` row, which is
what the webhook `external_ref` will eventually echo back. Three-way consistency: local job row, upstream job,
upstream packshot — all reachable from the same string.

### D8. Subjects-per-submit cap = 100 (matches upstream)

Both UI and submitter enforce `subjects.length <= 100`. If the bulk action receives more than 100 product
IDs, the controller chunks them into multiple `POST /jobs` calls (one per session_envelope, distinct
Idempotency-Keys, sequential not parallel). The operator sees a single aggregated flash message:
"Submitted 3 sessions, 247 jobs queued".

This adds a tiny bit of submitter logic but keeps the UX honest and matches what upstream will accept.

### D9. Idempotency-Key per session-envelope, not per subject

Brief says `POST /jobs` accepts `Idempotency-Key`. Generate one UUID v4 per submit call (one envelope = one
key). The existing `IdempotencyKeyGenerator` in `src/Api/Internal/` covers this. Don't reuse the key across
the chunks from D8 — each is a logically distinct submission.

## Risks / Trade-offs

- **[Upstream session-lifecycle envelope must be live]** → `POST /jobs` body shape was changed by the upstream
  `add-plugin-session-lifecycle` change. The existing `SubmitJobRequest` DTO already reflects that shape, so
  this is a one-time verify (`grep session_config src/Api/Dto/`) during task #1, but the smoke step must hit a
  live deployment that has shipped it.

- **[Cache pollution on API key rotation]** → Reference cache entries from the previous key remain on disk for
  up to 1h (`/aspect-ratios`). Bounded staleness; no security impact (cache files contain only public
  reference data scoped to *the prior* account). Acceptable for MVP. Mitigation: cache-bust on
  Configuration save in v2 if it bites.

- **[Submitter writes after API success]** → Failure mode is "upstream accepted job, local row never
  inserted". The webhook UPSERT in D3 makes the row eventually appear with `status='completed'` (or
  whatever the terminal status is). Trade-off: history of "I pressed Submit at T+0" is lost — only
  `submitted_at` from the webhook arrival exists in that path. Acceptable; logs cover the audit.

- **[BO operator picks an `(ai_model, aspect_ratio)` combination upstream doesn't support]** → Upstream returns
  422 with field-level errors. We surface them as form errors via `ApiValidationException` (already exists in
  `src/Api/Exception/ValidationException.php`). UX: form re-renders with red highlights, no DB writes, no
  flash redirect. Pre-flight client-side validation against `/ai-models` capability matrix is a nice-to-have
  but not load-bearing.

- **[Schema drift if upstream renames a status]** → `ps_qamera_packshot_job.status` ENUM is closed. If
  upstream adds e.g. `paused`, the handler crashes on save. Mitigation: handler maps unknown payload statuses
  to `pending` and logs a WARNING with the unknown value, instead of throwing. Sentry-grade observability —
  add to webhook logger calls.

- **[Subjects-cap chunking partial failure]** → If session #2 of 3 fails with 503, sessions #1 and #3 already
  succeeded (or session #1 succeeded, #3 never sent). UX: flash message reports "Submitted 1 of 3 sessions
  (47 jobs queued); 2 sessions failed — see logs". The operator can retry the failed selection. Not ideal,
  but bounded; adding fancy partial-state recovery is a Phase 4.4 problem.

- **[PHPStan level 5 + Twig template typing]** → Twig is untyped and not analyzed by PHPStan. Controllers
  must still be level-5-clean and pass `$variables` arrays through PHPDoc shape annotations to keep the
  static-analysis surface honest. Existing `ConfigurationController` does this; mirror the pattern.

## Open Questions

None blocking. (`listMannequinModels()` shape will be confirmed against the live `/models` response during
task #2 — DTO is straightforward, mirror of `Scenery`.)

The split-vs-single-change decision was settled in the proposal phase: single change.
