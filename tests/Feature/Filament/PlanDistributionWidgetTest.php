<?php

namespace Tests\Feature\Filament;

use App\Filament\Widgets\PlanDistributionWidget;
use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class PlanDistributionWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_distribution_widget_renders_grouped_customer_counts(): void
    {
        $admin = $this->internalAdmin();

        $this->createCustomer('Kunde Pro 1', Customer::PLAN_PRO, Customer::BILLING_MONTHLY);
        $this->createCustomer('Kunde Pro 2', Customer::PLAN_PRO, Customer::BILLING_MONTHLY);
        $this->createCustomer('Kunde Enterprise', Customer::PLAN_ENTERPRISE, Customer::BILLING_YEARLY);

        Livewire::actingAs($admin)
            ->test(PlanDistributionWidget::class)
            ->assertSee('Planfordeling')
            ->assertSee('Pro')
            ->assertSee('Enterprise')
            ->assertSee('Månedlig')
            ->assertSee('Årlig')
            ->assertSee('Kunder');
    }

    private function internalAdmin(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'customer_id' => null,
            'is_active' => true,
        ]);
    }

    private function createCustomer(string $name, string $plan, string $billingInterval): Customer
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
            'subscription_plan' => $plan,
            'billing_interval' => $billingInterval,
            'included_users' => 1,
            'included_ai_credits' => 0,
            'is_active' => true,
        ]);
    }
}
