<?php

namespace App\Services\Ai\Commercial;

use App\Models\AiTokenEvent;
use App\Models\Customer;
use App\Models\CustomerAiCaseUsage;
use App\Services\Ai\Pricing\AiTokenCostEstimator;
use App\Services\Billing\BillingEntitlementService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Purpose: Build an internal profitability analysis for AI-active SavedNotice cases.
 * Inputs: A date range and an optional customer filter.
 * Returns: Summary, customer, and case profitability arrays for downstream reporting.
 * Side effects: None.
 */
class AiCaseProfitabilityService
{
    /**
     * Purpose: Create the profitability service with the historical AI cost estimator and entitlement service.
     * Inputs: The internal cost estimator and billing entitlement dependencies.
     * Returns: None.
     * Side effects: None.
     */
    public function __construct(
        private readonly AiTokenCostEstimator $costEstimator,
        private readonly BillingEntitlementService $billingEntitlementService,
    ) {
    }

    /**
     * Purpose: Resolve the internal AI profitability snapshot for the given period.
     * Inputs: The date range and an optional customer id filter.
     * Returns: A stable array with summary, customer rows, and case rows.
     * Side effects: Executes read-only database queries.
     *
     * @return array{
     *     summary: array<string, mixed>,
     *     customers: array<int, array<string, mixed>>,
     *     cases: array<int, array<string, mixed>>,
     * }
     */
    public function analyze(CarbonInterface $dateFrom, CarbonInterface $dateTo, ?int $customerId = null): array
    {
        [$rangeStart, $rangeEnd] = $this->normalizeRange($dateFrom, $dateTo);

        $caseUsages = $this->loadCaseUsages($rangeStart, $rangeEnd, $customerId);

        if ($caseUsages->isEmpty()) {
            return [
                'summary' => $this->emptySummary($rangeStart, $rangeEnd, $customerId),
                'customers' => [],
                'cases' => [],
            ];
        }

        $caseRows = $this->buildCaseRows($caseUsages);
        $this->attachTokenCosts($caseRows, $this->loadTokenEvents($rangeStart, $rangeEnd, $caseUsages));

        foreach ($caseRows as $index => $caseRow) {
            $caseRows[$index] = $this->finalizeCaseRow($caseRow);
        }

        $customerRows = $this->buildCustomerRows($caseRows);
        $summary = $this->buildSummary($caseRows, $rangeStart, $rangeEnd, $customerId);

        usort($caseRows, fn (array $left, array $right): int => $this->compareRiskRows($left, $right));
        usort($customerRows, fn (array $left, array $right): int => $this->compareRiskRows($left, $right));

        return [
            'summary' => $summary,
            'customers' => $customerRows,
            'cases' => $caseRows,
        ];
    }

    /**
     * Purpose: Normalize the requested date range into the application timezone.
     * Inputs: The user-supplied start and end dates.
     * Returns: A normalized inclusive start/end pair.
     * Side effects: None.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function normalizeRange(CarbonInterface $dateFrom, CarbonInterface $dateTo): array
    {
        $timezone = config('app.timezone') ?: 'UTC';
        $start = CarbonImmutable::instance($dateFrom)->setTimezone($timezone)->startOfDay();
        $end = CarbonImmutable::instance($dateTo)->setTimezone($timezone)->endOfDay();

        if ($end->lessThan($start)) {
            [$start, $end] = [$end, $start];
        }

        return [$start, $end];
    }

    /**
     * Purpose: Load AI case usages within the requested period.
     * Inputs: The normalized range and optional customer filter.
     * Returns: A collection of monthly AI case ledger rows.
     * Side effects: Executes a read-only database query.
     */
    private function loadCaseUsages(CarbonImmutable $rangeStart, CarbonImmutable $rangeEnd, ?int $customerId): Collection
    {
        return CustomerAiCaseUsage::query()
            ->with([
                'customer:id,name,subscription_plan,billing_interval,included_ai_credits,billing_discount_percent',
                'savedNotice:id,customer_id,external_id,title',
            ])
            ->when($customerId !== null, fn ($query) => $query->where('customer_id', $customerId))
            ->whereBetween('activated_at', [$rangeStart, $rangeEnd])
            ->orderBy('activated_at')
            ->orderBy('customer_id')
            ->orderBy('saved_notice_id')
            ->get();
    }

