<?php

declare(strict_types=1);

namespace QameraAi\Module\Sync;

use Configuration;
use Context;
use Db;
use Product;
use Throwable;

/**
 * Writes / refreshes the `qamera_product_link` bookkeeping row for a
 * PrestaShop product. The writer is responsible only for local DB
 * state — it never calls the Qamera AI Plugin API. Network sync lands
 * in Phase 3 (`add-product-image-sync`), which reads the rows produced
 * here on first image upload.
 *
 * The upsert preserves downstream-owned columns (`status`,
 * `qamera_product_id`, `last_error_message`, `last_synced_at`,
 * `qamera_product_ref`, `created_at`) so a previously-registered or
 * errored product is not regressed back to `pending` when the admin
 * resaves it in the back office.
 */
class ProductSnapshotWriter
{
    private const NAME_MAX = 500;
    private const SKU_MAX = 100;
    private const DESCRIPTION_MAX = 5000;

    public function __construct(
        private readonly Db $db,
        private readonly string $tablePrefix,
        private readonly ProductRefBuilder $refBuilder,
        private readonly PrestaShopLoggerWrapper $logger
    ) {
    }

    /**
     * Upsert a snapshot row for the given product. Throws on DB failure
     * — the hook layer is responsible for catching and logging.
     */
    public function upsertFromProduct(Product $product, ?int $idShop = null): void
    {
        $idProduct = (int) $product->id;
        $idShop = $idShop ?? $this->resolveCurrentShopId();
        $idLangDefault = $this->resolveDefaultLangForShop($idShop);

        $ref = $this->refBuilder->build($idShop, $idProduct);

        $displayName = $this->extractDefaultLang($product->name ?? null, $idLangDefault, $idProduct, 'name');
        if ($displayName === null || $displayName === '') {
            // Spec requires display_name_snapshot NOT NULL; fall back to a
            // placeholder so the bookkeeping row still lands. Operators see
            // the warning via the log emitted below.
            $displayName = sprintf('product-%d', $idProduct);
            $this->logger->addLog(
                sprintf(
                    '[QameraAi] product id=%d has no usable name in any language; storing placeholder.',
                    $idProduct
                ),
                2,
                null,
                'QameraAiModule',
                $idProduct,
                true
            );
        }
        $displayName = $this->truncate($displayName, self::NAME_MAX);

        $sku = $this->normalizeReference($product->reference ?? null);
        $description = $this->extractDefaultLang(
            $product->description_short ?? null,
            $idLangDefault,
            $idProduct,
            'description_short'
        );
        if ($description !== null) {
            $description = $this->truncate($description, self::DESCRIPTION_MAX);
            if ($description === '') {
                $description = null;
            }
        }

        $sql = sprintf(
            'INSERT INTO `%sqamera_product_link` '
            . '(`id_product`, `id_shop`, `qamera_product_id`, `qamera_product_ref`, '
            . '`display_name_snapshot`, `sku_snapshot`, `description_snapshot`, '
            . '`status`, `created_at`, `updated_at`) '
            . "VALUES (%d, %d, NULL, '%s', '%s', %s, %s, 'pending', NOW(), NOW()) "
            . 'ON DUPLICATE KEY UPDATE '
            . '`display_name_snapshot` = VALUES(`display_name_snapshot`), '
            . '`sku_snapshot` = VALUES(`sku_snapshot`), '
            . '`description_snapshot` = VALUES(`description_snapshot`), '
            . '`updated_at` = NOW();',
            $this->tablePrefix,
            $idProduct,
            $idShop,
            $this->escape($ref),
            $this->escape($displayName),
            $sku === null ? 'NULL' : "'" . $this->escape($sku) . "'",
            $description === null ? 'NULL' : "'" . $this->escape($description) . "'"
        );

        $this->db->execute($sql);
    }

    /**
     * Reads the value of a (possibly per-language) PrestaShop field. PS
     * exposes translated fields as `array<int, string>` keyed by lang id
     * on hydrated `Product` instances. When the default language entry
     * is missing, we fall back to the first non-empty value and log a
     * warning so operators can clean up legacy data.
     *
     * @param array<int, string>|string|null $field
     */
    private function extractDefaultLang(
        array|string|null $field,
        int $idLangDefault,
        int $idProduct,
        string $fieldName
    ): ?string {
        if ($field === null) {
            return null;
        }
        if (is_string($field)) {
            $trim = trim($field);
            return $trim === '' ? null : $trim;
        }

        if (isset($field[$idLangDefault]) && trim((string) $field[$idLangDefault]) !== '') {
            return trim((string) $field[$idLangDefault]);
        }

        foreach ($field as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $this->logger->addLog(
                    sprintf(
                        '[QameraAi] product id=%d field=%s missing in default lang id=%d; '
                            . 'falling back to first non-empty language.',
                        $idProduct,
                        $fieldName,
                        $idLangDefault
                    ),
                    2,
                    null,
                    'QameraAiModule',
                    $idProduct,
                    true
                );
                return $value;
            }
        }

        return null;
    }

    private function normalizeReference(?string $reference): ?string
    {
        if ($reference === null) {
            return null;
        }
        $reference = trim($reference);
        if ($reference === '') {
            return null;
        }
        return $this->truncate($reference, self::SKU_MAX);
    }

    private function truncate(string $value, int $max): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max);
        }
        return substr($value, 0, $max);
    }

    private function escape(string $value): string
    {
        return $this->db->escape($value, true, true);
    }

    private function resolveCurrentShopId(): int
    {
        $shop = Context::getContext()->shop ?? null;
        return $shop !== null ? (int) $shop->id : 1;
    }

    private function resolveDefaultLangForShop(int $idShop): int
    {
        $value = Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);
        $intValue = (int) $value;
        return $intValue > 0 ? $intValue : 1;
    }
}
