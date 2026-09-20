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
use Tests\TestCase;

/**
 * Publication is an explicit human decision about a specific version, and nothing else may perform
 * it or redirect it.
 *
 * WikiController::approve() is the only code in the application that writes
 * enterprise_wiki_pages.published_version_id. What this file defends is the part of that guarantee
 * that currently holds by side effect rather than by design.
 *
 * THE CASE THAT MATTERS. A page owner may still save a new working version while their page sits in
 * pending_review, so the reviewer's "Godkjenn og publiser" could reach a version they never saw.
 * It does not, because EnterpriseWikiPageVersionWriter does not carry submitted_by_user_id,
 * submitted_at or reviewer_user_id onto the version it creates, and assertMayReviewCurrentVersion()
 * refuses a version with no valid submission. That check is commented as a guard against legacy
 * data; it is also the only thing standing between a reviewer and publishing unseen content. A
 * well-meaning change that copied those fields forward would reopen it silently, so it is pinned
 * down here.
 */
class WikiPublishIsExplicitTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    // =========================================================================
    // A version that landed during review is never published
    // =========================================================================

    public function test_a_version_saved_during_review_cannot_be_published(): void
    {
        [$page, $owner, $reviewer, $v1] = $this->pageOutForReview();

        $this->actingAs($owner)
            ->patch("/app/wiki/{$page->slug}/working-version", [
                'expected_page_version_id' => $v1->id,
                'blocks' => [['block_key' => 'block-0001', 'markdown' => 'Tekst kontrolløren aldri så.']],
            ])
            ->assertRedirect();

        $this->assertSame(2, (int) $this->currentVersion($page)->version_number, 'the save really happened');

        // 409, not a silent publish: the reviewer is allowed to review, the version in front of
        // them is simply not the one that was handed over.
        $this->actingAs($reviewer)
            ->patch("/app/wiki/{$page->slug}/approve")
            ->assertStatus(409);

        $page->refresh();
        $this->assertNull($page->published_version_id, 'nothing may be published behind the reviewer');
        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $page->status);
    }

    /** The refusal has to tell the reviewer what to do, not just fail. */
    public function test_the_refusal_names_the_way_out(): void
    {
        [$page, $owner, $reviewer, $v1] = $this->pageOutForReview();

        $this->actingAs($owner)->patch("/app/wiki/{$page->slug}/working-version", [
            'expected_page_version_id' => $v1->id,
            'blocks' => [['block_key' => 'block-0001', 'markdown' => 'Ny tekst.']],
        ]);

        $response = $this->actingAs($reviewer)->patch("/app/wiki/{$page->slug}/approve");

        $this->assertStringContainsString('Send siden tilbake', $response->exception->getMessage());
    }

    /**
     * The mechanism, stated directly: a freshly written working version carries no submission, and
     * that is what makes it unpublishable until someone hands it over again.
     */
    public function test_a_new_working_version_carries_no_submission(): void
    {
        [$page, $owner, , $v1] = $this->pageOutForReview();

        $this->assertNotNull($v1->fresh()->submitted_by_user_id, 'v1 was submitted');

        $this->actingAs($owner)->patch("/app/wiki/{$page->slug}/working-version", [
            'expected_page_version_id' => $v1->id,
            'blocks' => [['block_key' => 'block-0001', 'markdown' => 'Ny tekst.']],
        ]);

        $v2 = $this->currentVersion($page);
        $this->assertNull($v2->submitted_by_user_id);
        $this->assertNull($v2->submitted_at);
        $this->assertNull($v2->reviewer_user_id);
    }

    /** Resubmitting the new version is the supported way forward, and it publishes that version. */
    public function test_resubmitting_the_new_version_lets_it_be_published(): void
    {
        [$page, $owner, $reviewer, $v1] = $this->pageOutForReview();

        $this->actingAs($owner)->patch("/app/wiki/{$page->slug}/working-version", [
            'expected_page_version_id' => $v1->id,
            'blocks' => [['block_key' => 'block-0001', 'markdown' => 'Ny tekst.']],
        ]);

        $v2 = $this->currentVersion($page);

        // The exact route out that the refusal tells the reviewer to take.
        $this->actingAs($reviewer)
            ->patch("/app/wiki/{$page->slug}/reject", ['reason' => 'Ny arbeidsversjon kom inn under gjennomgang.'])
            ->assertRedirect();
        $this->assertSame(EnterpriseWikiPage::STATUS_REJECTED, $page->fresh()->status);

        $this->actingAs($owner)->patch("/app/wiki/{$page->slug}/submit")->assertRedirect();
        $this->assertSame(EnterpriseWikiPage::STATUS_DRAFT, $page->fresh()->status);

        $this->actingAs($owner)->patch("/app/wiki/{$page->slug}/submit", ['reviewer_user_id' => $reviewer->id])->assertRedirect();
        $this->actingAs($reviewer)->patch("/app/wiki/{$page->slug}/approve")->assertRedirect();

        $this->assertSame((int) $v2->id, (int) $page->fresh()->published_version_id);
    }

    /**
     * The page must never end up with no way out. Before the reviewer could always send a page
     * back, a version saved during review left the page unable to be approved (409), unable to be
     * rejected (409 as well) and unable to be resubmitted (422: it was already in review) — stuck
     * in pending_review with no action left in the interface.
     */
    public function test_a_page_is_never_left_with_no_way_out(): void
    {
        [$page, $owner, $reviewer, $v1] = $this->pageOutForReview();

        $this->actingAs($owner)->patch("/app/wiki/{$page->slug}/working-version", [
            'expected_page_version_id' => $v1->id,
            'blocks' => [['block_key' => 'block-0001', 'markdown' => 'Ny tekst.']],
        ]);

        // Approval is closed, as it must be.
        $this->actingAs($reviewer)->patch("/app/wiki/{$page->slug}/approve")->assertStatus(409);

        // Sending it back is not: it publishes nothing and hands the page to its owner.
        $this->actingAs($reviewer)
            ->patch("/app/wiki/{$page->slug}/reject", ['reason' => 'Arbeidsversjonen ble endret underveis.'])
            ->assertRedirect();

        $this->assertSame(EnterpriseWikiPage::STATUS_REJECTED, $page->fresh()->status);
        $this->assertNull($page->fresh()->published_version_id, 'and still publishes nothing');
    }

    /** Sending back is still a review decision — an outsider cannot do it. */
    public function test_rejecting_an_unsubmitted_version_still_needs_the_capability(): void
    {
        [$page, $owner, , $v1] = $this->pageOutForReview();
        $outsider = $this->user($page->customer_id, User::BID_ROLE_CONTRIBUTOR);

        $this->actingAs($owner)->patch("/app/wiki/{$page->slug}/working-version", [
            'expected_page_version_id' => $v1->id,
            'blocks' => [['block_key' => 'block-0001', 'markdown' => 'Ny tekst.']],
        ]);

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
