<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\AiProfitability;
use App\Models\AiModelPrice;
use App\Models\AiTokenEvent;
use App\Models\Customer;
use App\Models\CustomerAiCaseUsage;
use App\Models\ExchangeRate;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\SavedNotice;
use App\Models\User;
use App\Services\Ai\Commercial\AiCaseProfitabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiProfitabilityPageTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Purpose: Verify that an internal super admin can open the profitability page and see the service-driven data.
     * Inputs: None.
     * Returns: None.
     * Side effects: Writes deterministic AI profitability fixtures to the test database.
     */
    public function test_internal_super_admin_can_open_ai_profitability_page_and_see_service_data(): void
    {
        Carbon::setTestNow('2026-06-15 12:00:00');

        $admin = $this->internalAdmin();

        $customerA = $this->createCustomer('Lønnsom Kunde A', Customer::PLAN_PRO, Customer::BILLING_MONTHLY, 3);
        $customerB = $this->createCustomer('Mangler Kunde B', Customer::PLAN_ENTERPRISE, Customer::BILLING_MONTHLY, 0);

        $userA = $this->createUser($customerA, 'Kunde A Bruker', 'kunde-a@example.test');
        $userB = $this->createUser($customerB, 'Kunde B Bruker', 'kunde-b@example.test');

        $noticeA = $this->createSavedNotice($customerA, 'PROFIT-A-001', 'Lønnsom Case A');
        $noticeB = $this->createSavedNotice($customerB, 'PROFIT-B-001', 'Delvis Case B');
        $noticeC = $this->createSavedNotice($customerB, 'PROFIT-B-002', 'Tom Case C');

        $this->createCaseUsage($customerA, $noticeA, '2026-06-05 10:00:00', 'saved_notice_requirement_answer_draft');
        $this->createCaseUsage($customerB, $noticeB, '2026-06-06 10:00:00', 'saved_notice_documents_upload');
        $this->createCaseUsage($customerB, $noticeC, '2026-06-07 10:00:00', 'saved_notice_assessment_refresh');

        $this->createModelPrice('openai', 'gpt-4.1-mini', 'usd', 0.40, 1.60, '2026-06-01');
        $this->createExchangeRate('USD', 'NOK', 9.2935, '2026-06-01');

        $this->createTokenEvent($customerA, $userA, $noticeA, 'saved_notice_documents_upload', 'gpt-4.1-mini', 100_000, 50_000, 150_000, [
            'provider' => 'openai',
        ]);
        $this->createTokenEvent($customerB, $userB, $noticeB, 'saved_notice_documents_upload', 'gpt-5', 80_000, 20_000, 100_000, [
            'provider' => 'openai',
        ]);

        $report = app(AiCaseProfitabilityService::class)->analyze(
            Carbon::parse('2026-06-01 00:00:00'),
            Carbon::parse('2026-06-15 23:59:59'),
        );

        $this->assertSame(2, count($report['customers']));
        $this->assertSame(3, count($report['cases']));
        $this->assertSame('missing', $report['summary']['revenue_status']);
        $this->assertSame('partial', $report['summary']['cost_status']);

        $response = $this->actingAs($admin)->get(AiProfitability::getUrl());

        $response->assertOk();
        $response->assertSeeText('AI-lønnsomhet');
        $response->assertSeeText('Intern estimert analyse av AI-økonomi');
        $response->assertSeeText('ikke fakturagrunnlag');
        $response->assertSeeText('Tallene er ikke fakturagrunnlag eller regnskap');
        $response->assertSeeText('estimert inntekt, estimert intern AI-kost og estimert dekningsbidrag');
        $response->assertSeeText('Alle kunder samlet');
        $response->assertSeeText('Estimert inntekt');
        $response->assertSeeText('Estimert intern AI-kost');
        $response->assertSeeText('Estimert dekningsbidrag');
        $response->assertSeeText('Margin %');
        $response->assertSeeText('Kunder');
        $response->assertSeeText('AI-caser');
        $response->assertSeeText('Manglende inntekt');
        $response->assertSeeText('Ufullstendig kost');
        $response->assertSeeText('Manglende data betyr usikkerhet, ikke null');
        $response->assertSeeText('Lønnsom Kunde A');
        $response->assertSeeText('Mangler Kunde B');
        $response->assertSeeText('Lønnsom Case A');
        $response->assertSeeText('Delvis Case B');
        $response->assertSeeText('Tom Case C');
        $response->assertSeeText('2 kunder');
        $response->assertSeeText('3 AI-case');
        $response->assertSeeText('2 rader');
        $response->assertSeeText('≈ 330 kr');
        $response->assertSeeText('≈ 1 kr');
        $response->assertSeeText('Ikke beregnet');
        $response->assertSeeText('Mangler prisgrunnlag');
        $response->assertSeeText('Mangler kostgrunnlag');
        $response->assertSeeText('Delvis beregnet');
        $response->assertSeeText('Estimert fra avtale og AI-case');
        $response->assertSeeText('Estimert intern AI-kost');
        $response->assertSeeText('Beregnet fra estimert inntekt og estimert intern kost');
    }

    /**
     * Purpose: Verify that a non-internal user cannot access the profitability page.
     * Inputs: None.
     * Returns: None.
     * Side effects: Writes one customer and one user to the test database.
     */
    public function test_customer_admin_cannot_access_ai_profitability_page(): void
    {
        $customer = $this->createCustomer('Tilgangskunde', Customer::PLAN_PRO, Customer::BILLING_MONTHLY, 3);
        $user = $this->createUser($customer, 'Customer Admin', 'customer.admin@example.test');

        $this->actingAs($user);

        $this->assertFalse(AiProfitability::canAccess());
    }

    /**
     * Purpose: Create a deterministic customer fixture for the profitability page tests.
     * Inputs: Customer name, plan, billing interval, and AI credits.
     * Returns: The persisted customer row.
     * Side effects: Writes the prerequisite language and nationality rows when needed.
     */
    private function createCustomer(
        string $name,
        string $plan,
        string $billingInterval,
        ?int $includedAiCredits,
    ): Customer {
        $language = Language::query()->firstOrCreate(
            ['code' => 'no'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk'],
        );

        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO'],
        );

        return Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'nationality_id' => $nationality->id,
            'language_id' => $language->id,
            'is_active' => true,
            'subscription_plan' => $plan,
            'billing_interval' => $billingInterval,
            'included_ai_credits' => $includedAiCredits,
            'billing_discount_percent' => 0,
        ]);
    }

    /**
     * Purpose: Create a deterministic user fixture for the page tests.
     * Inputs: The owning customer, display name and email.
     * Returns: The persisted user row.
     * Side effects: Writes a user row.
     */
    private function createUser(Customer $customer, string $name, string $email): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => bcrypt('SecretPass123!'),
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    /**
     * Purpose: Create a deterministic internal admin fixture for the page test.
     * Inputs: None.
     * Returns: The persisted super admin user row.
     * Side effects: Writes a user row.
     */
    private function internalAdmin(): User
    {
        return User::query()->create([
            'name' => 'Procynia Admin',
            'email' => 'procynia.admin+'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('SecretPass123!'),
            'role' => User::ROLE_SUPER_ADMIN,
            'customer_id' => null,
            'is_active' => true,
        ]);
    }

    /**
     * Purpose: Create a deterministic SavedNotice fixture for profitability analysis tests.
     * Inputs: The owning customer, a unique external id and a title.
     * Returns: The persisted SavedNotice model.
     * Side effects: Writes one saved_notices row to the test database.
     */
    private function createSavedNotice(Customer $customer, string $externalId, string $title): SavedNotice
    {
        return SavedNotice::query()->create([
            'customer_id' => $customer->id,
            'external_id' => $externalId,
            'title' => $title,
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
            'source_type' => SavedNotice::SOURCE_TYPE_PUBLIC_NOTICE,
        ]);
    }

    /**
     * Purpose: Create one AI case usage ledger row for a SavedNotice in a specific month.
     * Inputs: The customer, SavedNotice, activation timestamp and the source operation key.
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
     * Purpose: Create a deterministic token event fixture for the profitability tests.
     * Inputs: The customer, user, SavedNotice, operation key, model, token counts, and optional overrides.
     * Returns: The persisted AiTokenEvent model.
     * Side effects: Writes one ai_token_events row to the test database.
     */
    private function createTokenEvent(
        Customer $customer,
        User $user,
        SavedNotice $savedNotice,
        string $operationKey,
        string $model,
        int $input,
        int $output,
        int $total,
        array $overrides = [],
    ): AiTokenEvent {
        return AiTokenEvent::query()->create(array_merge([
            'customer_id' => $customer->id,
            'user_id' => $user->id,
            'saved_notice_id' => $savedNotice->id,
            'operation_key' => $operationKey,
            'model' => $model,
            'input_tokens' => $input,
            'output_tokens' => $output,
            'total_tokens' => $total,
        ], $overrides));
    }

    /**
     * Purpose: Create a deterministic model price fixture for internal cost estimation tests.
     * Inputs: Provider, model, currency, token prices and validity start date.
     * Returns: The persisted AiModelPrice model.
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
     * Purpose: Create a deterministic exchange rate fixture for internal cost estimation tests.
     * Inputs: Base currency, quote currency, rate and rate date.
     * Returns: The persisted ExchangeRate model.
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
}
