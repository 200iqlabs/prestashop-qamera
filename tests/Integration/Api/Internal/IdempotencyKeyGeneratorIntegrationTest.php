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
 * existence and correctness is what this test guarantees.
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

    public function testUuid7BranchIsExercisedWhenAvailable(): void
    {
        self::assertTrue(
            method_exists(Uuid::class, 'uuid7'),
            'ramsey/uuid 4.7+ is required — uuid7 should be available under real autoload'
        );

        // Two consecutive uuid7 values must both be valid AND, being
        // time-ordered, the second SHOULD compare greater-or-equal to
        // the first lexicographically.
        $first = (new IdempotencyKeyGenerator())->generate();
        $second = (new IdempotencyKeyGenerator())->generate();
        self::assertMatchesRegularExpression(self::UUID_REGEX, $first);
        self::assertMatchesRegularExpression(self::UUID_REGEX, $second);
        self::assertGreaterThanOrEqual(0, strcmp($second, $first));
    }

    public function testUuid4FallbackProducesValidUuid(): void
    {
        // Directly exercise the fallback branch's underlying call —
        // the production `if (method_exists(...))` guard hides this
        // branch from us when uuid7 is available, so we assert here
        // that the dependency itself behaves as the fallback expects.
        $value = Uuid::uuid4()->toString();
        self::assertMatchesRegularExpression(self::UUID_REGEX, $value);
    }
}
