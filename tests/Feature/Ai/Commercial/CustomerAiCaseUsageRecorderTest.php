<?php

namespace Tests\Feature\Ai\Commercial;

use App\Models\Customer;
use App\Models\CustomerAiCaseUsage;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\SavedNotice;
use App\Models\User;
use App\Services\Ai\Commercial\CustomerAiCaseUsageRecorder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class CustomerAiCaseUsageRecorderTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Purpose: Verify that the recorder creates one row for the current calendar month.
     * Inputs: None.
     * Returns: None.
     * Side effects: Writes one ledger row in the test database.
     */
    public function test_it_records_a_saved_notice_as_ai_active_for_the_current_calendar_month(): void
    {
        Carbon::setTestNow('2026-06-15 10:00:00');

        $customer = $this->createCustomer('Case Ledger Customer AS');
        $savedNotice = $this->createSavedNotice($customer->id, 'CASE-LEDGER-001', 'Case Ledger Notice');
        $user = $this->createUser($customer, 'Case Ledger User');
        $recorder = app(CustomerAiCaseUsageRecorder::class);

        $first = $recorder->record(
            $savedNotice,
            'saved_notice_requirement_answer_draft',
            $user,
            101,
            202,
        );

        $this->assertInstanceOf(CustomerAiCaseUsage::class, $first);
        $this->assertSame(1, CustomerAiCaseUsage::query()->count());
        $this->assertSame(1, $customer->aiCaseUsages()->count());
        $this->assertSame(1, $savedNotice->aiCaseUsages()->count());
        $this->assertSame($customer->id, $first->customer_id);
        $this->assertSame($savedNotice->id, $first->saved_notice_id);
        $this->assertSame($user->id, $first->activated_by_user_id);
        $this->assertSame('saved_notice_requirement_answer_draft', $first->source_operation_key);
        $this->assertSame(101, $first->source_ai_usage_event_id);
        $this->assertSame(202, $first->source_ai_token_event_id);
        $this->assertSame('2026-06-15 10:00:00', $first->activated_at?->toDateTimeString());
        $this->assertSame('2026-06-01', $first->period_start?->toDateString());
        $this->assertSame('2026-06-30', $first->period_end?->toDateString());
    }

    /**
     * Purpose: Verify that the recorder does not duplicate the same SavedNotice in the same period.
     * Inputs: None.
     * Returns: None.
     * Side effects: Writes one ledger row in the test database.
     */
    public function test_it_does_not_duplicate_usage_for_the_same_saved_notice_in_the_same_period(): void
    {
        Carbon::setTestNow('2026-06-15 10:00:00');

        $customer = $this->createCustomer('Case Ledger Customer AS');
        $savedNotice = $this->createSavedNotice($customer->id, 'CASE-LEDGER-001', 'Case Ledger Notice');
        $user = $this->createUser($customer, 'Case Ledger User');
        $recorder = app(CustomerAiCaseUsageRecorder::class);

        $first = $recorder->record(
            $savedNotice,
            'saved_notice_requirement_answer_draft',
            $user,
            101,
            202,
        );

        $second = $recorder->record(
            $savedNotice,
            'saved_notice_documents_upload',
            $user,
            303,
            404,
        );

        $this->assertInstanceOf(CustomerAiCaseUsage::class, $second);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, CustomerAiCaseUsage::query()->count());
        $this->assertSame('saved_notice_requirement_answer_draft', $second->source_operation_key);
        $this->assertSame(101, $second->source_ai_usage_event_id);
        $this->assertSame(202, $second->source_ai_token_event_id);
    }

    /**
     * Purpose: Verify that a new calendar month creates a second ledger row for the same SavedNotice.
     * Inputs: None.
     * Returns: None.
     * Side effects: Writes two ledger rows in the test database.
     */
    public function test_it_records_a_new_usage_for_the_same_saved_notice_in_a_new_period(): void
    {
        Carbon::setTestNow('2026-06-20 14:30:00');

        $customer = $this->createCustomer('Case Ledger Period AS');
        $savedNotice = $this->createSavedNotice($customer->id, 'CASE-LEDGER-002', 'Case Ledger Period Notice');
        $user = $this->createUser($customer, 'Case Ledger Period User');
        $recorder = app(CustomerAiCaseUsageRecorder::class);

        $first = $recorder->record(
            $savedNotice,
            'saved_notice_documents_upload',
            $user,
            707,
            808,
        );

        Carbon::setTestNow('2026-07-03 09:30:00');

        $second = $recorder->record(
            $savedNotice,
            'saved_notice_assessment_refresh',
            $user,
            909,
            1001,
        );

        $this->assertInstanceOf(CustomerAiCaseUsage::class, $first);
        $this->assertInstanceOf(CustomerAiCaseUsage::class, $second);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, CustomerAiCaseUsage::query()->count());
        $this->assertSame('2026-06-01', $first->period_start?->toDateString());
        $this->assertSame('2026-06-30', $first->period_end?->toDateString());
        $this->assertSame('2026-07-01', $second->period_start?->toDateString());
        $this->assertSame('2026-07-31', $second->period_end?->toDateString());
    }

    /**
     * Purpose: Verify that persistence failures are swallowed and logged as warnings.
     * Inputs: None.
     * Returns: None.
     * Side effects: Stubs the DB transaction facade and asserts warning logging.
     */
    public function test_it_returns_null_and_logs_warning_when_persistence_fails(): void
    {
        Carbon::setTestNow('2026-06-20 14:30:00');

        $customer = $this->createCustomer('Case Ledger Failure AS');
        $savedNotice = $this->createSavedNotice($customer->id, 'CASE-LEDGER-003', 'Case Ledger Failure Notice');
        $user = $this->createUser($customer, 'Case Ledger Failure User');
        $recorder = app(CustomerAiCaseUsageRecorder::class);

        DB::shouldReceive('transaction')
            ->once()
            ->andThrow(new RuntimeException('Persistence failed'));

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use ($customer, $savedNotice): bool {
                return $message === '[PROCYNIA][AI_CASE_USAGE] Failed to record AI case usage.'
                    && ($context['customer_id'] ?? null) === $customer->id
                    && ($context['saved_notice_id'] ?? null) === $savedNotice->id
                    && ($context['source_operation_key'] ?? null) === 'saved_notice_documents_upload'
                    && ($context['error'] ?? null) === 'Persistence failed';
            });

        $result = $recorder->record(
            $savedNotice,
            'saved_notice_documents_upload',
            $user,
            111,
            222,
        );

        $this->assertNull($result);
        $this->assertSame(0, CustomerAiCaseUsage::query()->count());
    }

    /**
     * Purpose: Verify that a SavedNotice without persisted customer context is ignored safely.
     * Inputs: None.
     * Returns: None.
     * Side effects: Does not write any ledger rows.
     */
    public function test_it_returns_null_when_saved_notice_has_no_customer_context(): void
    {
        $recorder = app(CustomerAiCaseUsageRecorder::class);
        $savedNotice = new SavedNotice();

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === '[PROCYNIA][AI_CASE_USAGE] Skipped case usage recording because the SavedNotice or customer context was missing.'
                    && ($context['customer_id'] ?? null) === null
                    && ($context['saved_notice_id'] ?? null) === null
                    && ($context['source_operation_key'] ?? null) === 'unknown';
            });

        $result = $recorder->record($savedNotice, '   ');

        $this->assertNull($result);
        $this->assertSame(0, CustomerAiCaseUsage::query()->count());
    }

    /**
     * Purpose: Create a deterministic customer fixture for the recorder tests.
     * Inputs: The customer name.
     * Returns: The persisted customer row.
     * Side effects: Writes prerequisite language and nationality rows if needed.
     */
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
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);
    }

    /**
     * Purpose: Create a deterministic customer-scoped user fixture for the recorder tests.
     * Inputs: The owning customer and display name.
     * Returns: The persisted user row.
     * Side effects: Writes a user row in the test database.
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
     * Purpose: Create a deterministic SavedNotice fixture for the recorder tests.
     * Inputs: The customer id, external id, and title.
     * Returns: The persisted SavedNotice row.
     * Side effects: Writes one SavedNotice row in the test database.
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
}
