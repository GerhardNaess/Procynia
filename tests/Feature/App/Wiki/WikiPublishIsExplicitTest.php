<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\Concerns\GrantsWikiPermissions;
use Tests\TestCase;

/**
 * Publication is an explicit human decision about a specific version, and nothing else may perform
 * it or redirect it.
 *
 * WikiController::approve() is the only code in the application that writes
 * enterprise_wiki_pages.published_version_id. What this file defends is the part of that guarantee
 * that currently holds by side effect rather than by design.
 *
 * THE CASE THAT MATTERS. A page in pending_review cannot be edited (decision B5): the reviewer
 * decides exactly the version they were handed, and the owner changes it only by withdrawing it
 * first. Underneath that rule a second line still holds: EnterpriseWikiPageVersionWriter does not
 * carry submitted_by_user_id, submitted_at or reviewer_user_id onto a version it creates, and
 * approve() refuses a version with no valid submission. That is what keeps a page stranded before
 * B5 — still in review with an unassigned working version — from ever being published, so both
 * are pinned down here.
 */
class WikiPublishIsExplicitTest extends TestCase
{
    use DatabaseTransactions;
    use GrantsWikiPermissions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    // =========================================================================
    // What was handed over is what is decided (decision B5)
    // =========================================================================

    /**
     * The owner cannot change a version that is out for review, so the reviewer's «Godkjenn og
     * publiser» publishes exactly what they were handed.
     */
    public function test_a_page_in_review_cannot_be_edited_so_the_reviewer_publishes_what_was_handed_over(): void
    {
        [$page, $owner, $reviewer, $v1] = $this->pageOutForReview();

        $this->actingAs($owner)
            ->patch("/app/wiki/{$page->slug}/working-version", [
                'expected_page_version_id' => $v1->id,
                'blocks' => [['block_key' => 'block-0001', 'markdown' => 'Tekst kontrolløren aldri så.']],
            ])
            ->assertSessionHas('error', __('procynia.wiki.edit_locked_in_review'));

        $this->assertSame((int) $v1->id, (int) $this->currentVersion($page)->id, 'nothing was saved');

        $this->actingAs($reviewer)->patch("/app/wiki/{$page->slug}/approve")->assertRedirect();

        $this->assertSame((int) $v1->id, (int) $page->fresh()->published_version_id);
    }

    /**
     * The supported way to change it: withdraw, edit, send again. The new version carries no
     * submission until it is handed over, and then it is the one that is published.
     */
    public function test_withdrawing_editing_and_resubmitting_publishes_the_new_version(): void
    {
        [$page, $owner, $reviewer, $v1] = $this->pageOutForReview();

        $this->actingAs($owner)->patch("/app/wiki/{$page->slug}/withdraw")->assertRedirect();
        $this->assertSame(EnterpriseWikiPage::STATUS_DRAFT, $page->fresh()->status);

        $this->actingAs($owner)->patch("/app/wiki/{$page->slug}/working-version", [
            'expected_page_version_id' => $v1->id,
            'blocks' => [['block_key' => 'block-0001', 'markdown' => 'Ny tekst.']],
        ])->assertSessionHas('success');

        $v2 = $this->currentVersion($page);
        $this->assertSame(2, (int) $v2->version_number);
        $this->assertNull($v2->submitted_by_user_id);
        $this->assertNull($v2->submitted_at);
        $this->assertNull($v2->reviewer_user_id);

        $this->actingAs($owner)->patch("/app/wiki/{$page->slug}/submit", ['reviewer_user_id' => $reviewer->id])->assertRedirect();
        $this->actingAs($reviewer)->patch("/app/wiki/{$page->slug}/approve")->assertRedirect();

        $this->assertSame((int) $v2->id, (int) $page->fresh()->published_version_id);
    }

    // =========================================================================
    // Pages a manual edit stranded before decision B5
    // =========================================================================

