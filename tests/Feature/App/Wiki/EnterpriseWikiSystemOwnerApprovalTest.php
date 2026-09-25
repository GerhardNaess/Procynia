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
use Tests\TestCase;

/**
 * Who may finish a Wiki page, and the line between final authority and the capability to approve.
 *
 * A SYSTEM OWNER is the customer's final authority over their own Wiki. They do not have to be
 * chosen as reviewer, and they are not stopped by having submitted the page themselves. That last
 * part is a deliberate trade, made explicitly: one person can take a page from draft to published
 * alone, and the audit trail is what keeps that honest.
 *
 * A WIKI APPROVER may decide the page they were handed, and only that one. Holding the capability
 * is not the same as having been asked, and the four-eyes rule still holds for them.
 *
 * The distinction is the whole point, so most of what follows is about who is REFUSED. A rule that
 * only ever says yes is not a rule.
 */
class EnterpriseWikiSystemOwnerApprovalTest extends TestCase
{
    use DatabaseTransactions;

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

    /** Somebody else was asked. The System Owner can finish it regardless. */
    public function test_a_system_owner_approves_a_page_assigned_to_someone_else(): void
    {
        $case = $this->pageOutForReview();
        $systemOwner = $this->user($case['customer'], User::BID_ROLE_SYSTEM_OWNER);

        $this->assertTrue($this->reviewAssignmentSeenBy($systemOwner, $case['page'])['can_approve_final']);

        $this->actingAs($systemOwner)
            ->patch("/app/wiki/{$case['page']->slug}/approve")
            ->assertRedirect();

        $page = $case['page']->fresh();

        $this->assertSame(EnterpriseWikiPage::STATUS_APPROVED, $page->status);
        $this->assertSame((int) $case['version']->id, (int) $page->published_version_id);
        $this->assertNotNull($page->reviewed_at);
    }

    /**
     * Who actually published it. The assignment is history and stays readable, so the page can say
     * "reviewer: X, published by: Y" rather than crediting the decision to whoever was asked.
     */
    public function test_the_audit_trail_names_the_person_who_actually_decided(): void
    {
        $case = $this->pageOutForReview();
        $systemOwner = $this->user($case['customer'], User::BID_ROLE_SYSTEM_OWNER);

        $this->actingAs($systemOwner)->patch("/app/wiki/{$case['page']->slug}/approve")->assertRedirect();

        $page = $case['page']->fresh();
        $version = $case['version']->fresh();

        $this->assertSame((int) $systemOwner->id, (int) $page->reviewed_by_user_id);
        $this->assertSame((int) $case['reviewer']->id, (int) $version->reviewer_user_id, 'who was asked');
        $this->assertSame((int) $case['owner']->id, (int) $version->submitted_by_user_id, 'who sent it');
        $this->assertNotNull($version->submitted_at);
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

    // ── The document-owner gate ─────────────────────────────────────────────

    /**
     * Another person's judgement on their own material. Final authority over the Wiki is not
     * authority to sign in somebody else's name, so this still stops the page.
     */
    public function test_another_owners_outstanding_signoff_still_blocks_a_system_owner(): void
    {
        $case = $this->pageOutForReview(withSourceOwnedBy: 'other');
        $systemOwner = $this->user($case['customer'], User::BID_ROLE_SYSTEM_OWNER);

        $payload = $this->reviewAssignmentSeenBy($systemOwner, $case['page']);

        $this->assertFalse($payload['can_approve_final']);
        $this->assertSame('source_owners_pending', $payload['final_approval_blocker']);

        $this->actingAs($systemOwner)
            ->patch("/app/wiki/{$case['page']->slug}/approve")
            ->assertStatus(409);

        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $case['page']->fresh()->status);
        $this->assertTrue(
            $this->approvalFor($case['version'], $case['documentOwner'])->isPending(),
            'and nobody signed on their behalf',
        );
    }

