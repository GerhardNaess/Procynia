<?php

namespace Tests\Unit\Services\Ai\Commercial;

use App\Models\AiModelPrice;
use App\Models\AiTokenEvent;
use App\Models\Customer;
use App\Models\CustomerAiCaseUsage;
use App\Models\ExchangeRate;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\SavedNotice;
use App\Models\User;
use App\Services\Ai\AiUsageGuard;
use App\Services\Ai\Commercial\AiCaseProfitabilityService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiCaseProfitabilityServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Purpose: Verify that monthly plan revenue is allocated per AI-active case.
     * Inputs: None.
     * Returns: None.
     * Side effects: Writes fixture rows to the test database.
     */
    public function test_it_calculates_allocated_revenue_per_case_from_monthly_plan_and_included_ai_credits(): void
    {
        Carbon::setTestNow('2026-06-15 12:00:00');

        $customer = $this->createCustomer('Revenue Monthly AS', Customer::PLAN_PRO, Customer::BILLING_MONTHLY, 3, 0);
        $noticeA = $this->createSavedNotice($customer->id, 'REVENUE-MONTHLY-001', 'Revenue Monthly A');
        $noticeB = $this->createSavedNotice($customer->id, 'REVENUE-MONTHLY-002', 'Revenue Monthly B');

        $this->createCaseUsage($customer, $noticeA, '2026-06-05 10:00:00', AiUsageGuard::OPERATION_SAVED_NOTICE_REQUIREMENT_ANSWER_DRAFT);
        $this->createCaseUsage($customer, $noticeB, '2026-06-10 11:00:00', AiUsageGuard::OPERATION_SAVED_NOTICE_DOCUMENTS_UPLOAD);

        $report = $this->analyze('2026-06-01', '2026-06-30');

        $this->assertSame(2, $report['summary']['case_count']);
        $this->assertSame('ok', $report['summary']['revenue_status']);
        $this->assertEqualsWithDelta(660.0, $report['summary']['allocated_revenue_nok'], 0.0001);
        $this->assertEqualsWithDelta(330.0, $report['summary']['average_revenue_per_case_nok'], 0.0001);

        $customerRow = $this->customerRow($report, $customer->id);
        $this->assertSame(2, $customerRow['case_count']);
        $this->assertSame('ok', $customerRow['revenue_status']);
        $this->assertEqualsWithDelta(660.0, $customerRow['allocated_revenue_nok'], 0.0001);
        $this->assertEqualsWithDelta(330.0, $customerRow['average_revenue_per_case_nok'], 0.0001);

        $caseRow = $this->caseRow($report, $noticeA->id);
        $this->assertSame($customer->id, $caseRow['customer_id']);
        $this->assertSame('ok', $caseRow['revenue_status']);
        $this->assertEqualsWithDelta(330.0, $caseRow['allocated_revenue_nok'], 0.0001);
    }

    /**
     * Purpose: Verify that yearly plan pricing is normalized to a monthly equivalent before case allocation.
     * Inputs: None.
     * Returns: None.
     * Side effects: Writes fixture rows to the test database.
     */
    public function test_it_uses_monthly_equivalent_for_yearly_plan(): void
    {
        Carbon::setTestNow('2026-06-15 12:00:00');

        $customer = $this->createCustomer('Revenue Yearly AS', Customer::PLAN_PRO, Customer::BILLING_YEARLY, 1, 0);
        $notice = $this->createSavedNotice($customer->id, 'REVENUE-YEARLY-001', 'Revenue Yearly Case');
        $this->createCaseUsage($customer, $notice, '2026-06-15 10:00:00', AiUsageGuard::OPERATION_SAVED_NOTICE_DOCUMENTS_UPLOAD);

        $report = $this->analyze('2026-06-01', '2026-06-30');
        $expectedCaseRevenue = round(7921 / 12, 4);

        $this->assertSame('ok', $report['summary']['revenue_status']);
        $this->assertEqualsWithDelta($expectedCaseRevenue, $report['summary']['allocated_revenue_nok'], 0.0001);
        $this->assertEqualsWithDelta($expectedCaseRevenue, $report['summary']['average_revenue_per_case_nok'], 0.0001);

        $caseRow = $this->caseRow($report, $notice->id);
        $this->assertEqualsWithDelta($expectedCaseRevenue, $caseRow['allocated_revenue_nok'], 0.0001);
        $this->assertEqualsWithDelta($expectedCaseRevenue, $caseRow['average_revenue_per_case_nok'], 0.0001);
    }

    /**
     * Purpose: Verify that missing included AI credits prevent revenue allocation.
     * Inputs: None.
     * Returns: None.
     * Side effects: Writes fixture rows to the test database.
     */
    public function test_it_marks_revenue_missing_when_included_ai_credits_are_missing(): void
    {
        Carbon::setTestNow('2026-06-15 12:00:00');

        $customer = $this->createCustomer('Revenue Missing AS', Customer::PLAN_PRO, Customer::BILLING_MONTHLY, 0, 0);
        $notice = $this->createSavedNotice($customer->id, 'REVENUE-MISSING-001', 'Revenue Missing Case');
        $this->createCaseUsage($customer, $notice, '2026-06-15 10:00:00', AiUsageGuard::OPERATION_SAVED_NOTICE_REQUIREMENT_ANSWER_DRAFT);

        $report = $this->analyze('2026-06-01', '2026-06-30');

        $this->assertSame('missing', $report['summary']['revenue_status']);
        $this->assertNull($report['summary']['allocated_revenue_nok']);
        $this->assertNull($report['summary']['average_revenue_per_case_nok']);

        $customerRow = $this->customerRow($report, $customer->id);
        $this->assertSame('missing', $customerRow['revenue_status']);
        $this->assertNull($customerRow['allocated_revenue_nok']);
        $this->assertNull($customerRow['average_revenue_per_case_nok']);

        $caseRow = $this->caseRow($report, $notice->id);
        $this->assertSame('missing', $caseRow['revenue_status']);
        $this->assertNull($caseRow['allocated_revenue_nok']);
        $this->assertNull($caseRow['average_revenue_per_case_nok']);
    }

    /**
     * Purpose: Verify that internal cost is summed from token events tied to a SavedNotice.
     * Inputs: None.
     * Returns: None.
     * Side effects: Writes fixture rows to the test database.
     */
    public function test_it_sums_internal_cost_for_case_token_events(): void
    {
        Carbon::setTestNow('2026-06-15 12:00:00');

        $customer = $this->createCustomer('Cost Sum AS', Customer::PLAN_PRO, Customer::BILLING_MONTHLY, 3, 0);
        $user = $this->createUser($customer, 'Cost Sum User');
        $notice = $this->createSavedNotice($customer->id, 'COST-SUM-001', 'Cost Sum Case');
        $this->createCaseUsage($customer, $notice, '2026-06-15 10:00:00', AiUsageGuard::OPERATION_SAVED_NOTICE_DOCUMENTS_UPLOAD);

        $this->createModelPrice('openai', 'gpt-4.1-mini', 'usd', 0.40, 1.60, '2026-06-01');
        $this->createExchangeRate('USD', 'NOK', 10.00, '2026-06-15');

        $this->createTokenEvent(
            $customer,
            $user,
            AiUsageGuard::OPERATION_SAVED_NOTICE_DOCUMENTS_UPLOAD,
            'gpt-4.1-mini',
            100_000,
            50_000,
            150_000,
            [
                'provider' => 'openai',
                'saved_notice_id' => $notice->id,
                'created_at' => '2026-06-15 12:00:00',
            ],
        );

        $this->createTokenEvent(
            $customer,
            $user,
            AiUsageGuard::OPERATION_SAVED_NOTICE_DOCUMENTS_UPLOAD,
            'gpt-4.1-mini',
            50_000,
            25_000,
            75_000,
            [
                'provider' => 'openai',
                'saved_notice_id' => $notice->id,
                'created_at' => '2026-06-15 13:00:00',
            ],
        );

        $report = $this->analyze('2026-06-01', '2026-06-30');

        $this->assertSame('ok', $report['summary']['cost_status']);
        $this->assertEqualsWithDelta(1.8, $report['summary']['internal_cost_nok'], 0.0001);
        $this->assertEqualsWithDelta(1.8, $report['summary']['average_internal_cost_per_case_nok'], 0.0001);

        $caseRow = $this->caseRow($report, $notice->id);
        $this->assertSame('ok', $caseRow['cost_status']);
        $this->assertEqualsWithDelta(1.8, $caseRow['internal_cost_nok'], 0.0001);
        $this->assertEqualsWithDelta(1.8, $caseRow['average_internal_cost_per_case_nok'], 0.0001);
    }

    /**
     * Purpose: Verify that a token event without a matching price still marks the case as partial.
     * Inputs: None.
     * Returns: None.
     * Side effects: Writes fixture rows to the test database.
     */
    public function test_it_marks_cost_partial_when_token_event_price_is_missing(): void
    {
        Carbon::setTestNow('2026-06-15 12:00:00');

        $customer = $this->createCustomer('Cost Partial AS', Customer::PLAN_PRO, Customer::BILLING_MONTHLY, 3, 0);
        $user = $this->createUser($customer, 'Cost Partial User');
        $notice = $this->createSavedNotice($customer->id, 'COST-PARTIAL-001', 'Cost Partial Case');
        $this->createCaseUsage($customer, $notice, '2026-06-15 10:00:00', AiUsageGuard::OPERATION_SAVED_NOTICE_REQUIREMENT_ANSWER_DRAFT);

        $this->createTokenEvent(
            $customer,
            $user,
            AiUsageGuard::OPERATION_SAVED_NOTICE_REQUIREMENT_ANSWER_DRAFT,
            'gpt-unknown',
            100_000,
            50_000,
            150_000,
            [
                'provider' => 'openai',
                'saved_notice_id' => $notice->id,
                'created_at' => '2026-06-15 12:00:00',
            ],
        );

        $report = $this->analyze('2026-06-01', '2026-06-30');

        $this->assertSame('partial', $report['summary']['cost_status']);
        $this->assertEqualsWithDelta(0.0, $report['summary']['internal_cost_nok'], 0.0001);

        $caseRow = $this->caseRow($report, $notice->id);
        $this->assertSame('partial', $caseRow['cost_status']);
        $this->assertEqualsWithDelta(0.0, $caseRow['internal_cost_nok'], 0.0001);
    }

    /**
     * Purpose: Verify that token events without saved_notice_id are ignored for case cost.
     * Inputs: None.
     * Returns: None.
     * Side effects: Writes fixture rows to the test database.
     */
    public function test_it_ignores_token_events_without_saved_notice_id_for_case_cost(): void
    {
        Carbon::setTestNow('2026-06-15 12:00:00');

        $customer = $this->createCustomer('Ignored Token AS', Customer::PLAN_PRO, Customer::BILLING_MONTHLY, 3, 0);
        $user = $this->createUser($customer, 'Ignored Token User');
        $notice = $this->createSavedNotice($customer->id, 'IGNORED-TOKEN-001', 'Ignored Token Case');
        $this->createCaseUsage($customer, $notice, '2026-06-15 10:00:00', AiUsageGuard::OPERATION_SAVED_NOTICE_DOCUMENTS_UPLOAD);

        $this->createModelPrice('openai', 'gpt-4.1-mini', 'usd', 0.40, 1.60, '2026-06-01');
        $this->createExchangeRate('USD', 'NOK', 10.00, '2026-06-15');

        $this->createTokenEvent(
            $customer,
            $user,
            AiUsageGuard::OPERATION_SAVED_NOTICE_DOCUMENTS_UPLOAD,
            'gpt-4.1-mini',
            100_000,
            50_000,
            150_000,
            [
                'provider' => 'openai',
                'created_at' => '2026-06-15 12:00:00',
            ],
        );

        $report = $this->analyze('2026-06-01', '2026-06-30');

        $this->assertSame('missing', $report['summary']['cost_status']);
        $this->assertEqualsWithDelta(0.0, $report['summary']['internal_cost_nok'], 0.0001);

        $caseRow = $this->caseRow($report, $notice->id);
        $this->assertSame('missing', $caseRow['cost_status']);
        $this->assertEqualsWithDelta(0.0, $caseRow['internal_cost_nok'], 0.0001);
    }

    /**
     * Purpose: Verify that the service can narrow the analysis to one customer.
     * Inputs: None.
     * Returns: None.
     * Side effects: Writes fixture rows to the test database.
     */
    public function test_it_filters_by_customer_when_customer_id_is_provided(): void
    {
        Carbon::setTestNow('2026-06-15 12:00:00');

        $customerA = $this->createCustomer('Filter Customer A', Customer::PLAN_PRO, Customer::BILLING_MONTHLY, 3, 0);
        $customerB = $this->createCustomer('Filter Customer B', Customer::PLAN_MAX, Customer::BILLING_MONTHLY, 20, 0);

        $noticeA = $this->createSavedNotice($customerA->id, 'FILTER-001', 'Filter A Case');
        $noticeB = $this->createSavedNotice($customerB->id, 'FILTER-002', 'Filter B Case');

        $this->createCaseUsage($customerA, $noticeA, '2026-06-15 10:00:00', AiUsageGuard::OPERATION_SAVED_NOTICE_DOCUMENTS_UPLOAD);
        $this->createCaseUsage($customerB, $noticeB, '2026-06-15 11:00:00', AiUsageGuard::OPERATION_SAVED_NOTICE_DOCUMENTS_UPLOAD);

        $report = $this->analyze('2026-06-01', '2026-06-30', $customerA->id);

        $this->assertSame($customerA->id, $report['summary']['customer_id']);
        $this->assertSame(1, count($report['customers']));
        $this->assertSame(1, count($report['cases']));
        $this->assertSame($customerA->id, $report['customers'][0]['customer_id']);
        $this->assertSame($customerA->id, $report['cases'][0]['customer_id']);
        $this->assertSame($noticeA->id, $report['cases'][0]['saved_notice_id']);
    }

    /**
     * Purpose: Verify that case usage rows outside the selected period are excluded.
     * Inputs: None.
     * Returns: None.
     * Side effects: Writes fixture rows to the test database.
     */
    public function test_it_filters_case_usage_by_activated_at_period(): void
    {
        Carbon::setTestNow('2026-06-15 12:00:00');

        $customer = $this->createCustomer('Period Filter AS', Customer::PLAN_PRO, Customer::BILLING_MONTHLY, 3, 0);
        $noticeIn = $this->createSavedNotice($customer->id, 'PERIOD-IN-001', 'Period In Case');
        $noticeOut = $this->createSavedNotice($customer->id, 'PERIOD-OUT-001', 'Period Out Case');

        $this->createCaseUsage($customer, $noticeIn, '2026-06-15 10:00:00', AiUsageGuard::OPERATION_SAVED_NOTICE_DOCUMENTS_UPLOAD);
        $this->createCaseUsage($customer, $noticeOut, '2026-05-15 10:00:00', AiUsageGuard::OPERATION_SAVED_NOTICE_REQUIREMENT_ANSWER_DRAFT);

        $report = $this->analyze('2026-06-01', '2026-06-30');

        $this->assertSame(1, $report['summary']['case_count']);
        $this->assertSame(1, count($report['customers']));
        $this->assertSame(1, count($report['cases']));
        $this->assertSame($noticeIn->id, $report['cases'][0]['saved_notice_id']);
    }

    /**
     * Purpose: Create a profitability report instance for the supplied period.
     * Inputs: Period boundaries and an optional customer filter.
     * Returns: The analyzed report payload.
     * Side effects: Reads the application services and the test database only.
     */
    private function analyze(string $dateFrom, string $dateTo, ?int $customerId = null): array
    {
        return app(AiCaseProfitabilityService::class)->analyze(
            Carbon::parse($dateFrom),
            Carbon::parse($dateTo),
            $customerId,
        );
    }

    /**
     * Purpose: Create a deterministic customer fixture for profitability analysis tests.
     * Inputs: Name, plan, interval, optional AI credits override, and optional discount.
     * Returns: The persisted customer model.
     * Side effects: Writes customer, language, and nationality rows.
     */
    private function createCustomer(
        string $name,
        string $plan,
        string $billingInterval,
        ?int $includedAiCredits,
        float $billingDiscountPercent,
    ): Customer {
        $language = Language::query()->firstOrCreate(
            ['code' => 'no'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk'],
        );

        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO'],
        );

        $attributes = [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
            'subscription_plan' => $plan,
            'billing_interval' => $billingInterval,
            'billing_discount_percent' => $billingDiscountPercent,
        ];

        if ($includedAiCredits !== null) {
            $attributes['included_ai_credits'] = $includedAiCredits;
        }

        return Customer::query()->create($attributes);
    }

    /**
     * Purpose: Create a deterministic SavedNotice fixture for profitability analysis tests.
     * Inputs: The owning customer id, external id, and title.
     * Returns: The persisted SavedNotice row.
     * Side effects: Writes one saved_notices row to the test database.
     */
    private function createSavedNotice(int $customerId, string $externalId, string $title): SavedNotice
    {
        $attributes = [
            'customer_id' => $customerId,
            'saved_by_user_id' => null,
            'bid_status' => SavedNotice::BID_STATUS_DISCOVERED,
            'source_type' => SavedNotice::SOURCE_TYPE_PUBLIC_NOTICE,
            'opportunity_owner_user_id' => null,
            'bid_manager_user_id' => null,
            'external_id' => $externalId,
            'title' => $title,
            'buyer_name' => 'Procynia',
            'external_url' => "https://doffin.no/notices/{$externalId}",
            'summary' => 'Kort oppsummering',
            'publication_date' => '2026-06-01 00:00:00',
            'deadline' => '2026-06-30 00:00:00',
            'status' => 'ACTIVE',
            'cpv_code' => '72000000',
            'archived_at' => null,
            'reference_number' => null,
            'contact_person_name' => null,
            'contact_person_email' => null,
            'notes' => null,
        ];

        if (Schema::hasColumn('saved_notices', 'history_type')) {
            $attributes['history_type'] = null;
        }

        return SavedNotice::query()->create($attributes);
    }

    /**
     * Purpose: Create one AI case usage ledger row for a SavedNotice in a specific month.
     * Inputs: The customer, SavedNotice, activation timestamp, and source operation key.
     * Returns: The persisted CustomerAiCaseUsage model.
     * Side effects: Writes one customer_ai_case_usages row to the test database.
     */
    private function createCaseUsage(
        Customer $customer,
        SavedNotice $savedNotice,
        string $activatedAt,
        string $sourceOperationKey,
    ): CustomerAiCaseUsage {
        $moment = Carbon::parse($activatedAt);

        return CustomerAiCaseUsage::query()->create([
            'customer_id' => $customer->id,
            'saved_notice_id' => $savedNotice->id,
            'activated_at' => $moment,
            'activated_by_user_id' => null,
            'period_start' => $moment->copy()->startOfMonth()->toDateString(),
            'period_end' => $moment->copy()->endOfMonth()->toDateString(),
            'source_operation_key' => $sourceOperationKey,
            'source_ai_usage_event_id' => null,
            'source_ai_token_event_id' => null,
        ]);
    }

    /**
     * Purpose: Create a deterministic user fixture for token-based profitability tests.
     * Inputs: The owning customer and the display name.
     * Returns: The persisted user row.
     * Side effects: Writes one user row to the test database.
     */
    private function createUser(Customer $customer, string $name): User
    {
        return User::factory()->create([
            'name' => $name,
            'email' => Str::slug($name).'.'.Str::lower(Str::random(6)).'@example.test',
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    /**
     * Purpose: Create one token event for profitability analysis tests.
     * Inputs: Customer, user, operation key, model, token counts, and optional overrides.
     * Returns: The persisted AiTokenEvent row.
     * Side effects: Writes one ai_token_events row to the test database.
     */
    private function createTokenEvent(
        Customer $customer,
        User $user,
        string $operationKey,
        string $model,
        int $input,
        int $output,
        int $total,
        array $overrides = [],
    ): AiTokenEvent {
        $event = AiTokenEvent::query()->create(array_merge([
            'customer_id' => $customer->id,
            'user_id' => $user->id,
            'operation_key' => $operationKey,
            'model' => $model,
            'input_tokens' => $input,
            'output_tokens' => $output,
            'total_tokens' => $total,
            'provider' => $overrides['provider'] ?? 'openai',
            'saved_notice_id' => $overrides['saved_notice_id'] ?? null,
            'saved_notice_ai_document_id' => $overrides['saved_notice_ai_document_id'] ?? null,
            'requirement_extraction_run_id' => $overrides['requirement_extraction_run_id'] ?? null,
            'knowledge_item_id' => $overrides['knowledge_item_id'] ?? null,
        ], $overrides));

        if (isset($overrides['created_at'])) {
            $event->forceFill([
                'created_at' => $overrides['created_at'],
                'updated_at' => $overrides['created_at'],
            ])->save();
        }

        return $event;
    }

    /**
     * Purpose: Create one model price row for cost-estimation tests.
     * Inputs: Provider, model, currency, input/output price, and validity start date.
     * Returns: The persisted AiModelPrice row.
     * Side effects: Writes one ai_model_prices row to the test database.
     */
    private function createModelPrice(
        string $provider,
        string $model,
        string $currency,
        float $inputPricePer1mTokens,
        float $outputPricePer1mTokens,
        string $validFrom,
    ): AiModelPrice {
        return AiModelPrice::query()->create([
            'provider' => $provider,
            'model' => $model,
            'currency' => $currency,
            'input_price_per_1m_tokens' => $inputPricePer1mTokens,
            'output_price_per_1m_tokens' => $outputPricePer1mTokens,
            'valid_from' => $validFrom,
            'valid_to' => null,
            'is_active' => true,
        ]);
    }

    /**
     * Purpose: Create one exchange rate row for historical cost estimation.
     * Inputs: Base currency, quote currency, rate, and rate date.
     * Returns: The persisted ExchangeRate row.
     * Side effects: Writes one exchange_rates row to the test database.
     */
    private function createExchangeRate(
        string $baseCurrency,
        string $quoteCurrency,
        float $rate,
        string $rateDate,
    ): ExchangeRate {
        return ExchangeRate::query()->create([
            'base_currency' => $baseCurrency,
            'quote_currency' => $quoteCurrency,
            'rate' => $rate,
            'rate_date' => $rateDate,
            'source' => ExchangeRate::SOURCE_NORGES_BANK,
            'fetched_at' => now(),
        ]);
    }

    /**
     * Purpose: Fetch a customer row from the report by customer id.
     * Inputs: The report payload and the customer id.
     * Returns: The matching customer row.
     * Side effects: None.
     */
    private function customerRow(array $report, int $customerId): array
    {
        foreach ($report['customers'] as $row) {
            if ((int) $row['customer_id'] === $customerId) {
                return $row;
            }
        }

        $this->fail(sprintf('Customer row [%d] was not found.', $customerId));
    }

    /**
     * Purpose: Fetch a case row from the report by SavedNotice id.
     * Inputs: The report payload and the SavedNotice id.
     * Returns: The matching case row.
     * Side effects: None.
     */
    private function caseRow(array $report, int $savedNoticeId): array
    {
        foreach ($report['cases'] as $row) {
            if ((int) $row['saved_notice_id'] === $savedNoticeId) {
                return $row;
            }
        }

        $this->fail(sprintf('Case row [%d] was not found.', $savedNoticeId));
    }
}
