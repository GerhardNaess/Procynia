<?php

namespace App\Services\Ai\Pricing;

use App\Models\AiModelPrice;
use App\Models\AiTokenEvent;

/**
 * Purpose: Estimate the internal USD cost of one AI token event.
 * Inputs: An AiTokenEvent with provider, model, token counts and created_at.
 * Returns: A cost array or a 'price_missing' result — never 0.00 when price is unknown.
 * Side effects: None.
 */
class AiTokenCostEstimator
{
    public const RESULT_OK      = 'ok';
    public const RESULT_MISSING = 'price_missing';
    public const RESULT_NO_TOKENS = 'no_tokens';

    /**
     * Purpose: Estimate the cost for one AI token event using the historically correct price.
     * Inputs: The persisted AiTokenEvent.
     * Returns: An array with status, cost breakdown and price metadata.
     * Side effects: None.
     *
     * @return array{
     *     status: string,
     *     input_cost: float|null,
     *     cached_input_cost: float|null,
     *     output_cost: float|null,
     *     total_cost: float|null,
     *     currency: string|null,
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

        $inputCost  = $event->input_tokens  / 1_000_000 * (float) $price->input_price_per_1m_tokens;
        $outputCost = $event->output_tokens / 1_000_000 * (float) $price->output_price_per_1m_tokens;
        $totalCost  = $inputCost + $outputCost;

        return [
            'status'            => self::RESULT_OK,
            'input_cost'        => round($inputCost, 6),
            'cached_input_cost' => null,
            'output_cost'       => round($outputCost, 6),
            'total_cost'        => round($totalCost, 6),
            'currency'          => $price->currency,
            'price_id'          => $price->id,
            'provider'          => $price->provider,
            'model'             => $price->model,
            'valid_from'        => $price->valid_from?->toDateString(),
        ];
    }

    /**
     * @return array{
     *     status: string,
     *     input_cost: null,
     *     cached_input_cost: null,
     *     output_cost: null,
     *     total_cost: null,
     *     currency: null,
     *     price_id: null,
     *     provider: string|null,
     *     model: string|null,
     *     valid_from: null,
     * }
     */
    private function missingResult(string $status, AiTokenEvent $event): array
    {
        return [
            'status'            => $status,
            'input_cost'        => null,
            'cached_input_cost' => null,
            'output_cost'       => null,
            'total_cost'        => null,
            'currency'          => null,
            'price_id'          => null,
            'provider'          => $event->provider,
            'model'             => $event->model,
            'valid_from'        => null,
        ];
    }
}
