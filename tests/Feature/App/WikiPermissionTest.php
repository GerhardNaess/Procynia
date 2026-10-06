<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\CustomerPackageEntitlement;
use App\Models\CustomerRole;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\QualityItem;
use App\Models\User;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Enterprise Wiki, gated by the customer's own roles.
 *
 * Six permissions — wiki.view, wiki.edit, wiki.review, wiki.approve, wiki.delete and
 * wiki.source.manage — layered on top of the Wiki's existing authority model rather than replacing
 * any of it. What these tests defend:
 *
 *  - Each permission is enforced on its own and grants exactly what it names. wiki.edit is not
 *    wiki.review, wiki.review is not wiki.approve, and neither reaches sources or deletion.
 *  - A permission never substitutes for object security. The tenant check still runs, page and
 *    document ownership still decide, and the four-eyes rule is untouched — a user with every
 *    permission in the catalogue still cannot delete somebody else's page.
 *  - The pages are told what they may offer, so a control the UI shows is a request the controller
 *    accepts — but the controller is what refuses.
 *  - The seam out of Kvalitet and into Wiki asks for both sides.
 */
class WikiPermissionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    // =========================================================================
    // Scenario 1 — wiki.view alone: reads, changes nothing
    // =========================================================================

    public function test_no_wiki_permission_at_all_closes_the_module(): void
    {
        $customer = $this->customer();
        $user = $this->member($customer);
        $page = $this->page($customer, $user);

        $this->actingAs($user)->get('/app/wiki')->assertForbidden();
        $this->actingAs($user)->get('/app/wiki/'.$page->slug)->assertForbidden();
        $this->actingAs($user)->get('/app/wiki/graph')->assertForbidden();
        $this->actingAs($user)->get('/app/wiki/graph-data')->assertForbidden();
        $this->actingAs($user)->get('/app/wiki/ask')->assertForbidden();
    }

    public function test_wiki_view_alone_reads_the_wiki_and_writes_nothing(): void
    {
        $customer = $this->customer();
        $user = $this->member($customer);
        $this->grant($customer, $user, [CustomerPermissionCatalog::WIKI_VIEW]);

        // Owned by this user, so nothing but the missing permission can be what refuses the write.
        $page = $this->page($customer, $user);
        $version = $this->version($page);
        $document = $this->document($customer, $user);

        $this->actingAs($user)->get('/app/wiki')->assertOk();
        $this->actingAs($user)->get('/app/wiki/'.$page->slug)->assertOk();
        $this->actingAs($user)->get('/app/wiki/graph')->assertOk();
        $this->actingAs($user)->get('/app/wiki/ask')->assertOk();

        // Editing, reviewing, approving, deleting and source administration — all refused.
        $this->actingAs($user)
            ->patch('/app/wiki/'.$page->slug.'/working-version', [
                'expected_page_version_id' => $version->id,
                'blocks' => [],
            ])->assertForbidden();
        $this->actingAs($user)->patch('/app/wiki/'.$page->slug.'/approve')->assertForbidden();
        $this->actingAs($user)
            ->patch('/app/wiki/'.$page->slug.'/reject', ['reason' => 'Mangler kildehenvisning i avsnitt to.'])
            ->assertForbidden();
        $this->actingAs($user)->delete('/app/wiki/'.$page->slug)->assertForbidden();
        $this->actingAs($user)->post('/app/wiki/sources/'.$document->id.'/ingest')->assertForbidden();
        $this->actingAs($user)->delete('/app/wiki/sources/'.$document->id)->assertForbidden();

        $this->assertNotNull(EnterpriseWikiPage::query()->find($page->id));
        $this->assertNotNull(EnterpriseWikiDocument::query()->find($document->id));
    }

    public function test_the_page_payload_matches_what_wiki_view_alone_may_do(): void
    {
        $customer = $this->customer();
        $user = $this->member($customer);
        $this->grant($customer, $user, [CustomerPermissionCatalog::WIKI_VIEW]);
        $page = $this->page($customer, $user);
        $this->version($page);

        $this->assertSame([
            'can_view' => true,
            'can_edit' => false,
            'can_review' => false,
            'can_approve' => false,
            'can_delete' => false,
            'can_manage_sources' => false,
        ], $this->props($user, '/app/wiki')['permissions']);

        $show = $this->props($user, '/app/wiki/'.$page->slug);

        $this->assertFalse($show['can_delete_page']);
        $this->assertFalse($show['can_edit_wiki_claims']);
        $this->assertFalse($show['working_version_edit']['can_edit']);
        $this->assertSame('not_authorized', $show['working_version_edit']['unavailable_reason']);
        $this->assertFalse($show['review_assignment']['can_submit']);
    }

    // =========================================================================
    // Scenario 2 — + wiki.edit: edits, cannot approve
    // =========================================================================

    public function test_wiki_edit_opens_editing_and_nothing_downstream_of_it(): void
    {
        $customer = $this->customer();
        $user = $this->member($customer);
        $this->grant($customer, $user, [
            CustomerPermissionCatalog::WIKI_VIEW,
            CustomerPermissionCatalog::WIKI_EDIT,
        ]);

        $page = $this->page($customer, $user);
        $version = $this->version($page);

        // An empty block list is the documented no-op, so this proves the gate opened without
        // depending on claim verification running.
        $this->actingAs($user)
            ->patch('/app/wiki/'.$page->slug.'/working-version', [
                'expected_page_version_id' => $version->id,
                'blocks' => [],
            ])->assertSessionHasNoErrors();

        $show = $this->props($user, '/app/wiki/'.$page->slug);
        $this->assertTrue($show['working_version_edit']['can_edit']);
        $this->assertTrue($show['review_assignment']['can_submit']);

        // Approving and sending back are not editing.
        $this->actingAs($user)->patch('/app/wiki/'.$page->slug.'/approve')->assertForbidden();
        $this->actingAs($user)
            ->patch('/app/wiki/'.$page->slug.'/reject', ['reason' => 'Mangler kildehenvisning i avsnitt to.'])
            ->assertForbidden();
        $this->assertFalse($show['review_assignment']['can_approve_final']);
    }

    // =========================================================================
    // Scenario 3 — + wiki.review: review becomes available
    // =========================================================================

    public function test_wiki_review_opens_review_without_opening_approval(): void
    {
        $customer = $this->customer();
        $this->allowWikiPageApproval($customer);

        $author = $this->member($customer);
        $this->grant($customer, $author, [
            CustomerPermissionCatalog::WIKI_VIEW,
            CustomerPermissionCatalog::WIKI_EDIT,
        ]);

        $reviewer = $this->member($customer, User::BID_ROLE_BID_MANAGER, isWikiApprover: true);
        $this->grant($customer, $reviewer, [
            CustomerPermissionCatalog::WIKI_VIEW,
            CustomerPermissionCatalog::WIKI_REVIEW,
        ]);

        $page = $this->page($customer, $author);
        $this->version($page);

        // The reviewer is offerable because they hold wiki.review...
        $authorProps = $this->props($author, '/app/wiki/'.$page->slug);
        $this->assertContains(
            $reviewer->id,
            array_column($authorProps['review_assignment']['eligible_reviewers'], 'id'),
        );

        $this->actingAs($author)
            ->patch('/app/wiki/'.$page->slug.'/submit', ['reviewer_user_id' => $reviewer->id])
            ->assertSessionHasNoErrors();

        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $page->fresh()->status);

        // ...and may send it back, which is the review decision.
        $reviewerProps = $this->props($reviewer, '/app/wiki/'.$page->slug);
        $this->assertTrue($reviewerProps['review_assignment']['can_send_back']);
        $this->assertFalse($reviewerProps['review_assignment']['can_approve_final']);
        $this->assertSame('missing_capability', $reviewerProps['review_assignment']['final_approval_blocker']);

        // Publishing it is not.
        $this->actingAs($reviewer)->patch('/app/wiki/'.$page->slug.'/approve')->assertForbidden();
        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $page->fresh()->status);

        $this->actingAs($reviewer)
            ->patch('/app/wiki/'.$page->slug.'/reject', ['reason' => 'Mangler kildehenvisning i avsnitt to.'])
            ->assertSessionHasNoErrors();

        $this->assertSame(EnterpriseWikiPage::STATUS_REJECTED, $page->fresh()->status);
    }

    public function test_a_user_without_wiki_review_is_never_offered_as_reviewer(): void
    {
        $customer = $this->customer();
        $this->allowWikiPageApproval($customer);

        $author = $this->member($customer);
        $this->grant($customer, $author, [
            CustomerPermissionCatalog::WIKI_VIEW,
            CustomerPermissionCatalog::WIKI_EDIT,
        ]);

        // Holds the Wiki's own approval capability, but the customer gave them no Wiki role.
        $stranger = $this->member($customer, User::BID_ROLE_BID_MANAGER, isWikiApprover: true);

        $page = $this->page($customer, $author);
        $this->version($page);

        $props = $this->props($author, '/app/wiki/'.$page->slug);
        $this->assertNotContains(
            $stranger->id,
            array_column($props['review_assignment']['eligible_reviewers'], 'id'),
        );

        $this->actingAs($author)
            ->patch('/app/wiki/'.$page->slug.'/submit', ['reviewer_user_id' => $stranger->id])
            ->assertSessionHas('error');

        $this->assertSame(EnterpriseWikiPage::STATUS_DRAFT, $page->fresh()->status);
    }

    // =========================================================================
    // Scenario 4 — + wiki.approve: publishing becomes available
    // =========================================================================

    public function test_wiki_approve_publishes_the_page(): void
    {
        $customer = $this->customer();
        $this->allowWikiPageApproval($customer);

        $author = $this->member($customer);
        $this->grant($customer, $author, [
            CustomerPermissionCatalog::WIKI_VIEW,
            CustomerPermissionCatalog::WIKI_EDIT,
        ]);

        $reviewer = $this->member($customer, User::BID_ROLE_BID_MANAGER, isWikiApprover: true);
        $this->grant($customer, $reviewer, [
            CustomerPermissionCatalog::WIKI_VIEW,
            CustomerPermissionCatalog::WIKI_REVIEW,
            CustomerPermissionCatalog::WIKI_APPROVE,
        ]);

        $page = $this->page($customer, $author);
        $this->version($page);

        $this->actingAs($author)
            ->patch('/app/wiki/'.$page->slug.'/submit', ['reviewer_user_id' => $reviewer->id])
            ->assertSessionHasNoErrors();

        $props = $this->props($reviewer, '/app/wiki/'.$page->slug);
        $this->assertTrue($props['review_assignment']['can_approve_final']);
        $this->assertNull($props['review_assignment']['final_approval_blocker']);

        $this->actingAs($reviewer)->patch('/app/wiki/'.$page->slug.'/approve')->assertSessionHasNoErrors();

        $this->assertSame(EnterpriseWikiPage::STATUS_APPROVED, $page->fresh()->status);
    }

    // =========================================================================
    // Scenarios 5 and 6 — deletion
    // =========================================================================

    public function test_deleting_a_page_needs_wiki_delete(): void
    {
        $customer = $this->customer();
        $user = $this->member($customer);
        $this->grant($customer, $user, [
            CustomerPermissionCatalog::WIKI_VIEW,
            CustomerPermissionCatalog::WIKI_EDIT,
            CustomerPermissionCatalog::WIKI_REVIEW,
            CustomerPermissionCatalog::WIKI_APPROVE,
            CustomerPermissionCatalog::WIKI_SOURCE_MANAGE,
        ]);

        $page = $this->page($customer, $user);
        $this->version($page);

        $this->assertFalse($this->props($user, '/app/wiki/'.$page->slug)['can_delete_page']);
        $this->actingAs($user)->delete('/app/wiki/'.$page->slug)->assertForbidden();
        $this->assertNotNull(EnterpriseWikiPage::query()->find($page->id));
    }

    public function test_wiki_delete_removes_a_page_the_user_owns(): void
    {
        $customer = $this->customer();
        $user = $this->member($customer);
        $this->grant($customer, $user, [
            CustomerPermissionCatalog::WIKI_VIEW,
            CustomerPermissionCatalog::WIKI_DELETE,
        ]);

        $page = $this->page($customer, $user);
        $this->version($page);

        $this->assertTrue($this->props($user, '/app/wiki/'.$page->slug)['can_delete_page']);
        $this->actingAs($user)->delete('/app/wiki/'.$page->slug)->assertSessionHasNoErrors();
        $this->assertNull(EnterpriseWikiPage::query()->find($page->id));
    }

    /**
     * The permission is added to the Wiki's ownership rule, not put in place of it. This is the
     * single most important property of the whole change: wiki.delete says the customer gave this
     * person deletion work, and canDeleteEnterpriseWikiPage() still says which page is theirs.
     */
    public function test_wiki_delete_does_not_reach_somebody_elses_page(): void
    {
        $customer = $this->customer();
        $owner = $this->member($customer);
        $other = $this->member($customer);
        $this->grant($customer, $other, [
            CustomerPermissionCatalog::WIKI_VIEW,
            CustomerPermissionCatalog::WIKI_DELETE,
        ]);

        $page = $this->page($customer, $owner);
        $this->version($page);

        $this->assertFalse($this->props($other, '/app/wiki/'.$page->slug)['can_delete_page']);
        $this->actingAs($other)->delete('/app/wiki/'.$page->slug)->assertForbidden();
        $this->assertNotNull(EnterpriseWikiPage::query()->find($page->id));
    }

    // =========================================================================
    // Scenarios 7 and 8 — source administration, and the seam from Kvalitet
    // =========================================================================

    public function test_source_administration_needs_wiki_source_manage(): void
    {
        $customer = $this->customer();
        $user = $this->member($customer);
        $this->grant($customer, $user, [
            CustomerPermissionCatalog::WIKI_VIEW,
            CustomerPermissionCatalog::WIKI_EDIT,
            CustomerPermissionCatalog::WIKI_REVIEW,
            CustomerPermissionCatalog::WIKI_APPROVE,
            CustomerPermissionCatalog::WIKI_DELETE,
        ]);

        $document = $this->document($customer, $user);

        $this->actingAs($user)->post('/app/wiki/sources/'.$document->id.'/ingest')->assertForbidden();
        $this->actingAs($user)->get('/app/wiki/sources/'.$document->id.'/delete-preview')->assertForbidden();
        $this->actingAs($user)
            ->patch('/app/wiki/sources/'.$document->id.'/owner', ['owner_user_id' => $user->id])
            ->assertForbidden();
        $this->actingAs($user)->delete('/app/wiki/sources/'.$document->id)->assertForbidden();

        // The list stays readable, and says the actions are not on offer.
        $props = $this->props($user, '/app/wiki?tab=sources');
        $this->assertFalse($props['permissions']['can_manage_sources']);
        $this->assertFalse(collect($props['sources'])->firstWhere('id', $document->id)['can_delete']);

        $this->assertNotNull(EnterpriseWikiDocument::query()->find($document->id));
    }

    public function test_wiki_source_manage_opens_source_administration(): void
    {
        $customer = $this->customer();
        $user = $this->member($customer);
        $this->grant($customer, $user, [
            CustomerPermissionCatalog::WIKI_VIEW,
            CustomerPermissionCatalog::WIKI_SOURCE_MANAGE,
        ]);

        $document = $this->document($customer, $user);

        $props = $this->props($user, '/app/wiki?tab=sources');
        $this->assertTrue($props['permissions']['can_manage_sources']);
        $this->assertTrue(collect($props['sources'])->firstWhere('id', $document->id)['can_delete']);

        $this->actingAs($user)->get('/app/wiki/sources/'.$document->id.'/delete-preview')->assertOk();
        $this->actingAs($user)->delete('/app/wiki/sources/'.$document->id)->assertSessionHasNoErrors();

        $this->assertNull(EnterpriseWikiDocument::query()->find($document->id));
    }

    /**
     * Kvalitet hands an activity's knowledge to Wiki by creating an Enterprise Wiki source
     * document, so the action belongs to both modules and asks for both.
     */
    public function test_the_quality_to_wiki_handover_needs_both_permissions(): void
    {
        $customer = $this->customer();
        $user = $this->member($customer);
        $this->grant($customer, $user, [
            CustomerPermissionCatalog::QUALITY_VIEW,
            CustomerPermissionCatalog::QUALITY_CREATE,
            CustomerPermissionCatalog::QUALITY_EDIT,
            CustomerPermissionCatalog::WIKI_VIEW,
        ]);

        $process = QualityItem::query()->create([
            'customer_id' => $customer->id,
            'quality_type' => QualityItem::TYPE_PROCESS,
            'title' => 'Avvikshåndtering',
            'status' => QualityItem::STATUS_ACTIVE,
        ]);

        $payload = ['activity_key' => 'a1', 'title' => 'Registrere avvik', 'markdown' => '## Formål'."\n\n".'Tekst.'];

        $this->actingAs($user)
            ->post('/app/quality/items/'.$process->id.'/activities/article-draft', ['activity_key' => 'a1'])
            ->assertForbidden();
        $this->actingAs($user)
            ->post('/app/quality/items/'.$process->id.'/activities/articles', $payload)
            ->assertForbidden();

        $this->assertFalse(
            $this->props($user, '/app/quality/items/'.$process->id)['permissions']['can_create_wiki_articles'],
        );

        $this->grant($customer, $user, [CustomerPermissionCatalog::WIKI_SOURCE_MANAGE]);

        $this->assertTrue(
            $this->props($user, '/app/quality/items/'.$process->id)['permissions']['can_create_wiki_articles'],
        );
    }

    // =========================================================================
    // System Owner
    // =========================================================================

    public function test_system_owner_holds_the_whole_wiki_without_any_role(): void
    {
        $customer = $this->customer();
        $owner = $this->member($customer, User::BID_ROLE_SYSTEM_OWNER);
        $page = $this->page($customer, $owner);
        $this->version($page);
        $document = $this->document($customer, $owner);

        $props = $this->props($owner, '/app/wiki');

        $this->assertSame(
            ['can_view', 'can_edit', 'can_review', 'can_approve', 'can_delete', 'can_manage_sources'],
            array_keys($props['permissions']),
        );
        $this->assertNotContains(false, $props['permissions']);

        $this->actingAs($owner)->get('/app/wiki/'.$page->slug)->assertOk();
        $this->actingAs($owner)->get('/app/wiki/sources/'.$document->id.'/delete-preview')->assertOk();
    }

    // =========================================================================
    // Anbud is untouched
    // =========================================================================

    public function test_a_user_with_no_wiki_permission_still_reaches_anbud(): void
    {
        $customer = $this->customer();
        $user = $this->member($customer, User::BID_ROLE_BID_MANAGER);

        $this->actingAs($user)->get('/app/wiki')->assertForbidden();
        $this->actingAs($user)->get('/app/notices?mode=saved')->assertOk();
        $this->actingAs($user)->get('/app/ai')->assertOk();
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    private function props(User $user, string $url): array
    {
        return $this->actingAs($user)->get($url)->assertOk()->viewData('page')['props'];
    }

    private function grant(Customer $customer, User $user, array $permissionKeys): CustomerRole
    {
        $role = CustomerRole::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Rolle '.Str::upper(Str::random(8)),
            'is_active' => true,
        ]);

        $role->syncPermissions($permissionKeys);
        $user->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);

        return $role;
    }

    /**
     * The Wiki's OWN capability matrix, which the permissions sit on top of rather than replace.
     * Without this a Bid Manager cannot approve a Wiki page however the customer names their roles
     * — which is the point, and why the review scenarios have to set it up explicitly.
     */
    private function allowWikiPageApproval(Customer $customer): void
    {
        $settings = $customer->resolvedPermissionSettings();
        $settings[Customer::PERMISSION_APPROVE_WIKI_PAGES] = ['bid_manager'];
        $customer->forceFill(['permission_settings' => $settings])->save();
    }

    private function member(
        Customer $customer,
        string $bidRole = User::BID_ROLE_CONTRIBUTOR,
        bool $isWikiApprover = false,
    ): User {
        return User::query()->create([
            'name' => 'Wiki '.Str::upper(Str::random(4)),
            'email' => 'wiki-perm-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => $bidRole === User::BID_ROLE_SYSTEM_OWNER ? User::ROLE_CUSTOMER_ADMIN : User::ROLE_USER,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
            'is_wiki_approver' => $isWikiApprover,
        ]);
    }

    private function page(Customer $customer, User $owner): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'wiki-perm-'.Str::lower(Str::random(10)),
            'title' => 'Kvalitetspolicy',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
            'owner_user_id' => $owner->id,
        ]);
    }

    private function version(EnterpriseWikiPage $page): EnterpriseWikiPageVersion
    {
        return EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => '# '.$page->title."\n\nTekst.",
        ]);
    }

    private function document(Customer $customer, User $owner): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'kvalitetsmanual.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki-documents/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
            'owner_user_id' => $owner->id,
        ]);
    }

    private function customer(): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO'],
        );

        $customer = Customer::query()->create([
            'name' => 'Wiki Tilgang AS',
            'slug' => 'wiki-tilgang-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);

        // Module entitlements are a separate gate that runs before any of this — the virksomhet
        // has bought the module, which is what makes the permission question the one being asked.
        foreach (['basis', 'tender'] as $package) {
            CustomerPackageEntitlement::query()->updateOrCreate(
                ['customer_id' => $customer->id, 'package_key' => $package],
                ['status' => CustomerPackageEntitlement::STATUS_ACTIVE, 'activated_at' => now()],
            );
        }

        return $customer;
    }
}
