<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Api\Internal;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use QameraAi\Module\Api\Internal\RetryDecider;

final class RetryDeciderTest extends TestCase
{
    private RetryDecider $decider;
    private Request $request;

    protected function setUp(): void
    {
        $this->decider = new RetryDecider();
        $this->request = new Request('GET', 'https://example.test/me');
    }

    public function testRetriesOnConnectException(): void
    {
        $exception = new ConnectException('boom', $this->request);
        self::assertTrue($this->decider->shouldRetry(0, $this->request, null, $exception));
    }

    /**
     * @dataProvider transientStatuses
     */
    public function testRetriesOnTransientStatuses(int $status): void
    {
        $response = new Response($status);
        self::assertTrue($this->decider->shouldRetry(0, $this->request, $response, null));
    }

    /**
     * @return iterable<array{int}>
     */
    public static function transientStatuses(): iterable
    {
        return [[429], [502], [503], [504]];
    }

    /**
     * @dataProvider nonRetryableStatuses
     */
    public function testDoesNotRetryOnNonRetryableStatuses(int $status): void
    {
        $response = new Response($status);
        self::assertFalse($this->decider->shouldRetry(0, $this->request, $response, null));
    }

    /**
     * @return iterable<array{int}>
     */
    public static function nonRetryableStatuses(): iterable
    {
        return [[200], [400], [401], [403], [404], [409], [422], [500], [501]];
    }

    public function testStopsAtFourthAttempt(): void
    {
        // retries=2 means three attempts already; we're deciding the fourth — still allowed.
        $response = new Response(503);
        self::assertTrue($this->decider->shouldRetry(2, $this->request, $response, null));
        // retries=3 means four attempts already; cap reached.
        self::assertFalse($this->decider->shouldRetry(3, $this->request, $response, null));
    }

    public function testExponentialBackoffWithoutRetryAfter(): void
    {
        self::assertSame(250, $this->decider->delayMs(0, null));
        self::assertSame(500, $this->decider->delayMs(1, null));
        self::assertSame(1000, $this->decider->delayMs(2, null));
        self::assertSame(2000, $this->decider->delayMs(3, null));
    }

    public function testRetryAfterHonoured(): void
    {
        $response = new Response(429, ['Retry-After' => '2']);
        self::assertSame(2000, $this->decider->delayMs(0, $response));
    }

    public function testRetryAfterClampedToSixtySeconds(): void
    {
        $response = new Response(429, ['Retry-After' => '600']);
        self::assertSame(60_000, $this->decider->delayMs(0, $response));
    }

    public function testRetryAfterZeroFallsBackToExponential(): void
    {
        $response = new Response(429, ['Retry-After' => '0']);
        self::assertSame(250, $this->decider->delayMs(0, $response));
    }
}
