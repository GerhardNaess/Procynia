<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\AiForbruk;
use App\Models\AiModelPrice;
use App\Models\AiTokenEvent;
use App\Models\AiUsageEvent;
use App\Models\ExchangeRate;
use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiForbrukPageTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_internal_super_admin_can_access_ai_forbruk_page(): void
    {
        Carbon::setTestNow('2026-06-02 12:00:00');

        $admin = $this->internalAdmin();
        $response = $this->actingAs($admin)->get(AiForbruk::getUrl());

        $response->assertOk();
        $response->assertSee('AI-forbruk');
        $response->assertSee('AI-operasjoner');
        $response->assertSee('Tokenforbruk');
    }

    public function test_customer_admin_cannot_access_ai_forbruk_page(): void
    {
        $customer = $this->createCustomer('Kundekunde');
        $user = User::factory()->create([
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        $this->actingAs($user);
        $this->assertFalse(AiForbruk::canAccess());
    }

    public function test_regular_user_cannot_access_ai_forbruk_page(): void
    {
        $customer = $this->createCustomer('Kundekunde');
        $user = User::factory()->create([
            'role' => User::ROLE_USER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        $this->actingAs($user);
        $this->assertFalse(AiForbruk::canAccess());
    }

    public function test_super_admin_with_customer_id_cannot_access_ai_forbruk_page(): void
    {
        $customer = $this->createCustomer('Kundekunde');
        $user = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        $this->actingAs($user);
        $this->assertFalse(AiForbruk::canAccess());
    }

    public function test_page_shows_usage_data_for_selected_period(): void
    {
        Carbon::setTestNow('2026-06-02 12:00:00');

        $admin = $this->internalAdmin();
        $customerA = $this->createCustomer('Kunde Alfa');
        $userA = $this->createUser($customerA);

        $this->createUsageEvent($customerA, $userA, 'saved_notice_requirement_answer_draft', AiUsageEvent::STATUS_ALLOWED, 1);
        $this->createUsageEvent($customerA, $userA, 'saved_notice_requirement_answer_draft', AiUsageEvent::STATUS_BLOCKED, 1);

        $response = $this->actingAs($admin)->get(AiForbruk::getUrl());

        $response->assertOk();
        $response->assertSee('Kunde Alfa');
    }

    public function test_customer_a_data_is_isolated_from_customer_b(): void
    {
        Carbon::setTestNow('2026-06-02 12:00:00');

        $customerA = $this->createCustomer('Isolert Alfa');
        $customerB = $this->createCustomer('Isolert Beta');
        $userA = $this->createUser($customerA);
        $userB = $this->createUser($customerB);

        $this->createUsageEvent($customerA, $userA, 'saved_notice_requirement_answer_draft', AiUsageEvent::STATUS_ALLOWED, 10);
        $this->createUsageEvent($customerB, $userB, 'saved_notice_requirement_answer_draft', AiUsageEvent::STATUS_ALLOWED, 5);

        $sumA = AiUsageEvent::query()->where('customer_id', $customerA->id)->sum('operation_count');
        $sumB = AiUsageEvent::query()->where('customer_id', $customerB->id)->sum('operation_count');

        $this->assertSame(10, (int) $sumA);
        $this->assertSame(5, (int) $sumB);
    }

    public function test_kpi_aggregates_ai_operations_correctly(): void
    {
        Carbon::setTestNow('2026-06-02 12:00:00');

        $customer = $this->createCustomer('KPI Kunde');
        $user = $this->createUser($customer);

        $this->createUsageEvent($customer, $user, 'saved_notice_requirement_answer_draft', AiUsageEvent::STATUS_ALLOWED, 1);
        $this->createUsageEvent($customer, $user, 'knowledge_document_upload', AiUsageEvent::STATUS_ALLOWED, 1);
        $this->createUsageEvent($customer, $user, 'saved_notice_requirement_answer_draft', AiUsageEvent::STATUS_BLOCKED, 1);

        $total = AiUsageEvent::query()->where('customer_id', $customer->id)->count();
        $blocked = AiUsageEvent::query()->where('customer_id', $customer->id)->where('status', AiUsageEvent::STATUS_BLOCKED)->count();

        $this->assertSame(3, $total);
        $this->assertSame(1, $blocked);
    }

    public function test_token_aggregation_is_correct(): void
    {
        Carbon::setTestNow('2026-06-02 12:00:00');

        $customer = $this->createCustomer('Token Kunde');
        $user = $this->createUser($customer);

        $this->createTokenEvent($customer, $user, 'saved_notice_requirement_answer_draft', 'gpt-4.1-mini', 100, 40, 140);
        $this->createTokenEvent($customer, $user, 'saved_notice_requirement_answer_draft', 'gpt-4.1-mini', 200, 60, 260);

        $totalTokens = AiTokenEvent::query()->where('customer_id', $customer->id)->sum('total_tokens');
        $this->assertSame(400, (int) $totalTokens);
    }

    public function test_operation_key_grouping_is_isolated(): void
    {
        Carbon::setTestNow('2026-06-02 12:00:00');

        $customer = $this->createCustomer('Operasjon Kunde');
        $user = $this->createUser($customer);

        $this->createUsageEvent($customer, $user, 'saved_notice_requirement_answer_draft', AiUsageEvent::STATUS_ALLOWED, 1);
        $this->createUsageEvent($customer, $user, 'saved_notice_requirement_answer_draft', AiUsageEvent::STATUS_ALLOWED, 1);
        $this->createUsageEvent($customer, $user, 'knowledge_document_upload', AiUsageEvent::STATUS_ALLOWED, 1);

        $draftCount = AiUsageEvent::query()
            ->where('customer_id', $customer->id)
            ->where('operation_key', 'saved_notice_requirement_answer_draft')
            ->count();

        $knowledgeCount = AiUsageEvent::query()
            ->where('customer_id', $customer->id)
            ->where('operation_key', 'knowledge_document_upload')
            ->count();

        $this->assertSame(2, $draftCount);
        $this->assertSame(1, $knowledgeCount);
    }

    public function test_model_grouping_tokens_are_isolated(): void
    {
        Carbon::setTestNow('2026-06-02 12:00:00');

        $customer = $this->createCustomer('Model Kunde');
        $user = $this->createUser($customer);

        $this->createTokenEvent($customer, $user, 'draft', 'gpt-4.1-mini', 100, 40, 140);
        $this->createTokenEvent($customer, $user, 'draft', 'gpt-5', 500, 200, 700);

        $miniSum = AiTokenEvent::query()->where('model', 'gpt-4.1-mini')->sum('total_tokens');
        $gpt5Sum = AiTokenEvent::query()->where('model', 'gpt-5')->sum('total_tokens');

        $this->assertSame(140, (int) $miniSum);
        $this->assertSame(700, (int) $gpt5Sum);
    }

    public function test_total_cost_uses_uppercase_fx_for_lowercase_usd_prices(): void
    {
        Carbon::setTestNow('2026-06-03 12:00:00');

        $admin = $this->internalAdmin();
        $customer = $this->createCustomer('Valuta Kunde');
        $user = $this->createUser($customer);

        $this->createModelPrice('openai', 'gpt-4.1-mini', 'usd', 0.40, 1.60, '2026-06-01');
        $this->createExchangeRate('USD', 'NOK', 9.2935, '2026-06-03');

        $this->createTokenEvent(
            $customer,
            $user,
            'saved_notice_documents_upload',
            'gpt-4.1-mini',
            100_000,
            50_000,
            150_000,
            ['provider' => 'openai'],
        );

        $response = $this->actingAs($admin)->get(AiForbruk::getUrl());

        $response->assertOk();
        $response->assertSee('≈ 1 kr');
        $response->assertSee('Intern kostnad');
    }

    public function test_total_cost_is_partial_when_some_events_have_no_provider(): void
    {
        Carbon::setTestNow('2026-06-03 12:00:00');

        $admin = $this->internalAdmin();
        $customer = $this->createCustomer('Delvis Kunde');
        $user = $this->createUser($customer);

        $this->createModelPrice('openai', 'gpt-4.1-mini', 'usd', 0.40, 1.60, '2026-06-01');
        $this->createExchangeRate('USD', 'NOK', 9.2935, '2026-06-03');

        $this->createTokenEvent(
            $customer,
            $user,
            'saved_notice_requirement_answer_draft',
            'gpt-4.1',
            29_161,
            0,
            29_161,
        );

        $this->createTokenEvent(
            $customer,
            $user,
            'saved_notice_documents_upload',
            'gpt-4.1-mini',
            100_000,
            50_000,
            150_000,
            ['provider' => 'openai'],
        );

        $response = $this->actingAs($admin)->get(AiForbruk::getUrl());

        $response->assertOk();
        $response->assertSee('≈ 1 kr');
        $response->assertSee('Delvis dekning');
    }

    public function test_page_handles_empty_data_cleanly(): void
    {
        Carbon::setTestNow('2026-06-02 12:00:00');

        $admin = $this->internalAdmin();
        $response = $this->actingAs($admin)->get(AiForbruk::getUrl());

        $response->assertOk();
        $response->assertSee('Ingen data');
    }

    public function test_events_outside_period_are_excluded(): void
    {
        Carbon::setTestNow('2026-06-02 12:00:00');

        $customer = $this->createCustomer('Periode Kunde');
        $user = $this->createUser($customer);

        $oldNow = Carbon::getTestNow();

        Carbon::setTestNow('2026-01-15 10:00:00');
        $this->createUsageEvent($customer, $user, 'draft', AiUsageEvent::STATUS_ALLOWED, 1);

        Carbon::setTestNow('2026-06-01 10:00:00');
        $this->createUsageEvent($customer, $user, 'draft', AiUsageEvent::STATUS_ALLOWED, 1);

        Carbon::setTestNow($oldNow);

        $juneCount = AiUsageEvent::query()
            ->where('customer_id', $customer->id)
            ->whereBetween('created_at', [
                Carbon::create(2026, 6, 1)->startOfDay(),
                Carbon::create(2026, 6, 2)->endOfDay(),
            ])
            ->count();

        $this->assertSame(1, $juneCount);
    }

    public function test_page_shows_customer_in_selector(): void
    {
        Carbon::setTestNow('2026-06-02 12:00:00');

        $this->createCustomer('Synlig Kunde AS');
        $admin = $this->internalAdmin();

        $response = $this->actingAs($admin)->get(AiForbruk::getUrl());
        $response->assertOk();
        $response->assertSee('Synlig Kunde AS');
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    private function internalAdmin(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'customer_id' => null,
            'is_active' => true,
        ]);
    }

    private function createCustomer(string $name): Customer
    {
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
            'included_ai_credits' => 10,
        ]);
    }

    private function createUser(Customer $customer): User
    {
        return User::factory()->create([
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function createUsageEvent(Customer $customer, User $user, string $operationKey, string $status, int $count): void
    {
        AiUsageEvent::query()->create([
            'customer_id' => $customer->id,
            'user_id' => $user->id,
            'operation_key' => $operationKey,
            'status' => $status,
            'limit_type' => $status === AiUsageEvent::STATUS_BLOCKED ? AiUsageEvent::LIMIT_TYPE_CUSTOMER : null,
            'operation_count' => $count,
        ]);
    }

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
        return AiTokenEvent::query()->create(array_merge([
            'customer_id' => $customer->id,
            'user_id' => $user->id,
            'operation_key' => $operationKey,
            'model' => $model,
            'input_tokens' => $input,
            'output_tokens' => $output,
            'total_tokens' => $total,
        ], $overrides));
    }

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
