<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\CustomerResource\Pages\CreateCustomer;
use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class CustomerCreatePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_admin_can_create_customer_with_first_system_owner(): void
    {
        $admin = $this->internalAdmin();
        $language = Language::query()->firstOrCreate([
            'code' => 'no',
        ], [
            'name_en' => 'Norwegian',
            'name_no' => 'Norsk',
        ]);
        $nationality = Nationality::query()->firstOrCreate([
            'code' => 'NO',
        ], [
            'name_en' => 'Norwegian',
            'name_no' => 'Norsk',
            'flag_emoji' => 'NO',
        ]);
        $email = 'first.system.owner@example.test';

        Livewire::actingAs($admin)
            ->test(CreateCustomer::class)
            ->set('data.name', 'Ny Kunde AS')
            ->set('data.slug', 'ny-kunde-as')
            ->set('data.language_id', $language->id)
            ->set('data.nationality_id', $nationality->id)
            ->set('data.is_active', true)
            ->set('data.initial_system_owner_name', 'Første Systemeier')
            ->set('data.initial_system_owner_email', $email)
            ->call('create');

        $customer = Customer::query()->where('slug', 'ny-kunde-as')->first();

        $this->assertInstanceOf(Customer::class, $customer);

        $user = User::query()->where('email', $email)->first();

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame($customer->id, $user->customer_id);
        $this->assertSame(User::BID_ROLE_SYSTEM_OWNER, $user->bid_role);
        $this->assertNull($user->bid_manager_scope);
        $this->assertSame(User::ROLE_CUSTOMER_ADMIN, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertNotSame('', (string) $user->password);

        Notification::assertNotified('Kunden ble opprettet');
    }

    private function internalAdmin(): User
    {
        return User::factory()->create([
            'name' => 'Procynia Admin',
            'email' => 'procynia.admin+'.Str::lower(Str::random(6)).'@example.test',
            'role' => User::ROLE_SUPER_ADMIN,
            'customer_id' => null,
            'is_active' => true,
        ]);
    }
}
