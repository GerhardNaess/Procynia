<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiPageVersionDocumentOwnerApproval;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\Concerns\GrantsWikiPermissions;
use Tests\Concerns\ReadsMyTasks;
use Tests\TestCase;

/**
 * Who may finish a Wiki page, and what handing it over costs the person who hands it.
 *
 * One rule, for everyone — System Owner included: a page is published by somebody other than the
 * person who sent it in. There is no direct route from draft; publishing is the end of a review.
 *
 * Once a version names a reviewer, only that reviewer decides it. Otherwise the handover would be
 * decorative: a reviewer whose turn can be skipped by the person who asked for it was never really
 * asked.
 *
 * Not a dead end. Sending the page back is a separate authority, so a stalled review can always be
 * recovered, and the page can then be handed to somebody else.
 *
 * Most of what follows is about who is REFUSED. A rule that only ever says yes is not a rule.
 */
class EnterpriseWikiSystemOwnerApprovalTest extends TestCase
{
    use DatabaseTransactions;
    use GrantsWikiPermissions;
    use ReadsMyTasks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    // ── The authority ───────────────────────────────────────────────────────

    /** The ordinary route, unchanged: the person who was asked decides. */
    public function test_the_assigned_reviewer_still_approves(): void
    {
        $case = $this->pageOutForReview();

        $this->actingAs($case['reviewer'])
            ->patch("/app/wiki/{$case['page']->slug}/approve")
            ->assertRedirect();

        $page = $case['page']->fresh();

        $this->assertSame(EnterpriseWikiPage::STATUS_APPROVED, $page->status);
        $this->assertSame((int) $case['version']->id, (int) $page->published_version_id);
        $this->assertSame((int) $case['reviewer']->id, (int) $page->reviewed_by_user_id);
    }

