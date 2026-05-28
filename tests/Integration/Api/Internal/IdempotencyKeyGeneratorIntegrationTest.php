<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Integration\Api\Internal;

use QameraAi\Module\Api\Internal\IdempotencyKeyGenerator;
use QameraAi\Module\Tests\Integration\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

/**
 * Exercises `IdempotencyKeyGenerator::generate` under the real Composer
 * autoloader that PS+module ship inside the dev container. Covers
 * Phase-3 smoke regression scenario 3 (the `Uuid::uuid7` / `Uuid::uuid4`
 * fallback path — see `integration-test-harness` spec §5).
 *
 * Note: this test does NOT reproduce the cross-module autoloader clash
 * with `ps_checkout` / `ps_accounts` (those modules are not present in
 * the dev container) — see design.md Non-Goals. The fallback's
 * existence and correctness under THIS autoloader is what this test
 * guarantees; cross-module clashes remain operator-smoke territory.
 */
final class IdempotencyKeyGeneratorIntegrationTest extends IntegrationTestCase
{
    private const UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function testGeneratesValidUuidUnderRealAutoload(): void
    {
        $generator = new IdempotencyKeyGenerator();
        $value = $generator->generate();

        self::assertMatchesRegularExpression(self::UUID_REGEX, $value);
    }

    public function testUuid7BranchProducesValidUuid(): void
    {
        // composer.json pins ramsey/uuid 4.7+, where uuid7 is always
        // available — the production `hasUuid7()` guard exists to
        // tolerate cross-module autoloader clashes (see
        // testFallsBackToUuid4WhenUuid7Unavailable for the inverse).
        // Two consecutive calls must each be valid UUIDs. We
        // deliberately do NOT assert lexicographic ordering — uuid7's
        // lower 74 bits are random and two values generated within
        // the same millisecond can compare in either direction
        // (RFC 9562 §6.2).
        self::assertTrue(
            method_exists(Uuid::class, 'uuid7'),
            'ramsey/uuid 4.7+ should expose uuid7 — composer.json contract.'
        );
        $first = (new IdempotencyKeyGenerator())->generate();
        $second = (new IdempotencyKeyGenerator())->generate();
        self::assertMatchesRegularExpression(self::UUID_REGEX, $first);
        self::assertMatchesRegularExpression(self::UUID_REGEX, $second);
        self::assertNotSame($first, $second);
    }

    public function testFallsBackToUuid4WhenUuid7Unavailable(): void
    {
        // Subclass that pretends uuid7 doesn't exist — forces generate()
        // to take the production fallback branch even though the
        // ambient ramsey/uuid 4.7+ exposes uuid7. This is the only way
        // to exercise the actual production code path against the real
        // autoloader without runkit/uopz.
        $forcedFallback = new class () extends IdempotencyKeyGenerator {
            protected function hasUuid7(): bool
            {
                return false;
            }
        };

        $value = $forcedFallback->generate();
        self::assertMatchesRegularExpression(self::UUID_REGEX, $value);
    }
}
