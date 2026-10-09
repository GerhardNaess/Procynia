<?php

namespace Tests\Feature\Ai\Experience;

use App\Data\Ai\Experience\AiExperienceFilter;
use App\Models\Customer;
use App\Services\Ai\Experience\AiExperienceAnalysisService;
use App\Services\Ai\Experience\AiExperienceSnapshotService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAiExperienceScenarios;
use Tests\TestCase;

/**
 * The shared analysis over the experience snapshots: distributions, per user, per feature, per
 * module, module mix, tier, the current formula beside observed data, and the trend.
 *
 * Three customers, two final months (October, November 2026), monthly cost per period:
 *   Alfa  basis          2 users  level_1   10 / 20   (wiki)
 *   Beta  basis+tender   4 users  level_2   50 / 30   (wiki 10 + tender 40 / wiki 10 + tender 20)
 *   Gamma basis+risk     1 user   level_1    5 /  0   (wiki.risk)
 */
class AiExperienceAnalysisTest extends TestCase
{
    use CreatesAiExperienceScenarios;
    use RefreshDatabase;

    /** @var array<string, Customer> */
    private array $customers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pinCapacityConfig();
        Carbon::setTestNow('2026-10-02 12:00:00');
        // The migrations seed a default customer; it would add a zero-usage period of its own.
        Customer::query()->update(['is_active' => false]);
        $this->trustedBoundaryAt();

        $alfa = $this->experienceCustomer('Alfa AS', ['basis'], users: 2);
        $beta = $this->experienceCustomer('Beta AS', ['basis', 'tender'], users: 4, tier: 'level_2');
        $gamma = $this->experienceCustomer('Gamma AS', ['basis', 'risk'], users: 1);
        $this->customers = ['alfa' => $alfa, 'beta' => $beta, 'gamma' => $gamma];

        $this->experienceAttempt($alfa, '2026-10-05 10:00:00', 10.0);
        $this->experienceAttempt($alfa, '2026-11-05 10:00:00', 20.0);
        $this->experienceAttempt($beta, '2026-10-05 10:00:00', 10.0);
        $this->experienceAttempt($beta, '2026-10-06 10:00:00', 40.0, ['operation_key' => 'tender.requirement_answer', 'capacity_verdict' => 'warn']);
        $this->experienceAttempt($beta, '2026-11-05 10:00:00', 10.0);
        $this->experienceAttempt($beta, '2026-11-06 10:00:00', 20.0, ['operation_key' => 'tender.requirement_answer']);
        $this->experienceAttempt($gamma, '2026-10-05 10:00:00', 5.0, ['resource_type' => 'risk', 'resource_id' => 3]);

        // Snapshots taken while the periods ran, then finalised after the settle grace.
        $this->snapshots()->refreshAll();
        Carbon::setTestNow('2026-11-02 12:00:00');
        $this->snapshots()->refreshAll();
        Carbon::setTestNow('2026-12-10 12:00:00');
        $this->snapshots()->refreshAll();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_overview_reads_final_fully_covered_periods_with_median_p75_and_p95(): void
    {
        $report = $this->analysis()->report(new AiExperienceFilter);

        $this->assertSame(['customers' => 3, 'periods' => 6, 'calls' => 7, 'sufficient' => false], $report['basis']);
        // December is still open and left out unless asked for.
        $this->assertSame(3, $report['excluded_incomplete']);
        $this->assertSame(115.0, $report['overview']['settled_cost_nok']);
        // Monthly cost per customer/period: 0, 5, 10, 20, 30, 50.
        $this->assertSame(['n' => 6, 'mean' => 19.1667, 'median' => 15.0, 'p75' => 27.5, 'p95' => 45.0, 'min' => 0.0, 'max' => 50.0], $report['overview']['cost']);
        $this->assertSame(150.0, $report['overview']['units']['median']);

        $this->assertCount(9, $this->analysis()->report(new AiExperienceFilter(includeIncomplete: true))['rows']);
    }

    public function test_the_customer_list_sorts_by_cost_utilisation_or_calls(): void
    {
        $byCost = $this->analysis()->report(new AiExperienceFilter, 'cost')['rows'];
        $this->assertSame(['Beta AS', 50.0], [$byCost[0]['customer'], $byCost[0]['settled_cost_nok']]);

        // Alfa in November: 200 units of 600 included = 33.33 %, the highest share.
        $byUtilisation = $this->analysis()->report(new AiExperienceFilter, 'utilization')['rows'];
        $this->assertSame(['Alfa AS', 33.33], [$byUtilisation[0]['customer'], $byUtilisation[0]['used_percent']]);

        $byCalls = $this->analysis()->report(new AiExperienceFilter, 'calls')['rows'];
        $this->assertSame(2, $byCalls[0]['calls']);
    }

