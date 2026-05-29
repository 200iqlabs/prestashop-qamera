<?php

declare(strict_types=1);

namespace QameraAi\Module\Sync;

use Configuration;
use Context;
use Db;
use QameraAi\Module\Api\Dto\ImageResponse;
use QameraAi\Module\Api\Dto\ProductMetadata;
use QameraAi\Module\Api\Dto\RegisterImageRequest;
use QameraAi\Module\Api\Exception\AuthException;
use QameraAi\Module\Api\Exception\NotFoundException;
use QameraAi\Module\Api\Exception\RateLimitException;
use QameraAi\Module\Api\Exception\ServerException;
use QameraAi\Module\Api\Exception\TransportException;
use QameraAi\Module\Api\Exception\ValidationException;
use QameraAi\Module\Api\QameraApiClient;
use Throwable;

/**
 * Orchestrates the upstream sync triggered by the `actionWatermark`
 * PrestaShop hook. Reads the bookkeeping row written in Phase 2,
 * uploads the primary image via the configured strategy, calls
 * `POST /images` with or without `product_metadata` depending on the
 * row's current state, and persists the result.
 *
 * The service never throws — every failure is caught and recorded on
 * the bookkeeping row as `status='error'` with a sanitized
 * `last_error_message`. The hook layer relies on this to keep the BO
 * "Save product" action successful regardless of upstream state.
 */
class ProductImageSyncService
{
    private const ERROR_MAX = 500;

    public function __construct(
        private readonly Db $db,
        private readonly string $tablePrefix,
        private readonly ProductRefBuilder $refBuilder,
        private readonly QameraApiClient $apiClient,
        private readonly ImageUploadStrategy $uploadStrategy,
        private readonly PrimaryImageResolver $resolver,
        private readonly PrestaShopLoggerWrapper $logger,
        private readonly InMemoryDedupCache $dedupCache,
    ) {
    }

    public function syncOnImageAdded(int $idProduct, int $idImage): void
    {
        if (!(bool) Configuration::get('QAMERAAI_AUTO_REGISTER_PRODUCTS')) {
            return;
        }

        if ($this->dedupCache->seen($idProduct . ':' . $idImage)) {
            return;
        }

        $idShop = $this->resolveCurrentShopId();
        $row = $this->loadBookkeepingRow($idProduct, $idShop);
        if ($row === null) {
            $this->logger->addLog(
                sprintf(
                    '[QameraAi] no bookkeeping row for id_product=%d, id_shop=%d; '
                    . 'skipping image sync. Next actionProductSave will create the row.',
                    $idProduct,
                    $idShop
                ),
                1,
                null,
                'QameraAiModule',
                $idProduct,
                true
            );
            return;
        }

        $status = (string) ($row['status'] ?? 'pending');
        $isRegistered = ($status === 'registered'
            && isset($row['qamera_product_id'])
            && $row['qamera_product_id'] !== null
            && (string) $row['qamera_product_id'] !== '');

        // D2: a re-sync of an already-registered product with a stored asset
        // is a no-op. `registerImage` is idempotent on the deterministic
        // external_ref, so re-uploading only mints a fresh asset that upstream
        // ignores, drifting `qamera_asset_id` away from the catalog (the
        // divergence proved in the 2026-05-29 smoke). Skip entirely; the
        // AnalysisStatusRefresher keeps `qamera_asset_id` reconciled. A
        // registered row WITHOUT an asset falls through to re-register (recovery).
        if ($isRegistered && (string) ($row['qamera_asset_id'] ?? '') !== '') {
            $this->logger->addLog(
                sprintf(
                    '[QameraAi] id_product=%d already registered with a stored asset; '
                    . 'skipping re-sync upload to avoid orphaning the catalog asset.',
                    $idProduct
                ),
                1,
                null,
                'QameraAiModule',
                $idProduct,
                true
            );
            return;
        }

        $imageToUpload = $isRegistered
            ? $idImage
            : $this->resolver->resolve(
                $idProduct,
                $idImage,
                $this->resolveDefaultLangForShop($idShop)
            );

        if ($imageToUpload === null) {
            $this->logger->addLog(
                sprintf(
                    '[QameraAi] no resolvable primary image for id_product=%d; '
                    . 'skipping image sync (row status unchanged).',
                    $idProduct
                ),
                1,
                null,
                'QameraAiModule',
                $idProduct,
                true
            );
            return;
        }

        try {
            $localPath = $this->resolveImagePath($imageToUpload);
            $filename = $this->resolveFilename($localPath);
            $contentType = $this->resolveContentType($localPath);
            $sizeBytes = $this->resolveSizeBytes($localPath);
            $assetId = $this->uploadStrategy->uploadImage(
                $localPath,
                $filename,
                $contentType,
                $sizeBytes
            );

            $productRef = $this->refBuilder->build($idShop, $idProduct);
            $externalRef = sprintf('%s:image:%d', $productRef, $imageToUpload);
            $metadata = $isRegistered ? null : $this->buildMetadataFromRow($row);

            $request = new RegisterImageRequest($externalRef, $productRef, $assetId, $metadata);
            $response = $this->apiClient->registerImage($request);

            $this->persistSuccess($idProduct, $idShop, $isRegistered, $response, $assetId);
        } catch (Throwable $e) {
            $this->persistError($idProduct, $idShop, $this->mapExceptionToLastError($e));
        }
    }

