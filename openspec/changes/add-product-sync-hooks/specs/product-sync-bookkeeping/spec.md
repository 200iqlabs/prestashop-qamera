## ADDED Requirements

### Requirement: Hook actionProductAdd records a pending bookkeeping row when auto-register is enabled

When the `actionProductAdd` PrestaShop hook fires and `Configuration::get('QAMERAAI_AUTO_REGISTER_PRODUCTS')` evaluates truthy, the module SHALL insert a row into `ps_qamera_product_link` capturing the new product's identity (`id_product`, `id_shop`), a deterministic `qamera_product_ref` derived from `(id_shop, id_product)`, and a snapshot of the product's metadata (`display_name`, optional `sku`, optional `description`) read in the shop's default language. The row's `status` SHALL be `'pending'` and `qamera_product_id` SHALL be `NULL` — no call is made to the Qamera AI Plugin API at this stage. When the toggle evaluates falsy, the hook SHALL be a no-op.

#### Scenario: Toggle on, new product created

- **WHEN** an administrator with `QAMERAAI_AUTO_REGISTER_PRODUCTS=1` creates a new product in the back office (shop context `id_shop=1`, product saved with `id_product=42`, name "Widget", reference "WDG-001")
- **THEN** a single row exists in `ps_qamera_product_link` with `id_product=42`, `id_shop=1`, `qamera_product_ref='ps:1:42'`, `display_name_snapshot='Widget'`, `sku_snapshot='WDG-001'`, `status='pending'`, `qamera_product_id=NULL`, and `created_at`/`updated_at` set to the time of the hook

#### Scenario: Toggle off, new product created

- **WHEN** an administrator with `QAMERAAI_AUTO_REGISTER_PRODUCTS=0` creates a new product
- **THEN** no row is inserted into `ps_qamera_product_link` for that product

#### Scenario: Product without SKU or short description

- **WHEN** a product is created with an empty `reference` and empty `description_short` in the default language
- **THEN** the row is inserted with `sku_snapshot=NULL` and `description_snapshot=NULL`; `display_name_snapshot` is still populated and the row is otherwise complete

#### Scenario: Description snapshot honors upstream length limit

- **WHEN** a product is created with a `description_short` exceeding 5000 characters in the default language
- **THEN** `description_snapshot` is stored truncated to 5000 characters, matching the upstream `ProductMetadataSchema.description.max(5000)` constraint

### Requirement: Hook actionProductUpdate refreshes the snapshot without disturbing sync state

When the `actionProductUpdate` PrestaShop hook fires and `Configuration::get('QAMERAAI_AUTO_REGISTER_PRODUCTS')` evaluates truthy, the module SHALL upsert the bookkeeping row for that `(id_product, id_shop)`: an existing row has its `display_name_snapshot`, `sku_snapshot`, `description_snapshot`, and `updated_at` columns refreshed from the current `Product` state, while `status`, `qamera_product_id`, `last_error_message`, and `last_synced_at` are preserved. When no row exists yet (e.g. the toggle was off at create time and toggled on later), a fresh `status='pending'` row is inserted exactly as `actionProductAdd` would do.

#### Scenario: Update of a registered product refreshes only metadata

- **GIVEN** a bookkeeping row exists with `id_product=42`, `id_shop=1`, `status='registered'`, `qamera_product_id='4f8a…uuid'`, `display_name_snapshot='Widget'`
- **WHEN** the administrator renames the product to "Widget Pro" and saves
- **THEN** the row's `display_name_snapshot` becomes `'Widget Pro'`, `updated_at` is bumped, and `status`, `qamera_product_id`, `last_synced_at` retain their previous values

#### Scenario: Update of a product in error state retains the error

- **GIVEN** a bookkeeping row exists with `status='error'`, `last_error_message='display_name exceeds 500 chars'`
- **WHEN** the administrator shortens the name and saves
- **THEN** `display_name_snapshot` is refreshed, `updated_at` is bumped, and `status` remains `'error'` with `last_error_message` unchanged

#### Scenario: Update of a product that has no row yet inserts a fresh pending row

- **WHEN** the administrator updates a product (`id_product=99`, `id_shop=1`) for which no row exists (toggle was off at create time)
- **THEN** a new row is inserted with `qamera_product_ref='ps:1:99'`, `status='pending'`, `qamera_product_id=NULL`, identical to the result of an `actionProductAdd` call

### Requirement: Bookkeeping failures never block the PrestaShop product save

The hooks SHALL catch every `\Throwable` raised by the snapshot writer (DB connectivity loss, schema mismatch, anything) and SHALL log it via `PrestaShopLogger::addLog` at severity 2 with the context label `'QameraAi-Module'` and `id_object` set to `id_product`. The hook SHALL return normally after logging — the back-office "Save product" action MUST always succeed from the operator's point of view, regardless of bookkeeping state.