    public function test_cost_and_units_per_active_user(): void
    {
        $perUser = $this->analysis()->report(new AiExperienceFilter)['per_user'];

        // Alfa 5 / 10, Beta 12.5 / 7.5, Gamma 5 / 0.
        $this->assertSame(6.25, $perUser['cost']['median']);
        $this->assertSame(62.5, $perUser['units']['median']);
        $this->assertSame(11.875, $perUser['cost']['p95']);
    }

    public function test_usage_per_feature_names_wiki_work_by_the_module_it_came_from(): void
    {
        $features = collect($this->analysis()->report(new AiExperienceFilter)['features'])->keyBy('key');

        $this->assertSame(60.0, $features['tender']['settled_cost_nok']);
        $this->assertSame(1, $features['tender']['customers']);
        $this->assertSame(2, $features['tender']['periods']);
        $this->assertSame(52.2, $features['tender']['share']);
        $this->assertSame(5.0, $features['wiki.risk']['settled_cost_nok']);
        $this->assertSame(50.0, $features['wiki']['settled_cost_nok']);
    }

    public function test_module_analysis_compares_customers_with_and_without_without_claiming_cause(): void
    {
        $tender = collect($this->analysis()->report(new AiExperienceFilter)['modules'])->keyBy('package')['tender'];

        $this->assertSame(1, $tender['customers_with']);
        $this->assertSame(2, $tender['customers_without']);
        $this->assertSame(400.0, $tender['with_units']['median']);
        $this->assertSame(75.0, $tender['without_units']['median']);
        $this->assertSame(325.0, $tender['median_difference_units']);
        // Directly attributed to Tender's own feature: 400 and 200 units.
        $this->assertSame(300.0, $tender['direct_units']['median']);
        $this->assertArrayNotHasKey('caused_units', $tender);
    }

    public function test_module_mixes_group_customers_with_the_same_packages(): void
    {
        $mixes = collect($this->analysis()->report(new AiExperienceFilter)['module_mixes'])->keyBy('module_mix');

        $this->assertEqualsCanonicalizing(['basis', 'basis+tender', 'basis+risk'], $mixes->keys()->all());
        $this->assertSame(4.0, $mixes['basis+tender']['users']['median']);
        $this->assertSame(400.0, $mixes['basis+tender']['units']['median']);
        $this->assertSame(1, $mixes['basis']['basis']['customers']);
    }

    public function test_tier_analysis_reports_utilisation_and_how_often_capacity_was_tight(): void
    {
        $tiers = collect($this->analysis()->report(new AiExperienceFilter)['tiers'])->keyBy('tier_key');

        $this->assertSame(4, $tiers['level_1']['basis']['periods']);
        $this->assertSame(2, $tiers['level_2']['basis']['periods']);
        // Beta's October carried a warn verdict.
        $this->assertSame(1, $tiers['level_2']['periods_tight']);
        $this->assertSame(0, $tiers['level_2']['would_have_blocked']);
        // Beta: (500 + 4×50 + 400) × 1.5 = 1650; 500 / 1650 and 300 / 1650.
        $this->assertSame(24.24, $tiers['level_2']['utilization']['median']);
    }

    public function test_filters_narrow_by_customer_feature_period_tier_package_and_users(): void
    {
        $analysis = $this->analysis();

        $this->assertSame(['Beta AS'], array_values(array_unique(array_column($analysis->report(new AiExperienceFilter(customerId: $this->customers['beta']->id))['rows'], 'customer'))));

        // A feature filter keeps the periods where it was used and reads that feature's usage only.
        $tender = $analysis->report(new AiExperienceFilter(feature: 'tender'))['rows'];
        $this->assertCount(2, $tender);
        $this->assertEqualsCanonicalizing([40.0, 20.0], array_column($tender, 'settled_cost_nok'));

        $november = $analysis->report(new AiExperienceFilter(from: CarbonImmutable::parse('2026-11-01'), to: CarbonImmutable::parse('2026-11-30')))['rows'];
        $this->assertCount(3, $november);

        $this->assertCount(2, $analysis->report(new AiExperienceFilter(tier: 'level_2'))['rows']);
        $this->assertCount(2, $analysis->report(new AiExperienceFilter(package: 'risk'))['rows']);
        $this->assertCount(2, $analysis->report(new AiExperienceFilter(usersMin: 3))['rows']);
        $this->assertCount(4, $analysis->report(new AiExperienceFilter(usersMax: 2))['rows']);
        $this->assertCount(0, $analysis->report(new AiExperienceFilter(interval: 'year'))['rows']);
    }

