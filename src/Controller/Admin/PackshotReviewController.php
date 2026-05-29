<?php

declare(strict_types=1);

namespace QameraAi\Module\Controller\Admin;

use Context;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use QameraAi\Module\Api\Exception\ApiException;
use QameraAi\Module\Packshot\Acceptance\PackshotReviewRepository;
use QameraAi\Module\Packshot\Acceptance\PackshotReviewRow;
use QameraAi\Module\Packshot\Acceptance\PackshotVoteService;
use QameraAi\Module\Webhook\Event\QameraDbException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Packshots — review" BO view (add-packshot-acceptance-flow, D2). Lists
 * `voting='pending'` review rows as a thumbnail grid; each row carries ✓/✗
 * buttons that POST to {@see voteAction} over AJAX. The vote service runs the
 * upstream accept/reject FIRST and flips local `voting` only on a 2xx, so the
 * grid never shows an accepted state the server has not cascaded.
 *
 * v1 is flat (one packshot per job); a per-image/per-packshot gallery waits
 * for `add-multi-image-surfacing`.
 */
final class PackshotReviewController extends FrameworkBundleAdminController
{
    public function indexAction(
        Request $request,
        PackshotReviewRepository $repository
    ): Response {
        $rows = $repository->listPending($this->resolveLangId());

        return $this->render(
            '@Modules/qameraai/views/templates/admin/packshot_review.html.twig',
            [
                'rows' => $rows,
                'vote_url' => $this->generateUrl('_qameraai_admin_packshot_vote'),
                'products_url' => $this->generateUrl('_qameraai_admin_products_grid'),
                'js_asset_url' => rtrim(__PS_BASE_URI__, '/')
                    . '/modules/qameraai/views/js/packshot_review.js',
            ]
        );
    }

    /**
     * AJAX vote endpoint. Returns JSON `{ok, voting?}` / `{ok:false, error}`.
     * The HTTP status mirrors the failure class so the JS can distinguish a
     * recoverable upstream error (502) from a client/CSRF error (400).
     */
    public function voteAction(
        Request $request,
        PackshotVoteService $voteService
    ): Response {
        $token = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid('qamera_packshot_vote', $token)) {
            return $this->json(['ok' => false, 'error' => 'invalid_csrf'], Response::HTTP_BAD_REQUEST);
        }

        $jobId = trim((string) $request->request->get('job_id', ''));
        $decision = (string) $request->request->get('decision', '');
        if ($jobId === '' || !in_array($decision, ['accept', 'reject'], true)) {
            return $this->json(['ok' => false, 'error' => 'bad_request'], Response::HTTP_BAD_REQUEST);
        }

        try {
            if ($decision === 'accept') {
                $voteService->accept($jobId);
                $voting = PackshotReviewRow::VOTING_ACCEPTED;
            } else {
                $voteService->reject($jobId);
                $voting = PackshotReviewRow::VOTING_REJECTED;
            }
        } catch (ApiException $e) {
            // Upstream rejected the vote (e.g. 409 job_not_completed). The
            // local row is untouched (still pending); surface the message.
            return $this->json(
                ['ok' => false, 'error' => $e->getMessage()],
                Response::HTTP_BAD_GATEWAY
            );
        } catch (QameraDbException $e) {
            // The vote landed upstream but the local flip failed — the
            // webhook/refresh path will not self-heal voting, so log-worthy.
            return $this->json(
                ['ok' => false, 'error' => 'db_error'],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        return $this->json(['ok' => true, 'voting' => $voting]);
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
