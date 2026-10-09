<?php

namespace Tests\Feature\Ai\Experience;

use App\Models\AiCustomerExperiencePeriod;
use App\Models\AiCustomerExperiencePeriodRevision;
use App\Models\AiUsageAttempt;
use App\Services\Ai\Experience\AiExperienceAttribution;
use App\Services\Ai\Experience\AiExperienceSnapshotService;
use App\Services\EnterpriseWiki\Knowledge\WikiKnowledgeSourceRegistry;
use App\Services\Modules\ModuleEntitlementService;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAiExperienceScenarios;
use Tests\TestCase;

/**
 * One snapshot per customer and billing period: trusted ledger usage, with the customer's shape in
 * the period frozen once the period has ended.
 */
class AiExperienceSnapshotTest extends TestCase
{
    use CreatesAiExperienceScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pinCapacityConfig();
        Carbon::setTestNow('2026-10-20 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_only_trusted_rows_count_and_legacy_rows_make_the_period_partial(): void
    {
        $this->trustedBoundaryAt();
        $customer = $this->experienceCustomer('Trusted AS', ['basis'], users: 2);
        $this->experienceAttempt($customer, '2026-10-05 10:00:00', 10.0);
        // Legacy (pre-boundary ledger) and unattributed rows never enter the figures.
        $this->experienceAttempt($customer, '2026-10-06 10:00:00', 99.0, ['ledger_version' => null]);
        $this->experienceAttempt($customer, '2026-10-06 11:00:00', 77.0, ['attribution' => 'unattributed']);

        $this->service()->refreshCustomer($customer);

        $row = AiCustomerExperiencePeriod::query()->where('customer_id', $customer->id)->sole();
        $this->assertSame(1, $row->calls);
        $this->assertEqualsWithDelta(10.0, $row->settled_cost_nok, 0.0001);
        $this->assertEqualsWithDelta(100.0, $row->settled_units, 0.01);
        $this->assertSame(1, $row->legacy_calls);
        $this->assertSame(AiCustomerExperiencePeriod::COVERAGE_PARTIAL, $row->coverage);
    }

    public function test_a_period_after_the_boundary_without_legacy_rows_is_fully_covered_and_one_before_it_is_partial(): void
    {
        $this->trustedBoundaryAt('2026-10-10 00:00:00');
        $early = $this->experienceCustomer('Early AS', anchor: '2026-10-01 00:00:00');
        $this->experienceAttempt($early, '2026-10-12 10:00:00', 5.0);

        $this->service()->refreshCustomer($early);

        // The first period started before trusted data existed: never compared as if complete.
        $this->assertSame(AiCustomerExperiencePeriod::COVERAGE_PARTIAL, AiCustomerExperiencePeriod::query()->where('customer_id', $early->id)->sole()->coverage);

        Carbon::setTestNow('2026-11-20 12:00:00');
        $this->service()->refreshCustomer($early);

        $november = AiCustomerExperiencePeriod::query()->where('customer_id', $early->id)->where('period_start', '2026-11-01 00:00:00')->sole();
        $this->assertSame(AiCustomerExperiencePeriod::COVERAGE_FULL, $november->coverage);
    }

    public function test_pending_and_unresolved_cost_stays_separate_from_settled_cost(): void
    {
        $this->trustedBoundaryAt();
        $customer = $this->experienceCustomer('Pending AS');
        $this->experienceAttempt($customer, '2026-10-05 10:00:00', 20.0);
        $this->experienceAttempt($customer, '2026-10-05 11:00:00', 0.0, ['cost_nok' => null, 'reserved_cost_nok' => 3.0, 'settlement_status' => AiUsageAttempt::SETTLEMENT_PENDING, 'status' => AiUsageAttempt::STATUS_TIMEOUT]);
        $this->experienceAttempt($customer, '2026-10-05 12:00:00', 0.0, ['cost_nok' => null, 'reserved_cost_nok' => 2.0, 'settlement_status' => AiUsageAttempt::SETTLEMENT_UNRESOLVED, 'status' => AiUsageAttempt::STATUS_UNCERTAIN]);

        $this->service()->refreshCustomer($customer);

        $row = AiCustomerExperiencePeriod::query()->where('customer_id', $customer->id)->sole();
        $this->assertSame(3, $row->calls);
        $this->assertSame(1, $row->successful_calls);
        $this->assertSame(2, $row->failed_calls);
        $this->assertSame(1, $row->pending_calls);
        $this->assertSame(1, $row->unresolved_calls);
        $this->assertEqualsWithDelta(20.0, $row->settled_cost_nok, 0.0001);
        $this->assertEqualsWithDelta(3.0, $row->pending_reserved_cost_nok, 0.0001);
        $this->assertEqualsWithDelta(2.0, $row->unresolved_reserved_cost_nok, 0.0001);
        $this->assertEqualsWithDelta(200.0, $row->settled_units, 0.01);
        $this->assertEqualsWithDelta(50.0, $row->reserved_units, 0.01);
    }

    public function test_users_packages_tier_and_capacity_are_captured_for_the_period(): void
    {
        $this->trustedBoundaryAt();
        $customer = $this->experienceCustomer('Shape AS', ['basis', 'risk', 'tender'], users: 3, tier: 'level_2');
        // Inactive users are not billable and do not size the capacity.
        $this->experienceUser($customer, active: false);
        $this->experienceAttempt($customer, '2026-10-05 10:00:00', 105.0, ['capacity_verdict' => 'warn']);

        $this->service()->refreshCustomer($customer);

        $row = AiCustomerExperiencePeriod::query()->where('customer_id', $customer->id)->sole();
        $this->assertSame(3, $row->active_users_count);
        $this->assertSame(['basis', 'risk', 'tender'], $row->active_packages);
        $this->assertSame('basis+risk+tender', $row->module_mix);
        $this->assertSame('level_2', $row->tier_key);
        $this->assertSame(1.5, $row->tier_multiplier);
        // 500 + 3 × 50 + 150 + 400 = 1200; × 1.5 = 1800.
        $this->assertSame(1200, $row->base_units_per_month);
        $this->assertSame(1200, $row->calculated_base_capacity);
        $this->assertSame(1800, $row->calculated_total_capacity);
        $this->assertSame(1800, $row->included_units);
        $this->assertSame('tier', $row->capacity_source);
        $this->assertNull($row->override_units);
        $this->assertEqualsWithDelta(58.33, $row->used_percent, 0.01);
        $this->assertSame(1, $row->verdict_warn);
        $this->assertSame(AiCustomerExperiencePeriod::STATUS_OPEN, $row->status);
        $this->assertSame('month', $row->billing_interval);
        $this->assertSame(1, $row->period_months);
    }

    public function test_an_override_is_recorded_beside_the_tier_it_overrides(): void
    {
        $this->trustedBoundaryAt();
        $customer = $this->experienceCustomer('Override AS', ['basis'], users: 2);
        $customer->forceFill(['included_ai_units' => 9000])->save();

        $this->service()->refreshCustomer($customer);

        $row = AiCustomerExperiencePeriod::query()->where('customer_id', $customer->id)->sole();
        $this->assertSame('override', $row->capacity_source);
        $this->assertSame(9000, $row->override_units);
        $this->assertSame(9000, $row->included_units);
        $this->assertSame(600, $row->calculated_total_capacity);
    }

    public function test_the_context_is_frozen_once_the_period_has_ended_while_usage_follows_the_ledger(): void
    {
        $this->trustedBoundaryAt();
        $customer = $this->experienceCustomer('Frozen AS', ['basis', 'tender'], users: 2, tier: 'level_1');
        $this->experienceAttempt($customer, '2026-10-05 10:00:00', 10.0);
        $this->service()->refreshCustomer($customer);

        // After October the customer grows: more users, another option, a higher tier.
        Carbon::setTestNow('2026-11-05 12:00:00');
        $this->experienceUser($customer);
        $this->experienceUser($customer);
        app(ModuleEntitlementService::class)->activatePackage($customer, 'supplier');
        $customer->forceFill(['ai_capacity_tier' => 'level_3'])->save();
        // A late settlement still belongs to October.
        $this->experienceAttempt($customer, '2026-10-30 10:00:00', 5.0);

        $this->service()->refreshCustomer($customer->refresh());

        $october = AiCustomerExperiencePeriod::query()->where('customer_id', $customer->id)->where('period_start', '2026-10-01 00:00:00')->sole();
        $this->assertSame(AiCustomerExperiencePeriod::STATUS_FINAL, $october->status);
        $this->assertNotNull($october->finalized_at);
        $this->assertSame(2, $october->active_users_count);
        $this->assertSame(['basis', 'tender'], $october->active_packages);
        $this->assertSame('level_1', $october->tier_key);
        $this->assertSame(1000, $october->included_units);
        $this->assertSame(2, $october->calls);
        $this->assertEqualsWithDelta(15.0, $october->settled_cost_nok, 0.0001);

        // November is read from the customer as it is now.
        $november = AiCustomerExperiencePeriod::query()->where('customer_id', $customer->id)->where('period_start', '2026-11-01 00:00:00')->sole();
        $this->assertSame(4, $november->active_users_count);
        $this->assertSame(['basis', 'supplier', 'tender'], $november->active_packages);
        $this->assertSame('level_3', $november->tier_key);
        // (500 + 4 × 50 + 150 + 400) × 2 = 2500.
        $this->assertSame(2500, $november->included_units);
    }

    public function test_a_final_period_is_never_changed_silently_and_a_recheck_writes_a_revision(): void
    {
        $this->trustedBoundaryAt();
        $customer = $this->experienceCustomer('Revision AS');
        $this->experienceAttempt($customer, '2026-10-05 10:00:00', 10.0);
        Carbon::setTestNow('2026-11-10 12:00:00');
        $this->service()->refreshCustomer($customer);
        $october = AiCustomerExperiencePeriod::query()->where('customer_id', $customer->id)->where('period_start', '2026-10-01 00:00:00')->sole();
        $this->assertTrue($october->isFinal());

        $this->experienceAttempt($customer, '2026-10-20 10:00:00', 4.0);

        $stats = $this->service()->refreshCustomer($customer);
        $this->assertSame(1, $stats['skipped']);
        $this->assertSame(1, $october->refresh()->calls);

        $stats = $this->service()->refreshCustomer($customer, recheckFinal: true);
        $this->assertSame(1, $stats['revised']);
        $october->refresh();
        $this->assertSame(2, $october->calls);
        $this->assertSame(1, $october->revision);

        $revision = AiCustomerExperiencePeriodRevision::query()->where('ai_customer_experience_period_id', $october->id)->sole();
        $this->assertSame('recheck', $revision->reason);
        $this->assertSame(['from' => 1, 'to' => 2], $revision->changes['calls']);
        $this->assertEqualsWithDelta(14.0, $revision->changes['settled_cost_nok']['to'], 0.0001);

        // Nothing changed since: no further revision.
        $this->assertSame(0, $this->service()->refreshCustomer($customer, recheckFinal: true)['revised']);
        $this->assertSame(1, AiCustomerExperiencePeriodRevision::query()->count());
    }

    public function test_usage_is_attributed_per_feature_and_wiki_work_to_the_module_that_handed_the_source_over(): void
    {
        $this->trustedBoundaryAt();
        $customer = $this->experienceCustomer('Features AS', ['basis', 'supplier', 'tender']);
        $this->experienceAttempt($customer, '2026-10-05 10:00:00', 6.0, ['operation_key' => 'tender.requirement_answer']);
        $this->experienceAttempt($customer, '2026-10-05 11:00:00', 3.0, ['operation_key' => 'wiki.generate_page']);
        $this->experienceAttempt($customer, '2026-10-05 12:00:00', 1.0, ['operation_key' => 'wiki.generate_page', 'resource_type' => 'supplier', 'resource_id' => 4]);
        $this->experienceAttempt($customer, '2026-10-05 13:00:00', 0.0, ['operation_key' => 'wiki.generate_page', 'resource_type' => 'risk', 'cost_nok' => null, 'reserved_cost_nok' => 0.5, 'settlement_status' => AiUsageAttempt::SETTLEMENT_PENDING]);

        $this->service()->refreshCustomer($customer);

        $features = AiCustomerExperiencePeriod::query()->where('customer_id', $customer->id)->sole()
            ->features()->get()->keyBy('feature_key');
        $this->assertEqualsCanonicalizing(['tender', 'wiki', 'wiki.supplier', 'wiki.risk'], $features->keys()->all());
        $this->assertEqualsWithDelta(6.0, $features['tender']->settled_cost_nok, 0.0001);
        $this->assertEqualsWithDelta(30.0, $features['wiki']->settled_units, 0.01);
        $this->assertSame(1, $features['wiki.supplier']->calls);
        $this->assertEqualsWithDelta(0.0, $features['wiki.risk']->settled_cost_nok, 0.0001);
        $this->assertEqualsWithDelta(5.0, $features['wiki.risk']->reserved_units, 0.01);
    }

    public function test_every_wiki_knowledge_source_maps_to_the_module_it_comes_from(): void
    {
        $registry = app(WikiKnowledgeSourceRegistry::class);

        foreach (array_keys(WikiKnowledgeSourceRegistry::SOURCES) as $sourceType) {
            $this->assertSame(
                $registry->get($sourceType)->sourceModule(),
                AiExperienceAttribution::WIKI_ORIGIN_MODULES[$sourceType] ?? null,
                "Wiki source [{$sourceType}] has no attribution module.",
            );
        }

        foreach (array_keys(WikiKnowledgeSourceRegistry::DEPOSIT_ONLY_SOURCES) as $sourceType) {
            $this->assertArrayHasKey($sourceType, AiExperienceAttribution::WIKI_ORIGIN_MODULES);
        }
    }

    public function test_without_trusted_data_nothing_is_snapshotted(): void
    {
        $customer = $this->experienceCustomer('Empty AS');
        $this->experienceAttempt($customer, '2026-10-05 10:00:00', 10.0, ['ledger_version' => null]);

        $this->assertSame(0, array_sum($this->service()->refreshAll()));
        $this->assertSame(0, AiCustomerExperiencePeriod::query()->count());
    }

    public function test_an_active_customer_without_usage_gets_a_zero_period_so_medians_include_it(): void
    {
        $this->trustedBoundaryAt();
        $quiet = $this->experienceCustomer('Quiet AS');

        $this->service()->refreshAll();

        $row = AiCustomerExperiencePeriod::query()->where('customer_id', $quiet->id)->sole();
        $this->assertSame(0, $row->calls);
        $this->assertEqualsWithDelta(0.0, $row->settled_units, 0.01);
    }

    public function test_the_refresh_command_backfills_and_refreshes_one_period(): void
    {
        $this->trustedBoundaryAt();
        $customer = $this->experienceCustomer('Command AS');
        $this->experienceAttempt($customer, '2026-10-05 10:00:00', 10.0);
        Carbon::setTestNow('2026-12-10 12:00:00');

        $this->artisan('ai:experience-refresh', ['--customer' => $customer->id])
            ->expectsOutputToContain('Created 3')
            ->assertSuccessful();
        $this->assertSame(3, AiCustomerExperiencePeriod::query()->where('customer_id', $customer->id)->count());

        $this->experienceAttempt($customer, '2026-10-06 10:00:00', 1.0);
        $this->artisan('ai:experience-refresh', ['--customer' => $customer->id, '--period' => '2026-10-15'])
            ->expectsOutputToContain('Period: revised')
            ->assertSuccessful();

        $this->artisan('ai:experience-refresh', ['--period' => '2026-10-15'])->assertFailed();
    }

    public function test_the_refresh_is_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains((string) $event->command, 'ai:experience-refresh'));

        $this->assertCount(1, $events);
        $this->assertSame('15 4 * * *', $events->first()->expression);
    }

    private function service(): AiExperienceSnapshotService
    {
        // A fresh instance per call: the trusted boundary is memoised per service.
        return app()->make(AiExperienceSnapshotService::class);
    }
}
