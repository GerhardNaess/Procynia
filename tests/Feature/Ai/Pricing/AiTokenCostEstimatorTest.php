<?php

namespace Tests\Feature\Ai\Pricing;

use App\Models\AiModelPrice;
use App\Models\AiTokenEvent;
use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\Ai\Pricing\AiTokenCostEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiTokenCostEstimatorTest extends TestCase
{
    use RefreshDatabase;

    private AiTokenCostEstimator $estimator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->estimator = new AiTokenCostEstimator();
    }

    public function test_correct_cost_is_calculated_for_known_price(): void
    {
        Carbon::setTestNow('2026-06-03 12:00:00');

        AiModelPrice::query()->create([
            'provider' => 'openai', 'model' => 'gpt-4.1',
            'deployment_name' => null, 'provider_region' => null,
            'currency' => 'usd',
            'input_price_per_1m_tokens' => 2.00,
            'output_price_per_1m_tokens' => 8.00,
            'valid_from' => '2026-01-01', 'valid_to' => null, 'is_active' => true,
        ]);

        $event = $this->makeEvent(['provider' => 'openai', 'model' => 'gpt-4.1',
            'input_tokens' => 1_000_000, 'output_tokens' => 500_000, 'total_tokens' => 1_500_000]);

        $result = $this->estimator->estimate($event);

        $this->assertSame(AiTokenCostEstimator::RESULT_OK, $result['status']);
        $this->assertEqualsWithDelta(2.00, $result['input_cost'], 0.000001);
        $this->assertEqualsWithDelta(4.00, $result['output_cost'], 0.000001);
        $this->assertEqualsWithDelta(6.00, $result['total_cost'], 0.000001);
        $this->assertSame('usd', $result['currency']);

        Carbon::setTestNow();
    }

    public function test_missing_price_returns_price_missing_not_zero(): void
    {
        Carbon::setTestNow('2026-06-03');

        $event = $this->makeEvent(['provider' => 'openai', 'model' => 'gpt-unknown',
            'input_tokens' => 1000, 'output_tokens' => 500, 'total_tokens' => 1500]);

        $result = $this->estimator->estimate($event);

        $this->assertSame(AiTokenCostEstimator::RESULT_MISSING, $result['status']);
        $this->assertNull($result['total_cost'], 'Must not return 0.00 when price is missing.');

        Carbon::setTestNow();
    }

    public function test_zero_tokens_returns_no_tokens_status(): void
    {
        $event = $this->makeEvent(['provider' => 'openai', 'model' => 'gpt-4.1',
            'input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0]);

        $result = $this->estimator->estimate($event);

        $this->assertSame(AiTokenCostEstimator::RESULT_NO_TOKENS, $result['status']);
    }

    public function test_null_provider_returns_price_missing(): void
    {
        $event = $this->makeEvent(['provider' => null, 'model' => 'gpt-4.1',
            'input_tokens' => 500, 'output_tokens' => 200, 'total_tokens' => 700]);

        $result = $this->estimator->estimate($event);

        $this->assertSame(AiTokenCostEstimator::RESULT_MISSING, $result['status']);
    }

    public function test_historical_price_is_used_for_old_events(): void
    {
        Carbon::setTestNow('2026-06-03');

        AiModelPrice::query()->create([
            'provider' => 'openai', 'model' => 'gpt-4.1',
            'currency' => 'usd',
            'input_price_per_1m_tokens' => 2.00, 'output_price_per_1m_tokens' => 8.00,
            'valid_from' => '2026-01-01', 'valid_to' => '2026-03-31', 'is_active' => false,
        ]);

        AiModelPrice::query()->create([
            'provider' => 'openai', 'model' => 'gpt-4.1',
            'currency' => 'usd',
            'input_price_per_1m_tokens' => 3.00, 'output_price_per_1m_tokens' => 12.00,
            'valid_from' => '2026-04-01', 'valid_to' => null, 'is_active' => true,
        ]);

        $oldEvent = $this->makeEvent([
            'provider' => 'openai', 'model' => 'gpt-4.1',
            'input_tokens' => 1_000_000, 'output_tokens' => 0, 'total_tokens' => 1_000_000,
            'created_at' => '2026-02-15 10:00:00',
        ]);

        $result = $this->estimator->estimate($oldEvent);

        $this->assertSame(AiTokenCostEstimator::RESULT_OK, $result['status']);
        $this->assertEqualsWithDelta(2.00, $result['input_cost'], 0.000001, 'Old price (2.00) must be used for Feb event.');

        Carbon::setTestNow();
    }

    private function makeEvent(array $overrides): AiTokenEvent
    {
        $language    = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);
        $customer    = Customer::query()->create([
            'name' => 'Estimator Test AS', 'slug' => 'estimator-test-'.Str::random(4),
            'language_id' => $language->id, 'nationality_id' => $nationality->id, 'is_active' => true,
        ]);

        $event = new AiTokenEvent(array_merge([
            'customer_id'   => $customer->id,
            'operation_key' => 'test',
            'model'         => 'gpt-4.1',
            'provider'      => 'openai',
            'input_tokens'  => 1000,
            'output_tokens' => 500,
            'total_tokens'  => 1500,
        ], $overrides));

        $event->save();

        if (isset($overrides['created_at'])) {
            $event->forceFill(['created_at' => $overrides['created_at'], 'updated_at' => $overrides['created_at']])->save();
            $event->refresh();
        }

        return $event;
    }
}
