<?php

namespace Tests\Feature\Operational;

use App\Data\Ai\Operational\AiCostState;
use App\Data\Ai\Operational\AiFxState;
use App\Data\Ai\Operational\AiPriceState;
use App\Models\AiModelPrice;
use App\Models\AiUsageAttempt;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\Ai\Operational\AiOperationalPricingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Pricing and FX: how a provider call becomes money, and what happens when it cannot. */
class AiOperationalPricingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-15 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // =========================================================================
    // Model price
    // =========================================================================

    public function test_a_current_price_is_known(): void
    {
        $this->price('gpt-5', verifiedAt: '2026-09-10 00:00:00');

        $state = app(AiOperationalPricingService::class)->priceState('openai', 'gpt-5', null, null);

        $this->assertSame(AiPriceState::KNOWN, $state->state);
        $this->assertNotNull($state->price);
    }

    public function test_a_model_with_no_price_is_missing_not_free(): void
    {
        $this->price('gpt-5');

        $state = app(AiOperationalPricingService::class)->priceState('openai', 'brand-new-model', null, null);

        $this->assertSame(AiPriceState::MISSING, $state->state);
        $this->assertTrue($state->isMissing());
    }

    public function test_price_age_crosses_warning_and_critical(): void
    {
        foreach ([
            ['2026-09-10 00:00:00', AiPriceState::KNOWN],
            ['2026-05-01 00:00:00', AiPriceState::STALE_WARNING],
            ['2025-11-01 00:00:00', AiPriceState::STALE_CRITICAL],
        ] as [$verifiedAt, $expected]) {
            AiModelPrice::query()->delete();
            $this->price('gpt-5', verifiedAt: $verifiedAt);

            $state = app(AiOperationalPricingService::class)->priceState('openai', 'gpt-5', null, null);
            $this->assertSame($expected, $state->state, "price verified {$verifiedAt}");
        }
    }

    public function test_an_empty_catalogue_is_reported_as_unconfigured_rather_than_all_models_missing(): void
    {
        $service = app(AiOperationalPricingService::class);

        $this->assertFalse($service->catalogueIsConfigured());

        $this->price('gpt-5');
        $this->assertTrue($service->catalogueIsConfigured());
    }

    // =========================================================================
    // FX
    // =========================================================================

    public function test_a_fresh_rate_is_used_unpadded(): void
    {
        $this->rate('2026-09-15', 10.0);

        $fx = app(AiOperationalPricingService::class)->fxState('USD');

        $this->assertSame(AiFxState::FRESH, $fx->state);
        $this->assertEqualsWithDelta(10.0, $fx->rate, 0.0001);
        $this->assertEqualsWithDelta(10.0, $fx->effectiveRate, 0.0001, 'A current rate needs no safety margin.');
    }

    public function test_a_weekend_old_rate_is_still_fresh(): void
    {
        // Norges Bank does not publish on Sunday; a Monday call legitimately reads Friday's rate.
        $this->rate('2026-09-13', 10.0);

        $this->assertSame(AiFxState::FRESH, app(AiOperationalPricingService::class)->fxState('USD')->state);
    }

    public function test_a_stale_rate_is_used_but_padded(): void
    {
        $this->rate('2026-09-01', 10.0);

        $fx = app(AiOperationalPricingService::class)->fxState('USD');

        $this->assertSame(AiFxState::STALE_CRITICAL, $fx->state);
        $this->assertEqualsWithDelta(10.0, $fx->rate, 0.0001);
        $this->assertGreaterThan($fx->rate, $fx->effectiveRate, 'A stale rate must be padded for enforcement.');
        $this->assertEqualsWithDelta(11.0, $fx->effectiveRate, 0.0001);
    }

    public function test_a_warning_age_rate_is_padded_too(): void
    {
        $this->rate('2026-09-10', 10.0);

        $fx = app(AiOperationalPricingService::class)->fxState('USD');

        $this->assertSame(AiFxState::STALE_WARNING, $fx->state);
        $this->assertGreaterThan($fx->rate, $fx->effectiveRate);
    }

    public function test_a_missing_rate_falls_back_conservatively_and_never_to_zero(): void
    {
        $fx = app(AiOperationalPricingService::class)->fxState('USD');

        $this->assertSame(AiFxState::MISSING, $fx->state);
        $this->assertGreaterThan(0.0, $fx->rate, 'A missing rate must never price a call at 0 NOK.');
        $this->assertGreaterThan($fx->rate, $fx->effectiveRate);
    }

    public function test_a_nok_price_needs_no_conversion(): void
    {
        $fx = app(AiOperationalPricingService::class)->fxState('NOK');

        $this->assertSame(AiFxState::FRESH, $fx->state);
        $this->assertEqualsWithDelta(1.0, $fx->effectiveRate, 0.0001);
    }

    // =========================================================================
    // Cost of a finished attempt
    // =========================================================================

    public function test_a_priced_attempt_gets_a_known_cost_and_an_immutable_snapshot(): void
    {
        $price = $this->price('gpt-5', input: 10.0, output: 30.0, verifiedAt: '2026-09-14 00:00:00');
        $this->rate('2026-09-15', 10.0);
        $attempt = $this->attempt('gpt-5', inputTokens: 1_000_000, outputTokens: 1_000_000);

        $cost = app(AiOperationalPricingService::class)->costForAttempt($attempt);

        $this->assertSame(AiCostState::KNOWN, $cost->status);
        // (10 + 30) USD at 10 NOK/USD.
        $this->assertEqualsWithDelta(400.0, $cost->costNok, 0.01);
        $this->assertEqualsWithDelta(40.0, $cost->costUsd, 0.01);
        $this->assertSame($price->id, $cost->priceId);
        $this->assertSame('2026-09-15', $cost->fxRateDate);
    }

    public function test_an_unpriced_attempt_is_unknown_and_never_zero(): void
    {
        $this->price('gpt-5');
        $attempt = $this->attempt('some-new-model', inputTokens: 500_000, outputTokens: 500_000);

        $cost = app(AiOperationalPricingService::class)->costForAttempt($attempt);

        $this->assertSame(AiCostState::UNKNOWN, $cost->status);
        $this->assertNull($cost->costNok, 'An unpriceable call must not be recorded as costing nothing.');
        $this->assertNotSame(0.0, $cost->costNok);
    }

    public function test_a_stale_price_or_rate_downgrades_the_cost_to_estimated(): void
    {
        $this->price('gpt-5', input: 10.0, output: 30.0, verifiedAt: '2025-11-01 00:00:00');
        $this->rate('2026-09-15', 10.0);

        $cost = app(AiOperationalPricingService::class)->costForAttempt(
            $this->attempt('gpt-5', inputTokens: 1_000_000, outputTokens: 1_000_000),
        );

        $this->assertSame(AiCostState::ESTIMATED, $cost->status);
        $this->assertGreaterThan(400.0, $cost->costNok, 'A stale price is padded before it is trusted.');
    }

    // =========================================================================
    // Pre-call estimate
    // =========================================================================

    public function test_a_pre_call_estimate_is_padded_above_the_raw_token_price(): void
    {
        $this->price('gpt-5', input: 10.0, output: 30.0, verifiedAt: '2026-09-14 00:00:00');
        $this->rate('2026-09-15', 10.0);
        $service = app(AiOperationalPricingService::class);

        $estimate = $service->estimateMaxCostNok('openai', 'gpt-5', null, null, 'wiki.ask.answer');
        $tokens = $service->operationEstimate('wiki.ask.answer');
        $raw = ($tokens['input_tokens'] / 1_000_000 * 10.0 + $tokens['output_tokens'] / 1_000_000 * 30.0) * 10.0;

        $this->assertGreaterThan($raw, $estimate, 'A reservation must be conservative, not exact.');
    }

    public function test_an_unknown_operation_falls_back_to_the_conservative_default(): void
    {
        $service = app(AiOperationalPricingService::class);

        $this->assertSame(
            $service->operationEstimate('default'),
            $service->operationEstimate('some.operation.nobody.registered'),
        );
    }

    public function test_an_unpriceable_model_cannot_be_estimated(): void
    {
        $this->price('gpt-5');

        $this->assertNull(
            app(AiOperationalPricingService::class)->estimateMaxCostNok('openai', 'unpriced', null, null, 'wiki.ask.answer'),
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function price(string $model, float $input = 1.0, float $output = 2.0, ?string $verifiedAt = null): AiModelPrice
    {
        return AiModelPrice::query()->create([
            'provider' => 'openai',
            'model' => $model,
            'currency' => 'USD',
            'input_price_per_1m_tokens' => $input,
            'output_price_per_1m_tokens' => $output,
            'valid_from' => '2026-01-01',
            'is_active' => true,
            'last_verified_at' => $verifiedAt ?? '2026-09-14 00:00:00',
        ]);
    }

    private function rate(string $date, float $rate): ExchangeRate
    {
        return ExchangeRate::query()->create([
            'base_currency' => 'USD',
            'quote_currency' => 'NOK',
            'rate' => $rate,
            'rate_date' => $date,
            'source' => ExchangeRate::SOURCE_NORGES_BANK,
            'fetched_at' => now(),
        ]);
    }

    private function attempt(string $model, int $inputTokens, int $outputTokens): AiUsageAttempt
    {
        return AiUsageAttempt::query()->create([
            'customer_id' => $this->customer()->id,
            'feature' => 'wiki',
            'operation_key' => 'wiki.ask.answer',
            'provider' => 'openai',
            'endpoint' => 'responses',
            'model' => $model,
            'status' => AiUsageAttempt::STATUS_SUCCESS,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $inputTokens + $outputTokens,
            'started_at' => now(),
        ]);
    }

    private function customer(): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => 'Pricing '.Str::random(8),
            'slug' => 'pricing-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
            'subscription_plan' => Customer::PLAN_PRO,
            'included_ai_credits' => 10,
        ]);
    }
}
