<?php

declare(strict_types=1);

namespace QameraAi\Module\Controller\Admin;

use Context;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Product;
use QameraAi\Module\Api\Dto\ProductMetadata;
use QameraAi\Module\Api\Exception\ApiException;
use QameraAi\Module\Api\Exception\NotFoundException;
use QameraAi\Module\Api\QameraApiClient;
use QameraAi\Module\Gallery\GalleryIngestOrchestrator;
use QameraAi\Module\Gallery\IngestItem;
use QameraAi\Module\Sync\ProductRefBuilder;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * BO AJAX endpoints for the product-detail Qamera tab ingest picker
 * (gallery-image-ingest §5.1 / §5.3). Thin shell: validate CSRF + inputs,
 * delegate to {@see GalleryIngestOrchestrator} (one item per call) and the
 * status poll to `getProduct`. All ingest logic lives in the unit-tested
 * service layer.
 */
final class GalleryIngestController extends FrameworkBundleAdminController
{
    public function ingestAction(
        Request $request,
        int $idProduct,
        GalleryIngestOrchestrator $orchestrator
    ): JsonResponse {
        if (!$this->isCsrfTokenValid('qamera_gallery_ingest', (string) $request->request->get('_token'))) {
            return new JsonResponse(['ok' => false, 'error_code' => 'invalid_csrf'], Response::HTTP_BAD_REQUEST);
        }

        $psImageId = (int) $request->request->get('id_image');
        $action = (string) $request->request->get('mode');
        if ($psImageId <= 0 || !in_array($action, [IngestItem::ACTION_PRODUCT, IngestItem::ACTION_PACKSHOT], true)) {
            return new JsonResponse(['ok' => false, 'error_code' => 'bad_request'], Response::HTTP_BAD_REQUEST);
        }

        $idShop = $this->resolveShopId();
        $item = new IngestItem($idShop, $idProduct, $psImageId, $action, $this->buildMetadata($idProduct));
        $result = $orchestrator->ingest($item);

        return new JsonResponse([
            'ok' => !$result->isError(),
            'id_image' => $psImageId,
            'status' => $result->status,
            'image_ref' => $result->imageRef,
            'packshot_ref' => $result->packshotRef,
            'error_code' => $result->errorCode,
            'error_message' => $result->errorMessage,
            'retryable' => $result->retryable,
        ]);
    }

    public function statusAction(
        Request $request,
        int $idProduct,
        QameraApiClient $api,
        ProductRefBuilder $refBuilder
    ): JsonResponse {
        $productRef = $refBuilder->build($this->resolveShopId(), $idProduct);

        try {
            $product = $api->getProduct($productRef);
        } catch (NotFoundException $e) {
            return new JsonResponse(['ok' => true, 'found' => false, 'images' => []]);
        } catch (ApiException $e) {
            return new JsonResponse(['ok' => false, 'error_code' => 'api_error'], Response::HTTP_BAD_GATEWAY);
        }

        $images = [];
        foreach ($product->images as $image) {
            $images[] = [
                'image_id' => $image->id,
                'external_ref' => $image->externalRef,
                'analysis_status' => $image->analysisStatus,
            ];
        }

        $response = new JsonResponse(['ok' => true, 'found' => true, 'images' => $images]);
        $response->setPrivate();
        $response->setMaxAge(5);

        return $response;
    }

    private function buildMetadata(int $idProduct): ?ProductMetadata
    {
        try {
            $idLang = (int) (Context::getContext()->language->id ?? 1);
            $product = new Product($idProduct, false, $idLang > 0 ? $idLang : 1);
            $name = is_array($product->name) ? (string) reset($product->name) : (string) $product->name;
            if ($name === '') {
                return null;
            }
            $description = is_array($product->description_short)
                ? (string) reset($product->description_short)
                : (string) $product->description_short;

            return new ProductMetadata(
                $name,
                $product->reference !== '' ? (string) $product->reference : null,
                $description !== '' ? $description : null,
            );
        } catch (Throwable $e) {
            return null;
        }
    }

    private function resolveShopId(): int
    {
        $context = Context::getContext();
        if ($context->shop !== null && isset($context->shop->id)) {
            return (int) $context->shop->id;
        }

        return 1;
    }
}
