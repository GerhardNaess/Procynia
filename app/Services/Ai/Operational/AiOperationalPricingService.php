<?php

namespace App\Services\Ai\Operational;

use App\Data\Ai\Operational\AiCostState;
use App\Data\Ai\Operational\AiFxState;
use App\Data\Ai\Operational\AiPriceState;
use App\Models\AiModelPrice;
use App\Models\AiUsageAttempt;
use App\Models\ExchangeRate;
use Carbon\CarbonImmutable;

/**
 * The one place a provider call is turned into money.
 *
 * It answers two different questions with the same price and FX data: what a call is likely to
 * cost before it is made (for a reservation), and what it did cost afterwards (for the ledger).
 * `AiTokenCostEstimator` keeps pricing the older `ai_token_events` reporting ledger and is left
 * alone; this service prices the complete attempt ledger and is the only input to enforcement.
 */
class AiOperationalPricingService
{
    private const TARGET_CURRENCY = 'NOK';

    /**
     * Whether price-based enforcement is meaningful at all.
     *
     * The unknown-price hard stop exists to catch a *new* model being used before anyone priced
     * it. An entirely empty catalogue is a different situation — pricing has never been
     * configured — and treating that as "every model is unpriced" would turn a fresh or
     * misconfigured environment into a total AI outage. That state is alerted instead, loudly and
     * separately, so it cannot sit unnoticed.
     */
    public function catalogueIsConfigured(): bool
    {
        return AiModelPrice::query()->exists();
    }

    public function priceState(
        string $provider,
        string $model,
        ?string $deploymentName,
        ?string $providerRegion,
        ?CarbonImmutable $at = null,
    ): AiPriceState {
        $at ??= CarbonImmutable::now();

        if (trim($provider) === '' || trim($model) === '') {
            return new AiPriceState(AiPriceState::MISSING, null, null);
        }

        $price = AiModelPrice::findForEvent($provider, $model, $deploymentName, $providerRegion, $at);

        if (! $price instanceof AiModelPrice) {
            return new AiPriceState(AiPriceState::MISSING, null, null);
        }

        // Age is measured from the last time a human or a sync run confirmed the price, falling
        // back to when it became valid — not from when the row happened to be touched.
        $confirmedAt = $price->last_verified_at ?? $price->last_seen_at ?? $price->valid_from;
        $ageDays = $confirmedAt === null ? null : (int) CarbonImmutable::parse($confirmedAt)->diffInDays($at);

        if ($ageDays === null) {
            return new AiPriceState(AiPriceState::KNOWN, $price, null);
        }

        return new AiPriceState(match (true) {
            $ageDays >= $this->criticalPriceAgeDays() => AiPriceState::STALE_CRITICAL,
            $ageDays >= $this->warningPriceAgeDays() => AiPriceState::STALE_WARNING,
            default => AiPriceState::KNOWN,
        }, $price, $ageDays);
    }

    /**
     * Resolve the rate used to express a USD cost in NOK.
     *
     * A missing rate never yields 0 NOK. A stale rate is used rather than stopping AI over a feed
     * that has not published yet, but it is padded before it is charged against a safety budget:
     * the true rate has drifted by an unknown amount, and for a safety net the safe direction is up.
     */
    public function fxState(string $priceCurrency, ?CarbonImmutable $at = null): AiFxState
    {
        $at ??= CarbonImmutable::now();
        $currency = strtoupper(trim($priceCurrency));

        if ($currency === self::TARGET_CURRENCY) {
            return new AiFxState(AiFxState::FRESH, 1.0, 1.0, $at->toDateString(), 0);
        }

        $rate = ExchangeRate::findForDate($currency, self::TARGET_CURRENCY, $at);

        if (! $rate instanceof ExchangeRate) {
            $fallback = (float) config('procynia.ai.fx.fallback_usd_nok_rate', 12.0);

            return new AiFxState(
                AiFxState::MISSING,
                $fallback,
                $this->withMargin($fallback, $this->fxSafetyMarginPercent()),
                null,
                null,
            );
        }

        $rateDate = CarbonImmutable::parse($rate->rate_date);
        $ageDays = (int) $rateDate->diffInDays($at);
        $value = (float) $rate->rate;

        $state = match (true) {
            $ageDays >= $this->criticalFxAgeDays() => AiFxState::STALE_CRITICAL,
            $ageDays >= $this->warningFxAgeDays() => AiFxState::STALE_WARNING,
            default => AiFxState::FRESH,
        };

        return new AiFxState(
            $state,
            $value,
            $state === AiFxState::FRESH ? $value : $this->withMargin($value, $this->fxSafetyMarginPercent()),
            $rateDate->toDateString(),
            $ageDays,
        );
    }

