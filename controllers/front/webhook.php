<?php

declare(strict_types=1);

use QameraAi\Module\Packshot\Acceptance\PackshotReviewRepository;
use QameraAi\Module\Packshot\Acceptance\PackshotReviewWriter;
use QameraAi\Module\Packshot\PackshotJobRepository;
use QameraAi\Module\Packshot\PackshotJobUpdater;
use QameraAi\Module\Packshot\SyncedProductLinkLookup;
use QameraAi\Module\Webhook\Event\EventDispatcher;
use QameraAi\Module\Webhook\Event\Handler\JobCancelledHandler;
use QameraAi\Module\Webhook\Event\Handler\JobCompletedHandler;
use QameraAi\Module\Webhook\Event\Handler\JobFailedHandler;
use QameraAi\Module\Webhook\Event\Handler\JobRetriedHandler;
use QameraAi\Module\Webhook\Event\ProductLinkHeartbeat;
use QameraAi\Module\Webhook\HmacVerifier;
use QameraAi\Module\Webhook\Log\PrestaShopLoggerAdapter;
use QameraAi\Module\Webhook\ReplayGuard;
use QameraAi\Module\Webhook\SignatureHeaderParser;
use QameraAi\Module\Webhook\SystemClock;
use QameraAi\Module\Webhook\WebhookDeliveryRepository;
use QameraAi\Module\Webhook\WebhookLogger;
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

    /**
     * Return type intentionally omitted to match ModuleFrontController's
     * untyped parent signature; adding `: void` here would risk an LSP
     * violation if a future PS minor declares a return type on the parent.
     */
    public function initContent()
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
    public function display()
    {
        // No-op — body already written by emit().
    }

    /**
     * Manual service-graph construction is intentional: PrestaShop's
     * legacy ModuleFrontController does NOT expose the Symfony container
     * (`$this->get()` only works for ModuleAdminController / module hook
     * callbacks). The graph is six trivial value-holders with no I/O in
     * their constructors, so the per-request allocation cost is sub-ms.
     */
    private function buildHandler(): WebhookRequestHandler
    {
        $clock = new SystemClock();
        $logger = new PrestaShopLoggerAdapter(new \QameraAi\Module\Sync\PrestaShopLoggerWrapper());

        return new WebhookRequestHandler(
            new SignatureHeaderParser(),
            new HmacVerifier(),
            new ReplayGuard($clock),
            new WebhookDeliveryRepository(Db::getInstance(), _DB_PREFIX_),
            $clock,
            $logger,
            $this->buildDispatcher($logger)
        );
    }

    private function buildDispatcher(WebhookLogger $logger): EventDispatcher
    {
        $db = Db::getInstance();
        $heartbeat = new ProductLinkHeartbeat($db, _DB_PREFIX_);

        // Per-job mirror into ps_qamera_packshot_job. The updater owns the
        // status-mapping table + pre-submit-race recovery (FK from
        // job.product_ref); handlers below all call into it. The webhook no
        // longer writes ps_qamera_packshot_link (table removed).
        $packshotJob = new PackshotJobUpdater(
            new PackshotJobRepository($db, _DB_PREFIX_),
            new SyncedProductLinkLookup($db, _DB_PREFIX_),
            $logger
        );

        // Phase 4.4 — pending review queue writer. Only job.completed with
        // job.job_type='packshot' enters here; other handlers don't need it.
        $packshotReview = new PackshotReviewWriter(
            new PackshotReviewRepository($db, _DB_PREFIX_),
            $logger
        );

        return new EventDispatcher(
            [
                'job.completed' => new JobCompletedHandler($heartbeat, $logger, $packshotJob, $packshotReview),
                'job.failed' => new JobFailedHandler($heartbeat, $logger, $packshotJob),
                'job.cancelled' => new JobCancelledHandler($heartbeat, $logger, $packshotJob),
                'job.retried' => new JobRetriedHandler($heartbeat, $logger, $packshotJob),
            ],
            $logger
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
            // http_response_code() sets the correct reason phrase for ANY
            // status (including ones added later), works under HTTP/1.1 +
            // HTTP/2, and removes the fragile statusText() lookup with its
            // `default => 'OK'` fallback that would mislabel new codes.
            http_response_code($response->statusCode);
            header('Content-Type: ' . $response->contentType);
            header('Content-Length: ' . strlen($response->body));
            header('Cache-Control: no-store');
        }
        echo $response->body;
        exit;
    }
}
