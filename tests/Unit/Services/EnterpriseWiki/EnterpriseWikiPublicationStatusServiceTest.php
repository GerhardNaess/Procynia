<?php

namespace Tests\Unit\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Services\EnterpriseWiki\EnterpriseWikiPublicationStatusService;
use Tests\TestCase;

/**
 * The presenter that tells a user where a Wiki page stands.
 *
 * It decides nothing — every case below mirrors a gate WikiController already enforces — so these
 * tests exist to keep the words honest: that a page never claims to be publishable when submit()
 * or approve() would refuse it, and never claims to be blocked by something the domain does not
 * actually check.
 *
 * Models are built in memory rather than persisted: the presenter reads status and two version
 * ids, and nothing here needs a database to be true.
 */
class EnterpriseWikiPublicationStatusServiceTest extends TestCase
{
    private EnterpriseWikiPublicationStatusService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EnterpriseWikiPublicationStatusService;
    }

    public function test_a_page_that_was_never_published_is_a_draft_ready_to_submit(): void
    {
        $result = $this->publicationFor($this->page('draft'), $this->version(9), [
            'can_submit' => true,
            'eligible_reviewer_count' => 1,
        ]);

        $this->assertSame('draft', $result['state']);
        $this->assertSame('submit', $result['next_step']);
        $this->assertFalse($result['has_published_version']);
        $this->assertFalse($result['has_unpublished_changes']);
        $this->assertSame([], $result['blocking_reasons']);
    }

    /**
     * submit() refuses a reviewer who is the submitter, so a customer where nobody else can approve
     * Wiki pages has no way forward. Offering the action anyway would be an action that always fails.
     */
    public function test_a_draft_with_nobody_available_to_review_says_so(): void
    {
        $result = $this->publicationFor($this->page('draft'), $this->version(9), [
            'can_submit' => true,
            'eligible_reviewer_count' => 0,
        ]);

        $this->assertSame('blocked', $result['next_step']);
        $this->assertCount(1, $result['blocking_reasons']);
    }

    public function test_a_draft_someone_else_owns_points_at_the_owner_rather_than_an_action(): void
    {
        $result = $this->publicationFor($this->page('draft'), $this->version(9), [
            'can_submit' => false,
            'eligible_reviewer_count' => 1,
        ]);

        $this->assertSame('awaiting_owner', $result['next_step']);
        $this->assertSame([], $result['blocking_reasons']);
    }

    /**
     * The central rule this class protects: approve() never looks at claims, so unapproved claims
     * are quality information and must not appear as something stopping publication.
     */
    public function test_unapproved_claims_are_quality_status_and_never_a_blocker(): void
    {
        $result = $this->publicationFor(
            $this->page('pending_review'),
            $this->version(9),
            ['is_assigned_reviewer' => true, 'final_approval_blocker' => null],
            ['total' => 10, 'approved' => 2],
        );

        $this->assertSame('approve', $result['next_step']);
        $this->assertSame([], $result['blocking_reasons']);
        $this->assertSame(10, $result['claims_total']);
        $this->assertSame(2, $result['claims_approved']);
    }

    /**
     * Source sign-off was decoupled from publication: a document owner vouching for their own
     * material is provenance, recorded on the source document, and never a gate the Wiki page has
     * to clear. The reviewer is the only person a version in review is waiting on.
     */
    public function test_source_sign_off_is_not_a_publication_gate(): void
    {
        $result = $this->publicationFor(
            $this->page('pending_review'),
            $this->version(9),
            ['is_assigned_reviewer' => true, 'final_approval_blocker' => null],
        );

        $this->assertSame('in_review', $result['state']);
        $this->assertSame('approve', $result['next_step']);
        $this->assertSame([], $result['blocking_reasons']);
    }

    public function test_a_draft_reports_no_source_blocker(): void
    {
        $result = $this->publicationFor(
            $this->page('draft'),
            $this->version(9),
            ['can_submit' => true, 'eligible_reviewer_count' => 1],
        );

        $this->assertSame('submit', $result['next_step']);
        $this->assertSame([], $result['blocking_reasons']);
    }

    public function test_someone_who_is_not_the_reviewer_is_told_who_they_are_waiting_for(): void
    {
        $result = $this->publicationFor(
            $this->page('pending_review'),
            $this->version(9),
            ['is_assigned_reviewer' => false],
        );

        $this->assertSame('awaiting_review', $result['next_step']);
    }

    public function test_a_published_page_has_nothing_outstanding(): void
    {
        $result = $this->publicationFor($this->page('approved', 9), $this->version(9));

        $this->assertSame('published', $result['state']);
        $this->assertSame('none', $result['next_step']);
        $this->assertTrue($result['has_published_version']);
        $this->assertFalse($result['has_unpublished_changes']);
    }

    /**
     * A new ingest run returns a published page to draft while published_version_id keeps pointing
     * at the approved version. The page has NOT stopped being published, and must not be shown as
     * if it had never been.
     */
    public function test_a_published_page_with_newer_work_says_both_things(): void
    {
        $result = $this->publicationFor($this->page('draft', 5), $this->version(9), [
            'can_submit' => true,
            'eligible_reviewer_count' => 1,
        ]);

        $this->assertSame('published_with_changes', $result['state']);
        $this->assertTrue($result['has_published_version']);
        $this->assertTrue($result['has_unpublished_changes']);
        $this->assertSame('submit', $result['next_step']);
    }

    /**
     * Editing an approved page leaves its status approved, and submit() only accepts a draft — so
     * that version genuinely has no route back into review. The honest answer is to say so rather
     * than offer an action that would be refused.
     */
    public function test_a_version_edited_after_publication_is_reported_as_stuck(): void
    {
        $result = $this->publicationFor($this->page('approved', 5), $this->version(9));

        $this->assertSame('published_with_changes', $result['state']);
        $this->assertSame('blocked', $result['next_step']);
        $this->assertCount(1, $result['blocking_reasons']);
    }

    public function test_a_returned_page_asks_the_owner_to_fix_and_resubmit(): void
    {
        $result = $this->publicationFor($this->page('rejected'), $this->version(9), ['can_submit' => true]);

        $this->assertSame('changes_requested', $result['state']);
        $this->assertSame('resolve_changes', $result['next_step']);
    }

    public function test_a_page_without_a_version_cannot_move_at_all(): void
    {
        $result = $this->publicationFor($this->page('draft'), null);

        $this->assertSame('no_version', $result['state']);
        $this->assertSame('blocked', $result['next_step']);
    }

    /**
     * @param  array<string, mixed>  $ownerSummary
     * @param  array<string, mixed>  $reviewContext
     * @param  array{total: int, approved: int}|null  $claimCounts
     * @return array<string, mixed>
     */
    private function publicationFor(
        EnterpriseWikiPage $page,
        ?EnterpriseWikiPageVersion $version,
        array $reviewContext = [],
        ?array $claimCounts = null,
    ): array {
        return $this->service->forPage($page, $version, $reviewContext, $claimCounts);
    }

    private function page(string $status, ?int $publishedVersionId = null): EnterpriseWikiPage
    {
        $page = new EnterpriseWikiPage;
        $page->status = $status;
        $page->published_version_id = $publishedVersionId;

        return $page;
    }

    private function version(int $id, ?int $reviewerUserId = null): EnterpriseWikiPageVersion
    {
        $version = new EnterpriseWikiPageVersion;
        $version->id = $id;
        $version->version_number = 2;
        $version->reviewer_user_id = $reviewerUserId;

        return $version;
    }
}
