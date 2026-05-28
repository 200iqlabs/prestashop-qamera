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
 *
 * Phase 4.4 (add-analysis-status-surfacing) added an Analysis column
 * with a badge driven by the local cache columns and refreshed
 * client-side by `views/js/products_grid.js` against the per-row
 * status JSON endpoint. The Generate gate hardens to require
 * `analysis_status='described'` (or `'partial'` once multi-image
 * sync lands).
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
                    'disabled_hint' => $r->getDisabledHint(),
                    'analysis_status' => $r->analysisStatus,
                    'analysis_described_count' => $r->analysisDescribedCount,
                    'analysis_total_count' => $r->analysisTotalCount,
                    'analysis_refreshed_at' => $r->analysisRefreshedAt,
                ], $rows),
                'page' => $page,
                'total_pages' => $totalPages,
                'total' => $total,
                'page_size' => self::PAGE_SIZE,
                'generate_url' => $this->generateUrl('_qameraai_admin_generate_form'),
                'jobs_url' => $this->generateUrl('_qameraai_admin_jobs_history'),
                // Status endpoint URL is per-row; the JS builds the
                // concrete URL by substituting {idLink} at runtime.
                // The route has an int requirement on `idLink`, so we
                // pass `0` as a placeholder and rewrite the trailing
                // `/0/status` segment with an anchored regex (matches
                // only at end-of-path, immune to collisions with any
                // other `/0/status` substring earlier in the URL).
                'status_url_template' => preg_replace(
                    '#/0/status(?=\?|$)#',
                    '/{idLink}/status',
                    $this->generateUrl('_qameraai_admin_product_status', ['idLink' => 0]),
                    1
                ),
                'js_asset_url' => '/modules/qameraai/views/js/products_grid.js',
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
