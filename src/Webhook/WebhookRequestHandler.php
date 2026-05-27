<?php

declare(strict_types=1);

namespace QameraAi\Module\Webhook;

use Throwable;

/**
 * Framework-free orchestration core for inbound webhooks.
 *
 * Order of checks (each one stops on failure):
 *   1. method must be POST
 *   2. X-Qamera-Signature header present
 *   3. signature header parses
 *   4. X-Qamera-Delivery-Id header present
 *   5. body non-empty + decodes as a JSON object
 *   6. body's delivery_id matches the header
 *   7. body's event_type matches `^[a-z][a-z0-9_.-]{0,63}$`
 *   8. HMAC verifies under QAMERAAI_WEBHOOK_SECRET
 *   9. timestamp inside the replay window
 *  10. repository persist (200 accept / 200 duplicate / 500 on exception)
 *
 * Rejection paths NEVER persist a row (per spec D9).
 */
final class WebhookRequestHandler
{
    private const EVENT_TYPE_RE = '/^[a-z][a-z0-9_.-]{0,63}$/';

    public function __construct(
        private readonly SignatureHeaderParser $parser,
        private readonly HmacVerifier $verifier,
        private readonly ReplayGuard $replayGuard,
        private readonly WebhookDeliveryRepository $repository,
        private readonly Clock $clock,
        private readonly WebhookLogger $logger
    ) {
    }

    /**
     * @param array<string, string> $headers Case-insensitive lookup
     *        responsibility lives in the front controller; this method
     *        receives a normalised lowercase-keyed map.
     */
    public function handle(string $method, string $rawBody, array $headers, string $secret): WebhookResponse
    {
        if (strtoupper($method) !== 'POST') {
            $this->logger->error('rejected', ['reason' => RejectionReason::METHOD_NOT_ALLOWED]);

            return WebhookResponse::methodNotAllowed();
        }

        $signatureHeader = $this->header($headers, 'x-qamera-signature');
        if ($signatureHeader === null) {
            $this->logger->error('rejected', ['reason' => RejectionReason::MISSING_SIGNATURE]);

            return WebhookResponse::unauthorized();
        }

        try {
            $parsed = $this->parser->parse($signatureHeader);
        } catch (MalformedSignatureException $e) {
            $this->logger->error('rejected', ['reason' => RejectionReason::MALFORMED_SIGNATURE]);

            return WebhookResponse::badRequest();
        }

        $deliveryId = $this->header($headers, 'x-qamera-delivery-id');
        if ($deliveryId === null || $deliveryId === '') {
            $this->logger->error('rejected', ['reason' => RejectionReason::MISSING_DELIVERY_ID]);

            return WebhookResponse::badRequest();
        }

        if ($rawBody === '') {
            $this->logger->error(
                'rejected',
                ['reason' => RejectionReason::EMPTY_BODY, 'delivery_id' => $deliveryId]
            );

            return WebhookResponse::badRequest();
        }

        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded) || array_is_list($decoded)) {
            $this->logger->error(
                'rejected',
                ['reason' => RejectionReason::MALFORMED_BODY, 'delivery_id' => $deliveryId]
            );

            return WebhookResponse::badRequest();
        }

        $bodyDeliveryId = isset($decoded['delivery_id']) && is_string($decoded['delivery_id'])
            ? $decoded['delivery_id']
            : null;
        if ($bodyDeliveryId === null || $bodyDeliveryId !== $deliveryId) {
            $this->logger->error(
                'rejected',
                ['reason' => RejectionReason::DELIVERY_ID_MISMATCH, 'delivery_id' => $deliveryId]
            );

            return WebhookResponse::badRequest();
        }

        $eventType = isset($decoded['event_type']) && is_string($decoded['event_type'])
            ? $decoded['event_type']
            : null;
        if ($eventType === null || preg_match(self::EVENT_TYPE_RE, $eventType) !== 1) {
            $this->logger->error(
                'rejected',
                ['reason' => RejectionReason::MALFORMED_EVENT_TYPE, 'delivery_id' => $deliveryId]
            );

            return WebhookResponse::badRequest();
        }

        if (!$this->verifier->verify($rawBody, $parsed, $secret)) {
            $this->logger->error(
                'rejected',
                ['reason' => RejectionReason::SIGNATURE_MISMATCH, 'delivery_id' => $deliveryId]
            );

            return WebhookResponse::badRequest();
        }

        if (!$this->replayGuard->isFresh($parsed->timestamp)) {
            $this->logger->error(
                'rejected',
                [
                    'reason' => RejectionReason::REPLAY_WINDOW,
                    'delivery_id' => $deliveryId,
                    't' => $parsed->timestamp,
                    'now' => $this->clock->nowEpoch(),
                ]
            );

            return WebhookResponse::badRequest();
        }

        try {
            $outcome = $this->repository->recordAccepted(
                $deliveryId,
                $eventType,
                $rawBody,
                $this->clock->nowEpoch()
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'repository_failure',
                [
                    'delivery_id' => $deliveryId,
                    'exception' => get_class($e),
                ]
            );

            return WebhookResponse::internalServerError();
        }

        if ($outcome === DeliveryOutcome::DUPLICATE) {
            $this->logger->warning(
                'duplicate',
                ['delivery_id' => $deliveryId, 'event_type' => $eventType]
            );

            return WebhookResponse::duplicate();
        }

        $this->logger->info(
            'accepted',
            ['delivery_id' => $deliveryId, 'event_type' => $eventType]
        );

        return WebhookResponse::ok();
    }

    /**
     * @param array<string, string> $headers
     */
    private function header(array $headers, string $name): ?string
    {
        return $headers[$name] ?? null;
    }
}
