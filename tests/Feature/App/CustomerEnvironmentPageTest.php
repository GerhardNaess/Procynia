<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\Department;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

class CustomerEnvironmentPageTest extends TestCase
{
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

    public function test_system_owner_can_access_customer_environment_page_with_scoped_data(): void
    {
        $primary = $this->customerAdminContext('Procynia AS');
        $secondary = $this->customerAdminContext('Annen Kunde AS');

        $ownDepartment = $this->createDepartment($primary['customer']->id, 'Salg');
        $foreignDepartment = $this->createDepartment($secondary['customer']->id, 'Fremmed avdeling');

        $visibleUser = User::factory()->create([
            'name' => 'Synlig Bruker',
            'email' => 'synlig.bruker@example.test',
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $primary['customer']->id,
            'department_id' => $ownDepartment->id,
            'is_active' => true,
        ]);
        $visibleUser->departments()->attach($ownDepartment->id);

        $hiddenUser = User::factory()->create([
            'name' => 'Skjult Bruker',
            'email' => 'skjult.bruker@example.test',
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $secondary['customer']->id,
            'department_id' => $foreignDepartment->id,
            'is_active' => true,
        ]);
        $hiddenUser->departments()->attach($foreignDepartment->id);

        $response = $this->actingAs($primary['admin'])->get('/app/customer-environment');

        $response->assertOk();
        $response->assertViewHas('page', function ($page): bool {
            return data_get($page, 'component') === 'App/CustomerEnvironment/Index'
                && collect(data_get($page, 'props.departments', []))->contains(fn (array $department): bool => $department['name'] === 'Salg')
                && ! collect(data_get($page, 'props.departments', []))->contains(fn (array $department): bool => $department['name'] === 'Fremmed avdeling')
                && collect(data_get($page, 'props.users', []))->contains(fn (array $user): bool => $user['name'] === 'Synlig Bruker')
                && ! collect(data_get($page, 'props.users', []))->contains(fn (array $user): bool => $user['name'] === 'Skjult Bruker');
        });
    }

    /**
     * The page is gated on canManageCustomerUsers(), and since "Add configurable role permissions
     * and bid status improvements" (52f8b32) create_users defaults to
     * ['system_owner', 'bid_manager', 'contributor'] — so a contributor reaches it. What a
     * contributor still cannot do is manage departments: that is a separate permission
     * (create_departments, ['system_owner']), asserted here so the read access above cannot be
     * mistaken for full environment administration.
     */
    public function test_contributor_can_access_customer_environment_page_without_department_management(): void
    {
        $context = $this->customerUserContext();

        $this->actingAs($context['user'])->get('/app/customer-environment')->assertOk();

        $this->actingAs($context['user'])->get('/app/departments/create')->assertForbidden();
    }

    public function test_customer_environment_navigation_respects_customer_management_role(): void
    {
        $systemOwner = $this->customerAdminContext();
        $bidManagerCustomer = $this->createCustomer('Bid Manager Kunde AS');
        $bidManager = $this->departmentScopedBidManager($bidManagerCustomer, []);
        $contributor = $this->customerUserContext('Bidragsyter AS');

        $this->actingAs($systemOwner['admin'])
            ->get('/app/dashboard')
            ->assertViewHas('page', fn ($page): bool => (bool) data_get($page, 'props.auth.user.can_manage_customer_users')
                && (bool) data_get($page, 'props.auth.user.can_manage_customer_departments'));

        $this->actingAs($bidManager)
            ->get('/app/dashboard')
            ->assertViewHas('page', fn ($page): bool => (bool) data_get($page, 'props.auth.user.can_manage_customer_users')
                && ! data_get($page, 'props.auth.user.can_manage_customer_departments'));

        // A contributor has create_users by default, so the nav flag is true — and it agrees with
        // the backend gate, which is the property that matters: page access and nav visibility read
        // the same capability rather than drifting apart. Department management stays false, the
        // same split the bid manager above shows.
        $this->actingAs($contributor['user'])
            ->get('/app/dashboard')
            ->assertViewHas('page', fn ($page): bool => (bool) data_get($page, 'props.auth.user.can_manage_customer_users')
                && ! data_get($page, 'props.auth.user.can_manage_customer_departments'));
    }

    public function test_department_scoped_bid_manager_only_sees_scoped_environment_data(): void
    {
        $customer = $this->createCustomer('Procynia AS');
        $sales = $this->createDepartment($customer->id, 'Salg');
        $delivery = $this->createDepartment($customer->id, 'Leveranse');
        $manager = $this->departmentScopedBidManager($customer, [$sales->id]);

        $visibleUser = User::factory()->create([
            'name' => 'Synlig Bruker',
            'email' => 'scoped.visible@example.test',
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'department_id' => $sales->id,
            'is_active' => true,
        ]);
        $visibleUser->departments()->attach($sales->id);

        $hiddenUser = User::factory()->create([
            'name' => 'Skjult Bruker',
            'email' => 'scoped.hidden@example.test',
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'department_id' => $delivery->id,
            'is_active' => true,
        ]);
        $hiddenUser->departments()->attach($delivery->id);

        $response = $this->actingAs($manager)->get('/app/customer-environment');

        $response->assertOk();
        $response->assertViewHas('page', function ($page): bool {
            return data_get($page, 'component') === 'App/CustomerEnvironment/Index'
                && ! data_get($page, 'props.canCreateDepartments')
                && collect(data_get($page, 'props.departments', []))->contains(fn (array $department): bool => $department['name'] === 'Salg')
                && ! collect(data_get($page, 'props.departments', []))->contains(fn (array $department): bool => $department['name'] === 'Leveranse')
                && collect(data_get($page, 'props.users', []))->contains(fn (array $user): bool => $user['name'] === 'Synlig Bruker')
                && ! collect(data_get($page, 'props.users', []))->contains(fn (array $user): bool => $user['name'] === 'Skjult Bruker');
        });
    }

    private function customerAdminContext(string $customerName = 'Procynia AS'): array
    {
        $customer = $this->createCustomer($customerName);

        $admin = User::factory()->create([
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'bid_manager_scope' => null,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        return [
            'customer' => $customer,
            'admin' => $admin,
        ];
    }

    private function customerUserContext(string $customerName = 'Procynia AS'): array
    {
        $customer = $this->createCustomer($customerName);

        $user = User::factory()->create([
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        return [
            'customer' => $customer,
            'user' => $user,
        ];
    }

    private function departmentScopedBidManager(Customer $customer, array $managedDepartmentIds): User
    {
        $user = User::factory()->create([
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_BID_MANAGER,
            'bid_manager_scope' => User::BID_MANAGER_SCOPE_DEPARTMENTS,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        $user->managedDepartments()->sync($managedDepartmentIds);

        return $user;
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
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);
    }

    private function createDepartment(int $customerId, string $name): Department
    {
        return Department::query()->create([
            'customer_id' => $customerId,
            'name' => $name,
            'description' => null,
            'is_active' => true,
        ]);
    }
}
