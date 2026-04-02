<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\Department;
use App\Models\User;
use App\Models\WatchProfile;
use App\Services\Doffin\DoffinLiveSearchService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class WatchInboxDigestNotificationTest extends TestCase
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

    public function test_personal_watch_profile_creates_one_procynia_digest_notification_for_owner(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-02 01:15:00'));

        $customer = $this->createCustomer('Procynia AS');
        $department = $this->createDepartment($customer->id, 'Consulting');
        $owner = $this->createUser($customer->id, $department->id, User::ROLE_USER, 'owner@procynia.test');

        $this->createWatchProfile($customer->id, 'Personlig watch', $owner->id, null, ['rammeavtale']);
        $this->mockSearchResponses([
            'rammeavtale' => [[
                'id' => '2026-700001',
                'heading' => 'Rammeavtale for rådgivning',
                'description' => 'Denne rammeavtalen gjelder rådgivning.',
                'buyer' => [['name' => 'Procynia AS']],
                'publicationDate' => '2026-04-02T00:40:00',
                'deadline' => '2026-04-20',
                'status' => 'ACTIVE',
            ]],
        ]);

        $exitCode = Artisan::call('doffin:watch-inbox-discover');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('records_created: 1', $output);
        $this->assertStringContainsString('digest_alerts_created: 1', $output);
        $this->assertDatabaseCount('notifications', 1);

        $notification = DatabaseNotification::query()->firstOrFail();

        $this->assertSame(User::class, $notification->notifiable_type);
        $this->assertSame((string) $owner->id, (string) $notification->notifiable_id);
        $this->assertSame('watch_inbox_digest', $notification->data['type']);
        $this->assertSame(1, $notification->data['total_records']);
        $this->assertCount(1, $notification->data['watch_profile_sections']);
        $this->assertSame('Personlig watch', $notification->data['watch_profile_sections'][0]['watch_profile_name']);
        $this->assertSame('2026-700001', $notification->data['watch_profile_sections'][0]['records'][0]['doffin_notice_id']);
    }

    public function test_department_watch_profile_creates_notifications_for_multiple_users_in_same_department(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-02 01:15:00'));

        $customer = $this->createCustomer('Procynia AS');
        $consulting = $this->createDepartment($customer->id, 'Consulting');
        $otherDepartment = $this->createDepartment($customer->id, 'Sales');

        $pivotMember = $this->createUser($customer->id, null, User::ROLE_USER, 'pivot.member@procynia.test');
        $pivotMember->departments()->attach($consulting->id);

        $legacyMember = $this->createUser($customer->id, $consulting->id, User::ROLE_USER, 'legacy.member@procynia.test');
        $this->createUser($customer->id, $otherDepartment->id, User::ROLE_USER, 'other.member@procynia.test');

        $this->createWatchProfile($customer->id, 'Avdelingswatch', null, $consulting->id, ['renhold']);
        $this->mockSearchResponses([
            'renhold' => [[
                'id' => '2026-700002',
                'heading' => 'Renhold av nye lokaler',
                'description' => 'Renhold av lokaler for kommunen.',
                'buyer' => [['name' => 'Oslo kommune']],
                'publicationDate' => '2026-04-02T00:20:00',
                'deadline' => '2026-04-18',
                'status' => 'ACTIVE',
            ]],
        ]);

        $exitCode = Artisan::call('doffin:watch-inbox-discover');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('digest_recipients_total: 2', $output);
        $this->assertStringContainsString('digest_alerts_created: 2', $output);
        $this->assertDatabaseCount('notifications', 2);

        $notifiableIds = DatabaseNotification::query()
            ->pluck('notifiable_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->sort()
            ->values()
            ->all();

        $expectedIds = collect([$pivotMember->id, $legacyMember->id])
            ->sort()
            ->values()
            ->all();

        $this->assertSame($expectedIds, $notifiableIds);
    }

    public function test_department_watch_profile_does_not_duplicate_recipient_with_both_pivot_and_legacy_membership(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-02 01:15:00'));

        $customer = $this->createCustomer('Procynia AS');
        $consulting = $this->createDepartment($customer->id, 'Consulting');

        $hybridMember = $this->createUser($customer->id, $consulting->id, User::ROLE_USER, 'hybrid.member@procynia.test');
        $hybridMember->departments()->attach($consulting->id);

        $this->createWatchProfile($customer->id, 'Avdelingswatch', null, $consulting->id, ['renhold']);
        $this->mockSearchResponses([
            'renhold' => [[
                'id' => '2026-700007',
                'heading' => 'Renhold av nye lokaler',
                'description' => 'Renhold av lokaler for kommunen.',
                'buyer' => [['name' => 'Oslo kommune']],
                'publicationDate' => '2026-04-02T00:20:00',
                'deadline' => '2026-04-18',
                'status' => 'ACTIVE',
            ]],
        ]);

        $exitCode = Artisan::call('doffin:watch-inbox-discover');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('digest_recipients_total: 1', $output);
        $this->assertStringContainsString('digest_alerts_created: 1', $output);
        $this->assertDatabaseCount('notifications', 1);

        $notification = DatabaseNotification::query()->firstOrFail();

        $this->assertSame((string) $hybridMember->id, (string) $notification->notifiable_id);
    }

    public function test_no_new_hits_creates_no_procynia_notifications(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-02 01:15:00'));

        $customer = $this->createCustomer('Procynia AS');
        $department = $this->createDepartment($customer->id, 'Consulting');
        $user = $this->createUser($customer->id, $department->id, User::ROLE_USER, 'user@procynia.test');

        $this->createWatchProfile($customer->id, 'Personlig watch', $user->id, null, ['rammeavtale']);
        $this->mockSearchResponses([
            'rammeavtale' => [[
                'id' => '2026-700003',
                'heading' => 'For gammel kunngjøring',
                'description' => 'Denne rammeavtalen er for gammel.',
                'buyer' => [['name' => 'Procynia AS']],
                'publicationDate' => '2026-03-31T23:59:59',
                'deadline' => '2026-04-20',
                'status' => 'ACTIVE',
            ]],
        ]);

        $exitCode = Artisan::call('doffin:watch-inbox-discover');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('records_created: 0', $output);
        $this->assertStringContainsString('digest_alerts_created: 0', $output);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_same_recipient_gets_one_digest_with_multiple_watch_profiles(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-02 01:15:00'));

        $customer = $this->createCustomer('Procynia AS');
        $department = $this->createDepartment($customer->id, 'Consulting');
        $user = $this->createUser($customer->id, $department->id, User::ROLE_USER, 'user@procynia.test');

        $this->createWatchProfile($customer->id, 'Watch A', $user->id, null, ['rammeavtale']);
        $this->createWatchProfile($customer->id, 'Watch B', $user->id, null, ['renhold']);
        $this->mockSearchResponses([
            'rammeavtale' => [[
                'id' => '2026-700004',
                'heading' => 'Rammeavtale A',
                'description' => 'Denne rammeavtalen gjelder rådgivning.',
                'buyer' => [['name' => 'Procynia AS']],
                'publicationDate' => '2026-04-02T00:10:00',
                'deadline' => '2026-04-20',
                'status' => 'ACTIVE',
            ]],
            'renhold' => [[
                'id' => '2026-700005',
                'heading' => 'Renhold B',
                'description' => 'Renhold av nye lokaler.',
                'buyer' => [['name' => 'Oslo kommune']],
                'publicationDate' => '2026-04-02T00:12:00',
                'deadline' => '2026-04-19',
                'status' => 'ACTIVE',
            ]],
        ]);

        $exitCode = Artisan::call('doffin:watch-inbox-discover');

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseCount('notifications', 1);

        $notification = DatabaseNotification::query()->firstOrFail();

        $this->assertCount(2, $notification->data['watch_profile_sections']);
        $this->assertSame(
            ['Watch A', 'Watch B'],
            collect($notification->data['watch_profile_sections'])->pluck('watch_profile_name')->sort()->values()->all()
        );
    }

    public function test_records_without_recipients_are_skipped_and_logged(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-02 01:15:00'));
        Log::spy();

        $customer = $this->createCustomer('Procynia AS');
        $department = $this->createDepartment($customer->id, 'Consulting');

        $this->createWatchProfile($customer->id, 'Avdelingswatch', null, $department->id, ['renhold']);
        $this->mockSearchResponses([
            'renhold' => [[
                'id' => '2026-700006',
                'heading' => 'Renhold uten mottaker',
                'description' => 'Renhold av nye lokaler.',
                'buyer' => [['name' => 'Oslo kommune']],
                'publicationDate' => '2026-04-02T00:15:00',
                'deadline' => '2026-04-18',
                'status' => 'ACTIVE',
            ]],
        ]);

        $exitCode = Artisan::call('doffin:watch-inbox-discover');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('digest_records_skipped_no_recipient: 1', $output);
        $this->assertDatabaseCount('notifications', 0);

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, '[Procynia][WatchAlerts] Starting watch alert digest build.'))
            ->once();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, '[Procynia][WatchAlerts] Skipping record without valid recipient.'))
            ->once();

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, '[Procynia][WatchAlerts] Completed watch alert digest build.'))
            ->once();
    }

    public function test_watch_alert_flow_does_not_depend_on_saved_notice_tables(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-02 01:15:00'));

        $customer = $this->createCustomer('Procynia AS');
        $department = $this->createDepartment($customer->id, 'Consulting');
        $user = $this->createUser($customer->id, $department->id, User::ROLE_USER, 'user@procynia.test');

        $this->createWatchProfile($customer->id, 'Personlig watch', $user->id, null, ['rammeavtale']);
        $this->mockSearchResponses([
            'rammeavtale' => [[
                'id' => '2026-700007',
                'heading' => 'SavedNotice-uavhengig treff',
                'description' => 'Denne rammeavtalen gjelder rådgivning.',
                'buyer' => [['name' => 'Procynia AS']],
                'publicationDate' => '2026-04-02T00:30:00',
                'deadline' => '2026-04-20',
                'status' => 'ACTIVE',
            ]],
        ]);

        $exitCode = Artisan::call('doffin:watch-inbox-discover');

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseCount('notifications', 1);
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
            $table->string('bid_role')->nullable();
            $table->string('bid_manager_scope')->nullable();
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
        return User::query()->create([
            'name' => Str::before($email, '@'),
            'email' => $email,
            'password' => 'secret',
            'role' => $role,
            'is_active' => true,
            'customer_id' => $customerId,
            'department_id' => $departmentId,
        ]);
    }

    private function createWatchProfile(
        int $customerId,
        string $name,
        ?int $userId,
        ?int $departmentId,
        array $keywords = [],
        array $cpvRules = [],
    ): WatchProfile {
        $profile = WatchProfile::query()->create([
            'customer_id' => $customerId,
            'user_id' => $userId,
            'department_id' => $departmentId,
            'name' => $name,
            'description' => null,
            'keywords' => $keywords,
            'is_active' => true,
        ]);

        if ($cpvRules !== []) {
            $profile->cpvCodes()->createMany($cpvRules);
        }

        return $profile;
    }

    private function mockSearchResponses(array $responsesByKeyword): void
    {
        $service = Mockery::mock(DoffinLiveSearchService::class);
        $service->shouldReceive('search')
            ->andReturnUsing(function (array $filters, int $page, int $perPage) use ($responsesByKeyword): array {
                $keyword = trim((string) ($filters['keywords'] ?? ''));
                $hits = $responsesByKeyword[$keyword] ?? [];

                return [
                    'numHitsAccessible' => count($hits),
                    'numHitsTotal' => count($hits),
                    'hits' => $hits,
                ];
            });

        $this->app->instance(DoffinLiveSearchService::class, $service);
    }
}
