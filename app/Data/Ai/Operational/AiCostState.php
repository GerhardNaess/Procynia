<?php

namespace App\Data\Ai\Operational;

/**
 * How much a provider attempt is believed to have cost, and how much that belief is worth.
 *
 * `unknown` and `uncertain` are deliberately different things. Unknown means Procynia cannot price
 * the call — a missing model price. Uncertain means the provider may or may not have performed
 * work at all — a timeout. Both cost money conservatively; only one of them is a pricing problem.
 */
final readonly class AiCostState
{
    public const KNOWN = 'known';
    public const ESTIMATED = 'estimated';
    public const UNKNOWN = 'unknown';
    public const UNCERTAIN = 'uncertain';

    public function __construct(
        public string $status,
        public ?float $costUsd,
        public ?float $costNok,
        public ?int $priceId,
        public ?string $priceCurrency,
        public ?float $inputPricePer1m,
        public ?float $outputPricePer1m,
        public ?float $fxRate,
        public ?string $fxRateDate,
        public string $priceState,
        public string $fxState,
    ) {}

    /** The figure a safety budget must charge — never null, never silently zero. */
    public function budgetCostNok(float $fallbackNok = 0.0): float
    {
        return $this->costNok ?? $fallbackNok;
    }

    /** @return array<string, mixed> */
    public function toAttemptColumns(): array
    {
        return [
            'cost_status' => $this->status,
            'cost_usd' => $this->costUsd,
            'cost_nok' => $this->costNok,
            'ai_model_price_id' => $this->priceId,
            'price_currency' => $this->priceCurrency,
            'price_input_per_1m' => $this->inputPricePer1m,
            'price_output_per_1m' => $this->outputPricePer1m,
            'fx_rate' => $this->fxRate,
            'fx_rate_date' => $this->fxRateDate,
            'price_state' => $this->priceState,
            'fx_state' => $this->fxState,
        ];
    }
}
