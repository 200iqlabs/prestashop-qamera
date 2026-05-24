<?php

declare(strict_types=1);

namespace QameraAi\Module\Controller\Admin;

use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use QameraAi\Module\Api\Exception\ApiException;
use QameraAi\Module\Api\Exception\AuthException;
use QameraAi\Module\Api\Exception\NotFoundException;
use QameraAi\Module\Api\Exception\RateLimitException;
use QameraAi\Module\Api\Exception\ServerException;
use QameraAi\Module\Api\Exception\TransportException;
use QameraAi\Module\Api\Exception\ValidationException;
use QameraAi\Module\Api\Factory\MissingConfigurationException;
use QameraAi\Module\Api\Factory\QameraApiClientFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Live-validates stored Qamera AI credentials by issuing `GET /me` and
 * returning the response (or a typed-error envelope) as JSON. Distinct
 * admin route from the save form so a test action cannot accidentally
 * overwrite stored configuration.
 */
final class TestConnectionController extends FrameworkBundleAdminController
{
    public function indexAction(Request $request, QameraApiClientFactory $factory): JsonResponse
    {
        $token = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid('qamera_test_connection', $token)) {
            return new JsonResponse([
                'ok' => false,
                'message' => $this->trans('Invalid CSRF token.', 'Modules.Qameraai.Admin'),
                'code' => 'csrf_invalid',
            ], 400);
        }

        try {
            $me = $factory->create()->me();
        } catch (MissingConfigurationException $e) {
            return new JsonResponse([
                'ok' => false,
                'message' => $this->trans(
                    'Qamera AI API key is not configured. Save your credentials first.',
                    'Modules.Qameraai.Admin',
                ),
                'code' => 'not_configured',
            ]);
        } catch (ApiException $e) {
            return new JsonResponse([
                'ok' => false,
                'message' => $this->humanMessageFor($e),
                'code' => $e->getEnvelope()?->code ?? $this->fallbackCodeFor($e),
            ]);
        }

        return new JsonResponse([
            'ok' => true,
            'account_name' => $me->accountName,
            'credits_balance' => $me->creditsBalance,
            'subscription_plan' => $me->subscriptionPlan,
            'installation' => [
                'platform' => $me->installation->platform,
                'status' => $me->installation->status,
            ],
        ]);
    }

    private function humanMessageFor(ApiException $e): string
    {
        $envelope = $e->getEnvelope();
        if ($envelope !== null) {
            return $envelope->messageFor('en');
        }

        return match (true) {
            $e instanceof TransportException => $this->trans(
                'Could not reach Qamera AI. Check your network or the API base URL.',
                'Modules.Qameraai.Admin',
            ),
            $e instanceof AuthException => $this->trans(
                'Authentication failed. Verify the API key and that the installation is active.',
                'Modules.Qameraai.Admin',
            ),
            default => $e->getMessage(),
        };
    }

    private function fallbackCodeFor(ApiException $e): string
    {
        return match (true) {
            $e instanceof TransportException => 'transport_error',
            $e instanceof AuthException => 'auth_failed',
            $e instanceof NotFoundException => 'not_found',
            $e instanceof RateLimitException => 'rate_limited',
            $e instanceof ValidationException => 'validation_failed',
            $e instanceof ServerException => 'server_error',
            default => 'unknown_error',
        };
    }
}
