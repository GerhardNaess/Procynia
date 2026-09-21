<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\Notice;
use App\Models\SavedNotice;
use App\Models\User;
use App\Services\SavedNoticeAccessService;
use App\Support\CustomerContext;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Kommersiell eier" as a main role, beside Bid Manager rather than beside QA.
 *
 * THE TWO HALVES, AND WHY BOTH EXIST. The role says a person does commercial work in the
 * organisation — prioritisation, price, go/no-go. saved_notices.opportunity_owner_user_id says
 * which of them carries a particular case. Bid Manager has worked this way all along, and the
 * commercial side now reads the same.
 *
 * The role grants nothing by itself. Everything a commercial owner may do comes from the access
 * matrix, and the defaults give them nothing — introducing the role must not hand anyone new access
 * on deploy. Case-level access is a separate, unchanged path: being the commercial owner of a case
 * has always granted access to THAT case, and still does.
 */
class CommercialOwnerRoleTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    // =========================================================================
    // A main role, not a capability
    // =========================================================================

    public function test_it_is_a_main_role(): void
    {
        $this->assertContains(User::BID_ROLE_COMMERCIAL_OWNER, User::BID_ROLES);
        $this->assertSame('commercial_owner', User::BID_ROLE_COMMERCIAL_OWNER);
    }

    public function test_it_reads_correctly_in_both_languages(): void
    {
        app()->setLocale('no');
        $this->assertSame('Kommersiell eier', User::bidRoleOptions()[User::BID_ROLE_COMMERCIAL_OWNER]);

        app()->setLocale('en');
        $this->assertSame('Commercial owner', User::bidRoleOptions()[User::BID_ROLE_COMMERCIAL_OWNER]);
    }

    public function test_a_system_owner_is_offered_it_when_editing_a_user(): void
    {
        $customer = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $colleague = $this->user($customer, User::BID_ROLE_CONTRIBUTOR);

        $options = $this->actingAs($owner)
            ->get("/app/users/{$colleague->id}/edit")
            ->assertOk()
            ->viewData('page')['props']['bidRoleOptions'];

        $this->assertContains(User::BID_ROLE_COMMERCIAL_OWNER, array_column($options, 'value'));
    }

    public function test_a_user_can_be_made_a_commercial_owner_and_back_again(): void
    {
        $customer = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $colleague = $this->user($customer, User::BID_ROLE_CONTRIBUTOR);

        $this->actingAs($owner)
            ->put("/app/users/{$colleague->id}", $this->userPayload($colleague, User::BID_ROLE_COMMERCIAL_OWNER))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(User::BID_ROLE_COMMERCIAL_OWNER, $this->fresh($colleague)->bid_role);
        $this->assertTrue($this->fresh($colleague)->isCommercialOwner());

        $this->actingAs($owner)
            ->put("/app/users/{$colleague->id}", $this->userPayload($colleague, User::BID_ROLE_CONTRIBUTOR))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(User::BID_ROLE_CONTRIBUTOR, $this->fresh($colleague)->bid_role);
        $this->assertFalse($this->fresh($colleague)->isCommercialOwner());
    }

    /** It replaces the ordinary role; QA and Wiki approver still layer on top of it. */
    public function test_it_combines_with_the_supplemental_capabilities(): void
    {
        $customer = $this->customer();

        foreach ([[false, false], [true, false], [false, true], [true, true]] as [$isQa, $isWikiApprover]) {
            $user = $this->user($customer, User::BID_ROLE_COMMERCIAL_OWNER, $isQa, $isWikiApprover);

            $this->assertTrue($user->isCommercialOwner(), 'the main role is unaffected');
            $this->assertSame($isQa, $user->isQa());
            $this->assertSame($isWikiApprover, $user->isWikiApprover());
        }
    }

    // =========================================================================
    // The role grants nothing on its own
    // =========================================================================

    public function test_the_defaults_give_it_no_permissions_at_all(): void
    {
        foreach (Customer::DEFAULT_PERMISSION_SETTINGS as $permission => $roles) {
            $this->assertNotContains(
                User::BID_ROLE_COMMERCIAL_OWNER,
                $roles,
                "{$permission} must not be granted on deploy",
            );
        }
    }

    public function test_a_fresh_commercial_owner_can_do_nothing_extra(): void
    {
        $user = $this->user($this->customer(), User::BID_ROLE_COMMERCIAL_OWNER);

        $this->assertFalse($user->canManageCustomerUsers());
        $this->assertFalse($user->canViewAllCasesViaSettings());
        $this->assertFalse($user->canApproveWikiClaims());
        $this->assertFalse($user->canApproveWikiPages());
    }

    public function test_it_has_its_own_column_in_the_access_matrix(): void
    {
        $customer = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);

        $columns = collect(
            $this->actingAs($owner)
                ->get('/app/customer-environment?tab=permissions')
                ->assertOk()
                ->viewData('page')['props']['permissionSettings']['role_columns'],
        );

        $column = $columns->firstWhere('value', User::BID_ROLE_COMMERCIAL_OWNER);

        $this->assertNotNull($column);
        $this->assertSame('Kommersiell eier', $column['label']);
        $this->assertFalse($column['locked'], 'a System Owner must be able to tick it');
    }

    // =========================================================================
    // Permissions are granted from the matrix, and take real effect
    // =========================================================================

    public function test_creating_users_can_be_granted_and_taken_back(): void
    {
        $customer = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $commercial = $this->user($customer, User::BID_ROLE_COMMERCIAL_OWNER);

        // Refused while the matrix says no — the real endpoint, not just the flag.
        $this->actingAs($this->fresh($commercial))->get('/app/users/create')->assertForbidden();

        $this->grant($owner, Customer::PERMISSION_CREATE_USERS, ['contributor', User::BID_ROLE_COMMERCIAL_OWNER]);

        $this->assertTrue($this->fresh($commercial)->canManageCustomerUsers());
        $this->actingAs($this->fresh($commercial))->get('/app/users/create')->assertOk();

        $this->grant($owner, Customer::PERMISSION_CREATE_USERS, ['contributor']);

        $this->assertFalse($this->fresh($commercial)->canManageCustomerUsers());
        $this->actingAs($this->fresh($commercial))->get('/app/users/create')->assertForbidden();
    }

    public function test_seeing_all_cases_can_be_granted_and_taken_back(): void
    {
        $customer = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $commercial = $this->user($customer, User::BID_ROLE_COMMERCIAL_OWNER);
        $someoneElsesCase = $this->savedNotice($customer, $owner);

        $this->assertFalse($this->visibleCaseIds($commercial)->contains($someoneElsesCase->id));

        $this->grant($owner, Customer::PERMISSION_VIEW_ALL_CASES, [User::BID_ROLE_COMMERCIAL_OWNER]);

        $this->assertTrue($this->fresh($commercial)->canViewAllCasesViaSettings());
        $this->assertTrue($this->visibleCaseIds($commercial)->contains($someoneElsesCase->id));

        $this->grant($owner, Customer::PERMISSION_VIEW_ALL_CASES, []);

        $this->assertFalse($this->fresh($commercial)->canViewAllCasesViaSettings());
        $this->assertFalse($this->visibleCaseIds($commercial)->contains($someoneElsesCase->id));
    }

    /** The two are configured independently; neither drags the other along. */
    public function test_the_two_permissions_do_not_imply_each_other(): void
    {
        $customer = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $commercial = $this->user($customer, User::BID_ROLE_COMMERCIAL_OWNER);

        $this->grant($owner, Customer::PERMISSION_CREATE_USERS, [User::BID_ROLE_COMMERCIAL_OWNER]);
        $this->assertTrue($this->fresh($commercial)->canManageCustomerUsers());
        $this->assertFalse($this->fresh($commercial)->canViewAllCasesViaSettings());

        $this->grant($owner, Customer::PERMISSION_CREATE_USERS, []);
        $this->grant($owner, Customer::PERMISSION_VIEW_ALL_CASES, [User::BID_ROLE_COMMERCIAL_OWNER]);
        $this->assertFalse($this->fresh($commercial)->canManageCustomerUsers());
        $this->assertTrue($this->fresh($commercial)->canViewAllCasesViaSettings());
    }

    /** Nothing else follows implicitly from granting one thing. */
    public function test_no_other_permission_comes_along_for_the_ride(): void
    {
        $customer = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $commercial = $this->user($customer, User::BID_ROLE_COMMERCIAL_OWNER);

        $this->grant($owner, Customer::PERMISSION_VIEW_ALL_CASES, [User::BID_ROLE_COMMERCIAL_OWNER]);
        $this->grant($owner, Customer::PERMISSION_CREATE_USERS, [User::BID_ROLE_COMMERCIAL_OWNER]);

        $commercial = $this->fresh($commercial);

        $this->assertFalse(app(CustomerContext::class)->canCreateCustomerDepartments($commercial));
        $this->assertFalse($commercial->canApproveWikiClaims());
        $this->assertFalse($commercial->canApproveWikiPages());
    }

    public function test_the_grant_reaches_only_its_own_customer(): void
    {
        $customer = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $stranger = $this->user($this->customer(), User::BID_ROLE_COMMERCIAL_OWNER);

        $this->grant($owner, Customer::PERMISSION_VIEW_ALL_CASES, [User::BID_ROLE_COMMERCIAL_OWNER]);

        $this->assertFalse($this->fresh($stranger)->canViewAllCasesViaSettings());
    }

    // =========================================================================
    // Who may be the commercial owner OF a case
    // =========================================================================

    public function test_only_a_commercial_owner_is_offered_for_a_case(): void
    {
        $customer = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $commercial = $this->user($customer, User::BID_ROLE_COMMERCIAL_OWNER);
        $contributor = $this->user($customer, User::BID_ROLE_CONTRIBUTOR);
        $bidManager = $this->user($customer, User::BID_ROLE_BID_MANAGER);
        $case = $this->savedNotice($customer, $owner);

        $offered = $this->ownerOptionIds($owner, $case);

        $this->assertContains($commercial->id, $offered);
        $this->assertNotContains($contributor->id, $offered, 'a Contributor is not a commercial owner');
        $this->assertNotContains($bidManager->id, $offered, 'nor is a Bid Manager, by role alone');
    }

    public function test_assigning_a_commercial_owner_works_and_grants_case_access(): void
    {
        $customer = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $commercial = $this->user($customer, User::BID_ROLE_COMMERCIAL_OWNER);
        $case = $this->savedNotice($customer, $owner);

        $this->assertFalse($this->visibleCaseIds($commercial)->contains($case->id));

        $this->actingAs($owner)
            ->patch("/app/notices/saved/{$case->id}/opportunity-owner", ['opportunity_owner_user_id' => $commercial->id])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($commercial->id, (int) $case->fresh()->opportunity_owner_user_id);

        // The case-level access path is unchanged: owning the case still grants access to it,
        // with no view_all_cases involved.
        $this->assertTrue($this->visibleCaseIds($commercial)->contains($case->id));
        $this->assertFalse($this->fresh($commercial)->canViewAllCasesViaSettings());
    }

    public function test_a_contributor_cannot_be_assigned_as_a_new_commercial_owner(): void
    {
        $customer = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $contributor = $this->user($customer, User::BID_ROLE_CONTRIBUTOR);
        $case = $this->savedNotice($customer, $owner);

        $this->actingAs($owner)
            ->patch("/app/notices/saved/{$case->id}/opportunity-owner", ['opportunity_owner_user_id' => $contributor->id])
            ->assertSessionHasErrors('opportunity_owner_user_id');

        $this->assertNull($case->fresh()->opportunity_owner_user_id);
    }

    public function test_another_customers_commercial_owner_is_never_accepted(): void
    {
        $customer = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $stranger = $this->user($this->customer(), User::BID_ROLE_COMMERCIAL_OWNER);
        $case = $this->savedNotice($customer, $owner);

        $this->actingAs($owner)
            ->patch("/app/notices/saved/{$case->id}/opportunity-owner", ['opportunity_owner_user_id' => $stranger->id])
            ->assertSessionHasErrors('opportunity_owner_user_id');

        $this->assertNull($case->fresh()->opportunity_owner_user_id);
    }

    // =========================================================================
    // Cases assigned before the role existed
    // =========================================================================

    /**
     * A case could name anyone as its commercial owner before the role existed. Such an assignment
     * must keep working: the person stays visible, keeps their access, and the value is not cleared
     * behind anyone's back.
     */
    public function test_a_legacy_assignment_survives(): void
    {
        $customer = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $contributor = $this->user($customer, User::BID_ROLE_CONTRIBUTOR);
        $case = $this->savedNotice($customer, $owner);
        $case->forceFill(['opportunity_owner_user_id' => $contributor->id])->save();

        $this->actingAs($owner)->get("/app/notices/saved/{$case->id}")->assertOk();

        $this->assertSame($contributor->id, (int) $case->fresh()->opportunity_owner_user_id);
        $this->assertTrue($this->visibleCaseIds($contributor)->contains($case->id), 'access is unchanged');
    }

    public function test_a_legacy_holder_stays_selectable_on_their_own_case(): void
    {
        $customer = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $contributor = $this->user($customer, User::BID_ROLE_CONTRIBUTOR);
        $case = $this->savedNotice($customer, $owner);
        $case->forceFill(['opportunity_owner_user_id' => $contributor->id])->save();

        // Otherwise the select would render empty and clear the field on the next save.
        $this->assertContains($contributor->id, $this->ownerOptionIds($owner, $case->fresh()));

        // But nowhere else.
        $otherCase = $this->savedNotice($customer, $owner);
        $this->assertNotContains($contributor->id, $this->ownerOptionIds($owner, $otherCase));
    }

    public function test_re_saving_a_legacy_holder_is_allowed_but_moving_to_another_one_is_not(): void
    {
        $customer = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $contributor = $this->user($customer, User::BID_ROLE_CONTRIBUTOR);
        $otherContributor = $this->user($customer, User::BID_ROLE_CONTRIBUTOR);
        $case = $this->savedNotice($customer, $owner);
        $case->forceFill(['opportunity_owner_user_id' => $contributor->id])->save();

        $this->actingAs($owner)
            ->patch("/app/notices/saved/{$case->id}/opportunity-owner", ['opportunity_owner_user_id' => $contributor->id])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($owner)
            ->patch("/app/notices/saved/{$case->id}/opportunity-owner", ['opportunity_owner_user_id' => $otherContributor->id])
            ->assertSessionHasErrors('opportunity_owner_user_id');

        $this->assertSame($contributor->id, (int) $case->fresh()->opportunity_owner_user_id);
    }

    public function test_clearing_a_legacy_assignment_is_allowed(): void
    {
        $customer = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $contributor = $this->user($customer, User::BID_ROLE_CONTRIBUTOR);
        $case = $this->savedNotice($customer, $owner);
        $case->forceFill(['opportunity_owner_user_id' => $contributor->id])->save();

        $this->actingAs($owner)
            ->patch("/app/notices/saved/{$case->id}/opportunity-owner", ['opportunity_owner_user_id' => null])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNull($case->fresh()->opportunity_owner_user_id);
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    private function grant(User $actor, string $permission, array $roles): void
    {
        $this->actingAs($actor)
            ->patch('/app/customer-environment/permissions', ['permission' => $permission, 'roles' => $roles])
            ->assertRedirect()->assertSessionHasNoErrors();
    }

    /** @return Collection<int, int> */
    private function visibleCaseIds(User $user): Collection
    {
        return app(SavedNoticeAccessService::class)
            ->visibleQueryFor($this->fresh($user))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id);
    }

    /** @return list<int> */
    private function ownerOptionIds(User $actor, SavedNotice $case): array
    {
        $props = $this->actingAs($actor)
            ->get("/app/notices/saved/{$case->id}")
            ->assertOk()
            ->viewData('page')['props'];

        return array_map('intval', array_column($props['notice']['actions']['opportunity_owner_options'], 'value'));
    }

    /** @return array<string, mixed> */
    private function userPayload(User $user, string $bidRole): array
    {
        return [
            'name' => $user->name,
            'bid_role' => $bidRole,
            'is_qa' => $user->is_qa,
            'is_wiki_approver' => $user->is_wiki_approver,
        ];
    }

    private function fresh(User $user): User
    {
        return User::query()->with('customer')->findOrFail($user->id);
    }

    private function savedNotice(Customer $customer, User $savedBy): SavedNotice
    {
        $externalId = 'CO-'.Str::upper(Str::random(10));

        Notice::query()->create([
            'notice_id' => $externalId,
            'title' => 'Anbud om drift',
            'description' => 'Drift og forvaltning.',
            'buyer_name' => 'Procynia',
        ]);

        return SavedNotice::query()->create([
            'customer_id' => $customer->id,
            'saved_by_user_id' => $savedBy->id,
            'external_id' => $externalId,
            'title' => 'Anbud om drift',
            'buyer_name' => 'Procynia',
            'source_type' => SavedNotice::SOURCE_TYPE_PUBLIC_NOTICE,
        ]);
    }

    private function user(Customer $customer, string $bidRole, bool $isQa = false, bool $isWikiApprover = false): User
    {
        return User::query()->create([
            'name' => 'Kommersiell '.Str::random(4),
            'email' => Str::lower(Str::random(10)).'@commercial-owner.invalid',
            'password' => bcrypt('secret'),
            'role' => User::customerRoleForBidRole($bidRole),
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
            'is_qa' => $isQa,
            'is_wiki_approver' => $isWikiApprover,
        ]);
    }

    private function customer(): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => 'Kommersiell Eier AS',
            'slug' => 'kommersiell-'.Str::lower(Str::random(8)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);
    }
}
