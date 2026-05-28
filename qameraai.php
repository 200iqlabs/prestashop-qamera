<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

use QameraAi\Module\Install\Installer;
use QameraAi\Module\Sync\ProductImageSyncService;
use QameraAi\Module\Sync\ProductSnapshotWriter;

class QameraAi extends Module
{
    public function __construct()
    {
        $this->name = 'qameraai';
        $this->author = '200iq Labs';
        $this->version = '1.4.0';
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => '9.99.99'];
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->tab = 'administration';

        parent::__construct();

        $this->displayName = $this->trans('Qamera AI', [], 'Modules.Qameraai.Admin');
        $this->description = $this->trans(
            'AI-powered product photography: packshots and full sessions from your store products.',
            [],
            'Modules.Qameraai.Admin'
        );
        $this->confirmUninstall = $this->trans(
            'Are you sure you want to uninstall Qamera AI? Saved API credentials and local product/packshot links will be removed; assets already generated on Qamera AI side are unaffected.',
            [],
            'Modules.Qameraai.Admin'
        );
    }

    public function install(): bool
    {
        if (!parent::install()) {
            return false;
        }

        return (new Installer($this))->install();
    }

    public function uninstall(): bool
    {
        return (new Installer($this))->uninstall() && parent::uninstall();
    }

    public function getContent(): string
    {
        Tools::redirectAdmin(
            $this->context->link->getAdminLink('AdminQameraAiConfiguration')
        );

        return '';
    }

    public function isUsingNewTranslationSystem(): bool
    {
        return true;
    }

    /**
     * Bookkeeping hook for the `actionProductSave` event. Fired by
     * both Product::add() and Product::update() in PS 8/9; this is
     * the primary entry point for capturing newly-created products
     * (the legacy `actionProductAdd` hook is only dispatched by the
     * BO ProductDuplicator in PS 9, so it cannot be relied on for
     * fresh-product bookkeeping). Delegates to the same upsert path
     * as the other product hooks; the writer is idempotent so a
     * Save+Update double-fire during BO edits is harmless.
     *
     * @param array<string, mixed> $params
     */
    public function hookActionProductSave(array $params): void
    {
        $this->writeProductSnapshot($params);
    }

    /**
     * Bookkeeping hook for the `actionProductAdd` event. When
     * `QAMERAAI_AUTO_REGISTER_PRODUCTS` is truthy, records a row in
     * `qamera_product_link` with `status='pending'` and a metadata
     * snapshot of the saved product. No upstream Qamera AI API calls
     * are issued here — the row is consumed by the Phase-3 image-sync
     * change on first packshot/image upload.
     *
     * Any exception from the writer (DB outage, schema mismatch, etc.)
     * is swallowed and logged at severity 2 — the BO "Save product"
     * action MUST always succeed regardless of bookkeeping state.
     *
     * @param array<string, mixed> $params
     */
    public function hookActionProductAdd(array $params): void
    {
        $this->writeProductSnapshot($params);
    }

    /**
     * Bookkeeping hook for the `actionProductUpdate` event. Shares the
     * same code path as `hookActionProductAdd` — the writer's
     * `INSERT … ON DUPLICATE KEY UPDATE` semantics cover both
     * brand-new rows and refreshes of an existing one (without
     * disturbing downstream-owned columns like `status`).
     *
     * @param array<string, mixed> $params
     */
    public function hookActionProductUpdate(array $params): void
    {
        $this->writeProductSnapshot($params);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function writeProductSnapshot(array $params): void
    {
        if (!(bool) Configuration::get('QAMERAAI_AUTO_REGISTER_PRODUCTS')) {
            return;
        }

        $product = $params['product'] ?? null;
        if (!$product instanceof Product) {
            return;
        }

        $idProduct = (int) $product->id;

        try {
            /** @var ProductSnapshotWriter $writer */
            $writer = $this->get(ProductSnapshotWriter::class);
            $writer->upsertFromProduct($product);
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog(
                sprintf(
                    '[QameraAi] product snapshot write failed for id_product=%d: %s: %s',
                    $idProduct,
                    get_class($e),
                    $e->getMessage()
                ),
                2,
                null,
                'QameraAiModule',
                $idProduct > 0 ? $idProduct : null,
                true
            );
        }
    }

    /**
     * `actionWatermark` is fired by PS 8/9 after an image upload for a
     * product (and during BO bulk regenerate flows). Phase 3 uses this
     * as the trigger for upstream product registration via the Qamera
     * AI Plugin API. The handler extracts `id_product` and `id_image`,
     * delegates to `ProductImageSyncService::syncOnImageAdded`, and —
     * matching the Phase-2 swallow-throw contract — catches any
     * `\Throwable` so the BO image upload action always succeeds
     * regardless of upstream state.
     *
     * @param array<string, mixed> $params
     */
    public function hookActionWatermark(array $params): void
    {
        if (!(bool) Configuration::get('QAMERAAI_AUTO_REGISTER_PRODUCTS')) {
            return;
        }

        $idProduct = isset($params['id_product']) ? (int) $params['id_product'] : 0;
        $idImage = isset($params['id_image']) ? (int) $params['id_image'] : 0;
        if ($idProduct <= 0 || $idImage <= 0) {
            return;
        }

        try {
            /** @var ProductImageSyncService $service */
            $service = $this->get(ProductImageSyncService::class);
            $service->syncOnImageAdded($idProduct, $idImage);
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog(
                sprintf(
                    '[QameraAi] image sync failed for id_product=%d, id_image=%d: %s: %s',
                    $idProduct,
                    $idImage,
                    get_class($e),
                    $e->getMessage()
                ),
                2,
                null,
                'QameraAiModule',
                $idProduct,
                true
            );
        }
    }

    /**
     * Stub for the `displayAdminProductsExtra` hook registered by the
     * installer. The real implementation lands in Phase 4 (back-office
     * product page "Qamera AI" tab with packshot generation controls).
     *
     * @param array<string, mixed> $params
     */
    public function hookDisplayAdminProductsExtra(array $params): string
    {
        return '';
    }

    /**
     * Stub for the `displayBackOfficeHeader` hook registered by the
     * installer. The real implementation lands in Phase 4 (injects the
     * back-office CSS/JS bundle on Qamera-related admin screens).
     *
     * @param array<string, mixed> $params
     */
    public function hookDisplayBackOfficeHeader(array $params): void
    {
    }
}
