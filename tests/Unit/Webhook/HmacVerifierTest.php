<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Webhook;

use PHPUnit\Framework\TestCase;
use QameraAi\Module\Webhook\HmacVerifier;
use QameraAi\Module\Webhook\ParsedSignature;

final class HmacVerifierTest extends TestCase
{
    private const SECRET = 'whsec_test_a1b2c3d4e5f6';
    private const BODY = '{"delivery_id":"abc","event_type":"job.completed"}';
    private const TS = 1716800000;

    private HmacVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new HmacVerifier();
    }

    public function testSingleV1MatchVerifies(): void
    {
        $sig = WebhookFixtures::sign(self::TS, self::BODY, self::SECRET);

        self::assertTrue($this->verifier->verify(
            self::BODY,
            new ParsedSignature(self::TS, [$sig]),
            self::SECRET
        ));
    }

    public function testFirstOfTwoMatchVerifies(): void
    {
        $good = WebhookFixtures::sign(self::TS, self::BODY, self::SECRET);
        $bad = str_repeat('0', strlen($good));

        self::assertTrue($this->verifier->verify(
            self::BODY,
            new ParsedSignature(self::TS, [$good, $bad]),
            self::SECRET
        ));
    }

    public function testSecondOfTwoMatchVerifies(): void
    {
        $good = WebhookFixtures::sign(self::TS, self::BODY, self::SECRET);
        $bad = str_repeat('0', strlen($good));

        self::assertTrue($this->verifier->verify(
            self::BODY,
            new ParsedSignature(self::TS, [$bad, $good]),
            self::SECRET
        ));
    }

    public function testAllFailDoesNotVerify(): void
    {
        self::assertFalse($this->verifier->verify(
            self::BODY,
            new ParsedSignature(self::TS, ['deadbeef', 'feedface']),
            self::SECRET
        ));
    }

    public function testSecretMismatchRejected(): void
    {
        $sig = WebhookFixtures::sign(self::TS, self::BODY, 'wrong-secret');

        self::assertFalse($this->verifier->verify(
            self::BODY,
            new ParsedSignature(self::TS, [$sig]),
            self::SECRET
        ));
    }

    public function testBodyTamperingDetected(): void
    {
        $sig = WebhookFixtures::sign(self::TS, self::BODY, self::SECRET);

        self::assertFalse($this->verifier->verify(
            self::BODY . 'tampered',
            new ParsedSignature(self::TS, [$sig]),
            self::SECRET
        ));
    }

    public function testSourceUsesConstantTimeComparison(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../src/Webhook/HmacVerifier.php');
        self::assertIsString($source);

        $codeOnly = $this->stripPhpComments($source);

        self::assertStringNotContainsString('strcmp', $codeOnly);
        self::assertStringNotContainsString('strncmp', $codeOnly);
        self::assertSame(
            0,
            preg_match('/\$candidate\s*===|\$expected\s*===/', $codeOnly),
            'HmacVerifier must use hash_equals, not === on signature values'
        );
        self::assertStringContainsString('hash_equals', $codeOnly);
    }

    private function stripPhpComments(string $source): string
    {
        $tokens = token_get_all($source);
        $out = '';
        foreach ($tokens as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }
        return $out;
    }
}
