<?php

declare(strict_types=1);

namespace QameraAi\Module\Controller\Admin;

use Context;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use QameraAi\Module\Packshot\JobsGridFilters;
use QameraAi\Module\Packshot\PackshotJobRepository;
use QameraAi\Module\Packshot\PackshotJobRow;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Paginated jobs history. Status filter via query string (`status=failed`
 * etc.); empty value or `all` lifts the filter.
 */
final class JobsHistoryController extends FrameworkBundleAdminController
{
    private const PAGE_SIZE = 50;

    public function indexAction(
        Request $request,
        PackshotJobRepository $repository
    ): Response {
        $status = trim((string) $request->query->get('status', ''));
        $statusFilter = ($status === '' || $status === 'all') ? null : $status;
        if ($statusFilter !== null && !in_array($statusFilter, PackshotJobRow::STATUSES, true)) {
            $statusFilter = null;
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $offset = ($page - 1) * self::PAGE_SIZE;

        $filters = new JobsGridFilters(
            status: $statusFilter,
            idLang: $this->resolveLangId(),
            limit: self::PAGE_SIZE,
            offset: $offset,
        );

        $rows = $repository->listForGrid($filters);
        $total = $repository->countForGrid($statusFilter);
        $totalPages = (int) ceil(max(1, $total) / self::PAGE_SIZE);

        return $this->render(
            '@Modules/qameraai/views/templates/admin/jobs_history.html.twig',
            [
                'rows' => $rows,
                'status_filter' => $statusFilter ?? 'all',
                'statuses' => array_merge(['all'], PackshotJobRow::STATUSES),
                'page' => $page,
                'total' => $total,
                'total_pages' => $totalPages,
                'page_size' => self::PAGE_SIZE,
                'products_url' => $this->generateUrl('_qameraai_admin_products_grid'),
                // Per-row status endpoint; JS substitutes {jobId} at runtime.
                // A placeholder job id keeps generateUrl happy, then the
                // trailing `/__JOBID__/status` segment is rewritten (anchored
                // so it cannot collide with an earlier substring).
                'status_url_template' => preg_replace(
                    '#/__JOBID__/status(?=\?|$)#',
                    '/{jobId}/status',
                    $this->generateUrl('_qameraai_admin_job_status', ['jobId' => '__JOBID__']),
                    1
                ),
                // Twig asset() resolves to /admin-dev/... which 404s for module
                // JS — build the public path from __PS_BASE_URI__ (see commit
                // d1c9c18 in products_grid).
                'js_asset_url' => rtrim(__PS_BASE_URI__, '/') . '/modules/qameraai/views/js/jobs_history.js',
            ]
        );
    }

    private function resolveLangId(): int
    {
        $context = Context::getContext();
        $language = $context->language ?? null;
        if (is_object($language) && isset($language->id)) {
            return (int) $language->id;
        }
        return 1;
    }
}
