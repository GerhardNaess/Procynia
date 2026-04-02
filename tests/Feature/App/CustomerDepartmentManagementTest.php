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

class CustomerDepartmentManagementTest extends TestCase
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

    public function test_customer_admin_can_access_departments_index(): void
    {
        $context = $this->customerAdminContext();

        $this->actingAs($context['admin'])->get('/app/departments')->assertOk();
    }

    public function test_customer_admin_can_access_create_and_edit_department_pages(): void
    {
        $context = $this->customerAdminContext();
        $department = Department::query()->create([
            'customer_id' => $context['customer']->id,
            'name' => 'Maritime',
            'description' => 'Struktur',
            'is_active' => true,
        ]);

        $this->actingAs($context['admin'])->get('/app/departments/create')->assertOk();
        $this->actingAs($context['admin'])->get("/app/departments/{$department->id}/edit")->assertOk();
    }

    public function test_customer_user_cannot_access_departments_index(): void
    {
        $context = $this->customerUserContext();

        $this->actingAs($context['user'])->get('/app/departments')->assertForbidden();
    }

    public function test_customer_user_cannot_manage_department_routes(): void
    {
        $context = $this->customerUserContext();
        $department = Department::query()->create([
            'customer_id' => $context['customer']->id,
            'name' => 'Healthcare',
            'description' => 'Struktur',
            'is_active' => true,
        ]);

        $this->actingAs($context['user'])->get('/app/departments/create')->assertForbidden();
        $this->actingAs($context['user'])->post('/app/departments', [
            'name' => 'IT',
            'description' => 'Skal ikke virke',
        ])->assertForbidden();
        $this->actingAs($context['user'])->get("/app/departments/{$department->id}/edit")->assertForbidden();
        $this->actingAs($context['user'])->put("/app/departments/{$department->id}", [
            'name' => 'Skal Ikke Virke',
            'description' => 'Nei',
        ])->assertForbidden();
        $this->actingAs($context['user'])->patch("/app/departments/{$department->id}/toggle-active")->assertForbidden();
    }

    public function test_customer_admin_only_sees_departments_from_own_customer(): void
    {
        $primary = $this->customerAdminContext('Procynia AS');
        $secondary = $this->customerAdminContext('Annen Kunde AS');

        Department::query()->create([
            'customer_id' => $primary['customer']->id,
            'name' => 'Maritime',
            'description' => 'Synlig',
            'is_active' => true,
        ]);

        Department::query()->create([
            'customer_id' => $secondary['customer']->id,
            'name' => 'Healthcare',
            'description' => 'Skjult',
            'is_active' => true,
        ]);

        $response = $this->actingAs($primary['admin'])->get('/app/departments');

        $response->assertOk();
        $response->assertSee('Maritime');
        $response->assertDontSee('Healthcare');
    }

    public function test_customer_admin_can_create_department_for_own_customer(): void
    {
        $context = $this->customerAdminContext();

        $response = $this->actingAs($context['admin'])->post('/app/departments', [
            'name' => 'IT',
            'description' => 'Teknologirelatert struktur',
        ]);

        $response->assertRedirect('/app/departments');
        $this->assertDatabaseHas('departments', [
            'customer_id' => $context['customer']->id,
            'name' => 'IT',
            'description' => 'Teknologirelatert struktur',
            'is_active' => true,
        ]);
    }

    public function test_request_payload_cannot_override_customer_id(): void
    {
        $primary = $this->customerAdminContext('Procynia AS');
        $secondary = $this->customerAdminContext('Annen Kunde AS');

        $response = $this->actingAs($primary['admin'])->post('/app/departments', [
            'name' => 'Construction',
            'description' => 'Bygg',
            'customer_id' => $secondary['customer']->id,
        ]);

        $response->assertSessionHasErrors('customer_id');
        $this->assertDatabaseMissing('departments', [
            'name' => 'Construction',
            'customer_id' => $secondary['customer']->id,
        ]);
    }

    public function test_customer_admin_can_edit_department_in_own_customer(): void
    {
        $context = $this->customerAdminContext();
        $department = Department::query()->create([
            'customer_id' => $context['customer']->id,
            'name' => 'Maritime',
            'description' => null,
            'is_active' => true,
        ]);

        $response = $this->actingAs($context['admin'])->put("/app/departments/{$department->id}", [
            'name' => 'Maritime Ops',
            'description' => 'Struktur for maritime profiler',
        ]);

        $response->assertRedirect('/app/departments');
        $this->assertDatabaseHas('departments', [
            'id' => $department->id,
            'name' => 'Maritime Ops',
            'description' => 'Struktur for maritime profiler',
        ]);
    }

    public function test_customer_admin_cannot_edit_another_customers_department(): void
    {
        $primary = $this->customerAdminContext('Procynia AS');
        $secondary = $this->customerAdminContext('Annen Kunde AS');
        $department = Department::query()->create([
            'customer_id' => $secondary['customer']->id,
            'name' => 'Healthcare',
            'description' => null,
            'is_active' => true,
        ]);

        $this->actingAs($primary['admin'])->get("/app/departments/{$department->id}/edit")->assertNotFound();
        $this->actingAs($primary['admin'])->put("/app/departments/{$department->id}", [
            'name' => 'Skal Ikke Virke',
            'description' => null,
        ])->assertNotFound();
    }

    public function test_customer_admin_can_deactivate_department(): void
    {
        $context = $this->customerAdminContext();
        $department = Department::query()->create([
            'customer_id' => $context['customer']->id,
            'name' => 'Construction',
            'description' => null,
            'is_active' => true,
        ]);

        $response = $this->actingAs($context['admin'])->patch("/app/departments/{$department->id}/toggle-active");

        $response->assertSessionHas('success', 'Avdelingen ble deaktivert.');
        $this->assertDatabaseHas('departments', [
            'id' => $department->id,
            'is_active' => false,
        ]);
    }

    public function test_deactivating_department_keeps_existing_user_memberships(): void
    {
        $context = $this->customerAdminContext();
        $department = Department::query()->create([
            'customer_id' => $context['customer']->id,
            'name' => 'Historikk',
            'description' => null,
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $context['customer']->id,
            'department_id' => $department->id,
            'is_active' => true,
        ]);

        $department->members()->attach($user->id);

        $response = $this->actingAs($context['admin'])->patch("/app/departments/{$department->id}/toggle-active");

        $response->assertSessionHas('success', 'Avdelingen ble deaktivert.');
        $this->assertDatabaseHas('department_user', [
            'department_id' => $department->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_customer_admin_can_reactivate_department(): void
    {
        $context = $this->customerAdminContext();
        $department = Department::query()->create([
            'customer_id' => $context['customer']->id,
            'name' => 'Construction',
            'description' => null,
            'is_active' => false,
        ]);

        $response = $this->actingAs($context['admin'])->patch("/app/departments/{$department->id}/toggle-active");

        $response->assertSessionHas('success', 'Avdelingen ble aktivert.');
        $this->assertDatabaseHas('departments', [
            'id' => $department->id,
            'is_active' => true,
        ]);
    }

    public function test_duplicate_department_name_within_same_customer_is_rejected(): void
    {
        $context = $this->customerAdminContext();

        Department::query()->create([
            'customer_id' => $context['customer']->id,
            'name' => 'IT',
            'description' => null,
            'is_active' => true,
        ]);

        $response = $this->actingAs($context['admin'])->post('/app/departments', [
            'name' => 'it',
            'description' => 'Duplikat',
        ]);

        $response->assertSessionHasErrors('name');
    }

    public function test_same_name_across_different_customers_is_allowed(): void
    {
        $primary = $this->customerAdminContext('Procynia AS');
        $secondary = $this->customerAdminContext('Annen Kunde AS');

        Department::query()->create([
            'customer_id' => $primary['customer']->id,
            'name' => 'IT',
            'description' => null,
            'is_active' => true,
        ]);

        $response = $this->actingAs($secondary['admin'])->post('/app/departments', [
            'name' => 'IT',
            'description' => 'Tillatt i annen kunde',
        ]);

        $response->assertRedirect('/app/departments');
        $this->assertDatabaseHas('departments', [
            'customer_id' => $secondary['customer']->id,
            'name' => 'IT',
        ]);
    }

    public function test_department_scoped_bid_manager_cannot_create_departments(): void
    {
        $customer = $this->createCustomer('Procynia AS');
        $sales = $this->createDepartment($customer->id, 'Salg');
        $manager = $this->departmentScopedBidManager($customer, [$sales->id]);

        $this->actingAs($manager)->get('/app/departments/create')->assertForbidden();
        $this->actingAs($manager)->post('/app/departments', [
            'name' => 'Ny avdeling',
            'description' => 'Skal ikke være tillatt',
        ])->assertForbidden();
    }

    public function test_company_wide_bid_manager_cannot_create_or_edit_departments(): void
    {
        $customer = $this->createCustomer('Procynia AS');
        $department = $this->createDepartment($customer->id, 'Salg');
        $manager = User::factory()->create([
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_BID_MANAGER,
            'bid_manager_scope' => User::BID_MANAGER_SCOPE_COMPANY,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        $this->actingAs($manager)->get('/app/departments/create')->assertForbidden();
        $this->actingAs($manager)->get("/app/departments/{$department->id}/edit")->assertForbidden();
        $this->actingAs($manager)->patch("/app/departments/{$department->id}/toggle-active")->assertForbidden();
    }

    public function test_department_scoped_bid_manager_only_sees_scoped_departments_but_cannot_manage_department_structure(): void
    {
        $customer = $this->createCustomer('Procynia AS');
        $sales = $this->createDepartment($customer->id, 'Salg');
        $delivery = $this->createDepartment($customer->id, 'Leveranse');
        $manager = $this->departmentScopedBidManager($customer, [$sales->id]);

        $response = $this->actingAs($manager)->get('/app/departments');

        $response->assertOk();
        $response->assertSee('Salg');
        $response->assertDontSee('Leveranse');

        $this->actingAs($manager)->get("/app/departments/{$sales->id}/edit")->assertForbidden();
        $this->actingAs($manager)->get("/app/departments/{$delivery->id}/edit")->assertForbidden();
        $this->actingAs($manager)->patch("/app/departments/{$delivery->id}/toggle-active")->assertForbidden();
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
