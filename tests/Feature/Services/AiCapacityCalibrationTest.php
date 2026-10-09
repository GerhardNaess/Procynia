<?php

namespace Tests\Feature\Services;

use App\Data\Ai\Usage\AiUsageFilter;
use App\Data\Ai\Usage\AiUsagePeriod;
use App\Models\AiUsageAttempt;
use App\Models\Customer;
use App\Models\CustomerBillingPeriod;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\Ai\Commercial\AiCapacityCalibrationService;
use App\Services\Modules\ModuleEntitlementService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The internal calibration report: usage, cost and units per customer and window, how often the
 * capacity would have blocked, peak concurrency, and preflight estimates against actual cost.
 */
class AiCapacityCalibrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-08 10:00:00');
        config()->set('ai_customer_capacity.nok_per_unit', 0.10);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_estimates_are_compared_with_actual_cost_per_operation(): void
    {
        $customer = $this->customer();
        // Well sized: estimate 1.00, actual 0.40–0.60.
        foreach ([0.40, 0.50, 0.60] as $actual) {
            $this->attempt($customer, ['operation_key' => 'wiki.verify_claim', 'reserved_cost_nok' => 1.0, 'cost_nok' => $actual]);
        }
        // Too low: real calls exceed the estimate.
        foreach ([0.50, 2.50] as $actual) {
            $this->attempt($customer, ['operation_key' => 'wiki.generate_page', 'reserved_cost_nok' => 1.0, 'cost_nok' => $actual]);
        }
        // Too high: estimate ten times the actual.
        $this->attempt($customer, ['operation_key' => 'tender.requirement_answer', 'reserved_cost_nok' => 1.0, 'cost_nok' => 0.10]);
        // Open settlements have no actual cost and are left out.
        $this->attempt($customer, ['operation_key' => 'wiki.verify_claim', 'reserved_cost_nok' => 1.0, 'cost_nok' => null, 'settlement_status' => 'pending']);

        $rows = collect(app(AiCapacityCalibrationService::class)->estimateAccuracy($this->filter()))->keyBy('operation_key');

        $this->assertSame(3, $rows['wiki.verify_claim']['calls']);
        $this->assertSame(1.0, $rows['wiki.verify_claim']['avg_estimate_nok']);
        $this->assertSame(0.5, $rows['wiki.verify_claim']['avg_actual_nok']);
        $this->assertSame(0.5, $rows['wiki.verify_claim']['median_actual_nok']);
        $this->assertSame(2.0, $rows['wiki.verify_claim']['estimate_actual_ratio']);
        $this->assertSame('ok', $rows['wiki.verify_claim']['assessment']);
        $this->assertSame('too_low', $rows['wiki.generate_page']['assessment']);
        $this->assertSame('too_high', $rows['tender.requirement_answer']['assessment']);
    }

    public function test_a_customer_report_reads_only_that_customer_and_counts_would_have_blocked(): void
    {
        $customer = $this->customer(['included_ai_units' => 100]);
        $other = $this->customer(['included_ai_units' => 100]);
        $this->attempt($customer, ['cost_nok' => 2.0, 'capacity_verdict' => 'allow', 'feature' => 'wiki', 'operation_key' => 'wiki.verify_claim']);
        $this->attempt($customer, ['cost_nok' => 3.0, 'capacity_verdict' => 'warn', 'feature' => 'tender', 'operation_key' => 'tender.requirement_answer']);
        $this->attempt($customer, ['cost_nok' => 1.0, 'capacity_verdict' => 'exhausted', 'feature' => 'quality', 'operation_key' => 'quality.interpret_process']);
        $this->attempt($customer, ['cost_nok' => null, 'reserved_cost_nok' => 0.5, 'settlement_status' => 'pending', 'capacity_verdict' => 'insufficient']);
        $this->attempt($other, ['cost_nok' => 99.0, 'capacity_verdict' => 'exhausted']);

        $report = app(AiCapacityCalibrationService::class)->customerReport($customer, $this->window());

        $this->assertSame(4, $report['calls']);
        $this->assertSame(6.0, $report['settled_cost_nok']);
        $this->assertSame(60.0, $report['used_units']);
        $this->assertSame(5.0, $report['reserved_units']);
        $this->assertSame(100, $report['included_units']);
        $this->assertSame('override', $report['included_source']);
        $this->assertSame(2, $report['verdicts']['would_have_blocked']);
        $this->assertSame(1, $report['verdicts']['warn']);
        $this->assertEqualsCanonicalizing(['wiki', 'tender', 'quality'], array_values(array_unique(array_column($report['breakdown'], 'feature'))));
    }

    public function test_the_report_names_the_tier_and_the_source(): void
    {
        config()->set('ai_customer_capacity.base', ['basis' => 2000, 'per_user' => 0, 'options' => []]);
        config()->set('ai_customer_capacity.tiers', [
            'tier_a' => ['name' => 'Tier A', 'multiplier' => 1.0, 'active' => true, 'sort_order' => 10],
        ]);
        config()->set('ai_customer_capacity.default_tier', null);
        $tiered = $this->customer(['ai_capacity_tier' => 'tier_a']);
        app(ModuleEntitlementService::class)->activatePackage($tiered, 'basis');
        $overridden = $this->customer(['ai_capacity_tier' => 'tier_a', 'included_ai_units' => 9000]);
        // No tier and no default: the only way to be unconfigured.
        $unconfigured = $this->customer();
        app(ModuleEntitlementService::class)->activatePackage($unconfigured, 'basis');

        foreach ([$tiered, $overridden, $unconfigured] as $customer) {
            $this->attempt($customer, ['cost_nok' => 1.0, 'started_at' => now()->subHour(), 'finished_at' => now()->subHour(), 'capacity_verdict' => 'allow']);
        }

        $calibration = app(AiCapacityCalibrationService::class);
        $expected = [
            [$tiered, 2000, 'tier', 'tier_a'],
            [$overridden, 9000, 'override', 'tier_a'],
            [$unconfigured, null, 'unconfigured', null],
        ];

        foreach ($expected as [$customer, $units, $source, $tier]) {
            $report = $calibration->customerReport($customer, $this->window());
            $this->assertSame($units, $report['included_units']);
            $this->assertSame($source, $report['included_source']);
            $this->assertSame($tier, $report['tier_key']);
        }

        $this->artisan('ai:capacity-analysis', ['--customer' => $tiered->id])
            ->expectsOutputToContain('Tier A (tier_a)')
            ->expectsOutputToContain('Capacity source')
            ->doesntExpectOutputToContain('basis')
            ->assertSuccessful();

        $this->artisan('ai:capacity-analysis', ['--customer' => $unconfigured->id])
            ->expectsOutputToContain('none selected')
            ->expectsOutputToContain('unconfigured')
            ->assertSuccessful();
    }

    public function test_the_window_follows_the_billing_period(): void
    {
        $customer = $this->customer(['included_ai_units' => 100]);
        CustomerBillingPeriod::query()->create([
            'customer_id' => $customer->id, 'provider' => 'stripe', 'provider_subscription_id' => 'sub_cal',
            'period_start' => '2026-09-15 00:00:00', 'period_end' => '2026-10-15 00:00:00',
            'interval' => 'month', 'subscription_status' => 'active', 'synced_at' => now(),
        ]);
        $this->attempt($customer, ['cost_nok' => 1.0, 'started_at' => '2026-09-14 23:59:59']);
        $this->attempt($customer, ['cost_nok' => 2.0, 'started_at' => '2026-09-15 00:00:00']);

        $this->artisan('ai:capacity-analysis', ['--customer' => $customer->id])
            ->expectsOutputToContain('2026-09-15 00:00:00')
            ->expectsOutputToContain('Estimate vs actual')
            ->assertSuccessful();

        $report = app(AiCapacityCalibrationService::class)->customerReport(
            $customer,
            new AiUsagePeriod(CarbonImmutable::parse('2026-09-15 00:00:00'), CarbonImmutable::parse('2026-10-15 00:00:00')),
        );
        $this->assertSame(2.0, $report['settled_cost_nok']);
    }

    public function test_peak_concurrency_counts_overlapping_calls(): void
    {
        $customer = $this->customer();
        $this->attempt($customer, ['started_at' => '2026-10-08 09:00:00', 'finished_at' => '2026-10-08 09:00:30']);
        $this->attempt($customer, ['started_at' => '2026-10-08 09:00:10', 'finished_at' => '2026-10-08 09:00:40']);
        $this->attempt($customer, ['started_at' => '2026-10-08 09:00:20', 'finished_at' => '2026-10-08 09:00:25']);
        $this->attempt($customer, ['started_at' => '2026-10-08 09:05:00', 'finished_at' => '2026-10-08 09:05:10']);

        $peak = app(AiCapacityCalibrationService::class)->peakConcurrency(new AiUsageFilter($this->window(), customerId: $customer->id));

        $this->assertSame(3, $peak);
    }

    public function test_the_overview_spreads_cost_across_customers_and_the_command_runs(): void
    {
        foreach ([1.0, 2.0, 10.0] as $cost) {
            $this->attempt($this->customer(), ['cost_nok' => $cost, 'started_at' => now()->subHour(), 'finished_at' => now()->subHour()]);
        }

        $overview = app(AiCapacityCalibrationService::class)->overview($this->window());

        $this->assertCount(3, $overview['customers']);
        $this->assertSame(10.0, $overview['customers'][0]['settled_cost_nok'], 'Heaviest first.');
        $this->assertSame(2.0, $overview['median_cost_nok']);
        $this->assertSame(10.0, $overview['max_cost_nok']);

        $this->artisan('ai:capacity-analysis', ['--days' => 7])
            ->expectsOutputToContain('indicative only')
            ->expectsOutputToContain('median settled cost 2')
            ->assertSuccessful();
    }

    private function window(): AiUsagePeriod
    {
        return new AiUsagePeriod(CarbonImmutable::parse('2026-10-01 00:00:00'), CarbonImmutable::parse('2026-11-01 00:00:00'));
    }

    private function filter(): AiUsageFilter
    {
        return new AiUsageFilter($this->window());
    }

    /** @param array<string, mixed> $overrides */
    private function attempt(Customer $customer, array $overrides = []): AiUsageAttempt
    {
        return AiUsageAttempt::query()->create(array_merge([
            'customer_id' => $customer->id, 'attribution' => 'customer',
            'ledger_version' => AiUsageAttempt::LEDGER_VERSION,
            'feature' => 'wiki', 'operation_key' => 'wiki.verify_claim',
            'provider' => 'openai', 'endpoint' => 'responses', 'model' => 'gpt-4.1-mini',
            'status' => AiUsageAttempt::STATUS_SUCCESS,
            'input_tokens' => 100, 'output_tokens' => 10, 'total_tokens' => 110,
            'cost_nok' => 1.0, 'cost_status' => 'known', 'settlement_status' => AiUsageAttempt::SETTLEMENT_SETTLED,
            'started_at' => now(), 'finished_at' => now(),
        ], $overrides));
    }

    /** @param array<string, mixed> $attributes */
    private function customer(array $attributes = []): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create(array_merge([
            'name' => 'Calibration '.Str::random(8),
            'slug' => 'calibration-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ], $attributes));
    }
}
