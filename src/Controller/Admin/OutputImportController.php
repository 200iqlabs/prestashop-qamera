<?php

declare(strict_types=1);

namespace QameraAi\Module\Controller\Admin;

use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use QameraAi\Module\Packshot\Output\ImportResultPresenter;
use QameraAi\Module\Packshot\Output\OutputImporter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * BO endpoint for the "Download to shop" action on the Jobs history grid
 * (add-packshot-output-downloader). Thin shell: validate CSRF + job id, run
 * {@see OutputImporter::import}, and map the {@see \QameraAi\Module\Packshot\Output\ImportResult}
 * to JSON via {@see ImportResultPresenter}. All importing logic (gate, fetch,
 * gallery write, ledger) lives in the unit-tested service layer.
 */
final class OutputImportController extends FrameworkBundleAdminController
{
    public function importAction(
        Request $request,
        string $jobId,
        OutputImporter $importer,
        ImportResultPresenter $presenter
    ): Response {
        $token = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid('qamera_output_import', $token)) {
            return $this->json(['ok' => false, 'state' => 'aborted', 'reason' => 'invalid_csrf'], Response::HTTP_BAD_REQUEST);
        }

        $jobId = trim($jobId);
        if ($jobId === '') {
            return $this->json(['ok' => false, 'state' => 'aborted', 'reason' => 'bad_request'], Response::HTTP_BAD_REQUEST);
        }

        $result = $importer->import($jobId);
        $payload = $presenter->present($result);

        return $this->json($payload['json'], $payload['status']);
    }
}