#### Scenario: Database temporarily unavailable

- **WHEN** the MySQL connection drops mid-hook and the `INSERT … ON DUPLICATE KEY UPDATE` query throws a `PrestaShopDatabaseException`
- **THEN** the hook catches the exception, writes a severity-2 entry to PS logs with the product ID, and returns void; the PrestaShop product save completes normally and the operator sees a successful save in the BO

#### Scenario: Snapshot writer not registered in container

- **GIVEN** a misconfiguration where the service container fails to resolve `ProductSnapshotWriter`
- **WHEN** the hook fires
- **THEN** the hook catches the resolution exception, logs it, and returns void — the product save still completes

### Requirement: qamera_product_ref is deterministic and formatted as "ps:{id_shop}:{id_product}"

The module SHALL compute `qamera_product_ref` from `(id_shop, id_product)` using the format `"ps:{id_shop}:{id_product}"` (literal prefix `ps:`, two colon separators, decimal integer IDs). The ref SHALL be stable across re-installs and reads — the same `(id_shop, id_product)` pair SHALL produce byte-identical refs on every call. The ref SHALL never exceed 200 characters (upstream `ProductRefSchema` limit) and SHALL contain only ASCII characters in `[a-z0-9:]`.

#### Scenario: Typical product in default shop

- **WHEN** `ProductRefBuilder::build(idShop: 1, idProduct: 42)` is called
- **THEN** the result is exactly the string `'ps:1:42'`

#### Scenario: Multi-shop instance

- **WHEN** the same `id_product=42` is bookkept under `id_shop=1` and `id_shop=2`
- **THEN** the two rows have distinct refs `'ps:1:42'` and `'ps:2:42'`, and both rows coexist without violating any UNIQUE constraint

#### Scenario: Invalid shop id rejected

- **WHEN** `ProductRefBuilder::build` is invoked with `idShop=0` or a non-positive integer
- **THEN** the builder raises `InvalidArgumentException` (the snapshot writer surfaces this up — the hook's catch-all logs it; no row is written)

### Requirement: Snapshot writer uses upsert semantics on (id_product, id_shop)

The `ProductSnapshotWriter::upsertFromProduct` operation SHALL execute a single `INSERT … ON DUPLICATE KEY UPDATE` statement keyed on the existing `UNIQUE(id_product, id_shop)` constraint of `ps_qamera_product_link`. The `ON DUPLICATE KEY UPDATE` clause SHALL refresh **only** the snapshot columns (`display_name_snapshot`, `sku_snapshot`, `description_snapshot`) and `updated_at`. The columns `qamera_product_id`, `qamera_product_ref`, `status`, `last_error_message`, `last_synced_at`, and `created_at` SHALL NOT be present in the `UPDATE` clause — they remain whatever value the row carried before, preserving state owned by downstream sync code.

#### Scenario: Race between actionProductAdd and an earlier insert

- **GIVEN** a row already exists for `(id_product=42, id_shop=1)` from a manual SQL backfill, with `status='registered'`, `qamera_product_id='abc…'`
- **WHEN** `actionProductAdd` fires for that product
- **THEN** the upsert behaves as an UPDATE — snapshot columns refresh, `status` stays `'registered'`, `qamera_product_id` stays `'abc…'`, no second row is created

### Requirement: Snapshot reads from the shop's default language

`ProductSnapshotWriter` SHALL read `Product::name` and `Product::description_short` using the language id returned by `Configuration::get('PS_LANG_DEFAULT', null, null, $idShop)`. `Product::reference` is single-string (not per-language) and SHALL be used as-is for `sku_snapshot`. The choice of default-shop language SHALL be stable across admin sessions — i.e. the same product yields the same snapshot regardless of which admin user triggered the hook.

#### Scenario: Admin in English edits a Polish-default-language shop

- **GIVEN** the shop's default language is Polish (`PS_LANG_DEFAULT` for `id_shop=1` resolves to the Polish language id) and the admin is logged in with the English UI
- **WHEN** the admin edits a product whose name is "Widget" in English and "Widżet" in Polish
- **THEN** `display_name_snapshot` is stored as `'Widżet'` (the Polish/default-language value), not `'Widget'`

#### Scenario: Default-language value missing

- **WHEN** a product has no translation in the default language (an edge case in legacy data) but does have one in another language
- **THEN** the writer falls back to the first non-empty language value cast to string, logs a warning via `PrestaShopLogger` at severity 2, and still completes the insert
