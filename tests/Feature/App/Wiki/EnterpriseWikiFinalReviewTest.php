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
 * Final Wiki review is the publishing decision, not a status change.
 *
 * The assigned reviewer judges the whole working version and decides whether it becomes the
 * published one. Everything before it — claim approval, document-owner approval — answers a narrower
 * question; this is the last human gate.
 *
 * The invariant that matters: an approved page always names what was approved. Status and
 * published_version_id move together, under a row lock, so two people pressing approve at the same
 * moment cannot leave the page approved with nothing published.
 *
 * See docs/enterprise-wiki-approval-model.md §6 and §10.
 */
class EnterpriseWikiFinalReviewTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    // A + B. the assigned reviewer approves, and the decision is fully recorded
    public function test_the_assigned_reviewer_publishes_the_working_version(): void
    {
        $case = $this->readyForFinalReview();

        $this->actingAs($case['reviewer'])
            ->patch("/app/wiki/{$case['page']->slug}/approve")
            ->assertRedirect(route('app.wiki.show', $case['page']->slug));

        $page = $case['page']->fresh();
        $this->assertSame(EnterpriseWikiPage::STATUS_APPROVED, $page->status);
        $this->assertSame($case['version']->id, (int) $page->published_version_id);
        $this->assertNotNull($page->reviewed_at);
        $this->assertSame($case['reviewer']->id, (int) $page->reviewed_by_user_id);
    }

    // M. approved never means "published nothing"
    public function test_an_approved_page_always_names_the_version_it_published(): void
    {
        $case = $this->readyForFinalReview();
        $this->actingAs($case['reviewer'])->patch("/app/wiki/{$case['page']->slug}/approve");

        $page = $case['page']->fresh();
        $this->assertSame(EnterpriseWikiPage::STATUS_APPROVED, $page->status);
        $this->assertNotNull($page->published_version_id);
    }

    // C. publication moves, history stays
    public function test_approving_a_second_version_moves_publication_and_keeps_the_first(): void
    {
        $case = $this->readyForFinalReview();
        $v1 = $case['version'];
        $this->actingAs($case['reviewer'])->patch("/app/wiki/{$case['page']->slug}/approve");

        $v2 = $this->newWorkingVersion($case['page'], $v1);
        $this->submitFor($case['page'], $case['reviewer']);
        $this->clearSourceOwnerGate($case['page'], $v2, $case['documentOwners']);

        $this->actingAs($case['reviewer'])
            ->patch("/app/wiki/{$case['page']->slug}/approve")
            ->assertRedirect(route('app.wiki.show', $case['page']->slug));

        $page = $case['page']->fresh();
        $this->assertSame($v2->id, (int) $page->published_version_id);
        $this->assertNotNull(EnterpriseWikiPageVersion::query()->find($v1->id), 'the earlier version is kept');
        $this->assertSame($v1->id, (int) EnterpriseWikiPageVersion::query()->find($v1->id)->id);
    }

    // N. a published version and a version under review coexist
    public function test_a_page_under_review_still_points_at_the_previously_approved_version(): void
    {
        $case = $this->readyForFinalReview();
        $v1 = $case['version'];
        $this->actingAs($case['reviewer'])->patch("/app/wiki/{$case['page']->slug}/approve");

        $this->newWorkingVersion($case['page'], $v1);
        $this->submitFor($case['page'], $case['reviewer']);

        $page = $case['page']->fresh();
        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $page->status);
        $this->assertSame($v1->id, (int) $page->published_version_id, 'readers keep v1 while v2 is judged');
    }

    // D + E. the source-owner gate holds
    public function test_a_pending_document_owner_blocks_final_approval(): void
    {
        $case = $this->submittedPage();

        $this->actingAs($case['reviewer'])
            ->patch("/app/wiki/{$case['page']->slug}/approve")
            ->assertStatus(409);

        $this->assertNull($case['page']->fresh()->published_version_id);
    }

    public function test_a_rejecting_document_owner_blocks_final_approval(): void
    {
        $case = $this->submittedPage();
        $requirement = $this->activeRequirements($case['version'])->first();

        $this->actingAs($case['documentOwners'][0])
            ->patch("/app/wiki/{$case['page']->slug}/document-owner-approvals/{$requirement->id}/reject", ['comment' => 'Kildegrunnlaget stemmer ikke med innholdet.']);

        // A refusal returns the version to its owner, so the page has left review — 422 rather than
        // 409. What matters is that it cannot be published.
        $this->actingAs($case['reviewer'])
            ->patch("/app/wiki/{$case['page']->slug}/approve")
            ->assertStatus(422);

        $this->assertNull($case['page']->fresh()->published_version_id);
    }

    // F. capability alone is not enough
    public function test_a_reviewer_who_was_not_assigned_is_refused(): void
    {
        $case = $this->readyForFinalReview();
        $otherReviewer = $this->user($case['customer'], User::BID_ROLE_BID_MANAGER);

        $this->actingAs($otherReviewer)
            ->patch("/app/wiki/{$case['page']->slug}/approve")
            ->assertForbidden();

        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $case['page']->fresh()->status);
    }

    // G. assignment alone is not enough either
    public function test_an_assigned_reviewer_who_lost_the_capability_is_refused(): void
    {
        $case = $this->readyForFinalReview();
        $this->grant($case['customer'], Customer::PERMISSION_APPROVE_WIKI_PAGES, []);

        $this->actingAs($case['reviewer']->fresh())
            ->patch("/app/wiki/{$case['page']->slug}/approve")
            ->assertForbidden();
    }

    // H + I. separation of duties survives the System Owner override
    public function test_the_submitter_cannot_approve_their_own_version(): void
    {
        $case = $this->readyForFinalReview();
        $submitter = User::query()->findOrFail($case['page']->owner_user_id);
        $this->grant($case['customer'], Customer::PERMISSION_APPROVE_WIKI_PAGES, ['contributor', 'bid_manager']);

        $this->actingAs($submitter->fresh())
            ->patch("/app/wiki/{$case['page']->slug}/approve")
            ->assertForbidden();
    }

    public function test_a_system_owner_may_take_over_but_not_approve_their_own_submission(): void
    {
        $case = $this->readyForFinalReview();
        $systemOwner = $this->user($case['customer'], User::BID_ROLE_SYSTEM_OWNER);

        $this->actingAs($systemOwner)
            ->patch("/app/wiki/{$case['page']->slug}/approve")
            ->assertRedirect(route('app.wiki.show', $case['page']->slug));

        // The takeover is recorded as the actual decision-maker.
        $this->assertSame($systemOwner->id, (int) $case['page']->fresh()->reviewed_by_user_id);
    }

    public function test_a_system_owner_who_submitted_the_version_cannot_approve_it(): void
    {
        $case = $this->submittedPage();
        $systemOwner = $this->user($case['customer'], User::BID_ROLE_SYSTEM_OWNER);
        $case['page']->forceFill(['status' => EnterpriseWikiPage::STATUS_DRAFT])->save();
        $case['version']->forceFill([
            'submitted_by_user_id' => null,
            'submitted_at' => null,
            'reviewer_user_id' => null,
        ])->save();

        $this->actingAs($systemOwner)->patch("/app/wiki/{$case['page']->slug}/submit", [
            'reviewer_user_id' => $case['reviewer']->id,
        ])->assertRedirect();

        $this->clearSourceOwnerGate($case['page'], $case['version'], $case['documentOwners']);

        $this->actingAs($systemOwner)
            ->patch("/app/wiki/{$case['page']->slug}/approve")
            ->assertForbidden();
    }

    // J. the handover record is history, not scratch space
    public function test_approving_preserves_the_submission_record(): void
    {
        $case = $this->readyForFinalReview();
        $before = $case['version']->fresh();

        $this->actingAs($case['reviewer'])->patch("/app/wiki/{$case['page']->slug}/approve");

        $after = $case['version']->fresh();
        $this->assertSame((int) $before->submitted_by_user_id, (int) $after->submitted_by_user_id);
        $this->assertEquals($before->submitted_at, $after->submitted_at);
        $this->assertSame((int) $before->reviewer_user_id, (int) $after->reviewer_user_id);
    }

    // K. rejection never withdraws published content
    public function test_rejecting_leaves_the_published_version_alone(): void
    {
        $case = $this->readyForFinalReview();
        $v1 = $case['version'];
        $this->actingAs($case['reviewer'])->patch("/app/wiki/{$case['page']->slug}/approve");

        $this->newWorkingVersion($case['page'], $v1);
        $this->submitFor($case['page'], $case['reviewer']);

        $this->actingAs($case['reviewer'])
            ->patch("/app/wiki/{$case['page']->slug}/reject", ['reason' => 'Kildegrunnlaget stemmer ikke med innholdet.'])
            ->assertRedirect(route('app.wiki.show', $case['page']->slug));

        $page = $case['page']->fresh();
        $this->assertSame(EnterpriseWikiPage::STATUS_REJECTED, $page->status);
        $this->assertSame($v1->id, (int) $page->published_version_id);
    }

    // L + O. a decision can only be made once
    public function test_approving_twice_is_refused_the_second_time(): void
    {
        $case = $this->readyForFinalReview();

        $this->actingAs($case['reviewer'])
            ->patch("/app/wiki/{$case['page']->slug}/approve")
            ->assertRedirect(route('app.wiki.show', $case['page']->slug));

        $publishedAfterFirst = (int) $case['page']->fresh()->published_version_id;

        $this->actingAs($case['reviewer'])
            ->patch("/app/wiki/{$case['page']->slug}/approve")
            ->assertStatus(422);

        $this->assertSame(
            $publishedAfterFirst,
            (int) $case['page']->fresh()->published_version_id,
            'the second attempt moves nothing',
        );
    }

    public function test_a_second_reviewer_cannot_overwrite_a_decision_already_made(): void
    {
        // The row lock serialises concurrent attempts; whoever loses re-reads a page that is no
        // longer pending_review. This asserts the losing path leaves the winner's decision intact.
        $case = $this->readyForFinalReview();
        $systemOwner = $this->user($case['customer'], User::BID_ROLE_SYSTEM_OWNER);

        $this->actingAs($case['reviewer'])->patch("/app/wiki/{$case['page']->slug}/approve");

        $this->actingAs($systemOwner)
            ->patch("/app/wiki/{$case['page']->slug}/reject", ['reason' => 'Kildegrunnlaget stemmer ikke med innholdet.'])
            ->assertStatus(422);

        $page = $case['page']->fresh();
        $this->assertSame(EnterpriseWikiPage::STATUS_APPROVED, $page->status);
        $this->assertSame($case['reviewer']->id, (int) $page->reviewed_by_user_id);
    }

    public function test_the_payload_says_whether_final_approval_is_available_and_why_not(): void
    {
        $case = $this->submittedPage();

        $this->actingAs($case['reviewer'])
            ->get("/app/wiki/{$case['page']->slug}")
            ->assertSuccessful()
            ->assertInertia(fn ($inertia) => $inertia
                ->where('review_assignment.can_approve_final', false)
                ->where('review_assignment.final_approval_blocker', 'source_owners_pending')
                ->where('review_assignment.working_version_id', $case['version']->id));

        $this->clearSourceOwnerGate($case['page'], $case['version'], $case['documentOwners']);

        $this->actingAs($case['reviewer'])
            ->get("/app/wiki/{$case['page']->slug}")
            ->assertInertia(fn ($inertia) => $inertia
                ->where('review_assignment.can_approve_final', true)
                ->where('review_assignment.final_approval_blocker', null));
    }

    public function test_the_payload_tells_a_submitter_why_they_cannot_approve(): void
    {
        $case = $this->readyForFinalReview();
        $submitter = User::query()->findOrFail($case['page']->owner_user_id);
        $this->grant($case['customer'], Customer::PERMISSION_APPROVE_WIKI_PAGES, ['contributor', 'bid_manager']);

        $this->actingAs($submitter->fresh())
            ->get("/app/wiki/{$case['page']->slug}")
            ->assertInertia(fn ($inertia) => $inertia
                ->where('review_assignment.final_approval_blocker', 'own_submission'));
    }

    // submit() is the only ordinary way into pending_review
    public function test_a_finished_ingest_leaves_the_page_in_draft(): void
    {
        // FinalizeEnterpriseWikiIngest used to set pending_review directly, which produced pages
        // waiting on nobody. A finished run produces work, not a review request.
        $source = file_get_contents(base_path('app/Jobs/Ai/Wiki/FinalizeEnterpriseWikiIngest.php'));
        $at = strpos($source, "->where('id', \$run->enterprise_wiki_page_id)");

        $this->assertNotFalse($at, 'the page status update is gone');
        $this->assertStringContainsString(
            'STATUS_DRAFT',
            substr($source, $at, 200),
            'a finished ingest must leave the page in draft',
        );
    }

    public function test_submitting_is_what_produces_a_complete_assignment(): void
    {
        $case = $this->submittedPage();
        $version = $case['version']->fresh();

        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $case['page']->fresh()->status);
        $this->assertNotNull($version->submitted_by_user_id);
        $this->assertNotNull($version->submitted_at);
        $this->assertNotNull($version->reviewer_user_id);
    }

    public function test_a_pending_page_without_an_assignment_cannot_be_decided_by_anyone(): void
    {
        // Legacy shape only: pending_review reached without going through submit().
        $case = $this->readyForFinalReview();
        $case['version']->forceFill([
            'submitted_by_user_id' => null,
            'submitted_at' => null,
            'reviewer_user_id' => null,
        ])->save();

        foreach ([$case['reviewer'], $this->user($case['customer'], User::BID_ROLE_SYSTEM_OWNER)] as $actor) {
            $this->actingAs($actor)
                ->patch("/app/wiki/{$case['page']->slug}/approve")
                ->assertStatus(409);

            $this->actingAs($actor)
                ->patch("/app/wiki/{$case['page']->slug}/reject", ['reason' => 'Kildegrunnlaget stemmer ikke med innholdet.'])
                ->assertStatus(409);
        }

        $page = $case['page']->fresh();
        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $page->status);
        $this->assertNull($page->published_version_id, 'nothing was published on the way past the guard');
    }

    public function test_the_payload_reports_a_missing_assignment_as_the_blocker(): void
    {
        $case = $this->readyForFinalReview();
        $case['version']->forceFill([
            'submitted_by_user_id' => null,
            'submitted_at' => null,
            'reviewer_user_id' => null,
        ])->save();

        $this->actingAs($case['reviewer'])
            ->get("/app/wiki/{$case['page']->slug}")
            ->assertInertia(fn ($inertia) => $inertia
                ->where('review_assignment.can_approve_final', false)
                ->where('review_assignment.final_approval_blocker', 'missing_assignment'));
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    /** A submitted page whose source owners have all signed off. */
    private function readyForFinalReview(): array
    {
        $case = $this->submittedPage();
        $this->clearSourceOwnerGate($case['page'], $case['version'], $case['documentOwners']);

        return $case;
    }

    /** A page handed to a reviewer, with one source document still awaiting its owner. */
    private function submittedPage(): array
    {
        $customer = $this->customer();
        $pageOwner = $this->user($customer);
        $documentOwner = $this->user($customer);
        $reviewer = $this->reviewer($customer);

        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'owner_user_id' => $pageOwner->id,
            'slug' => 'sluttreview-'.Str::lower(Str::random(6)),
            'title' => 'Sluttreview Side',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => '# Sluttreview Side',
            'generated_by_model' => 'gpt-5',
        ]);

        $this->attachSource($page, $version, $this->document($customer, $documentOwner), 0);
        $this->submitFor($page, $reviewer);

        return [
            'customer' => $customer,
            'page' => $page->fresh(),
            'version' => $version,
            'reviewer' => $reviewer,
            'documentOwners' => [$documentOwner],
        ];
    }

    private function submitFor(EnterpriseWikiPage $page, User $reviewer): void
    {
        $owner = User::query()->findOrFail($page->owner_user_id);

        $this->actingAs($owner)
            ->patch("/app/wiki/{$page->slug}/submit", ['reviewer_user_id' => $reviewer->id])
            ->assertRedirect(route('app.wiki.show', $page->slug));
    }

    /** A regenerated working version, carrying the same source so the gate is real. */
    private function newWorkingVersion(EnterpriseWikiPage $page, EnterpriseWikiPageVersion $previous): EnterpriseWikiPageVersion
    {
        $previous->forceFill(['is_current' => false])->save();

        $next = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => $previous->version_number + 1,
            'is_current' => true,
            'content_markdown' => '# Ny versjon',
            'generated_by_model' => 'gpt-5',
        ]);

        $documentId = EnterpriseWikiSourceReference::query()
            ->whereIn('enterprise_wiki_claim_id', EnterpriseWikiClaim::query()
                ->where('enterprise_wiki_page_version_id', $previous->id)
                ->pluck('id'))
            ->value('source_id');

        if ($documentId !== null) {
            $this->attachSource($page, $next, EnterpriseWikiDocument::query()->findOrFail($documentId), 0);
        }

        EnterpriseWikiPage::query()->whereKey($page->id)->update(['status' => EnterpriseWikiPage::STATUS_DRAFT]);

        return $next;
    }

    private function attachSource(EnterpriseWikiPage $page, EnterpriseWikiPageVersion $version, EnterpriseWikiDocument $document, int $order): void
    {
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

    /** @param list<User> $documentOwners */
    private function clearSourceOwnerGate(EnterpriseWikiPage $page, EnterpriseWikiPageVersion $version, array $documentOwners): void
    {
        foreach ($this->activeRequirements($version) as $requirement) {
            $owner = collect($documentOwners)->firstWhere('id', $requirement->document_owner_user_id);

            $this->actingAs($owner)
                ->patch("/app/wiki/{$page->slug}/document-owner-approvals/{$requirement->id}/approve")
                ->assertRedirect(route('app.wiki.show', $page->slug));
        }
    }

    private function activeRequirements(EnterpriseWikiPageVersion $version)
    {
        return EnterpriseWikiPageVersionDocumentOwnerApproval::query()
            ->where('enterprise_wiki_page_version_id', $version->id)
            ->whereNull('superseded_at')
            ->orderBy('id')
            ->get();
    }

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

    private function customer(): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => 'Sluttreview Test AS',
            'slug' => 'sluttreview-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    private function user(Customer $customer, string $bidRole = User::BID_ROLE_CONTRIBUTOR): User
    {
        return User::query()->create([
            'name' => 'Bruker '.Str::random(5),
            'email' => Str::lower(Str::random(8)).'@sluttreview.test',
            'password' => bcrypt('secret'),
            'role' => in_array($bidRole, [User::BID_ROLE_SYSTEM_OWNER, User::BID_ROLE_BID_MANAGER], true)
                ? User::ROLE_CUSTOMER_ADMIN
                : User::ROLE_USER,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function document(Customer $customer, User $owner): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'owner_user_id' => $owner->id,
            'original_filename' => 'kilde-'.Str::random(4).'.docx',
            'file_path' => 'wiki-documents/'.$customer->id.'/'.Str::random(16).'.docx',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Kildetekst',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }
}