    /**
     * Before B5 an edit during review left the page in pending_review with a working version nobody
     * was handed. Such a page may still exist. It is never published: approve() refuses a version
     * with no valid submission, and says what to do instead.
     */
    public function test_a_stranded_unassigned_version_is_never_published(): void
    {
        [$page, , $reviewer] = $this->strandedPage();

        $response = $this->actingAs($reviewer)->patch("/app/wiki/{$page->slug}/approve");

        $response->assertStatus(409);
        $this->assertSame(__('procynia.wiki.approve_refused_missing_assignment'), $response->exception->getMessage());
        $this->assertNull($page->fresh()->published_version_id, 'nothing may be published behind the reviewer');
        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $page->fresh()->status);
    }

    /** And it is never left with no way out: its owner withdraws it, and it is a draft again. */
    public function test_a_stranded_page_is_never_left_with_no_way_out(): void
    {
        [$page, $owner] = $this->strandedPage();

        $this->actingAs($owner)->patch("/app/wiki/{$page->slug}/withdraw")->assertRedirect();

        $this->assertSame(EnterpriseWikiPage::STATUS_DRAFT, $page->fresh()->status);
        $this->assertNull($page->fresh()->published_version_id, 'and still publishes nothing');
    }

    /**
     * Nobody takes over a review they were never given (O6, docs/external-api-phase-0-plan.md §2):
     * not the person the earlier version was handed to, not another Wiki approver, and not a System
     * Owner. The version in front of them was handed to nobody; its owner withdraws it.
     */
    public function test_nobody_decides_a_version_that_was_handed_to_nobody(): void
    {
        [$page, , $formerReviewer] = $this->strandedPage();
        // Every Wiki permission and the approver capability: the refusal can only come from the
        // rule that the version was handed to somebody else, never from a missing grant.
        $otherApprover = $this->grantWikiPermissions($page->customer_id, $this->user($page->customer_id, User::BID_ROLE_CONTRIBUTOR));
        $otherApprover->forceFill(['is_wiki_approver' => true])->save();
        $this->assertTrue($otherApprover->fresh()->canApproveWikiPages());
        $systemOwner = $this->user($page->customer_id, User::BID_ROLE_SYSTEM_OWNER);

        foreach ([$formerReviewer, $otherApprover, $systemOwner] as $approver) {
            $this->actingAs($approver)->get(route('app.wiki.show', ['slug' => $page->slug]))
                ->assertInertia(fn ($inertia) => $inertia->where('review_assignment.can_send_back', false));
            $this->actingAs($approver)
                ->patch("/app/wiki/{$page->slug}/reject", ['reason' => 'Overtar en gjennomgang jeg ikke fikk.'])
                ->assertForbidden();
        }

        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $page->fresh()->status);
        $this->assertNull($page->fresh()->reviewed_by_user_id);
    }

    /** Sending back is still a review decision — an outsider cannot do it. */
    public function test_rejecting_an_unsubmitted_version_still_needs_the_capability(): void
    {
        [$page] = $this->strandedPage();
        $outsider = $this->user($page->customer_id, User::BID_ROLE_CONTRIBUTOR);

        $this->actingAs($outsider)
            ->patch("/app/wiki/{$page->slug}/reject", ['reason' => 'Jeg mener dette er feil.'])
            ->assertForbidden();

        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $page->fresh()->status);
    }

    // =========================================================================
    // Nothing else publishes
    // =========================================================================

    public function test_saving_a_working_version_never_publishes(): void
    {
        [$page, $owner, , $v1] = $this->pageOutForReview();

        $this->actingAs($owner)->patch("/app/wiki/{$page->slug}/working-version", [
            'expected_page_version_id' => $v1->id,
            'blocks' => [['block_key' => 'block-0001', 'markdown' => 'Redigert.']],
        ]);

        $this->assertNull($page->fresh()->published_version_id, 'editing is not approving');
    }

    public function test_submitting_for_review_never_publishes(): void
    {
        [$page] = $this->pageOutForReview();

        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $page->fresh()->status);
        $this->assertNull($page->fresh()->published_version_id, 'handing a version over is not approving it');
    }

    /**
     * Claim approval and page publication are separate decisions with separate permissions
     * (approve_wiki_claims vs approve_wiki_pages). Approving every claim on a version must not
     * publish the page.
     */
    public function test_approving_a_claim_never_publishes_the_page(): void
    {
        [$page, , , $v1] = $this->pageOutForReview();
        $qa = $this->user($page->customer_id, User::BID_ROLE_CONTRIBUTOR, isQa: true);

        $claim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $v1->id,
            'claim_text' => 'Google Cloud Functions brukes som integrasjonskomponent.',
            'content_block_key' => 'block-0001',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'position_order' => 1,
        ]);

        $this->actingAs($qa)->patch("/app/wiki/{$page->slug}/claims/{$claim->id}/approve");

        $this->assertNull($page->fresh()->published_version_id, 'a claim decision is not a page decision');
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    /** @return array{0: EnterpriseWikiPage, 1: User, 2: User, 3: EnterpriseWikiPageVersion} */
    private function pageOutForReview(): array
    {
        $customer = $this->customer();
        $owner = $this->user($customer->id, User::BID_ROLE_SYSTEM_OWNER);
        $reviewer = $this->user($customer->id, User::BID_ROLE_SYSTEM_OWNER);

        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'publish-'.Str::lower(Str::random(6)),
            'title' => 'Google Cloud',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ENTITY,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('h', 64, '0'),
            'owner_user_id' => $owner->id,
        ]);

        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => "# Google Cloud\n\nFørste tekst.",
            'content_blocks_json' => [[
                'block_key' => 'block-0001',
                'position' => 0,
                'markdown' => 'Første tekst.',
                'content_origin' => 'human_authored',
            ]],
            'generated_by_model' => 'gpt-5',
        ]);

        $this->actingAs($owner)
            ->patch("/app/wiki/{$page->slug}/submit", ['reviewer_user_id' => $reviewer->id])
            ->assertRedirect();

        return [$page->fresh(), $owner, $reviewer, $version];
    }

    /**
     * The state an edit during review left behind before decision B5: still in pending_review, the
     * submitted version superseded by one that carries no submission. Written directly, because no
     * code path produces it any more.
     *
     * @return array{0: EnterpriseWikiPage, 1: User, 2: User, 3: EnterpriseWikiPageVersion}
     */
    private function strandedPage(): array
    {
        [$page, $owner, $reviewer, $v1] = $this->pageOutForReview();

        $v1->forceFill(['is_current' => false])->save();
        $v2 = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 2,
            'is_current' => true,
            'content_markdown' => "# Google Cloud\n\nTekst kontrolløren aldri så.",
            'content_blocks_json' => $v1->content_blocks_json,
            'created_by_user_id' => $owner->id,
        ]);

        return [$page->fresh(), $owner, $reviewer, $v2];
    }

    private function currentVersion(EnterpriseWikiPage $page): EnterpriseWikiPageVersion
    {
        return EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->where('is_current', true)
            ->firstOrFail();
    }

    private function user(int $customerId, string $bidRole, bool $isQa = false): User
    {
        return User::query()->create([
            'name' => 'Publish '.Str::random(4),
            'email' => Str::lower(Str::random(10)).'@publish-explicit.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => $bidRole,
            'customer_id' => $customerId,
            'is_active' => true,
            'is_qa' => $isQa,
        ]);
    }

    private function customer(): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => 'Publish Explicit AS',
            'slug' => 'publish-explicit-'.Str::lower(Str::random(8)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);
    }
}
