<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Whether the page offers the reviewer the return that the endpoint would actually honour.
 *
 * The two review decisions never had the same conditions. Publishing waits for every document owner
 * and refuses a version that changed after it was handed over. Sending the page back waits for
 * neither — deliberately, because it is the way out of both: a page that could not be approved,
 * could not be returned and could not be resubmitted would be stuck for good.
 *
 * The panel gated both on can_approve_final, so exactly when a reviewer was blocked, the one action
 * still open to them disappeared. These assert that can_send_back tracks reject()'s real rule, by
 * checking the payload and the endpoint against each other in the same scenario — a flag that
 * drifted from the endpoint would be a button that lies in one direction or the other.
 */
class EnterpriseWikiSendBackAvailabilityTest extends TestCase
{
    use DatabaseTransactions;

    private const COMMENT = 'Andre avsnitt må avklares mot kilden før siden kan publiseres.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    // ── The case that was broken ────────────────────────────────────────────

    /**
     * An open document-owner gate blocks publication and nothing else. This is the exact state the
     * reviewer was left in with no action at all.
     */
    public function test_an_open_document_owner_gate_blocks_approval_but_not_the_return(): void
    {
        $case = $this->pageOutForReview(withSourceDocument: true);

        $payload = $this->reviewAssignmentSeenBy($case['reviewer'], $case['page']);

        $this->assertFalse($payload['can_approve_final'], 'the premise: publication is blocked');
        $this->assertSame('source_owners_pending', $payload['final_approval_blocker']);
        $this->assertTrue($payload['can_send_back'], 'and the way out is still offered');
    }

    /** And the endpoint agrees — which is what makes the offer honest. */
    public function test_the_return_really_is_accepted_while_the_gate_is_open(): void
    {
        $case = $this->pageOutForReview(withSourceDocument: true);

        $this->actingAs($case['reviewer'])
            ->patch("/app/wiki/{$case['page']->slug}/reject", ['reason' => self::COMMENT])
            ->assertRedirect(route('app.wiki.show', $case['page']->slug));

        $this->assertSame(EnterpriseWikiPage::STATUS_REJECTED, $case['page']->fresh()->status);
    }

    /**
     * A version edited after the handover cannot be approved — approving would publish content the
     * reviewer never saw. Returning it is the documented way out, so it has to be on offer.
     */
    public function test_a_version_that_moved_can_still_be_sent_back(): void
    {
        $case = $this->pageOutForReview();
        // What an owner's save looks like from here: the submission record no longer matches.
        $case['version']->forceFill(['submitted_at' => null, 'submitted_by_user_id' => null])->save();

        $payload = $this->reviewAssignmentSeenBy($case['reviewer'], $case['page']);

        $this->assertFalse($payload['can_approve_final']);
        $this->assertSame('missing_assignment', $payload['final_approval_blocker']);
        $this->assertTrue($payload['can_send_back']);

        $this->actingAs($case['reviewer'])
            ->patch("/app/wiki/{$case['page']->slug}/reject", ['reason' => self::COMMENT])
            ->assertRedirect();

        $this->assertSame(EnterpriseWikiPage::STATUS_REJECTED, $case['page']->fresh()->status);
    }

    // ── The ordinary case still works ───────────────────────────────────────

    public function test_an_unblocked_reviewer_is_offered_both_decisions(): void
    {
        $case = $this->pageOutForReview();

        $payload = $this->reviewAssignmentSeenBy($case['reviewer'], $case['page']);

        $this->assertTrue($payload['can_approve_final']);
        $this->assertTrue($payload['can_send_back']);
        $this->assertSame($case['reviewer']->name, $payload['reviewer']['name']);
    }

    // ── Who it is not offered to ────────────────────────────────────────────

    /** Offering a return to somebody the endpoint would refuse is the same bug in reverse. */
    public function test_a_bystander_is_offered_neither_and_refused_both(): void
    {
        $case = $this->pageOutForReview();
        $bystander = $this->approver($case['customer']);

        $payload = $this->reviewAssignmentSeenBy($bystander, $case['page']);

        $this->assertFalse($payload['can_send_back']);
        $this->assertFalse($payload['can_approve_final']);

        $this->actingAs($bystander)
            ->patch("/app/wiki/{$case['page']->slug}/reject", ['reason' => self::COMMENT])
            ->assertForbidden();
        $this->actingAs($bystander)
            ->patch("/app/wiki/{$case['page']->slug}/approve")
            ->assertForbidden();
    }

    /** The capability is what qualifies, as it does everywhere else in review. */
    public function test_someone_without_the_capability_is_offered_nothing(): void
    {
        $case = $this->pageOutForReview();
        $plain = $this->user($case['customer'], User::BID_ROLE_CONTRIBUTOR);

        $this->assertFalse($this->reviewAssignmentSeenBy($plain, $case['page'])['can_send_back']);
    }

    public function test_the_submitter_cannot_send_back_their_own_handover(): void
    {
        $case = $this->pageOutForReview();

        // The owner submitted it; the version names somebody else as reviewer.
        $this->assertFalse($this->reviewAssignmentSeenBy($case['owner'], $case['page'])['can_send_back']);
    }

    // ── Not a page in review ────────────────────────────────────────────────

    public function test_a_draft_page_offers_no_return(): void
    {
        $case = $this->draftPage();

        $this->assertFalse($this->reviewAssignmentSeenBy($case['reviewer'], $case['page'])['can_send_back']);
    }

