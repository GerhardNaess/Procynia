<?php

namespace Tests\Feature\App;

use App\Http\Controllers\App\WikiController;
use App\Models\Customer;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * QA and Wiki approver are two separate supplemental capabilities, and neither implies the other.
 *
 * WHAT WENT WRONG IN PRACTICE. An administrator wanted a colleague to publish Wiki pages. The only
 * supplemental capability a user could be given was QA, so the page permission had to be granted to
 * the QA column — which meant every QA user became a publisher, and the two decisions could not be
 * told apart. Approving a claim says one statement is supported by its source; approving a page
 * publishes it.
 *
 * Wiki approver is now its own capability, modelled exactly as QA is: a boolean on the user, layered
 * on top of their ordinary bid_role, with its own column in the access matrix. A user can hold
 * either, both or neither, and the matrix stays the authoritative place where a capability is mapped
 * to a permission.
 */
class WikiPageApprovalPermissionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    // =========================================================================
    // The permission is administered in Tilganger
    // =========================================================================

    public function test_the_permission_appears_in_the_access_grid(): void
    {
        $customer = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);

        $this->actingAs($owner)
            ->get('/app/customer-environment?tab=permissions')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where(
                'permissionSettings.permission_rows',
                fn ($rows) => collect($rows)->contains('key', Customer::PERMISSION_APPROVE_WIKI_PAGES),
            ));
    }

    public function test_the_two_wiki_rows_cannot_be_mistaken_for_each_other(): void
    {
        $customer = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);

        $rows = collect(
            $this->actingAs($owner)
                ->get('/app/customer-environment?tab=permissions')
                ->assertOk()
                ->viewData('page')['props']['permissionSettings']['permission_rows'],
        )->keyBy('key');

        $pages = $rows[Customer::PERMISSION_APPROVE_WIKI_PAGES];
        $claims = $rows[Customer::PERMISSION_APPROVE_WIKI_CLAIMS];

        $this->assertSame('Godkjenne og publisere Wiki-sider', $pages['label']);
        $this->assertSame('Kan gjennomgå, godkjenne og publisere Wiki-sider som er sendt til gjennomgang.', $pages['description']);

        // The claim row has to say what it is NOT, since that is the confusion being fixed.
        $this->assertSame('Godkjenne Wiki-påstander', $claims['label']);
        $this->assertStringContainsString('publisere sider', $claims['description']);
        $this->assertNotSame($pages['label'], $claims['label']);
    }

    public function test_the_english_labels_say_the_same_thing(): void
    {
        $this->assertSame('Approve and publish Wiki pages', __('procynia.wiki.permission_approve_wiki_pages', [], 'en'));
        $this->assertSame(
            'Can review, approve, and publish Wiki pages submitted for review.',
            __('procynia.wiki.permission_approve_wiki_pages_help', [], 'en'),
        );
        $this->assertSame('Approve Wiki claims', __('procynia.wiki.permission_approve_wiki_claims', [], 'en'));
    }

    // =========================================================================
    // Granting and revoking it
    // =========================================================================

    public function test_an_administrator_can_grant_it_and_it_takes_effect(): void
    {
        $customer = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $colleague = $this->user($customer, User::BID_ROLE_CONTRIBUTOR);

        $this->assertFalse($colleague->canApproveWikiPages(), 'not by default');

        $this->actingAs($owner)->patch('/app/customer-environment/permissions', [
            'permission' => Customer::PERMISSION_APPROVE_WIKI_PAGES,
            'roles' => ['contributor'],
        ])->assertRedirect();

        $this->assertTrue($this->fresh($colleague)->canApproveWikiPages());
    }

    public function test_the_grant_is_persisted(): void
    {
        $customer = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);

        $this->actingAs($owner)->patch('/app/customer-environment/permissions', [
            'permission' => Customer::PERMISSION_APPROVE_WIKI_PAGES,
            'roles' => ['contributor'],
        ]);

        $roles = $customer->fresh()->resolvedPermissionSettings()[Customer::PERMISSION_APPROVE_WIKI_PAGES];

        $this->assertContains('contributor', $roles);
        $this->assertContains('system_owner', $roles, 'System Owner keeps it whatever is ticked');
    }

    public function test_revoking_it_takes_effect_too(): void
    {
        $customer = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $colleague = $this->user($customer, User::BID_ROLE_CONTRIBUTOR);

        $this->actingAs($owner)->patch('/app/customer-environment/permissions', [
            'permission' => Customer::PERMISSION_APPROVE_WIKI_PAGES,
            'roles' => ['contributor'],
        ]);
        $this->assertTrue($this->fresh($colleague)->canApproveWikiPages());

        // Unticking the last box sends an empty array. That has to be accepted, or the permission
        // can be granted but never taken back.
        $this->actingAs($owner)->patch('/app/customer-environment/permissions', [
            'permission' => Customer::PERMISSION_APPROVE_WIKI_PAGES,
            'roles' => [],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertFalse($this->fresh($colleague)->canApproveWikiPages());
    }

    // =========================================================================
    // QA stays a different thing
    // =========================================================================

    /**
     * The four combinations, which are the whole point of the split. Run against the shipped
     * defaults, so this also pins down that the defaults keep the two apart.
     *
     * @dataProvider capabilityCombinations
     */
    #[DataProvider('capabilityCombinations')]
    public function test_the_two_capabilities_are_independent(bool $isQa, bool $isWikiApprover, bool $claims, bool $pages): void
    {
        $customer = $this->customer();
        $user = $this->user($customer, User::BID_ROLE_CONTRIBUTOR, isQa: $isQa, isWikiApprover: $isWikiApprover);

        $this->assertSame($claims, $user->canApproveWikiClaims(), 'claim approval');
        $this->assertSame($pages, $user->canApproveWikiPages(), 'page approval');
    }

    /** @return array<string, array{bool, bool, bool, bool}> */
    public static function capabilityCombinations(): array
    {
        return [
            'neither' => [false, false, false, false],
            'QA only' => [true, false, true, false],
            'Wiki approver only' => [false, true, false, true],
            'both' => [true, true, true, true],
        ];
    }

    public function test_a_system_owner_holds_both_without_either_capability(): void
    {
        $owner = $this->user($this->customer(), User::BID_ROLE_SYSTEM_OWNER);

        $this->assertFalse($owner->isQa());
        $this->assertFalse($owner->isWikiApprover());
        $this->assertTrue($owner->canApproveWikiClaims());
        $this->assertTrue($owner->canApproveWikiPages());
    }

    // =========================================================================
    // The capability as a supplemental role
    // =========================================================================

    public function test_it_combines_with_an_ordinary_role_rather_than_replacing_it(): void
    {
        $user = $this->user($this->customer(), User::BID_ROLE_CONTRIBUTOR, isQa: true, isWikiApprover: true);

        $this->assertSame(User::BID_ROLE_CONTRIBUTOR, $user->bid_role, 'the ordinary role is untouched');
        $this->assertTrue($user->isQa());
        $this->assertTrue($user->isWikiApprover());
    }

    public function test_it_is_not_offered_as_a_primary_role(): void
    {
        $this->assertNotContains(Customer::ROLE_WIKI_APPROVER, User::BID_ROLES);
        $this->assertNotContains(Customer::ROLE_QA, User::BID_ROLES);
    }

    public function test_an_administrator_can_add_and_remove_it_without_touching_qa(): void
    {
        $customer = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $colleague = $this->user($customer, User::BID_ROLE_CONTRIBUTOR, isQa: true);

        $this->actingAs($owner)->put("/app/users/{$colleague->id}", $this->userPayload($colleague, isQa: true, isWikiApprover: true))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($this->fresh($colleague)->isWikiApprover());
        $this->assertTrue($this->fresh($colleague)->isQa(), 'adding one must not disturb the other');

        $this->actingAs($owner)->put("/app/users/{$colleague->id}", $this->userPayload($colleague, isQa: true, isWikiApprover: false))
            ->assertRedirect();
        $this->assertFalse($this->fresh($colleague)->isWikiApprover());
        $this->assertTrue($this->fresh($colleague)->isQa(), 'removing one must not disturb the other');
    }

    public function test_removing_qa_leaves_the_wiki_approver_capability_alone(): void
    {
        $customer = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $colleague = $this->user($customer, User::BID_ROLE_CONTRIBUTOR, isQa: true, isWikiApprover: true);

        $this->actingAs($owner)->put("/app/users/{$colleague->id}", $this->userPayload($colleague, isQa: false, isWikiApprover: true))
            ->assertRedirect();

        $colleague = $this->fresh($colleague);
        $this->assertFalse($colleague->isQa());
        $this->assertTrue($colleague->isWikiApprover());
    }

    public function test_a_user_can_hold_neither(): void
    {
        $customer = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $colleague = $this->user($customer, User::BID_ROLE_CONTRIBUTOR, isQa: true, isWikiApprover: true);

        $this->actingAs($owner)->put("/app/users/{$colleague->id}", $this->userPayload($colleague, isQa: false, isWikiApprover: false))
            ->assertRedirect();

        $colleague = $this->fresh($colleague);
        $this->assertFalse($colleague->isQa());
        $this->assertFalse($colleague->isWikiApprover());
    }

    // =========================================================================
    // The matrix maps capabilities to permissions
    // =========================================================================

    public function test_the_matrix_offers_a_column_of_its_own(): void
    {
        $customer = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);

        $columns = collect(
            $this->actingAs($owner)
                ->get('/app/customer-environment?tab=permissions')
                ->assertOk()
                ->viewData('page')['props']['permissionSettings']['role_columns'],
        );

        $this->assertNotNull($columns->firstWhere('value', Customer::ROLE_WIKI_APPROVER));
        $this->assertNotNull($columns->firstWhere('value', Customer::ROLE_QA), 'QA keeps its own');
        $this->assertSame('Wiki-godkjenner', $columns->firstWhere('value', Customer::ROLE_WIKI_APPROVER)['label']);
    }

    public function test_the_defaults_map_each_decision_to_its_own_capability(): void
    {
        $defaults = Customer::DEFAULT_PERMISSION_SETTINGS;

        $this->assertSame(['system_owner', Customer::ROLE_QA], $defaults[Customer::PERMISSION_APPROVE_WIKI_CLAIMS]);
        $this->assertSame(['system_owner', Customer::ROLE_WIKI_APPROVER], $defaults[Customer::PERMISSION_APPROVE_WIKI_PAGES]);
    }

    /** The matrix stays authoritative: a customer may remap either capability. */
    public function test_a_customer_can_remap_the_capability(): void
    {
        $customer = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $approver = $this->user($customer, User::BID_ROLE_CONTRIBUTOR, isWikiApprover: true);

        $this->assertTrue($this->fresh($approver)->canApproveWikiPages());

        $this->actingAs($owner)->patch('/app/customer-environment/permissions', [
            'permission' => Customer::PERMISSION_APPROVE_WIKI_PAGES,
            'roles' => [],
        ])->assertRedirect();

        $this->assertFalse($this->fresh($approver)->canApproveWikiPages(), 'the matrix decides, not the flag alone');
    }

    public function test_qa_alone_does_not_let_anyone_publish_a_page(): void
    {
        $customer = $this->customer();
        $qa = $this->user($customer, User::BID_ROLE_CONTRIBUTOR, isQa: true);

        $this->assertTrue($qa->canApproveWikiClaims(), 'QA does carry the claim decision');
        $this->assertFalse($qa->canApproveWikiPages(), 'but never the page decision');
    }

    public function test_granting_page_approval_does_not_hand_out_claim_approval(): void
    {
        $customer = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $colleague = $this->user($customer, User::BID_ROLE_CONTRIBUTOR);

        $this->actingAs($owner)->patch('/app/customer-environment/permissions', [
            'permission' => Customer::PERMISSION_APPROVE_WIKI_PAGES,
            'roles' => ['contributor'],
        ]);

        $colleague = $this->fresh($colleague);
        $this->assertTrue($colleague->canApproveWikiPages());
        $this->assertFalse($colleague->canApproveWikiClaims(), 'the two are granted separately');
    }

    /**
     * Deploying this must not quietly promote the QA users a customer already has. The default now
     * names the Wiki approver capability, and nobody holds that until an administrator grants it —
     * so the default grants nothing on its own, and QA is not in it.
     */
    public function test_an_existing_qa_user_is_not_promoted_by_the_defaults(): void
    {
        $default = Customer::DEFAULT_PERMISSION_SETTINGS[Customer::PERMISSION_APPROVE_WIKI_PAGES];

        $this->assertNotContains(Customer::ROLE_QA, $default);

        $qa = $this->user($this->customer(), User::BID_ROLE_CONTRIBUTOR, isQa: true);
        $this->assertFalse($qa->canApproveWikiPages());
    }

    // =========================================================================
    // The flow this unblocks
    // =========================================================================

    /**
     * The reported dead end: the only person who could approve was the only person who could
     * submit, and nobody may approve what they submitted themselves — so the page could not be
     * handed to anyone.
     */
    public function test_granting_it_unblocks_sending_a_page_for_review(): void
    {
        $customer = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $colleague = $this->user($customer, User::BID_ROLE_CONTRIBUTOR);
        $page = $this->draftPage($customer, $owner);

        $this->assertSame([], $this->reviewerOptions($owner, $page), 'nobody to hand it to');

        $this->actingAs($owner)->patch('/app/customer-environment/permissions', [
            'permission' => Customer::PERMISSION_APPROVE_WIKI_PAGES,
            'roles' => ['contributor'],
        ]);

        $options = $this->reviewerOptions($owner, $page->fresh());

        $this->assertSame([$colleague->id], array_column($options, 'id'));
    }

    public function test_the_page_can_then_actually_be_published(): void
    {
        $customer = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $colleague = $this->user($customer, User::BID_ROLE_CONTRIBUTOR);
        $page = $this->draftPage($customer, $owner);
        $version = $page->currentVersion()->first();

        $this->actingAs($owner)->patch('/app/customer-environment/permissions', [
            'permission' => Customer::PERMISSION_APPROVE_WIKI_PAGES,
            'roles' => ['contributor'],
        ]);

        $this->actingAs($owner)
            ->patch("/app/wiki/{$page->slug}/submit", ['reviewer_user_id' => $colleague->id])
            ->assertRedirect();

        $this->actingAs($this->fresh($colleague))
            ->patch("/app/wiki/{$page->slug}/approve")
            ->assertRedirect();

        $page->refresh();
        $this->assertSame((int) $version->id, (int) $page->published_version_id);
        $this->assertSame(EnterpriseWikiPage::STATUS_APPROVED, $page->status);
    }

    public function test_a_qa_only_colleague_never_becomes_an_eligible_reviewer(): void
    {
        $customer = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $this->user($customer, User::BID_ROLE_CONTRIBUTOR, isQa: true);
        $page = $this->draftPage($customer, $owner);

        $this->assertSame([], $this->reviewerOptions($owner, $page));
    }

    // =========================================================================
    // Tenant isolation
    // =========================================================================

    public function test_granting_it_never_reaches_another_customer(): void
    {
        $ourCustomer = $this->customer();
        $owner = $this->user($ourCustomer, User::BID_ROLE_SYSTEM_OWNER);
        $otherCustomer = $this->customer();
        $stranger = $this->user($otherCustomer, User::BID_ROLE_CONTRIBUTOR);

        $this->actingAs($owner)->patch('/app/customer-environment/permissions', [
            'permission' => Customer::PERMISSION_APPROVE_WIKI_PAGES,
            'roles' => ['contributor'],
        ]);

        $this->assertFalse($this->fresh($stranger)->canApproveWikiPages(), 'settings are per customer');
    }

    public function test_a_reviewer_from_another_customer_is_never_offered(): void
    {
        $ourCustomer = $this->customer();
        $owner = $this->user($ourCustomer, User::BID_ROLE_SYSTEM_OWNER);
        $this->user($this->customer(), User::BID_ROLE_SYSTEM_OWNER);

        $this->assertSame([], $this->reviewerOptions($owner, $this->draftPage($ourCustomer, $owner)));
    }

    public function test_only_a_system_owner_may_change_the_grid(): void
    {
        $customer = $this->customer();
        $colleague = $this->user($customer, User::BID_ROLE_CONTRIBUTOR, isQa: true);

        $this->actingAs($colleague)->patch('/app/customer-environment/permissions', [
            'permission' => Customer::PERMISSION_APPROVE_WIKI_PAGES,
            'roles' => ['contributor'],
        ])->assertForbidden();

        $this->assertFalse($this->fresh($colleague)->canApproveWikiPages());
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    /** @return list<array{id: int, name: string}> */
    private function reviewerOptions(User $actor, EnterpriseWikiPage $page): array
    {
        $method = new \ReflectionMethod(WikiController::class, 'eligibleReviewerOptions');
        $method->setAccessible(true);

        return $method->invoke(app(WikiController::class), $page, $this->fresh($actor));
    }

    /**
     * The user-update payload, carrying only what this file is about. The endpoint expects the
     * whole form, so the unrelated fields are echoed back from the record unchanged.
     *
     * @return array<string, mixed>
     */
    private function userPayload(User $user, bool $isQa, bool $isWikiApprover): array
    {
        // Only what update() accepts: email and is_active are 'prohibited' there, and are changed
        // through their own endpoints.
        return [
            'name' => $user->name,
            'bid_role' => $user->bid_role,
            'is_qa' => $isQa,
            'is_wiki_approver' => $isWikiApprover,
        ];
    }

    private function fresh(User $user): User
    {
        return User::query()->with('customer')->findOrFail($user->id);
    }

    private function draftPage(Customer $customer, User $owner): EnterpriseWikiPage
    {
        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'perm-'.Str::lower(Str::random(6)),
            'title' => 'Google Cloud',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ENTITY,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('h', 64, '0'),
            'owner_user_id' => $owner->id,
        ]);

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => "# Google Cloud\n\nTekst.",
            'generated_by_model' => 'gpt-5',
        ]);

        return $page->fresh();
    }

    private function user(Customer $customer, string $bidRole, bool $isQa = false, bool $isWikiApprover = false): User
    {
        return User::query()->create([
            'name' => 'Tilgang '.Str::random(4),
            'email' => Str::lower(Str::random(10)).'@wiki-permission.invalid',
            'password' => bcrypt('secret'),
            'role' => $bidRole === User::BID_ROLE_SYSTEM_OWNER ? User::ROLE_CUSTOMER_ADMIN : User::ROLE_USER,
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
            'name' => 'Wiki Permission AS',
            'slug' => 'wiki-permission-'.Str::lower(Str::random(8)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);
    }
}