    public function test_the_current_formula_is_shown_beside_observed_data_with_neutral_signals_only(): void
    {
        $formula = $this->analysis()->report(new AiExperienceFilter)['formula'];

        $this->assertSame(500, $formula['model']['basis']);
        $this->assertSame(50, $formula['model']['per_user']);
        $this->assertSame(400, $formula['model']['options']['tender']);
        $this->assertSame(['level_1' => 1.0, 'level_2' => 1.5, 'level_3' => 2.0], $formula['model']['tiers']);

        $factors = collect($formula['factors'])->keyBy('key');
        $this->assertSame(62.5, $factors['per_user']['observed']['median']);
        $this->assertContains('insufficient_data', $factors['per_user']['signals']);
        // Tender's own usage (400, 200 units/month) against its weight 400: never «over» half the time.
        $this->assertSame(0.0, $factors['tender']['share_above']);
        $this->assertContains('mostly_below', $factors['tender']['signals']);
        $this->assertContains('mostly_unused', $factors['level_1']['signals']);

        // Nothing proposes a value.
        $shipped = json_encode($formula);
        $this->assertDoesNotMatchRegularExpression('/recommend|suggest|proposed|new_value/i', $shipped);
    }

    public function test_a_large_enough_sample_drops_the_insufficient_data_warning(): void
    {
        config()->set('ai_experience.minimum_sample', ['customers' => 3, 'periods' => 6, 'calls' => 7]);

        $this->assertTrue($this->analysis()->report(new AiExperienceFilter)['basis']['sufficient']);
    }

    public function test_a_customer_period_detail_holds_the_setup_feature_and_operation_breakdown(): void
    {
        $id = collect($this->analysis()->report(new AiExperienceFilter(customerId: $this->customers['beta']->id))['rows'])
            ->firstWhere('period_start', CarbonImmutable::parse('2026-10-01 00:00:00', 'UTC'))['id'];

        $detail = $this->analysis()->detail($id);

        $this->assertSame('Beta AS', $detail['customer']);
        $this->assertSame(4, $detail['users']);
        $this->assertSame(['basis', 'tender'], $detail['packages']);
        $this->assertSame('level_2', $detail['tier_key']);
        $this->assertSame(1100, $detail['base_units_per_month']);
        $this->assertSame(1650, $detail['included_units']);
        $this->assertSame(['tender', 'wiki'], array_column($detail['features'], 'key'));
        $this->assertSame(80.0, $detail['features'][0]['share']);
        $this->assertSame(['tender.requirement_answer', 'wiki.generate_page'], array_column($detail['operations'], 'operation_key'));
        $this->assertSame(1, $detail['verdict_warn']);
        $this->assertNull($this->analysis()->detail(999999));
    }

    public function test_the_calendar_trend_counts_customers_and_spreads_cost(): void
    {
        $trend = collect($this->analysis()->trend(new AiExperienceFilter(from: CarbonImmutable::parse('2026-10-01'), to: CarbonImmutable::parse('2026-11-30'))))->keyBy('month');

        $this->assertSame(['2026-10', '2026-11'], $trend->keys()->all());
        $this->assertSame(65.0, $trend['2026-10']['cost_nok']);
        $this->assertSame(3, $trend['2026-10']['customers']);
        $this->assertSame(10.0, $trend['2026-10']['median_cost_nok']);
        $this->assertSame(2, $trend['2026-11']['customers']);
        $this->assertSame(500.0, $trend['2026-11']['units']);
    }

    public function test_the_report_runs_a_bounded_number_of_queries_however_many_customers(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->analysis()->report(new AiExperienceFilter);
            $this->analysis()->trend(new AiExperienceFilter);
            $this->analysis()->operations(new AiExperienceFilter);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $before = $count();

        foreach (range(1, 6) as $i) {
            $customer = $this->experienceCustomer("Extra {$i} AS", ['basis', 'supplier'], users: $i, anchor: '2026-10-01 00:00:00');
            $this->experienceAttempt($customer, '2026-10-07 10:00:00', 1.0 * $i);
        }
        $this->snapshots()->refreshAll(recheckFinal: true);

        $this->assertSame($before, $count());
        $this->assertLessThanOrEqual(8, $before);
    }

    public function test_capacity_analysis_prints_the_same_experience_analysis(): void
    {
        $this->artisan('ai:capacity-analysis', ['--experience' => true])
            ->expectsOutputToContain('Data basis: 3 customers · 6 periods · 7 AI calls · 3 open/partial periods left out')
            ->expectsOutputToContain('Too little data for a reliable assessment')
            ->expectsOutputToContain('median 15 · p75 27.5 · p95 45')
            ->expectsOutputToContain('basis+tender')
            ->expectsOutputToContain('Read-only')
            ->assertSuccessful();
    }

    private function analysis(): AiExperienceAnalysisService
    {
        return app(AiExperienceAnalysisService::class);
    }

    private function snapshots(): AiExperienceSnapshotService
    {
        return app()->make(AiExperienceSnapshotService::class);
    }
}
