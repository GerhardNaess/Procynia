<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageReviewEvent;
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
 * Sending a version back to its owner, with a reason they can act on.
 *
 * Two people can ask for changes, about different things: the assigned reviewer about the page as a
 * whole, a document owner about the content drawn from their own sources. Both must say why, and
 * both leave a row in enterprise_wiki_page_review_events — append-only, so a page that goes round
 * three times keeps all three objections rather than only the last.
 *
 * `rejected` is kept as the technical status and read as "changes requested": the page returns to
 * its owner, is reopened to draft, and can be submitted again. See
 * docs/enterprise-wiki-approval-model.md §10.
 *
 * The invariant underneath all of it: a returned version never disturbs what is already published.
 */
class EnterpriseWikiChangesRequestedTest extends TestCase
{
    use DatabaseTransactions;

    private const REASON = 'Avsnittet om leveransemodell stemmer ikke med kildedokumentet.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    // A + K. the reviewer sends it back, with a reason that is kept
    public function test_the_assigned_reviewer_can_request_changes_with_a_reason(): void
    {
        $case = $this->readyForFinalReview();

        $this->actingAs($case['reviewer'])
            ->patch("/app/wiki/{$case['page']->slug}/reject", ['reason' => self::REASON])
            ->assertRedirect(route('app.wiki.show', $case['page']->slug));

        $event = EnterpriseWikiPageReviewEvent::query()
            ->where('enterprise_wiki_page_id', $case['page']->id)
            ->firstOrFail();

        $this->assertSame(self::REASON, $event->reason);
        $this->assertSame($case['reviewer']->id, (int) $event->actor_user_id);
        $this->assertSame(EnterpriseWikiPageReviewEvent::ACTOR_ROLE_REVIEWER, $event->actor_role);
        $this->assertSame($case['version']->id, (int) $event->enterprise_wiki_page_version_id);
    }

