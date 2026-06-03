<?php

namespace App\Services\Ai\Pricing;

use App\Models\AiModelPrice;
use App\Models\AiTokenEvent;
use App\Models\ExchangeRate;

/**
 * Purpose: Estimate the internal cost of one AI token event in the target currency (NOK).
 * Inputs: An AiTokenEvent with provider, model, token counts and created_at.
 * Returns: A cost array with NOK amounts, or a status string when data is missing.
 * Side effects: None.
 */
class AiTokenCostEstimator
{
    public const RESULT_OK             = 'ok';
    public const RESULT_MISSING        = 'price_missing';
    public const RESULT_NO_TOKENS      = 'no_tokens';
    public const RESULT_NO_RATE        = 'exchange_rate_missing';

    private const TARGET_CURRENCY = 'NOK';

    /**
     * Purpose: Estimate the NOK cost for one AI token event using historical model price and exchange rate.
     * Inputs: The persisted AiTokenEvent.
     * Returns: Cost breakdown in NOK, or a missing-data result — never 0 kr when data is unknown.
     * Side effects: None.
     *
     * @return array{
     *     status: string,
     *     input_cost_nok: float|null,
     *     output_cost_nok: float|null,
     *     total_cost_nok: float|null,
     *     exchange_rate: float|null,
     *     price_currency: string|null,
     *     price_id: int|null,
     *     provider: string|null,
     *     model: string|null,
     *     valid_from: string|null,
     * }
     */
    public function estimate(AiTokenEvent $event): array
    {
        if ($event->total_tokens <= 0) {
            return $this->missingResult(self::RESULT_NO_TOKENS, $event);
        }

        $provider = $event->provider;
        $model    = $event->model;

        if ($provider === null || $provider === '' || $model === null || $model === '') {
            return $this->missingResult(self::RESULT_MISSING, $event);
        }

        $price = AiModelPrice::findForEvent(
            $provider,
            $model,
            $event->deployment_name,
            $event->provider_region,
            $event->created_at ?? now(),
        );

        if ($price === null) {
            return $this->missingResult(self::RESULT_MISSING, $event);
        }

        $priceCurrency = strtoupper((string) $price->currency);

        $inputCostNative  = $event->input_tokens  / 1_000_000 * (float) $price->input_price_per_1m_tokens;
        $outputCostNative = $event->output_tokens / 1_000_000 * (float) $price->output_price_per_1m_tokens;
        $totalCostNative  = $inputCostNative + $outputCostNative;

        if ($priceCurrency === self::TARGET_CURRENCY) {
            return [
                'status'          => self::RESULT_OK,
                'input_cost_nok'  => round($inputCostNative, 4),
                'output_cost_nok' => round($outputCostNative, 4),
                'total_cost_nok'  => round($totalCostNative, 4),
                'exchange_rate'   => null,
                'price_currency'  => $priceCurrency,
                'price_id'        => $price->id,
                'provider'        => $price->provider,
                'model'           => $price->model,
                'valid_from'      => $price->valid_from?->toDateString(),
            ];
        }

        $fxRate = ExchangeRate::findForDate(
            $priceCurrency,
            self::TARGET_CURRENCY,
            $event->created_at ?? now(),
        );

        if ($fxRate === null) {
            return $this->missingResult(self::RESULT_NO_RATE, $event, $price);
        }

        $rate = (float) $fxRate->rate;

        return [
            'status'          => self::RESULT_OK,
            'input_cost_nok'  => round($inputCostNative * $rate, 4),
            'output_cost_nok' => round($outputCostNative * $rate, 4),
            'total_cost_nok'  => round($totalCostNative * $rate, 4),
            'exchange_rate'   => $rate,
            'price_currency'  => $priceCurrency,
            'price_id'        => $price->id,
            'provider'        => $price->provider,
            'model'           => $price->model,
            'valid_from'      => $price->valid_from?->toDateString(),
        ];
    }

    /**
     * @return array{
     *     status: string,
     *     input_cost_nok: null,
     *     output_cost_nok: null,
     *     total_cost_nok: null,
     *     exchange_rate: null,
     *     price_currency: string|null,
     *     price_id: int|null,
     *     provider: string|null,
     *     model: string|null,
     *     valid_from: null,
     * }
     */
    private function missingResult(string $status, AiTokenEvent $event, ?AiModelPrice $price = null): array
    {
        return [
            'status'          => $status,
            'input_cost_nok'  => null,
            'output_cost_nok' => null,
            'total_cost_nok'  => null,
            'exchange_rate'   => null,
            'price_currency'  => $price?->currency,
            'price_id'        => $price?->id,
            'provider'        => $event->provider,
            'model'           => $event->model,
            'valid_from'      => null,
        ];
    }
}
