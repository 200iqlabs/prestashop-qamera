<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Webhook;

/**
 * Shared HMAC-construction helper for webhook tests. The signed string
 * is `<timestamp>.<rawBody>` (literal dot), matching the upstream
 * dispatcher contract.
 */
final class WebhookFixtures
{
    public const SECRET = 'whsec_test_3f8d6e7c5b4a39281706050403020100ff';
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
     * @param array<string, mixed> $overrides
     */
    public static function body(array $overrides = [], string $deliveryId = self::DELIVERY_ID): string
    {
        $payload = array_merge(
            [
                'delivery_id' => $deliveryId,
                'event_type' => 'job.completed',
                'job_id' => '8d6a3f3d-1c2b-4e5f-9a01-2b3c4d5e6f70',
                'data' => ['status' => 'completed'],
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
    public static function headers(int $ts, string $body, string $secret = self::SECRET, string $deliveryId = self::DELIVERY_ID): array
    {
        return [
            'x-qamera-signature' => self::signatureHeader($ts, $body, $secret),
            'x-qamera-delivery-id' => $deliveryId,
            'content-type' => 'application/json',
        ];
    }
}
