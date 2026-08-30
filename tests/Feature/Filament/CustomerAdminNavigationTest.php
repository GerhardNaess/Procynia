<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\UserResource;
use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerAdminNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_resource_belongs_to_kunder_navigation_group(): void
    {
        $this->assertSame('Kunder', CustomerResource::getNavigationGroup());
    }

    public function test_user_resource_belongs_to_kunder_navigation_group(): void
    {
        $this->assertSame('Kunder', UserResource::getNavigationGroup());
    }

    public function test_customer_resource_sorts_before_user_resource(): void
    {
        $this->assertLessThan(
            UserResource::getNavigationSort(),
            CustomerResource::getNavigationSort(),
        );
    }

    public function test_customer_resource_access_requires_internal_admin(): void
    {
        $admin = $this->internalAdmin();
        $this->actingAs($admin);
        $this->assertTrue(CustomerResource::canAccess());

        $customerAdmin = $this->customerAdmin();
        $this->actingAs($customerAdmin);
        $this->assertFalse(CustomerResource::canAccess());
    }

    /**
     * Filament is the internal administration surface.
     *
     * This test previously asserted that a customer administrator could reach UserResource, back when
     * Filament exposed a second, parallel user-administration path for them. That path was removed as
     * a deliberate product decision: a customer administrator is a customer-scoped role, not an
     * internal system administrator.
     *
     * The expectation is inverted rather than deleted, and the replacement below proves the capability
     * did not disappear — it moved to the customer frontend, where it is tenant-scoped by construction.
     */
    public function test_user_resource_access_requires_internal_admin(): void
    {
        $admin = $this->internalAdmin();
        $this->actingAs($admin);
        $this->assertTrue(UserResource::canAccess());

        $customerAdmin = $this->customerAdmin();
        $this->actingAs($customerAdmin);
        $this->assertFalse(
            UserResource::canAccess(),
            'Customer administrators no longer administer users through Filament.',
        );

        $regularUser = $this->regularUser();
        $this->actingAs($regularUser);
        $this->assertFalse(UserResource::canAccess());
    }

    /**
     * The other half of the same decision: what a customer administrator lost in Filament, they still
     * have — and always had — in the customer frontend.
     */
    public function test_a_customer_admin_administers_users_in_the_customer_frontend_instead(): void
    {
        $customerAdmin = $this->customerAdmin();

        $this->actingAs($customerAdmin)
            ->get(route('app.users.index'))
            ->assertOk();

        $this->actingAs($customerAdmin)
            ->get(route('app.users.create'))
            ->assertOk();

        // And that surface is closed to Filament, so there is exactly one path.
        $this->actingAs($customerAdmin)
            ->get(route('filament.admin.resources.users.index'))
            ->assertForbidden();
    }

    public function test_customer_list_shows_aktiv_not_yes_for_active_customer(): void
    {
        $admin = $this->internalAdmin();
        $this->createCustomer('Aktiv Kunde AS', true);

        $response = $this->actingAs($admin)->get(CustomerResource::getUrl('index'));

        $response->assertOk();
        $response->assertSee('Aktiv');
        $response->assertDontSee('>Yes<', false);
    }

    public function test_customer_list_shows_inaktiv_not_no_for_inactive_customer(): void
    {
        $admin = $this->internalAdmin();
        $this->createCustomer('Inaktiv Kunde AS', false);

        $response = $this->actingAs($admin)->get(CustomerResource::getUrl('index'));

        $response->assertOk();
        $response->assertSee('Inaktiv');
        $response->assertDontSee('>No<', false);
    }

    public function test_user_list_shows_aktiv_not_yes_for_active_user(): void
    {
        $admin = $this->internalAdmin();
        $customer = $this->createCustomer();
        User::query()->create([
            'name' => 'Aktiv Testbruker',
            'email' => 'aktiv.test+'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('pass'),
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->get(UserResource::getUrl('index'));

        $response->assertOk();
        $response->assertSee('Aktiv');
        $response->assertDontSee('>Yes<', false);
    }

    public function test_user_list_shows_inaktiv_not_no_for_inactive_user(): void
    {
        $admin = $this->internalAdmin();
        $customer = $this->createCustomer();
        User::query()->create([
            'name' => 'Inaktiv Testbruker',
            'email' => 'inaktiv.test+'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('pass'),
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'customer_id' => $customer->id,
            'is_active' => false,
        ]);

        $response = $this->actingAs($admin)->get(UserResource::getUrl('index'));

        $response->assertOk();
        $response->assertSee('Inaktiv');
        $response->assertDontSee('>No<', false);
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
        $customer = $this->createCustomer('Customer Admin Co');

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

    private function regularUser(): User
    {
        $customer = $this->createCustomer('Regular User Co');

        return User::query()->create([
            'name' => 'Vanlig Bruker',
            'email' => 'vanlig.bruker+'.Str::lower(Str::random(8)).'@example.test',
            'password' => bcrypt('SecretPass123!'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function createCustomer(string $name = 'Test Kunde AS', bool $isActive = true): Customer
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
            'is_active' => $isActive,
        ]);
    }
}