    /**
     * Purpose: Load token events that may belong to the selected case ledger rows.
     * Inputs: The normalized range and the already loaded case usages.
     * Returns: Token events limited to the relevant customers and SavedNotices.
     * Side effects: Executes a read-only database query.
     */
    private function loadTokenEvents(CarbonImmutable $rangeStart, CarbonImmutable $rangeEnd, Collection $caseUsages): Collection
    {
        $customerIds = $caseUsages
            ->pluck('customer_id')
            ->unique()
            ->values()
            ->all();

        $savedNoticeIds = $caseUsages
            ->pluck('saved_notice_id')
            ->unique()
            ->values()
            ->all();

        if ($customerIds === [] || $savedNoticeIds === []) {
            return collect();
        }

        return AiTokenEvent::query()
            ->whereIn('customer_id', $customerIds)
            ->whereIn('saved_notice_id', $savedNoticeIds)
            ->whereNotNull('saved_notice_id')
            ->whereBetween('created_at', [$rangeStart, $rangeEnd])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Purpose: Build the raw case rows before token costs are attached.
     * Inputs: The AI case ledger rows.
     * Returns: A list of case analysis rows.
     * Side effects: None.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildCaseRows(Collection $caseUsages): array
    {
        return $caseUsages->map(function (CustomerAiCaseUsage $usage): array {
            $customer = $usage->customer;
            $revenue = $customer instanceof Customer
                ? $this->resolveRevenueProxy($customer)
                : $this->missingRevenueProxy();

            return [
                'case_usage_id' => $usage->id,
                'customer_id' => $usage->customer_id,
                'customer_name' => $customer?->name ?? __('procynia.common.none'),
                'subscription_plan' => $customer?->subscription_plan,
                'plan_name' => $customer?->planName() ?? __('procynia.common.none'),
                'billing_interval' => $customer?->billing_interval ?? Customer::BILLING_MONTHLY,
                'billing_discount_percent' => (float) ($customer?->billing_discount_percent ?? 0),
                'included_ai_credits' => $revenue['included_ai_credits'],
                'monthly_plan_value_nok' => $revenue['monthly_plan_value_nok'],
                'allocated_revenue_nok' => $revenue['allocated_revenue_per_case_nok'],
                'average_revenue_per_case_nok' => $revenue['allocated_revenue_per_case_nok'],
                'revenue_status' => $revenue['status'],
                'saved_notice_id' => $usage->saved_notice_id,
                'saved_notice_external_id' => $usage->savedNotice?->external_id,
                'saved_notice_title' => $usage->savedNotice?->title,
                'activated_at' => $usage->activated_at?->toDateTimeString(),
                'period_start' => $usage->period_start?->toDateString(),
                'period_end' => $usage->period_end?->toDateString(),
                'source_operation_key' => $usage->source_operation_key,
                'token_event_count' => 0,
                'priceable_token_event_count' => 0,
                'zero_token_event_count' => 0,
                'unpriceable_token_event_count' => 0,
                'internal_cost_nok' => 0.0,
                'average_internal_cost_per_case_nok' => 0.0,
                'cost_status' => 'missing',
                'contribution_margin_nok' => null,
                'margin_percent' => null,
            ];
        })->all();
    }

    /**
     * Purpose: Attach token costs to the matching case rows.
     * Inputs: The mutable case row array and the relevant token events.
     * Returns: None.
     * Side effects: Mutates the case rows in memory only.
     *
     * @param array<int, array<string, mixed>> $caseRows
     */
    private function attachTokenCosts(array &$caseRows, Collection $tokenEvents): void
    {
        $caseIndex = [];

        foreach ($caseRows as $index => $caseRow) {
            $caseIndex[$this->caseKey(
                (int) $caseRow['customer_id'],
                (int) $caseRow['saved_notice_id'],
                (string) $caseRow['period_start'],
            )] = $index;
        }

        foreach ($tokenEvents as $event) {
            if (! $event instanceof AiTokenEvent || $event->saved_notice_id === null || $event->created_at === null) {
                continue;
            }

            $key = $this->caseKey(
                (int) $event->customer_id,
                (int) $event->saved_notice_id,
                $this->monthKey($event->created_at),
            );

            if (! array_key_exists($key, $caseIndex)) {
                continue;
            }

            $index = $caseIndex[$key];
            $caseRows[$index]['token_event_count']++;

            $estimate = $this->costEstimator->estimate($event);

            if ($estimate['status'] === AiTokenCostEstimator::RESULT_OK) {
                $caseRows[$index]['priceable_token_event_count']++;
                $caseRows[$index]['internal_cost_nok'] += (float) ($estimate['total_cost_nok'] ?? 0.0);
                continue;
            }

            if ($estimate['status'] === AiTokenCostEstimator::RESULT_NO_TOKENS) {
                $caseRows[$index]['zero_token_event_count']++;
                continue;
            }

            $caseRows[$index]['unpriceable_token_event_count']++;
        }
    }

    /**
     * Purpose: Finalize the cost and profitability fields for a single case row.
     * Inputs: The partially populated case row.
     * Returns: A normalized case row payload.
     * Side effects: None.
     *
     * @param array<string, mixed> $caseRow
     * @return array<string, mixed>
     */
    private function finalizeCaseRow(array $caseRow): array
    {
        $caseRow['internal_cost_nok'] = round((float) $caseRow['internal_cost_nok'], 4);
        $caseRow['average_internal_cost_per_case_nok'] = $caseRow['internal_cost_nok'];

        if ((int) $caseRow['token_event_count'] === 0) {
            $caseRow['cost_status'] = 'missing';
        } elseif ((int) $caseRow['unpriceable_token_event_count'] > 0) {
            $caseRow['cost_status'] = 'partial';
        } else {
            $caseRow['cost_status'] = 'ok';
        }

        if ($caseRow['revenue_status'] === 'ok' && is_numeric($caseRow['allocated_revenue_nok'])) {
            $caseRow['allocated_revenue_nok'] = round((float) $caseRow['allocated_revenue_nok'], 4);
            $caseRow['average_revenue_per_case_nok'] = $caseRow['allocated_revenue_nok'];
            $caseRow['contribution_margin_nok'] = round($caseRow['allocated_revenue_nok'] - $caseRow['internal_cost_nok'], 4);

            if ((float) $caseRow['allocated_revenue_nok'] > 0) {
                $caseRow['margin_percent'] = round(
                    ($caseRow['contribution_margin_nok'] / (float) $caseRow['allocated_revenue_nok']) * 100,
                    2,
                );
            }

            return $caseRow;
        }

        $caseRow['allocated_revenue_nok'] = null;
        $caseRow['average_revenue_per_case_nok'] = null;
        $caseRow['contribution_margin_nok'] = null;
        $caseRow['margin_percent'] = null;

        return $caseRow;
    }

    /**
     * Purpose: Build the per-customer profitability rows from the finalized case rows.
     * Inputs: The finalized case rows.
     * Returns: An ordered list of customer analysis rows.
     * Side effects: None.
     *
     * @param array<int, array<string, mixed>> $caseRows
     * @return array<int, array<string, mixed>>
     */
    private function buildCustomerRows(array $caseRows): array
    {
        $grouped = collect($caseRows)->groupBy(fn (array $row): string => (string) $row['customer_id']);

        return $grouped->map(function (Collection $rows): array {
            $first = $rows->first();
            $customerId = (int) $first['customer_id'];
            $caseCount = $rows->count();
            $revenueKnownRows = $rows->where('revenue_status', 'ok');
            $revenueMissingRows = $rows->where('revenue_status', 'missing');
            $costStatuses = $rows->pluck('cost_status')->all();
            $revenueTotal = $revenueKnownRows->sum(fn (array $row): float => (float) $row['allocated_revenue_nok']);
            $costTotal = $rows->sum(fn (array $row): float => (float) $row['internal_cost_nok']);

            $revenueStatus = $revenueMissingRows->isNotEmpty() ? 'missing' : 'ok';
            $costStatus = $this->aggregateCostStatus($costStatuses);

            $allocatedRevenue = $revenueStatus === 'ok' && $revenueKnownRows->isNotEmpty()
                ? round((float) $revenueTotal, 4)
                : null;

            $averageRevenue = $allocatedRevenue !== null && $caseCount > 0
                ? round($allocatedRevenue / $caseCount, 4)
                : null;

            $averageCost = $caseCount > 0
                ? round($costTotal / $caseCount, 4)
                : null;

            $contributionMargin = $allocatedRevenue !== null
                ? round($allocatedRevenue - (float) $costTotal, 4)
                : null;

            $marginPercent = $allocatedRevenue !== null && (float) $allocatedRevenue > 0
                ? round(($contributionMargin / (float) $allocatedRevenue) * 100, 2)
                : null;

            return [
                'customer_id' => $customerId,
                'customer_name' => $first['customer_name'],
                'subscription_plan' => $first['subscription_plan'],
                'plan_name' => $first['plan_name'],
                'billing_interval' => $first['billing_interval'],
                'billing_discount_percent' => $first['billing_discount_percent'],
                'included_ai_credits' => $first['included_ai_credits'],
                'case_count' => $caseCount,
                'revenue_case_count' => $revenueKnownRows->count(),
                'allocated_revenue_nok' => $allocatedRevenue,
                'average_revenue_per_case_nok' => $averageRevenue,
                'internal_cost_nok' => round((float) $costTotal, 4),
                'average_internal_cost_per_case_nok' => $averageCost,
                'contribution_margin_nok' => $contributionMargin,
                'margin_percent' => $marginPercent,
                'revenue_status' => $revenueStatus,
                'cost_status' => $costStatus,
                'token_event_count' => $rows->sum(fn (array $row): int => (int) $row['token_event_count']),
                'priceable_token_event_count' => $rows->sum(fn (array $row): int => (int) $row['priceable_token_event_count']),
                'zero_token_event_count' => $rows->sum(fn (array $row): int => (int) $row['zero_token_event_count']),
                'unpriceable_token_event_count' => $rows->sum(fn (array $row): int => (int) $row['unpriceable_token_event_count']),
            ];
        })->values()->all();
    }

    /**
     * Purpose: Build the period summary from the finalized case rows.
     * Inputs: The case rows and the requested period metadata.
     * Returns: A summary array with period totals and coverage statuses.
     * Side effects: None.
     *
     * @param array<int, array<string, mixed>> $caseRows
     */
    private function buildSummary(array $caseRows, CarbonImmutable $rangeStart, CarbonImmutable $rangeEnd, ?int $customerId): array
    {
        $caseCount = count($caseRows);
        $revenueCaseCount = count(array_filter($caseRows, fn (array $row): bool => $row['revenue_status'] === 'ok'));
        $revenueStatus = $revenueCaseCount === $caseCount && $caseCount > 0 ? 'ok' : 'missing';
        $costStatus = $this->aggregateCostStatus(array_column($caseRows, 'cost_status'));
        $allocatedRevenue = array_sum(array_map(
            fn (array $row): float => $row['revenue_status'] === 'ok' ? (float) $row['allocated_revenue_nok'] : 0.0,
            $caseRows,
        ));
        $internalCost = array_sum(array_map(
            fn (array $row): float => (float) $row['internal_cost_nok'],
            $caseRows,
        ));

        $allocatedRevenue = $revenueCaseCount > 0 ? round($allocatedRevenue, 4) : null;
        $averageRevenue = $allocatedRevenue !== null && $revenueCaseCount > 0
            ? round($allocatedRevenue / $revenueCaseCount, 4)
            : null;
        $averageInternalCost = $caseCount > 0
            ? round($internalCost / $caseCount, 4)
            : null;
        $contributionMargin = $allocatedRevenue !== null
            ? round($allocatedRevenue - $internalCost, 4)
            : null;
        $marginPercent = $allocatedRevenue !== null && (float) $allocatedRevenue > 0
            ? round(($contributionMargin / (float) $allocatedRevenue) * 100, 2)
            : null;

        return [
            'date_from' => $rangeStart->toDateString(),
            'date_to' => $rangeEnd->toDateString(),
            'customer_id' => $customerId,
            'case_count' => $caseCount,
            'revenue_case_count' => $revenueCaseCount,
            'allocated_revenue_nok' => $allocatedRevenue,
            'average_revenue_per_case_nok' => $averageRevenue,
            'internal_cost_nok' => round($internalCost, 4),
            'average_internal_cost_per_case_nok' => $averageInternalCost,
            'contribution_margin_nok' => $contributionMargin,
            'margin_percent' => $marginPercent,
            'revenue_status' => $revenueStatus,
            'cost_status' => $costStatus,
        ];
    }

    /**
     * Purpose: Return an empty summary payload for a period with no AI case usage.
     * Inputs: The normalized range and an optional customer filter.
     * Returns: A summary with zero totals and missing coverage.
     * Side effects: None.
     */
    private function emptySummary(CarbonImmutable $rangeStart, CarbonImmutable $rangeEnd, ?int $customerId): array
    {
        return [
            'date_from' => $rangeStart->toDateString(),
            'date_to' => $rangeEnd->toDateString(),
            'customer_id' => $customerId,
            'case_count' => 0,
            'revenue_case_count' => 0,
            'allocated_revenue_nok' => null,
            'average_revenue_per_case_nok' => null,
            'internal_cost_nok' => 0.0,
            'average_internal_cost_per_case_nok' => null,
            'contribution_margin_nok' => null,
            'margin_percent' => null,
            'revenue_status' => 'missing',
            'cost_status' => 'missing',
        ];
    }

    /**
     * Purpose: Resolve the monthly plan value and per-case revenue proxy for a customer.
     * Inputs: The customer and its current plan settings.
     * Returns: The revenue proxy payload for the customer.
     * Side effects: None.
     *
     * @return array{
     *     status: string,
     *     monthly_plan_value_nok: float|null,
     *     allocated_revenue_per_case_nok: float|null,
     *     included_ai_credits: int|null
     * }
     */
    private function resolveRevenueProxy(Customer $customer): array
    {
        $discountPercent = max(0.0, min(100.0, (float) ($customer->billing_discount_percent ?? 0)));

        $includedAiCredits = $this->billingEntitlementService->includedAiCredits($customer);

        if ($includedAiCredits <= 0) {
            return $this->missingRevenueProxy($includedAiCredits);
        }

        // Priority 1: monthly value from the active Tjenestekatalog base-plan line.
        $monthlyEquivalent = $this->billingEntitlementService->basePlanMonthlyValueNok($customer);

        // Priority 2: config/procynia_plans.php fallback when no Tjenestekatalog price is available.
        if ($monthlyEquivalent === null) {
            $planConfig = $customer->planConfig();
            $billingInterval = $customer->billing_interval ?? Customer::BILLING_MONTHLY;

            $planPriceKey = $billingInterval === Customer::BILLING_YEARLY
                ? 'yearly_price_nok'
                : 'monthly_price_nok';

            $planPrice = $planConfig[$planPriceKey] ?? null;

            if ($planPrice === null) {
                return $this->missingRevenueProxy($includedAiCredits);
            }

            $monthlyEquivalent = $billingInterval === Customer::BILLING_YEARLY
                ? ((float) $planPrice / 12)
                : (float) $planPrice;
        }

        $monthlyEquivalentAfterDiscount = round($monthlyEquivalent * (1 - ($discountPercent / 100)), 4);
        $allocatedRevenuePerCase = round($monthlyEquivalentAfterDiscount / $includedAiCredits, 4);

        return [
            'status' => 'ok',
            'monthly_plan_value_nok' => $monthlyEquivalentAfterDiscount,
            'allocated_revenue_per_case_nok' => $allocatedRevenuePerCase,
            'included_ai_credits' => $includedAiCredits,
        ];
    }

    /**
     * Purpose: Return a missing-revenue payload while preserving the detected credit count.
     * Inputs: The discovered AI credit count, if any.
     * Returns: A normalized missing-revenue payload.
     * Side effects: None.
     *
     * @return array{
     *     status: string,
     *     monthly_plan_value_nok: null,
     *     allocated_revenue_per_case_nok: null,
     *     included_ai_credits: int|null
     * }
     */
    private function missingRevenueProxy(?int $includedAiCredits = null): array
    {
        return [
            'status' => 'missing',
            'monthly_plan_value_nok' => null,
            'allocated_revenue_per_case_nok' => null,
            'included_ai_credits' => $includedAiCredits,
        ];
    }

    /**
     * Purpose: Convert a timestamp to the month key used by the AI case ledger.
     * Inputs: A timestamp-like value.
     * Returns: The first day of the month in YYYY-MM-DD format.
     * Side effects: None.
     */
    private function monthKey(CarbonInterface $moment): string
    {
        return CarbonImmutable::instance($moment)
            ->setTimezone(config('app.timezone') ?: 'UTC')
            ->startOfMonth()
            ->toDateString();
    }

    /**
     * Purpose: Generate the internal lookup key for a case row or token event.
     * Inputs: The customer id, SavedNotice id and month key.
     * Returns: A stable hashable lookup string.
     * Side effects: None.
     */
    private function caseKey(int $customerId, int $savedNoticeId, string $monthKey): string
    {
        return $customerId.'|'.$savedNoticeId.'|'.$monthKey;
    }

    /**
     * Purpose: Compare profitability rows so that risky rows appear first.
     * Inputs: Two normalized report rows.
     * Returns: A standard usort comparison result.
     * Side effects: None.
     *
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    private function compareRiskRows(array $left, array $right): int
    {
        $leftMargin = $left['margin_percent'] ?? null;
        $rightMargin = $right['margin_percent'] ?? null;
        $leftMarginSort = $leftMargin === null ? -1_000_000 : (float) $leftMargin;
        $rightMarginSort = $rightMargin === null ? -1_000_000 : (float) $rightMargin;

        if ($leftMarginSort !== $rightMarginSort) {
            return $leftMarginSort <=> $rightMarginSort;
        }

        $leftCost = (float) ($left['internal_cost_nok'] ?? 0.0);
        $rightCost = (float) ($right['internal_cost_nok'] ?? 0.0);

        if ($leftCost !== $rightCost) {
            return $rightCost <=> $leftCost;
        }

        return strcmp((string) ($left['customer_name'] ?? ''), (string) ($right['customer_name'] ?? ''));
    }

    /**
     * Purpose: Reduce a list of per-row cost states into a single customer or summary cost state.
     * Inputs: The row statuses.
     * Returns: The combined cost status.
     * Side effects: None.
     *
     * @param array<int, string> $statuses
     */
    private function aggregateCostStatus(array $statuses): string
    {
        if ($statuses === []) {
            return 'missing';
        }

        if (in_array('partial', $statuses, true)) {
            return 'partial';
        }

        if (in_array('missing', $statuses, true)) {
            return 'missing';
        }

        return 'ok';
    }
}
