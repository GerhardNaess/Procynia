<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\Department;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerEnvironmentPageTest extends TestCase
{
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

    public function test_contributor_cannot_access_customer_environment_page(): void
    {
        $context = $this->customerUserContext();

        $this->actingAs($context['user'])->get('/app/customer-environment')->assertForbidden();
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

        $this->actingAs($contributor['user'])
            ->get('/app/dashboard')
            ->assertViewHas('page', fn ($page): bool => ! data_get($page, 'props.auth.user.can_manage_customer_users'));
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

    private function useProjectPostgresConnection(): void
    {
        $connectionName = 'feature_pgsql';

        config([
            "database.connections.{$connectionName}" => [
                'driver' => 'pgsql',
                'host' => $this->projectEnv('DB_HOST', '127.0.0.1'),
                'port' => $this->projectEnv('DB_PORT', '5432'),
                'database' => $this->projectEnv('DB_DATABASE', 'procynia'),
                'username' => $this->projectEnv('DB_USERNAME', 'gehard'),
                'password' => $this->projectEnv('DB_PASSWORD', ''),
                'charset' => 'utf8',
                'prefix' => '',
                'prefix_indexes' => true,
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ],
            'database.default' => $connectionName,
        ]);

        DB::purge($connectionName);
        DB::setDefaultConnection($connectionName);
        DB::reconnect($connectionName);
    }

    private function projectEnv(string $key, string $default): string
    {
        static $values = null;

        if (! is_array($values)) {
            $values = [];

            foreach (file(base_path('.env'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $trimmed = trim($line);

                if ($trimmed === '' || str_starts_with($trimmed, '#') || ! str_contains($trimmed, '=')) {
                    continue;
                }

                [$envKey, $envValue] = explode('=', $trimmed, 2);
                $values[trim($envKey)] = trim($envValue, " \t\n\r\0\x0B\"'");
            }
        }

        return (string) ($values[$key] ?? $default);
    }
}
