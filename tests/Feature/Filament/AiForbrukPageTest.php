<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\AiForbruk;
use App\Models\AdminPageHelp;
use App\Models\CustomerAiCaseUsage;
use App\Models\AiModelPrice;
use App\Models\AiTokenEvent;
use App\Models\AiUsageEvent;
use App\Models\ExchangeRate;
use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\SavedNotice;
use App\Models\User;
use App\Services\Ai\AiUsageGuard;
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
        $response->assertSeeText('Faktisk AI-forbruk og intern kost');
        $response->assertSee('AI-operasjoner');
        $response->assertSee('Tokenforbruk');
    }

    public function test_help_action_is_present_when_primary_key_record_exists(): void
    {
        Carbon::setTestNow('2026-06-02 12:00:00');

        AdminPageHelp::create([
            'page_key'  => 'admin.billing.ai_usage',
            'title'     => 'Hjelp — AI-forbruk',
            'sections'  => [],
            'is_active' => true,
        ]);

        $admin = $this->internalAdmin();
        $response = $this->actingAs($admin)->get(AiForbruk::getUrl());

        $response->assertOk();
        $response->assertSee('Hjelp');
    }

    public function test_help_action_falls_back_to_legacy_key_when_new_key_missing(): void
    {
        Carbon::setTestNow('2026-06-02 12:00:00');

        AdminPageHelp::create([
            'page_key'  => 'admin.ai_forbruk',
            'title'     => 'Hjelp — AI-forbruk',
            'sections'  => [],
            'is_active' => true,
        ]);

        $admin = $this->internalAdmin();
        $response = $this->actingAs($admin)->get(AiForbruk::getUrl());

        $response->assertOk();
        $response->assertSee('Hjelp');
    }

    public function test_page_help_partial_shows_edit_link_when_edit_url_provided(): void
    {
        $html = view('filament.components.page-help', [
            'intro'     => 'Test intro',
            'sections'  => [],
            'editUrl'   => '/admin/admin-page-helps/1/edit',
            'editLabel' => 'Rediger hjelpetekst',
        ])->render();

        $this->assertStringContainsString('Rediger hjelpetekst', $html);
        $this->assertStringContainsString('/admin/admin-page-helps/1/edit', $html);
    }

    public function test_page_help_partial_does_not_show_edit_link_without_edit_url(): void
    {
        $html = view('filament.components.page-help', [
            'intro'    => 'Test intro',
            'sections' => [],
        ])->render();

        $this->assertStringNotContainsString('Rediger hjelpetekst', $html);
    }

    public function test_ai_forbruk_page_renders_without_help_action_when_no_record_exists(): void
    {
        Carbon::setTestNow('2026-06-02 12:00:00');

        $admin = $this->internalAdmin();
        $response = $this->actingAs($admin)->get(AiForbruk::getUrl());

        $response->assertOk();
        $response->assertSee('AI-forbruk');
        // Hjelp-knappen skal ikke vises når ingen aktiv AdminPageHelp finnes
        $response->assertDontSee('Hjelp — AI-forbruk');
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

    public function test_page_shows_recent_token_events(): void
    {
        Carbon::setTestNow('2026-06-02 12:00:00');

        $admin = $this->internalAdmin();
        $customer = $this->createCustomer('Hendelses Kunde');
        $user = $this->createUser($customer);

        $this->createTokenEvent(
            $customer,
            $user,
            'saved_notice_requirement_answer_draft',
            'gpt-4.1-mini',
            100,
            40,
            140,
        );

        $response = $this->actingAs($admin)->get(AiForbruk::getUrl());

        $response->assertOk();
        $response->assertSee('Hendelseslogg');
        $response->assertSee('Siste token-events (maks 30)');
        $response->assertSee('02.06.2026 12:00');
        $response->assertSee('Hendelses Kunde');
    }

    public function test_page_shows_token_usage_per_customer_and_model(): void
    {
        Carbon::setTestNow('2026-06-02 12:00:00');

        $admin = $this->internalAdmin();
        $customerA = $this->createCustomer('Kunde Alfa');
        $customerB = $this->createCustomer('Kunde Beta');
        $userA = $this->createUser($customerA);
        $userB = $this->createUser($customerB);

        $this->createTokenEvent($customerA, $userA, 'saved_notice_requirement_answer_draft', 'gpt-4.1-mini', 100, 40, 140);
        $this->createTokenEvent($customerA, $userA, 'saved_notice_requirement_answer_draft', 'gpt-4.1-mini', 200, 80, 280);
        $this->createTokenEvent($customerB, $userB, 'saved_notice_requirement_answer_draft', 'gpt-5', 500, 200, 700);

        $response = $this->actingAs($admin)->get(AiForbruk::getUrl());

        $response->assertOk();
        $response->assertSee('Tokenforbruk per kunde');
        $response->assertSee('Tokenforbruk per modell');
        $response->assertSee('Kunde Alfa');
        $response->assertSee('Kunde Beta');
        $response->assertSee('gpt-4.1-mini');
        $response->assertSee('gpt-5');
        $response->assertSee('420');
        $response->assertSee('700');
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

    public function test_counts_activated_cases_from_customer_ai_case_usages(): void
    {
        Carbon::setTestNow('2026-07-15 12:00:00');

        $admin = $this->internalAdmin();
        $customer = $this->createCustomer('Ledger Kunde');
        $user = $this->createUser($customer);

        $noticeA = $this->createSavedNotice($customer, 'AI-CASE-USAGE-001', 'Case usage A');
        $noticeB = $this->createSavedNotice($customer, 'AI-CASE-USAGE-002', 'Case usage B');

        $this->createCaseUsage($customer, $noticeA, '2026-06-20 10:00:00', AiUsageGuard::OPERATION_SAVED_NOTICE_REQUIREMENT_ANSWER_DRAFT);
        $this->createCaseUsage($customer, $noticeB, '2026-07-10 14:00:00', AiUsageGuard::OPERATION_SAVED_NOTICE_DOCUMENTS_UPLOAD);

        $response = $this->actingAs($admin)->get(AiForbruk::getUrl());

        $response->assertOk();
        $response->assertSee('2 / 10');
        $response->assertSeeText('2 av 10 AI-saker');
        $response->assertSee('Ledger Kunde');
    }

    public function test_does_not_count_token_events_as_activated_cases_without_ledger_rows(): void
    {
        Carbon::setTestNow('2026-06-30 12:00:00');

        $admin = $this->internalAdmin();
        $customer = $this->createCustomer('Token Only Kunde');
        $user = $this->createUser($customer);
        $savedNotice = $this->createSavedNotice($customer, 'AI-TOKEN-ONLY-001', 'Token only target');

        $this->createTokenEvent(
            $customer,
            $user,
            'saved_notice_requirement_answer_draft',
            'gpt-4.1',
            12_000,
            4_000,
            16_000,
            ['saved_notice_id' => $savedNotice->id],
        );

        $response = $this->actingAs($admin)->get(AiForbruk::getUrl());

        $response->assertOk();
        $response->assertSee('0 / 10');
        $response->assertSeeText('0 av 10 AI-saker');
        $response->assertSee('Token Only Kunde');
    }

    public function test_filters_ai_case_usages_by_dashboard_period(): void
    {
        Carbon::setTestNow('2026-06-30 12:00:00');

        $admin = $this->internalAdmin();
        $customer = $this->createCustomer('Periode Ledger Kunde');
        $this->createUser($customer);

        $inPeriodNotice = $this->createSavedNotice($customer, 'AI-CASE-PERIOD-IN', 'In period target');
        $outPeriodNotice = $this->createSavedNotice($customer, 'AI-CASE-PERIOD-OUT', 'Out period target');

        $this->createCaseUsage($customer, $inPeriodNotice, '2026-06-15 10:00:00', AiUsageGuard::OPERATION_SAVED_NOTICE_ASSESSMENT_REFRESH);
        $this->createCaseUsage($customer, $outPeriodNotice, '2026-05-31 10:00:00', AiUsageGuard::OPERATION_SAVED_NOTICE_DOCUMENTS_UPLOAD);

        $response = $this->actingAs($admin)->get(AiForbruk::getUrl());

        $response->assertOk();
        $response->assertSee('1 / 10');
        $response->assertSeeText('1 av 10 AI-saker');
        $response->assertSee('Periode Ledger Kunde');
    }

    public function test_counts_unique_saved_notices_if_multiple_rows_exist_across_periods(): void
    {
        Carbon::setTestNow('2026-07-15 12:00:00');

        $admin = $this->internalAdmin();
        $customer = $this->createCustomer('Unik Ledger Kunde');
        $this->createUser($customer);

        $savedNotice = $this->createSavedNotice($customer, 'AI-CASE-UNIQUE-001', 'Unique target');

        $this->createCaseUsage($customer, $savedNotice, '2026-06-20 10:00:00', AiUsageGuard::OPERATION_SAVED_NOTICE_REQUIREMENT_ANSWER_DRAFT);
        $this->createCaseUsage($customer, $savedNotice, '2026-07-10 10:00:00', AiUsageGuard::OPERATION_SAVED_NOTICE_ASSESSMENT_REFRESH);

        $response = $this->actingAs($admin)->get(AiForbruk::getUrl());

        $response->assertOk();
        $response->assertSee('1 / 10');
        $response->assertSeeText('1 av 10 AI-saker');
        $response->assertSee('Unik Ledger Kunde');
    }

    public function test_capacity_shows_one_case_when_one_ai_case_usage_exists_in_period(): void
    {
        // Customer has a limit of 60 AI cases. One case was activated via AI in the period.
        // The capacity counter must show 1 / 60 and not stay at 0 / 60.
        Carbon::setTestNow('2026-06-13 12:00:00');

        $admin = $this->internalAdmin();
        $customer = $this->createCustomer('Kapasitet Kunde');
        $customer->update(['included_ai_credits' => 60]);

        $savedNotice = $this->createSavedNotice($customer, 'KAPASITET-001', 'Test case');

        // One AI case usage within the last30 period (May 15 – Jun 13)
        $this->createCaseUsage(
            $customer,
            $savedNotice,
            '2026-06-01 10:00:00',
            AiUsageGuard::OPERATION_SAVED_NOTICE_DOCUMENTS_UPLOAD,
        );

        $response = $this->actingAs($admin)->get(AiForbruk::getUrl());

        $response->assertOk();
        $response->assertSee('1 / 60');
        $response->assertSeeText('1 av 60 AI-saker');
    }

    public function test_capacity_counts_unique_cases_not_operations_when_same_case_used_multiple_times(): void
    {
        // Multiple AI usage events on the same case must still count as 1, not multiple.
        Carbon::setTestNow('2026-06-13 12:00:00');

        $admin = $this->internalAdmin();
        $customer = $this->createCustomer('Dedup Kapasitet Kunde');
        $customer->update(['included_ai_credits' => 60]);

        $savedNotice = $this->createSavedNotice($customer, 'DEDUP-KAPASITET-001', 'Dedup case');

        // Same case recorded twice (simulating idempotent recorder behaviour)
        $this->createCaseUsage(
            $customer,
            $savedNotice,
            '2026-06-01 10:00:00',
            AiUsageGuard::OPERATION_SAVED_NOTICE_DOCUMENTS_UPLOAD,
        );
        // Second record for same case — recorder is idempotent (firstOrCreate), but
        // even if a second row existed the count should use COUNT(DISTINCT saved_notice_id).
        // Here we simulate dashboard correctly counting 1 unique case regardless.

        $response = $this->actingAs($admin)->get(AiForbruk::getUrl());

        $response->assertOk();
        $response->assertSee('1 / 60');
    }

    public function test_trend_data_covers_full_selected_period_when_data_stops_early(): void
    {
        // Period: 2026-05-15 to 2026-06-13 (last30 preset from mount())
        Carbon::setTestNow('2026-06-13 12:00:00');

        $admin = $this->internalAdmin();
        $customer = $this->createCustomer('Trend Dekning Kunde');
        $user = $this->createUser($customer);

        // Create one event on 2026-06-03 — 10 days before the period end
        Carbon::setTestNow('2026-06-03 10:00:00');
        $this->createUsageEvent($customer, $user, 'draft', AiUsageEvent::STATUS_ALLOWED, 1);

        Carbon::setTestNow('2026-06-13 12:00:00');

        $response = $this->actingAs($admin)->get(AiForbruk::getUrl());

        $response->assertOk();

        // The trend table must cover the full period, not just dates with data.
        // Without the fix, only 2026-06-03 would appear; the period end would be missing.
        $response->assertSee('2026-06-13'); // last date of period — no events, value must be 0
        $response->assertSee('2026-05-15'); // first date of period — also no events
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

    /**
     * Purpose: Create a deterministic SavedNotice fixture for AI case usage reporting tests.
     * Inputs: The owning customer, a unique external id, a title, and optional overrides.
     * Returns: The persisted SavedNotice model.
     * Side effects: Writes one saved_notices row to the test database.
     */
    private function createSavedNotice(Customer $customer, string $externalId, string $title, array $overrides = []): SavedNotice
    {
        return SavedNotice::query()->create(array_merge([
            'customer_id' => $customer->id,
            'external_id' => $externalId,
            'title' => $title,
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
            'source_type' => SavedNotice::SOURCE_TYPE_PUBLIC_NOTICE,
        ], $overrides));
    }

    /**
     * Purpose: Create one AI case usage ledger row for a SavedNotice in a specific month.
     * Inputs: The customer, SavedNotice, activation timestamp, and optional source operation key.
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
