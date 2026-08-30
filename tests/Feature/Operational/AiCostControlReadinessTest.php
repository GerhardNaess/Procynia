<?php

namespace Tests\Feature\Operational;

use App\Models\AdminNotification;
use App\Models\AiModelPrice;
use App\Models\AiRuntimeControl;
use App\Models\AiUsageAttempt;
use App\Models\Customer;
use App\Models\CustomerAiUsageReservation;
use App\Models\ExchangeRate;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\SavedNotice;
use App\Services\Operations\RuntimePreflightService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Production readiness: the preconditions that must fail a deploy, and the sweep that stops a
 * safe-but-invisible state from becoming permanent.
 */
class AiCostControlReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-20 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // =========================================================================
    // Preflight
    // =========================================================================

    public function test_a_migrated_runtime_passes_the_cost_control_preflight(): void
    {
        $this->seedPricing();

        $checks = $this->costControlChecks();

        $this->assertSame(RuntimePreflightService::STATUS_PASS, $checks['AI cost control schema']['status']);
        $this->assertSame(RuntimePreflightService::STATUS_PASS, $checks['AI runtime control row']['status']);
        $this->assertSame(RuntimePreflightService::STATUS_PASS, $checks['AI model pricing']['status']);
        $this->assertSame(RuntimePreflightService::STATUS_PASS, $checks['AI cost control configuration']['status']);
    }

    public function test_operational_enforcement_without_any_model_price_is_a_deploy_blocker(): void
    {
        // Enforcement that cannot price a single call looks active but protects nothing.
        AiRuntimeControl::query()->orderBy('id')->firstOrFail()
            ->forceFill(['operational_budget_enabled' => true, 'global_daily_nok_limit' => 1000])->save();

        $check = $this->costControlChecks()['AI model pricing'];

        $this->assertSame(RuntimePreflightService::STATUS_FAIL, $check['status']);
        $this->assertTrue($check['critical']);
        $this->assertTrue(app(RuntimePreflightService::class)->hasCriticalFailure([$check]));
    }

    public function test_an_empty_catalogue_without_enforcement_is_only_a_warning(): void
    {
        $check = $this->costControlChecks()['AI model pricing'];

        $this->assertSame(RuntimePreflightService::STATUS_WARN, $check['status']);
        $this->assertFalse(app(RuntimePreflightService::class)->hasCriticalFailure([$check]));
    }

    public function test_a_missing_runtime_control_row_is_a_deploy_blocker(): void
    {
        // The guard fails closed without it, which is correct but is a total AI outage.
        AiRuntimeControl::query()->delete();

        $check = $this->costControlChecks()['AI runtime control row'];

        $this->assertSame(RuntimePreflightService::STATUS_FAIL, $check['status']);
        $this->assertTrue($check['critical']);
    }

    public function test_an_active_global_stop_is_surfaced_as_a_warning_not_a_pass(): void
    {
        AiRuntimeControl::query()->orderBy('id')->firstOrFail()->forceFill(['global_ai_stop' => true])->save();

        $check = $this->costControlChecks()['AI runtime control row'];

        $this->assertSame(RuntimePreflightService::STATUS_WARN, $check['status']);
        $this->assertStringContainsStringIgnoringCase('global ai stop', $check['detail']);
    }

    public function test_contradictory_policy_configuration_is_a_deploy_blocker(): void
    {
        foreach ([
            ['procynia.ai.quota.critical_percent', 50],
            ['procynia.ai.pricing.critical_age_days', 1],
            ['procynia.ai.fx.critical_age_days', 1],
            ['procynia.ai.fx.fallback_usd_nok_rate', 0],
            ['procynia.ai.fx.safety_margin_percent', -5],
        ] as [$key, $value]) {
            $this->refreshApplication();
            Carbon::setTestNow('2026-09-20 10:00:00');
            config()->set($key, $value);

            $check = $this->costControlChecks()['AI cost control configuration'];

            $this->assertSame(RuntimePreflightService::STATUS_FAIL, $check['status'], "{$key} = {$value}");
        }
    }

    public function test_a_stale_exchange_rate_warns_but_never_blocks_the_deploy(): void
    {
        $this->seedPricing();
        ExchangeRate::query()->update(['rate_date' => '2026-08-01']);

        $check = $this->costControlChecks()['AI exchange rates'];

        // FX never stops AI on its own, so it must never stop a deploy either.
        $this->assertSame(RuntimePreflightService::STATUS_WARN, $check['status']);
        $this->assertFalse($check['critical']);
    }

    // =========================================================================
    // Health sweep
    // =========================================================================

    public function test_an_ageing_uncertain_hold_is_reported_to_internal_admins(): void
    {
        $this->seedPricing();
        $customer = $this->customer();
        $this->uncertainHold($customer, reservedAt: '2026-09-18 08:00:00');

        $this->artisan('ai:cost-control-health')->assertExitCode(0);

        $this->assertDatabaseHas('admin_notifications', ['type' => 'ai_uncertain_reservations_ageing']);
    }

    public function test_a_recent_uncertain_hold_is_not_reported_yet(): void
    {
        $this->seedPricing();
        $customer = $this->customer();
        $this->uncertainHold($customer, reservedAt: '2026-09-20 09:00:00');

        $this->artisan('ai:cost-control-health')->assertExitCode(0);

        $this->assertDatabaseMissing('admin_notifications', ['type' => 'ai_uncertain_reservations_ageing']);
    }

    public function test_the_sweep_reports_unpriced_attempts_and_never_treats_them_as_free(): void
    {
        $this->seedPricing();
        $customer = $this->customer();
        AiUsageAttempt::query()->create([
            'customer_id' => $customer->id, 'feature' => 'wiki', 'operation_key' => 'wiki.ask.answer',
            'provider' => 'openai', 'endpoint' => 'responses', 'model' => 'unpriced-model',
            'status' => AiUsageAttempt::STATUS_SUCCESS, 'cost_status' => 'unknown',
            'input_tokens' => 1000, 'output_tokens' => 500, 'total_tokens' => 1500,
            'started_at' => '2026-09-20 09:00:00',
        ]);

        $this->artisan('ai:cost-control-health')->assertExitCode(0);

        $this->assertDatabaseHas('admin_notifications', ['type' => 'ai_unpriced_attempts']);
        $this->assertNull(
            AiUsageAttempt::query()->where('model', 'unpriced-model')->value('cost_nok'),
            'An unpriceable call must stay unpriced, never settle at zero.',
        );
    }

    public function test_the_sweep_does_not_repeat_the_same_alert_within_a_day(): void
    {
        $this->seedPricing();
        $customer = $this->customer();
        $this->uncertainHold($customer, reservedAt: '2026-09-18 08:00:00');

        $this->artisan('ai:cost-control-health')->assertExitCode(0);
        $this->artisan('ai:cost-control-health')->assertExitCode(0);
        $this->artisan('ai:cost-control-health')->assertExitCode(0);

        $this->assertSame(
            1,
            AdminNotification::query()->where('type', 'ai_uncertain_reservations_ageing')->count(),
        );
    }

    public function test_the_sweep_never_releases_a_hold_it_reports(): void
    {
        $this->seedPricing();
        $customer = $this->customer();
        $reservation = $this->uncertainHold($customer, reservedAt: '2026-09-18 08:00:00');

        $this->artisan('ai:cost-control-health')->assertExitCode(0);

        // Deciding what an uncertain call cost is a human judgement, not a sweep's.
        $this->assertSame(
            CustomerAiUsageReservation::STATUS_UNCERTAIN,
            $reservation->fresh()->status,
        );
    }

    public function test_an_empty_price_catalogue_is_reported_by_the_sweep(): void
    {
        $this->artisan('ai:cost-control-health')->assertExitCode(0);

        $this->assertDatabaseHas('admin_notifications', ['type' => 'ai_model_price_catalogue_empty']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** @return array<string, array{name: string, status: string, detail: string, critical: bool}> */
    private function costControlChecks(): array
    {
        $checks = app(RuntimePreflightService::class)->run();
        $byName = [];

        foreach ($checks as $check) {
            $byName[$check['name']] = $check;
        }

        return $byName;
    }

    private function uncertainHold(Customer $customer, string $reservedAt): CustomerAiUsageReservation
    {
        return CustomerAiUsageReservation::query()->create([
            'customer_id' => $customer->id,
            'saved_notice_id' => SavedNotice::query()->create([
                'customer_id' => $customer->id, 'external_id' => 'RDY-'.Str::random(10),
                'title' => 'Readiness notice', 'buyer_name' => 'Procynia', 'status' => 'ACTIVE',
            ])->id,
            'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
            'operation' => 'test', 'status' => CustomerAiUsageReservation::STATUS_UNCERTAIN,
            'reserved_at' => $reservedAt, 'failure_reason' => 'http_504',
        ]);
    }

    private function seedPricing(): void
    {
        AiModelPrice::query()->firstOrCreate(
            ['provider' => 'openai', 'model' => 'gpt-5'],
            [
                'currency' => 'USD', 'input_price_per_1m_tokens' => 10.0, 'output_price_per_1m_tokens' => 30.0,
                'valid_from' => '2026-01-01', 'is_active' => true, 'last_verified_at' => '2026-09-19 00:00:00',
            ],
        );
        ExchangeRate::query()->firstOrCreate(
            ['base_currency' => 'USD', 'quote_currency' => 'NOK', 'rate_date' => '2026-09-19', 'source' => ExchangeRate::SOURCE_NORGES_BANK],
            ['rate' => 10.0, 'fetched_at' => now()],
        );
    }

    private function customer(): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => 'Readiness '.Str::random(8),
            'slug' => 'readiness-'.Str::lower(Str::random(10)),
            'language_id' => $language->id, 'nationality_id' => $nationality->id, 'is_active' => true,
            'subscription_plan' => Customer::PLAN_PRO, 'included_ai_credits' => 10,
        ]);
    }
}