    // B. no return without a reason
    public function test_a_reason_is_required_and_must_say_something(): void
    {
        $case = $this->readyForFinalReview();

        foreach ([[], ['reason' => '   '], ['reason' => 'nei']] as $payload) {
            $this->actingAs($case['reviewer'])
                ->patch("/app/wiki/{$case['page']->slug}/reject", $payload)
                ->assertSessionHasErrors('reason');
        }

        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $case['page']->fresh()->status);
        $this->assertSame(0, EnterpriseWikiPageReviewEvent::query()->where('enterprise_wiki_page_id', $case['page']->id)->count());
    }

    // C. capability alone does not let someone send it back
    public function test_a_reviewer_who_was_not_assigned_cannot_request_changes(): void
    {
        $case = $this->readyForFinalReview();
        $other = $this->user($case['customer'], User::BID_ROLE_BID_MANAGER);

        $this->actingAs($other)
            ->patch("/app/wiki/{$case['page']->slug}/reject", ['reason' => self::REASON])
            ->assertForbidden();

        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $case['page']->fresh()->status);
    }

    // D. takeover works; self-review still does not
    public function test_a_system_owner_can_request_changes_but_not_on_their_own_submission(): void
    {
        $case = $this->readyForFinalReview();
        $systemOwner = $this->user($case['customer'], User::BID_ROLE_SYSTEM_OWNER);

        $this->actingAs($systemOwner)
            ->patch("/app/wiki/{$case['page']->slug}/reject", ['reason' => self::REASON])
            ->assertRedirect(route('app.wiki.show', $case['page']->slug));

        $this->assertSame(EnterpriseWikiPage::STATUS_REJECTED, $case['page']->fresh()->status);
    }

    // E. a document owner objects about their own sources
    public function test_a_document_owner_can_request_changes_with_a_reason(): void
    {
        $case = $this->submittedPage();
        $requirement = $this->activeRequirements($case['version'])->first();

        $this->actingAs($case['documentOwner'])
            ->patch("/app/wiki/{$case['page']->slug}/document-owner-approvals/{$requirement->id}/reject", [
                'comment' => self::REASON,
            ])
            ->assertRedirect(route('app.wiki.show', $case['page']->slug));

        $event = EnterpriseWikiPageReviewEvent::query()->where('enterprise_wiki_page_id', $case['page']->id)->firstOrFail();
        $this->assertSame(EnterpriseWikiPageReviewEvent::ACTOR_ROLE_DOCUMENT_OWNER, $event->actor_role);
        $this->assertSame(self::REASON, $event->reason);

        // A real objection sends the version back rather than parking it in review.
        $this->assertSame(EnterpriseWikiPage::STATUS_REJECTED, $case['page']->fresh()->status);
        $this->assertSame(
            EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_REJECTED,
            $requirement->fresh()->approval_status,
        );
    }

    public function test_a_document_owner_must_give_a_reason_to_refuse(): void
    {
        $case = $this->submittedPage();
        $requirement = $this->activeRequirements($case['version'])->first();

        $this->actingAs($case['documentOwner'])
            ->patch("/app/wiki/{$case['page']->slug}/document-owner-approvals/{$requirement->id}/reject")
            ->assertSessionHasErrors('comment');

        $this->assertSame(
            EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_PENDING,
            $requirement->fresh()->approval_status,
        );
    }

    public function test_approving_a_requirement_still_needs_no_reason(): void
    {
        $case = $this->submittedPage();
        $requirement = $this->activeRequirements($case['version'])->first();

        $this->actingAs($case['documentOwner'])
            ->patch("/app/wiki/{$case['page']->slug}/document-owner-approvals/{$requirement->id}/approve")
            ->assertRedirect(route('app.wiki.show', $case['page']->slug));

        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $case['page']->fresh()->status);
    }

    // F. one owner cannot answer for another
    public function test_another_document_owner_cannot_refuse_someone_elses_requirement(): void
    {
        $case = $this->submittedPage();
        $requirement = $this->activeRequirements($case['version'])->first();
        $stranger = $this->user($case['customer']);

        $this->actingAs($stranger)
            ->patch("/app/wiki/{$case['page']->slug}/document-owner-approvals/{$requirement->id}/reject", [
                'comment' => self::REASON,
            ])
            ->assertForbidden();
    }

    // G + P. nothing about a return touches what is published
    public function test_no_form_of_return_disturbs_the_published_version(): void
    {
        $case = $this->readyForFinalReview();
        $v1 = $case['version'];
        $this->actingAs($case['reviewer'])->patch("/app/wiki/{$case['page']->slug}/approve");
        $this->assertSame($v1->id, (int) $case['page']->fresh()->published_version_id);

        $v2 = $this->newWorkingVersion($case['page'], $v1, $case['documentOwner']);
        $this->submitFor($case['page'], $case['reviewer']);

        $this->actingAs($case['reviewer'])
            ->patch("/app/wiki/{$case['page']->slug}/reject", ['reason' => self::REASON]);

        $this->assertSame(
            $v1->id,
            (int) $case['page']->fresh()->published_version_id,
            'readers keep the approved version while the new one goes back',
        );
    }

    // H + I + J. the page becomes editable again and can be resubmitted
    public function test_after_a_return_the_owner_reopens_and_submits_again_with_an_explicit_reviewer(): void
    {
        $case = $this->readyForFinalReview();
        $this->actingAs($case['reviewer'])->patch("/app/wiki/{$case['page']->slug}/reject", ['reason' => self::REASON]);
        $this->assertSame(EnterpriseWikiPage::STATUS_REJECTED, $case['page']->fresh()->status);

        $owner = User::query()->findOrFail($case['page']->owner_user_id);

        // Reopen clears the finished round's assignment.
        $this->actingAs($owner)->patch("/app/wiki/{$case['page']->slug}/submit")->assertRedirect();
        $this->assertSame(EnterpriseWikiPage::STATUS_DRAFT, $case['page']->fresh()->status);
        $this->assertNull($case['version']->fresh()->reviewer_user_id);

        // A new round needs a reviewer named again — the previous one is not reused silently.
        $this->actingAs($owner)
            ->patch("/app/wiki/{$case['page']->slug}/submit")
            ->assertSessionHasErrors('reviewer_user_id');

        $this->submitFor($case['page'], $case['reviewer']);
        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $case['page']->fresh()->status);
        $this->assertSame($case['reviewer']->id, (int) $case['version']->fresh()->reviewer_user_id);
    }

    // M. the requirement set is derived again on every submission
    public function test_resubmitting_re_syncs_the_source_owner_requirements(): void
    {
        $case = $this->readyForFinalReview();
        $this->actingAs($case['reviewer'])->patch("/app/wiki/{$case['page']->slug}/reject", ['reason' => self::REASON]);

        $owner = User::query()->findOrFail($case['page']->owner_user_id);
        $this->actingAs($owner)->patch("/app/wiki/{$case['page']->slug}/submit");

        // The document changes hands while the page sits with its owner.
        $newDocumentOwner = $this->user($case['customer']);
        $case['document']->forceFill(['owner_user_id' => $newDocumentOwner->id])->save();

        $this->submitFor($case['page'], $case['reviewer']);

        $active = $this->activeRequirements($case['version']);
        $this->assertCount(1, $active);
        $this->assertSame($newDocumentOwner->id, (int) $active->first()->document_owner_user_id);
    }

    // K + N. several rounds, every objection kept
    public function test_a_page_can_go_through_several_rounds_and_keeps_every_reason(): void
    {
        $case = $this->submittedPage();
        $owner = User::query()->findOrFail($case['page']->owner_user_id);

        // Round 1: the document owner objects.
        $requirement = $this->activeRequirements($case['version'])->first();
        $this->actingAs($case['documentOwner'])
            ->patch("/app/wiki/{$case['page']->slug}/document-owner-approvals/{$requirement->id}/reject", [
                'comment' => 'Runde 1: kildehenvisningen peker på feil avsnitt.',
            ]);
        $this->assertSame(EnterpriseWikiPage::STATUS_REJECTED, $case['page']->fresh()->status);

        // The owner reworks: editing produces a new version, so the old decisions do not carry over.
        $this->actingAs($owner)->patch("/app/wiki/{$case['page']->slug}/submit");
        $v2 = $this->newWorkingVersion($case['page'], $case['version'], $case['documentOwner']);
        $this->submitFor($case['page'], $case['reviewer']);

        // Round 2: owners sign off, the reviewer still wants changes.
        $this->clearSourceOwnerGate($case['page'], $v2, $case['documentOwner']);
        $this->actingAs($case['reviewer'])
            ->patch("/app/wiki/{$case['page']->slug}/reject", ['reason' => 'Runde 2: sammendraget mangler leveransemodellen.'])
            ->assertRedirect();

        // Round 3: reworked again, and approved.
        $this->actingAs($owner)->patch("/app/wiki/{$case['page']->slug}/submit");
        $v3 = $this->newWorkingVersion($case['page'], $v2, $case['documentOwner']);
        $this->submitFor($case['page'], $case['reviewer']);
        $this->clearSourceOwnerGate($case['page'], $v3, $case['documentOwner']);

        $this->actingAs($case['reviewer'])
            ->patch("/app/wiki/{$case['page']->slug}/approve")
            ->assertRedirect(route('app.wiki.show', $case['page']->slug));

        // O. the version that was actually approved is the one published.
        $page = $case['page']->fresh();
        $this->assertSame(EnterpriseWikiPage::STATUS_APPROVED, $page->status);
        $this->assertSame($v3->id, (int) $page->published_version_id);

        // K. both objections survive, attributed and in order.
        $events = EnterpriseWikiPageReviewEvent::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $events);
        $this->assertStringContainsString('Runde 1', $events[0]->reason);
        $this->assertSame(EnterpriseWikiPageReviewEvent::ACTOR_ROLE_DOCUMENT_OWNER, $events[0]->actor_role);
        $this->assertStringContainsString('Runde 2', $events[1]->reason);
        $this->assertSame(EnterpriseWikiPageReviewEvent::ACTOR_ROLE_REVIEWER, $events[1]->actor_role);
    }

    // L. a previous round's refusal does not block a later one
    public function test_a_refusal_from_an_earlier_version_does_not_block_a_new_round(): void
    {
        $case = $this->submittedPage();
        $requirement = $this->activeRequirements($case['version'])->first();
        $this->actingAs($case['documentOwner'])
            ->patch("/app/wiki/{$case['page']->slug}/document-owner-approvals/{$requirement->id}/reject", [
                'comment' => self::REASON,
            ]);

        $owner = User::query()->findOrFail($case['page']->owner_user_id);
        $this->actingAs($owner)->patch("/app/wiki/{$case['page']->slug}/submit");
        $v2 = $this->newWorkingVersion($case['page'], $case['version'], $case['documentOwner']);
        $this->submitFor($case['page'], $case['reviewer']);
        $this->clearSourceOwnerGate($case['page'], $v2, $case['documentOwner']);

        $this->actingAs($case['reviewer'])
            ->patch("/app/wiki/{$case['page']->slug}/approve")
            ->assertRedirect(route('app.wiki.show', $case['page']->slug));

        $this->assertSame($v2->id, (int) $case['page']->fresh()->published_version_id);
    }

    public function test_the_payload_carries_the_reason_and_who_asked(): void
    {
        $case = $this->readyForFinalReview();
        $this->actingAs($case['reviewer'])->patch("/app/wiki/{$case['page']->slug}/reject", ['reason' => self::REASON]);

        $owner = User::query()->findOrFail($case['page']->owner_user_id);

        $this->actingAs($owner)
            ->get("/app/wiki/{$case['page']->slug}")
            ->assertSuccessful()
            ->assertInertia(fn ($inertia) => $inertia
                ->where('review_assignment.changes_requested.is_returned', true)
                ->where('review_assignment.changes_requested.latest.reason', self::REASON)
                ->where('review_assignment.changes_requested.latest.actor_role', EnterpriseWikiPageReviewEvent::ACTOR_ROLE_REVIEWER)
                ->where('review_assignment.changes_requested.latest.actor.name', $case['reviewer']->name));
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    private function readyForFinalReview(): array
    {
        $case = $this->submittedPage();
        $this->clearSourceOwnerGate($case['page'], $case['version'], $case['documentOwner']);

        return $case;
    }

    private function submittedPage(): array
    {
        $customer = $this->customer();
        $pageOwner = $this->user($customer);
        $documentOwner = $this->user($customer);
        $reviewer = $this->reviewer($customer);

        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'owner_user_id' => $pageOwner->id,
            'slug' => 'retur-'.Str::lower(Str::random(6)),
            'title' => 'Retur Side',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        $version = $this->version($page, 1);
        $document = $this->document($customer, $documentOwner);
        $this->attachSource($page, $version, $document);
        $this->submitFor($page, $reviewer);

        return [
            'customer' => $customer,
            'page' => $page->fresh(),
            'version' => $version,
            'reviewer' => $reviewer,
            'documentOwner' => $documentOwner,
            'document' => $document,
        ];
    }

    /** Reworking produces a NEW version — content is never mutated in place. */
    private function newWorkingVersion(EnterpriseWikiPage $page, EnterpriseWikiPageVersion $previous, User $documentOwner): EnterpriseWikiPageVersion
    {
        $previous->forceFill(['is_current' => false])->save();
        $next = $this->version($page, $previous->version_number + 1);

        $documentId = EnterpriseWikiSourceReference::query()
            ->whereIn('enterprise_wiki_claim_id', EnterpriseWikiClaim::query()
                ->where('enterprise_wiki_page_version_id', $previous->id)
                ->pluck('id'))
            ->value('source_id');

        if ($documentId !== null) {
            $this->attachSource($page, $next, EnterpriseWikiDocument::query()->findOrFail($documentId));
        }

        // Reworking happens while the page sits with its owner, so it is editable again.
        EnterpriseWikiPage::query()->whereKey($page->id)->update(['status' => EnterpriseWikiPage::STATUS_DRAFT]);

        return $next;
    }

    private function version(EnterpriseWikiPage $page, int $number): EnterpriseWikiPageVersion
    {
        return EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => $number,
            'is_current' => true,
            'content_markdown' => "# Retur Side v{$number}",
            'generated_by_model' => 'gpt-5',
        ]);
    }

    private function attachSource(EnterpriseWikiPage $page, EnterpriseWikiPageVersion $version, EnterpriseWikiDocument $document): void
    {
        $claim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'Påstand fra kilden.',
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
            'excerpt' => 'Utdrag',
        ]);
    }

    private function submitFor(EnterpriseWikiPage $page, User $reviewer): void
    {
        $owner = User::query()->findOrFail($page->owner_user_id);

        $this->actingAs($owner)
            ->patch("/app/wiki/{$page->slug}/submit", ['reviewer_user_id' => $reviewer->id])
            ->assertRedirect(route('app.wiki.show', $page->slug));
    }

    private function clearSourceOwnerGate(EnterpriseWikiPage $page, EnterpriseWikiPageVersion $version, User $documentOwner): void
    {
        foreach ($this->activeRequirements($version) as $requirement) {
            $this->actingAs($documentOwner)
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
        $settings = $customer->resolvedPermissionSettings();
        $settings[Customer::PERMISSION_APPROVE_WIKI_PAGES] = ['bid_manager'];
        $customer->forceFill(['permission_settings' => $settings])->save();

        return $this->user($customer, User::BID_ROLE_BID_MANAGER);
    }

    private function customer(): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => 'Retur Test AS',
            'slug' => 'retur-'.Str::lower(Str::random(6)),
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
            'email' => Str::lower(Str::random(8)).'@retur.test',
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
