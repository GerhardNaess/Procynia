<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\CustomerResource\Pages\ManageCustomerBilling;
use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

class CustomerBillingDiscountTest extends TestCase
{
    use RefreshDatabase;
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    public function test_billing_page_shows_ingen_rabatt_when_discount_is_zero(): void
    {
        $admin = $this->internalAdmin();
        $customer = $this->createCustomer();

        $response = $this->actingAs($admin)->get(CustomerResource::getUrl('billing', ['record' => $customer]));

        $response->assertOk();
        $response->assertSee('Kunderabatt');
        $response->assertSee('Ingen rabatt');
    }

    public function test_billing_page_shows_discount_percentage_when_set(): void
    {
        $admin = $this->internalAdmin();
        $customer = $this->createCustomer();
        $customer->forceFill(['billing_discount_percent' => 20.00])->save();

        $response = $this->actingAs($admin)->get(CustomerResource::getUrl('billing', ['record' => $customer]));

        $response->assertOk();
        $response->assertSee('Kunderabatt');
        $response->assertSee('20,00 %');
        $response->assertDontSee('Ingen rabatt');
    }

    public function test_endre_kunderabatt_button_is_visible_on_page(): void
    {
        $admin = $this->internalAdmin();
        $customer = $this->createCustomer();

        $response = $this->actingAs($admin)->get(CustomerResource::getUrl('billing', ['record' => $customer]));

        $response->assertOk();
        $response->assertSee('Endre kunderabatt');
    }

    public function test_kunderabatt_section_shows_percentage_when_set(): void
    {
        $admin = $this->internalAdmin();
        $customer = $this->createCustomer();
        $customer->forceFill(['billing_discount_percent' => 15.50])->save();

        $response = $this->actingAs($admin)->get(CustomerResource::getUrl('billing', ['record' => $customer]));

        $response->assertOk();
        $response->assertSee('15,50 %');
    }

    public function test_discount_can_be_updated_via_edit_action(): void
    {
        $admin = $this->internalAdmin();
        $customer = $this->createCustomer();

        Livewire::actingAs($admin)
            ->test(ManageCustomerBilling::class, ['record' => $customer])
            ->callAction('edit_discount', ['billing_discount_percent' => 25.00])
            ->assertHasNoErrors();

        $customer->refresh();
        $this->assertEquals('25.00', $customer->billing_discount_percent);
    }

    public function test_discount_is_stored_as_decimal_with_two_places(): void
    {
        $admin = $this->internalAdmin();
        $customer = $this->createCustomer();

        Livewire::actingAs($admin)
            ->test(ManageCustomerBilling::class, ['record' => $customer])
            ->callAction('edit_discount', ['billing_discount_percent' => 33.33])
            ->assertHasNoErrors();

        $customer->refresh();
        $this->assertEquals('33.33', $customer->billing_discount_percent);
    }

    public function test_discount_defaults_to_zero(): void
    {
        $customer = $this->createCustomer();

        $this->assertEquals('0.00', $customer->fresh()->billing_discount_percent);
    }

    public function test_discount_accepts_zero(): void
    {
        $admin = $this->internalAdmin();
        $customer = $this->createCustomer();
        $customer->forceFill(['billing_discount_percent' => 20.00])->save();

        Livewire::actingAs($admin)
            ->test(ManageCustomerBilling::class, ['record' => $customer])
            ->callAction('edit_discount', ['billing_discount_percent' => 0])
            ->assertHasNoErrors();

        $customer->refresh();
        $this->assertEquals('0.00', $customer->billing_discount_percent);
    }

    public function test_discount_cannot_exceed_100(): void
    {
        $admin = $this->internalAdmin();
        $customer = $this->createCustomer();
        $customer->forceFill(['billing_discount_percent' => 10.00])->save();

        Livewire::actingAs($admin)
            ->test(ManageCustomerBilling::class, ['record' => $customer])
            ->callAction('edit_discount', ['billing_discount_percent' => 101]);

        // Invalid input should not persist
        $customer->refresh();
        $this->assertLessThanOrEqual(100, (float) $customer->billing_discount_percent);
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

    private function createCustomer(string $name = 'Rabattkunde AS'): Customer
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
