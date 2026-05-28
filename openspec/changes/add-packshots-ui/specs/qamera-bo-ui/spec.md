## ADDED Requirements

### Requirement: Products grid lists synced rows with bulk-select and generate actions

A BO controller `ProductsGridController` SHALL render at `GET /modules/qameraai/products` a paginated grid
of `ps_qamera_product_link` rows joined with `ps_product_lang` for the localised product name. Columns
SHALL include: checkbox (bulk-select), thumbnail, product name, sync status, last_synced_at, per-row
"Generate" action button.

Rows whose `qamera_image_id IS NULL` SHALL render with the "Generate" action disabled and a hover hint
"Sync this product first". Bulk actions on a selection containing any unsynced row SHALL exclude those rows
silently (with a flash message noting the count excluded).

#### Scenario: Synced row shows enabled Generate button

- **GIVEN** a `ps_qamera_product_link` row with non-NULL `qamera_image_id` and `status='synced'`
- **WHEN** the grid renders
- **THEN** the row's "Generate" button is enabled and links to the generate form pre-seeded with this product

#### Scenario: Unsynced row shows disabled Generate button with hint

- **GIVEN** a row with `qamera_image_id IS NULL`
- **WHEN** the grid renders
- **THEN** the "Generate" button is disabled
- **AND** the row carries an accessible title attribute "Sync this product first"

#### Scenario: Bulk action filters out unsynced selection

- **GIVEN** the operator bulk-selects 5 rows, 2 of which are unsynced
- **WHEN** they click "Generate packshots"
- **THEN** the generate form opens with 3 subjects (only synced rows)
- **AND** a flash-info shows "2 products excluded — sync required first"

### Requirement: Generate form posts session_config + subjects to the submitter

A controller `GenerateFormController` SHALL render a form (modal or full page) that collects:

- One subject per selected product (pre-populated, MVP does not split a product into multiple subjects)
- `ai_model` dropdown — required — sourced from cached `/ai-models`
- `scenery_id` dropdown — optional — sourced from cached `/sceneries`, filters out `status='archived'`
- `model_id` (mannequin) dropdown — optional — sourced from cached `/models`
- `preset_id` dropdown — optional — sourced from cached `/presets`, shows `credit_cost` as label suffix
- `aspect_ratio` radio/dropdown — required — sourced from cached `/aspect-ratios`, default = entry where
  `default=true`
- `images_count` number input — required, range 1–50, default 4
- `suggestions` textarea — optional, max 2000 chars
- Live pre-flight cost display — updates client-side on every field change via the cost calculator

On `POST`, the controller SHALL validate `ai_model` is present (server-side, even if JS disabled the
submit button) and `subjects.length <= 100` (chunking handled by submitter), then call
`PackshotJobSubmitter::submit()`. On success it SHALL flash a success message naming the order_id(s) and
redirect to the jobs history page. On `ApiValidationException`, it SHALL re-render the form with
field-level error messages and NO redirect. On other API exceptions it SHALL flash a generic error and
re-render the form with inputs preserved.

#### Scenario: Successful submission redirects to jobs history

- **GIVEN** a valid form post with 1 subject and `ai_model` selected
- **WHEN** the submitter returns successfully
- **THEN** the response is a 302 to `/modules/qameraai/jobs`
- **AND** a flash-success names the new `order_id`

#### Scenario: Missing ai_model server-side returns 200 with form error

- **GIVEN** a form post without `ai_model` (client-side validation bypassed)
- **WHEN** the controller validates input
- **THEN** the response is the form re-rendered with an error on the `ai_model` field
- **AND** no call is made to `QameraApiClient::submitJob()`

#### Scenario: ApiValidationException surfaces field-level errors

- **GIVEN** the API returns 422 with `errors: [{field:'images_count', message:'must be ≤ 50'}]`
- **WHEN** the controller catches `ApiValidationException`
- **THEN** the form re-renders with the upstream message attached to the `images_count` field

### Requirement: Jobs history lists local rows with status filter and cursor pagination

A controller `JobsHistoryController` SHALL render at `GET /modules/qameraai/jobs` a paginated list of
`ps_qamera_packshot_job` rows joined with the product name. The list SHALL be filterable by status (one of
`pending|in_progress|completed|failed|cancelled` or `all`), default sort `submitted_at DESC`. Columns SHALL
include: submitted_at, product name, status badge, ai_model, aspect_ratio, output thumbnail (when
`output_url` is set), last_error_message snippet (when set).

A per-row "Refresh" button SHALL call `QameraApiClient::getJob($qameraJobId)` and update the local row.
A per-row "Re-mint URL" button SHALL appear when `output_url_expires_at` is in the past, and call
`QameraApiClient::getJobRefreshUrl()` (out of scope for MVP — button hidden in MVP, requirement reserved
for Phase 4.4).

#### Scenario: Default page shows all statuses sorted newest first

- **WHEN** the operator opens `/modules/qameraai/jobs` with no filter
- **THEN** the grid lists rows from `ps_qamera_packshot_job` ordered by `submitted_at DESC`
- **AND** the status filter dropdown is set to "All"

#### Scenario: Status filter narrows the list

- **GIVEN** the operator selects status=`failed` from the filter
- **THEN** only rows with `status='failed'` appear
- **AND** `last_error_message` is rendered for each

### Requirement: BO templates use Twig and translation domain Modules.Qameraai.Admin

All BO templates for this change SHALL be Twig (`.html.twig`), located under `views/templates/admin/`. All
operator-visible strings SHALL go through Symfony's `trans` filter with domain `Modules.Qameraai.Admin`. PL
is the primary translation; EN is the fallback. No hardcoded operator-language strings in PHP or Twig.

#### Scenario: All BO strings are translatable

- **WHEN** any template renders
- **THEN** every operator-visible string passes through `{{ '...' | trans({}, 'Modules.Qameraai.Admin') }}`
  or the Symfony equivalent

### Requirement: BO client-side code uses vanilla JS + jQuery + Bootstrap 4 only

JavaScript for the generate form (cost recalc, dynamic subject disable, max-subjects guard) SHALL be plain
ES5/ES6 in `views/js/generate_form.js`, loaded via the BO controller. The module SHALL NOT introduce any
build step, NPM dependency, React, Vue, or other framework. jQuery and Bootstrap 4 are available globally in
PS admin and may be used.

#### Scenario: No node_modules or build artifacts in repo

- **WHEN** the change ships
- **THEN** the repository contains no `package.json`, `node_modules/`, or bundled JS artifacts
- **AND** all JS sits as readable source files under `views/js/`
