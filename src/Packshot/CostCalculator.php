<?php

declare(strict_types=1);

namespace QameraAi\Module\Packshot;

use QameraAi\Module\Api\Cache\CachedReferenceClient;
use QameraAi\Module\Api\Dto\Pricing;

/**
 * Pre-flight credit cost estimator for the BO generate form. Reads from
 * the TTL-cached `/pricing` response so a stream of form-field changes
 * doesn't hammer upstream.
 *
 * Formula: `unit_cost(ai_model) × images_count × subjects_count` where
 * `unit_cost` comes from the `PricingEntry` matching
 * `(jobType='packshot', provider, model)`. The `ai_model` string carries
 * `provider/model` shape (validated by {@see Subject}); we split on the
 * first slash.
 *
 * Returns `null` when no matching pricing entry is found — the UI must
 * surface "unavailable" rather than "0 credits", which would mislead the
 * operator.
 */
final class CostCalculator
{
    public function __construct(private readonly CachedReferenceClient $client)
    {
    }

    public function estimate(string $aiModel, int $imagesCount, int $subjectCount): ?int
    {
        if ($imagesCount < 1 || $subjectCount < 1) {
            return null;
        }

        $parts = explode('/', $aiModel, 2);
        if (count($parts) !== 2) {
            return null;
        }
        [$provider, $model] = $parts;
        if ($provider === '' || $model === '') {
            return null;
        }

        $pricing = $this->client->getPricing();
        $unitCost = $this->findUnitCost($pricing, $provider, $model);
        if ($unitCost === null) {
            return null;
        }

        return $unitCost * $imagesCount * $subjectCount;
    }

    private function findUnitCost(Pricing $pricing, string $provider, string $model): ?int
    {
        foreach ($pricing->getEntries() as $entry) {
            if (
                $entry->jobType === 'packshot'
                && $entry->provider === $provider
                && $entry->model === $model
            ) {
                return $entry->creditCost;
            }
        }
        return null;
    }
}
