<?php

/**
 * Initial AI model prices for the ConfigAiModelPriceProvider.
 * These are seeded into ai_model_prices on first sync.
 *
 * Prices in USD per 1 million tokens, Standard tier.
 * Source: OpenAI public pricing page (https://developers.openai.com/api/docs/pricing).
 * Last verified against that page: 2026-10-08, every entry below.
 *
 * A change here reaches ai_model_prices through the scheduled `ai:sync-model-prices`, which closes
 * the old row and opens a new one — attempts already priced keep the snapshot they were priced at.
 * Set OPENAI_PROVIDER_KEY in .env to identify the provider (default: openai).
 */
return [
    'providers' => [
        'openai' => [
            [
                'model' => 'gpt-4.1',
                'currency' => 'usd',
                'input_price_per_1m_tokens' => 2.00,
                'cached_input_price_per_1m_tokens' => 0.50,
                'output_price_per_1m_tokens' => 8.00,
                'source_url' => 'https://developers.openai.com/api/docs/pricing',
            ],
            [
                'model' => 'gpt-4.1-mini',
                'currency' => 'usd',
                'input_price_per_1m_tokens' => 0.40,
                'cached_input_price_per_1m_tokens' => 0.10,
                'output_price_per_1m_tokens' => 1.60,
                'source_url' => 'https://developers.openai.com/api/docs/pricing',
            ],
            [
                'model' => 'gpt-4.1-nano',
                'currency' => 'usd',
                'input_price_per_1m_tokens' => 0.10,
                'cached_input_price_per_1m_tokens' => 0.025,
                'output_price_per_1m_tokens' => 0.40,
                'source_url' => 'https://developers.openai.com/api/docs/pricing',
            ],
            [
                'model' => 'gpt-5',
                'currency' => 'usd',
                // Was 15.00 / 3.75 / 75.00 — an overstatement of 7.5–12x, corrected 2026-10-08.
                'input_price_per_1m_tokens' => 1.25,
                'cached_input_price_per_1m_tokens' => 0.125,
                'output_price_per_1m_tokens' => 10.00,
                'source_url' => 'https://developers.openai.com/api/docs/pricing',
            ],
            [
                'model' => 'text-embedding-3-small',
                'currency' => 'usd',
                'input_price_per_1m_tokens' => 0.02,
                'cached_input_price_per_1m_tokens' => null,
                'output_price_per_1m_tokens' => 0.00,
                'source_url' => 'https://developers.openai.com/api/docs/pricing',
            ],
            [
                'model' => 'text-embedding-3-large',
                'currency' => 'usd',
                'input_price_per_1m_tokens' => 0.13,
                'cached_input_price_per_1m_tokens' => null,
                'output_price_per_1m_tokens' => 0.00,
                'source_url' => 'https://developers.openai.com/api/docs/pricing',
            ],
        ],
    ],
];
