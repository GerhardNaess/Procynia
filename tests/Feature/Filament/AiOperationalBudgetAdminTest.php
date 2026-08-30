<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\AiUsageCapacity;
use App\Filament\Resources\CustomerResource\Pages\ManageCustomerAiControl;
use App\Models\AiRuntimeControl;
use App\Models\BillingEvent;
use App\Models\Customer;
use App\Models\CustomerAiOperationalLimit;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/** Only Procynia sets a NOK safety budget, and every change is audited. */
class AiOperationalBudgetAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-15 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_an_admin_can_set_a_customer_nok_budget_with_an_audited_reason(): void
    {
        $admin = $this->internalAdmin();
        $customer = $this->customer();

        $this->actingAs($admin);
        Livewire::test(ManageCustomerAiControl::class, ['record' => $customer])
            ->callAction('set_operational_limits', [
                'is_enabled' => true,
                'daily_nok_limit' => 500,
                'monthly_nok_limit' => 9000,
                'reason' => 'Avtalt takgrense for september',
            ]);

        $limit = CustomerAiOperationalLimit::query()->where('customer_id', $customer->id)->firstOrFail();

        $this->assertTrue($limit->is_enabled);
        $this->assertEqualsWithDelta(500.0, (float) $limit->daily_nok_limit, 0.01);
        $this->assertEqualsWithDelta(9000.0, (float) $limit->monthly_nok_limit, 0.01);
        $this->assertSame($admin->id, $limit->changed_by_user_id);
        $this->assertDatabaseHas('billing_events', [
            'customer_id' => $customer->id,
            'user_id' => $admin->id,
            'event_type' => 'ai_customer_operational_limits_updated',
            'source' => 'ai_cost_control',
            'description' => 'Avtalt takgrense for september',
        ]);
    }

    public function test_a_customer_budget_change_records_the_previous_values(): void
    {
        $admin = $this->internalAdmin();
        $customer = $this->customer();
        CustomerAiOperationalLimit::query()->create([
            'customer_id' => $customer->id, 'is_enabled' => true, 'daily_nok_limit' => 100, 'monthly_nok_limit' => 2000,
        ]);

        $this->actingAs($admin);
        Livewire::test(ManageCustomerAiControl::class, ['record' => $customer])
            ->callAction('set_operational_limits', [
                'is_enabled' => true, 'daily_nok_limit' => 300, 'monthly_nok_limit' => 5000, 'reason' => 'Økt etter avtale',
            ]);

        $event = BillingEvent::query()
            ->where('event_type', 'ai_customer_operational_limits_updated')->latest('id')->firstOrFail();

        $this->assertEqualsWithDelta(100.0, (float) $event->before['daily_nok_limit'], 0.01);
        $this->assertEqualsWithDelta(300.0, (float) $event->after['daily_nok_limit'], 0.01);
    }

    public function test_an_admin_can_set_the_global_nok_budget(): void
    {
        $admin = $this->internalAdmin();

        $this->actingAs($admin);
        Livewire::test(AiUsageCapacity::class)
            ->callAction('set_global_operational_limits', [
                'operational_budget_enabled' => true,
                'global_daily_nok_limit' => 4000,
                'global_monthly_nok_limit' => 80000,
                'reason' => 'Plattformtak for september',
            ]);

        $control = AiRuntimeControl::query()->orderBy('id')->firstOrFail();

        $this->assertTrue((bool) $control->operational_budget_enabled);
        $this->assertEqualsWithDelta(4000.0, (float) $control->global_daily_nok_limit, 0.01);
        $this->assertDatabaseHas('billing_events', [
            'customer_id' => null,
            'user_id' => $admin->id,
            'event_type' => 'ai_global_operational_limits_updated',
            'description' => 'Plattformtak for september',
        ]);
    }

    public function test_the_customer_page_shows_the_operational_position_separately_from_the_quota(): void
    {
        $admin = $this->internalAdmin();
        $customer = $this->customer();
        CustomerAiOperationalLimit::query()->create([
            'customer_id' => $customer->id, 'is_enabled' => true, 'daily_nok_limit' => 500,
        ]);

        $this->actingAs($admin);
        Livewire::test(ManageCustomerAiControl::class, ['record' => $customer])
            ->assertSet('operationalBudget.is_enabled', true)
            ->assertSet('operationalBudget.daily.limit', 500.0)
            ->assertSet('operationalBudget.daily.committed', 0.0)
            // The commercial quota is still its own, separate figure on the same page.
            ->assertSet('quota.included', 10);
    }

    public function test_a_customer_user_cannot_set_a_nok_budget(): void
    {
        $customer = $this->customer();
        $systemOwner = User::query()->create([
            'customer_id' => $customer->id, 'name' => 'System owner',
            'email' => Str::lower(Str::random(10)).'@customer.test',
            'password' => bcrypt('SecretPass123!'), 'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER, 'is_active' => true,
        ]);

        $this->actingAs($systemOwner);

        Livewire::test(ManageCustomerAiControl::class, ['record' => $customer])->assertForbidden();
        $this->actingAs($systemOwner)->get(AiUsageCapacity::getUrl())->assertForbidden();
        $this->assertDatabaseCount('customer_ai_operational_limits', 0);
    }

    private function internalAdmin(): User
    {
        return User::query()->create([
            'name' => 'Procynia Admin',
            'email' => 'procynia.admin+'.Str::lower(Str::random(8)).'@example.test',
            'password' => bcrypt('SecretPass123!'), 'role' => User::ROLE_SUPER_ADMIN,
            'customer_id' => null, 'is_active' => true,
        ]);
    }

    private function customer(): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => 'OpsAdmin '.Str::random(8),
            'slug' => 'opsadmin-'.Str::lower(Str::random(10)),
            'language_id' => $language->id, 'nationality_id' => $nationality->id, 'is_active' => true,
            'subscription_plan' => Customer::PLAN_PRO, 'included_ai_credits' => 10,
        ]);
    }
}
