## Why

The module currently syncs products to Qamera only via PrestaShop hooks: `actionProductSave` (on PS BO save) and `actionWatermark` (on watermark re-apply). Products that existed in the catalog before the module was installed never fire these hooks and therefore never appear in `ps_qamera_product_link`. There is no operator-facing way to bulk-register pre-existing inventory — the only workaround today is editing every product and re-saving it, one by one. For any store with non-trivial catalog size this is a hard adoption blocker: install the module, see an empty Qamera grid, conclude it does not work, uninstall.

Surfaced explicitly by the operator on 2026-05-28: "co z tymi produktami które nie zostały jeszcze dodane do nas do platformy — zostały dodane do presty a nie do qamera, czy w qamera tylko pojawiają się nowe zdjęcia — nie da się dodać starych?"

## What Changes

- New BO action "Sync all products" (or "Sync selected products" with the existing grid bulk-select) on the Qamera Products tab. Idempotent — re-running skips already-synced rows.
- New service `BulkProductSyncRunner` that iterates PS products in batches (configurable batch size, default 25), calls the existing `ProductImageSyncService` per product, and aggregates results into a per-batch summary surfaced to the operator (succeeded / skipped-already-synced / failed-with-message).
- Job-style execution: the bulk run lands in a server-side queue / background job so the operator's HTTP request returns immediately with a job id; a "Bulk sync status" panel polls progress and surfaces per-product errors. Decision between true background worker (cron) vs `register_shutdown_function` streaming vs synchronous chunked AJAX = design.md.
- Rate-limit / pacing: respect upstream `/products` and `/images` API per-second budget (see `qamera-api-client` retry config); fail soft on `429` and re-enqueue with backoff.
- Filter UI on the products grid: "Show only un-synced" / "Show only synced" / "All" so the operator can scope the bulk action.
- CLI / WP-CLI-equivalent entry point (PS console command if PS exposes one, otherwise a thin `bin/` script callable from the dev container) so large-catalog operators can trigger the run from shell.

## Capabilities

### New Capabilities

- `bulk-product-sync`: orchestration, batching, progress reporting, and resume-on-failure semantics for catalog-scale registration.

### Modified Capabilities

- `qamera-bo-ui`: products grid gains "Sync all" button + sync-state filter; new "Bulk sync status" panel.
- `product-image-sync`: existing per-product service stays as-is but gains an explicit "skip if already synced and content unchanged" fast path that the bulk runner consumes per row.

## Impact

- **Code**: new `src/Sync/BulkProductSyncRunner.php`, new BO controller + Twig + JS for the status panel, optional CLI entry point under `bin/`.
- **Schema**: new `ps_qamera_bulk_sync_run` table tracking run id / started_at / finished_at / total / succeeded / failed / status (`queued` / `running` / `finished` / `cancelled`) and a `ps_qamera_bulk_sync_run_item` per-row table for diagnostics on failures.
- **Operator workflow**: install module on existing store → click "Sync all" → status panel reports progress → operator can navigate away and come back.
- **Performance**: catalogs of 10k+ products need to run for hours; the design MUST handle PHP request timeouts (the synchronous-AJAX path is unacceptable for these sizes).
- **Out of scope**: incremental delta sync (catch-up after the module was uninstalled+reinstalled — operator re-runs full bulk sync), multistore-aware per-shop batching (single-shop iteration in v1; OQ-PS multistore marker still in effect), reverse sync (Qamera → PS).
