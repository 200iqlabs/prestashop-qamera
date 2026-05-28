<?php

declare(strict_types=1);

namespace QameraAi\Module\Controller\Admin;

use Context;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use QameraAi\Module\Packshot\SyncedProductLink;
use QameraAi\Module\Packshot\SyncedProductLinkLookup;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lists synced products with single + bulk "Generate" actions. Rows
 * whose `qamera_image_id IS NULL` render with the action disabled and
 * a hover hint per the `qamera-bo-ui` spec.
 */
final class ProductsGridController extends FrameworkBundleAdminController
{
    private const PAGE_SIZE = 50;

    public function indexAction(
        Request $request,
        SyncedProductLinkLookup $lookup
    ): Response {
        $idShop = $this->resolveShopId();
        $page = max(1, (int) $request->query->get('page', 1));
        $offset = ($page - 1) * self::PAGE_SIZE;

        $rows = $lookup->listForGrid($idShop, self::PAGE_SIZE, $offset);
        $total = $lookup->countForShop($idShop);
        $totalPages = (int) ceil(max(1, $total) / self::PAGE_SIZE);

        return $this->render(
            '@Modules/qameraai/views/templates/admin/products_grid.html.twig',
            [
                'rows' => array_map(static fn (SyncedProductLink $r): array => [
                    'id_link' => $r->idLink,
                    'id_product' => $r->idProduct,
                    'name' => $r->displayNameSnapshot,
                    'product_ref' => $r->qameraProductRef,
                    'qamera_image_id' => $r->qameraImageId,
                    'status' => $r->status,
                    'last_synced_at' => $r->lastSyncedAt,
                    'can_generate' => $r->canGenerate(),
                ], $rows),
                'page' => $page,
                'total_pages' => $totalPages,
                'total' => $total,
                'page_size' => self::PAGE_SIZE,
                'generate_url' => $this->generateUrl('_qameraai_admin_generate_form'),
                'jobs_url' => $this->generateUrl('_qameraai_admin_jobs_history'),
            ]
        );
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
