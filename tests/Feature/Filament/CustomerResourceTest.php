<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\CustomerResource\Pages\CreateCustomer;
use App\Filament\Resources\CustomerResource\Pages\EditCustomer;
use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class CustomerResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_create_form_does_not_expose_billing_discount_percent(): void
    {
        $admin = $this->internalAdmin();

        Livewire::actingAs($admin)
            ->test(CreateCustomer::class)
            ->assertFormFieldDoesNotExist('billing_discount_percent');
    }

    public function test_customer_edit_form_does_not_expose_billing_discount_percent(): void
    {
        $admin = $this->internalAdmin();
        $customer = $this->createCustomer();

        Livewire::actingAs($admin)
            ->test(EditCustomer::class, ['record' => $customer->getKey()])
            ->assertFormFieldDoesNotExist('billing_discount_percent');
    }

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

    private function createCustomer(string $name = 'Kundeskjema AS'): Customer
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
}