    /**
     * Their own outstanding row is not somebody they are waiting for — it is their own second
     * click, on the same version, for the same judgement. Publishing carries it.
     */
    public function test_a_system_owners_own_signoff_is_settled_by_publishing(): void
    {
        $case = $this->pageOutForReview(withSourceOwnedBy: 'system_owner');
        $systemOwner = $case['documentOwner'];

        $payload = $this->reviewAssignmentSeenBy($systemOwner, $case['page']);
        $this->assertTrue($payload['can_approve_final'], 'not reported as waiting on themselves');

        $this->actingAs($systemOwner)
            ->patch("/app/wiki/{$case['page']->slug}/approve")
            ->assertRedirect();

        $this->assertSame(EnterpriseWikiPage::STATUS_APPROVED, $case['page']->fresh()->status);

        $approval = $this->approvalFor($case['version'], $systemOwner);
        $this->assertTrue($approval->isApproved());
        $this->assertSame((int) $systemOwner->id, (int) $approval->decided_by_user_id);
        // Their own decision on their own document. Overriding is what you do to someone else.
        $this->assertFalse((bool) $approval->is_override);
    }

    /**
     * Both at once, which is the case that decides whether the shortcut is safe: their own row
     * clears, the other person's does not, and the page does not publish.
     */
    public function test_their_own_row_clears_while_another_owners_still_stops_the_page(): void
    {
        $case = $this->pageOutForReview(withSourceOwnedBy: 'both');
        $systemOwner = $case['documentOwner'];

        $this->actingAs($systemOwner)
            ->patch("/app/wiki/{$case['page']->slug}/approve")
            ->assertStatus(409);

        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $case['page']->fresh()->status);
        $this->assertTrue($this->approvalFor($case['version'], $case['otherOwner'])->isPending());
        // The gate refused before anything was written, so their own row is untouched too.
        $this->assertTrue($this->approvalFor($case['version'], $systemOwner)->isPending());
    }

    /** An ordinary reviewer gets no such shortcut; only their own sign-off would be theirs to give. */
    public function test_an_ordinary_reviewer_is_not_given_the_shortcut(): void
    {
        $case = $this->pageOutForReview(withSourceOwnedBy: 'reviewer');

        $payload = $this->reviewAssignmentSeenBy($case['reviewer'], $case['page']);

        $this->assertFalse($payload['can_approve_final']);
        $this->assertSame('source_owners_pending', $payload['final_approval_blocker']);
    }

    // ── The task list ───────────────────────────────────────────────────────

    /** The page is decided, so nobody is still holding it — least of all the person who was asked. */
    public function test_the_reviewers_task_disappears_when_a_system_owner_finishes_the_page(): void
    {
        $case = $this->pageOutForReview();
        $systemOwner = $this->user($case['customer'], User::BID_ROLE_SYSTEM_OWNER);

        $this->assertCount(1, $this->wikiTasksFor($case['reviewer']), 'the premise: it was theirs');

        $this->actingAs($systemOwner)->patch("/app/wiki/{$case['page']->slug}/approve")->assertRedirect();

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

        return $response->viewData('page')['props']['infoCenter']['wiki_tasks'];
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
    private function pageOutForReview(?string $withSourceOwnedBy = null): array
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
                'system_owner', 'both' => $this->user($customer, User::BID_ROLE_SYSTEM_OWNER),
                default => $this->user($customer, User::BID_ROLE_CONTRIBUTOR),
            };

            $this->attachSource($page, $version, $documentOwner, 0);

            if ($withSourceOwnedBy === 'both') {
                $otherOwner = $this->user($customer, User::BID_ROLE_CONTRIBUTOR);
                $this->attachSource($page, $version, $otherOwner, 1);
            }
        }

        $this->actingAs($owner)
            ->patch("/app/wiki/{$page->slug}/submit", ['reviewer_user_id' => $reviewer->id])
            ->assertRedirect();

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
        return User::query()->create([
            'name' => 'Bruker '.Str::random(5),
            'email' => Str::lower(Str::random(9)).'@systemeier.test',
            'password' => bcrypt('secret'),
            'role' => $bidRole === User::BID_ROLE_SYSTEM_OWNER ? User::ROLE_CUSTOMER_ADMIN : User::ROLE_USER,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
            'is_wiki_approver' => $isWikiApprover,
        ]);
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
