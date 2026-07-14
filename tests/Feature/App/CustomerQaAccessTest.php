<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * QA is an additive capability layered on top of a user's ordinary bid_role (never a
 * replacement for it) — stored directly on `users.is_qa` since a user belongs to exactly one
 * customer (users.customer_id), so the flag is already customer-scoped the same way bid_role is.
 *
 * Covers: QA assignment via the existing "Brukere" tab mechanism (UserController), effective
 * permission combination (ordinary role + QA + "Alle"), and the "Tilganger" tab QA column
 * (CustomerEnvironmentController::permissionSettingsPayload()/updatePermissions()).
 */
class CustomerQaAccessTest extends TestCase
{
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->withoutMiddleware([VerifyCsrfToken::class, ValidateCsrfToken::class]);
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

    // =========================================================================
    // QA assignment (Kundemiljø → Brukere)
    // =========================================================================

    public function test_system_owner_can_grant_qa_to_a_user(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $target = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);

        $this->actingAs($owner)->put("/app/users/{$target->id}", $this->updatePayload($target, ['is_qa' => true]))
            ->assertRedirect();

        $this->assertTrue($target->fresh()->is_qa);
    }

    public function test_qa_can_be_removed_again(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $target = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR, isQa: true);

        $this->actingAs($owner)->put("/app/users/{$target->id}", $this->updatePayload($target, ['is_qa' => false]));

        $this->assertFalse($target->fresh()->is_qa);
    }

    public function test_granting_qa_does_not_change_the_ordinary_role(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $target = $this->createUser($customer, User::BID_ROLE_BID_MANAGER);

        $this->actingAs($owner)->put("/app/users/{$target->id}", $this->updatePayload($target, [
            'bid_role' => User::BID_ROLE_BID_MANAGER,
            'is_qa' => true,
        ]));

        $fresh = $target->fresh();
        $this->assertTrue($fresh->is_qa);
        $this->assertSame(User::BID_ROLE_BID_MANAGER, $fresh->resolvedBidRole());
    }

    public function test_removing_qa_does_not_change_the_ordinary_role(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $target = $this->createUser($customer, User::BID_ROLE_BID_MANAGER, isQa: true);

        $this->actingAs($owner)->put("/app/users/{$target->id}", $this->updatePayload($target, [
            'bid_role' => User::BID_ROLE_BID_MANAGER,
            'is_qa' => false,
        ]));

        $fresh = $target->fresh();
        $this->assertFalse($fresh->is_qa);
        $this->assertSame(User::BID_ROLE_BID_MANAGER, $fresh->resolvedBidRole());
    }

    public function test_qa_is_scoped_to_the_users_own_customer(): void
    {
        $customerA = $this->createCustomer('Kunde A AS');
        $customerB = $this->createCustomer('Kunde B AS');
        $ownerA = $this->createUser($customerA, User::BID_ROLE_SYSTEM_OWNER);
        $targetA = $this->createUser($customerA, User::BID_ROLE_CONTRIBUTOR);
        $targetB = $this->createUser($customerB, User::BID_ROLE_CONTRIBUTOR);

        $this->actingAs($ownerA)->put("/app/users/{$targetA->id}", $this->updatePayload($targetA, ['is_qa' => true]));

        $this->assertTrue($targetA->fresh()->is_qa);
        $this->assertFalse($targetB->fresh()->is_qa);
    }

    public function test_user_in_another_customer_cannot_be_changed(): void
    {
        $customerA = $this->createCustomer('Kunde A AS');
        $customerB = $this->createCustomer('Kunde B AS');
        $ownerA = $this->createUser($customerA, User::BID_ROLE_SYSTEM_OWNER);
        $targetB = $this->createUser($customerB, User::BID_ROLE_CONTRIBUTOR);

        $this->actingAs($ownerA)->put("/app/users/{$targetB->id}", $this->updatePayload($targetB, ['is_qa' => true]))
            ->assertNotFound();

        $this->assertFalse($targetB->fresh()->is_qa);
    }

    public function test_unauthorized_user_cannot_assign_qa(): void
    {
        $customer = $this->createCustomer();
        $bidManager = $this->createUser($customer, User::BID_ROLE_BID_MANAGER, bidManagerScope: User::BID_MANAGER_SCOPE_COMPANY);
        $target = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);

        $this->actingAs($bidManager)->put("/app/users/{$target->id}", $this->updatePayload($target, ['is_qa' => true]))
            ->assertForbidden();

        $this->assertFalse($target->fresh()->is_qa);
    }

    public function test_bid_manager_cannot_submit_is_qa_when_creating_a_user(): void
    {
        $customer = $this->createCustomer();
        $bidManager = $this->createUser($customer, User::BID_ROLE_BID_MANAGER, bidManagerScope: User::BID_MANAGER_SCOPE_COMPANY);

        $response = $this->actingAs($bidManager)->post('/app/users', [
            'name' => 'Ny bruker',
            'email' => 'ny.bruker@example.test',
            'password' => 'SecretPass123!',
            'password_confirmation' => 'SecretPass123!',
            'is_qa' => true,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'ny.bruker@example.test']);
    }

    // =========================================================================
    // Effective permissions
    // =========================================================================

    public function test_system_owner_has_full_access_without_qa(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER, isQa: false);

        $this->assertTrue($owner->canApproveWikiClaims());
    }

    public function test_bid_manager_without_qa_does_not_get_qa_permission(): void
    {
        $customer = $this->createCustomer();
        $bidManager = $this->createUser($customer, User::BID_ROLE_BID_MANAGER, isQa: false);

        $this->assertFalse($bidManager->canApproveWikiClaims());
    }

    public function test_bid_manager_with_qa_gets_both_bid_manager_and_qa_permissions(): void
    {
        $customer = $this->createCustomer();
        $bidManagerWithQa = $this->createUser($customer, User::BID_ROLE_BID_MANAGER, isQa: true);

        // QA permission (approve_wiki_claims default roles: system_owner, qa)
        $this->assertTrue($bidManagerWithQa->canApproveWikiClaims());
        // Ordinary bid_manager role permission (create_users default includes bid_manager)
        $this->assertTrue($bidManagerWithQa->canManageCustomerUsers());
    }

    public function test_contributor_without_qa_does_not_get_qa_permission(): void
    {
        $customer = $this->createCustomer();
        $contributor = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR, isQa: false);

        $this->assertFalse($contributor->canApproveWikiClaims());
    }

    public function test_contributor_with_qa_gets_both_contributor_and_qa_permissions(): void
    {
        $customer = $this->createCustomer();
        $contributorWithQa = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR, isQa: true);

        $this->assertTrue($contributorWithQa->canApproveWikiClaims());
        // Ordinary contributor role permission (create_users default includes contributor)
        $this->assertTrue($contributorWithQa->canManageCustomerUsers());
    }

    public function test_all_permission_still_grants_access_regardless_of_role_or_qa(): void
    {
        $customer = $this->createCustomer();
        $customer->permission_settings = array_merge($customer->resolvedPermissionSettings(), [
            Customer::PERMISSION_APPROVE_WIKI_CLAIMS => ['system_owner', 'all'],
        ]);
        $customer->save();

        $viewerWithoutQa = $this->createUser($customer, User::BID_ROLE_VIEWER, isQa: false);

        $this->assertTrue($viewerWithoutQa->fresh()->canApproveWikiClaims());
    }

    public function test_removing_qa_removes_the_qa_permission_without_touching_the_ordinary_role_permission(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $contributor = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR, isQa: true);

        $this->assertTrue($contributor->canApproveWikiClaims());

        $this->actingAs($owner)->put("/app/users/{$contributor->id}", $this->updatePayload($contributor, ['is_qa' => false]));

        $fresh = $contributor->fresh();
        $this->assertFalse($fresh->canApproveWikiClaims());
        // create_users default still includes contributor — unaffected by removing QA.
        $this->assertTrue($fresh->canManageCustomerUsers());
    }

    // =========================================================================
    // Tilganger tab: QA column
    // =========================================================================

    public function test_permission_gallery_includes_qa_column_and_approve_wiki_claims_row(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $response = $this->actingAs($owner)->get('/app/customer-environment?tab=permissions');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia): bool {
            $settings = data_get($inertia, 'props.permissionSettings');
            $roleValues = collect(data_get($settings, 'role_columns', []))->pluck('value')->all();
            $rowKeys = collect(data_get($settings, 'permission_rows', []))->pluck('key')->all();
            $approveRow = collect(data_get($settings, 'permission_rows', []))
                ->firstWhere('key', Customer::PERMISSION_APPROVE_WIKI_CLAIMS);

            return in_array('qa', $roleValues, true)
                && in_array(Customer::PERMISSION_APPROVE_WIKI_CLAIMS, $rowKeys, true)
                && in_array('system_owner', $approveRow['roles'] ?? [], true)
                && in_array('qa', $approveRow['roles'] ?? [], true)
                && ! in_array('bid_manager', $approveRow['roles'] ?? [], true)
                && ! in_array('contributor', $approveRow['roles'] ?? [], true);
        });
    }

    public function test_system_owner_can_configure_qa_column_through_existing_permissions_mechanism(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $this->actingAs($owner)->patch('/app/customer-environment/permissions', [
            'permission' => Customer::PERMISSION_CREATE_DEPARTMENTS,
            'roles' => ['qa'],
        ])->assertRedirect();

        $settings = $customer->fresh()->resolvedPermissionSettings();

        $this->assertContains('qa', $settings[Customer::PERMISSION_CREATE_DEPARTMENTS]);
        // System Owner is force-included by the controller regardless of submitted roles.
        $this->assertContains('system_owner', $settings[Customer::PERMISSION_CREATE_DEPARTMENTS]);
    }

    // =========================================================================
    // UI: is_qa / can_approve_wiki_claims props
    // =========================================================================

    public function test_users_list_marks_qa_users_with_is_qa_true(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $qaContributor = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR, isQa: true);
        $plainContributor = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR, isQa: false);

        $response = $this->actingAs($owner)->get('/app/users');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($qaContributor, $plainContributor): bool {
            $users = collect(data_get($inertia, 'props.users', []))->keyBy('id');

            return ($users->get($qaContributor->id)['is_qa'] ?? null) === true
                && ($users->get($plainContributor->id)['is_qa'] ?? null) === false;
        });
    }

    public function test_auth_user_props_expose_is_qa_and_can_approve_wiki_claims_matching_backend_authorization(): void
    {
        $customer = $this->createCustomer();
        $qaContributor = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR, isQa: true);
        $plainContributor = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR, isQa: false);

        $this->actingAs($qaContributor)->get('/app/dashboard')->assertViewHas('page', function (array $inertia): bool {
            return data_get($inertia, 'props.auth.user.is_qa') === true
                && data_get($inertia, 'props.auth.user.can_approve_wiki_claims') === true;
        });

        $this->actingAs($plainContributor)->get('/app/dashboard')->assertViewHas('page', function (array $inertia): bool {
            return data_get($inertia, 'props.auth.user.is_qa') === false
                && data_get($inertia, 'props.auth.user.can_approve_wiki_claims') === false;
        });

        // Frontend gating for claim actions reads exactly this prop — confirm it matches the
        // model method the backend endpoint itself authorizes against.
        $this->assertTrue($qaContributor->fresh()->canApproveWikiClaims());
        $this->assertFalse($plainContributor->fresh()->canApproveWikiClaims());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createCustomer(string $name = 'QA Test AS'): Customer
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
            'subscription_plan' => Customer::PLAN_MAX,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'included_users' => 10,
            'included_ai_credits' => 20,
        ]);
    }

    private function createUser(
        Customer $customer,
        string $bidRole,
        bool $isQa = false,
        ?string $bidManagerScope = null,
    ): User {
        return User::factory()->create([
            'role' => User::customerRoleForBidRole($bidRole),
            'bid_role' => $bidRole,
            'is_qa' => $isQa,
            'bid_manager_scope' => $bidRole === User::BID_ROLE_BID_MANAGER ? ($bidManagerScope ?? User::BID_MANAGER_SCOPE_COMPANY) : null,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    /**
     * A full valid updateValidationRules() payload for $target, with $overrides applied —
     * mirrors the "Rediger bruker" form's real field set so PUT requests validate cleanly.
     */
    private function updatePayload(User $target, array $overrides = []): array
    {
        $bidRole = $overrides['bid_role'] ?? $target->resolvedBidRole();

        return array_merge([
            'name' => $target->name,
            'bid_role' => $bidRole,
            'is_qa' => (bool) $target->is_qa,
            'bid_manager_scope' => $bidRole === User::BID_ROLE_BID_MANAGER
                ? ($target->resolvedBidManagerScope() ?? User::BID_MANAGER_SCOPE_COMPANY)
                : null,
            'primary_affiliation_scope' => User::PRIMARY_AFFILIATION_SCOPE_COMPANY,
            'department_ids' => [],
        ], $overrides);
    }
}