    /**
     * Price one finished attempt.
     *
     * Returns an `unknown` state — never a zero cost — when the model cannot be priced, so an
     * unpriced call still shows up as a risk rather than as free work.
     */
    public function costForAttempt(AiUsageAttempt $attempt): AiCostState
    {
        $at = CarbonImmutable::parse($attempt->started_at ?? now());
        $priceState = $this->priceState(
            (string) ($attempt->provider ?? ''),
            (string) ($attempt->model ?? ''),
            $attempt->deployment_name,
            $attempt->provider_region,
            $at,
        );

        if ($priceState->isMissing()) {
            return new AiCostState(
                AiCostState::UNKNOWN, null, null, null, null, null, null, null, null,
                AiPriceState::MISSING, AiFxState::FRESH,
            );
        }

        $price = $priceState->price;
        $currency = strtoupper((string) $price->currency);
        $fx = $this->fxState($currency, $at);

        $inputCost = ((int) $attempt->input_tokens) / 1_000_000 * (float) $price->input_price_per_1m_tokens;
        $outputCost = ((int) $attempt->output_tokens) / 1_000_000 * (float) $price->output_price_per_1m_tokens;
        $native = $inputCost + $outputCost;

        // A stale price still prices the call, but the figure charged against a safety budget is
        // padded for the same reason a stale rate is.
        $nok = $native * $fx->effectiveRate;

        if ($priceState->isStale()) {
            $nok = $this->withMargin($nok, (float) config('procynia.ai.pricing.stale_safety_margin_percent', 20));
        }

        return new AiCostState(
            status: $priceState->isStale() || $fx->state !== AiFxState::FRESH ? AiCostState::ESTIMATED : AiCostState::KNOWN,
            costUsd: $currency === 'USD' ? round($native, 6) : null,
            costNok: round($nok, 4),
            priceId: $price->id,
            priceCurrency: $currency,
            inputPricePer1m: (float) $price->input_price_per_1m_tokens,
            outputPricePer1m: (float) $price->output_price_per_1m_tokens,
            fxRate: $fx->rate,
            fxRateDate: $fx->rateDate,
            priceState: $priceState->state,
            fxState: $fx->state,
        );
    }

    /**
     * A deliberately pessimistic price for a call that has not happened yet.
     *
     * Token counts come from one registry of per-operation ceilings rather than being guessed at
     * each call site, and the result is padded again. The point is not accuracy: it is that a
     * single call cannot land far past a hard limit because we only counted afterwards.
     */
    public function estimateMaxCostNok(
        string $provider,
        string $model,
        ?string $deploymentName,
        ?string $providerRegion,
        ?string $operationKey,
        ?CarbonImmutable $at = null,
    ): ?float {
        $priceState = $this->priceState($provider, $model, $deploymentName, $providerRegion, $at);

        if ($priceState->isMissing()) {
            return null;
        }

        $price = $priceState->price;
        $estimate = $this->operationEstimate($operationKey);
        $fx = $this->fxState((string) $price->currency, $at);

        $native = $estimate['input_tokens'] / 1_000_000 * (float) $price->input_price_per_1m_tokens
            + $estimate['output_tokens'] / 1_000_000 * (float) $price->output_price_per_1m_tokens;

        $nok = $native * $fx->effectiveRate;
        $nok = $this->withMargin($nok, $this->reservationSafetyMarginPercent());

        if ($priceState->isStale()) {
            $nok = $this->withMargin($nok, (float) config('procynia.ai.pricing.stale_safety_margin_percent', 20));
        }

        return round($nok, 4);
    }

    /** @return array{input_tokens: int, output_tokens: int} */
    public function operationEstimate(?string $operationKey): array
    {
        $registry = (array) config('procynia.ai.operation_estimates', []);
        $default = (array) ($registry['default'] ?? ['input_tokens' => 40000, 'output_tokens' => 8000]);
        $entry = (array) ($registry[$operationKey ?? ''] ?? $default);

        return [
            'input_tokens' => max(0, (int) ($entry['input_tokens'] ?? $default['input_tokens'] ?? 0)),
            'output_tokens' => max(0, (int) ($entry['output_tokens'] ?? $default['output_tokens'] ?? 0)),
        ];
    }

    private function withMargin(float $value, float $percent): float
    {
        return $value * (1 + max(0.0, $percent) / 100);
    }

    public function warningPriceAgeDays(): int
    {
        return max(1, (int) config('procynia.ai.pricing.warning_age_days', 90));
    }

    public function criticalPriceAgeDays(): int
    {
        return max($this->warningPriceAgeDays(), (int) config('procynia.ai.pricing.critical_age_days', 180));
    }

    public function warningFxAgeDays(): int
    {
        return max(1, (int) config('procynia.ai.fx.warning_age_days', 3));
    }

    public function criticalFxAgeDays(): int
    {
        return max($this->warningFxAgeDays(), (int) config('procynia.ai.fx.critical_age_days', 14));
    }

    private function fxSafetyMarginPercent(): float
    {
        return max(0.0, (float) config('procynia.ai.fx.safety_margin_percent', 10));
    }

    private function reservationSafetyMarginPercent(): float
    {
        return max(0.0, (float) config('procynia.ai.operational_budget.reservation_safety_margin_percent', 25));
    }
}
