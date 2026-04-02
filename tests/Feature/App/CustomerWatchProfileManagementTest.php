<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\Department;
use App\Models\User;
use App\Models\WatchProfile;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerWatchProfileManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'session.driver' => 'array',
        ]);

        $this->app['db']->purge('sqlite');
        $this->app['db']->reconnect('sqlite');
        $this->withoutMiddleware(VerifyCsrfToken::class);

        $this->createSchema();
    }

    public function test_regular_user_can_create_personal_watch_profile_and_only_see_accessible_profiles(): void
    {
        $customer = $this->createCustomer('Procynia AS');
        $departmentA = $this->createDepartment($customer->id, 'Salg');
        $departmentB = $this->createDepartment($customer->id, 'Leveranse');
        $userA = $this->createUser($customer->id, $departmentA->id, User::ROLE_USER, 'user.a@procynia.test');
        $userB = $this->createUser($customer->id, $departmentB->id, User::ROLE_USER, 'user.b@procynia.test');

        $this->createWatchProfile($customer->id, 'Min Profil', $userA->id, null);
        $this->createWatchProfile($customer->id, 'Salg Profil', null, $departmentA->id);
        $this->createWatchProfile($customer->id, 'Skjult Personlig', $userB->id, null);
        $this->createWatchProfile($customer->id, 'Skjult Avdeling', null, $departmentB->id);
        $this->seedCpvCodes(['72000000']);

        $this->actingAs($userA)->get('/app/watch-profiles')
            ->assertOk()
            ->assertSee('Min Profil')
            ->assertSee('Salg Profil')
            ->assertDontSee('Skjult Personlig')
            ->assertDontSee('Skjult Avdeling');

        $response = $this->actingAs($userA)
            ->withSession(['_token' => 'test-token'])
            ->post('/app/watch-profiles', [
                '_token' => 'test-token',
                'owner_scope' => 'user',
                'name' => 'Personlig Discovery',
                'description' => 'Min egen profil',
                'is_active' => true,
                'department_id' => null,
                'keywords' => "rammeavtale\nkonsulent",
                'cpv_codes' => [
                    ['cpv_code' => '72000000', 'weight' => 15],
                ],
            ]);

        $response->assertRedirect('/app/watch-profiles');
        $this->assertDatabaseHas('watch_profiles', [
            'customer_id' => $customer->id,
            'user_id' => $userA->id,
            'department_id' => null,
            'name' => 'Personlig Discovery',
        ]);
    }

    public function test_regular_user_with_pivot_membership_can_create_department_watch_profile_only_for_owned_department(): void
    {
        $customer = $this->createCustomer('Procynia AS');
        $departmentA = $this->createDepartment($customer->id, 'Salg');
        $departmentB = $this->createDepartment($customer->id, 'Leveranse');
        $userA = $this->createUser($customer->id, null, User::ROLE_USER, 'user.a@procynia.test');
        $userA->departments()->attach($departmentA->id);
        $this->seedCpvCodes(['72000000']);

        $this->actingAs($userA)
            ->withSession(['_token' => 'test-token'])
            ->post('/app/watch-profiles', [
                '_token' => 'test-token',
                'owner_scope' => 'department',
                'name' => 'Salg Discovery',
                'description' => null,
                'is_active' => true,
                'department_id' => $departmentA->id,
                'keywords' => 'salg',
                'cpv_codes' => [
                    ['cpv_code' => '72000000', 'weight' => 5],
                ],
            ])->assertRedirect('/app/watch-profiles');

        $this->assertDatabaseHas('watch_profiles', [
            'customer_id' => $customer->id,
            'user_id' => null,
            'department_id' => $departmentA->id,
            'name' => 'Salg Discovery',
        ]);

        $this->actingAs($userA)
            ->withSession(['_token' => 'test-token'])
            ->post('/app/watch-profiles', [
                '_token' => 'test-token',
                'owner_scope' => 'department',
                'name' => 'Ugyldig Avdeling',
                'description' => null,
                'is_active' => true,
                'department_id' => $departmentB->id,
                'keywords' => 'leveranse',
                'cpv_codes' => [],
            ])->assertSessionHasErrors('department_id');
    }

    public function test_user_cannot_manage_other_users_or_other_departments_watch_profiles(): void
    {
        $customer = $this->createCustomer('Procynia AS');
        $departmentA = $this->createDepartment($customer->id, 'Salg');
        $departmentB = $this->createDepartment($customer->id, 'Leveranse');
        $userA = $this->createUser($customer->id, $departmentA->id, User::ROLE_USER, 'user.a@procynia.test');
        $userB = $this->createUser($customer->id, $departmentB->id, User::ROLE_USER, 'user.b@procynia.test');

        $personalProfile = $this->createWatchProfile($customer->id, 'Skjult Personlig', $userB->id, null);
        $departmentProfile = $this->createWatchProfile($customer->id, 'Skjult Avdeling', null, $departmentB->id);

        $this->actingAs($userA)->get("/app/watch-profiles/{$personalProfile->id}/edit")->assertNotFound();
        $this->actingAs($userA)->get("/app/watch-profiles/{$departmentProfile->id}/edit")->assertNotFound();
        $this->actingAs($userA)
            ->withSession(['_token' => 'test-token'])
            ->patch("/app/watch-profiles/{$departmentProfile->id}/toggle-active", [
                '_token' => 'test-token',
            ])->assertNotFound();
        $this->actingAs($userA)
            ->withSession(['_token' => 'test-token'])
            ->delete("/app/watch-profiles/{$personalProfile->id}", [
                '_token' => 'test-token',
            ])->assertNotFound();
    }

    public function test_customer_admin_can_see_and_edit_all_customer_watch_profiles(): void
    {
        $customer = $this->createCustomer('Procynia AS');
        $otherCustomer = $this->createCustomer('Annen Kunde AS');
        $departmentA = $this->createDepartment($customer->id, 'Salg');
        $departmentB = $this->createDepartment($customer->id, 'Leveranse');
        $admin = $this->createUser($customer->id, $departmentA->id, User::ROLE_CUSTOMER_ADMIN, 'admin@procynia.test');
        $userB = $this->createUser($customer->id, $departmentB->id, User::ROLE_USER, 'user.b@procynia.test');

        $personalProfile = $this->createWatchProfile($customer->id, 'Personlig Profil', $userB->id, null);
        $departmentProfile = $this->createWatchProfile($customer->id, 'Avdelingsprofil', null, $departmentB->id);
        $foreignProfile = $this->createWatchProfile($otherCustomer->id, 'Fremmed', null, null);

        $this->actingAs($admin)->get('/app/watch-profiles')
            ->assertOk()
            ->assertSee('Personlig Profil')
            ->assertSee('Avdelingsprofil')
            ->assertDontSee('Fremmed');

        $this->actingAs($admin)->get("/app/watch-profiles/{$personalProfile->id}/edit")->assertOk();
        $this->actingAs($admin)->get("/app/watch-profiles/{$departmentProfile->id}/edit")->assertOk();
        $this->actingAs($admin)->get("/app/watch-profiles/{$foreignProfile->id}/edit")->assertNotFound();
    }

    public function test_cpv_lookup_endpoint_returns_matches_from_cpv_codes_by_code(): void
    {
        $customer = $this->createCustomer('Procynia AS');
        $department = $this->createDepartment($customer->id, 'Salg');
        $user = $this->createUser($customer->id, $department->id, User::ROLE_USER, 'user@procynia.test');
        $this->seedCpvCodes([
            [
                'code' => '72000000',
                'description_no' => 'IT-tjenester',
                'description_en' => 'IT services',
            ],
            [
                'code' => '90910000',
                'description_no' => 'Renholdstjenester',
                'description_en' => 'Cleaning services',
            ],
        ]);

        $response = $this->actingAs($user)->get('/app/watch-profiles/cpv-suggestions?query=7200&limit=10');

        $response->assertOk();
        $response->assertJsonFragment([
            'code' => '72000000',
            'description' => 'IT-tjenester',
        ]);
        $response->assertJsonMissing([
            'code' => '90910000',
        ]);
    }

    public function test_cpv_lookup_endpoint_uses_prefix_matching_for_numeric_queries(): void
    {
        $customer = $this->createCustomer('Procynia AS');
        $department = $this->createDepartment($customer->id, 'Salg');
        $user = $this->createUser($customer->id, $department->id, User::ROLE_USER, 'user@procynia.test');
        $this->seedCpvCodes([
            [
                'code' => '71000000',
                'description_no' => 'Arkitekttjenester',
                'description_en' => 'Architecture services',
            ],
            [
                'code' => '71200000',
                'description_no' => 'Tekniske tjenester',
                'description_en' => 'Technical services',
            ],
            [
                'code' => '72140000',
                'description_no' => 'Strategisk rådgivning',
                'description_en' => 'Strategic consulting',
            ],
            [
                'code' => '72141000',
                'description_no' => 'Analyse av informasjonssystemer',
                'description_en' => 'Information systems analysis',
            ],
            [
                'code' => '39721400',
                'description_no' => 'Elektriske apparater',
                'description_en' => 'Electrical appliances',
            ],
            [
                'code' => '63721400',
                'description_no' => 'Støttetjenester',
                'description_en' => 'Support services',
            ],
            [
                'code' => '90721400',
                'description_no' => 'Miljøtjenester',
                'description_en' => 'Environmental services',
            ],
        ]);

        $prefixSeven = $this->actingAs($user)->get('/app/watch-profiles/cpv-suggestions?query=7&limit=10');
        $prefixSeventyOne = $this->actingAs($user)->get('/app/watch-profiles/cpv-suggestions?query=71&limit=10');
        $prefixSevenTwoOneFour = $this->actingAs($user)->get('/app/watch-profiles/cpv-suggestions?query=7214&limit=10');

        $prefixSeven->assertOk();
        $this->assertSame(
            ['71000000', '71200000', '72140000', '72141000'],
            array_column($prefixSeven->json('data'), 'code'),
        );

        $prefixSeventyOne->assertOk();
        $this->assertSame(
            ['71000000', '71200000'],
            array_column($prefixSeventyOne->json('data'), 'code'),
        );

        $prefixSevenTwoOneFour->assertOk();
        $this->assertSame(
            ['72140000', '72141000'],
            array_column($prefixSevenTwoOneFour->json('data'), 'code'),
        );
    }

    public function test_cpv_lookup_endpoint_returns_matches_from_cpv_codes_by_description(): void
    {
        $customer = $this->createCustomer('Procynia AS');
        $department = $this->createDepartment($customer->id, 'Salg');
        $user = $this->createUser($customer->id, $department->id, User::ROLE_USER, 'user@procynia.test');
        $this->seedCpvCodes([
            [
                'code' => '72000000',
                'description_no' => 'IT-tjenester',
                'description_en' => 'IT services',
            ],
            [
                'code' => '90910000',
                'description_no' => 'Renholdstjenester',
                'description_en' => 'Cleaning services',
            ],
        ]);

        $response = $this->actingAs($user)->get('/app/watch-profiles/cpv-suggestions?query=renhold&limit=10');

        $response->assertOk();
        $response->assertJsonFragment([
            'code' => '90910000',
            'description' => 'Renholdstjenester',
        ]);
        $response->assertJsonMissing([
            'code' => '72000000',
        ]);
    }

    public function test_edit_payload_includes_cpv_description_for_existing_rules(): void
    {
        $customer = $this->createCustomer('Procynia AS');
        $department = $this->createDepartment($customer->id, 'Salg');
        $user = $this->createUser($customer->id, $department->id, User::ROLE_USER, 'user@procynia.test');
        $this->seedCpvCodes(['72000000']);

        $watchProfile = $this->createWatchProfile($customer->id, 'Min Profil', $user->id, null);
        $watchProfile->cpvCodes()->create([
            'cpv_code' => '72000000',
            'weight' => 15,
        ]);

        $response = $this->actingAs($user)->get("/app/watch-profiles/{$watchProfile->id}/edit");

        $response->assertOk();
        $response->assertSee('"cpv_code":"72000000"', false);
        $response->assertSee('"description":"Beskrivelse 72000000"', false);
    }

    public function test_watch_profile_store_validates_that_cpv_code_exists(): void
    {
        $customer = $this->createCustomer('Procynia AS');
        $department = $this->createDepartment($customer->id, 'Salg');
        $user = $this->createUser($customer->id, $department->id, User::ROLE_USER, 'user@procynia.test');

        $response = $this->actingAs($user)
            ->withSession(['_token' => 'test-token'])
            ->post('/app/watch-profiles', [
                '_token' => 'test-token',
                'owner_scope' => 'user',
                'name' => 'Ugyldig CPV',
                'description' => null,
                'is_active' => true,
                'department_id' => null,
                'keywords' => 'rammeavtale',
                'cpv_codes' => [
                    ['cpv_code' => '99999999', 'weight' => 5],
                ],
            ]);

        $response->assertSessionHasErrors('cpv_codes.0.cpv_code');
    }

    public function test_customer_admin_can_filter_watch_profiles_by_user(): void
    {
        $customer = $this->createCustomer('Procynia AS');
        $departmentA = $this->createDepartment($customer->id, 'Salg');
        $departmentB = $this->createDepartment($customer->id, 'Leveranse');
        $admin = $this->createUser($customer->id, $departmentA->id, User::ROLE_CUSTOMER_ADMIN, 'admin@procynia.test');
        $userA = $this->createUser($customer->id, $departmentA->id, User::ROLE_USER, 'user.a@procynia.test');
        $userB = $this->createUser($customer->id, $departmentB->id, User::ROLE_USER, 'user.b@procynia.test');

        $this->createWatchProfile($customer->id, 'Personlig A', $userA->id, null);
        $this->createWatchProfile($customer->id, 'Personlig B', $userB->id, null);
        $this->createWatchProfile($customer->id, 'Avdeling B', null, $departmentB->id);

        $response = $this->actingAs($admin)->get("/app/watch-profiles?user_id={$userB->id}");
        $response->assertOk();
        $response->assertSee('Personlig B');
        $response->assertDontSee('Personlig A');
        $response->assertDontSee('Avdeling B');
        $response->assertSee('"user_id":'.$userB->id, false);
        $response->assertSee('"department_id":null', false);
    }

    public function test_customer_admin_can_filter_watch_profiles_by_department(): void
    {
        $customer = $this->createCustomer('Procynia AS');
        $departmentA = $this->createDepartment($customer->id, 'Salg');
        $departmentB = $this->createDepartment($customer->id, 'Leveranse');
        $admin = $this->createUser($customer->id, $departmentA->id, User::ROLE_CUSTOMER_ADMIN, 'admin@procynia.test');
        $userA = $this->createUser($customer->id, $departmentA->id, User::ROLE_USER, 'user.a@procynia.test');

        $this->createWatchProfile($customer->id, 'Personlig A', $userA->id, null);
        $this->createWatchProfile($customer->id, 'Avdeling A', null, $departmentA->id);
        $this->createWatchProfile($customer->id, 'Avdeling B', null, $departmentB->id);

        $response = $this->actingAs($admin)->get("/app/watch-profiles?department_id={$departmentB->id}");
        $response->assertOk();
        $response->assertSee('Avdeling B');
        $response->assertDontSee('Personlig A');
        $response->assertDontSee('Avdeling A');
        $response->assertSee('"user_id":null', false);
        $response->assertSee('"department_id":'.$departmentB->id, false);
    }

    public function test_regular_user_cannot_expand_access_by_manipulating_filter_query_parameters(): void
    {
        $customer = $this->createCustomer('Procynia AS');
        $departmentA = $this->createDepartment($customer->id, 'Salg');
        $departmentB = $this->createDepartment($customer->id, 'Leveranse');
        $userA = $this->createUser($customer->id, $departmentA->id, User::ROLE_USER, 'user.a@procynia.test');
        $userB = $this->createUser($customer->id, $departmentB->id, User::ROLE_USER, 'user.b@procynia.test');

        $this->createWatchProfile($customer->id, 'Personlig A', $userA->id, null);
        $this->createWatchProfile($customer->id, 'Avdeling A', null, $departmentA->id);
        $this->createWatchProfile($customer->id, 'Personlig B', $userB->id, null);
        $this->createWatchProfile($customer->id, 'Avdeling B', null, $departmentB->id);

        $response = $this->actingAs($userA)->get("/app/watch-profiles?user_id={$userB->id}&department_id={$departmentB->id}");
        $response->assertOk();
        $response->assertDontSee('Personlig B');
        $response->assertDontSee('Avdeling B');
        $response->assertSee('"user_id":'.$userB->id, false);
        $response->assertSee('"department_id":'.$departmentB->id, false);
        $response->assertSee('"filterOptions":{"users":[{"value":'.$userA->id.',"label":"user.a"}]', false);
        $response->assertDontSee('"label":"user.b"', false);
        $response->assertSee('"departments":[{"value":'.$departmentA->id.',"label":"Salg"}]', false);
        $response->assertDontSee('"label":"Leveranse"', false);
    }

    public function test_filter_options_are_scoped_for_customer_admin_and_regular_user(): void
    {
        $customer = $this->createCustomer('Procynia AS');
        $departmentA = $this->createDepartment($customer->id, 'Salg');
        $departmentB = $this->createDepartment($customer->id, 'Leveranse');
        $admin = $this->createUser($customer->id, $departmentA->id, User::ROLE_CUSTOMER_ADMIN, 'admin@procynia.test');
        $userA = $this->createUser($customer->id, $departmentA->id, User::ROLE_USER, 'user.a@procynia.test');
        $userB = $this->createUser($customer->id, $departmentB->id, User::ROLE_USER, 'user.b@procynia.test');

        $this->createWatchProfile($customer->id, 'Personlig A', $userA->id, null);
        $this->createWatchProfile($customer->id, 'Personlig B', $userB->id, null);
        $this->createWatchProfile($customer->id, 'Avdeling A', null, $departmentA->id);
        $this->createWatchProfile($customer->id, 'Avdeling B', null, $departmentB->id);

        $adminResponse = $this->actingAs($admin)->get('/app/watch-profiles');
        $userResponse = $this->actingAs($userA)->get('/app/watch-profiles');

        $adminResponse->assertSee('"label":"user.a"', false);
        $adminResponse->assertSee('"label":"user.b"', false);
        $adminResponse->assertSee('"label":"Salg"', false);
        $adminResponse->assertSee('"label":"Leveranse"', false);
        $userResponse->assertSee('"filterOptions":{"users":[{"value":'.$userA->id.',"label":"user.a"}]', false);
        $userResponse->assertDontSee('"label":"user.b"', false);
        $userResponse->assertSee('"departments":[{"value":'.$departmentA->id.',"label":"Salg"}]', false);
        $userResponse->assertDontSee('"label":"Leveranse"', false);
    }

    private function createSchema(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->unsignedBigInteger('nationality_id')->nullable();
            $table->unsignedBigInteger('language_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('departments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->string('role')->default(User::ROLE_USER);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('nationality_id')->nullable();
            $table->unsignedBigInteger('preferred_language_id')->nullable();
            $table->timestamps();
        });

        Schema::create('department_user', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('department_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
        });

        Schema::create('cpv_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('description_en')->nullable();
            $table->string('description_no')->nullable();
            $table->timestamps();
        });

        Schema::create('watch_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('keywords')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('watch_profile_cpv_codes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('watch_profile_id');
            $table->string('cpv_code');
            $table->integer('weight')->default(1);
            $table->timestamps();
        });
    }

    private function createCustomer(string $name): Customer
    {
        return Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
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

    private function createUser(int $customerId, ?int $departmentId, string $role, string $email): User
    {
        return User::factory()->create([
            'name' => Str::before($email, '@'),
            'role' => $role,
            'customer_id' => $customerId,
            'department_id' => $departmentId,
            'is_active' => true,
            'email' => $email,
        ]);
    }

    private function createWatchProfile(int $customerId, string $name, ?int $userId, ?int $departmentId): WatchProfile
    {
        return WatchProfile::query()->create([
            'customer_id' => $customerId,
            'user_id' => $userId,
            'department_id' => $departmentId,
            'name' => $name,
            'description' => null,
            'keywords' => ['rammeavtale'],
            'is_active' => true,
        ]);
    }

    private function seedCpvCodes(array $codes): void
    {
        foreach ($codes as $entry) {
            $code = is_array($entry) ? (string) ($entry['code'] ?? '') : (string) $entry;

            \App\Models\CpvCode::query()->create([
                'code' => $code,
                'description_en' => is_array($entry)
                    ? (string) ($entry['description_en'] ?? "Description {$code}")
                    : "Description {$code}",
                'description_no' => is_array($entry)
                    ? (string) ($entry['description_no'] ?? "Beskrivelse {$code}")
                    : "Beskrivelse {$code}",
            ]);
        }
    }

}
