<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\AiUsageCapacity;
use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\CustomerResource\Pages\ManageCustomerAiControl;
use App\Models\AiRuntimeControl;
use App\Models\Customer;
use App\Models\CustomerAiCaseUsage;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\SavedNotice;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\AiQuotaNotification;
use App\Services\Ai\Commercial\AiQuotaStatusService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/** The internal cost-control surface: who may use it, and that it goes through the services. */
class AiCostControlAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-15 10:00:00');
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // =========================================================================
    // Authorization
    // =========================================================================

    public function test_an_internal_admin_can_open_the_customer_ai_control_page(): void
    {
        $admin = $this->internalAdmin();
        $customer = $this->customer(10);

        $this->actingAs($admin)
            ->get(CustomerResource::getUrl('ai-control', ['record' => $customer]))
            ->assertOk();

        $this->assertTrue(ManageCustomerAiControl::canAccess());
    }

    public function test_a_customer_system_owner_cannot_open_the_internal_ai_control_page(): void
    {
        $customer = $this->customer(10);
        $systemOwner = $this->customerUser($customer, User::BID_ROLE_SYSTEM_OWNER, User::ROLE_CUSTOMER_ADMIN);

        $this->actingAs($systemOwner)
            ->get(CustomerResource::getUrl('ai-control', ['record' => $customer]))
            ->assertForbidden();

        $this->actingAs($systemOwner);
        $this->assertFalse(ManageCustomerAiControl::canAccess());
    }

    public function test_an_ordinary_customer_user_cannot_open_the_internal_ai_control_page(): void
    {
        $customer = $this->customer(10);
        $contributor = $this->customerUser($customer, User::BID_ROLE_CONTRIBUTOR, User::ROLE_USER);

        $this->actingAs($contributor)
            ->get(CustomerResource::getUrl('ai-control', ['record' => $customer]))
            ->assertForbidden();
    }

    public function test_a_customer_user_cannot_suspend_ai_even_by_calling_the_action_directly(): void
    {
        $customer = $this->customer(10);
        $systemOwner = $this->customerUser($customer, User::BID_ROLE_SYSTEM_OWNER, User::ROLE_CUSTOMER_ADMIN);

        $this->actingAs($systemOwner);

        // Server-side, not just hidden in the UI.
        Livewire::test(ManageCustomerAiControl::class, ['record' => $customer])
            ->assertForbidden();

        $this->assertNotSame(Customer::AI_ACCESS_SUSPENDED, $customer->fresh()->ai_access_status);
    }

    // =========================================================================
    // Customer suspend / resume
    // =========================================================================

    public function test_an_admin_can_suspend_and_resume_ai_with_an_audited_reason(): void
    {
        $admin = $this->internalAdmin();
        $customer = $this->customer(10);
        $owner = $this->customerUser($customer, User::BID_ROLE_SYSTEM_OWNER, User::ROLE_USER);

        $this->actingAs($admin);

        Livewire::test(ManageCustomerAiControl::class, ['record' => $customer])
            ->callAction('suspend_ai', ['reason' => 'Mistanke om ukontrollert forbruk']);

        $this->assertSame(Customer::AI_ACCESS_SUSPENDED, $customer->fresh()->ai_access_status);
        $this->assertDatabaseHas('billing_events', [
            'customer_id' => $customer->id,
            'user_id' => $admin->id,
            'event_type' => 'ai_customer_suspended',
            'source' => 'ai_cost_control',
            'description' => 'Mistanke om ukontrollert forbruk',
        ]);

        Livewire::test(ManageCustomerAiControl::class, ['record' => $customer->fresh()])
            ->callAction('resume_ai', ['reason' => 'Avklart med kunden']);

        $this->assertSame(Customer::AI_ACCESS_ENABLED, $customer->fresh()->ai_access_status);
        $this->assertDatabaseHas('billing_events', [
            'customer_id' => $customer->id,
            'user_id' => $admin->id,
            'event_type' => 'ai_customer_resumed',
        ]);

        // One action, one notification each way — the admin trigger reuses the phase 3 service.
        Notification::assertSentToTimes($owner, AiQuotaNotification::class, 2);
        $this->assertEqualsCanonicalizing(
            ['ai_quota.ai_suspended', 'ai_quota.ai_resumed'],
            UserNotification::query()->where('customer_id', $customer->id)->pluck('event_type')->all(),
        );
    }

    public function test_suspending_blocks_the_customers_ai_immediately(): void
    {
        $admin = $this->internalAdmin();
        $customer = $this->customer(10);

        $this->actingAs($admin);
        Livewire::test(ManageCustomerAiControl::class, ['record' => $customer])
            ->callAction('suspend_ai', ['reason' => 'Driftsstans']);

        $status = app(AiQuotaStatusService::class)->forCustomer($customer->fresh());

        $this->assertTrue($status->isSuspended);
        $this->assertSame('suspended', $status->status);
    }

    // =========================================================================
    // Credit adjustments
    // =========================================================================

    public function test_an_admin_can_grant_extra_credits_from_the_page(): void
    {
        $admin = $this->internalAdmin();
        $customer = $this->customer(3);
        $this->consume($customer, 3);

        $this->actingAs($admin);
        Livewire::test(ManageCustomerAiControl::class, ['record' => $customer])
            ->callAction('adjust_credits', ['amount' => 5, 'reason' => 'Kompensasjon for feilet kjøring']);

        $status = app(AiQuotaStatusService::class)->forCustomer($customer->fresh());

        $this->assertSame(5, $status->extra);
        $this->assertSame(5, $status->remaining);
        $this->assertDatabaseHas('customer_ai_credit_adjustments', [
            'customer_id' => $customer->id,
            'amount' => 5,
            'actor_user_id' => $admin->id,
            'reason' => 'Kompensasjon for feilet kjøring',
        ]);
        $this->assertDatabaseHas('billing_events', [
            'customer_id' => $customer->id,
            'user_id' => $admin->id,
            'event_type' => 'ai_credits_adjusted',
        ]);
    }

    public function test_the_page_shows_the_canonical_quota_and_the_audit_history(): void
    {
        $admin = $this->internalAdmin();
        $customer = $this->customer(10);
        $this->consume($customer, 8);

        $this->actingAs($admin);

        Livewire::test(ManageCustomerAiControl::class, ['record' => $customer])
            ->assertSet('quota.used', 8)
            ->assertSet('quota.included', 10)
            ->assertSet('quota.remaining', 2)
            ->assertSet('quota.status', 'warning')
            ->assertSet('quota.period_start', '2026-09-01');
    }

    public function test_the_page_never_shows_another_customers_position(): void
    {
        $admin = $this->internalAdmin();
        $customer = $this->customer(10);
        $other = $this->customer(10);
        $this->consume($customer, 2);
        $this->consume($other, 9);

        $this->actingAs($admin);

        Livewire::test(ManageCustomerAiControl::class, ['record' => $customer])
            ->assertSet('quota.customer_id', $customer->id)
            ->assertSet('quota.used', 2);
    }

    // =========================================================================
    // Global stop
    // =========================================================================

    public function test_an_admin_can_enable_and_disable_the_global_stop_from_the_ui(): void
    {
        $admin = $this->internalAdmin();
        $this->actingAs($admin);

        Livewire::test(AiUsageCapacity::class)
            ->assertSet('globalStopActive', false)
            ->callAction('enable_global_stop', ['reason' => 'Leverandørhendelse'])
            ->assertSet('globalStopActive', true);

        $this->assertTrue(AiRuntimeControl::query()->orderBy('id')->first()->global_ai_stop);
        $this->assertDatabaseHas('billing_events', [
            'customer_id' => null,
            'user_id' => $admin->id,
            'event_type' => 'ai_global_stop_enabled',
            'description' => 'Leverandørhendelse',
        ]);

        Livewire::test(AiUsageCapacity::class)
            ->callAction('disable_global_stop', ['reason' => 'Hendelsen er lukket'])
            ->assertSet('globalStopActive', false);

        $this->assertFalse(AiRuntimeControl::query()->orderBy('id')->first()->global_ai_stop);
        $this->assertDatabaseHas('billing_events', [
            'user_id' => $admin->id,
            'event_type' => 'ai_global_stop_disabled',
        ]);
    }

    public function test_a_customer_user_cannot_reach_the_global_control_page(): void
    {
        $customer = $this->customer(10);
        $systemOwner = $this->customerUser($customer, User::BID_ROLE_SYSTEM_OWNER, User::ROLE_CUSTOMER_ADMIN);

        $this->actingAs($systemOwner)->get(AiUsageCapacity::getUrl())->assertForbidden();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function consume(Customer $customer, int $cases): void
    {
        for ($index = 0; $index < $cases; $index++) {
            CustomerAiCaseUsage::query()->create([
                'customer_id' => $customer->id,
                'saved_notice_id' => SavedNotice::query()->create([
                    'customer_id' => $customer->id,
                    'external_id' => 'ADM-'.Str::random(10),
                    'title' => 'Admin notice',
                    'buyer_name' => 'Procynia',
                    'status' => 'ACTIVE',
                ])->id,
                'activated_at' => now(),
                'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
                'source_operation_key' => 'test',
            ]);
        }
    }

    private function internalAdmin(): User
    {
        return User::query()->create([
            'name' => 'Procynia Admin',
            'email' => 'procynia.admin+'.Str::lower(Str::random(8)).'@example.test',
            'password' => bcrypt('SecretPass123!'),
            'role' => User::ROLE_SUPER_ADMIN,
            'customer_id' => null,
            'is_active' => true,
        ]);
    }

    private function customerUser(Customer $customer, string $bidRole, string $role): User
    {
        return User::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Customer user '.Str::random(6),
            'email' => Str::lower(Str::random(10)).'@customer.test',
            'password' => bcrypt('SecretPass123!'),
            'role' => $role,
            'bid_role' => $bidRole,
            'is_active' => true,
        ]);
    }

    private function customer(int $credits): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => 'Admin '.Str::random(8),
            'slug' => 'admin-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
            'subscription_plan' => Customer::PLAN_PRO,
            'included_ai_credits' => $credits,
        ]);
    }
}
