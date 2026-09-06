<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Send til gjennomgang" now hands the work to a named person.
 *
 * Before this, it moved a status field and nothing else: no submitter, no recipient, and the same
 * System Owner could submit and approve. Three columns on the VERSION record the handover, because
 * the version is what is under review — an assignment that outlived a regeneration would point at a
 * review of content that no longer exists.
 *
 * Separation of duties holds for everyone, System Owner included: an emergency exception has not
 * been decided, so none is implemented. See docs/enterprise-wiki-approval-model.md §10.
 */
class EnterpriseWikiReviewAssignmentTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    // A + B. the owner hands the page to a valid reviewer, and the handover is recorded
    public function test_the_page_owner_submits_and_the_handover_is_recorded(): void
    {
        [$customer, $owner, $page, $version] = $this->draftPage();
        $reviewer = $this->reviewer($customer);

        $this->actingAs($owner)
            ->patch("/app/wiki/{$page->slug}/submit", ['reviewer_user_id' => $reviewer->id])
            ->assertRedirect(route('app.wiki.show', $page->slug));

        $version->refresh();
        $this->assertSame($owner->id, (int) $version->submitted_by_user_id);
        $this->assertNotNull($version->submitted_at);
        $this->assertSame($reviewer->id, (int) $version->reviewer_user_id);
        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $page->fresh()->status);
    }

    // N. the metadata belongs to the version, not the page
    public function test_the_assignment_lives_on_the_version(): void
    {
        [$customer, $owner, $page, $version] = $this->draftPage();
        $this->actingAs($owner)->patch("/app/wiki/{$page->slug}/submit", [
            'reviewer_user_id' => $this->reviewer($customer)->id,
        ]);

        foreach (['submitted_by_user_id', 'submitted_at', 'reviewer_user_id'] as $column) {
            $this->assertNotNull($version->fresh()->{$column});
            $this->assertArrayNotHasKey($column, $page->fresh()->getAttributes());
        }
    }

    // J. submitting never touches what was already published
    public function test_submitting_does_not_disturb_the_published_version(): void
    {
        [$customer, $owner, $page, $version] = $this->draftPage();
        $page->forceFill(['published_version_id' => $version->id])->save();

        $this->actingAs($owner)->patch("/app/wiki/{$page->slug}/submit", [
            'reviewer_user_id' => $this->reviewer($customer)->id,
        ]);

        $this->assertSame($version->id, (int) $page->fresh()->published_version_id);
    }

    // C + M. reviewers come from the same customer only
    public function test_a_reviewer_from_another_customer_is_refused(): void
    {
        [, $owner, $page, $version] = $this->draftPage();
        $otherCustomer = $this->customer('Fremmed Kunde AS');
        $foreignReviewer = $this->reviewer($otherCustomer);

        $this->actingAs($owner)->patch("/app/wiki/{$page->slug}/submit", [
            'reviewer_user_id' => $foreignReviewer->id,
        ])->assertSessionHas('error');

        $this->assertNull($version->fresh()->reviewer_user_id);
        $this->assertSame(EnterpriseWikiPage::STATUS_DRAFT, $page->fresh()->status);
    }

    // D + I. the reviewer must actually hold the page-approval capability
    public function test_a_reviewer_without_the_capability_is_refused(): void
    {
        [$customer, $owner, $page, $version] = $this->draftPage();
        $plainContributor = $this->user($customer, User::BID_ROLE_CONTRIBUTOR);

        $this->actingAs($owner)->patch("/app/wiki/{$page->slug}/submit", [
            'reviewer_user_id' => $plainContributor->id,
        ])->assertSessionHas('error');

        $this->assertNull($version->fresh()->reviewer_user_id);
    }

    public function test_claim_approval_alone_does_not_make_someone_a_reviewer(): void
    {
        [$customer, $owner, $page] = $this->draftPage();
        $this->grant($customer, Customer::PERMISSION_APPROVE_WIKI_CLAIMS, ['contributor']);
        $claimApprover = $this->user($customer, User::BID_ROLE_CONTRIBUTOR);

        $this->assertTrue($claimApprover->canApproveWikiClaims());
        $this->assertFalse($claimApprover->canBeEnterpriseWikiReviewerFor($page, $owner->id));
    }

    public function test_an_inactive_user_cannot_be_a_reviewer(): void
    {
        [$customer, $owner, $page] = $this->draftPage();
        $reviewer = $this->reviewer($customer);
        $reviewer->forceFill(['is_active' => false])->save();

        $this->assertFalse($reviewer->fresh()->canBeEnterpriseWikiReviewerFor($page, $owner->id));
    }

    // E. no self-assignment
    public function test_the_submitter_cannot_be_the_reviewer(): void
    {
        [$customer, , $page, $version] = $this->draftPage();
        // The owner also holds the review capability — they still may not review their own submission.
        $this->grant($customer, Customer::PERMISSION_APPROVE_WIKI_PAGES, ['contributor', 'bid_manager']);
        $owner = User::query()->findOrFail($page->owner_user_id);

        $this->actingAs($owner)->patch("/app/wiki/{$page->slug}/submit", [
            'reviewer_user_id' => $owner->id,
        ])->assertSessionHas('error');

        $this->assertNull($version->fresh()->reviewer_user_id);
    }

    // F. only the owner (or a System Owner) may submit
    public function test_a_user_who_does_not_own_the_page_cannot_submit(): void
    {
        [$customer, , $page] = $this->draftPage();
        $bystander = $this->reviewer($customer);

        $this->actingAs($bystander)->patch("/app/wiki/{$page->slug}/submit", [
            'reviewer_user_id' => $this->reviewer($customer)->id,
        ])->assertForbidden();

        $this->assertSame(EnterpriseWikiPage::STATUS_DRAFT, $page->fresh()->status);
    }

    public function test_a_system_owner_can_submit_a_page_that_has_no_owner(): void
    {
        // 2 of 19 local pages have no determinable owner; somebody has to be able to move them.
        [$customer, , $page] = $this->draftPage();
        $page->forceFill(['owner_user_id' => null])->save();
        $systemOwner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);

        $this->actingAs($systemOwner)->patch("/app/wiki/{$page->slug}/submit", [
            'reviewer_user_id' => $this->reviewer($customer)->id,
        ])->assertRedirect(route('app.wiki.show', $page->slug));

        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $page->fresh()->status);
    }

    // G + H. only the assigned reviewer may act
    public function test_only_the_assigned_reviewer_may_approve(): void
    {
        [$customer, $page, $reviewer] = $this->submittedPage();
        $otherReviewer = $this->reviewer($customer);

        $this->actingAs($otherReviewer)
            ->patch("/app/wiki/{$page->slug}/approve")
            ->assertForbidden();

        $this->actingAs($reviewer)
            ->patch("/app/wiki/{$page->slug}/approve")
            ->assertRedirect(route('app.wiki.show', $page->slug));

        $this->assertSame(EnterpriseWikiPage::STATUS_APPROVED, $page->fresh()->status);
    }

    // K. reject follows the same assignment
    public function test_only_the_assigned_reviewer_may_reject(): void
    {
        [$customer, $page, $reviewer] = $this->submittedPage();

        $this->actingAs($this->reviewer($customer))
            ->patch("/app/wiki/{$page->slug}/reject")
            ->assertForbidden();

        $this->actingAs($reviewer)
            ->patch("/app/wiki/{$page->slug}/reject")
            ->assertRedirect(route('app.wiki.show', $page->slug));

        $this->assertSame(EnterpriseWikiPage::STATUS_REJECTED, $page->fresh()->status);
    }

    public function test_the_submitter_cannot_approve_their_own_submission_even_as_system_owner(): void
    {
        [$customer, , $page] = $this->draftPage();
        $systemOwner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $page->forceFill(['owner_user_id' => $systemOwner->id])->save();
        $reviewer = $this->reviewer($customer);

        $this->actingAs($systemOwner)->patch("/app/wiki/{$page->slug}/submit", [
            'reviewer_user_id' => $reviewer->id,
        ]);

        $this->actingAs($systemOwner)
            ->patch("/app/wiki/{$page->slug}/approve")
            ->assertForbidden();

        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $page->fresh()->status);
    }

    public function test_a_system_owner_may_step_in_on_someone_elses_assignment(): void
    {
        [$customer, $page] = $this->submittedPage();
        $systemOwner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);

        $this->actingAs($systemOwner)
            ->patch("/app/wiki/{$page->slug}/approve")
            ->assertRedirect(route('app.wiki.show', $page->slug));

        $this->assertSame(EnterpriseWikiPage::STATUS_APPROVED, $page->fresh()->status);
    }

    // L. a version that never went through the flow is handled explicitly, not guessed
    public function test_a_pending_page_with_no_assignment_falls_back_to_the_capability(): void
    {
        [$customer, , $page, $version] = $this->draftPage();
        $page->forceFill(['status' => EnterpriseWikiPage::STATUS_PENDING_REVIEW])->save();

        $this->assertNull($version->fresh()->reviewer_user_id, 'no reviewer is invented for it');

        $this->actingAs($this->reviewer($customer))
            ->patch("/app/wiki/{$page->slug}/approve")
            ->assertRedirect(route('app.wiki.show', $page->slug));
    }

    // An assignment in flight is not silently replaced
    public function test_submitting_a_page_that_is_already_assigned_is_refused(): void
    {
        [$customer, $page, $reviewer] = $this->submittedPage();
        $owner = User::query()->findOrFail($page->owner_user_id);
        $page->forceFill(['status' => EnterpriseWikiPage::STATUS_DRAFT])->save();

        $this->actingAs($owner)->patch("/app/wiki/{$page->slug}/submit", [
            'reviewer_user_id' => $this->reviewer($customer)->id,
        ])->assertStatus(409);

        $this->assertSame($reviewer->id, (int) $page->currentVersion()->first()->reviewer_user_id);
    }

    public function test_reopening_a_rejected_page_clears_the_assignment(): void
    {
        [$customer, $page, $reviewer] = $this->submittedPage();
        $this->actingAs($reviewer)->patch("/app/wiki/{$page->slug}/reject");

        $owner = User::query()->findOrFail($page->owner_user_id);
        $this->actingAs($owner)
            ->patch("/app/wiki/{$page->slug}/submit")
            ->assertRedirect(route('app.wiki.show', $page->slug));

        $version = $page->fresh()->currentVersion()->first();
        $this->assertSame(EnterpriseWikiPage::STATUS_DRAFT, $page->fresh()->status);
        $this->assertNull($version->reviewer_user_id, 'the rejected round leaves no assignment behind');
        $this->assertNull($version->submitted_by_user_id);
    }

    public function test_the_page_payload_carries_the_assignment_and_eligible_reviewers(): void
    {
        [$customer, $owner, $page] = $this->draftPage();
        $reviewer = $this->reviewer($customer);

        $this->actingAs($owner)
            ->get("/app/wiki/{$page->slug}")
            ->assertSuccessful()
            ->assertInertia(fn ($inertia) => $inertia
                ->where('review_assignment.can_submit', true)
                ->where('review_assignment.reviewer', null)
                ->where(
                    'review_assignment.eligible_reviewers',
                    fn ($options) => collect($options)->contains(fn ($o) => (int) $o['id'] === $reviewer->id)
                        && ! collect($options)->contains(fn ($o) => (int) $o['id'] === $owner->id),
                ));
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    /** @return array{0: Customer, 1: User, 2: EnterpriseWikiPage, 3: EnterpriseWikiPageVersion} */
    private function draftPage(): array
    {
        $customer = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_CONTRIBUTOR);
        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'owner_user_id' => $owner->id,
            'slug' => 'tildeling-'.Str::lower(Str::random(6)),
            'title' => 'Tildeling Side',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => '# Tildeling Side',
            'generated_by_model' => 'gpt-5',
        ]);

        return [$customer, $owner, $page, $version];
    }

    /** @return array{0: Customer, 1: EnterpriseWikiPage, 2: User} */
    private function submittedPage(): array
    {
        [$customer, $owner, $page] = $this->draftPage();
        $reviewer = $this->reviewer($customer);

        $this->actingAs($owner)->patch("/app/wiki/{$page->slug}/submit", ['reviewer_user_id' => $reviewer->id]);

        return [$customer, $page->fresh(), $reviewer];
    }

    /** A user who holds approve_wiki_pages for this customer. */
    private function reviewer(Customer $customer): User
    {
        $this->grant($customer, Customer::PERMISSION_APPROVE_WIKI_PAGES, ['bid_manager']);

        return $this->user($customer, User::BID_ROLE_BID_MANAGER);
    }

    /** @param list<string> $roles */
    private function grant(Customer $customer, string $permission, array $roles): void
    {
        $settings = $customer->resolvedPermissionSettings();
        $settings[$permission] = $roles;
        $customer->forceFill(['permission_settings' => $settings])->save();
    }

    private function customer(string $name = 'Tildeling Test AS'): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    private function user(Customer $customer, string $bidRole): User
    {
        return User::query()->create([
            'name' => 'Bruker '.Str::random(5),
            'email' => Str::lower(Str::random(8)).'@tildeling.test',
            'password' => bcrypt('secret'),
            'role' => in_array($bidRole, [User::BID_ROLE_SYSTEM_OWNER, User::BID_ROLE_BID_MANAGER], true)
                ? User::ROLE_CUSTOMER_ADMIN
                : User::ROLE_USER,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }
}
