<?php

declare(strict_types=1);

namespace QameraAi\Module\Packshot;

use QameraAi\Module\Api\Cache\CachedReferenceClientFactory;

/**
 * Tiny adapter so the BO cost endpoint can autowire a per-request
 * calculator without touching `CachedReferenceClient` (which requires
 * a runtime API key). When the key is missing the factory throws
 * MissingConfigurationException, which the controller maps to
 * "unavailable" — the UI shows that string instead of "0 credits".
 */
final class CalculatorBridge
{
    public function __construct(
        private readonly CachedReferenceClientFactory $factory
    ) {
    }

    public function estimate(string $aiModel, int $imagesCount, int $subjectCount): ?int
    {
        $calc = new CostCalculator($this->factory->create());
        return $calc->estimate($aiModel, $imagesCount, $subjectCount);
    }
}
