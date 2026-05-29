<?php

declare(strict_types=1);

namespace QameraAi\Module\Controller\Admin;

use Context;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use QameraAi\Module\Packshot\SyncedProductLink;
use QameraAi\Module\Packshot\SyncedProductLinkLookup;
use QameraAi\Module\Sync\AnalysisStatusRefresher;
use QameraAi\Module\Sync\RefreshResult;
use QameraAi\Module\Webhook\Event\QameraDbException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Per-row analysis-status endpoint feeding the BO grid's JS poll loop
 * and the per-row Refresh button.
 *
 * `?force=1` bypasses the per-row TTL gate inside
 * {@see AnalysisStatusRefresher}; otherwise the refresher serves
 * settled rows from the local cache without an upstream call.
 *
 * The response envelope deliberately includes both the raw enum
 * (`analysis_status`) and the pre-computed presentation primitives
 * (`badge_class`, `badge_label`, `badge_icon`, `hint`,
 * `generate_enabled`) so the JS layer mutates the DOM without
 * duplicating the mapping logic that lives in Twig + SyncedProductLink.
 */
final class ProductStatusController extends FrameworkBundleAdminController
{
    public function statusAction(
        Request $request,
        int $idLink,
        SyncedProductLinkLookup $lookup,
        AnalysisStatusRefresher $refresher
    ): JsonResponse {
        $idShop = $this->resolveShopId();

        try {
            $link = $lookup->findByIdLink($idShop, $idLink);
        } catch (QameraDbException $e) {
            return new JsonResponse(
                ['error' => 'db_error', 'message' => $e->getMessage()],
                500,
            );
        }

        if ($link === null) {
            return new JsonResponse(['error' => 'not_found'], 404);
        }

        $force = $request->query->get('force') === '1';
        $result = $refresher->refresh($link, $force);

        // Build a fresh link projection carrying the post-refresh values
        // so `canGenerate()` and `getDisabledHint()` reflect the latest
        // upstream state rather than the pre-refresh cache.
        $linkAfter = new SyncedProductLink(
            idLink: $link->idLink,
            idShop: $link->idShop,
            idProduct: $link->idProduct,
            qameraAssetId: $link->qameraAssetId,
            qameraProductRef: $link->qameraProductRef,
            displayNameSnapshot: $link->displayNameSnapshot,
            status: $link->status,
            lastSyncedAt: $link->lastSyncedAt,
            analysisStatus: $result->analysisStatus,
            analysisDescribedCount: $result->describedCount,
            analysisTotalCount: $result->totalCount,
            analysisRefreshedAt: $result->refreshedAt,
        );

        $payload = $this->renderPayload($linkAfter, $result);

        $response = new JsonResponse($payload, 200);
        $response->setPrivate();
        $response->setMaxAge(5);

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function renderPayload(SyncedProductLink $link, RefreshResult $result): array
    {
        $generateEnabled = $link->canGenerate();
        $hintKey = $link->getDisabledHint();
        $hint = $hintKey === null
            ? null
            : $this->trans($hintKey, 'Modules.Qameraai.Admin');
        $badge = $this->renderBadge($result->analysisStatus);

        $payload = [
            'id_link' => $link->idLink,
            'analysis_status' => $result->analysisStatus,
            'analysis_described_count' => $result->describedCount,
            'analysis_total_count' => $result->totalCount,
            'analysis_refreshed_at' => $result->refreshedAt,
            'generate_enabled' => $generateEnabled,
            'badge_class' => $badge['class'],
            'badge_label' => $badge['label'],
            'badge_icon' => $badge['icon'],
            'hint' => $hint,
        ];

        if ($result->refreshError !== null) {
            $payload['refresh_error'] = $result->refreshError;
        }

        return $payload;
    }

    /**
     * @return array{class: string, label: string, icon: string}
     */
    private function renderBadge(?string $analysisStatus): array
    {
        $meta = match ($analysisStatus) {
            SyncedProductLink::ANALYSIS_STATUS_DESCRIBED => [
                'class' => 'badge-success',
                'label' => 'Ready',
                'icon' => '✓',
            ],
            SyncedProductLink::ANALYSIS_STATUS_PROCESSING => [
                'class' => 'badge-info',
                'label' => 'Processing',
                'icon' => '🔄',
            ],
            SyncedProductLink::ANALYSIS_STATUS_ERROR => [
                'class' => 'badge-danger',
                'label' => 'Error',
                'icon' => '⚠',
            ],
            SyncedProductLink::ANALYSIS_STATUS_PARTIAL => [
                'class' => 'badge-warning',
                'label' => 'Partial',
                'icon' => '◐',
            ],
            // pending / null / unknown all fall through to the same chip
            default => [
                'class' => 'badge-secondary',
                'label' => 'Pending',
                'icon' => '⏳',
            ],
        };

        $meta['label'] = $this->trans($meta['label'], 'Modules.Qameraai.Admin');

        return $meta;
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