    /** Somebody else was asked, so it is theirs to decide — System Owner included. */
    public function test_a_system_owner_cannot_decide_a_page_assigned_to_someone_else(): void
    {
        $case = $this->pageOutForReview();
        $systemOwner = $this->user($case['customer'], User::BID_ROLE_SYSTEM_OWNER);

        $payload = $this->reviewAssignmentSeenBy($systemOwner, $case['page']);
        $this->assertFalse($payload['can_approve_final']);
        $this->assertSame('not_assigned', $payload['final_approval_blocker']);

        $this->actingAs($systemOwner)
            ->patch("/app/wiki/{$case['page']->slug}/approve")
            ->assertForbidden();

        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $case['page']->fresh()->status);
    }

    /**
     * The case the rule exists for: the System Owner chose the reviewer track for this version.
     * Having asked Gerhard, they wait for Gerhard.
     */
    public function test_a_system_owner_who_sent_the_page_for_review_waits_for_the_reviewer(): void
    {
        $case = $this->pageOutForReview();

        $payload = $this->reviewAssignmentSeenBy($case['owner'], $case['page']);
        $this->assertFalse($payload['can_approve_final']);
        $this->assertSame('own_submission', $payload['final_approval_blocker']);
        $this->assertSame($case['reviewer']->name, $payload['reviewer']['name'], 'and the page names who');

        $this->actingAs($case['owner'])
            ->patch("/app/wiki/{$case['page']->slug}/approve")
            ->assertForbidden();

        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $case['page']->fresh()->status);
    }

    /**
     * The reviewer's other decision is the way out of a review nobody wants to finish. The
     * submitter cannot use it on their own page: the four-eyes rule that stops them approving it
     * stops them returning it too, which is worth knowing but is not new here.
     */
    public function test_the_reviewer_can_send_the_page_back_and_the_submitter_cannot(): void
    {
        $case = $this->pageOutForReview();

        $this->assertFalse($this->reviewAssignmentSeenBy($case['owner'], $case['page'])['can_send_back']);
        $this->assertTrue($this->reviewAssignmentSeenBy($case['reviewer'], $case['page'])['can_send_back']);

        $this->actingAs($case['reviewer'])
            ->patch("/app/wiki/{$case['page']->slug}/reject", ['reason' => 'Andre avsnitt må avklares mot kilden.'])
            ->assertRedirect();

        $this->assertSame(EnterpriseWikiPage::STATUS_REJECTED, $case['page']->fresh()->status);
    }

    /** A returned page goes back through review; being a draft again is not a way around it. */
    public function test_a_page_back_in_draft_is_still_published_only_through_review(): void
    {
        $case = $this->pageOutForReview();

        $this->actingAs($case['reviewer'])
            ->patch("/app/wiki/{$case['page']->slug}/reject", ['reason' => 'Andre avsnitt må avklares mot kilden.'])
            ->assertRedirect();
        // Reopening a returned page clears the handover.
        $this->actingAs($case['owner'])->patch("/app/wiki/{$case['page']->slug}/submit")->assertRedirect();

        $page = $case['page']->fresh();
        $this->assertSame(EnterpriseWikiPage::STATUS_DRAFT, $page->status);
        $this->assertNull($page->currentVersion()->first()->reviewer_user_id);
        $this->assertFalse($this->reviewAssignmentSeenBy($case['owner'], $page)['can_approve_final']);

        $this->actingAs($case['owner'])->patch("/app/wiki/{$page->slug}/approve")->assertStatus(422);

        $this->assertSame(EnterpriseWikiPage::STATUS_DRAFT, $case['page']->fresh()->status);
    }

    /**
     * Who actually published it. The assignment is history and stays readable, so the page can say
     * "reviewer: X, published by: Y" rather than crediting the decision to whoever was asked.
     */
    public function test_the_audit_trail_names_the_person_who_actually_decided(): void
    {
        $case = $this->pageOutForReview();

        $this->actingAs($case['reviewer'])->patch("/app/wiki/{$case['page']->slug}/approve")->assertRedirect();

        $page = $case['page']->fresh();
        $version = $case['version']->fresh();

        $this->assertSame((int) $case['reviewer']->id, (int) $page->reviewed_by_user_id);
        $this->assertSame((int) $case['reviewer']->id, (int) $version->reviewer_user_id, 'who was asked');
        $this->assertSame((int) $case['owner']->id, (int) $version->submitted_by_user_id, 'who sent it');
        $this->assertNotNull($version->submitted_at);
    }

    // ── No route straight from draft ────────────────────────────────────────

    /**
     * The contradiction this rule removes: a page that says it must be checked by somebody else,
     * beside a button that lets its author publish it. A System Owner sends their draft for review
     * like everybody else.
     */
    public function test_a_system_owner_cannot_publish_their_own_draft(): void
    {
        $case = $this->draftPage();

        $payload = $this->reviewAssignmentSeenBy($case['owner'], $case['page']);
        $this->assertFalse($payload['can_approve_final']);
        $this->assertSame('not_in_review', $payload['final_approval_blocker']);

        $this->actingAs($case['owner'])
            ->patch("/app/wiki/{$case['page']->slug}/approve")
            ->assertStatus(422);

        $page = $case['page']->fresh();
        $this->assertSame(EnterpriseWikiPage::STATUS_DRAFT, $page->status);
        $this->assertNull($page->published_version_id);
    }

    /** Sending it in is the way forward, and the person it was sent to publishes it. */
    public function test_sending_a_draft_for_review_still_works(): void
    {
        $case = $this->draftPage();
        $reviewer = $this->approver($case['customer']);

        $this->actingAs($case['owner'])
            ->patch("/app/wiki/{$case['page']->slug}/submit", ['reviewer_user_id' => $reviewer->id])
            ->assertRedirect();

        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $case['page']->fresh()->status);
        $this->assertSame((int) $reviewer->id, (int) $case['version']->fresh()->reviewer_user_id);
        $this->assertFalse($this->reviewAssignmentSeenBy($case['owner'], $case['page'])['can_approve_final']);
        $this->assertTrue($this->reviewAssignmentSeenBy($reviewer, $case['page'])['can_approve_final']);

        $this->actingAs($reviewer)->patch("/app/wiki/{$case['page']->slug}/approve")->assertRedirect();

        $this->assertSame(EnterpriseWikiPage::STATUS_APPROVED, $case['page']->fresh()->status);
    }

    /** The next step says the page has to be reviewed by somebody else, not that it can be published. */
    public function test_the_next_step_asks_for_review_by_another_user(): void
    {
        $case = $this->draftPage();

        $response = $this->actingAs($case['owner'])->get("/app/wiki/{$case['page']->slug}");
        $response->assertOk();

        $publication = $response->viewData('page')['props']['publication'];
        $this->assertSame('submit', $publication['next_step']);
        $this->assertStringContainsString('annen bruker', $publication['next_step_label']);
    }

    /** Roles are the customer's own, so the page names the permission a role needs. */
    public function test_the_review_payload_names_the_approve_permission(): void
    {
        $case = $this->draftPage();

        $this->assertSame(
            __('procynia.customer_env.roles.permissions.wiki_approve'),
            $this->reviewAssignmentSeenBy($case['owner'], $case['page'])['approve_permission_label'],
        );
    }

    /** Nor does a Wiki approver who was never asked. */
    public function test_a_wiki_approver_cannot_publish_a_draft(): void
    {
        $case = $this->draftPage();
        $approver = $this->approver($case['customer']);

        $this->assertFalse($this->reviewAssignmentSeenBy($approver, $case['page'])['can_approve_final']);

        $this->actingAs($approver)
            ->patch("/app/wiki/{$case['page']->slug}/approve")
            ->assertStatus(422);

        $this->assertSame(EnterpriseWikiPage::STATUS_DRAFT, $case['page']->fresh()->status);
    }

    public function test_qa_and_contributors_cannot_publish_a_draft(): void
    {
        $case = $this->draftPage();
        $qa = $this->user($case['customer'], User::BID_ROLE_CONTRIBUTOR);
        $qa->forceFill(['is_qa' => true])->save();
        $contributor = $this->user($case['customer'], User::BID_ROLE_CONTRIBUTOR);

        // Refused for different reasons — one lacks the capability entirely, the other is stopped
        // by the page not being in review — so what is asserted is that neither gets through.
        foreach ([$qa->fresh(), $contributor] as $actor) {
            $response = $this->actingAs($actor)->patch("/app/wiki/{$case['page']->slug}/approve");
            $this->assertTrue($response->status() >= 400, 'publication is refused');
        }

        $this->assertSame(EnterpriseWikiPage::STATUS_DRAFT, $case['page']->fresh()->status);
    }

    /** Pending claims are quality work, never a publication gate. */
    public function test_pending_claims_do_not_block_publication(): void
    {
        $case = $this->pageOutForReview();
        EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $case['page']->id,
            'enterprise_wiki_page_version_id' => $case['version']->id,
            'claim_text' => 'Påstand uten kilde.',
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ]);

        $this->actingAs($case['reviewer'])->patch("/app/wiki/{$case['page']->slug}/approve")->assertRedirect();

        $this->assertSame(EnterpriseWikiPage::STATUS_APPROVED, $case['page']->fresh()->status);
    }

    // ── Who is still refused ────────────────────────────────────────────────

    /** The line this whole change had to avoid crossing. */
    public function test_a_wiki_approver_who_was_not_asked_cannot_approve(): void
    {
        $case = $this->pageOutForReview();
        $bystander = $this->approver($case['customer']);

        $this->assertFalse($this->reviewAssignmentSeenBy($bystander, $case['page'])['can_approve_final']);

        $this->actingAs($bystander)
            ->patch("/app/wiki/{$case['page']->slug}/approve")
            ->assertForbidden();

        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $case['page']->fresh()->status);
    }

    /** Quality assurance contributes to a page. It has never decided one. */
    public function test_qa_alone_cannot_approve(): void
    {
        $case = $this->pageOutForReview();
        $qa = $this->user($case['customer'], User::BID_ROLE_CONTRIBUTOR);
        $qa->forceFill(['is_qa' => true])->save();

        $this->actingAs($qa->fresh())
            ->patch("/app/wiki/{$case['page']->slug}/approve")
            ->assertForbidden();
    }

    /** A document owner vouches for their own material, not for the page. */
    public function test_a_document_owner_alone_cannot_approve(): void
    {
        $case = $this->pageOutForReview(withSourceOwnedBy: 'other');

        $this->actingAs($case['documentOwner'])
            ->patch("/app/wiki/{$case['page']->slug}/approve")
            ->assertForbidden();
    }

    public function test_a_system_owner_at_another_customer_reaches_nothing(): void
    {
        $case = $this->pageOutForReview();
        $outsider = $this->user($this->customer('Annen Kunde AS'), User::BID_ROLE_SYSTEM_OWNER);

        $this->actingAs($outsider)->get("/app/wiki/{$case['page']->slug}")->assertNotFound();
        $this->actingAs($outsider)->patch("/app/wiki/{$case['page']->slug}/approve")->assertNotFound();

        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $case['page']->fresh()->status);
    }

    // ── Source documents are provenance, not approval ───────────────────────

    /**
     * The decoupling, asserted from both directions.
     *
     * A document owner vouching for their own material is traceability: it says where the content
     * came from and who stands behind it. The Wiki page is the thing being approved, and making the
     * source an extra level of approval meant a finished page could sit unpublished waiting on
     * somebody who had no view on the page at all.
     */
    public function test_an_unsigned_source_no_longer_blocks_a_reviewer(): void
    {
        $case = $this->pageOutForReview(withSourceOwnedBy: 'other');

        $payload = $this->reviewAssignmentSeenBy($case['reviewer'], $case['page']);
        $this->assertTrue($payload['can_approve_final']);
        $this->assertNull($payload['final_approval_blocker']);

        $this->actingAs($case['reviewer'])
            ->patch("/app/wiki/{$case['page']->slug}/approve")
            ->assertRedirect();

        $this->assertSame(EnterpriseWikiPage::STATUS_APPROVED, $case['page']->fresh()->status);
    }

    /** The 409 that used to mean "a document owner has not signed" is gone. */
    public function test_publishing_is_never_refused_over_a_source_signoff(): void
    {
        $case = $this->pageOutForReview(withSourceOwnedBy: 'other');

        $response = $this->actingAs($case['reviewer'])->patch("/app/wiki/{$case['page']->slug}/approve");

        $this->assertNotSame(409, $response->status());
    }

    /**
     * Publishing signs nothing. The two were deliberately uncoupled in both directions: a page
     * being published says nothing about whether anybody has vouched for its sources, and pretending
     * otherwise would put a decision in somebody's name that they never made.
     */
    public function test_publishing_does_not_sign_anybodys_source_off(): void
    {
        $case = $this->pageOutForReview(withSourceOwnedBy: 'other');

        $this->actingAs($case['reviewer'])->patch("/app/wiki/{$case['page']->slug}/approve")->assertRedirect();

        $this->assertTrue(
            $this->approvalFor($case['version'], $case['documentOwner'])->isPending(),
            'their sign-off is still theirs to give',
        );
    }

    /** Not even the actor's own row, which publishing used to settle for them. */
    public function test_publishing_does_not_sign_the_actors_own_source_off(): void
    {
        $case = $this->pageOutForReview(withSourceOwnedBy: 'reviewer');
        // Opening the page is what keeps the requirement rows current.
        $this->reviewAssignmentSeenBy($case['reviewer'], $case['page']);

        $this->actingAs($case['reviewer'])->patch("/app/wiki/{$case['page']->slug}/approve")->assertRedirect();

        $this->assertSame(EnterpriseWikiPage::STATUS_APPROVED, $case['page']->fresh()->status);
        $this->assertTrue($this->approvalFor($case['version'], $case['reviewer'])->isPending());
    }

    /**
     * What is kept. The link from the page to the document it drew on, and the record of who owns
     * that document, are the reason any of this exists — they are just no longer a gate.
     */
    public function test_the_link_to_the_source_and_its_owner_survives(): void
    {
        $case = $this->pageOutForReview(withSourceOwnedBy: 'other');

        $this->actingAs($case['reviewer'])->patch("/app/wiki/{$case['page']->slug}/approve")->assertRedirect();

        $approval = $this->approvalFor($case['version'], $case['documentOwner']);
        $this->assertSame((int) $case['documentOwner']->id, (int) $approval->document_owner_user_id);
        $this->assertSame((int) $case['version']->id, (int) $approval->enterprise_wiki_page_version_id);

        $claim = EnterpriseWikiClaim::query()
            ->where('enterprise_wiki_page_version_id', $case['version']->id)
            ->sole();
        $this->assertSame(
            1,
            EnterpriseWikiSourceReference::query()->where('enterprise_wiki_claim_id', $claim->id)->count(),
            'provenance is untouched',
        );
    }

    // ── The task list ───────────────────────────────────────────────────────

    /** The page is decided, so nobody is still holding it — least of all the person who was asked. */
    public function test_the_reviewers_task_disappears_once_they_decide(): void
    {
        $case = $this->pageOutForReview();

        $this->assertCount(1, $this->wikiTasksFor($case['reviewer']), 'the premise: it was theirs');

        $this->actingAs($case['reviewer'])->patch("/app/wiki/{$case['page']->slug}/approve")->assertRedirect();

        $this->assertSame([], $this->wikiTasksFor($case['reviewer']));
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function reviewAssignmentSeenBy(User $actor, EnterpriseWikiPage $page): array
    {
        $response = $this->actingAs($actor)->get("/app/wiki/{$page->slug}");
        $response->assertOk();

        return $response->viewData('page')['props']['review_assignment'];
    }

    /** @return list<array<string, mixed>> */
    private function wikiTasksFor(User $user): array
    {
        $response = $this->actingAs($user)->get(route('app.info-center.index', ['view' => 'my_tasks']));
        $response->assertOk();

        return $this->wikiTasksIn($response->viewData('page')['props']['infoCenter']);
    }

    private function approvalFor(EnterpriseWikiPageVersion $version, User $owner): EnterpriseWikiPageVersionDocumentOwnerApproval
    {
        return EnterpriseWikiPageVersionDocumentOwnerApproval::query()
            ->where('enterprise_wiki_page_version_id', $version->id)
            ->whereNull('superseded_at')
            ->where('document_owner_user_id', $owner->id)
            ->sole();
    }

    /**
     * A page handed to a reviewer, optionally carrying source material somebody has to vouch for.
     *
     * `withSourceOwnedBy` picks whose sign-off is outstanding: another person, the System Owner
     * themselves, the assigned reviewer, or both the System Owner and another person.
     *
     * @return array<string, mixed>
     */
    /**
     * The same page, left in draft. `withSourceOwnedBy` picks whose sign-off is outstanding:
     * another person, or the System Owner who owns the page.
     *
     * @return array<string, mixed>
     */
    private function draftPage(?string $withSourceOwnedBy = null): array
    {
        return $this->pageOutForReview($withSourceOwnedBy, submit: false);
    }

    private function pageOutForReview(?string $withSourceOwnedBy = null, bool $submit = true): array
    {
        $customer = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $reviewer = $this->approver($customer);

        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'owner_user_id' => $owner->id,
            'slug' => 'systemeier-'.Str::lower(Str::random(8)),
            'title' => 'Sårbarhetsstyring',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => '# Sårbarhetsstyring',
            'generated_by_model' => 'gpt-5',
        ]);

        $documentOwner = null;
        $otherOwner = null;

        if ($withSourceOwnedBy !== null) {
            $documentOwner = match ($withSourceOwnedBy) {
                'reviewer' => $reviewer,
                'self' => $owner,
                'system_owner', 'both' => $this->user($customer, User::BID_ROLE_SYSTEM_OWNER),
                default => $this->user($customer, User::BID_ROLE_CONTRIBUTOR),
            };

            $this->attachSource($page, $version, $documentOwner, 0);

            if ($withSourceOwnedBy === 'both') {
                $otherOwner = $this->user($customer, User::BID_ROLE_CONTRIBUTOR);
                $this->attachSource($page, $version, $otherOwner, 1);
            }
        }

        if ($submit) {
            $this->actingAs($owner)
                ->patch("/app/wiki/{$page->slug}/submit", ['reviewer_user_id' => $reviewer->id])
                ->assertRedirect();
        }

        return [
            'customer' => $customer,
            'owner' => $owner,
            'reviewer' => $reviewer,
            'documentOwner' => $documentOwner,
            'otherOwner' => $otherOwner,
            'page' => $page->fresh(),
            'version' => $version->fresh(),
        ];
    }

    private function attachSource(EnterpriseWikiPage $page, EnterpriseWikiPageVersion $version, User $documentOwner, int $order): void
    {
        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $page->customer_id,
            'owner_user_id' => $documentOwner->id,
            'original_filename' => 'kilde-'.Str::random(4).'.docx',
            'file_path' => 'wiki-documents/'.$page->customer_id.'/'.Str::random(16).'.docx',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Kildetekst',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);

        $claim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => "Påstand {$order}.",
            'position_order' => $order,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ]);

        EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $claim->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_label' => $document->original_filename,
            'excerpt' => 'Utdrag',
        ]);
    }

    private function approver(Customer $customer): User
    {
        return $this->user($customer, User::BID_ROLE_CONTRIBUTOR, isWikiApprover: true);
    }

    private function user(Customer $customer, string $bidRole, bool $isWikiApprover = false): User
    {
        $user = User::query()->create([
            'name' => 'Bruker '.Str::random(5),
            'email' => Str::lower(Str::random(9)).'@systemeier.test',
            'password' => bcrypt('secret'),
            'role' => $bidRole === User::BID_ROLE_SYSTEM_OWNER ? User::ROLE_CUSTOMER_ADMIN : User::ROLE_USER,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
            'is_wiki_approver' => $isWikiApprover,
        ]);

        $this->grantWikiPermissions($customer, $user);

        return $user;
    }

    private function customer(string $name = 'Systemeier AS'): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }
}
