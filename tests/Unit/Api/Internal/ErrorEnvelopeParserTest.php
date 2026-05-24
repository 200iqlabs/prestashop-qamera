<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Api\Internal;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use QameraAi\Module\Api\Internal\ErrorEnvelopeParser;

final class ErrorEnvelopeParserTest extends TestCase
{
    private ErrorEnvelopeParser $parser;

    protected function setUp(): void
    {
        $this->parser = new ErrorEnvelopeParser();
    }

    public function testParsesWellFormedEnvelope(): void
    {
        $response = new Response(401, [], json_encode([
            'error' => [
                'code' => 'invalid_api_key',
                'message_i18n' => ['en' => 'Invalid key', 'pl' => 'Nieprawidłowy klucz'],
                'retryable' => false,
                'doc_url' => 'https://qamera.ai/docs/errors/invalid_api_key',
            ],
        ], JSON_THROW_ON_ERROR));

        $envelope = $this->parser->parse($response);

        self::assertNotNull($envelope);
        self::assertSame('invalid_api_key', $envelope->code);
        self::assertSame('Invalid key', $envelope->messageI18n['en']);
        self::assertSame('Nieprawidłowy klucz', $envelope->messageI18n['pl']);
        self::assertFalse($envelope->retryable);
        self::assertSame('https://qamera.ai/docs/errors/invalid_api_key', $envelope->docUrl);
    }

    public function testReturnsNullForMalformedJson(): void
    {
        $response = new Response(500, [], '<html>Bad Gateway</html>');
        self::assertNull($this->parser->parse($response));
    }

    public function testReturnsNullForEmptyBody(): void
    {
        $response = new Response(500);
        self::assertNull($this->parser->parse($response));
    }

    public function testReturnsNullWhenErrorKeyMissing(): void
    {
        $response = new Response(500, [], json_encode(['data' => 'oops']));
        self::assertNull($this->parser->parse($response));
    }

    public function testToleratesMissingMessageI18n(): void
    {
        $response = new Response(429, [], json_encode([
            'error' => ['code' => 'rate_limited'],
        ]));

        $envelope = $this->parser->parse($response);

        self::assertNotNull($envelope);
        self::assertSame('rate_limited', $envelope->code);
        self::assertSame([], $envelope->messageI18n);
        self::assertFalse($envelope->retryable);
        self::assertNull($envelope->docUrl);
    }
}
