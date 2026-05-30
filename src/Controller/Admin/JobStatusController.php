<?php

declare(strict_types=1);

namespace QameraAi\Module\Controller\Admin;

use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use QameraAi\Module\Packshot\Acceptance\PackshotReviewRepository;
use QameraAi\Module\Packshot\JobsStatusRefresher;
use QameraAi\Module\Packshot\Output\ImportedOutputRepository;
use QameraAi\Module\Packshot\Output\OutputImporter;
use QameraAi\Module\Packshot\PackshotJobRepository;
use QameraAi\Module\Webhook\Event\QameraDbException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Per-row job-status endpoint feeding the Jobs-history JS poll loop and the
 * per-row Refresh button. `?force=1` bypasses the refresher's TTL gate.
 * Sibling of {@see ProductStatusController} for the packshot-job mirror.
 */
final class JobStatusController extends FrameworkBundleAdminController
{
    public function statusAction(
        Request $request,
        string $jobId,
        PackshotJobRepository $repository,
        JobsStatusRefresher $refresher,
        OutputImporter $importer,
        ImportedOutputRepository $importedOutputs,
        PackshotReviewRepository $reviews
    ): JsonResponse {
        try {
            $row = $repository->findByJobId($jobId);
        } catch (QameraDbException $e) {
            return new JsonResponse(['error' => 'db_error', 'message' => $e->getMessage()], 500);
        }

        if ($row === null) {
            return new JsonResponse(['error' => 'not_found'], 404);
        }

        $force = $request->query->get('force') === '1';
        $result = $refresher->refresh($row, $force);

        $payload = [
            'qamera_job_id' => $row->qameraJobId,
            'status' => $result->status,
            'badge_class' => 'qameraai-badge qameraai-badge-' . $result->status,
            'badge_label' => $this->trans($result->status, 'Modules.Qameraai.Admin'),
            'output_url' => $result->outputUrl,
            'last_error_message' => $result->lastErrorMessage,
            'in_flight' => $refresher->isInFlight($result->status),
            // Per-row "Download to shop" display state, recomputed against the
            // freshly-refreshed status so the poll can surface the affordance
            // in place (no full page reload). Mirrors indexAction's gridState.
            'import_state' => $importer->gridState(
                $result->status,
                $reviews->findByJobId($jobId),
                $importedOutputs->importedIndexes($jobId)
            ),
        ];

        if ($result->refreshError !== null) {
            $payload['refresh_error'] = $result->refreshError;
        }

        $response = new JsonResponse($payload, 200);
        $response->setPrivate();
        $response->setMaxAge(5);

        return $response;
    }
}
