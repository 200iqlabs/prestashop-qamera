<?php

declare(strict_types=1);

namespace QameraAi\Module\Controller\Admin;

use Configuration;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Back-office configuration screen for Qamera AI credentials and module
 * defaults. Phase 1: renders the form, persists API key / webhook secret
 * / API base URL through `Configuration::updateValue`, and surfaces a
 * stub "Test Connection" affordance. Phase 2 will wire `QameraApiClient`
 * to hit `GET /api/v1/plugin/me` from the test button.
 */
final class ConfigurationController extends FrameworkBundleAdminController
{
    public function indexAction(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            return $this->handleSubmit($request);
        }

        return $this->render(
            '@Modules/qameraai/views/templates/admin/configuration.html.twig',
            [
                'apiBaseUrl' => (string) Configuration::get('QAMERAAI_API_BASE_URL'),
                'apiKeyMasked' => $this->maskSecret((string) Configuration::get('QAMERAAI_API_KEY')),
                'webhookSecretMasked' => $this->maskSecret((string) Configuration::get('QAMERAAI_WEBHOOK_SECRET')),
                'autoRegisterProducts' => (bool) Configuration::get('QAMERAAI_AUTO_REGISTER_PRODUCTS'),
                'syncBatchSize' => (int) Configuration::get('QAMERAAI_SYNC_BATCH_SIZE'),
            ]
        );
    }

    private function handleSubmit(Request $request): RedirectResponse
    {
        $apiBaseUrl = trim((string) $request->request->get('api_base_url'));
        $apiKey = trim((string) $request->request->get('api_key'));
        $webhookSecret = trim((string) $request->request->get('webhook_secret'));
        $autoRegister = $request->request->getBoolean('auto_register_products');
        $batchSize = max(1, (int) $request->request->get('sync_batch_size', 100));

        if ($apiBaseUrl !== '') {
            Configuration::updateValue('QAMERAAI_API_BASE_URL', $apiBaseUrl);
        }
        if ($apiKey !== '' && !$this->isMaskedSecret($apiKey)) {
            Configuration::updateValue('QAMERAAI_API_KEY', $apiKey);
        }
        if ($webhookSecret !== '' && !$this->isMaskedSecret($webhookSecret)) {
            Configuration::updateValue('QAMERAAI_WEBHOOK_SECRET', $webhookSecret);
        }
        Configuration::updateValue('QAMERAAI_AUTO_REGISTER_PRODUCTS', $autoRegister ? '1' : '0');
        Configuration::updateValue('QAMERAAI_SYNC_BATCH_SIZE', (string) $batchSize);

        $this->addFlash(
            'success',
            $this->trans('Settings saved.', 'Modules.Qameraai.Admin')
        );

        return $this->redirectToRoute('_qameraai_admin_configuration');
    }

    private function maskSecret(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return str_repeat('•', 12) . substr($value, -4);
    }

    private function isMaskedSecret(string $value): bool
    {
        return str_starts_with($value, str_repeat('•', 12));
    }
}
