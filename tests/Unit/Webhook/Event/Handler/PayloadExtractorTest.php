<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Webhook\Event\Handler;

use PHPUnit\Framework\TestCase;
use QameraAi\Module\Webhook\Event\Handler\PayloadExtractor;

/**
 * Covers the nested-access helpers used to read the real webhook wire body
 * `{ event, job:{…}, outputs:[{url}] }` (fix-webhook-payload-contract).
 */
final class PayloadExtractorTest extends TestCase
{
    public function testJobStringReadsNestedJobField(): void
    {
        $payload = ['job' => ['product_ref' => 'ps:1:42', 'id' => 'job-uuid']];

        self::assertSame('ps:1:42', PayloadExtractor::jobString($payload, 'product_ref'));
        self::assertSame('job-uuid', PayloadExtractor::jobString($payload, 'id'));
    }

    public function testJobStringReturnsNullWhenJobMissing(): void
    {
        self::assertNull(PayloadExtractor::jobString([], 'product_ref'));
    }

    public function testJobStringReturnsNullWhenFieldMissingOrEmpty(): void
    {
        self::assertNull(PayloadExtractor::jobString(['job' => []], 'product_ref'));
        self::assertNull(PayloadExtractor::jobString(['job' => ['product_ref' => '']], 'product_ref'));
    }

    public function testFirstOutputUrlReturnsFirstHttpsUrl(): void
    {
        $payload = ['outputs' => [['url' => 'https://storage.example/img.png'], ['url' => 'https://x/2.png']]];

        self::assertSame('https://storage.example/img.png', PayloadExtractor::firstOutputUrl($payload));
    }

    public function testFirstOutputUrlReturnsNullWhenNoOutputs(): void
    {
        self::assertNull(PayloadExtractor::firstOutputUrl([]));
        self::assertNull(PayloadExtractor::firstOutputUrl(['outputs' => []]));
    }

    public function testFirstOutputUrlRejectsNonHttpScheme(): void
    {
        $payload = ['outputs' => [['url' => 'javascript:alert(1)']]];

        self::assertNull(PayloadExtractor::firstOutputUrl($payload));
    }

    public function testJobErrorMessagePrefersLocaleThenEnThenCode(): void
    {
        $payload = ['job' => ['error' => [
            'code' => 'generation_failed',
            'message_i18n' => ['en' => 'No source upload found', 'pl' => 'Brak źródła'],
            'retryable' => false,
        ]]];

        self::assertSame('Brak źródła', PayloadExtractor::jobErrorMessage($payload, 'pl'));
        self::assertSame('No source upload found', PayloadExtractor::jobErrorMessage($payload, 'de'));
    }

    public function testJobErrorMessagePrefersI18nThenPlainMessageThenCode(): void
    {
        // i18n wins when present.
        $i18n = ['job' => ['error' => [
            'code' => 'generation_failed',
            'message_i18n' => ['en' => 'localized'],
            'message' => 'plain',
        ]]];
        self::assertSame('localized', PayloadExtractor::jobErrorMessage($i18n, 'en'));

        // Plain `message` is used when no i18n is available (some providers
        // shape job.error as {code, message}), before falling back to code.
        $plain = ['job' => ['error' => ['code' => 'generation_failed', 'message' => 'quota exceeded']]];
        self::assertSame('quota exceeded', PayloadExtractor::jobErrorMessage($plain, 'en'));
    }

    public function testJobErrorMessageFallsBackToCodeWhenNoMessages(): void
    {
        $payload = ['job' => ['error' => ['code' => 'generation_failed', 'message_i18n' => [], 'retryable' => false]]];

        self::assertSame('generation_failed', PayloadExtractor::jobErrorMessage($payload, 'en'));
    }

    public function testJobErrorMessageReturnsNullWhenNoError(): void
    {
        self::assertNull(PayloadExtractor::jobErrorMessage(['job' => []], 'en'));
        self::assertNull(PayloadExtractor::jobErrorMessage(['job' => ['error' => null]], 'en'));
    }
}
