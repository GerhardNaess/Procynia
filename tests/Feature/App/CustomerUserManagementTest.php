<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerUserManagementTest extends TestCase
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

        parent::tearDown();
    }

    public function test_customer_admin_can_access_users_index(): void
    {
        $context = $this->customerAdminContext();

        $response = $this->actingAs($context['admin'])->get('/app/users');

        $response->assertOk();
    }

    public function test_customer_admin_can_access_create_user_page(): void
    {
        $context = $this->customerAdminContext();

        $response = $this->actingAs($context['admin'])->get('/app/users/create');

        $response->assertOk();
    }

    public function test_customer_admin_can_access_edit_user_page(): void
    {
        $context = $this->customerAdminContext();
        $target = User::factory()->create([
            'role' => User::ROLE_USER,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($context['admin'])->get("/app/users/{$target->id}/edit");

        $response->assertOk();
    }

    public function test_customer_user_cannot_access_users_index(): void
    {
        $context = $this->customerUserContext();

        $response = $this->actingAs($context['user'])->get('/app/users');

        $response->assertForbidden();
    }

    public function test_customer_user_cannot_access_create_or_store_user_routes(): void
    {
        $context = $this->customerUserContext();

        $this->actingAs($context['user'])->get('/app/users/create')->assertForbidden();
        $this->actingAs($context['user'])->post('/app/users', [
            'name' => 'Skal Ikke Virke',
            'email' => 'skal.ikke.virke@example.test',
            'role' => 'user',
        ])->assertForbidden();
    }

    public function test_customer_user_cannot_access_edit_route(): void
    {
        $context = $this->customerUserContext();
        $target = User::factory()->create([
            'role' => User::ROLE_USER,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
        ]);

        $this->actingAs($context['user'])->get("/app/users/{$target->id}/edit")->assertForbidden();
    }

    public function test_customer_admin_only_sees_users_from_the_same_customer(): void
    {
        $primary = $this->customerAdminContext('Procynia AS');
        $secondary = $this->customerAdminContext('Annen Kunde AS');

        User::factory()->create([
            'name' => 'Synlig Bruker',
            'email' => 'synlig@example.test',
            'role' => User::ROLE_USER,
            'customer_id' => $primary['customer']->id,
            'is_active' => true,
        ]);

        User::factory()->create([
            'name' => 'Skjult Bruker',
            'email' => 'skjult@example.test',
            'role' => User::ROLE_USER,
            'customer_id' => $secondary['customer']->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($primary['admin'])->get('/app/users');

        $response->assertOk();
        $response->assertSee('Synlig Bruker');
        $response->assertDontSee('Skjult Bruker');
    }

    public function test_customer_admin_can_create_a_new_user_for_own_customer(): void
    {
        $context = $this->customerAdminContext();

        $response = $this->actingAs($context['admin'])->post('/app/users', [
            'name' => 'Ny Bruker',
            'email' => 'ny.bruker@example.test',
            'role' => 'admin',
        ]);

        $response->assertRedirect('/app/users');
        $response->assertSessionHas('userCreated');

        $user = User::query()->where('email', 'ny.bruker@example.test')->first();

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame($context['customer']->id, $user->customer_id);
        $this->assertSame(User::ROLE_CUSTOMER_ADMIN, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertNotSame('', (string) $user->password);
        $this->assertNotSame('password', (string) $user->password);
    }

    public function test_customer_admin_cannot_create_a_user_with_another_customer_id_from_payload(): void
    {
        $primary = $this->customerAdminContext('Procynia AS');
        $secondary = $this->customerAdminContext('Annen Kunde AS');

        $response = $this->actingAs($primary['admin'])->post('/app/users', [
            'name' => 'Ny Bruker',
            'email' => 'kunde.avvik@example.test',
            'role' => 'user',
            'customer_id' => $secondary['customer']->id,
        ]);

        $response->assertSessionHasErrors('customer_id');
        $this->assertDatabaseMissing('users', [
            'email' => 'kunde.avvik@example.test',
        ]);
    }

    public function test_customer_admin_cannot_create_superadmin_through_this_flow(): void
    {
        $context = $this->customerAdminContext();

        $response = $this->actingAs($context['admin'])->post('/app/users', [
            'name' => 'Feil Rolle',
            'email' => 'superadmin.forsok@example.test',
            'role' => User::ROLE_SUPER_ADMIN,
        ]);

        $response->assertSessionHasErrors('role');
        $this->assertDatabaseMissing('users', [
            'email' => 'superadmin.forsok@example.test',
        ]);
    }

    public function test_created_user_gets_the_correct_allowed_role(): void
    {
        $context = $this->customerAdminContext();

        $response = $this->actingAs($context['admin'])->post('/app/users', [
            'name' => 'Vanlig Bruker',
            'email' => 'vanlig.bruker@example.test',
            'role' => 'user',
        ]);

        $response->assertRedirect('/app/users');
        $this->assertDatabaseHas('users', [
            'email' => 'vanlig.bruker@example.test',
            'customer_id' => $context['customer']->id,
            'role' => User::ROLE_USER,
        ]);
    }

    public function test_customer_admin_can_change_role_from_user_to_admin(): void
    {
        $context = $this->customerAdminContext();
        $target = User::factory()->create([
            'role' => User::ROLE_USER,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($context['admin'])->put("/app/users/{$target->id}", [
            'name' => 'Oppgradert Bruker',
            'role' => 'admin',
        ]);

        $response->assertRedirect('/app/users');
        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'name' => 'Oppgradert Bruker',
            'role' => User::ROLE_CUSTOMER_ADMIN,
        ]);
    }

    public function test_role_mapping_works_correctly_from_admin_to_user(): void
    {
        $context = $this->customerAdminContext();
        $otherAdmin = User::factory()->create([
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($context['admin'])->put("/app/users/{$otherAdmin->id}", [
            'name' => $otherAdmin->name,
            'role' => 'user',
        ]);

        $response->assertRedirect('/app/users');
        $this->assertDatabaseHas('users', [
            'id' => $otherAdmin->id,
            'role' => User::ROLE_USER,
        ]);
    }

    public function test_customer_admin_cannot_assign_superadmin_via_update(): void
    {
        $context = $this->customerAdminContext();
        $target = User::factory()->create([
            'role' => User::ROLE_USER,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($context['admin'])->put("/app/users/{$target->id}", [
            'name' => $target->name,
            'role' => User::ROLE_SUPER_ADMIN,
        ]);

        $response->assertSessionHasErrors('role');
        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'role' => User::ROLE_USER,
        ]);
    }

    public function test_customer_admin_cannot_edit_user_from_another_customer(): void
    {
        $primary = $this->customerAdminContext('Procynia AS');
        $secondary = $this->customerAdminContext('Annen Kunde AS');
        $target = User::factory()->create([
            'role' => User::ROLE_USER,
            'customer_id' => $secondary['customer']->id,
            'is_active' => true,
        ]);

        $this->actingAs($primary['admin'])->get("/app/users/{$target->id}/edit")->assertNotFound();
        $this->actingAs($primary['admin'])->put("/app/users/{$target->id}", [
            'name' => 'Skal Ikke Virke',
            'role' => 'admin',
        ])->assertNotFound();
        $this->actingAs($primary['admin'])->patch("/app/users/{$target->id}/toggle-active")->assertNotFound();
    }

    public function test_customer_admin_cannot_deactivate_self(): void
    {
        $context = $this->customerAdminContext();

        $response = $this->actingAs($context['admin'])->patch("/app/users/{$context['admin']->id}/toggle-active");

        $response->assertSessionHas('error', 'Du kan ikke deaktivere din egen bruker.');
        $this->assertDatabaseHas('users', [
            'id' => $context['admin']->id,
            'is_active' => true,
        ]);
    }

    public function test_customer_admin_cannot_remove_last_admin(): void
    {
        $context = $this->customerAdminContext();

        $response = $this->actingAs($context['admin'])->put("/app/users/{$context['admin']->id}", [
            'name' => $context['admin']->name,
            'role' => 'user',
        ]);

        $response->assertSessionHasErrors('role');
        $this->assertDatabaseHas('users', [
            'id' => $context['admin']->id,
            'role' => User::ROLE_CUSTOMER_ADMIN,
        ]);
    }

    public function test_customer_admin_can_deactivate_another_user(): void
    {
        $context = $this->customerAdminContext();
        $target = User::factory()->create([
            'role' => User::ROLE_USER,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($context['admin'])->patch("/app/users/{$target->id}/toggle-active");

        $response->assertSessionHas('success', 'Brukeren ble deaktivert.');
        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'is_active' => false,
        ]);
    }

    public function test_customer_admin_can_reactivate_user(): void
    {
        $context = $this->customerAdminContext();
        $target = User::factory()->create([
            'role' => User::ROLE_USER,
            'customer_id' => $context['customer']->id,
            'is_active' => false,
        ]);

        $response = $this->actingAs($context['admin'])->patch("/app/users/{$target->id}/toggle-active");

        $response->assertSessionHas('success', 'Brukeren ble aktivert.');
        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'is_active' => true,
        ]);
    }

    private function customerAdminContext(string $customerName = 'Procynia AS'): array
    {
        $customer = $this->createCustomer($customerName);

        $admin = User::factory()->create([
            'role' => User::ROLE_CUSTOMER_ADMIN,
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
