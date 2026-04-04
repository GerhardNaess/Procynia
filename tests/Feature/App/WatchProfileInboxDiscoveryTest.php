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
                        'publicationDate' => '2026-03-29T00:45:00',
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
                        'publicationDate' => '2026-03-28T12:00:00',
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
        $this->assertSame('1', $searchCalls[0]['filters']['publication_period']);
        $this->assertSame('ACTIVE', $searchCalls[0]['filters']['status']);
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

    public function test_nightly_discovery_only_persists_recent_active_hits_from_the_last_24_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-29 10:00:00'));

        $customer = $this->createCustomer('Procynia AS');
        $department = $this->createDepartment($customer->id, 'Consulting');
        $user = $this->createUser($customer->id, $department->id, User::ROLE_USER, 'user@procynia.test');
        $profile = $this->createWatchProfile($customer->id, 'Personlig', $user->id, null, ['rammeavtale']);

        $searchCalls = [];
        $service = Mockery::mock(DoffinLiveSearchService::class);
        $service->shouldReceive('search')
            ->once()
            ->andReturnUsing(function (array $filters, int $page, int $perPage) use (&$searchCalls): array {
                $searchCalls[] = compact('filters', 'page', 'perPage');

                return [
                    'numHitsAccessible' => 3,
                    'numHitsTotal' => 3,
                    'hits' => [
                        [
                            'id' => '2026-210001',
                            'heading' => 'Recent active notice',
                            'description' => 'Included rammeavtale hit',
                            'buyer' => [['name' => 'Procynia AS']],
                            'publicationDate' => '2026-03-29T09:30:00',
                            'deadline' => '2026-04-15',
                            'status' => 'ACTIVE',
                        ],
                        [
                            'id' => '2026-210002',
                            'heading' => 'Old active notice',
                            'description' => 'Too old',
                            'buyer' => [['name' => 'Procynia AS']],
                            'publicationDate' => '2026-03-28T08:59:59',
                            'deadline' => '2026-04-15',
                            'status' => 'ACTIVE',
                        ],
                        [
                            'id' => '2026-210003',
                            'heading' => 'Recent inactive notice',
                            'description' => 'Wrong status',
                            'buyer' => [['name' => 'Procynia AS']],
                            'publicationDate' => '2026-03-29T08:00:00',
                            'deadline' => '2026-04-15',
                            'status' => 'EXPIRED',
                        ],
                    ],
                ];
            });

        $this->app->instance(DoffinLiveSearchService::class, $service);

        $exitCode = Artisan::call('doffin:watch-inbox-discover');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('profiles_processed: 1', $output);
        $this->assertStringContainsString('records_seen: 1', $output);
        $this->assertStringContainsString('records_created: 1', $output);
        $this->assertSame('1', $searchCalls[0]['filters']['publication_period']);
        $this->assertSame('ACTIVE', $searchCalls[0]['filters']['status']);

        $this->assertDatabaseHas('watch_profile_inbox_records', [
            'watch_profile_id' => $profile->id,
            'doffin_notice_id' => '2026-210001',
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseMissing('watch_profile_inbox_records', [
            'watch_profile_id' => $profile->id,
            'doffin_notice_id' => '2026-210002',
        ]);
        $this->assertDatabaseMissing('watch_profile_inbox_records', [
            'watch_profile_id' => $profile->id,
            'doffin_notice_id' => '2026-210003',
        ]);
    }

    public function test_nightly_discovery_skips_hits_with_zero_relevance_score_and_persists_positive_scores(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-29 10:00:00'));

        $customer = $this->createCustomer('Procynia AS');
        $department = $this->createDepartment($customer->id, 'Consulting');
        $user = $this->createUser($customer->id, $department->id, User::ROLE_USER, 'user@procynia.test');
        $profile = $this->createWatchProfile($customer->id, 'Personlig', $user->id, null, ['rammeavtale']);

        $service = Mockery::mock(DoffinLiveSearchService::class);
        $service->shouldReceive('search')
            ->once()
            ->andReturn([
                'numHitsAccessible' => 2,
                'numHitsTotal' => 2,
                'hits' => [
                    [
                        'id' => '2026-220001',
                        'heading' => 'Relevant active notice',
                        'description' => 'Denne rammeavtalen gjelder konsulenttjenester.',
                        'buyer' => [['name' => 'Procynia AS']],
                        'publicationDate' => '2026-03-29T09:30:00',
                        'deadline' => '2026-04-15',
                        'status' => 'ACTIVE',
                    ],
                    [
                        'id' => '2026-220002',
                        'heading' => 'Irrelevant active notice',
                        'description' => 'Ingen match mot profilen.',
                        'buyer' => [['name' => 'Unknown Buyer']],
                        'publicationDate' => '2026-03-29T08:30:00',
                        'deadline' => '2026-04-15',
                        'status' => 'ACTIVE',
                    ],
                ],
            ]);

        $this->app->instance(DoffinLiveSearchService::class, $service);

        $exitCode = Artisan::call('doffin:watch-inbox-discover');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('records_seen: 1', $output);
        $this->assertStringContainsString('records_created: 1', $output);
        $this->assertDatabaseHas('watch_profile_inbox_records', [
            'watch_profile_id' => $profile->id,
            'doffin_notice_id' => '2026-220001',
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseMissing('watch_profile_inbox_records', [
            'watch_profile_id' => $profile->id,
            'doffin_notice_id' => '2026-220002',
        ]);
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
            $table->string('bid_status')->default('discovered');
            $table->unsignedBigInteger('opportunity_owner_user_id')->nullable();
            $table->timestamp('bid_qualified_at')->nullable();
            $table->timestamp('bid_submitted_at')->nullable();
            $table->timestamp('bid_closed_at')->nullable();
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

        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
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
