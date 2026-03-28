<?php

namespace Tests\Feature\App;

use App\Models\CpvCode;
use App\Models\Customer;
use App\Models\Department;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Models\WatchProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerWatchProfileManagementTest extends TestCase
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

    public function test_customer_admin_can_access_watch_profiles_index_create_and_edit_pages(): void
    {
        $context = $this->customerAdminContext();
        $department = $this->createDepartment($context['customer']->id, 'IT');
        $profile = $this->createWatchProfile($context['customer']->id, $department->id, 'Maritime Core');

        $this->actingAs($context['admin'])->get('/app/watch-profiles')->assertOk();
        $this->actingAs($context['admin'])->get('/app/watch-profiles/create')->assertOk();
        $this->actingAs($context['admin'])->get("/app/watch-profiles/{$profile->id}/edit")->assertOk();
    }

    public function test_customer_user_cannot_manage_watch_profiles_routes(): void
    {
        $context = $this->customerUserContext();
        $profile = $this->createWatchProfile($context['customer']->id, null, 'Restricted Profile');

        $this->actingAs($context['user'])->get('/app/watch-profiles')->assertForbidden();
        $this->actingAs($context['user'])->get('/app/watch-profiles/create')->assertForbidden();
        $this->actingAs($context['user'])->post('/app/watch-profiles', [
            'name' => 'Skal Ikke Virke',
            'description' => null,
            'is_active' => true,
            'department_id' => null,
            'keywords' => '',
            'cpv_codes' => [],
        ])->assertForbidden();
        $this->actingAs($context['user'])->get("/app/watch-profiles/{$profile->id}/edit")->assertForbidden();
        $this->actingAs($context['user'])->put("/app/watch-profiles/{$profile->id}", [
            'name' => 'Skal Ikke Virke',
            'description' => null,
            'is_active' => true,
            'department_id' => null,
            'keywords' => '',
            'cpv_codes' => [],
        ])->assertForbidden();
        $this->actingAs($context['user'])->patch("/app/watch-profiles/{$profile->id}/toggle-active")->assertForbidden();
        $this->actingAs($context['user'])->delete("/app/watch-profiles/{$profile->id}")->assertForbidden();
    }

    public function test_customer_only_sees_own_watch_profiles(): void
    {
        $primary = $this->customerAdminContext('Procynia AS');
        $secondary = $this->customerAdminContext('Annen Kunde AS');

        $this->createWatchProfile($primary['customer']->id, null, 'Procynia Maritime');
        $this->createWatchProfile($secondary['customer']->id, null, 'Skjult Profil');

        $response = $this->actingAs($primary['admin'])->get('/app/watch-profiles');

        $response->assertOk();
        $response->assertSee('Procynia Maritime');
        $response->assertDontSee('Skjult Profil');
    }

    public function test_customer_admin_can_create_watch_profile_for_own_customer(): void
    {
        $context = $this->customerAdminContext();
        $department = $this->createDepartment($context['customer']->id, 'IT');
        $this->seedCpvCodes(['72000000', '48000000']);

        $response = $this->actingAs($context['admin'])->post('/app/watch-profiles', [
            'name' => 'Maritime Ops',
            'description' => 'Profil for maritime anbud',
            'is_active' => true,
            'department_id' => $department->id,
            'keywords' => "ferge\nhavn\nferge",
            'cpv_codes' => [
                ['cpv_code' => '72000000', 'weight' => 10],
                ['cpv_code' => '48000000', 'weight' => 25],
            ],
        ]);

        $response->assertRedirect('/app/watch-profiles');

        $profile = WatchProfile::query()
            ->where('customer_id', $context['customer']->id)
            ->where('name', 'Maritime Ops')
            ->with('cpvCodes')
            ->first();

        $this->assertInstanceOf(WatchProfile::class, $profile);
        $this->assertSame($context['customer']->id, $profile->customer_id);
        $this->assertSame($department->id, $profile->department_id);
        $this->assertSame(['ferge', 'havn'], $profile->keywords);
        $this->assertTrue($profile->is_active);
        $this->assertCount(2, $profile->cpvCodes);
        $this->assertDatabaseHas('watch_profile_cpv_codes', [
            'watch_profile_id' => $profile->id,
            'cpv_code' => '72000000',
            'weight' => 10,
        ]);
    }

    public function test_customer_admin_cannot_override_customer_id_or_foreign_department_id(): void
    {
        $primary = $this->customerAdminContext('Procynia AS');
        $secondary = $this->customerAdminContext('Annen Kunde AS');
        $foreignDepartment = $this->createDepartment($secondary['customer']->id, 'Foreign');
        $this->seedCpvCodes(['72000000']);

        $response = $this->actingAs($primary['admin'])->post('/app/watch-profiles', [
            'name' => 'Invalid',
            'description' => null,
            'is_active' => true,
            'customer_id' => $secondary['customer']->id,
            'department_id' => $foreignDepartment->id,
            'keywords' => "keyword",
            'cpv_codes' => [
                ['cpv_code' => '72000000', 'weight' => 10],
            ],
        ]);

        $response->assertSessionHasErrors(['customer_id', 'department_id']);
        $this->assertDatabaseMissing('watch_profiles', [
            'name' => 'Invalid',
        ]);
    }

    public function test_customer_admin_can_edit_watch_profile_and_sync_cpv_rules_cleanly(): void
    {
        $context = $this->customerAdminContext();
        $department = $this->createDepartment($context['customer']->id, 'IT');
        $this->seedCpvCodes(['72000000', '48000000', '72200000']);

        $profile = $this->createWatchProfile($context['customer']->id, null, 'Original Name', ['old'], true, [
            ['cpv_code' => '72000000', 'weight' => 10],
            ['cpv_code' => '48000000', 'weight' => 20],
        ]);

        $response = $this->actingAs($context['admin'])->put("/app/watch-profiles/{$profile->id}", [
            'name' => 'Updated Name',
            'description' => 'Oppdatert beskrivelse',
            'is_active' => false,
            'department_id' => $department->id,
            'keywords' => "ferge\nhavn",
            'cpv_codes' => [
                ['cpv_code' => '72200000', 'weight' => 30],
            ],
        ]);

        $response->assertRedirect('/app/watch-profiles');

        $profile->refresh();
        $profile->load('cpvCodes');

        $this->assertSame('Updated Name', $profile->name);
        $this->assertSame('Oppdatert beskrivelse', $profile->description);
        $this->assertFalse($profile->is_active);
        $this->assertSame($department->id, $profile->department_id);
        $this->assertSame(['ferge', 'havn'], $profile->keywords);
        $this->assertCount(1, $profile->cpvCodes);
        $this->assertDatabaseHas('watch_profile_cpv_codes', [
            'watch_profile_id' => $profile->id,
            'cpv_code' => '72200000',
            'weight' => 30,
        ]);
        $this->assertDatabaseMissing('watch_profile_cpv_codes', [
            'watch_profile_id' => $profile->id,
            'cpv_code' => '72000000',
        ]);
        $this->assertDatabaseMissing('watch_profile_cpv_codes', [
            'watch_profile_id' => $profile->id,
            'cpv_code' => '48000000',
        ]);

        $editResponse = $this->actingAs($context['admin'])->get("/app/watch-profiles/{$profile->id}/edit");
        $editResponse->assertSee('ferge');
        $editResponse->assertSee('havn');
        $editResponse->assertSee('72200000');
    }

    public function test_customer_admin_cannot_access_another_customers_watch_profile(): void
    {
        $primary = $this->customerAdminContext('Procynia AS');
        $secondary = $this->customerAdminContext('Annen Kunde AS');
        $profile = $this->createWatchProfile($secondary['customer']->id, null, 'Foreign Profile');

        $this->actingAs($primary['admin'])->get("/app/watch-profiles/{$profile->id}/edit")->assertNotFound();
        $this->actingAs($primary['admin'])->put("/app/watch-profiles/{$profile->id}", [
            'name' => 'Skal Ikke Virke',
            'description' => null,
            'is_active' => true,
            'department_id' => null,
            'keywords' => '',
            'cpv_codes' => [],
        ])->assertNotFound();
        $this->actingAs($primary['admin'])->patch("/app/watch-profiles/{$profile->id}/toggle-active")->assertNotFound();
        $this->actingAs($primary['admin'])->delete("/app/watch-profiles/{$profile->id}")->assertNotFound();
    }

    public function test_customer_admin_can_toggle_active_state(): void
    {
        $context = $this->customerAdminContext();
        $profile = $this->createWatchProfile($context['customer']->id, null, 'Toggle Profile');

        $this->actingAs($context['admin'])->patch("/app/watch-profiles/{$profile->id}/toggle-active")
            ->assertSessionHas('success', 'Watch Profile ble deaktivert.');

        $this->assertDatabaseHas('watch_profiles', [
            'id' => $profile->id,
            'is_active' => false,
        ]);

        $this->actingAs($context['admin'])->patch("/app/watch-profiles/{$profile->id}/toggle-active")
            ->assertSessionHas('success', 'Watch Profile ble aktivert.');

        $this->assertDatabaseHas('watch_profiles', [
            'id' => $profile->id,
            'is_active' => true,
        ]);
    }

    public function test_customer_admin_can_delete_watch_profile_and_cascade_cpv_rules(): void
    {
        $context = $this->customerAdminContext();
        $this->seedCpvCodes(['72000000']);
        $profile = $this->createWatchProfile($context['customer']->id, null, 'Delete Me', ['keyword'], true, [
            ['cpv_code' => '72000000', 'weight' => 10],
        ]);

        $response = $this->actingAs($context['admin'])->delete("/app/watch-profiles/{$profile->id}");

        $response->assertRedirect('/app/watch-profiles');
        $this->assertDatabaseMissing('watch_profiles', [
            'id' => $profile->id,
        ]);
        $this->assertDatabaseMissing('watch_profile_cpv_codes', [
            'watch_profile_id' => $profile->id,
        ]);
    }

    public function test_listing_shows_expected_watch_profile_values(): void
    {
        $context = $this->customerAdminContext();
        $department = $this->createDepartment($context['customer']->id, 'Maritime');
        $this->seedCpvCodes(['72000000', '48000000']);

        $this->createWatchProfile($context['customer']->id, $department->id, 'Listing Profile', ['ferge', 'havn'], true, [
            ['cpv_code' => '72000000', 'weight' => 10],
            ['cpv_code' => '48000000', 'weight' => 20],
        ]);

        $response = $this->actingAs($context['admin'])->get('/app/watch-profiles');

        $response->assertOk();
        $response->assertSee('Listing Profile');
        $response->assertSee('Maritime');
        $response->assertSee('"cpv_rule_count":2', false);
        $response->assertSee('"keyword_count":2', false);
    }

    public function test_duplicate_name_within_same_customer_is_rejected_but_allowed_across_customers(): void
    {
        $primary = $this->customerAdminContext('Procynia AS');
        $secondary = $this->customerAdminContext('Annen Kunde AS');

        $this->createWatchProfile($primary['customer']->id, null, 'Shared Name');

        $this->actingAs($primary['admin'])->post('/app/watch-profiles', [
            'name' => 'shared name',
            'description' => null,
            'is_active' => true,
            'department_id' => null,
            'keywords' => '',
            'cpv_codes' => [],
        ])->assertSessionHasErrors('name');

        $this->actingAs($secondary['admin'])->post('/app/watch-profiles', [
            'name' => 'Shared Name',
            'description' => null,
            'is_active' => true,
            'department_id' => null,
            'keywords' => '',
            'cpv_codes' => [],
        ])->assertRedirect('/app/watch-profiles');

        $this->assertDatabaseHas('watch_profiles', [
            'customer_id' => $secondary['customer']->id,
            'name' => 'Shared Name',
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

    private function createDepartment(int $customerId, string $name): Department
    {
        return Department::query()->create([
            'customer_id' => $customerId,
            'name' => $name,
            'description' => null,
            'is_active' => true,
        ]);
    }

    private function createWatchProfile(
        int $customerId,
        ?int $departmentId,
        string $name,
        array $keywords = [],
        bool $isActive = true,
        array $cpvCodes = [],
    ): WatchProfile {
        $profile = WatchProfile::query()->create([
            'customer_id' => $customerId,
            'department_id' => $departmentId,
            'name' => $name,
            'description' => null,
            'keywords' => $keywords,
            'is_active' => $isActive,
        ]);

        if ($cpvCodes !== []) {
            $profile->cpvCodes()->createMany($cpvCodes);
        }

        return $profile;
    }

    private function seedCpvCodes(array $codes): void
    {
        foreach ($codes as $code) {
            CpvCode::query()->firstOrCreate(
                ['code' => $code],
                [
                    'description_en' => "Description {$code}",
                    'description_no' => "Beskrivelse {$code}",
                ],
            );
        }
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
                $values[$envKey] = trim($envValue, " \t\n\r\0\x0B\"'");
            }
        }

        return (string) ($values[$key] ?? $default);
    }
}
