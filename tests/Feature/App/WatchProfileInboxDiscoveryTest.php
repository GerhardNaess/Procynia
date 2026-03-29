<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\Department;
use App\Models\User;
use App\Models\WatchProfile;
use App\Models\WatchProfileInboxRecord;
use App\Services\Doffin\DoffinLiveSearchService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class WatchProfileInboxDiscoveryTest extends TestCase
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();

        parent::tearDown();
    }

    public function test_nightly_discovery_processes_active_watch_profiles_and_upserts_scoped_records_idempotently(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-29 01:15:00'));

        $customer = $this->createCustomer('Procynia AS');
        $department = $this->createDepartment($customer->id, 'Consulting');
        $user = $this->createUser($customer->id, $department->id, User::ROLE_USER, 'user@procynia.test');

        $personalProfile = $this->createWatchProfile($customer->id, 'Personlig', $user->id, null, ['rammeavtale'], [
            ['cpv_code' => '72000000', 'weight' => 25],
        ]);
        $departmentProfile = $this->createWatchProfile($customer->id, 'Avdeling', null, $department->id, ['renhold']);
        $this->createWatchProfile($customer->id, 'Inaktiv', $user->id, null, ['skal-ikke-kjores'], [], false);

        $searchCalls = [];
        $service = Mockery::mock(DoffinLiveSearchService::class);
        $service->shouldReceive('search')
            ->times(4)
            ->andReturnUsing(function (array $filters, int $page, int $perPage) use (&$searchCalls): array {
                $searchCalls[] = compact('filters', 'page', 'perPage');

                if ($filters['keywords'] === 'rammeavtale') {
                    return [
                        'numHitsAccessible' => 1,
                        'numHitsTotal' => 1,
                        'hits' => [[
                            'id' => '2026-200001',
                            'heading' => 'Rammeavtale for konsulentbistand',
                            'description' => 'Denne rammeavtalen gjelder konsulenttjenester.',
                            'buyer' => [['name' => 'Procynia AS']],
                            'publicationDate' => '2026-03-29',
                            'deadline' => '2026-04-10',
                            'cpvCodes' => ['72000000'],
                            'status' => 'ACTIVE',
                        ]],
                    ];
                }

                return [
                    'numHitsAccessible' => 1,
                    'numHitsTotal' => 1,
                    'hits' => [[
                        'id' => '2026-200002',
                        'heading' => 'Renholdstjenester for nytt kontor',
                        'description' => 'Renhold av nye lokaler.',
                        'buyer' => [['name' => 'Oslo kommune']],
                        'publicationDate' => '2026-03-28',
                        'deadline' => '2026-04-09',
                        'cpvCodes' => ['90910000'],
                        'status' => 'ACTIVE',
                    ]],
                ];
            });

        $this->app->instance(DoffinLiveSearchService::class, $service);

        $firstExitCode = Artisan::call('doffin:watch-inbox-discover');
        $firstOutput = Artisan::output();

        $this->assertSame(0, $firstExitCode);
        $this->assertStringContainsString('profiles_processed: 2', $firstOutput);
        $this->assertStringContainsString('records_created: 2', $firstOutput);
        $this->assertCount(2, $searchCalls);
        $this->assertSame('rammeavtale', $searchCalls[0]['filters']['keywords']);
        $this->assertSame('72000000', $searchCalls[0]['filters']['cpv']);
        $this->assertSame('renhold', $searchCalls[1]['filters']['keywords']);

        $this->assertDatabaseHas('watch_profile_inbox_records', [
            'watch_profile_id' => $personalProfile->id,
            'user_id' => $user->id,
            'department_id' => null,
            'doffin_notice_id' => '2026-200001',
            'relevance_score' => 55,
        ]);
        $this->assertDatabaseHas('watch_profile_inbox_records', [
            'watch_profile_id' => $departmentProfile->id,
            'user_id' => null,
            'department_id' => $department->id,
            'doffin_notice_id' => '2026-200002',
            'relevance_score' => 20,
        ]);

        $firstPersonalRecord = WatchProfileInboxRecord::query()
            ->where('watch_profile_id', $personalProfile->id)
            ->where('doffin_notice_id', '2026-200001')
            ->firstOrFail();

        Carbon::setTestNow(Carbon::parse('2026-03-29 03:00:00'));

        $secondExitCode = Artisan::call('doffin:watch-inbox-discover');
        $secondOutput = Artisan::output();
        $secondPersonalRecord = WatchProfileInboxRecord::query()
            ->where('watch_profile_id', $personalProfile->id)
            ->where('doffin_notice_id', '2026-200001')
            ->firstOrFail();

        $this->assertSame(0, $secondExitCode);
        $this->assertStringContainsString('records_created: 0', $secondOutput);
        $this->assertStringContainsString('records_updated: 2', $secondOutput);
        $this->assertDatabaseCount('watch_profile_inbox_records', 2);
        $this->assertTrue($firstPersonalRecord->discovered_at->equalTo(Carbon::parse('2026-03-29 01:15:00')));
        $this->assertTrue($secondPersonalRecord->discovered_at->equalTo(Carbon::parse('2026-03-29 01:15:00')));
        $this->assertTrue($secondPersonalRecord->last_seen_at->equalTo(Carbon::parse('2026-03-29 03:00:00')));
    }

    public function test_user_and_department_inboxes_return_scoped_notice_card_payload_with_provenance(): void
    {
        $customer = $this->createCustomer('Procynia AS');
        $departmentA = $this->createDepartment($customer->id, 'Salg');
        $departmentB = $this->createDepartment($customer->id, 'Leveranse');
        $userA = $this->createUser($customer->id, $departmentA->id, User::ROLE_USER, 'user.a@procynia.test');
        $userB = $this->createUser($customer->id, $departmentB->id, User::ROLE_USER, 'user.b@procynia.test');

        $personalProfile = $this->createWatchProfile($customer->id, 'Min Profil', $userA->id, null, ['rammeavtale']);
        $departmentProfile = $this->createWatchProfile($customer->id, 'Salg Profil', null, $departmentA->id, ['renhold']);
        $this->createWatchProfile($customer->id, 'Skjult Personlig', $userB->id, null, ['skjult']);
        $foreignDepartmentProfile = $this->createWatchProfile($customer->id, 'Skjult Avdeling', null, $departmentB->id, ['skjult']);

        WatchProfileInboxRecord::query()->create([
            'watch_profile_id' => $personalProfile->id,
            'customer_id' => $customer->id,
            'user_id' => $userA->id,
            'department_id' => null,
            'doffin_notice_id' => '2026-300001',
            'title' => 'Personlig treff',
            'buyer_name' => 'Procynia AS',
            'publication_date' => Carbon::parse('2026-03-29'),
            'deadline' => Carbon::parse('2026-04-15'),
            'external_url' => 'https://doffin.no/notices/2026-300001',
            'relevance_score' => 44,
            'discovered_at' => Carbon::parse('2026-03-29 02:00:00'),
            'last_seen_at' => Carbon::parse('2026-03-29 02:00:00'),
            'raw_payload' => [
                'description' => 'Personlig innbokstreff',
                'status' => 'ACTIVE',
                'cpvCodes' => ['72000000'],
            ],
        ]);

        WatchProfileInboxRecord::query()->create([
            'watch_profile_id' => $departmentProfile->id,
            'customer_id' => $customer->id,
            'user_id' => null,
            'department_id' => $departmentA->id,
            'doffin_notice_id' => '2026-300002',
            'title' => 'Avdelingstreff',
            'buyer_name' => 'Oslo kommune',
            'publication_date' => Carbon::parse('2026-03-28'),
            'deadline' => Carbon::parse('2026-04-14'),
            'external_url' => 'https://doffin.no/notices/2026-300002',
            'relevance_score' => 31,
            'discovered_at' => Carbon::parse('2026-03-29 03:00:00'),
            'last_seen_at' => Carbon::parse('2026-03-29 03:00:00'),
            'raw_payload' => [
                'description' => 'Avdelingsinnbokstreff',
                'status' => 'ACTIVE',
                'cpvCodes' => ['90910000'],
            ],
        ]);

        WatchProfileInboxRecord::query()->create([
            'watch_profile_id' => $foreignDepartmentProfile->id,
            'customer_id' => $customer->id,
            'user_id' => null,
            'department_id' => $departmentB->id,
            'doffin_notice_id' => '2026-399999',
            'title' => 'Skjult treff',
            'buyer_name' => 'Skjult kommune',
            'relevance_score' => 1,
            'discovered_at' => Carbon::parse('2026-03-29 04:00:00'),
            'raw_payload' => ['description' => 'Skjult'],
        ]);

        $userInboxResponse = $this->actingAs($userA)->get('/app/inbox/user');

        $userInboxResponse->assertOk();
        $userInboxResponse->assertSee('Min innboks');
        $userInboxResponse->assertSee('Personlig treff');
        $userInboxResponse->assertSee('Min Profil');
        $userInboxResponse->assertSee('2026-300001');
        $userInboxResponse->assertSee('"relevance_score":44', false);
        $userInboxResponse->assertDontSee('Skjult treff');

        $departmentInboxResponse = $this->actingAs($userA)->get('/app/inbox/department');

        $departmentInboxResponse->assertOk();
        $departmentInboxResponse->assertSee('Avdelingsinnboks');
        $departmentInboxResponse->assertSee('Avdelingstreff');
        $departmentInboxResponse->assertSee('Salg Profil');
        $departmentInboxResponse->assertSee('2026-300002');
        $departmentInboxResponse->assertSee('"relevance_score":31', false);
        $departmentInboxResponse->assertDontSee('Skjult treff');
    }

    public function test_user_inbox_can_move_a_notice_to_worklist_via_existing_save_flow(): void
    {
        $customer = $this->createCustomer('Procynia AS');
        $department = $this->createDepartment($customer->id, 'Salg');
        $user = $this->createUser($customer->id, $department->id, User::ROLE_USER, 'user@procynia.test');
        $profile = $this->createWatchProfile($customer->id, 'Min Profil', $user->id, null, ['rammeavtale']);

        WatchProfileInboxRecord::query()->create([
            'watch_profile_id' => $profile->id,
            'customer_id' => $customer->id,
            'user_id' => $user->id,
            'department_id' => null,
            'doffin_notice_id' => '2026-500001',
            'title' => 'Personlig inbox-treff',
            'buyer_name' => 'Procynia AS',
            'publication_date' => Carbon::parse('2026-03-29'),
            'deadline' => Carbon::parse('2026-04-20'),
            'external_url' => 'https://doffin.no/notices/2026-500001',
            'relevance_score' => 51,
            'discovered_at' => Carbon::parse('2026-03-29 08:00:00'),
            'last_seen_at' => Carbon::parse('2026-03-29 08:00:00'),
            'raw_payload' => [
                'description' => 'Personlig inbox-treff',
                'status' => 'ACTIVE',
                'cpvCodes' => ['72000000'],
            ],
        ]);

        $initialInbox = $this->actingAs($user)->get('/app/inbox/user');
        $initialInbox->assertOk();
        $initialInbox->assertSee('"is_saved":false', false);

        $saveResponse = $this->actingAs($user)
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->from('/app/inbox/user')
            ->post('/app/notices/save', [
                'notice_id' => '2026-500001',
                'title' => 'Personlig inbox-treff',
                'buyer_name' => 'Procynia AS',
                'external_url' => 'https://doffin.no/notices/2026-500001',
                'summary' => 'Personlig inbox-treff',
                'publication_date' => '2026-03-29',
                'deadline' => '2026-04-20',
                'status' => 'ACTIVE',
                'cpv_code' => '72000000',
            ]);

        $saveResponse->assertRedirect('/app/inbox/user');
        $this->assertDatabaseHas('saved_notices', [
            'customer_id' => $customer->id,
            'saved_by_user_id' => $user->id,
            'external_id' => '2026-500001',
        ]);

        $savedInbox = $this->actingAs($user)->get('/app/inbox/user');
        $savedInbox->assertOk();
        $savedInbox->assertSee('"is_saved":true', false);
    }

    public function test_department_inbox_can_move_a_notice_to_worklist_via_existing_save_flow(): void
    {
        $customer = $this->createCustomer('Procynia AS');
        $department = $this->createDepartment($customer->id, 'Salg');
        $user = $this->createUser($customer->id, $department->id, User::ROLE_USER, 'user@procynia.test');
        $profile = $this->createWatchProfile($customer->id, 'Salg Profil', null, $department->id, ['renhold']);

        WatchProfileInboxRecord::query()->create([
            'watch_profile_id' => $profile->id,
            'customer_id' => $customer->id,
            'user_id' => null,
            'department_id' => $department->id,
            'doffin_notice_id' => '2026-500002',
            'title' => 'Avdelings inbox-treff',
            'buyer_name' => 'Oslo kommune',
            'publication_date' => Carbon::parse('2026-03-28'),
            'deadline' => Carbon::parse('2026-04-19'),
            'external_url' => 'https://doffin.no/notices/2026-500002',
            'relevance_score' => 42,
            'discovered_at' => Carbon::parse('2026-03-29 09:00:00'),
            'last_seen_at' => Carbon::parse('2026-03-29 09:00:00'),
            'raw_payload' => [
                'description' => 'Avdelings inbox-treff',
                'status' => 'ACTIVE',
                'cpvCodes' => ['90910000'],
            ],
        ]);

        $initialInbox = $this->actingAs($user)->get('/app/inbox/department');
        $initialInbox->assertOk();
        $initialInbox->assertSee('"is_saved":false', false);

        $saveResponse = $this->actingAs($user)
            ->withSession(['_token' => 'test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token'])
            ->from('/app/inbox/department')
            ->post('/app/notices/save', [
                'notice_id' => '2026-500002',
                'title' => 'Avdelings inbox-treff',
                'buyer_name' => 'Oslo kommune',
                'external_url' => 'https://doffin.no/notices/2026-500002',
                'summary' => 'Avdelings inbox-treff',
                'publication_date' => '2026-03-28',
                'deadline' => '2026-04-19',
                'status' => 'ACTIVE',
                'cpv_code' => '90910000',
            ]);

        $saveResponse->assertRedirect('/app/inbox/department');
        $this->assertDatabaseHas('saved_notices', [
            'customer_id' => $customer->id,
            'saved_by_user_id' => $user->id,
            'external_id' => '2026-500002',
        ]);

        $savedInbox = $this->actingAs($user)->get('/app/inbox/department');
        $savedInbox->assertOk();
        $savedInbox->assertSee('"is_saved":true', false);
    }

    public function test_department_inbox_access_and_navigation_follow_users_department_id(): void
    {
        $customer = $this->createCustomer('Procynia AS');
        $departmentA = $this->createDepartment($customer->id, 'Salg');
        $departmentB = $this->createDepartment($customer->id, 'Leveranse');
        $userA = $this->createUser($customer->id, $departmentA->id, User::ROLE_USER, 'user.a@procynia.test');
        $userB = $this->createUser($customer->id, $departmentB->id, User::ROLE_USER, 'user.b@procynia.test');
        $userWithoutDepartment = $this->createUser($customer->id, null, User::ROLE_USER, 'user.none@procynia.test');

        $profileA = $this->createWatchProfile($customer->id, 'Salg Profil', null, $departmentA->id, ['renhold']);
        $profileB = $this->createWatchProfile($customer->id, 'Leveranse Profil', null, $departmentB->id, ['it']);

        WatchProfileInboxRecord::query()->create([
            'watch_profile_id' => $profileA->id,
            'customer_id' => $customer->id,
            'user_id' => null,
            'department_id' => $departmentA->id,
            'doffin_notice_id' => '2026-410001',
            'title' => 'Salgstreff',
            'buyer_name' => 'Oslo kommune',
            'relevance_score' => 18,
            'discovered_at' => Carbon::parse('2026-03-29 06:00:00'),
            'last_seen_at' => Carbon::parse('2026-03-29 06:00:00'),
            'raw_payload' => ['description' => 'Salg'],
        ]);

        WatchProfileInboxRecord::query()->create([
            'watch_profile_id' => $profileB->id,
            'customer_id' => $customer->id,
            'user_id' => null,
            'department_id' => $departmentB->id,
            'doffin_notice_id' => '2026-410002',
            'title' => 'Leveransetreff',
            'buyer_name' => 'Bergen kommune',
            'relevance_score' => 12,
            'discovered_at' => Carbon::parse('2026-03-29 07:00:00'),
            'last_seen_at' => Carbon::parse('2026-03-29 07:00:00'),
            'raw_payload' => ['description' => 'Leveranse'],
        ]);

        $userInboxWithDepartment = $this->actingAs($userA)->get('/app/inbox/user');
        $userInboxWithoutDepartment = $this->actingAs($userWithoutDepartment)->get('/app/inbox/user');
        $departmentInboxForUserA = $this->actingAs($userA)->get('/app/inbox/department');
        $departmentInboxForUserB = $this->actingAs($userB)->get('/app/inbox/department');
        $departmentInboxWithoutDepartment = $this->actingAs($userWithoutDepartment)->get('/app/inbox/department');

        $userInboxWithDepartment->assertOk();
        $userInboxWithDepartment->assertSee('"can_access_department_inbox":true', false);
        $userInboxWithoutDepartment->assertOk();
        $userInboxWithoutDepartment->assertSee('"can_access_department_inbox":false', false);

        $departmentInboxForUserA->assertOk();
        $departmentInboxForUserA->assertSee('Salgstreff');
        $departmentInboxForUserA->assertDontSee('Leveransetreff');

        $departmentInboxForUserB->assertOk();
        $departmentInboxForUserB->assertSee('Leveransetreff');
        $departmentInboxForUserB->assertDontSee('Salgstreff');

        $departmentInboxWithoutDepartment->assertForbidden();
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

        Schema::create('saved_notices', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('saved_by_user_id')->nullable();
            $table->string('external_id');
            $table->string('title');
            $table->string('buyer_name')->nullable();
            $table->string('external_url', 2000)->nullable();
            $table->text('summary')->nullable();
            $table->timestamp('publication_date')->nullable();
            $table->timestamp('deadline')->nullable();
            $table->string('status')->nullable();
            $table->string('cpv_code')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('questions_deadline_at')->nullable();
            $table->timestamp('questions_rfi_deadline_at')->nullable();
            $table->timestamp('rfi_submission_deadline_at')->nullable();
            $table->timestamp('questions_rfp_deadline_at')->nullable();
            $table->timestamp('rfp_submission_deadline_at')->nullable();
            $table->timestamp('award_date_at')->nullable();
            $table->string('selected_supplier_name')->nullable();
            $table->decimal('contract_value_mnok', 12, 2)->nullable();
            $table->unsignedInteger('contract_period_months')->nullable();
            $table->timestamp('next_process_date_at')->nullable();
            $table->timestamps();
        });

        Schema::create('watch_profile_inbox_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('watch_profile_id');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->string('doffin_notice_id');
            $table->string('title');
            $table->string('buyer_name')->nullable();
            $table->timestamp('publication_date')->nullable();
            $table->timestamp('deadline')->nullable();
            $table->string('external_url', 2000)->nullable();
            $table->unsignedInteger('relevance_score')->nullable();
            $table->timestamp('discovered_at');
            $table->timestamp('last_seen_at')->nullable();
            $table->json('raw_payload')->nullable();
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
            'role' => $role,
            'customer_id' => $customerId,
            'department_id' => $departmentId,
            'is_active' => true,
            'email' => $email,
        ]);
    }

    private function createWatchProfile(
        int $customerId,
        string $name,
        ?int $userId,
        ?int $departmentId,
        array $keywords = [],
        array $cpvRules = [],
        bool $isActive = true,
    ): WatchProfile {
        $profile = WatchProfile::query()->create([
            'customer_id' => $customerId,
            'user_id' => $userId,
            'department_id' => $departmentId,
            'name' => $name,
            'description' => null,
            'keywords' => $keywords,
            'is_active' => $isActive,
        ]);

        if ($cpvRules !== []) {
            $profile->cpvCodes()->createMany($cpvRules);
        }

        return $profile;
    }
}
