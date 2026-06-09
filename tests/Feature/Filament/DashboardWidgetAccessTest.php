<?php

namespace Tests\Feature\Filament;

use App\Filament\Widgets\MrrWidget;
use App\Filament\Widgets\PlanDistributionWidget;
use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardWidgetAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_admin_can_view_global_dashboard_widgets(): void
    {
        $admin = $this->internalAdmin();

        $this->actingAs($admin);

        $this->assertTrue(MrrWidget::canView());
        $this->assertTrue(PlanDistributionWidget::canView());
    }

    public function test_customer_admin_cannot_view_global_dashboard_widgets(): void
    {
        $customerAdmin = $this->customerAdmin();

        $this->actingAs($customerAdmin);

        $this->assertFalse(MrrWidget::canView());
        $this->assertFalse(PlanDistributionWidget::canView());
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

    private function customerAdmin(): User
    {
        $customer = $this->createCustomer();

        return User::query()->create([
            'name' => 'Kunde Admin',
            'email' => 'kunde.admin+'.Str::lower(Str::random(8)).'@example.test',
            'password' => bcrypt('SecretPass123!'),
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function createCustomer(string $name = 'Procynia AS'): Customer
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
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);
    }
}
