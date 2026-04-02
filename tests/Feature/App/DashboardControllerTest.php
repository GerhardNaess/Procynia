<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\Department;
use App\Models\SavedNotice;
use App\Models\User;
use App\Models\WatchProfile;
use App\Models\WatchProfileInboxRecord;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
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

    public function test_dashboard_returns_scoped_real_data_for_a_user_with_department_access(): void
    {
        $customer = $this->createCustomer('Procynia AS');
        $otherCustomer = $this->createCustomer('Other Customer');
        $departmentA = $this->createDepartment($customer->id, 'Sales');
        $departmentB = $this->createDepartment($customer->id, 'Delivery');
        $otherDepartment = $this->createDepartment($otherCustomer->id, 'External');
        $userA = $this->createUser($customer->id, $departmentA->id, User::ROLE_USER, 'user.a@procynia.test');
        $userB = $this->createUser($customer->id, $departmentB->id, User::ROLE_USER, 'user.b@procynia.test');
        $foreignUser = $this->createUser($otherCustomer->id, $otherDepartment->id, User::ROLE_USER, 'user.c@procynia.test');

        $personalProfile = $this->createWatchProfile($customer->id, 'Personal Profile', $userA->id, null, true);
        $departmentProfile = $this->createWatchProfile($customer->id, 'Sales Profile', null, $departmentA->id, true);
        $hiddenPersonalProfile = $this->createWatchProfile($customer->id, 'Hidden Personal', $userB->id, null, true);
        $hiddenDepartmentProfile = $this->createWatchProfile($customer->id, 'Hidden Department', null, $departmentB->id, true);
        $this->createWatchProfile($customer->id, 'Inactive Personal', $userA->id, null, false);
        $foreignProfile = $this->createWatchProfile($otherCustomer->id, 'Foreign Profile', $foreignUser->id, null, true);

        $this->createInboxRecord($customer->id, $personalProfile->id, $userA->id, null, '2026-500001', 'Personal Hit', 'Procynia AS', '2026-03-29 10:00:00');
        $this->createInboxRecord($customer->id, $departmentProfile->id, null, $departmentA->id, '2026-500002', 'Department Hit', 'Oslo kommune', '2026-03-29 09:00:00');
        $this->createInboxRecord($customer->id, $hiddenPersonalProfile->id, $userB->id, null, '2026-500003', 'Hidden Personal Hit', 'Skjult', '2026-03-29 11:00:00');
        $this->createInboxRecord($customer->id, $hiddenDepartmentProfile->id, null, $departmentB->id, '2026-500004', 'Hidden Department Hit', 'Skjult', '2026-03-29 12:00:00');
        $this->createInboxRecord($otherCustomer->id, $foreignProfile->id, $foreignUser->id, null, '2026-500005', 'Foreign Hit', 'External', '2026-03-29 13:00:00');

        $this->createSavedNotice($customer->id, '2026-600001', 'Saved Notice A', organizationalDepartmentId: $departmentA->id, bidStatus: SavedNotice::BID_STATUS_DISCOVERED);
        $this->createSavedNotice($customer->id, '2026-600002', 'Saved Notice B', organizationalDepartmentId: $departmentA->id, bidStatus: SavedNotice::BID_STATUS_SUBMITTED);
        $this->createSavedNotice($customer->id, '2026-600003', 'Archived Notice', archived: true, organizationalDepartmentId: $departmentA->id, bidStatus: SavedNotice::BID_STATUS_ARCHIVED);
        $this->createSavedNotice($otherCustomer->id, '2026-600004', 'Foreign Saved Notice', organizationalDepartmentId: $otherDepartment->id, bidStatus: SavedNotice::BID_STATUS_WON);

        $page = $this->inertiaPage($this->actingAs($userA)->get('/app/dashboard'));

        $this->assertSame('App/Dashboard/Index', $page['component']);
        $this->assertArrayHasKey('pipeline', $page['props']);
        $this->assertArrayHasKey('stats', $page['props']);
        $this->assertArrayHasKey('recentInboxItems', $page['props']);
        $this->assertArrayHasKey('recentWorklistItems', $page['props']);
        $this->assertArrayHasKey('watchProfileSummary', $page['props']);
        $this->assertArrayHasKey('quickLinks', $page['props']);

        $this->assertSame(1, $page['props']['stats']['userInbox']['value']);
        $this->assertSame(1, $page['props']['stats']['departmentInbox']['value']);
        $this->assertTrue($page['props']['stats']['departmentInbox']['is_available']);
        $this->assertSame(2, $page['props']['stats']['worklist']['value']);
        $this->assertSame(2, $page['props']['stats']['activeWatchProfiles']['value']);
        $this->assertSame(3, $page['props']['pipeline']['total_count']);
        $this->assertSame(2, $page['props']['pipeline']['active_total_count']);
        $this->assertSame(1, $page['props']['pipeline']['outcome_total_count']);

        $this->assertSame(['Personal Hit', 'Department Hit'], array_column($page['props']['recentInboxItems'], 'title'));
        $this->assertSame(['Min inbox', 'Avdeling'], array_column($page['props']['recentInboxItems'], 'source_label'));
        $this->assertEqualsCanonicalizing(['Saved Notice A', 'Saved Notice B'], array_column($page['props']['recentWorklistItems'], 'title'));

        $this->assertSame(1, $page['props']['watchProfileSummary']['active_personal_count']);
        $this->assertSame(1, $page['props']['watchProfileSummary']['active_department_count']);
        $this->assertEqualsCanonicalizing(['Personal Profile', 'Sales Profile'], array_column($page['props']['watchProfileSummary']['recent_profiles'], 'name'));

        $quickLinkKeys = array_column($page['props']['quickLinks'], 'key');
        $this->assertContains('departmentInbox', $quickLinkKeys);
        $this->assertNotContains('Foreign Profile', array_column($page['props']['watchProfileSummary']['recent_profiles'], 'name'));
    }

    public function test_dashboard_returns_zero_and_no_department_shortcuts_for_user_without_department(): void
    {
        $customer = $this->createCustomer('Procynia AS');
        $department = $this->createDepartment($customer->id, 'Sales');
        $user = $this->createUser($customer->id, null, User::ROLE_USER, 'user.no.department@procynia.test');
        $personalProfile = $this->createWatchProfile($customer->id, 'Personal Profile', $user->id, null, true);
        $departmentProfile = $this->createWatchProfile($customer->id, 'Department Profile', null, $department->id, true);

        $this->createInboxRecord($customer->id, $personalProfile->id, $user->id, null, '2026-700001', 'Personal Hit', 'Procynia AS', '2026-03-29 08:00:00');
        $this->createInboxRecord($customer->id, $departmentProfile->id, null, $department->id, '2026-700002', 'Department Hit', 'Oslo kommune', '2026-03-29 09:00:00');

        $page = $this->inertiaPage($this->actingAs($user)->get('/app/dashboard'));

        $this->assertSame(1, $page['props']['stats']['userInbox']['value']);
        $this->assertSame(0, $page['props']['stats']['departmentInbox']['value']);
        $this->assertFalse($page['props']['stats']['departmentInbox']['is_available']);
        $this->assertNull($page['props']['stats']['departmentInbox']['href']);
        $this->assertSame(['Personal Hit'], array_column($page['props']['recentInboxItems'], 'title'));
        $this->assertSame(1, $page['props']['watchProfileSummary']['active_personal_count']);
        $this->assertSame(0, $page['props']['watchProfileSummary']['active_department_count']);
        $this->assertNotContains('departmentInbox', array_column($page['props']['quickLinks'], 'key'));
    }

    public function test_dashboard_treats_pivot_only_membership_as_department_access(): void
    {
        $customer = $this->createCustomer('Procynia AS');
        $department = $this->createDepartment($customer->id, 'Sales');
        $user = $this->createUser($customer->id, null, User::ROLE_USER, 'user.pivot.department@procynia.test');
        $user->departments()->attach($department->id);
        $departmentProfile = $this->createWatchProfile($customer->id, 'Department Profile', null, $department->id, true);

        $this->createInboxRecord($customer->id, $departmentProfile->id, null, $department->id, '2026-700003', 'Department Hit', 'Oslo kommune', '2026-03-29 09:00:00');

        $page = $this->inertiaPage($this->actingAs($user)->get('/app/dashboard'));

        $this->assertSame(1, $page['props']['stats']['departmentInbox']['value']);
        $this->assertTrue($page['props']['stats']['departmentInbox']['is_available']);
        $this->assertSame(['Department Hit'], array_column($page['props']['recentInboxItems'], 'title'));
        $this->assertSame(1, $page['props']['watchProfileSummary']['active_department_count']);
        $this->assertContains('departmentInbox', array_column($page['props']['quickLinks'], 'key'));
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
            $table->string('primary_affiliation_scope')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('primary_department_id')->nullable();
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

        Schema::create('saved_notices', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('saved_by_user_id')->nullable();
            $table->unsignedBigInteger('opportunity_owner_user_id')->nullable();
            $table->unsignedBigInteger('bid_manager_user_id')->nullable();
            $table->unsignedBigInteger('organizational_department_id')->nullable();
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
            $table->string('bid_status')->default(SavedNotice::BID_STATUS_DISCOVERED);
            $table->timestamps();
        });

        Schema::create('saved_notice_user_access', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('saved_notice_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('granted_by_user_id')->nullable();
            $table->string('access_role');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
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
        $user = User::factory()->create([
            'name' => Str::before($email, '@'),
            'role' => $role,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customerId,
            'department_id' => $departmentId,
            'primary_affiliation_scope' => $departmentId !== null
                ? User::PRIMARY_AFFILIATION_SCOPE_DEPARTMENT
                : User::PRIMARY_AFFILIATION_SCOPE_COMPANY,
            'primary_department_id' => $departmentId,
            'is_active' => true,
            'email' => $email,
        ]);

        if ($departmentId !== null) {
            $user->departments()->syncWithoutDetaching([$departmentId]);
        }

        return $user;
    }

    private function createWatchProfile(
        int $customerId,
        string $name,
        ?int $userId,
        ?int $departmentId,
        bool $isActive,
    ): WatchProfile {
        return WatchProfile::query()->create([
            'customer_id' => $customerId,
            'user_id' => $userId,
            'department_id' => $departmentId,
            'name' => $name,
            'description' => null,
            'keywords' => ['framework agreement'],
            'is_active' => $isActive,
        ]);
    }

    private function createInboxRecord(
        int $customerId,
        int $watchProfileId,
        ?int $userId,
        ?int $departmentId,
        string $noticeId,
        string $title,
        string $buyerName,
        string $discoveredAt,
    ): WatchProfileInboxRecord {
        return WatchProfileInboxRecord::query()->create([
            'watch_profile_id' => $watchProfileId,
            'customer_id' => $customerId,
            'user_id' => $userId,
            'department_id' => $departmentId,
            'doffin_notice_id' => $noticeId,
            'title' => $title,
            'buyer_name' => $buyerName,
            'publication_date' => Carbon::parse($discoveredAt)->startOfDay(),
            'deadline' => Carbon::parse($discoveredAt)->addDays(7),
            'external_url' => "https://doffin.no/notices/{$noticeId}",
            'relevance_score' => 30,
            'discovered_at' => Carbon::parse($discoveredAt),
            'last_seen_at' => Carbon::parse($discoveredAt),
            'raw_payload' => [
                'status' => 'ACTIVE',
            ],
        ]);
    }

    private function createSavedNotice(
        int $customerId,
        string $externalId,
        string $title,
        bool $archived = false,
        ?int $organizationalDepartmentId = null,
        string $bidStatus = SavedNotice::BID_STATUS_DISCOVERED,
    ): SavedNotice
    {
        return SavedNotice::query()->create([
            'customer_id' => $customerId,
            'saved_by_user_id' => null,
            'opportunity_owner_user_id' => null,
            'bid_manager_user_id' => null,
            'organizational_department_id' => $organizationalDepartmentId,
            'external_id' => $externalId,
            'title' => $title,
            'buyer_name' => 'Procynia',
            'external_url' => "https://doffin.no/notices/{$externalId}",
            'summary' => 'Summary',
            'publication_date' => '2026-03-20 00:00:00',
            'deadline' => '2026-04-20 00:00:00',
            'status' => 'ACTIVE',
            'cpv_code' => '72000000',
            'archived_at' => $archived ? now() : null,
            'bid_status' => $bidStatus,
        ]);
    }

    private function inertiaPage($response): array
    {
        $response->assertOk();

        $page = $response->viewData('page');

        if (is_array($page)) {
            return $page;
        }

        $this->assertIsString($page);

        return json_decode($page, true, 512, JSON_THROW_ON_ERROR);
    }
}
