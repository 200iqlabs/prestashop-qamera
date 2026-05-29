<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Webhook;

/**
 * Shared builders for webhook tests, modelling the REAL server wire body
 * and headers (fix-webhook-payload-contract):
 *
 *   body:    { event, delivered_at, job:{…}, outputs:[{url}], external_metadata }
 *   headers: X-Qamera-Signature: t=<unix>,v1=<hex of "t.rawBody">
 *            X-Qamera-Request-Id: <stable webhook_deliveries.id>
 *
 * There is NO top-level delivery_id/event_type/payload wrapper and NO
 * X-Qamera-Delivery-Id header.
 */
final class WebhookFixtures
{
    /**
     * Synthetic HMAC key used exclusively by unit tests. NOT a credential.
     */
    public const SECRET = 'qameraai-unit-test-hmac-key-not-a-credential';

    /** The X-Qamera-Request-Id value (stable per delivery, reused across retries). */
    public const DELIVERY_ID = 'd7a4ce99-2f3e-4b9a-b6c5-c39d4e1c0001';

    public static function sign(int $ts, string $body, string $secret = self::SECRET): string
    {
        return hash_hmac('sha256', $ts . '.' . $body, $secret);
    }

    public static function signatureHeader(int $ts, string $body, string $secret = self::SECRET): string
    {
        return 't=' . $ts . ',v1=' . self::sign($ts, $body, $secret);
    }

    public static function multiSignatureHeader(int $ts, string $body, string ...$secrets): string
    {
        $parts = ['t=' . $ts];
        foreach ($secrets as $s) {
            $parts[] = 'v1=' . self::sign($ts, $body, $s);
        }
        return implode(',', $parts);
    }

    /**
     * Build a real-shape wire body. Top-level keys (e.g. `event`) and the
     * whole `job`/`outputs` arrays can be overridden via $overrides
     * (shallow merge at the top level).
     *
     * @param array<string, mixed> $overrides
     */
    public static function body(array $overrides = []): string
    {
        $payload = array_merge(
            [
                'event' => 'job.completed',
                'delivered_at' => '2026-05-09T08:00:00.000Z',
                'job' => [
                    'id' => '8d6a3f3d-1c2b-4e5f-9a01-2b3c4d5e6f70',
                    'status' => 'completed',
                    'job_type' => 'photo_shoot',
                    'order_id' => 'ord-1',
                    'product_ref' => 'ps:1:42',
                    'error' => null,
                ],
                'outputs' => [
                    ['url' => 'https://storage.example/out.png', 'type' => 'image/png'],
                ],
                'external_metadata' => null,
            ],
            $overrides
        );

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \RuntimeException('json_encode failed');
        }

        return $encoded;
    }

    /**
     * @return array<string, string>
     */
    public static function headers(
        int $ts,
        string $body,
        string $secret = self::SECRET,
        string $requestId = self::DELIVERY_ID
    ): array {
        return [
            'x-qamera-signature' => self::signatureHeader($ts, $body, $secret),
            'x-qamera-request-id' => $requestId,
            'content-type' => 'application/json',
        ];
    }
}
