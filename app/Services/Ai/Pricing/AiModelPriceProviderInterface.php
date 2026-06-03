<?php

namespace App\Services\Ai\Pricing;

interface AiModelPriceProviderInterface
{
    /**
     * Purpose: Return the canonical provider key this provider represents.
     * Inputs: None.
     * Returns: A stable string matching the provider column in ai_model_prices (e.g. 'openai').
     * Side effects: None.
     */
    public function providerKey(): string;

    /**
     * Purpose: Fetch the current price list for all known models from this provider.
     * Inputs: None.
     * Returns: Array of normalised price DTOs — each must contain:
     *   - model (string)
     *   - deployment_name (string|null)
     *   - provider_region (string|null)
     *   - currency (string, e.g. 'usd')
     *   - input_price_per_1m_tokens (float)
     *   - cached_input_price_per_1m_tokens (float|null)
     *   - output_price_per_1m_tokens (float)
     *   - source_url (string|null)
     *   - raw_payload_hash (string|null)
     * Side effects: May perform I/O (config read, HTTP, file read).
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchPrices(): array;
}
