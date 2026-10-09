<?php

namespace App\Console\Commands;

use App\Data\Ai\Experience\AiExperienceFilter;
use App\Data\Ai\Usage\AiUsageFilter;
use App\Data\Ai\Usage\AiUsagePeriod;
use App\Models\Customer;
use App\Services\Ai\Commercial\AiCapacityCalibrationService;
use App\Services\Ai\Experience\AiExperienceAnalysisService;
use App\Services\Billing\CustomerBillingPeriodResolver;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attribute\AsCommand;
use Illuminate\Console\Command;

/**
 * Internal calibration report for the shared AI capacity (docs/operations/ai-capacity.md): usage,
 * cost, units, how often the current capacity would have blocked, peak concurrency and how well
 * the preflight estimates match actual cost. Read-only — it never adjusts a commercial value.
 *
 *   ai:capacity-analysis                         all customers, last 30 days
 *   ai:capacity-analysis --customer=12           one customer, its current billing period
 *   ai:capacity-analysis --customer=12 --days=14 one customer, last 14 days
 *   ai:capacity-analysis --operation=wiki.generate_page
 *   ai:capacity-analysis --experience            the experience model (AiExperienceAnalysisService),
 *                                                the same analysis Admin → AI-erfaring shows
 */
#[AsCommand(name: 'ai:capacity-analysis')]
class AiCapacityAnalysis extends Command
{
    /** Below this, the numbers are indicative only (the recommended minimum before deciding). */
    public const RECOMMENDED_MINIMUM_DAYS = 14;

    protected $signature = 'ai:capacity-analysis
                            {--customer= : Customer id; without --days its current billing period is used}
                            {--days= : Window in days back from now (default 30 without --customer)}
                            {--operation= : Limit to one operation key}
                            {--experience : Report the experience snapshots (per customer and billing period) instead}
                            {--include-incomplete : With --experience: also open and partly covered periods}';

    protected $description = 'Internal AI capacity calibration: usage, cost, units, would-have-blocked and estimate accuracy.';

    public function handle(AiCapacityCalibrationService $calibration, CustomerBillingPeriodResolver $billingPeriods, AiExperienceAnalysisService $experience): int
    {
        if ($this->option('experience')) {
            return $this->experience($experience);
        }

        $operation = $this->option('operation') ?: null;
        $customer = null;

        if ($this->option('customer') !== null) {
            $customer = Customer::query()->find((int) $this->option('customer'));

            if (! $customer instanceof Customer) {
                $this->error('Unknown customer.');

                return self::FAILURE;
            }
        }

        $days = $this->option('days') !== null ? max(1, (int) $this->option('days')) : null;
        $period = match (true) {
            $days !== null => new AiUsagePeriod(CarbonImmutable::now()->subDays($days), CarbonImmutable::now()),
            $customer instanceof Customer => $billingPeriods->current($customer)->toUsagePeriod(),
            default => new AiUsagePeriod(CarbonImmutable::now()->subDays(30), CarbonImmutable::now()),
        };

        $this->line(sprintf('Window: %s – %s%s', $period->start->toDateTimeString(), $period->end->toDateTimeString(), $operation ? " · operation {$operation}" : ''));
        $this->line(sprintf('Units: nok_per_unit=%s (technical calibration value, not a price) · enforcement=%s', config('ai_customer_capacity.nok_per_unit'), config('ai_customer_capacity.enforcement')));

        if ($period->start->diffInDays($period->end) < self::RECOMMENDED_MINIMUM_DAYS) {
            $this->warn(sprintf('Window is under %d days: indicative only. Decide capacity on at least %d days, preferably a full billing period.', self::RECOMMENDED_MINIMUM_DAYS, self::RECOMMENDED_MINIMUM_DAYS));
        }

        if ($customer instanceof Customer) {
            $this->customer($calibration->customerReport($customer, $period, $operation));
        } else {
            $this->overview($calibration->overview($period, $operation));
        }

        $this->newLine();
        $this->info('Per operation — Estimate vs actual (settled calls with an estimate), cost distribution, failure and open rates');
        $this->table(
            ['Operation', 'Calls', 'Failed %', 'Open %', 'Mean actual', 'Median', 'p75', 'p95', 'Mean estimate', 'Estimate/actual', 'Assessment'],
            array_map(fn (array $row): array => [
                $row['operation_key'], $row['calls'],
                round($row['failure_rate'] * 100, 1), round($row['open_rate'] * 100, 1),
                $row['mean_actual_nok'] ?? '–', $row['median_actual_nok'] ?? '–', $row['p75_actual_nok'] ?? '–', $row['p95_actual_nok'] ?? '–',
                $row['mean_estimate_nok'] ?? '–', $row['estimate_actual_ratio'] ?? '–', $row['assessment'] ?? '–',
            ], $calibration->operationStatistics(new AiUsageFilter($period, customerId: $customer?->id, operation: $operation))),
        );

        return self::SUCCESS;
    }

