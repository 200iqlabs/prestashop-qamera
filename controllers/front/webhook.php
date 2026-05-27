<?php

declare(strict_types=1);

use QameraAi\Module\Webhook\HmacVerifier;
use QameraAi\Module\Webhook\Log\PrestaShopLoggerAdapter;
use QameraAi\Module\Webhook\ReplayGuard;
use QameraAi\Module\Webhook\SignatureHeaderParser;
use QameraAi\Module\Webhook\SystemClock;
use QameraAi\Module\Webhook\WebhookDeliveryRepository;
use QameraAi\Module\Webhook\WebhookRequestHandler;
use QameraAi\Module\Webhook\WebhookResponse;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Storefront entry point for inbound Qamera AI webhooks.
 *
 * Reachable at `/module/qameraai/webhook` (rewrite ON) or
 * `index.php?fc=module&module=qameraai&controller=webhook`. Unauthenticated
 * by design — HMAC verification IS the authentication. CSRF-exempt because
 * the request is signed at the body level.
 *
 * The class is a thin adapter: it captures the raw input stream and headers
 * once, delegates to the framework-free {@see WebhookRequestHandler}, and
 * emits the returned {@see WebhookResponse} byte-for-byte via header()/echo
 * (bypassing Smarty/the PS template engine).
 */
class QameraaiWebhookModuleFrontController extends ModuleFrontController
{
    /** @var bool Skip the standard CSRF / customer-context handshake. */
    public $auth = false;

    /** @var bool Disable Smarty rendering — we emit JSON directly. */
    public $display_header = false;

    /** @var bool */
    public $display_footer = false;

    /** @var bool */
    public $ssl = true;

    public function initContent(): void
    {
        // Intentionally do NOT call parent::initContent() — that would load
        // theme templates and write headers we control ourselves.
        $rawBody = (string) file_get_contents('php://input');
        $headers = $this->collectHeaders();
        $method = isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';
        $secret = (string) Configuration::get('QAMERAAI_WEBHOOK_SECRET');

        $handler = $this->buildHandler();
        $response = $handler->handle($method, $rawBody, $headers, $secret);

        $this->emit($response);
    }

    /**
     * PS calls display() after initContent(); we've already emitted in
     * initContent() + exit, but override defensively in case the parent
     * dispatcher invokes us through a different code path.
     */
    public function display(): void
    {
        // No-op — body already written by emit().
    }

    private function buildHandler(): WebhookRequestHandler
    {
        $clock = new SystemClock();

        return new WebhookRequestHandler(
            new SignatureHeaderParser(),
            new HmacVerifier(),
            new ReplayGuard($clock),
            new WebhookDeliveryRepository(Db::getInstance(), _DB_PREFIX_),
            $clock,
            new PrestaShopLoggerAdapter()
        );
    }

    /**
     * @return array<string, string> Lowercase-keyed headers.
     */
    private function collectHeaders(): array
    {
        $out = [];

        if (function_exists('getallheaders')) {
            /** @var array<string, string>|false $raw */
            $raw = getallheaders();
            if (is_array($raw)) {
                foreach ($raw as $name => $value) {
                    $out[strtolower((string) $name)] = (string) $value;
                }
            }
        }

        // Fallback / supplement: PHP-FPM and some Apache configs only
        // surface custom headers under `HTTP_*` in $_SERVER.
        foreach ($_SERVER as $key => $value) {
            if (!is_string($key) || strncmp($key, 'HTTP_', 5) !== 0) {
                continue;
            }
            $name = strtolower(str_replace('_', '-', substr($key, 5)));
            if (!isset($out[$name])) {
                $out[$name] = (string) $value;
            }
        }

        return $out;
    }

    private function emit(WebhookResponse $response): void
    {
        if (!headers_sent()) {
            header('HTTP/1.1 ' . $response->statusCode . ' ' . $this->statusText($response->statusCode));
            header('Content-Type: ' . $response->contentType);
            header('Content-Length: ' . strlen($response->body));
            header('Cache-Control: no-store');
        }
        echo $response->body;
        exit;
    }

    private function statusText(int $code): string
    {
        return match ($code) {
            200 => 'OK',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            405 => 'Method Not Allowed',
            500 => 'Internal Server Error',
            default => 'OK',
        };
    }
}