    public function test_a_page_already_decided_offers_no_return(): void
    {
        $case = $this->pageOutForReview();
        $this->actingAs($case['reviewer'])->patch("/app/wiki/{$case['page']->slug}/approve")->assertRedirect();

        $payload = $this->reviewAssignmentSeenBy($case['reviewer'], $case['page']->fresh());

        $this->assertFalse($payload['can_send_back']);
        $this->assertFalse($payload['can_approve_final']);
    }

    // ── Scope ───────────────────────────────────────────────────────────────

    public function test_a_reviewer_at_another_customer_never_sees_the_page(): void
    {
        $case = $this->pageOutForReview();
        $outsider = $this->approver($this->customer('Annen Kunde AS'));

        $this->actingAs($outsider)->get("/app/wiki/{$case['page']->slug}")->assertNotFound();
    }

    // ── What the owner reads afterwards ─────────────────────────────────────

    /**
     * The comment is the whole point of the return: without it the owner knows only that somebody
     * was unhappy. It is stored on the review event and reaches the page the owner opens.
     */
    public function test_the_comment_reaches_the_owner_on_the_page_they_open(): void
    {
        $case = $this->pageOutForReview();

        $this->actingAs($case['reviewer'])
            ->patch("/app/wiki/{$case['page']->slug}/reject", ['reason' => self::COMMENT])
            ->assertRedirect();

        $changes = $this->reviewAssignmentSeenBy($case['owner'], $case['page']->fresh())['changes_requested'];

        $this->assertTrue($changes['is_returned']);
        $this->assertSame(self::COMMENT, $changes['latest']['reason']);
        $this->assertSame($case['reviewer']->name, $changes['latest']['actor']['name']);
        $this->assertSame('reviewer', $changes['latest']['actor_role']);
        $this->assertNotNull($changes['latest']['created_at'], 'the owner needs to know when');
        $this->assertSame(
            (int) $case['version']->id,
            (int) $changes['latest']['page_version_id'],
            'the comment belongs to the version it was written about',
        );
    }

    /** Backend validates it; the dialog is not the only thing standing between here and an empty one. */
    public function test_a_return_without_a_comment_is_refused(): void
    {
        $case = $this->pageOutForReview();

        $this->actingAs($case['reviewer'])
            ->patch("/app/wiki/{$case['page']->slug}/reject", ['reason' => '   '])
            ->assertSessionHasErrors('reason');

        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $case['page']->fresh()->status);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function reviewAssignmentSeenBy(User $actor, EnterpriseWikiPage $page): array
    {
        $response = $this->actingAs($actor)->get("/app/wiki/{$page->slug}");
        $response->assertOk();

        return $response->viewData('page')['props']['review_assignment'];
    }

    /** @return array<string, mixed> */
    private function pageOutForReview(bool $withSourceDocument = false): array
    {
        $case = $this->draftPage();

        if ($withSourceDocument) {
            $this->attachSource($case['page'], $case['version'], $case['owner']);
        }

        $this->actingAs($case['owner'])
            ->patch("/app/wiki/{$case['page']->slug}/submit", ['reviewer_user_id' => $case['reviewer']->id])
            ->assertRedirect();

        $case['page']->refresh();
        $case['version']->refresh();

        return $case;
    }

    /**
     * A claim backed by a document somebody owns — which is what creates a document-owner
     * requirement when the page is submitted, and therefore the gate.
     */
    private function attachSource(EnterpriseWikiPage $page, EnterpriseWikiPageVersion $version, User $documentOwner): void
    {
        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $page->customer_id,
            'owner_user_id' => $documentOwner->id,
            'original_filename' => 'tjenestebeskrivelse.docx',
            'file_path' => 'wiki-documents/'.$page->customer_id.'/'.Str::random(16).'.docx',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Exit-prosessen varer i tolv uker.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);

        $claim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'Exit-prosessen varer i tolv uker.',
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ]);

        EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $claim->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_label' => $document->original_filename,
            'excerpt' => 'Exit-prosessen varer i tolv uker.',
        ]);
    }

    /** @return array<string, mixed> */
    private function draftPage(): array
    {
        $customer = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $reviewer = $this->approver($customer);

        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'owner_user_id' => $owner->id,
            'slug' => 'retur-'.Str::lower(Str::random(8)),
            'title' => 'Exit-prosess (tjenestenedtrapping)',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => '# Exit-prosess',
            'generated_by_model' => 'gpt-5',
        ]);

        return compact('customer', 'owner', 'reviewer', 'page', 'version');
    }

    private function approver(Customer $customer): User
    {
        return $this->user($customer, User::BID_ROLE_CONTRIBUTOR, isWikiApprover: true);
    }

    private function user(Customer $customer, string $bidRole, bool $isWikiApprover = false): User
    {
        return User::query()->create([
            'name' => 'Bruker '.Str::random(5),
            'email' => Str::lower(Str::random(9)).'@retur.test',
            'password' => bcrypt('secret'),
            'role' => $bidRole === User::BID_ROLE_SYSTEM_OWNER ? User::ROLE_CUSTOMER_ADMIN : User::ROLE_USER,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
            'is_wiki_approver' => $isWikiApprover,
        ]);
    }

    private function customer(string $name = 'Retur AS'): Customer
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