    /**
     * The experience model, read through the same service as the Admin page. Descriptive only: it
     * prints distributions and neutral signals, never a proposed value.
     */
    private function experience(AiExperienceAnalysisService $experience): int
    {
        $customerId = $this->option('customer') !== null ? (int) $this->option('customer') : null;
        $report = $experience->report(new AiExperienceFilter(customerId: $customerId, includeIncomplete: (bool) $this->option('include-incomplete')));
        $basis = $report['basis'];
        $describe = fn (array $stats): string => $stats['n'] === 0 ? '–' : sprintf('median %s · p75 %s · p95 %s · mean %s', $stats['median'], $stats['p75'], $stats['p95'], $stats['mean']);

        $this->line(sprintf('Data basis: %d customers · %d periods · %d AI calls%s', $basis['customers'], $basis['periods'], $basis['calls'],
            $report['excluded_incomplete'] > 0 ? " · {$report['excluded_incomplete']} open/partial periods left out" : ''));

        if (! $basis['sufficient']) {
            $this->warn('Too little data for a reliable assessment. The figures are shown, but decide nothing on them yet.');
        }

        $this->table(['Measure (per month)', 'Distribution'], [
            ['Settled cost NOK per customer/period', $describe($report['overview']['cost'])],
            ['AI units used', $describe($report['overview']['units'])],
            ['Utilisation %', $describe($report['overview']['utilization'])],
            ['Cost NOK per active user', $describe($report['per_user']['cost'])],
            ['AI units per active user', $describe($report['per_user']['units'])],
        ]);

        $this->table(['Factor', 'Current value', 'Observed', 'Periods', 'Signals'], array_map(fn (array $factor): array => [
            $factor['factor'] === 'option' || $factor['factor'] === 'tier' ? "{$factor['factor']}:{$factor['key']}" : $factor['factor'],
            $factor['current'] ?? '–',
            $describe($factor['observed']),
            $factor['basis']['periods'],
            implode(', ', $factor['signals']) ?: '–',
        ], $report['formula']['factors']));

        $this->table(['Module mix', 'Periods', 'Customers', 'Median users', 'Units/month', 'Cost NOK/month'], array_map(fn (array $mix): array => [
            $mix['module_mix'], $mix['basis']['periods'], $mix['basis']['customers'], $mix['users']['median'] ?? '–',
            $describe($mix['units']), $describe($mix['cost']),
        ], $report['module_mixes']));

        $this->line('Read-only: nothing here changes a weight, multiplier, price or customer capacity.');

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $report */
    private function customer(array $report): void
    {
        $verdicts = $report['verdicts'];

        $this->table(['Measure', 'Value'], [
            ['Customer', "{$report['customer']} (#{$report['customer_id']})"],
            ['AI capacity tier', $this->tier($report)],
            ['Included units (period)', $report['included_units'] ?? 'none (no commercial limit)'],
            ['Capacity source', $report['included_source']],
            ['AI calls', $report['calls']],
            ['Settled cost NOK', $report['settled_cost_nok']],
            ['Units used', $report['used_units']],
            ['Units reserved (open)', $report['reserved_units']],
            ['Peak concurrent calls', $report['peak_concurrency']],
            ['Verdicts allow / warn / unmetered', "{$verdicts['allow']} / {$verdicts['warn']} / {$verdicts['unmetered']}"],
            ['Would have blocked (exhausted / insufficient)', "{$verdicts['would_have_blocked']} ({$verdicts['exhausted']} / {$verdicts['insufficient']})"],
        ]);

        $this->table(
            ['Feature', 'Operation', 'Model', 'Calls', 'Settled NOK', 'Units'],
            array_map(fn (array $row): array => [$row['feature'], $row['operation_key'], $row['model'], $row['calls'], $row['settled_cost_nok'], $row['used_units']], $report['breakdown']),
        );
    }

    /** @param array{customers: list<array<string, mixed>>, median_cost_nok: float, max_cost_nok: float} $overview */
    private function overview(array $overview): void
    {
        $this->table(
            ['Customer', 'Tier', 'Included', 'Source', 'Calls', 'Settled NOK', 'Units used', 'Reserved', 'Peak', 'Would block'],
            array_map(fn (array $row): array => [
                "{$row['customer']} (#{$row['customer_id']})",
                $row['tier_key'] ?? '–',
                $row['included_units'] ?? 'none',
                $row['included_source'],
                $row['calls'], $row['settled_cost_nok'], $row['used_units'], $row['reserved_units'],
                $row['peak_concurrency'], $row['verdicts']['would_have_blocked'],
            ], $overview['customers']),
        );

        $this->line(sprintf('Customers: %d · median settled cost %s NOK · heaviest %s NOK', count($overview['customers']), $overview['median_cost_nok'], $overview['max_cost_nok']));
    }

    /** @param array<string, mixed> $report */
    private function tier(array $report): string
    {
        return $report['tier_key'] === null ? 'none selected' : "{$report['tier_name']} ({$report['tier_key']})";
    }
}
