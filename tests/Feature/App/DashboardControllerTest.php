<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\Department;
use App\Models\BidSubmission;
use App\Models\SavedNotice;
use App\Models\SavedNoticeBusinessReview;
use App\Models\SavedNoticePhaseComment;
use App\Models\SavedNoticeUserAccess;
use App\Models\User;
use App\Models\WatchProfile;
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

        $savedNoticeA = $this->createSavedNotice(
            $customer->id,
            '2026-600001',
            'Saved Notice A',
            organizationalDepartmentId: $departmentA->id,
            bidStatus: SavedNotice::BID_STATUS_DISCOVERED,
            deadlineAt: now()->addDays(12)->toDateTimeString(),
        );
        $savedNoticeB = $this->createSavedNotice(
            $customer->id,
            '2026-600002',
            'Saved Notice B',
            organizationalDepartmentId: $departmentA->id,
            bidStatus: SavedNotice::BID_STATUS_SUBMITTED,
            bidManagerUserId: $userA->id,
            deadlineAt: now()->addDays(9)->toDateTimeString(),
        );
        $this->createSavedNotice(
            $customer->id,
            '2026-600003',
            'Archived Notice',
            archived: true,
            organizationalDepartmentId: $departmentA->id,
            bidStatus: SavedNotice::BID_STATUS_ARCHIVED,
        );
        $savedNoticeD = $this->createSavedNotice(
            $customer->id,
            '2026-600004',
            'Go No Go Notice',
            organizationalDepartmentId: $departmentA->id,
            bidStatus: SavedNotice::BID_STATUS_GO_NO_GO,
            bidManagerUserId: $userA->id,
            deadlineAt: now()->addDays(10)->toDateTimeString(),
            updatedAt: now()->subDays(8)->toDateTimeString(),
        );
        $savedNoticeE = $this->createSavedNotice(
            $customer->id,
            '2026-600005',
            'Follow-up Notice',
            organizationalDepartmentId: $departmentA->id,
            bidStatus: SavedNotice::BID_STATUS_QUALIFYING,
            opportunityOwnerUserId: $userA->id,
            deadlineAt: now()->addDays(3)->toDateTimeString(),
        );
        $savedNoticeF = $this->createSavedNotice(
            $customer->id,
            '2026-600006',
            'In Progress Notice',
            organizationalDepartmentId: $departmentA->id,
            bidStatus: SavedNotice::BID_STATUS_IN_PROGRESS,
            bidManagerUserId: $userA->id,
            deadlineAt: now()->addDays(8)->toDateTimeString(),
        );

        SavedNoticePhaseComment::query()->create([
            'saved_notice_id' => $savedNoticeE->id,
            'user_id' => $userA->id,
            'phase_status' => SavedNotice::BID_STATUS_QUALIFYING,
            'comment' => 'Qualification comment',
            'created_at' => now()->subHours(3),
            'updated_at' => now()->subHours(3),
        ]);
        BidSubmission::query()->create([
            'saved_notice_id' => $savedNoticeB->id,
            'sequence_number' => 1,
            'label' => 'Initial Submission',
            'submitted_at' => now()->subHours(2),
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
        ]);
        $this->createSavedNotice($otherCustomer->id, '2026-600004', 'Foreign Saved Notice', organizationalDepartmentId: $otherDepartment->id, bidStatus: SavedNotice::BID_STATUS_WON);

        $page = $this->inertiaPage($this->actingAs($userA)->get('/app/dashboard'));

        $this->assertSame('App/Dashboard/Index', $page['component']);
        $this->assertArrayHasKey('cockpit', $page['props']);
        $this->assertArrayHasKey('pipeline', $page['props']);
        $this->assertArrayHasKey('stats', $page['props']);
        $this->assertArrayHasKey('recentWorklistItems', $page['props']);
        $this->assertArrayHasKey('watchProfileSummary', $page['props']);
        $this->assertArrayHasKey('quickLinks', $page['props']);

        $this->assertSame(5, $page['props']['stats']['worklist']['value']);
        $this->assertSame(2, $page['props']['stats']['activeWatchProfiles']['value']);
        $this->assertSame(6, $page['props']['pipeline']['total_count']);
        $this->assertSame(5, $page['props']['pipeline']['active_total_count']);
        $this->assertSame(1, $page['props']['pipeline']['outcome_total_count']);

        $this->assertSame(4, count($page['props']['cockpit']['attention']['items']));
        $this->assertSame(1, $page['props']['cockpit']['attention']['items'][0]['count']);
        $this->assertSame(6, $page['props']['cockpit']['portfolio']['total']);
        $this->assertSame(5, $page['props']['cockpit']['portfolio']['active']);
        $this->assertSame(1, $page['props']['cockpit']['portfolio']['outcome']);
        $this->assertCount(2, $page['props']['cockpit']['pipeline_quality']['conversions']);
        $this->assertSame(3, $page['props']['cockpit']['responsibility_activity']['bid_manager_cases_count']);
        $this->assertSame(1, $page['props']['cockpit']['responsibility_activity']['opportunity_owner_cases_count']);
        $this->assertSame(2, $page['props']['cockpit']['responsibility_activity']['saved_watch_lists_count']);
        $this->assertSame(0, $page['props']['cockpit']['responsibility_activity']['contributor_cases_count']);
        $this->assertGreaterThanOrEqual(3, $page['props']['cockpit']['responsibility_activity']['activity']['activity_count_14_days']);
        $this->assertNotEmpty($page['props']['cockpit']['deadlines']['items']);
        $this->assertContains('Saved Notice A', array_column($page['props']['recentWorklistItems'], 'title'));
        $this->assertContains('Saved Notice B', array_column($page['props']['recentWorklistItems'], 'title'));

        $this->assertSame(1, $page['props']['watchProfileSummary']['active_personal_count']);
        $this->assertSame(1, $page['props']['watchProfileSummary']['active_department_count']);
        $this->assertEqualsCanonicalizing(['Personal Profile', 'Sales Profile'], array_column($page['props']['watchProfileSummary']['recent_profiles'], 'name'));
        $this->assertNotContains('Foreign Profile', array_column($page['props']['watchProfileSummary']['recent_profiles'], 'name'));
    }

    public function test_dashboard_returns_zero_and_no_department_shortcuts_for_user_without_department(): void
    {
        $customer = $this->createCustomer('Procynia AS');
        $department = $this->createDepartment($customer->id, 'Sales');
        $user = $this->createUser($customer->id, null, User::ROLE_USER, 'user.no.department@procynia.test');
        $personalProfile = $this->createWatchProfile($customer->id, 'Personal Profile', $user->id, null, true);
        $departmentProfile = $this->createWatchProfile($customer->id, 'Department Profile', null, $department->id, true);

        $page = $this->inertiaPage($this->actingAs($user)->get('/app/dashboard'));

        $this->assertSame(0, $page['props']['stats']['worklist']['value']);
        $this->assertSame(1, $page['props']['watchProfileSummary']['active_personal_count']);
        $this->assertSame(0, $page['props']['watchProfileSummary']['active_department_count']);
    }

    public function test_dashboard_deadlines_include_business_reviews_in_calendar(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-03 12:00:00'));

        try {
            $customer = $this->createCustomer('Procynia AS');
            $department = $this->createDepartment($customer->id, 'Sales');
            $user = $this->createUser($customer->id, $department->id, User::ROLE_USER, 'user.business-review@procynia.test');

            $savedNotice = $this->createSavedNotice(
                $customer->id,
                '2026-600007',
                'Business Review Notice',
                organizationalDepartmentId: $department->id,
                bidStatus: SavedNotice::BID_STATUS_DISCOVERED,
                deadlineAt: now()->addDays(20)->toDateTimeString(),
                rfiSubmissionDeadlineAt: now()->subDay()->toDateTimeString(),
                rfpSubmissionDeadlineAt: now()->addDays(12)->toDateTimeString(),
                opportunityOwnerUserId: $user->id,
            );

            SavedNoticeBusinessReview::query()->create([
                'saved_notice_id' => $savedNotice->id,
                'business_review_at' => now()->addDays(2)->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $page = $this->inertiaPage($this->actingAs($user)->get('/app/dashboard'));

            $deadlineItems = $page['props']['cockpit']['deadlines']['items'];
            $businessReviewItem = collect($deadlineItems)->firstWhere('deadline_type', 'business_review');
            $this->assertNotNull($businessReviewItem);
            $this->assertSame('Business Review', $businessReviewItem['deadline_type_label']);
            $this->assertSame(now()->addDays(2)->toDateString(), $businessReviewItem['date']);
            $this->assertContains('Business Review Notice', array_column($deadlineItems, 'title'));

            $attentionItems = collect($page['props']['cockpit']['attention']['items'])->keyBy('key');
            $this->assertSame(1, $attentionItems['deadline-soon']['count']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_dashboard_responsibility_activity_uses_role_based_scope_for_supported_roles(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-03 12:00:00'));

        try {
            $customer = $this->createCustomer('Procynia AS');
            $department = $this->createDepartment($customer->id, 'Sales');
            $regularUser = $this->createUser($customer->id, $department->id, User::ROLE_USER, 'user.regular@procynia.test');
            $otherUser = $this->createUser($customer->id, $department->id, User::ROLE_USER, 'user.other@procynia.test');
            $bidManagerUser = $this->createUser(
                $customer->id,
                null,
                User::ROLE_CUSTOMER_ADMIN,
                'user.bid-manager@procynia.test',
                User::BID_ROLE_BID_MANAGER,
                User::BID_MANAGER_SCOPE_COMPANY,
            );
            $systemOwnerUser = $this->createUser(
                $customer->id,
                null,
                User::ROLE_CUSTOMER_ADMIN,
                'user.system-owner@procynia.test',
                User::BID_ROLE_SYSTEM_OWNER,
            );

            $regularBidManagerNotice = $this->createSavedNotice(
                $customer->id,
                '2026-710001',
                'Regular bid-manager notice',
                organizationalDepartmentId: $department->id,
                bidStatus: SavedNotice::BID_STATUS_DISCOVERED,
                updatedAt: now()->subDays(20)->toDateTimeString(),
                bidManagerUserId: $regularUser->id,
                deadlineAt: now()->addDays(2)->toDateTimeString(),
            );
            $regularBidManagerComment = SavedNoticePhaseComment::query()->create([
                'saved_notice_id' => $regularBidManagerNotice->id,
                'user_id' => $regularUser->id,
                'phase_status' => SavedNotice::BID_STATUS_DISCOVERED,
                'comment' => 'Regular bid-manager comment',
            ]);
            $regularBidManagerComment->timestamps = false;
            $regularBidManagerComment->forceFill([
                'created_at' => now()->subHours(3),
                'updated_at' => now()->subHours(3),
            ])->saveQuietly();
            BidSubmission::query()->create([
                'saved_notice_id' => $regularBidManagerNotice->id,
                'sequence_number' => 1,
                'label' => 'Regular submission',
                'submitted_at' => now()->subHours(2),
                'created_at' => now()->subHours(2),
                'updated_at' => now()->subHours(2),
            ]);
            SavedNoticeUserAccess::query()->create([
                'saved_notice_id' => $regularBidManagerNotice->id,
                'user_id' => $regularUser->id,
                'granted_by_user_id' => $otherUser->id,
                'access_role' => SavedNoticeUserAccess::ACCESS_ROLE_CONTRIBUTOR,
            ]);

            $this->createSavedNotice(
                $customer->id,
                '2026-710002',
                'Regular opportunity-owner notice',
                organizationalDepartmentId: $department->id,
                bidStatus: SavedNotice::BID_STATUS_DISCOVERED,
                updatedAt: now()->subDays(2)->toDateTimeString(),
                opportunityOwnerUserId: $regularUser->id,
                deadlineAt: now()->addDays(4)->toDateTimeString(),
            );

            $foreignBidManagerNotice = $this->createSavedNotice(
                $customer->id,
                '2026-710003',
                'Foreign bid-manager notice',
                organizationalDepartmentId: $department->id,
                bidStatus: SavedNotice::BID_STATUS_DISCOVERED,
                updatedAt: now()->subHour()->toDateTimeString(),
                bidManagerUserId: $otherUser->id,
                deadlineAt: now()->addDay()->toDateTimeString(),
            );
            $foreignBidManagerComment = SavedNoticePhaseComment::query()->create([
                'saved_notice_id' => $foreignBidManagerNotice->id,
                'user_id' => $otherUser->id,
                'phase_status' => SavedNotice::BID_STATUS_DISCOVERED,
                'comment' => 'Foreign bid-manager comment',
            ]);
            $foreignBidManagerComment->timestamps = false;
            $foreignBidManagerComment->forceFill([
                'created_at' => now()->subMinutes(50),
                'updated_at' => now()->subMinutes(50),
            ])->saveQuietly();
            BidSubmission::query()->create([
                'saved_notice_id' => $foreignBidManagerNotice->id,
                'sequence_number' => 1,
                'label' => 'Foreign submission',
                'submitted_at' => now()->subMinutes(30),
                'created_at' => now()->subMinutes(30),
                'updated_at' => now()->subMinutes(30),
            ]);
            SavedNoticeUserAccess::query()->create([
                'saved_notice_id' => $foreignBidManagerNotice->id,
                'user_id' => $otherUser->id,
                'granted_by_user_id' => $regularUser->id,
                'access_role' => SavedNoticeUserAccess::ACCESS_ROLE_CONTRIBUTOR,
            ]);

            $this->createSavedNotice(
                $customer->id,
                '2026-710004',
                'Foreign opportunity-owner notice',
                organizationalDepartmentId: $department->id,
                bidStatus: SavedNotice::BID_STATUS_DISCOVERED,
                updatedAt: now()->subDays(10)->toDateTimeString(),
                opportunityOwnerUserId: $otherUser->id,
                deadlineAt: now()->addDays(7)->toDateTimeString(),
            );

            $regularPage = $this->inertiaPage($this->actingAs($regularUser)->get('/app/dashboard'));
            $regularResponsibility = $regularPage['props']['cockpit']['responsibility_activity'];

            $this->assertSame(1, $regularResponsibility['bid_manager_cases_count']);
            $this->assertSame(1, $regularResponsibility['opportunity_owner_cases_count']);
            $this->assertSame(1, $regularResponsibility['contributor_cases_count']);
            $this->assertSame(now()->subHours(3)->toIso8601String(), $regularResponsibility['activity']['last_comment_at']);
            $this->assertSame(now()->subHours(2)->toIso8601String(), $regularResponsibility['activity']['last_activity_at']);
            $this->assertSame(3, $regularResponsibility['activity']['activity_count_14_days']);
            $this->assertSame(0, $regularResponsibility['activity']['inactive_7_days_count']);

            $bidManagerPage = $this->inertiaPage($this->actingAs($bidManagerUser)->get('/app/dashboard'));
            $bidManagerResponsibility = $bidManagerPage['props']['cockpit']['responsibility_activity'];

            $this->assertSame(2, $bidManagerResponsibility['bid_manager_cases_count']);
            $this->assertSame(2, $bidManagerResponsibility['opportunity_owner_cases_count']);
            $this->assertSame(2, $bidManagerResponsibility['contributor_cases_count']);
            $this->assertSame(now()->subMinutes(50)->toIso8601String(), $bidManagerResponsibility['activity']['last_comment_at']);
            $this->assertSame(now()->subMinutes(30)->toIso8601String(), $bidManagerResponsibility['activity']['last_activity_at']);
            $this->assertSame(7, $bidManagerResponsibility['activity']['activity_count_14_days']);
            $this->assertSame(1, $bidManagerResponsibility['activity']['inactive_7_days_count']);

            $systemOwnerPage = $this->inertiaPage($this->actingAs($systemOwnerUser)->get('/app/dashboard'));
            $systemOwnerResponsibility = $systemOwnerPage['props']['cockpit']['responsibility_activity'];

            $this->assertSame(2, $systemOwnerResponsibility['bid_manager_cases_count']);
            $this->assertSame(2, $systemOwnerResponsibility['opportunity_owner_cases_count']);
            $this->assertSame(2, $systemOwnerResponsibility['contributor_cases_count']);
            $this->assertSame(now()->subMinutes(50)->toIso8601String(), $systemOwnerResponsibility['activity']['last_comment_at']);
            $this->assertSame(now()->subMinutes(30)->toIso8601String(), $systemOwnerResponsibility['activity']['last_activity_at']);
            $this->assertSame(7, $systemOwnerResponsibility['activity']['activity_count_14_days']);
            $this->assertSame(1, $systemOwnerResponsibility['activity']['inactive_7_days_count']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_dashboard_attention_and_deadlines_use_role_based_scope_for_all_supported_roles(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-03 12:00:00'));

        try {
            $customer = $this->createCustomer('Procynia AS');
            $department = $this->createDepartment($customer->id, 'Sales');
            $regularUser = $this->createUser($customer->id, $department->id, User::ROLE_USER, 'user.regular@procynia.test');
            $otherUser = $this->createUser($customer->id, $department->id, User::ROLE_USER, 'user.other@procynia.test');
            $bidManagerUser = $this->createUser(
                $customer->id,
                null,
                User::ROLE_CUSTOMER_ADMIN,
                'user.bid-manager@procynia.test',
                User::BID_ROLE_BID_MANAGER,
                User::BID_MANAGER_SCOPE_COMPANY,
            );
            $systemOwnerUser = $this->createUser(
                $customer->id,
                null,
                User::ROLE_CUSTOMER_ADMIN,
                'user.system-owner@procynia.test',
                User::BID_ROLE_SYSTEM_OWNER,
            );

            $this->createSavedNotice(
                $customer->id,
                '2026-720001',
                'Regular own bid-manager case',
                organizationalDepartmentId: $department->id,
                bidStatus: SavedNotice::BID_STATUS_DISCOVERED,
                deadlineAt: now()->addDays(2)->toDateTimeString(),
                updatedAt: now()->subDay()->toDateTimeString(),
                bidManagerUserId: $regularUser->id,
            );
            $this->createSavedNotice(
                $customer->id,
                '2026-720002',
                'Regular own opportunity-owner case',
                organizationalDepartmentId: $department->id,
                bidStatus: SavedNotice::BID_STATUS_DISCOVERED,
                deadlineAt: now()->addDays(9)->toDateTimeString(),
                updatedAt: now()->subDay()->toDateTimeString(),
                opportunityOwnerUserId: $regularUser->id,
            );
            $this->createSavedNotice(
                $customer->id,
                '2026-720003',
                'Foreign go/no-go case',
                organizationalDepartmentId: $department->id,
                bidStatus: SavedNotice::BID_STATUS_GO_NO_GO,
                deadlineAt: now()->addDay()->toDateTimeString(),
                updatedAt: now()->subDays(2)->toDateTimeString(),
            );
            $this->createSavedNotice(
                $customer->id,
                '2026-720004',
                'Foreign inactive case',
                organizationalDepartmentId: $department->id,
                bidStatus: SavedNotice::BID_STATUS_DISCOVERED,
                deadlineAt: now()->addDays(17)->toDateTimeString(),
                updatedAt: now()->subDays(10)->toDateTimeString(),
                bidManagerUserId: $otherUser->id,
            );

            $regularPage = $this->inertiaPage($this->actingAs($regularUser)->get('/app/dashboard'));
            $regularAttention = collect($regularPage['props']['cockpit']['attention']['items'])->keyBy('key');

            $this->assertSame(4, $regularAttention->count());
            $this->assertSame(1, $regularAttention['deadline-soon']['count']);
            $this->assertSame(1, $regularAttention['missing-bid-manager']['count']);
            $this->assertSame(0, $regularAttention['go-no-go-pending']['count']);
            $this->assertSame(0, $regularAttention['inactive-seven-days']['count']);
            $this->assertStringContainsString('cockpit_scope=1', $regularAttention['deadline-soon']['href']);
            $this->assertSame(2, count($regularPage['props']['cockpit']['deadlines']['items']));
            $this->assertEqualsCanonicalizing(
                ['Regular own bid-manager case', 'Regular own opportunity-owner case'],
                array_column($regularPage['props']['cockpit']['deadlines']['items'], 'title'),
            );

            $bidManagerPage = $this->inertiaPage($this->actingAs($bidManagerUser)->get('/app/dashboard'));
            $bidManagerAttention = collect($bidManagerPage['props']['cockpit']['attention']['items'])->keyBy('key');

            $this->assertSame(4, $bidManagerAttention->count());
            $this->assertSame(2, $bidManagerAttention['deadline-soon']['count']);
            $this->assertSame(2, $bidManagerAttention['missing-bid-manager']['count']);
            $this->assertSame(1, $bidManagerAttention['go-no-go-pending']['count']);
            $this->assertSame(1, $bidManagerAttention['inactive-seven-days']['count']);
            $this->assertStringContainsString('cockpit_scope=1', $bidManagerAttention['deadline-soon']['href']);
            $this->assertSame(4, count($bidManagerPage['props']['cockpit']['deadlines']['items']));
            $this->assertEqualsCanonicalizing(
                [
                    'Regular own bid-manager case',
                    'Regular own opportunity-owner case',
                    'Foreign go/no-go case',
                    'Foreign inactive case',
                ],
                array_column($bidManagerPage['props']['cockpit']['deadlines']['items'], 'title'),
            );

            $systemOwnerPage = $this->inertiaPage($this->actingAs($systemOwnerUser)->get('/app/dashboard'));
            $systemOwnerAttention = collect($systemOwnerPage['props']['cockpit']['attention']['items'])->keyBy('key');

            $this->assertSame(4, $systemOwnerAttention->count());
            $this->assertSame(2, $systemOwnerAttention['deadline-soon']['count']);
            $this->assertSame(2, $systemOwnerAttention['missing-bid-manager']['count']);
            $this->assertSame(1, $systemOwnerAttention['go-no-go-pending']['count']);
            $this->assertSame(1, $systemOwnerAttention['inactive-seven-days']['count']);
            $this->assertStringContainsString('cockpit_scope=1', $systemOwnerAttention['deadline-soon']['href']);
            $this->assertSame(4, count($systemOwnerPage['props']['cockpit']['deadlines']['items']));
            $this->assertEqualsCanonicalizing(
                [
                    'Regular own bid-manager case',
                    'Regular own opportunity-owner case',
                    'Foreign go/no-go case',
                    'Foreign inactive case',
                ],
                array_column($systemOwnerPage['props']['cockpit']['deadlines']['items'], 'title'),
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_dashboard_treats_pivot_only_membership_as_department_access(): void
    {
        $customer = $this->createCustomer('Procynia AS');
        $department = $this->createDepartment($customer->id, 'Sales');
        $user = $this->createUser($customer->id, null, User::ROLE_USER, 'user.pivot.department@procynia.test');
        $user->departments()->attach($department->id);
        $departmentProfile = $this->createWatchProfile($customer->id, 'Department Profile', null, $department->id, true);

        $page = $this->inertiaPage($this->actingAs($user)->get('/app/dashboard'));

        $this->assertSame(1, $page['props']['watchProfileSummary']['active_department_count']);
        $this->assertEqualsCanonicalizing(
            ['Department Profile'],
            array_column($page['props']['watchProfileSummary']['recent_profiles'], 'name'),
        );
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
            $table->string('source_type')->default(SavedNotice::SOURCE_TYPE_PUBLIC_NOTICE);
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

        Schema::create('saved_notice_phase_comments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('saved_notice_id');
            $table->unsignedBigInteger('user_id');
            $table->string('phase_status');
            $table->text('comment');
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

        Schema::create('bid_submissions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('saved_notice_id');
            $table->unsignedInteger('sequence_number');
            $table->string('label');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('saved_notice_business_reviews', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('saved_notice_id');
            $table->timestamp('business_review_at');
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

    private function createUser(
        int $customerId,
        ?int $departmentId,
        string $role,
        string $email,
        ?string $bidRole = null,
        ?string $bidManagerScope = null,
    ): User
    {
        $user = User::factory()->create([
            'name' => Str::before($email, '@'),
            'role' => $role,
            'bid_role' => $bidRole ?? User::BID_ROLE_CONTRIBUTOR,
            'bid_manager_scope' => $bidManagerScope,
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

    private function createSavedNotice(
        int $customerId,
        string $externalId,
        string $title,
        bool $archived = false,
        ?int $organizationalDepartmentId = null,
        string $bidStatus = SavedNotice::BID_STATUS_DISCOVERED,
        ?string $deadlineAt = null,
        ?string $questionsRfiDeadlineAt = null,
        ?string $rfiSubmissionDeadlineAt = null,
        ?string $questionsRfpDeadlineAt = null,
        ?string $rfpSubmissionDeadlineAt = null,
        ?string $awardDateAt = null,
        ?string $updatedAt = null,
        ?int $bidManagerUserId = null,
        ?int $opportunityOwnerUserId = null,
    ): SavedNotice
    {
        $notice = SavedNotice::query()->create([
            'customer_id' => $customerId,
            'saved_by_user_id' => null,
            'opportunity_owner_user_id' => $opportunityOwnerUserId,
            'bid_manager_user_id' => $bidManagerUserId,
            'organizational_department_id' => $organizationalDepartmentId,
            'external_id' => $externalId,
            'title' => $title,
            'buyer_name' => 'Procynia',
            'external_url' => "https://doffin.no/notices/{$externalId}",
            'summary' => 'Summary',
            'publication_date' => '2026-03-20 00:00:00',
            'deadline' => $deadlineAt ?? '2026-04-20 00:00:00',
            'questions_rfi_deadline_at' => $questionsRfiDeadlineAt,
            'rfi_submission_deadline_at' => $rfiSubmissionDeadlineAt,
            'questions_rfp_deadline_at' => $questionsRfpDeadlineAt,
            'rfp_submission_deadline_at' => $rfpSubmissionDeadlineAt,
            'award_date_at' => $awardDateAt,
            'status' => 'ACTIVE',
            'cpv_code' => '72000000',
            'archived_at' => $archived ? now() : null,
            'bid_status' => $bidStatus,
        ]);

        if ($updatedAt !== null) {
            $notice->timestamps = false;
            $notice->forceFill([
                'updated_at' => Carbon::parse($updatedAt),
            ])->saveQuietly();
            $notice->timestamps = true;
        }

        return $notice;
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
