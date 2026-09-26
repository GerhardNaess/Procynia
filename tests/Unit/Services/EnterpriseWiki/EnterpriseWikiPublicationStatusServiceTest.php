<?php

namespace Tests\Unit\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\User;
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
    /**
     * "Sideeier må sende siden til gjennomgang" is true and useless: the payload already holds the
     * owner's name, so the sentence can say who, in what role, owes what.
     */
    public function test_a_draft_someone_else_owns_names_that_person(): void
    {
        $page = $this->page('draft');
        $page->setRelation('owner', $this->user('Alisan Senel'));

        $result = $this->publicationFor($page, $this->version(9), [
            'can_submit' => false,
            'eligible_reviewer_count' => 1,
        ]);

        $this->assertSame('awaiting_owner', $result['next_step']);
        $this->assertSame(
            ['name' => 'Alisan Senel', 'role' => EnterpriseWikiPublicationStatusService::ACTOR_PAGE_OWNER],
            $result['next_actor'],
        );
        $this->assertStringContainsString('Alisan Senel', $result['next_step_label']);
    }

    /**
     * A page with no owner has nobody to point at. Naming the role anyway would send the reader
     * looking for a person who does not exist, so the generic sentence stays.
     */
    public function test_a_draft_with_no_owner_keeps_the_unnamed_sentence(): void
    {
        $page = $this->page('draft');
        $page->setRelation('owner', null);

        $result = $this->publicationFor($page, $this->version(9), [
            'can_submit' => false,
            'eligible_reviewer_count' => 1,
        ]);

        $this->assertSame('awaiting_owner', $result['next_step']);
        $this->assertNull($result['next_actor']);
        $this->assertSame(__('procynia.wiki.publication_next_awaiting_owner'), $result['next_step_label']);
    }

    /** The viewer who may act is addressed directly, rather than told what the page is ready for. */
    public function test_the_viewer_who_may_submit_is_addressed_directly(): void
    {
        $result = $this->publicationFor($this->page('draft'), $this->version(9), [
            'can_submit' => true,
            'eligible_reviewer_count' => 1,
        ]);

        $this->assertSame('submit', $result['next_step']);
        $this->assertNull($result['next_actor'], 'nobody is being waited on — it is this reader\'s turn');
        $this->assertSame(__('procynia.wiki.publication_next_submit_self'), $result['next_step_label']);
    }

    /**
     * The list computes no viewer context, so a row there must not claim the reader may act: most
     * of the rows on that screen belong to somebody else.
     */
    public function test_a_row_without_viewer_context_says_what_the_page_is_ready_for(): void
    {
        $result = $this->publicationFor($this->page('draft'), $this->version(9));

        $this->assertSame('submit', $result['next_step']);
        $this->assertSame(__('procynia.wiki.publication_next_submit'), $result['next_step_label']);
    }

    /** The reviewer case already named its person, and keeps doing so unchanged. */
    public function test_a_version_in_review_still_names_its_reviewer(): void
    {
        $version = $this->version(9);
        $version->setRelation('reviewer', $this->user('Gerhard Næss'));

        $result = $this->publicationFor($this->page('pending_review'), $version, [
            'is_assigned_reviewer' => false,
        ]);

        $this->assertSame('awaiting_review', $result['next_step']);
        $this->assertSame(
            ['name' => 'Gerhard Næss', 'role' => EnterpriseWikiPublicationStatusService::ACTOR_REVIEWER],
            $result['next_actor'],
        );
        $this->assertSame(
            __('procynia.wiki.publication_next_awaiting_review_named', ['name' => 'Gerhard Næss']),
            $result['next_step_label'],
        );
    }

    /** Nobody is waited on once the page is published, so there is no actor to name. */
    public function test_a_settled_page_names_nobody(): void
    {
        $result = $this->publicationFor($this->page('approved', 9), $this->version(9));

        $this->assertSame('none', $result['next_step']);
        $this->assertNull($result['next_actor']);
    }

    private function user(string $name): User
    {
        $user = new User;
        $user->name = $name;

        return $user;
    }

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