    protected function resolveFilename(string $localPath): string
    {
        $base = basename($localPath);
        return $base !== '' ? $base : 'image.jpg';
    }

    protected function resolveContentType(string $localPath): string
    {
        if (function_exists('mime_content_type')) {
            $detected = @mime_content_type($localPath);
            if (is_string($detected) && $detected !== '') {
                return $detected;
            }
        }
        return 'image/jpeg';
    }

    protected function resolveSizeBytes(string $localPath): int
    {
        $size = @filesize($localPath);
        return is_int($size) && $size > 0 ? $size : 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadBookkeepingRow(int $idProduct, int $idShop): ?array
    {
        $sql = sprintf(
            'SELECT `status`, `qamera_product_id`, `qamera_asset_id`, `display_name_snapshot`, '
            . '`sku_snapshot`, `description_snapshot` '
            . 'FROM `%sqamera_product_link` '
            . 'WHERE `id_product` = %d AND `id_shop` = %d',
            $this->tablePrefix,
            $idProduct,
            $idShop
        );
        $row = $this->db->getRow($sql);
        if ($row === false || $row === null || $row === []) {
            return null;
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function buildMetadataFromRow(array $row): ProductMetadata
    {
        $name = (string) ($row['display_name_snapshot'] ?? '');
        $sku = $row['sku_snapshot'] ?? null;
        $description = $row['description_snapshot'] ?? null;

        return new ProductMetadata(
            $name,
            is_string($sku) && $sku !== '' ? $sku : null,
            is_string($description) && $description !== '' ? $description : null,
        );
    }

    private function persistSuccess(
        int $idProduct,
        int $idShop,
        bool $isRegistered,
        ImageResponse $response,
        string $assetId
    ): void {
        // `qamera_asset_id` always updates on a successful registerImage
        // — both the cascade-create AND re-sync paths PUT a fresh upload
        // and mint a fresh storage `asset_id` (the value `requestUpload()`
        // returned and the client PUT to), which the Phase-4.3 BO needs to
        // feed `Subject.packshot_asset_id` on the next generate-job submit.
        // The logical `ImageResponse.imageId` is deliberately NOT persisted
        // — it does not resolve a source upload upstream.
        $assetIdSql = $assetId !== ''
            ? sprintf("'%s'", $this->escape($assetId))
            : 'NULL';

        if ($isRegistered) {
            // Already-registered path: bump heartbeat AND refresh the
            // asset id so re-syncs propagate after a manual image
            // replace in PS BO.
            $sql = sprintf(
                'UPDATE `%sqamera_product_link` '
                . 'SET `last_synced_at` = NOW(), `updated_at` = NOW(), '
                . '`qamera_asset_id` = %s '
                . 'WHERE `id_product` = %d AND `id_shop` = %d',
                $this->tablePrefix,
                $assetIdSql,
                $idProduct,
                $idShop
            );
            $this->db->execute($sql);
            return;
        }

        $productId = $response->productId;
        if ($productId === '') {
            // Upstream did not return a product_id despite cascade-create
            // input — record the row as an error rather than silently
            // marking it registered with a NULL qamera_product_id.
            $this->persistError(
                $idProduct,
                $idShop,
                'Upstream did not return product_id after cascade-create request.'
            );
            return;
        }

        $sql = sprintf(
            'UPDATE `%sqamera_product_link` '
            . "SET `status` = 'registered', `qamera_product_id` = '%s', "
            . '`qamera_asset_id` = %s, '
            . '`last_error_message` = NULL, `last_synced_at` = NOW(), '
            . '`updated_at` = NOW() '
            . 'WHERE `id_product` = %d AND `id_shop` = %d',
            $this->tablePrefix,
            $this->escape($productId),
            $assetIdSql,
            $idProduct,
            $idShop
        );
        $this->db->execute($sql);
    }

    private function persistError(int $idProduct, int $idShop, string $message): void
    {
        $sql = sprintf(
            'UPDATE `%sqamera_product_link` '
            . "SET `status` = 'error', `last_error_message` = '%s', "
            . '`last_synced_at` = NOW(), `updated_at` = NOW() '
            . 'WHERE `id_product` = %d AND `id_shop` = %d',
            $this->tablePrefix,
            $this->escape($message),
            $idProduct,
            $idShop
        );
        $this->db->execute($sql);
    }

    private function mapExceptionToLastError(Throwable $e): string
    {
        $message = match (true) {
            $e instanceof ValidationException => 'Upstream validation: ' . $e->getMessage(),
            $e instanceof AuthException =>
                'API credentials invalid (HTTP 401). Check API key in module configuration.',
            $e instanceof NotFoundException =>
                'Upstream returned 404. Possible causes: (a) installation inactive — check '
                . 'status in Qamera AI panel; (b) product_ref not found upstream and '
                . 'product_metadata was omitted from the request (expected only on the '
                . 'registered → registered path).',
            $e instanceof RateLimitException =>
                'Rate limit exceeded — try again later. (HTTP 429)',
            $e instanceof ServerException =>
                'Upstream server error (HTTP 5xx) after retries. Try again later.',
            $e instanceof TransportException =>
                'Network error reaching Qamera AI: ' . $e->getMessage(),
            default => 'Unexpected: ' . get_class($e) . ': ' . $e->getMessage(),
        };

        return $this->truncate($message, self::ERROR_MAX);
    }

    /**
     * Resolves the absolute filesystem path of the image bytes that the
     * upload strategy will PUT to upstream. Uses PrestaShop's
     * `_PS_PRODUCT_IMG_DIR_` plus the standard `Image::getImgFolder` layout
     * (`p/4/2/42.jpg`). Tests subclass this service to inject a stub
     * path so they do not depend on the PS image-folder layout.
     */
    protected function resolveImagePath(int $idImage): string
    {
        $base = defined('_PS_PRODUCT_IMG_DIR_') ? (string) constant('_PS_PRODUCT_IMG_DIR_') : '';
        $folder = self::buildImageFolder($idImage);
        return $base . $folder . $idImage . '.jpg';
    }

    private static function buildImageFolder(int $idImage): string
    {
        // PrestaShop splits image ids into one-digit-per-directory.
        // e.g. id=42 → "4/2/", id=1234 → "1/2/3/4/".
        $chars = (string) $idImage;
        $folder = '';
        $len = strlen($chars);
        for ($i = 0; $i < $len; $i++) {
            $folder .= $chars[$i] . '/';
        }
        return $folder;
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
}
