<?php

namespace App\Services\Ai\Pricing;

/**
 * Purpose: Read AI model prices from the local config file (config/ai_model_prices.php).
 * Inputs: config('ai_model_prices.providers') keyed by provider name.
 * Returns: Normalised price arrays for the configured provider.
 * Side effects: Reads application config.
 */
class ConfigAiModelPriceProvider implements AiModelPriceProviderInterface
{
    public function __construct(private readonly string $provider = 'openai')
    {
    }

    public function providerKey(): string
    {
        return $this->provider;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchPrices(): array
    {
        $entries = config("ai_model_prices.providers.{$this->provider}", []);

        if (! is_array($entries)) {
            return [];
        }

        $prices = [];

        foreach ($entries as $entry) {
            if (! is_array($entry) || ! isset($entry['model'])) {
                continue;
            }

            $raw  = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $hash = $raw !== false ? hash('sha256', $raw) : null;

            $prices[] = [
                'model'                           => (string) $entry['model'],
                'deployment_name'                 => isset($entry['deployment_name']) ? (string) $entry['deployment_name'] : null,
                'provider_region'                 => isset($entry['provider_region']) ? (string) $entry['provider_region'] : null,
                'currency'                        => (string) ($entry['currency'] ?? 'usd'),
                'input_price_per_1m_tokens'       => (float) ($entry['input_price_per_1m_tokens'] ?? 0),
                'cached_input_price_per_1m_tokens' => isset($entry['cached_input_price_per_1m_tokens'])
                    ? (float) $entry['cached_input_price_per_1m_tokens']
                    : null,
                'output_price_per_1m_tokens'      => (float) ($entry['output_price_per_1m_tokens'] ?? 0),
                'source_url'                      => isset($entry['source_url']) ? (string) $entry['source_url'] : null,
                'raw_payload_hash'                => $hash,
            ];
        }

        return $prices;
    }
}
