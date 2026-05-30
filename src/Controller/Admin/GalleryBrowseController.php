<?php

declare(strict_types=1);

namespace QameraAi\Module\Controller\Admin;

use Context;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use QameraAi\Module\Api\Exception\ApiException;
use QameraAi\Module\Api\Exception\NotFoundException;
use QameraAi\Module\Api\QameraApiClient;
use QameraAi\Module\Gallery\Browse\BrowseViewPresenter;
use QameraAi\Module\Gallery\Browse\ProductImageBrowseAssembler;
use QameraAi\Module\Gallery\Browse\SessionImageResolver;
use QameraAi\Module\Gallery\Browse\Thumbnail;
use QameraAi\Module\Gallery\Browse\ThumbnailSourcer;
use QameraAi\Module\Packshot\Output\ImportResultPresenter;
use QameraAi\Module\Packshot\Output\OutputImporter;
use QameraAi\Module\Sync\ProductRefBuilder;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * BO AJAX endpoints for the product-detail Qamera tab browse accordion
 * (gallery-image-ingest §5.2): the initial per-image assembly, the lazy
 * session-image walk on row expand, and the per-output add-to-gallery import.
 * Thin shell over the unit-tested assembler / resolver / importer services.
 */
final class GalleryBrowseController extends FrameworkBundleAdminController
{
    public function browseAction(
        Request $request,
        int $idProduct,
        QameraApiClient $api,
        ProductRefBuilder $refBuilder,
        ProductImageBrowseAssembler $assembler,
        ThumbnailSourcer $sourcer,
        BrowseViewPresenter $presenter
    ): JsonResponse {
        $productRef = $refBuilder->build($this->resolveShopId(), $idProduct);

        try {
            $product = $api->getProduct($productRef);
        } catch (NotFoundException $e) {
            return new JsonResponse(['ok' => true] + $presenter->presentNotFound());
        } catch (ApiException $e) {
            return new JsonResponse(['ok' => false, 'error_code' => 'api_error'], Response::HTTP_BAD_GATEWAY);
        }

        $view = $assembler->assemble($product);
        $sourcer->applyTo($view);

        return new JsonResponse(['ok' => true] + $presenter->present($view, $this->thumbResolver()));
    }

    public function sessionsAction(
        Request $request,
        int $idProduct,
        string $imageId,
        QameraApiClient $api,
        ProductRefBuilder $refBuilder,
        SessionImageResolver $resolver
    ): JsonResponse {
        $productRef = $refBuilder->build($this->resolveShopId(), $idProduct);

        try {
            $product = $api->getProduct($productRef);
            // The jobs walk needs plugin.jobs:read; a key without it (or any
            // other upstream error) must degrade to "no sessions + notice",
            // never an uncaught 500 that the UI shows as a hard failure.
            $walk = $resolver->resolve($productRef, $product->packshots);
        } catch (NotFoundException $e) {
            return new JsonResponse(['ok' => true, 'sessions' => [], 'sessions_truncated' => false]);
        } catch (ApiException $e) {
            return new JsonResponse([
                'ok' => true,
                'sessions' => [],
                'sessions_truncated' => false,
                'sessions_error' => true,
            ]);
        }

        $sessions = [];
        foreach ($walk->sessions as $session) {
            if ($session->imageId !== $imageId) {
                continue;
            }
            $sessions[] = [
                'image_id' => $session->imageId,
                'job_id' => $session->jobId,
                'output_index' => $session->outputIndex,
                'url' => $session->url,
                'importable' => true,
            ];
        }

        return new JsonResponse([
            'ok' => true,
            'sessions' => $sessions,
            'sessions_truncated' => $walk->capHit,
        ]);
    }

    public function importOutputAction(
        Request $request,
        int $idProduct,
        OutputImporter $importer,
        ImportResultPresenter $presenter
    ): JsonResponse {
        if (!$this->isCsrfTokenValid('qamera_gallery_import', (string) $request->request->get('_token'))) {
            return new JsonResponse(
                ['ok' => false, 'state' => 'aborted', 'reason' => 'invalid_csrf'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $jobId = trim((string) $request->request->get('job_id'));
        $outputIndex = (int) $request->request->get('output_index');
        if ($jobId === '' || $outputIndex < 0) {
            return new JsonResponse(
                ['ok' => false, 'state' => 'aborted', 'reason' => 'bad_request'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $result = $importer->importOutput($jobId, $outputIndex);
        $payload = $presenter->present($result);

        return new JsonResponse($payload['json'], $payload['status']);
    }

    /**
     * Renders a {@see Thumbnail} descriptor to a concrete URL: a signed job
     * output passes through; a PS image id resolves to its local thumbnail
     * via the PS image link; a placeholder yields null (the JS shows the
     * labelled placeholder).
     *
     * @return callable(?Thumbnail):?string
     */
    private function thumbResolver(): callable
    {
        $link = Context::getContext()->link;

        return static function (?Thumbnail $thumbnail) use ($link): ?string {
            if ($thumbnail === null) {
                return null;
            }
            if ($thumbnail->kind === Thumbnail::KIND_URL) {
                return $thumbnail->value;
            }
            if ($thumbnail->kind === Thumbnail::KIND_PS_IMAGE && $link !== null) {
                return $link->getImageLink('qamera', (int) $thumbnail->value, 'home_default');
            }

            return null;
        };
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
