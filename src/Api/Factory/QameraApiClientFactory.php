<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Factory;

use Configuration;
use Context;
use Module;
use QameraAi\Module\Api\Internal\ErrorEnvelopeParser;
use QameraAi\Module\Api\Internal\HeaderBuilder;
use QameraAi\Module\Api\Internal\IdempotencyKeyGenerator;
use QameraAi\Module\Api\Internal\JsonDecoder;
use QameraAi\Module\Api\Internal\RetryDecider;
use QameraAi\Module\Api\QameraApiClient;

/**
 * Builds {@see QameraApiClient} from PrestaShop state (Configuration,
 * Context, module version, _PS_VERSION_). Kept out of the client class so
 * the client itself stays unit-testable without PS globals.
 */
final class QameraApiClientFactory
{
    private const DEFAULT_BASE_URL = 'https://qamera.ai/api/v1/plugin';

    public function __construct(
        private readonly RetryDecider $retryDecider,
        private readonly ErrorEnvelopeParser $envelopeParser,
        private readonly IdempotencyKeyGenerator $keyGenerator,
        private readonly JsonDecoder $decoder,
    ) {
    }

    public function create(): QameraApiClient
    {
        $apiKey = (string) Configuration::get('QAMERAAI_API_KEY');
        if ($apiKey === '') {
            throw new MissingConfigurationException('Qamera AI API key is not configured');
        }

        $baseUrl = (string) Configuration::get('QAMERAAI_API_BASE_URL');
        if ($baseUrl === '') {
            $baseUrl = self::DEFAULT_BASE_URL;
        }
        $baseUrl = rtrim($baseUrl, '/');

        $headerBuilder = new HeaderBuilder(
            $apiKey,
            $this->buildUserAgent(),
            $this->resolveAcceptLanguage(),
        );

        return new QameraApiClient(
            $baseUrl,
            $headerBuilder,
            $this->retryDecider,
            $this->envelopeParser,
            $this->keyGenerator,
            $this->decoder,
        );
    }

    private function buildUserAgent(): string
    {
        $moduleVersion = '0.0.0';
        $module = Module::getInstanceByName('qameraai');
        if ($module instanceof Module && is_string($module->version) && $module->version !== '') {
            $moduleVersion = $module->version;
        }

        $psVersion = defined('_PS_VERSION_') ? (string) constant('_PS_VERSION_') : 'unknown';

        return sprintf('QameraAi-PrestaShop-Module/%s (%s)', $moduleVersion, $psVersion);
    }

    private function resolveAcceptLanguage(): string
    {
        $context = Context::getContext();
        $iso = null;
        if ($context !== null && isset($context->language) && $context->language !== null) {
            $iso = $context->language->iso_code ?? null;
        }

        if (is_string($iso) && $iso !== '') {
            return $iso;
        }

        return 'en';
    }
}
