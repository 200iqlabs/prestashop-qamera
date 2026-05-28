<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Packshot;

use PHPUnit\Framework\TestCase;
use QameraAi\Module\Api\Cache\CachedReferenceClient;
use QameraAi\Module\Api\Dto\Pricing;
use QameraAi\Module\Api\Dto\PricingEntry;
use QameraAi\Module\Packshot\CostCalculator;

final class CostCalculatorTest extends TestCase
{
    public function testKnownModelReturnsProductOfUnitImagesAndSubjects(): void
    {
        $client = $this->stubClient([new PricingEntry('packshot', 'openai', 'gpt-image-1', 5)]);
        $calc = new CostCalculator($client);

        self::assertSame(20, $calc->estimate('openai/gpt-image-1', 4, 1));
        // 5 credits/image × 2 images × 3 subjects = 30
        self::assertSame(30, $calc->estimate('openai/gpt-image-1', 2, 3));
    }

    public function testBulkScenarioFromSpec(): void
    {
        $client = $this->stubClient([new PricingEntry('packshot', 'acme', 'foo', 7)]);
        $calc = new CostCalculator($client);

        // Spec scenario: 3 subjects × 2 images × 7 = 42
        self::assertSame(42, $calc->estimate('acme/foo', 2, 3));
    }

    public function testUnknownModelReturnsNull(): void
    {
        $client = $this->stubClient([new PricingEntry('packshot', 'openai', 'gpt-image-1', 5)]);
        $calc = new CostCalculator($client);

        self::assertNull($calc->estimate('replicate/sdxl', 4, 1));
    }

    public function testNonPackshotJobTypeIsIgnored(): void
    {
        // Only entries with jobType='packshot' contribute. A pricing entry
        // for jobType='video' must not satisfy a packshot estimate even
        // when provider+model match.
        $client = $this->stubClient([new PricingEntry('video', 'openai', 'gpt-image-1', 5)]);
        $calc = new CostCalculator($client);

        self::assertNull($calc->estimate('openai/gpt-image-1', 4, 1));
    }

    public function testMalformedAiModelReturnsNull(): void
    {
        $client = $this->stubClient([]);
        $calc = new CostCalculator($client);

        self::assertNull($calc->estimate('no-slash-here', 4, 1));
        self::assertNull($calc->estimate('/missing-provider', 4, 1));
        self::assertNull($calc->estimate('missing-model/', 4, 1));
    }

    public function testNonPositiveImagesOrSubjectsReturnsNull(): void
    {
        $client = $this->stubClient([new PricingEntry('packshot', 'openai', 'gpt-image-1', 5)]);
        $calc = new CostCalculator($client);

        self::assertNull($calc->estimate('openai/gpt-image-1', 0, 1));
        self::assertNull($calc->estimate('openai/gpt-image-1', 4, 0));
    }

    /**
     * @param PricingEntry[] $entries
     */
    private function stubClient(array $entries): CachedReferenceClient
    {
        return new class ($entries) extends CachedReferenceClient {
            /** @var PricingEntry[] */
            private array $entries;

            /**
             * @param PricingEntry[] $entries
             */
            public function __construct(array $entries)
            {
                $this->entries = $entries;
                // Skip parent ctor — this stub never reads its api key
                // or cache; getPricing() returns the prepared fixture.
            }

            public function getPricing(): Pricing
            {
                return new Pricing($this->entries, 'USD');
            }
        };
    }
}
