<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiLintFinding;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiPageVersionDocumentOwnerApproval;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 2A–3A: wiki controller tests.
 *
 * Visibility rules (from plan §5.3):
 *   approved                    → all authenticated customer users
 *   draft / pending_review      → System Owner and Bid Manager only
 *   rejected                    → System Owner only (phase 3A addition)
 *
 * Status transitions (phase 3A, System Owner only):
 *   submit: draft → pending_review | rejected → draft
 *   approve: pending_review → approved
 *   reject: pending_review → rejected
 *
 * Bid Manager as approver is deferred to a later phase (plan §5.3, §11 #2).
 * Claim-level approval is out of scope for phase 3A.
 */
class WikiControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    // =========================================================================
    // index() — visibility
    // =========================================================================

    public function test_contributor_sees_approved_pages_in_index(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Iso-side');

        $response = $this->actingAs($user)->get('/app/wiki');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($page): bool {
            $pages = data_get($inertia, 'props.pages', []);

            return data_get($inertia, 'component') === 'App/Wiki/Index'
                && collect($pages)->contains(fn (array $p) => $p['id'] === $page->id);
        });
    }

    public function test_contributor_does_not_see_draft_pages_in_index(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $draft = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'Utkast-side');

        $response = $this->actingAs($user)->get('/app/wiki');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($draft): bool {
            $pages = collect(data_get($inertia, 'props.pages', []));

            return ! $pages->contains(fn (array $p) => $p['id'] === $draft->id);
        });
    }

    public function test_contributor_does_not_see_pending_review_pages_in_index(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $pending = $this->createPage($customer, EnterpriseWikiPage::STATUS_PENDING_REVIEW, 'Under-review-side');

        $response = $this->actingAs($user)->get('/app/wiki');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($pending): bool {
            $pages = collect(data_get($inertia, 'props.pages', []));

            return ! $pages->contains(fn (array $p) => $p['id'] === $pending->id);
        });
    }

    public function test_system_owner_sees_draft_and_pending_review_pages_in_index(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $draft = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'Mitt utkast');
        $pending = $this->createPage($customer, EnterpriseWikiPage::STATUS_PENDING_REVIEW, 'Til gjennomgang');
        $approved = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Godkjent side');

        $response = $this->actingAs($owner)->get('/app/wiki');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($draft, $pending, $approved): bool {
            $ids = collect(data_get($inertia, 'props.pages', []))->pluck('id');

            return $ids->contains($draft->id)
                && $ids->contains($pending->id)
                && $ids->contains($approved->id);
        });
    }

    public function test_bid_manager_sees_draft_and_pending_review_pages_in_index(): void
    {
        $customer = $this->createCustomer();
        $manager = $this->createUser($customer, User::BID_ROLE_BID_MANAGER);
        $draft = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'BM utkast');
        $pending = $this->createPage($customer, EnterpriseWikiPage::STATUS_PENDING_REVIEW, 'BM review');

        $response = $this->actingAs($manager)->get('/app/wiki');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($draft, $pending): bool {
            $ids = collect(data_get($inertia, 'props.pages', []))->pluck('id');

            return $ids->contains($draft->id) && $ids->contains($pending->id);
        });
    }

    // =========================================================================
    // index() — customer isolation
    // =========================================================================

    public function test_index_does_not_leak_other_customer_pages(): void
    {
        $customer = $this->createCustomer('Eigen kunde');
        $otherCustomer = $this->createCustomer('Annen kunde');
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $ownPage = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Vår side');
        $foreignPage = $this->createPage($otherCustomer, EnterpriseWikiPage::STATUS_APPROVED, 'Fremmed side');

        $response = $this->actingAs($user)->get('/app/wiki');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($ownPage, $foreignPage): bool {
            $ids = collect(data_get($inertia, 'props.pages', []))->pluck('id');

            return $ids->contains($ownPage->id) && ! $ids->contains($foreignPage->id);
        });
    }

    // =========================================================================
    // show() — basic returns
    // =========================================================================

    public function test_show_returns_page_with_claims_and_source_references(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'ISO 9001-kompetanse');
        $version = $this->createVersion($page, isCurrentTrue: true);
        $claim = $this->createClaim($page, $version, 'Vi er ISO 9001-sertifisert.');
        $ref = $this->createSourceReference($claim, 'kompetanse.docx', 'ISO sertifisert siden 2020.');

        $response = $this->actingAs($user)->get('/app/wiki/'.$page->slug);

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($page, $version, $claim, $ref): bool {
            $component = data_get($inertia, 'component');
            $props = data_get($inertia, 'props');
            $claims = data_get($props, 'claims', []);
            $firstClaim = $claims[0] ?? null;
            $firstRef = ($firstClaim['source_references'] ?? [])[0] ?? null;

            return $component === 'App/Wiki/Show'
                && data_get($props, 'page.id') === $page->id
                && data_get($props, 'current_version.id') === $version->id
                && count($claims) === 1
                && ($firstClaim['claim_text'] ?? null) === $claim->claim_text
                && ($firstClaim['approval_status'] ?? null) === EnterpriseWikiClaim::APPROVAL_STATUS_PENDING
                && ($firstRef['source_label'] ?? null) === $ref->source_label
                && ($firstRef['excerpt'] ?? null) === $ref->excerpt;
        });
    }

    public function test_show_exposes_customer_source_documents_for_claim_linking(): void
    {
        $customer = $this->createCustomer();
        $otherCustomer = $this->createCustomer('Fremmed kunde');
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $foreignOwner = $this->createUser($otherCustomer, User::BID_ROLE_SYSTEM_OWNER);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Kildevalg');
        $version = $this->createVersion($page, isCurrentTrue: true);
        $claim = $this->createClaim($page, $version, 'Påstand uten kilde.');
        $document = $this->createDocument($customer, EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED);
        $document->forceFill(['owner_user_id' => $owner->id])->save();
        $foreignDocument = $this->createDocument($otherCustomer, EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED);
        $foreignDocument->forceFill(['owner_user_id' => $foreignOwner->id])->save();

        $response = $this->actingAs($owner)->get('/app/wiki/'.$page->slug);

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($document, $foreignDocument, $owner): bool {
            $props = data_get($inertia, 'props');
            $sourceDocuments = collect(data_get($props, 'source_documents', []));

            $ownDoc = $sourceDocuments->firstWhere('id', $document->id);
            $foreignDoc = $sourceDocuments->firstWhere('id', $foreignDocument->id);

            return $sourceDocuments->count() === 1
                && $ownDoc !== null
                && ($ownDoc['original_filename'] ?? null) === $document->original_filename
                && ($ownDoc['owner_name'] ?? null) === $owner->name
                && ! $foreignDoc
                && isset($ownDoc['download_url']);
        });
    }

    public function test_show_exposes_document_owner_claim_actions_and_filters_source_documents_to_owned_documents(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $foreignOwner = $this->createUser($customer, User::BID_ROLE_BID_MANAGER);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_PENDING_REVIEW, 'Eier tilgang');
        $version = $this->createVersion($page, isCurrentTrue: true);

        $ownDocument = $this->createDocument($customer, EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED);
        $ownDocument->forceFill(['owner_user_id' => $owner->id])->save();
        $foreignDocument = $this->createDocument($customer, EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED);
        $foreignDocument->forceFill(['owner_user_id' => $foreignOwner->id])->save();

        $ownClaim = $this->createClaim($page, $version, 'Egen dokumentpåstand.');
        $this->createDocumentSourceReference($ownClaim, $ownDocument, 'Egen kilde.');
        $foreignClaim = $this->createClaim($page, $version, 'Fremmed dokumentpåstand.', 1);
        $this->createDocumentSourceReference($foreignClaim, $foreignDocument, 'Fremmed kilde.');

        $response = $this->actingAs($owner)->get('/app/wiki/'.$page->slug);

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($ownDocument, $foreignDocument, $ownClaim, $foreignClaim, $owner): bool {
            $props = data_get($inertia, 'props');
            $sourceDocuments = collect(data_get($props, 'source_documents', []));
            $claims = collect(data_get($props, 'claims', []))->keyBy('id');

            $ownSourceDocument = $sourceDocuments->firstWhere('id', $ownDocument->id);
            $foreignSourceDocument = $sourceDocuments->firstWhere('id', $foreignDocument->id);
            $own = $claims->get($ownClaim->id);
            $foreign = $claims->get($foreignClaim->id);

            return data_get($props, 'can_handle_wiki_claims') === true
                && $ownSourceDocument !== null
                && ($ownSourceDocument['owner_user_id'] ?? null) === $owner->id
                && $foreignSourceDocument === null
                && ($own['can_handle'] ?? null) === true
                && ($foreign['can_handle'] ?? null) === false
                && count($own['source_references'] ?? []) === 1
                && count($foreign['source_references'] ?? []) === 1;
        });
    }

    public function test_show_distinguishes_page_status_from_document_owner_approval_status(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_BID_MANAGER);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'Statusforklaring');
        $version = $this->createVersion($page, isCurrentTrue: true);
        $document = $this->createDocument($customer);
        $document->forceFill(['owner_user_id' => $owner->id])->save();
        $claim = $this->createClaim($page, $version, 'Grunnlag for statusforklaring.');
        $this->createDocumentSourceReference($claim, $document);
        $this->createDocumentOwnerApproval(
            $customer,
            $page,
            $version,
            $owner,
            [$document->id],
            EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_APPROVED,
        );

        $response = $this->actingAs($owner)->get('/app/wiki/'.$page->slug);

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($owner): bool {
            $props = data_get($inertia, 'props');
            $summary = data_get($props, 'document_owner_approval_summary', []);
            $approvals = collect(data_get($props, 'document_owner_approvals', []));
            $approval = $approvals->first();

            return data_get($props, 'page.status') === EnterpriseWikiPage::STATUS_DRAFT
                && ($summary['ready'] ?? null) === true
                && ($summary['summary_text'] ?? null) === __('procynia.wiki.document_owner_summary_approved', [
                    'approved' => 1,
                    'total' => 1,
                ])
                && ($approval['summary_text'] ?? null) === __('procynia.wiki.document_owner_sentence_approved', [
                    'owner' => $owner->name,
                    'source' => 'test-document.pdf',
                ])
                && ($approval['approval_status'] ?? null) === EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_APPROVED;
        });
    }

    public function test_show_returns_404_for_unknown_slug(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);

        $this->actingAs($user)->get('/app/wiki/finnes-ikke')->assertNotFound();
    }

    // =========================================================================
    // show() — visibility by status
    // =========================================================================

    public function test_show_returns_404_for_draft_page_to_contributor(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'Utkast kun for SO');

        $this->actingAs($user)->get('/app/wiki/'.$page->slug)->assertNotFound();
    }

    public function test_show_returns_404_for_pending_review_page_to_contributor(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_PENDING_REVIEW, 'Under review');

        $this->actingAs($user)->get('/app/wiki/'.$page->slug)->assertNotFound();
    }

    public function test_system_owner_can_view_pending_review_page(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_PENDING_REVIEW, 'Til godkjenning');

        $this->actingAs($owner)->get('/app/wiki/'.$page->slug)->assertOk();
    }

    /**
     * QA needs the narrowest possible read access to do its job: a Contributor with QA must be
     * able to open a draft/pending_review page (and its claims) to approve them, even though
     * plain Contributor visibility would 404 it (see test_show_returns_404_for_draft_page_to_
     * contributor above).
     */
    public function test_contributor_with_qa_can_view_draft_page(): void
    {
        $customer = $this->createCustomer();
        $qaContributor = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR, isQa: true);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'QA ser utkast');

        $this->actingAs($qaContributor)->get('/app/wiki/'.$page->slug)->assertOk();
    }

    public function test_contributor_with_qa_can_view_pending_review_page(): void
    {
        $customer = $this->createCustomer();
        $qaContributor = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR, isQa: true);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_PENDING_REVIEW, 'QA ser til godkjenning');

        $this->actingAs($qaContributor)->get('/app/wiki/'.$page->slug)->assertOk();
    }

    // =========================================================================
    // show() — customer isolation
    // =========================================================================

    public function test_show_enforces_customer_isolation(): void
    {
        $customer = $this->createCustomer('Eigen kunde');
        $otherCustomer = $this->createCustomer('Annen kunde');
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $foreignPage = $this->createPage($otherCustomer, EnterpriseWikiPage::STATUS_APPROVED, 'Fremmed wiki');

        $this->actingAs($user)->get('/app/wiki/'.$foreignPage->slug)->assertNotFound();
    }

    // =========================================================================
    // show() — System Owner visibility of rejected pages (phase 3A)
    // =========================================================================

    public function test_system_owner_can_view_rejected_page(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_REJECTED, 'Avvist side');

        $this->actingAs($owner)->get('/app/wiki/'.$page->slug)->assertOk();
    }

    public function test_contributor_cannot_view_rejected_page(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_REJECTED, 'Avvist for bidrag');

        $this->actingAs($user)->get('/app/wiki/'.$page->slug)->assertNotFound();
    }

    // =========================================================================
    // submit() — draft → pending_review | rejected → draft
    // =========================================================================

    public function test_system_owner_can_submit_draft_to_pending_review(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'Send til gjennomgang');

        $this->actingAs($owner)
            ->patch('/app/wiki/'.$page->slug.'/submit')
            ->assertRedirect();

        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $page->fresh()->status);
    }

    public function test_system_owner_can_reopen_rejected_page_to_draft(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_REJECTED, 'Gjenåpne avvist');

        $this->actingAs($owner)
            ->patch('/app/wiki/'.$page->slug.'/submit')
            ->assertRedirect();

        $this->assertSame(EnterpriseWikiPage::STATUS_DRAFT, $page->fresh()->status);
    }

    public function test_submit_from_approved_status_returns_422(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Allerede godkjent');

        $this->actingAs($owner)
            ->patch('/app/wiki/'.$page->slug.'/submit')
            ->assertStatus(422);

        $this->assertSame(EnterpriseWikiPage::STATUS_APPROVED, $page->fresh()->status);
    }

    public function test_contributor_cannot_submit_page(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'Submit av bidragsyter');

        $this->actingAs($user)
            ->patch('/app/wiki/'.$page->slug.'/submit')
            ->assertForbidden();

        $this->assertSame(EnterpriseWikiPage::STATUS_DRAFT, $page->fresh()->status);
    }

    public function test_bid_manager_cannot_submit_page_in_pilot(): void
    {
        $customer = $this->createCustomer();
        $manager = $this->createUser($customer, User::BID_ROLE_BID_MANAGER);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'Submit av BM');

        $this->actingAs($manager)
            ->patch('/app/wiki/'.$page->slug.'/submit')
            ->assertForbidden();
    }

    public function test_submit_enforces_customer_isolation(): void
    {
        $customer = $this->createCustomer('Eigen kunde');
        $otherCustomer = $this->createCustomer('Annen kunde');
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $foreignPage = $this->createPage($otherCustomer, EnterpriseWikiPage::STATUS_DRAFT, 'Fremmed utkast');

        $this->actingAs($owner)
            ->patch('/app/wiki/'.$foreignPage->slug.'/submit')
            ->assertNotFound();

        $this->assertSame(EnterpriseWikiPage::STATUS_DRAFT, $foreignPage->fresh()->status);
    }

    // =========================================================================
    // approve() — pending_review → approved
    // =========================================================================

    public function test_system_owner_can_approve_pending_review_page(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_PENDING_REVIEW, 'Godkjenn meg');

        $this->actingAs($owner)
            ->patch('/app/wiki/'.$page->slug.'/approve')
            ->assertRedirect();

        $this->assertSame(EnterpriseWikiPage::STATUS_APPROVED, $page->fresh()->status);
    }

    public function test_approve_sets_reviewed_fields_on_page(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_PENDING_REVIEW, 'Reviewed felt');

        $this->actingAs($owner)
            ->patch('/app/wiki/'.$page->slug.'/approve')
            ->assertRedirect();

        $fresh = $page->fresh();
        $this->assertNotNull($fresh->reviewed_at);
        $this->assertSame($owner->id, $fresh->reviewed_by_user_id);
    }

    public function test_contributor_cannot_approve_page(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_PENDING_REVIEW, 'Bidragsyter godkjenner');

        $this->actingAs($user)
            ->patch('/app/wiki/'.$page->slug.'/approve')
            ->assertForbidden();

        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $page->fresh()->status);
    }

    public function test_bid_manager_cannot_approve_page_in_pilot(): void
    {
        $customer = $this->createCustomer();
        $manager = $this->createUser($customer, User::BID_ROLE_BID_MANAGER);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_PENDING_REVIEW, 'BM godkjenner');

        $this->actingAs($manager)
            ->patch('/app/wiki/'.$page->slug.'/approve')
            ->assertForbidden();

        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $page->fresh()->status);
    }

    /**
     * Regression: QA is a separate permission from whole-page approval/rejection. A user who
     * can approve individual claims via QA must still be System-Owner-only for approving or
     * rejecting an entire Wiki page (see WikiClaimControllerTest for the claim-level permission
     * this is deliberately NOT reused for).
     */
    public function test_contributor_with_qa_cannot_approve_whole_page(): void
    {
        $customer = $this->createCustomer();
        $qaContributor = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR, isQa: true);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_PENDING_REVIEW, 'QA godkjenner ikke hele siden');

        $this->actingAs($qaContributor)
            ->patch('/app/wiki/'.$page->slug.'/approve')
            ->assertForbidden();

        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $page->fresh()->status);
    }

    public function test_bid_manager_with_qa_cannot_approve_whole_page(): void
    {
        $customer = $this->createCustomer();
        $qaBidManager = $this->createUser($customer, User::BID_ROLE_BID_MANAGER, isQa: true);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_PENDING_REVIEW, 'BM+QA godkjenner ikke hele siden');

        $this->actingAs($qaBidManager)
            ->patch('/app/wiki/'.$page->slug.'/approve')
            ->assertForbidden();

        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $page->fresh()->status);
    }

    public function test_approve_of_draft_page_returns_422(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'Godkjenn utkast');

        $this->actingAs($owner)
            ->patch('/app/wiki/'.$page->slug.'/approve')
            ->assertStatus(422);

        $this->assertSame(EnterpriseWikiPage::STATUS_DRAFT, $page->fresh()->status);
    }

    public function test_approve_enforces_customer_isolation(): void
    {
        $customer = $this->createCustomer('Eigen kunde');
        $otherCustomer = $this->createCustomer('Annen kunde');
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $foreignPage = $this->createPage($otherCustomer, EnterpriseWikiPage::STATUS_PENDING_REVIEW, 'Fremmed godkjenning');

        $this->actingAs($owner)
            ->patch('/app/wiki/'.$foreignPage->slug.'/approve')
            ->assertNotFound();

        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $foreignPage->fresh()->status);
    }

    // =========================================================================
    // reject() — pending_review → rejected
    // =========================================================================

    public function test_system_owner_can_reject_pending_review_page(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_PENDING_REVIEW, 'Avvis meg');

        $this->actingAs($owner)
            ->patch('/app/wiki/'.$page->slug.'/reject')
            ->assertRedirect();

        $this->assertSame(EnterpriseWikiPage::STATUS_REJECTED, $page->fresh()->status);
    }

    public function test_reject_sets_reviewed_fields_on_page(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_PENDING_REVIEW, 'Avvis felt');

        $this->actingAs($owner)
            ->patch('/app/wiki/'.$page->slug.'/reject')
            ->assertRedirect();

        $fresh = $page->fresh();
        $this->assertNotNull($fresh->reviewed_at);
        $this->assertSame($owner->id, $fresh->reviewed_by_user_id);
    }

    public function test_contributor_cannot_reject_page(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_PENDING_REVIEW, 'Bidragsyter avviser');

        $this->actingAs($user)
            ->patch('/app/wiki/'.$page->slug.'/reject')
            ->assertForbidden();

        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $page->fresh()->status);
    }

    public function test_bid_manager_cannot_reject_page_in_pilot(): void
    {
        $customer = $this->createCustomer();
        $manager = $this->createUser($customer, User::BID_ROLE_BID_MANAGER);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_PENDING_REVIEW, 'BM avviser');

        $this->actingAs($manager)
            ->patch('/app/wiki/'.$page->slug.'/reject')
            ->assertForbidden();

        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $page->fresh()->status);
    }

    public function test_contributor_with_qa_cannot_reject_whole_page(): void
    {
        $customer = $this->createCustomer();
        $qaContributor = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR, isQa: true);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_PENDING_REVIEW, 'QA avviser ikke hele siden');

        $this->actingAs($qaContributor)
            ->patch('/app/wiki/'.$page->slug.'/reject')
            ->assertForbidden();

        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $page->fresh()->status);
    }

    public function test_reject_of_draft_page_returns_422(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'Avvis utkast');

        $this->actingAs($owner)
            ->patch('/app/wiki/'.$page->slug.'/reject')
            ->assertStatus(422);

        $this->assertSame(EnterpriseWikiPage::STATUS_DRAFT, $page->fresh()->status);
    }

    public function test_reject_enforces_customer_isolation(): void
    {
        $customer = $this->createCustomer('Eigen kunde');
        $otherCustomer = $this->createCustomer('Annen kunde');
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $foreignPage = $this->createPage($otherCustomer, EnterpriseWikiPage::STATUS_PENDING_REVIEW, 'Fremmed avvisning');

        $this->actingAs($owner)
            ->patch('/app/wiki/'.$foreignPage->slug.'/reject')
            ->assertNotFound();

        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $foreignPage->fresh()->status);
    }

    // =========================================================================
    // No claim-level approve/reject routes (phase 3A scope boundary)
    // =========================================================================

    public function test_no_claim_approve_route_exists(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Claim-route-test');

        $this->actingAs($owner)
            ->patch('/app/wiki/'.$page->slug.'/claims/approve')
            ->assertNotFound();
    }

    // =========================================================================
    // Phase 4B-1: verification basis — page_reference and no-source warning
    // =========================================================================

    public function test_show_source_reference_includes_page_reference(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Side med avsnittref');
        $version = $this->createVersion($page, isCurrentTrue: true);
        $claim = $this->createClaim($page, $version, 'Testpåstand med kilde.');
        $this->createSourceReference($claim, 'dokument.docx', 'Relevant tekstutdrag.', 'Avsnitt 3.2');

        $response = $this->actingAs($user)->get('/app/wiki/'.$page->slug);

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia): bool {
            $claims = data_get($inertia, 'props.claims', []);
            $ref = ($claims[0]['source_references'] ?? [])[0] ?? null;

            return $ref !== null && ($ref['page_reference'] ?? null) === 'Avsnitt 3.2';
        });
    }

    public function test_show_claim_with_no_sources_returns_empty_source_references(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Side uten kilder');
        $version = $this->createVersion($page, isCurrentTrue: true);
        $this->createClaim($page, $version, 'Påstand uten kildereferanse.');

        $response = $this->actingAs($user)->get('/app/wiki/'.$page->slug);

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia): bool {
            $claims = data_get($inertia, 'props.claims', []);

            return count($claims) === 1 && ($claims[0]['source_references'] ?? null) === [];
        });
    }

    public function test_bid_manager_sees_claims_and_sources_on_pending_review_page(): void
    {
        $customer = $this->createCustomer();
        $manager = $this->createUser($customer, User::BID_ROLE_BID_MANAGER);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_PENDING_REVIEW, 'BM verifikasjon');
        $version = $this->createVersion($page, isCurrentTrue: true);
        $claim = $this->createClaim($page, $version, 'Verifikasjonspåstand.');
        $this->createSourceReference($claim, 'kildedok.pdf', 'Kildeutdrag.');

        $response = $this->actingAs($manager)->get('/app/wiki/'.$page->slug);

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia): bool {
            $claims = data_get($inertia, 'props.claims', []);

            return count($claims) === 1
                && count($claims[0]['source_references'] ?? []) === 1;
        });
    }

    // =========================================================================
    // Phase 4B-3: quality indicators — source_found / no_source / missing_excerpt
    //   The indicator state is computed on the frontend from source_references data.
    //   These tests verify the controller passes the data shape that drives each state.
    //   contributor → pending_review access is already covered by
    //   test_show_returns_404_for_pending_review_page_to_contributor (phase 2A section).
    // =========================================================================

    public function test_show_claim_with_source_and_excerpt_passes_data_for_source_found_indicator(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Kilde funnet side');
        $version = $this->createVersion($page, isCurrentTrue: true);
        $claim = $this->createClaim($page, $version, 'Påstand med kilde og utdrag.');
        $this->createSourceReference($claim, 'kilde.pdf', 'Dette er et tekstutdrag.');

        $response = $this->actingAs($user)->get('/app/wiki/'.$page->slug);

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia): bool {
            $claims = data_get($inertia, 'props.claims', []);
            $ref = ($claims[0]['source_references'] ?? [])[0] ?? null;

            return $ref !== null
                && count($claims[0]['source_references']) === 1
                && ! empty($ref['excerpt']);
        });
    }

    public function test_show_claim_with_source_but_null_excerpt_passes_data_for_missing_excerpt_indicator(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Mangler utdrag side');
        $version = $this->createVersion($page, isCurrentTrue: true);
        $claim = $this->createClaim($page, $version, 'Påstand med kilde men uten utdrag.');
        $this->createSourceReference($claim, 'kilde.pdf', null);

        $response = $this->actingAs($user)->get('/app/wiki/'.$page->slug);

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia): bool {
            $claims = data_get($inertia, 'props.claims', []);
            $ref = ($claims[0]['source_references'] ?? [])[0] ?? null;

            return $ref !== null
                && count($claims[0]['source_references']) === 1
                && ($ref['excerpt'] === null || $ref['excerpt'] === '');
        });
    }

    // =========================================================================
    // Phase 3B: approval UI — flash messages and bid manager visibility
    // =========================================================================

    public function test_approve_redirects_with_success_flash(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_PENDING_REVIEW, 'Flash godkjenn');

        $this->actingAs($owner)
            ->patch('/app/wiki/'.$page->slug.'/approve')
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    public function test_reject_redirects_with_success_flash(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_PENDING_REVIEW, 'Flash avvis');

        $this->actingAs($owner)
            ->patch('/app/wiki/'.$page->slug.'/reject')
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    public function test_submit_redirects_with_success_flash(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'Flash submit');

        $this->actingAs($owner)
            ->patch('/app/wiki/'.$page->slug.'/submit')
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    public function test_bid_manager_can_view_pending_review_page(): void
    {
        $customer = $this->createCustomer();
        $manager = $this->createUser($customer, User::BID_ROLE_BID_MANAGER);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_PENDING_REVIEW, 'BM lesetilgang');

        $this->actingAs($manager)
            ->get('/app/wiki/'.$page->slug)
            ->assertOk();
    }

    // =========================================================================
    // Phase 4A-8: ingest run status in sources prop
    // =========================================================================

    public function test_index_source_includes_latest_ingest_run_status(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $document = $this->createDocument($customer);
        $this->createIngestRun($customer, $document, EnterpriseWikiIngestRun::STATUS_COMPLETED);

        $response = $this->actingAs($user)->get('/app/wiki?tab=sources');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($document): bool {
            $sources = data_get($inertia, 'props.sources', []);
            $source = collect($sources)->firstWhere('id', $document->id);

            return $source !== null
                && data_get($source, 'latest_ingest_run.status') === EnterpriseWikiIngestRun::STATUS_COMPLETED;
        });
    }

    public function test_index_source_latest_run_is_null_when_no_ingest_started(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $document = $this->createDocument($customer);

        $response = $this->actingAs($user)->get('/app/wiki?tab=sources');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($document): bool {
            $sources = data_get($inertia, 'props.sources', []);
            $source = collect($sources)->firstWhere('id', $document->id);

            return $source !== null && $source['latest_ingest_run'] === null;
        });
    }

    public function test_index_does_not_include_other_customer_ingest_runs(): void
    {
        $customer = $this->createCustomer('Eigen kunde');
        $other = $this->createCustomer('Annen kunde');
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $ownDoc = $this->createDocument($customer);
        $foreignDoc = $this->createDocument($other);

        // Both documents happen to get the same database ID sequence — the foreign
        // run must NOT bleed into the own customer's source list.
        $this->createIngestRun($other, $foreignDoc, EnterpriseWikiIngestRun::STATUS_COMPLETED);

        $response = $this->actingAs($user)->get('/app/wiki?tab=sources');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($ownDoc): bool {
            $sources = data_get($inertia, 'props.sources', []);
            $source = collect($sources)->firstWhere('id', $ownDoc->id);

            // own doc has no run → null; foreign run must not appear
            return $source !== null && $source['latest_ingest_run'] === null;
        });
    }

    public function test_index_source_shows_queued_status_when_run_is_queued(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $document = $this->createDocument($customer);
        $this->createIngestRun($customer, $document, EnterpriseWikiIngestRun::STATUS_QUEUED);

        $response = $this->actingAs($user)->get('/app/wiki?tab=sources');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($document): bool {
            $sources = data_get($inertia, 'props.sources', []);
            $source = collect($sources)->firstWhere('id', $document->id);

            return data_get($source, 'latest_ingest_run.status') === EnterpriseWikiIngestRun::STATUS_QUEUED;
        });
    }

    public function test_sources_tab_includes_document_owner_data_and_options(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_BID_MANAGER);
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $document = $this->createDocument($customer);
        $document->forceFill(['owner_user_id' => $owner->id])->save();

        $response = $this->actingAs($user)->get('/app/wiki?tab=sources');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($document, $owner): bool {
            $sources = data_get($inertia, 'props.sources', []);
            $source = collect($sources)->firstWhere('id', $document->id);
            $options = data_get($inertia, 'props.document_owner_options', []);

            return $source !== null
                && (int) data_get($source, 'owner_user_id') === $owner->id
                && data_get($source, 'owner_name') === $owner->name
                && collect($options)->contains(fn (array $option) => (int) $option['id'] === $owner->id);
        });
    }

    // =========================================================================
    // Actions layout: can_delete determines whether a user sees the row's delete action
    // =========================================================================

    public function test_sources_tab_marks_can_delete_true_for_system_owner(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $document = $this->createDocument($customer);

        $response = $this->actingAs($user)->get('/app/wiki?tab=sources');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($document): bool {
            $sources = data_get($inertia, 'props.sources', []);
            $source = collect($sources)->firstWhere('id', $document->id);

            return $source !== null && $source['can_delete'] === true;
        });
    }

    public function test_sources_tab_marks_can_delete_true_for_the_documents_owner(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $document = $this->createDocument($customer);
        $document->forceFill(['owner_user_id' => $owner->id])->save();

        $response = $this->actingAs($owner)->get('/app/wiki?tab=sources');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($document): bool {
            $sources = data_get($inertia, 'props.sources', []);
            $source = collect($sources)->firstWhere('id', $document->id);

            return $source !== null && $source['can_delete'] === true;
        });
    }

    public function test_sources_tab_marks_can_delete_false_for_an_unrelated_contributor(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $otherContributor = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $document = $this->createDocument($customer);
        $document->forceFill(['owner_user_id' => $owner->id])->save();

        $response = $this->actingAs($otherContributor)->get('/app/wiki?tab=sources');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($document): bool {
            $sources = data_get($inertia, 'props.sources', []);
            $source = collect($sources)->firstWhere('id', $document->id);

            return $source !== null && $source['can_delete'] === false;
        });
    }

    public function test_sources_tab_marks_can_delete_false_for_viewer(): void
    {
        $customer = $this->createCustomer();
        $viewer = $this->createUser($customer, User::BID_ROLE_VIEWER);
        $document = $this->createDocument($customer);

        $response = $this->actingAs($viewer)->get('/app/wiki?tab=sources');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($document): bool {
            $sources = data_get($inertia, 'props.sources', []);
            $source = collect($sources)->firstWhere('id', $document->id);

            return $source !== null && $source['can_delete'] === false;
        });
    }

    // =========================================================================
    // Actions layout: row action labels are short/button-like; the long delete text only
    // appears in the confirmation dialog, never in the narrow table cell.
    // =========================================================================

    public function test_row_delete_action_label_is_short_not_the_full_confirmation_text(): void
    {
        $locale = app()->getLocale();
        app()->setLocale('no');

        $this->assertSame('Slett', trans('procynia.wiki.source_delete_button'));
        $this->assertSame('Slett dokument og generert Wiki-innhold', trans('procynia.wiki.delete_confirm_button'));

        app()->setLocale('en');
        $this->assertSame('Delete', trans('procynia.wiki.source_delete_button'));
        $this->assertSame('Delete document and generated Wiki content', trans('procynia.wiki.delete_confirm_button'));
        app()->setLocale($locale);
    }

    public function test_ingest_action_label_reads_as_a_button_not_a_status(): void
    {
        $locale = app()->getLocale();
        app()->setLocale('no');

        $label = trans('procynia.wiki.source_ingest_button');
        $this->assertSame('Lag Wiki-utkast', $label);
        // Not a status word — this is the specific bug this layout fix corrects (Del: the button
        // must never read like one of the Wiki-status chip labels, e.g. "I kø"/"Kjører"/"Fullført").
        $this->assertNotContains($label, ['I kø', 'Kjører', 'Fullført', 'Feilet', 'Venter']);

        app()->setLocale('en');
        $this->assertSame('Create Wiki draft', trans('procynia.wiki.source_ingest_button'));
        app()->setLocale($locale);
    }

    public function test_download_and_actions_column_translations_exist(): void
    {
        $locale = app()->getLocale();
        app()->setLocale('no');

        $this->assertSame('Last ned', trans('procynia.wiki.source_download_button'));
        $this->assertSame('Handlinger', trans('procynia.wiki.source_col_actions'));

        app()->setLocale('en');
        $this->assertSame('Download', trans('procynia.wiki.source_download_button'));
        $this->assertSame('Actions', trans('procynia.wiki.source_col_actions'));
        app()->setLocale($locale);
    }

    // =========================================================================
    // Phase 4A-9: generated pages per source
    // =========================================================================

    public function test_index_source_includes_generated_pages_from_completed_run(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $document = $this->createDocument($customer);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'Tjenestebeskrivelse');
        $run = $this->createIngestRun($customer, $document, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        $run->update(['enterprise_wiki_page_id' => $page->id]);

        $response = $this->actingAs($user)->get('/app/wiki?tab=sources');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($document, $page): bool {
            $sources = data_get($inertia, 'props.sources', []);
            $source = collect($sources)->firstWhere('id', $document->id);
            $generatedPages = data_get($source, 'generated_pages', []);

            return collect($generatedPages)->contains(fn (array $p) => $p['id'] === $page->id);
        });
    }

    public function test_index_source_generated_pages_is_empty_when_no_run_exists(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $document = $this->createDocument($customer);

        $response = $this->actingAs($user)->get('/app/wiki?tab=sources');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($document): bool {
            $sources = data_get($inertia, 'props.sources', []);
            $source = collect($sources)->firstWhere('id', $document->id);

            return $source !== null && data_get($source, 'generated_pages', null) === [];
        });
    }

    public function test_index_source_does_not_include_other_customer_generated_pages(): void
    {
        $customer = $this->createCustomer('Eigen kunde');
        $other = $this->createCustomer('Annen kunde');
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $ownDoc = $this->createDocument($customer);
        $foreignDoc = $this->createDocument($other);
        $foreignPage = $this->createPage($other, EnterpriseWikiPage::STATUS_DRAFT, 'Annen side');
        $foreignRun = $this->createIngestRun($other, $foreignDoc, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        $foreignRun->update(['enterprise_wiki_page_id' => $foreignPage->id]);

        $response = $this->actingAs($user)->get('/app/wiki?tab=sources');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($ownDoc, $foreignPage): bool {
            $sources = data_get($inertia, 'props.sources', []);
            $source = collect($sources)->firstWhere('id', $ownDoc->id);
            $pageIds = collect(data_get($source, 'generated_pages', []))->pluck('id');

            return ! $pageIds->contains($foreignPage->id);
        });
    }

    // =========================================================================
    // Phase 4A-6: sources prop isolation
    // =========================================================================

    public function test_index_returns_wiki_sources_for_current_customer(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $document = $this->createDocument($customer);

        $response = $this->actingAs($user)->get('/app/wiki?tab=sources');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($document): bool {
            $sources = data_get($inertia, 'props.sources', []);

            return collect($sources)->contains(fn (array $s) => $s['id'] === $document->id);
        });
    }

    public function test_index_does_not_return_other_customer_wiki_sources(): void
    {
        $customer = $this->createCustomer('Eigen kunde');
        $other = $this->createCustomer('Annen kunde');
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $ownDoc = $this->createDocument($customer);
        $foreignDoc = $this->createDocument($other);

        $response = $this->actingAs($user)->get('/app/wiki?tab=sources');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($ownDoc, $foreignDoc): bool {
            $ids = collect(data_get($inertia, 'props.sources', []))->pluck('id');

            return $ids->contains($ownDoc->id) && ! $ids->contains($foreignDoc->id);
        });
    }

    public function test_index_sends_wiki_generation_available_as_false(): void
    {
        config(['services.enterprise_wiki.ai_enabled' => false]);

        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $response = $this->actingAs($user)->get('/app/wiki');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia): bool {
            return array_key_exists('wiki_generation_available', $inertia['props'])
                && $inertia['props']['wiki_generation_available'] === false;
        });
    }

    // =========================================================================
    // Phase 4B-5B: lint_health on index, lint_findings on show
    // =========================================================================

    public function test_index_sends_lint_health_with_zero_counts_when_no_findings(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $response = $this->actingAs($user)->get('/app/wiki');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia): bool {
            $health = data_get($inertia, 'props.lint_health');

            return $health !== null
                && $health['error'] === 0
                && $health['warning'] === 0
                && $health['info'] === 0
                && $health['total'] === 0;
        });
    }

    public function test_index_lint_health_counts_open_findings_by_severity(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'Testside');

        $this->createLintFinding($customer, $page, EnterpriseWikiLintFinding::SEVERITY_ERROR);
        $this->createLintFinding($customer, $page, EnterpriseWikiLintFinding::SEVERITY_WARNING);
        $this->createLintFinding($customer, $page, EnterpriseWikiLintFinding::SEVERITY_WARNING);

        $response = $this->actingAs($user)->get('/app/wiki');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia): bool {
            $health = data_get($inertia, 'props.lint_health');

            return $health !== null
                && $health['error'] === 1
                && $health['warning'] === 2
                && $health['total'] === 3;
        });
    }

    public function test_index_lint_health_does_not_count_resolved_findings(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'Testside');

        $this->createLintFinding($customer, $page, EnterpriseWikiLintFinding::SEVERITY_WARNING, EnterpriseWikiLintFinding::STATUS_RESOLVED);

        $response = $this->actingAs($user)->get('/app/wiki');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia): bool {
            $health = data_get($inertia, 'props.lint_health');

            return $health !== null && $health['total'] === 0;
        });
    }

    public function test_index_lint_health_is_scoped_to_current_customer(): void
    {
        $customer = $this->createCustomer('Eigen kunde');
        $other = $this->createCustomer('Annen kunde');
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $ownPage = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'Eigen side');
        $foreignPage = $this->createPage($other, EnterpriseWikiPage::STATUS_DRAFT, 'Annen side');

        $this->createLintFinding($other, $foreignPage, EnterpriseWikiLintFinding::SEVERITY_ERROR);

        $response = $this->actingAs($user)->get('/app/wiki');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia): bool {
            $health = data_get($inertia, 'props.lint_health');

            return $health !== null && $health['total'] === 0;
        });
    }

    public function test_show_sends_open_lint_findings_for_page(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'Testside');
        $finding = $this->createLintFinding($customer, $page, EnterpriseWikiLintFinding::SEVERITY_WARNING);

        $response = $this->actingAs($user)->get('/app/wiki/'.$page->slug);

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($finding): bool {
            $findings = data_get($inertia, 'props.lint_findings', []);

            return collect($findings)->contains(fn (array $f) => $f['id'] === $finding->id);
        });
    }

    public function test_show_does_not_include_resolved_lint_findings(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'Testside');
        $this->createLintFinding($customer, $page, EnterpriseWikiLintFinding::SEVERITY_WARNING, EnterpriseWikiLintFinding::STATUS_RESOLVED);

        $response = $this->actingAs($user)->get('/app/wiki/'.$page->slug);

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia): bool {
            return data_get($inertia, 'props.lint_findings') === [];
        });
    }

    public function test_show_lint_findings_are_scoped_to_page(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'Testside');
        $otherPage = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'Annen testside');
        $own = $this->createLintFinding($customer, $page, EnterpriseWikiLintFinding::SEVERITY_WARNING);
        $foreign = $this->createLintFinding($customer, $otherPage, EnterpriseWikiLintFinding::SEVERITY_ERROR);

        $response = $this->actingAs($user)->get('/app/wiki/'.$page->slug);

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($own, $foreign): bool {
            $ids = collect(data_get($inertia, 'props.lint_findings', []))->pluck('id');

            return $ids->contains($own->id) && ! $ids->contains($foreign->id);
        });
    }

    public function test_show_lint_findings_exclude_other_customer(): void
    {
        $customer = $this->createCustomer('Eigen kunde');
        $other = $this->createCustomer('Annen kunde');
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'Eigen side');
        $foreignPage = $this->createPage($other, EnterpriseWikiPage::STATUS_DRAFT, 'Annen side');
        $foreignFinding = $this->createLintFinding($other, $foreignPage, EnterpriseWikiLintFinding::SEVERITY_ERROR);

        $response = $this->actingAs($user)->get('/app/wiki/'.$page->slug);

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($foreignFinding): bool {
            $ids = collect(data_get($inertia, 'props.lint_findings', []))->pluck('id');

            return ! $ids->contains($foreignFinding->id);
        });
    }

    // =========================================================================
    // Phase 1I: claim_summary prop
    // =========================================================================

    public function test_show_sends_claim_summary_with_correct_total(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Summary side');
        $version = $this->createVersion($page, isCurrentTrue: true);
        $this->createClaim($page, $version, 'Påstand 1.');
        $this->createClaim($page, $version, 'Påstand 2.');
        $this->createClaim($page, $version, 'Påstand 3.');

        $response = $this->actingAs($user)->get('/app/wiki/'.$page->slug);

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia): bool {
            $summary = data_get($inertia, 'props.claim_summary');

            return $summary !== null && $summary['total'] === 3;
        });
    }

    public function test_show_claim_summary_quality_breakdown_counts_correctly(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Quality summary side');
        $version = $this->createVersion($page, isCurrentTrue: true);

        $claim1 = $this->createClaim($page, $version, 'Kilde funnet påstand.');
        $this->createSourceReference($claim1, 'kilde.pdf', 'Tekstutdrag her.');

        $claim2 = $this->createClaim($page, $version, 'Mangler utdrag påstand.');
        $this->createSourceReference($claim2, 'kilde.pdf', null);

        $this->createClaim($page, $version, 'Mangler kilde påstand.');
        $this->createClaim($page, $version, 'Avvist påstand.', 3, [
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_REJECTED,
            'approved_by_user_id' => $user->id,
            'approved_at' => now(),
            'approval_comment' => 'Avvist manuelt.',
        ]);

        $response = $this->actingAs($user)->get('/app/wiki/'.$page->slug);

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia): bool {
            $summary = data_get($inertia, 'props.claim_summary');

            return $summary !== null
                && $summary['total'] === 4
                && $summary['source_found'] === 1
                && $summary['missing_excerpt'] === 1
                && $summary['rejected'] === 1
                && $summary['missing_source'] === 1;
        });
    }

    public function test_show_claim_summary_total_is_zero_with_no_claims(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'Tom side');

        $response = $this->actingAs($user)->get('/app/wiki/'.$page->slug);

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia): bool {
            $summary = data_get($inertia, 'props.claim_summary');

            return $summary !== null && $summary['total'] === 0;
        });
    }

    public function test_show_claim_summary_total_reflects_high_volume_for_warning_display(): void
    {
        // Frontend shows a high-volume warning when total > 100.
        // This test verifies the controller correctly counts a volume that crosses the threshold.
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_PENDING_REVIEW, 'Stor side');
        $version = $this->createVersion($page, isCurrentTrue: true);

        $rows = [];
        for ($i = 0; $i < 101; $i++) {
            $rows[] = [
                'enterprise_wiki_page_id' => $page->id,
                'enterprise_wiki_page_version_id' => $version->id,
                'claim_text' => "Påstand {$i}.",
                'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
                'conflict_flag' => false,
                'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
                'position_order' => $i,
                'created_at' => now(),
            ];
        }
        EnterpriseWikiClaim::query()->insert($rows);

        $response = $this->actingAs($user)->get('/app/wiki/'.$page->slug);

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia): bool {
            $summary = data_get($inertia, 'props.claim_summary');

            return $summary !== null && $summary['total'] === 101;
        });
    }

    // =========================================================================
    // Article layer UI: content_markdown in current_version prop
    // =========================================================================

    public function test_show_sends_content_markdown_in_current_version_prop(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Artikkelside');
        $version = $this->createVersion($page, isCurrentTrue: true);

        $response = $this->actingAs($user)->get('/app/wiki/'.$page->slug);

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($version): bool {
            $currentVersion = data_get($inertia, 'props.current_version');

            return $currentVersion !== null
                && array_key_exists('content_markdown', $currentVersion)
                && $currentVersion['content_markdown'] === $version->content_markdown;
        });
    }

    // =========================================================================
    // Inline wikilink rendering fix: rendered_markdown must contain clickable
    // internal links derived from canonical [[slug|anchor]] wikilinks
    // =========================================================================

    public function test_current_content_markdown_with_wikilink_is_not_mutated_by_show(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $target = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Advania', EnterpriseWikiPage::PAGE_TYPE_ENTITY);
        $article = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Artikkel');
        $original = "# Artikkel\n\nSamarbeidet med [[{$target->slug}|Advania]] er sentralt.";
        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $article->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => $original,
        ]);

        $this->actingAs($user)->get('/app/wiki/'.$article->slug);

        $this->assertDatabaseHas('enterprise_wiki_page_versions', [
            'enterprise_wiki_page_id' => $article->id,
            'content_markdown' => $original,
        ]);
    }

    public function test_rendered_markdown_contains_internal_link_for_valid_wikilink(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $target = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Advania', EnterpriseWikiPage::PAGE_TYPE_ENTITY);
        $article = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Artikkel');
        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $article->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => "# Artikkel\n\nSamarbeidet med [[{$target->slug}|Advania]] er sentralt.",
        ]);

        $response = $this->actingAs($user)->get('/app/wiki/'.$article->slug);

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($target): bool {
            $rendered = data_get($inertia, 'props.current_version.rendered_markdown');

            return $rendered !== null
                && str_contains($rendered, "(/app/wiki/{$target->slug})")
                && str_contains($rendered, 'Advania');
        });
    }

    public function test_rendered_markdown_anchor_text_is_visible_in_the_transformed_link(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $target = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Styringsgruppe', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $article = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Artikkel');
        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $article->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => "# Artikkel\n\nEskalerer til [[{$target->slug}|styringsgruppen]] ved avvik.",
        ]);

        $response = $this->actingAs($user)->get('/app/wiki/'.$article->slug);

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia): bool {
            $rendered = data_get($inertia, 'props.current_version.rendered_markdown');

            return str_contains($rendered, '[styringsgruppen]');
        });
    }

    public function test_bare_slug_wikilink_renders_with_deterministic_display_text(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $target = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Prince2', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $article = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Artikkel');
        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $article->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => "# Artikkel\n\nSe [[{$target->slug}]] for mer.",
        ]);

        $response = $this->actingAs($user)->get('/app/wiki/'.$article->slug);

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($target): bool {
            $rendered = data_get($inertia, 'props.current_version.rendered_markdown');

            return str_contains($rendered, "[{$target->slug}](/app/wiki/{$target->slug})");
        });
    }

    public function test_rendered_markdown_is_computed_the_same_way_for_every_page_type(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $target = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Advania', EnterpriseWikiPage::PAGE_TYPE_ENTITY);

        foreach ([
            EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            EnterpriseWikiPage::PAGE_TYPE_SUMMARY,
            EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
            EnterpriseWikiPage::PAGE_TYPE_ENTITY,
        ] as $pageType) {
            $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, "Side {$pageType}", $pageType);
            EnterpriseWikiPageVersion::query()->create([
                'enterprise_wiki_page_id' => $page->id,
                'version_number' => 1,
                'is_current' => true,
                'content_markdown' => "# Side\n\nSe [[{$target->slug}|Advania]] her.",
            ]);

            $response = $this->actingAs($user)->get('/app/wiki/'.$page->slug);

            $response->assertOk();
            $response->assertViewHas('page', function (array $inertia) use ($target): bool {
                $rendered = data_get($inertia, 'props.current_version.rendered_markdown');

                return $rendered !== null && str_contains($rendered, "(/app/wiki/{$target->slug})");
            });
        }
    }

    public function test_incremental_relink_generated_version_renders_inline_links(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $target = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Konsept', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $article = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Artikkel');
        // generated_by_model tag mirrors EnterpriseWikiIncrementalRelinkService::writeNewCurrentVersion() —
        // the renderer only ever reads content_markdown, so provenance never affects rendering.
        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $article->id,
            'version_number' => 2,
            'is_current' => true,
            'content_markdown' => "# Artikkel\n\nDette nevner [[{$target->slug}|Konsept]] nå.",
            'generated_by_model' => 'gpt-5/incremental-relink',
        ]);

        $response = $this->actingAs($user)->get('/app/wiki/'.$article->slug);

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($target): bool {
            $rendered = data_get($inertia, 'props.current_version.rendered_markdown');

            return str_contains($rendered, "(/app/wiki/{$target->slug})");
        });
    }

    public function test_semantic_repair_generated_version_renders_inline_links(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $target = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Entitet', EnterpriseWikiPage::PAGE_TYPE_ENTITY);
        $article = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Artikkel');
        // generated_by_model tag mirrors EnterpriseWikiLinkSemanticRepairService::writeNewCurrentVersion().
        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $article->id,
            'version_number' => 2,
            'is_current' => true,
            'content_markdown' => "# Artikkel\n\nRepareringen la til [[{$target->slug}|Entitet]] her.",
            'generated_by_model' => 'gpt-5/link-semantic-repair',
        ]);

        $response = $this->actingAs($user)->get('/app/wiki/'.$article->slug);

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($target): bool {
            $rendered = data_get($inertia, 'props.current_version.rendered_markdown');

            return str_contains($rendered, "(/app/wiki/{$target->slug})");
        });
    }

    // =========================================================================
    // Phase 8E-18: traversal data in show()
    // =========================================================================

    public function test_show_includes_page_type_in_page_prop(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE);

        $response = $this->actingAs($user)->get('/app/wiki/'.$page->slug);

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia): bool {
            return data_get($inertia, 'props.page.page_type') === EnterpriseWikiPage::PAGE_TYPE_ARTICLE;
        });
    }

    public function test_show_includes_empty_outgoing_links_when_no_links(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Isolert side');

        $response = $this->actingAs($user)->get('/app/wiki/'.$page->slug);

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia): bool {
            return data_get($inertia, 'props.outgoing_links') === [];
        });
    }

    public function test_show_includes_empty_incoming_links_when_no_backlinks(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Isolert side');

        $response = $this->actingAs($user)->get('/app/wiki/'.$page->slug);

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia): bool {
            return data_get($inertia, 'props.incoming_links') === [];
        });
    }

    public function test_show_outgoing_links_contains_linked_page_data(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $article = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Kildeartikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
        $summary = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Sammendrag', EnterpriseWikiPage::PAGE_TYPE_SUMMARY);
        $this->createPageLink($customer, $article, $summary, EnterpriseWikiPageLink::LINK_TYPE_WIKILINK);

        $response = $this->actingAs($user)->get('/app/wiki/'.$article->slug);

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($summary): bool {
            $links = data_get($inertia, 'props.outgoing_links', []);
            $found = collect($links)->firstWhere('id', $summary->id);

            return $found !== null
                && $found['title'] === $summary->title
                && $found['slug'] === $summary->slug
                && $found['page_type'] === EnterpriseWikiPage::PAGE_TYPE_SUMMARY;
        });
    }

    public function test_show_incoming_links_contains_backlink_page_data(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $article = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Kildeartikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
        $summary = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Sammendrag', EnterpriseWikiPage::PAGE_TYPE_SUMMARY);
        $this->createPageLink($customer, $summary, $article, EnterpriseWikiPageLink::LINK_TYPE_WIKILINK);

        $response = $this->actingAs($user)->get('/app/wiki/'.$article->slug);

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($summary): bool {
            $links = data_get($inertia, 'props.incoming_links', []);

            return collect($links)->contains(fn (array $p) => $p['id'] === $summary->id);
        });
    }

    public function test_show_related_concepts_for_article_page(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $article = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Kildeartikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
        $concept = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Konsept', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $this->createPageLink($customer, $article, $concept, EnterpriseWikiPageLink::LINK_TYPE_WIKILINK);

        $response = $this->actingAs($user)->get('/app/wiki/'.$article->slug);

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($concept): bool {
            $concepts = data_get($inertia, 'props.related_concepts', []);

            return collect($concepts)->contains(fn (array $p) => $p['id'] === $concept->id);
        });
    }

    public function test_show_related_entities_for_article_page(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $article = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Kildeartikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
        $entity = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Entitet', EnterpriseWikiPage::PAGE_TYPE_ENTITY);
        $this->createPageLink($customer, $article, $entity, EnterpriseWikiPageLink::LINK_TYPE_WIKILINK);

        $response = $this->actingAs($user)->get('/app/wiki/'.$article->slug);

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($entity): bool {
            $entities = data_get($inertia, 'props.related_entities', []);

            return collect($entities)->contains(fn (array $p) => $p['id'] === $entity->id);
        });
    }

    public function test_show_related_articles_for_concept_page(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $concept = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'Konsept', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $article = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'Kildeartikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
        $this->createPageLink($customer, $concept, $article, EnterpriseWikiPageLink::LINK_TYPE_WIKILINK);

        $response = $this->actingAs($user)->get('/app/wiki/'.$concept->slug);

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($article): bool {
            $articles = data_get($inertia, 'props.related_articles', []);

            return collect($articles)->contains(fn (array $p) => $p['id'] === $article->id);
        });
    }

    public function test_show_includes_lint_summary_with_zero_counts_when_no_findings(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Ren side');

        $response = $this->actingAs($user)->get('/app/wiki/'.$page->slug);

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia): bool {
            $summary = data_get($inertia, 'props.lint_summary');

            return $summary !== null
                && $summary['error'] === 0
                && $summary['warning'] === 0
                && $summary['info'] === 0
                && $summary['total'] === 0;
        });
    }

    public function test_show_lint_summary_counts_open_findings_by_severity(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'Side med funn');
        $this->createLintFinding($customer, $page, EnterpriseWikiLintFinding::SEVERITY_ERROR);
        $this->createLintFinding($customer, $page, EnterpriseWikiLintFinding::SEVERITY_WARNING);
        $this->createLintFinding($customer, $page, EnterpriseWikiLintFinding::SEVERITY_WARNING);

        $response = $this->actingAs($user)->get('/app/wiki/'.$page->slug);

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia): bool {
            $summary = data_get($inertia, 'props.lint_summary');

            return $summary !== null
                && $summary['error'] === 1
                && $summary['warning'] === 2
                && $summary['total'] === 3;
        });
    }

    public function test_show_traversal_data_is_customer_scoped(): void
    {
        $customer1 = $this->createCustomer('Eigen kunde');
        $customer2 = $this->createCustomer('Annen kunde');
        $user = $this->createUser($customer1, User::BID_ROLE_CONTRIBUTOR);

        $page1 = $this->createPage($customer1, EnterpriseWikiPage::STATUS_APPROVED, 'Side 1', EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
        $page2 = $this->createPage($customer2, EnterpriseWikiPage::STATUS_APPROVED, 'Fremmed side', EnterpriseWikiPage::PAGE_TYPE_SUMMARY);

        // Create a link that belongs to customer2 — must not appear in customer1's page props
        $this->createPageLink($customer2, $page2, $page1, EnterpriseWikiPageLink::LINK_TYPE_WIKILINK);

        $response = $this->actingAs($user)->get('/app/wiki/'.$page1->slug);

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($page2): bool {
            $incoming = data_get($inertia, 'props.incoming_links', []);

            return ! collect($incoming)->contains(fn (array $p) => $p['id'] === $page2->id);
        });
    }

    public function test_show_page_without_version_still_returns_empty_traversal_arrays(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'Tom side');

        $response = $this->actingAs($user)->get('/app/wiki/'.$page->slug);

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia): bool {
            return data_get($inertia, 'props.outgoing_links') === []
                && data_get($inertia, 'props.incoming_links') === []
                && data_get($inertia, 'props.related_articles') === []
                && data_get($inertia, 'props.related_concepts') === []
                && data_get($inertia, 'props.related_entities') === [];
        });
    }

    public function test_show_does_not_create_links_claims_or_lint_rows_on_get(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $article = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'Kildeartikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
        $summary = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'Sammendrag', EnterpriseWikiPage::PAGE_TYPE_SUMMARY);
        $this->createPageLink($customer, $article, $summary, EnterpriseWikiPageLink::LINK_TYPE_WIKILINK);

        $linksBefore = EnterpriseWikiPageLink::query()->count();
        $claimsBefore = EnterpriseWikiClaim::query()->count();
        $findingsBefore = EnterpriseWikiLintFinding::query()->count();

        $this->actingAs($user)->get('/app/wiki/'.$article->slug)->assertOk();

        $this->assertSame($linksBefore, EnterpriseWikiPageLink::query()->count());
        $this->assertSame($claimsBefore, EnterpriseWikiClaim::query()->count());
        $this->assertSame($findingsBefore, EnterpriseWikiLintFinding::query()->count());
    }

    // =========================================================================
    // Phase 8E-19: document owner summary in index()
    // =========================================================================

    public function test_index_pages_tab_shows_single_owner_pending_and_approved_summaries(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $owner = $this->createUser($customer, User::BID_ROLE_BID_MANAGER);

        $pendingPage = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Dokumenteier venter');
        $pendingVersion = $this->createVersion($pendingPage, isCurrentTrue: true);
        $pendingDoc = $this->createDocument($customer);
        $pendingDoc->forceFill(['owner_user_id' => $owner->id])->save();
        $pendingClaim = $this->createClaim($pendingPage, $pendingVersion, 'Kilde for ventende side');
        $this->createDocumentSourceReference($pendingClaim, $pendingDoc);
        $this->createDocumentOwnerApproval($customer, $pendingPage, $pendingVersion, $owner, [$pendingDoc->id], EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_PENDING);

        $approvedPage = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Dokumenteier godkjent');
        $approvedVersion = $this->createVersion($approvedPage, isCurrentTrue: true);
        $approvedDoc = $this->createDocument($customer);
        $approvedDoc->forceFill(['owner_user_id' => $owner->id])->save();
        $approvedClaim = $this->createClaim($approvedPage, $approvedVersion, 'Kilde for godkjent side');
        $this->createDocumentSourceReference($approvedClaim, $approvedDoc);
        $this->createDocumentOwnerApproval($customer, $approvedPage, $approvedVersion, $owner, [$approvedDoc->id], EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_APPROVED);

        $response = $this->actingAs($user)->get('/app/wiki');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($pendingPage, $approvedPage, $owner): bool {
            $pages = collect(data_get($inertia, 'props.pages', []));
            $pending = $pages->firstWhere('id', $pendingPage->id);
            $approved = $pages->firstWhere('id', $approvedPage->id);

            return $pending !== null
                && $approved !== null
                && data_get($pending, 'document_owner_summary.state') === 'pending'
                && data_get($pending, 'document_owner_summary.owner_count') === 1
                && data_get($pending, 'document_owner_summary.label') === $owner->name.' · '.__('procynia.wiki.document_owner_pending_label')
                && data_get($approved, 'document_owner_summary.state') === 'approved'
                && data_get($approved, 'document_owner_summary.owner_count') === 1
                && data_get($approved, 'document_owner_summary.label') === $owner->name.' · '.__('procynia.wiki.document_owner_approved_label');
        });
    }

    public function test_index_pages_tab_collapses_multiple_documents_from_same_owner(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $owner = $this->createUser($customer, User::BID_ROLE_BID_MANAGER);

        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'To dokumenter samme eier');
        $version = $this->createVersion($page, isCurrentTrue: true);
        $docA = $this->createDocument($customer);
        $docB = $this->createDocument($customer);
        $docA->forceFill(['owner_user_id' => $owner->id])->save();
        $docB->forceFill(['owner_user_id' => $owner->id])->save();
        $claimA = $this->createClaim($page, $version, 'Kilde A');
        $this->createDocumentSourceReference($claimA, $docA);
        $claimB = $this->createClaim($page, $version, 'Kilde B');
        $this->createDocumentSourceReference($claimB, $docB);
        $this->createDocumentOwnerApproval($customer, $page, $version, $owner, [$docA->id, $docB->id], EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_APPROVED);

        $response = $this->actingAs($user)->get('/app/wiki');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($page, $owner): bool {
            $pages = collect(data_get($inertia, 'props.pages', []));
            $row = $pages->firstWhere('id', $page->id);

            return $row !== null
                && data_get($row, 'document_owner_summary.owner_count') === 1
                && data_get($row, 'document_owner_summary.approved_count') === 1
                && data_get($row, 'document_owner_summary.label') === $owner->name.' · '.__('procynia.wiki.document_owner_approved_label');
        });
    }

    public function test_index_pages_tab_prioritizes_rejected_over_pending(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $pendingOwner = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $rejectedOwner = $this->createUser($customer, User::BID_ROLE_BID_MANAGER);

        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Avvist foran venting');
        $version = $this->createVersion($page, isCurrentTrue: true);
        $pendingDoc = $this->createDocument($customer);
        $rejectedDoc = $this->createDocument($customer);
        $pendingDoc->forceFill(['owner_user_id' => $pendingOwner->id])->save();
        $rejectedDoc->forceFill(['owner_user_id' => $rejectedOwner->id])->save();
        $pendingClaim = $this->createClaim($page, $version, 'Ventende kilde');
        $this->createDocumentSourceReference($pendingClaim, $pendingDoc);
        $rejectedClaim = $this->createClaim($page, $version, 'Avvist kilde', 1);
        $this->createDocumentSourceReference($rejectedClaim, $rejectedDoc);
        $this->createDocumentOwnerApproval($customer, $page, $version, $pendingOwner, [$pendingDoc->id], EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_PENDING);
        $this->createDocumentOwnerApproval($customer, $page, $version, $rejectedOwner, [$rejectedDoc->id], EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_REJECTED);

        $response = $this->actingAs($user)->get('/app/wiki');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia): bool {
            $pages = collect(data_get($inertia, 'props.pages', []));
            $row = $pages->firstWhere('title', 'Avvist foran venting');

            return $row !== null
                && data_get($row, 'document_owner_summary.state') === 'rejected'
                && data_get($row, 'document_owner_summary.label') === '2 eiere · Avvist av 1';
        });
    }

    public function test_index_pages_tab_marks_missing_owner_and_override_explicitly(): void
    {
        $customer = $this->createCustomer();
        $systemOwner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Manglende eier');
        $version = $this->createVersion($page, isCurrentTrue: true);
        $doc = $this->createDocument($customer);
        $claim = $this->createClaim($page, $version, 'Manglende eier-kilde');
        $this->createDocumentSourceReference($claim, $doc);
        $approval = $this->createDocumentOwnerApproval($customer, $page, $version, null, [$doc->id], EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_APPROVED, true);
        $approval->forceFill([
            'decided_by_user_id' => $systemOwner->id,
            'decided_at' => now(),
        ])->save();

        $response = $this->actingAs($systemOwner)->get('/app/wiki');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia): bool {
            $pages = collect(data_get($inertia, 'props.pages', []));
            $row = $pages->firstWhere('title', 'Manglende eier');

            return $row !== null
                && data_get($row, 'document_owner_summary.state') === 'missing_owner'
                && str_contains((string) data_get($row, 'document_owner_summary.label'), 'Mangler Dokumenteier')
                && str_contains((string) data_get($row, 'document_owner_summary.label'), 'System Owner-override');
        });
    }

    public function test_index_pages_tab_shows_sync_pending_when_approvals_are_not_materialized(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Avventer synk');
        $version = $this->createVersion($page, isCurrentTrue: true);
        $doc = $this->createDocument($customer);
        $doc->forceFill(['owner_user_id' => $user->id])->save();
        $claim = $this->createClaim($page, $version, 'Kilde for ventende side');
        $this->createDocumentSourceReference($claim, $doc);

        $response = $this->actingAs($user)->get('/app/wiki');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia): bool {
            $pages = collect(data_get($inertia, 'props.pages', []));
            $row = $pages->firstWhere('title', 'Avventer synk');

            return $row !== null
                && data_get($row, 'document_owner_summary.state') === 'awaiting_sync'
                && data_get($row, 'document_owner_summary.label') === __('procynia.wiki.document_owner_sync_pending');
        });
    }

    public function test_index_pages_tab_ignores_unused_documents_and_other_customer_pages(): void
    {
        $customer = $this->createCustomer('Egen kunde');
        $otherCustomer = $this->createCustomer('Annen kunde');
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $owner = $this->createUser($customer, User::BID_ROLE_BID_MANAGER);

        $ownPage = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Egen side');
        $ownVersion = $this->createVersion($ownPage, isCurrentTrue: true);
        $ownDoc = $this->createDocument($customer);
        $ownDoc->forceFill(['owner_user_id' => $owner->id])->save();
        $ownClaim = $this->createClaim($ownPage, $ownVersion, 'Egen kilde');
        $this->createDocumentSourceReference($ownClaim, $ownDoc);
        $this->createDocumentOwnerApproval($customer, $ownPage, $ownVersion, $owner, [$ownDoc->id], EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_PENDING);

        $unusedDoc = $this->createDocument($customer, EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED);
        $foreignPage = $this->createPage($otherCustomer, EnterpriseWikiPage::STATUS_APPROVED, 'Fremmed side');
        $foreignVersion = $this->createVersion($foreignPage, isCurrentTrue: true);
        $foreignDoc = $this->createDocument($otherCustomer);
        $foreignOwner = $this->createUser($otherCustomer, User::BID_ROLE_BID_MANAGER);
        $foreignDoc->forceFill(['owner_user_id' => $foreignOwner->id])->save();
        $foreignClaim = $this->createClaim($foreignPage, $foreignVersion, 'Fremmed kilde');
        $this->createDocumentSourceReference($foreignClaim, $foreignDoc);
        $this->createDocumentOwnerApproval($otherCustomer, $foreignPage, $foreignVersion, $foreignOwner, [$foreignDoc->id], EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_APPROVED);

        $response = $this->actingAs($user)->get('/app/wiki');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($ownPage, $unusedDoc, $foreignPage, $owner): bool {
            $pages = collect(data_get($inertia, 'props.pages', []));
            $own = $pages->firstWhere('id', $ownPage->id);
            $foreign = $pages->firstWhere('id', $foreignPage->id);

            return $own !== null
                && data_get($own, 'document_owner_summary.label') === $owner->name.' · '.__('procynia.wiki.document_owner_pending_label')
                && ! $pages->contains(fn (array $page) => $page['id'] === $unusedDoc->id)
                && $foreign === null;
        });
    }

    // =========================================================================
    // Phase 8F-3: safe deletion of EnterpriseWikiDocument — delete-preview
    // =========================================================================

    public function test_delete_preview_returns_404_for_wrong_customer(): void
    {
        $owner = $this->createCustomer('Eier');
        $other = $this->createCustomer('Annen');
        $user = $this->createUser($owner, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($other);

        $this->actingAs($user)
            ->getJson("/app/wiki/sources/{$doc->id}/delete-preview")
            ->assertNotFound();
    }

    public function test_delete_preview_returns_blocked_for_queued_run(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);
        $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_QUEUED);

        $response = $this->actingAs($user)
            ->getJson("/app/wiki/sources/{$doc->id}/delete-preview")
            ->assertOk();

        $this->assertTrue($response->json('blocked'));
        $this->assertSame('in_progress_run', $response->json('reason'));
    }

    public function test_delete_preview_not_blocked_for_completed_run(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);
        $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_COMPLETED);

        $response = $this->actingAs($user)
            ->getJson("/app/wiki/sources/{$doc->id}/delete-preview")
            ->assertOk();

        $this->assertFalse($response->json('blocked'));
        $this->assertSame($doc->original_filename, $response->json('document_name'));
        $this->assertSame(1, $response->json('run_count'));
        $this->assertSame(0, $response->json('sole_source_page_count'));
        $this->assertSame(0, $response->json('shared_page_count'));
    }

    public function test_delete_preview_counts_sole_source_page(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'Kun én kilde');
        $this->createIngestRunPage($run, $page);

        $response = $this->actingAs($user)
            ->getJson("/app/wiki/sources/{$doc->id}/delete-preview")
            ->assertOk();

        $this->assertFalse($response->json('blocked'));
        $this->assertSame(1, $response->json('sole_source_page_count'));
        $this->assertSame(0, $response->json('shared_page_count'));
    }

    public function test_delete_preview_counts_shared_page(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $docA = $this->createDocument($customer);
        $docB = $this->createDocument($customer);
        $runA = $this->createIngestRun($customer, $docA, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        $runB = $this->createIngestRun($customer, $docB, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        $shared = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'Delt side');
        $this->createIngestRunPage($runA, $shared);
        $this->createIngestRunPage($runB, $shared);

        $response = $this->actingAs($user)
            ->getJson("/app/wiki/sources/{$docA->id}/delete-preview")
            ->assertOk();

        $this->assertFalse($response->json('blocked'));
        $this->assertSame(0, $response->json('sole_source_page_count'));
        $this->assertSame(1, $response->json('shared_page_count'));
    }

    public function test_delete_preview_does_not_delete_anything(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'Skal overleve preview');
        $this->createIngestRunPage($run, $page);

        $docsBefore = EnterpriseWikiDocument::query()->count();
        $runsBefore = EnterpriseWikiIngestRun::query()->count();
        $pagesBefore = EnterpriseWikiPage::query()->count();

        $this->actingAs($user)
            ->getJson("/app/wiki/sources/{$doc->id}/delete-preview")
            ->assertOk();

        $this->assertSame($docsBefore, EnterpriseWikiDocument::query()->count());
        $this->assertSame($runsBefore, EnterpriseWikiIngestRun::query()->count());
        $this->assertSame($pagesBefore, EnterpriseWikiPage::query()->count());
    }

    // =========================================================================
    // Phase 8F-3: safe deletion — destroy
    // =========================================================================

    public function test_destroy_returns_404_for_wrong_customer(): void
    {
        $owner = $this->createCustomer('Eier');
        $other = $this->createCustomer('Annen');
        $user = $this->createUser($owner, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($other);

        $this->actingAs($user)
            ->delete("/app/wiki/sources/{$doc->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('enterprise_wiki_documents', ['id' => $doc->id]);
    }

    public function test_destroy_blocks_when_in_progress_run_exists(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_RUNNING);

        $this->actingAs($user)
            ->delete("/app/wiki/sources/{$doc->id}")
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('enterprise_wiki_documents', ['id' => $doc->id]);
        $this->assertDatabaseHas('enterprise_wiki_ingest_runs', ['id' => $run->id]);
    }

    public function test_destroy_deletes_document_and_runs_without_pages(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_FAILED);

        $this->actingAs($user)
            ->delete("/app/wiki/sources/{$doc->id}")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('enterprise_wiki_documents', ['id' => $doc->id]);
        $this->assertDatabaseMissing('enterprise_wiki_ingest_runs', ['id' => $run->id]);
    }

    public function test_destroy_deletes_sole_source_page_and_its_data(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'Enkelt kildeside');
        $version = $this->createVersion($page, isCurrentTrue: true);
        $claim = $this->createClaim($page, $version, 'Påstand');
        $this->createSourceReference($claim, 'kilde.pdf');
        $this->createLintFinding($customer, $page, EnterpriseWikiLintFinding::SEVERITY_ERROR);
        $this->createIngestRunPage($run, $page);

        $this->actingAs($user)
            ->delete("/app/wiki/sources/{$doc->id}")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('enterprise_wiki_documents', ['id' => $doc->id]);
        $this->assertDatabaseMissing('enterprise_wiki_pages', ['id' => $page->id]);
        $this->assertDatabaseMissing('enterprise_wiki_page_versions', ['enterprise_wiki_page_id' => $page->id]);
        $this->assertDatabaseMissing('enterprise_wiki_claims', ['enterprise_wiki_page_id' => $page->id]);
        $this->assertDatabaseMissing('enterprise_wiki_lint_findings', ['enterprise_wiki_page_id' => $page->id]);
    }

    public function test_destroy_keeps_shared_page_intact(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $docA = $this->createDocument($customer);
        $docB = $this->createDocument($customer);
        $runA = $this->createIngestRun($customer, $docA, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        $runB = $this->createIngestRun($customer, $docB, EnterpriseWikiIngestRun::STATUS_COMPLETED);

        $shared = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Delt konsept');
        $this->createIngestRunPage($runA, $shared);
        $this->createIngestRunPage($runB, $shared);

        $this->actingAs($user)
            ->delete("/app/wiki/sources/{$docA->id}")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('enterprise_wiki_documents', ['id' => $docA->id]);
        $this->assertDatabaseHas('enterprise_wiki_pages', ['id' => $shared->id]);
    }

    public function test_destroy_removes_document_source_references_on_shared_pages(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $docA = $this->createDocument($customer);
        $docB = $this->createDocument($customer);
        $runA = $this->createIngestRun($customer, $docA, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        $runB = $this->createIngestRun($customer, $docB, EnterpriseWikiIngestRun::STATUS_COMPLETED);

        $shared = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Delt side med kilde');
        $version = $this->createVersion($shared, isCurrentTrue: true);
        $claim = $this->createClaim($shared, $version, 'Delt påstand');
        $this->createIngestRunPage($runA, $shared);
        $this->createIngestRunPage($runB, $shared);

        // Source reference pointing to the document being deleted
        $docRef = EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $claim->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $docA->id,
            'source_label' => $docA->original_filename,
            'source_hash' => str_pad('d', 64, '0'),
            'excerpt' => 'Utdrag fra docA',
        ]);

        $this->actingAs($user)
            ->delete("/app/wiki/sources/{$docA->id}")
            ->assertRedirect()
            ->assertSessionHas('success');

        // Shared page survives
        $this->assertDatabaseHas('enterprise_wiki_pages', ['id' => $shared->id]);
        $this->assertDatabaseHas('enterprise_wiki_claims', ['id' => $claim->id]);
        // Source reference pointing to the deleted document is removed
        $this->assertDatabaseMissing('enterprise_wiki_source_references', ['id' => $docRef->id]);
    }

    public function test_destroy_deletes_lint_findings_for_document(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);

        $finding = EnterpriseWikiLintFinding::query()->create([
            'customer_id' => $customer->id,
            'enterprise_wiki_document_id' => $doc->id,
            'code' => EnterpriseWikiLintFinding::CODE_DOCUMENT_INGEST_FAILED,
            'severity' => EnterpriseWikiLintFinding::SEVERITY_ERROR,
            'message' => 'Ingest feilet',
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
            'detected_at' => now(),
        ]);

        $this->actingAs($user)
            ->delete("/app/wiki/sources/{$doc->id}")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('enterprise_wiki_lint_findings', ['id' => $finding->id]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createCustomer(string $name = 'Testkunde AS'): Customer
    {
        $language = Language::query()->firstOrCreate(
            ['code' => 'no'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk'],
        );

        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO'],
        );

        return Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    private function createUser(Customer $customer, string $bidRole, bool $isQa = false): User
    {
        return User::query()->create([
            'name' => 'Test User',
            'email' => Str::lower(Str::random(8)).'@test.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => $bidRole,
            'is_qa' => $isQa,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function createPage(
        Customer $customer,
        string $status,
        string $title,
        string $pageType = EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
    ): EnterpriseWikiPage {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(4)),
            'title' => $title,
            'page_type' => $pageType,
            'status' => $status,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }

    private function createPageLink(
        Customer $customer,
        EnterpriseWikiPage $from,
        EnterpriseWikiPage $to,
        string $linkType,
    ): EnterpriseWikiPageLink {
        return EnterpriseWikiPageLink::query()->create([
            'customer_id' => $customer->id,
            'from_page_id' => $from->id,
            'to_page_id' => $to->id,
            'link_type' => $linkType,
            'source' => EnterpriseWikiPageLink::SOURCE_DETERMINISTIC,
            'confidence' => EnterpriseWikiPageLink::CONFIDENCE_CERTAIN,
        ]);
    }

    private function createVersion(EnterpriseWikiPage $page, bool $isCurrentTrue = false): EnterpriseWikiPageVersion
    {
        return EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => $isCurrentTrue,
            'content_markdown' => '# '.$page->title,
        ]);
    }

    private function createClaim(
        EnterpriseWikiPage $page,
        EnterpriseWikiPageVersion $version,
        string $text,
        int $positionOrder = 0,
        array $overrides = [],
    ): EnterpriseWikiClaim {
        return EnterpriseWikiClaim::query()->create(array_merge([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => $text,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'position_order' => $positionOrder,
        ], $overrides));
    }

    private function createDocument(Customer $customer, string $status = EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'test-document.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki-documents/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'document_status' => $status,
        ]);
    }

    private function createIngestRun(Customer $customer, EnterpriseWikiDocument $document, string $status): EnterpriseWikiIngestRun
    {
        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_hash' => hash('sha256', "enterprise_wiki_document:{$document->id}"),
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'status' => $status,
        ]);
    }

    private function createSourceReference(
        EnterpriseWikiClaim $claim,
        string $label,
        ?string $excerpt = null,
        ?string $pageReference = null,
    ): EnterpriseWikiSourceReference {
        return EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $claim->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_KNOWLEDGE_ITEM_VERSION,
            'source_id' => 1,
            'source_label' => $label,
            'source_hash' => str_pad('h', 64, '0'),
            'excerpt' => $excerpt,
            'page_reference' => $pageReference,
        ]);
    }

    private function createDocumentSourceReference(EnterpriseWikiClaim $claim, EnterpriseWikiDocument $document, ?string $excerpt = null): EnterpriseWikiSourceReference
    {
        return EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $claim->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_label' => $document->original_filename,
            'source_hash' => hash('sha256', 'enterprise_wiki_document:'.$document->id),
            'excerpt' => $excerpt,
            'page_reference' => null,
        ]);
    }

    private function createDocumentOwnerApproval(
        Customer $customer,
        EnterpriseWikiPage $page,
        EnterpriseWikiPageVersion $version,
        ?User $owner,
        array $sourceDocumentIds,
        string $status = EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_PENDING,
        bool $isOverride = false,
    ): EnterpriseWikiPageVersionDocumentOwnerApproval {
        sort($sourceDocumentIds);

        return EnterpriseWikiPageVersionDocumentOwnerApproval::query()->create([
            'customer_id' => $customer->id,
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'enterprise_wiki_ingest_run_id' => null,
            'document_owner_user_id' => $owner?->id,
            'source_document_ids' => $sourceDocumentIds,
            'source_documents_hash' => hash('sha256', json_encode($sourceDocumentIds, JSON_THROW_ON_ERROR)),
            'approval_status' => $status,
            'approval_comment' => null,
            'decided_at' => $status === EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_PENDING ? null : now(),
            'decided_by_user_id' => $status === EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_PENDING ? null : $owner?->id,
            'is_override' => $isOverride,
            'override_reason' => $isOverride ? 'Override for test coverage.' : null,
            'overridden_by_user_id' => $isOverride ? $owner?->id : null,
            'overridden_at' => $isOverride ? now() : null,
        ]);
    }

    private function createLintFinding(
        Customer $customer,
        EnterpriseWikiPage $page,
        string $severity,
        string $status = EnterpriseWikiLintFinding::STATUS_OPEN,
    ): EnterpriseWikiLintFinding {
        return EnterpriseWikiLintFinding::query()->create([
            'customer_id' => $customer->id,
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_claim_id' => null,
            'enterprise_wiki_document_id' => null,
            'code' => EnterpriseWikiLintFinding::CODE_CLAIM_MISSING_SOURCE,
            'severity' => $severity,
            'message' => 'Testfunn',
            'status' => $status,
            'detected_at' => now(),
            'resolved_at' => $status === EnterpriseWikiLintFinding::STATUS_RESOLVED ? now() : null,
        ]);
    }

    private function createDecisionOnlyRun(Customer $customer, EnterpriseWikiDocument $document, array $decision = []): EnterpriseWikiIngestRun
    {
        if ($decision === []) {
            $decision = [
                'source_article' => ['action' => 'create', 'title' => 'Test', 'proposed_slug' => 'test-ab1c', 'reason' => 'New.'],
                'source_summary' => ['action' => 'create', 'title' => 'Sammendrag', 'proposed_slug' => 'sammendrag-ab1c', 'reason' => 'Companion.'],
                'concept_pages' => [],
                'entity_pages' => [],
                'no_action_reason' => null,
                'warnings' => [],
            ];
        }

        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'status' => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_json' => $decision,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_PENDING,
            'maintainer_decision_generated_at' => now(),
        ]);
    }

    private function createIngestRunPage(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $page, string $action = 'created'): void
    {
        DB::table('enterprise_wiki_ingest_run_pages')->insertOrIgnore([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => $action,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // =========================================================================
    // Phase 8E-8: maintainer decision prop in sources
    // =========================================================================

    public function test_index_decision_only_run_is_included_in_source_latest_ingest_run(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $document = $this->createDocument($customer);
        $this->createDecisionOnlyRun($customer, $document);

        $response = $this->actingAs($user)->get('/app/wiki?tab=sources');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($document): bool {
            $sources = data_get($inertia, 'props.sources', []);
            $source = collect($sources)->firstWhere('id', $document->id);

            return data_get($source, 'latest_ingest_run.status') === EnterpriseWikiIngestRun::STATUS_DECISION_ONLY;
        });
    }

    public function test_index_decision_only_run_includes_maintainer_decision_json_in_prop(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $document = $this->createDocument($customer);
        $this->createDecisionOnlyRun($customer, $document);

        $response = $this->actingAs($user)->get('/app/wiki?tab=sources');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($document): bool {
            $sources = data_get($inertia, 'props.sources', []);
            $source = collect($sources)->firstWhere('id', $document->id);
            $json = data_get($source, 'latest_ingest_run.maintainer_decision_json');

            return is_array($json) && array_key_exists('source_article', $json);
        });
    }

    public function test_index_decision_only_run_includes_maintainer_decision_status_in_prop(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $document = $this->createDocument($customer);
        $this->createDecisionOnlyRun($customer, $document);

        $response = $this->actingAs($user)->get('/app/wiki?tab=sources');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($document): bool {
            $sources = data_get($inertia, 'props.sources', []);
            $source = collect($sources)->firstWhere('id', $document->id);

            return data_get($source, 'latest_ingest_run.maintainer_decision_status') === EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_PENDING;
        });
    }

    public function test_index_decision_only_run_includes_maintainer_decision_generated_at_in_prop(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $document = $this->createDocument($customer);
        $this->createDecisionOnlyRun($customer, $document);

        $response = $this->actingAs($user)->get('/app/wiki?tab=sources');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($document): bool {
            $sources = data_get($inertia, 'props.sources', []);
            $source = collect($sources)->firstWhere('id', $document->id);

            return data_get($source, 'latest_ingest_run.maintainer_decision_generated_at') !== null;
        });
    }

    public function test_index_source_latest_ingest_run_includes_progress_fields(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $document = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $document, EnterpriseWikiIngestRun::STATUS_GENERATING_PAGES);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Fremdriftsside');
        $this->createIngestRunPage($run, $page);

        $response = $this->actingAs($user)->get('/app/wiki?tab=sources');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($document): bool {
            $sources = data_get($inertia, 'props.sources', []);
            $source = collect($sources)->firstWhere('id', $document->id);
            $latestRun = data_get($source, 'latest_ingest_run');

            return data_get($source, 'latest_ingest_run.status') === EnterpriseWikiIngestRun::STATUS_GENERATING_PAGES
                && data_get($latestRun, 'last_progress_at') !== null
                && data_get($latestRun, 'updated_at') !== null
                && data_get($latestRun, 'pages_count') === 1
                && data_get($latestRun, 'sections_count') === 0
                && data_get($latestRun, 'lint_count') === 0;
        });
    }

    public function test_index_decision_only_run_not_leaked_to_other_customer(): void
    {
        $customer = $this->createCustomer('Eigen kunde');
        $other = $this->createCustomer('Annen kunde');
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $ownDoc = $this->createDocument($customer);
        $foreignDoc = $this->createDocument($other);
        $this->createDecisionOnlyRun($other, $foreignDoc);

        $response = $this->actingAs($user)->get('/app/wiki?tab=sources');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($ownDoc): bool {
            $sources = data_get($inertia, 'props.sources', []);
            $source = collect($sources)->firstWhere('id', $ownDoc->id);

            // Own doc has no run; foreign decision must not appear
            return $source !== null && $source['latest_ingest_run'] === null;
        });
    }

    public function test_index_source_without_decision_run_has_null_maintainer_decision_json(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $document = $this->createDocument($customer);
        $this->createIngestRun($customer, $document, EnterpriseWikiIngestRun::STATUS_COMPLETED);

        $response = $this->actingAs($user)->get('/app/wiki?tab=sources');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($document): bool {
            $sources = data_get($inertia, 'props.sources', []);
            $source = collect($sources)->firstWhere('id', $document->id);

            return data_get($source, 'latest_ingest_run.maintainer_decision_json') === null;
        });
    }

    public function test_index_get_request_does_not_create_any_wiki_rows(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $document = $this->createDocument($customer);
        $this->createDecisionOnlyRun($customer, $document);

        $pagesBefore = EnterpriseWikiPage::query()->count();
        $versionsBefore = EnterpriseWikiPageVersion::query()->count();
        $claimsBefore = EnterpriseWikiClaim::query()->count();
        $runsBefore = EnterpriseWikiIngestRun::query()->count();

        $this->actingAs($user)->get('/app/wiki')->assertOk();

        $this->assertSame($pagesBefore, EnterpriseWikiPage::query()->count());
        $this->assertSame($versionsBefore, EnterpriseWikiPageVersion::query()->count());
        $this->assertSame($claimsBefore, EnterpriseWikiClaim::query()->count());
        $this->assertSame($runsBefore, EnterpriseWikiIngestRun::query()->count());
    }

    // =========================================================================
    // Phase 8F-1: tab navigation
    // =========================================================================

    public function test_index_default_tab_is_pages(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $response = $this->actingAs($user)->get('/app/wiki');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia): bool {
            return data_get($inertia, 'props.active_tab') === 'pages';
        });
    }

    public function test_index_tab_param_sources_sets_active_tab(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $response = $this->actingAs($user)->get('/app/wiki?tab=sources');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia): bool {
            return data_get($inertia, 'props.active_tab') === 'sources';
        });
    }

    public function test_index_invalid_tab_falls_back_to_pages(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $response = $this->actingAs($user)->get('/app/wiki?tab=invalid');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia): bool {
            return data_get($inertia, 'props.active_tab') === 'pages';
        });
    }

    public function test_index_tab_runs_returns_runs_prop(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $document = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $document, EnterpriseWikiIngestRun::STATUS_COMPLETED);

        $response = $this->actingAs($user)->get('/app/wiki?tab=runs');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($run): bool {
            $runs = data_get($inertia, 'props.runs', []);

            return data_get($inertia, 'props.active_tab') === 'runs'
                && collect($runs)->contains(fn (array $r) => $r['id'] === $run->id);
        });
    }

    public function test_index_tab_runs_is_scoped_to_current_customer(): void
    {
        $customer = $this->createCustomer('Eigen kunde');
        $other = $this->createCustomer('Annen kunde');
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $ownDoc = $this->createDocument($customer);
        $foreignDoc = $this->createDocument($other);
        $ownRun = $this->createIngestRun($customer, $ownDoc, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        $foreignRun = $this->createIngestRun($other, $foreignDoc, EnterpriseWikiIngestRun::STATUS_COMPLETED);

        $response = $this->actingAs($user)->get('/app/wiki?tab=runs');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($ownRun, $foreignRun): bool {
            $ids = collect(data_get($inertia, 'props.runs', []))->pluck('id');

            return $ids->contains($ownRun->id) && ! $ids->contains($foreignRun->id);
        });
    }

    public function test_index_tab_quality_returns_quality_findings_prop(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'Kvalitetsside');
        $finding = $this->createLintFinding($customer, $page, EnterpriseWikiLintFinding::SEVERITY_WARNING);

        $response = $this->actingAs($user)->get('/app/wiki?tab=quality');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($finding): bool {
            $findings = data_get($inertia, 'props.quality_findings', []);

            return data_get($inertia, 'props.active_tab') === 'quality'
                && collect($findings)->contains(fn (array $f) => $f['id'] === $finding->id);
        });
    }

    public function test_index_tab_quality_is_scoped_to_current_customer(): void
    {
        $customer = $this->createCustomer('Eigen kunde');
        $other = $this->createCustomer('Annen kunde');
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $ownPage = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'Eigen side');
        $foreignPage = $this->createPage($other, EnterpriseWikiPage::STATUS_DRAFT, 'Annen side');
        $foreignFinding = $this->createLintFinding($other, $foreignPage, EnterpriseWikiLintFinding::SEVERITY_ERROR);

        $response = $this->actingAs($user)->get('/app/wiki?tab=quality');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($foreignFinding): bool {
            $ids = collect(data_get($inertia, 'props.quality_findings', []))->pluck('id');

            return ! $ids->contains($foreignFinding->id);
        });
    }

    // =========================================================================
    // Phase 8F-5: quality tab — filtering
    // =========================================================================

    public function test_quality_tab_filter_by_severity(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'Testside');
        $error = $this->createLintFinding($customer, $page, EnterpriseWikiLintFinding::SEVERITY_ERROR);
        $warning = $this->createLintFinding($customer, $page, EnterpriseWikiLintFinding::SEVERITY_WARNING);

        $response = $this->actingAs($user)->get('/app/wiki?tab=quality&q_severity=error');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($error, $warning): bool {
            $ids = collect(data_get($inertia, 'props.quality_findings', []))->pluck('id');

            return $ids->contains($error->id) && ! $ids->contains($warning->id);
        });
    }

    public function test_quality_tab_filter_by_code(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'Testside');

        $missingSource = EnterpriseWikiLintFinding::query()->create([
            'customer_id' => $customer->id,
            'enterprise_wiki_page_id' => $page->id,
            'code' => EnterpriseWikiLintFinding::CODE_CLAIM_MISSING_SOURCE,
            'severity' => EnterpriseWikiLintFinding::SEVERITY_WARNING,
            'message' => 'Mangler kilde',
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
            'detected_at' => now(),
        ]);
        $missingExcerpt = EnterpriseWikiLintFinding::query()->create([
            'customer_id' => $customer->id,
            'enterprise_wiki_page_id' => $page->id,
            'code' => EnterpriseWikiLintFinding::CODE_SOURCE_REFERENCE_MISSING_EXCERPT,
            'severity' => EnterpriseWikiLintFinding::SEVERITY_WARNING,
            'message' => 'Mangler utdrag',
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
            'detected_at' => now(),
        ]);

        $response = $this->actingAs($user)->get('/app/wiki?tab=quality&q_code=claim_missing_source');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($missingSource, $missingExcerpt): bool {
            $ids = collect(data_get($inertia, 'props.quality_findings', []))->pluck('id');

            return $ids->contains($missingSource->id) && ! $ids->contains($missingExcerpt->id);
        });
    }

    public function test_quality_tab_filter_by_page_type(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $articlePage = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'title' => 'Article side',
            'slug' => 'article-side-'.uniqid(),
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
        ]);
        $conceptPage = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'title' => 'Concept side',
            'slug' => 'concept-side-'.uniqid(),
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
        ]);

        $articleFinding = $this->createLintFinding($customer, $articlePage, EnterpriseWikiLintFinding::SEVERITY_WARNING);
        $conceptFinding = $this->createLintFinding($customer, $conceptPage, EnterpriseWikiLintFinding::SEVERITY_WARNING);

        $response = $this->actingAs($user)->get('/app/wiki?tab=quality&q_page_type=article');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($articleFinding, $conceptFinding): bool {
            $ids = collect(data_get($inertia, 'props.quality_findings', []))->pluck('id');

            return $ids->contains($articleFinding->id) && ! $ids->contains($conceptFinding->id);
        });
    }

    public function test_quality_tab_invalid_filters_are_ignored(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'Testside');
        $finding = $this->createLintFinding($customer, $page, EnterpriseWikiLintFinding::SEVERITY_WARNING);

        $response = $this->actingAs($user)->get('/app/wiki?tab=quality&q_severity=bogus&q_code=not_a_code&q_page_type=not_a_type');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($finding): bool {
            $ids = collect(data_get($inertia, 'props.quality_findings', []))->pluck('id');

            return $ids->contains($finding->id);
        });
    }

    public function test_quality_tab_returns_quality_filters_prop(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $response = $this->actingAs($user)->get('/app/wiki?tab=quality&q_severity=error&q_page_type=article');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia): bool {
            $f = data_get($inertia, 'props.quality_filters');

            return $f !== null
                && $f['severity'] === 'error'
                && $f['page_type'] === 'article';
        });
    }

    public function test_quality_tab_finding_exposes_page_type_and_run_id(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'Testside');

        $finding = EnterpriseWikiLintFinding::query()->create([
            'customer_id' => $customer->id,
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'code' => EnterpriseWikiLintFinding::CODE_CLAIM_MISSING_SOURCE,
            'severity' => EnterpriseWikiLintFinding::SEVERITY_WARNING,
            'message' => 'Testfunn',
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
            'detected_at' => now(),
        ]);

        $response = $this->actingAs($user)->get('/app/wiki?tab=quality');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($finding, $run): bool {
            $found = collect(data_get($inertia, 'props.quality_findings', []))
                ->firstWhere('id', $finding->id);

            return $found !== null
                && $found['run_id'] === $run->id
                && isset($found['page_type'])
                && isset($found['source_filename'])
                && isset($found['target_url'])
                && str_contains($found['target_url'], '/app/wiki/'.$found['page_slug']);
        });
    }

    public function test_quality_tab_navigation_targets_page_and_claim_findings(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'Navigasjonsside');
        $version = $this->createVersion($page, isCurrentTrue: true);
        $claim = $this->createClaim($page, $version, 'Påstand uten kilde.');
        $pageFinding = EnterpriseWikiLintFinding::query()->create([
            'customer_id' => $customer->id,
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'code' => EnterpriseWikiLintFinding::CODE_ARTICLE_WITHOUT_SUMMARY_LINK,
            'severity' => EnterpriseWikiLintFinding::SEVERITY_WARNING,
            'message' => 'Article page has no link to a summary page.',
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
            'detected_at' => now(),
        ]);
        $claimFinding = EnterpriseWikiLintFinding::query()->create([
            'customer_id' => $customer->id,
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'enterprise_wiki_claim_id' => $claim->id,
            'code' => EnterpriseWikiLintFinding::CODE_CLAIM_MISSING_SOURCE,
            'severity' => EnterpriseWikiLintFinding::SEVERITY_WARNING,
            'message' => 'Claim has no source reference.',
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
            'detected_at' => now(),
        ]);

        $response = $this->actingAs($user)->get('/app/wiki?tab=quality');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($pageFinding, $claimFinding, $claim, $page): bool {
            $rows = collect(data_get($inertia, 'props.quality_findings', []))->keyBy('id');
            $pageRow = $rows->get($pageFinding->id);
            $claimRow = $rows->get($claimFinding->id);

            return $pageRow !== null
                && $claimRow !== null
                && $pageRow['target_url'] === route('app.wiki.show', ['slug' => $pageRow['page_slug']])
                && $pageRow['target_page_id'] === $page->id
                && $pageRow['target_claim_id'] === null
                && $claimRow['target_url'] === route('app.wiki.show', ['slug' => $pageRow['page_slug']]).'?claim_id='.$claim->id
                && $claimRow['target_page_id'] === $page->id
                && $claimRow['target_claim_id'] === $claim->id;
        });
    }

    public function test_quality_tab_keeps_unknown_check_types_visible(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'Testside');
        $finding = EnterpriseWikiLintFinding::query()->create([
            'customer_id' => $customer->id,
            'enterprise_wiki_page_id' => $page->id,
            'code' => 'future_unknown_check_type',
            'severity' => EnterpriseWikiLintFinding::SEVERITY_INFO,
            'message' => 'Future unknown check message.',
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
            'detected_at' => now(),
        ]);

        $response = $this->actingAs($user)->get('/app/wiki?tab=quality');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($finding): bool {
            $found = collect(data_get($inertia, 'props.quality_findings', []))
                ->firstWhere('id', $finding->id);

            return $found !== null
                && ($found['code'] ?? null) === 'future_unknown_check_type'
                && ($found['message'] ?? null) === 'Future unknown check message.';
        });
    }

    public function test_quality_tab_translates_all_current_check_types(): void
    {
        $codes = EnterpriseWikiLintFinding::CODES;
        $locale = app()->getLocale();

        app()->setLocale('no');
        foreach ($codes as $code) {
            $label = trans("procynia.wiki.quality_checks.{$code}.label");
            $description = trans("procynia.wiki.quality_checks.{$code}.description");

            $this->assertNotSame($code, $label);
            $this->assertNotEmpty($description);
        }

        app()->setLocale('en');
        foreach ($codes as $code) {
            $label = trans("procynia.wiki.quality_checks.{$code}.label");
            $description = trans("procynia.wiki.quality_checks.{$code}.description");

            $this->assertNotSame($code, $label);
            $this->assertNotEmpty($description);
        }

        app()->setLocale($locale);
    }

    public function test_quality_tab_empty_state_handled_gracefully(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $response = $this->actingAs($user)->get('/app/wiki?tab=quality');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia): bool {
            $findings = data_get($inertia, 'props.quality_findings', []);

            return is_array($findings) && count($findings) === 0;
        });
    }

    public function test_quality_tab_guest_is_redirected(): void
    {
        $response = $this->get('/app/wiki?tab=quality');
        $response->assertRedirect('/login');
    }

    // =========================================================================
    // Phase 8G-2: coverage panel in quality tab
    // =========================================================================

    public function test_quality_tab_includes_coverage_prop(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $response = $this->actingAs($user)->get('/app/wiki?tab=quality');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia): bool {
            $coverage = data_get($inertia, 'props.coverage');

            return is_array($coverage)
                && array_key_exists('source_coverage', $coverage)
                && array_key_exists('page_quality', $coverage)
                && array_key_exists('claim_coverage', $coverage)
                && array_key_exists('lint', $coverage);
        });
    }

    public function test_quality_tab_coverage_source_coverage_data(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);
        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $doc->id,
            'source_hash' => hash('sha256', "doc:{$doc->id}"),
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'status' => EnterpriseWikiIngestRun::STATUS_COMPLETED,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
        ]);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Coverage test side');
        $this->createIngestRunPage($run, $page);

        $response = $this->actingAs($user)->get('/app/wiki?tab=quality');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia): bool {
            $sc = data_get($inertia, 'props.coverage.source_coverage');

            return is_array($sc)
                && $sc['extracted_documents'] === 1
                && $sc['documents_with_applied_run'] === 1;
        });
    }

    public function test_quality_tab_coverage_page_quality_data(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Godkjent side');
        $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'Utkast side');

        $response = $this->actingAs($user)->get('/app/wiki?tab=quality');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia): bool {
            $pq = data_get($inertia, 'props.coverage.page_quality');

            return is_array($pq) && $pq['total'] === 2;
        });
    }

    public function test_quality_tab_coverage_claim_coverage_data(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Claim test side');
        $version = $this->createVersion($page, true);
        $this->createClaim($page, $version, 'Test-påstand');

        $response = $this->actingAs($user)->get('/app/wiki?tab=quality');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia): bool {
            $cc = data_get($inertia, 'props.coverage.claim_coverage');

            return is_array($cc)
                && $cc['claims_total'] === 1
                && $cc['claims_without_source_reference'] === 1;
        });
    }

    public function test_quality_tab_coverage_lint_data(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Lint test side');
        $this->createLintFinding($customer, $page, EnterpriseWikiLintFinding::SEVERITY_ERROR);
        $this->createLintFinding($customer, $page, EnterpriseWikiLintFinding::SEVERITY_WARNING);

        $response = $this->actingAs($user)->get('/app/wiki?tab=quality');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia): bool {
            $lint = data_get($inertia, 'props.coverage.lint');

            return is_array($lint)
                && $lint['open_errors'] === 1
                && $lint['open_warnings'] === 1;
        });
    }

    public function test_quality_tab_coverage_gaps_are_customer_scoped(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $this->createDocument($customer); // no applied run → gap

        $response = $this->actingAs($user)->get('/app/wiki?tab=quality');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia): bool {
            $gaps = data_get($inertia, 'props.coverage.source_coverage.gaps', []);

            return count($gaps) === 1;
        });
    }

    public function test_quality_tab_coverage_other_customer_data_not_counted(): void
    {
        $customerA = $this->createCustomer('Kunde A');
        $customerB = $this->createCustomer('Kunde B');
        $userA = $this->createUser($customerA, User::BID_ROLE_SYSTEM_OWNER);
        $this->createPage($customerB, EnterpriseWikiPage::STATUS_APPROVED, 'Annen kundes side');
        $this->createDocument($customerB);

        $response = $this->actingAs($userA)->get('/app/wiki?tab=quality');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia): bool {
            $pq = data_get($inertia, 'props.coverage.page_quality');
            $sc = data_get($inertia, 'props.coverage.source_coverage');

            return $pq['total'] === 0 && $sc['extracted_documents'] === 0;
        });
    }

    public function test_quality_tab_is_read_only(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        // Quality tab renders via GET — verify no POST route exists for coverage
        $this->actingAs($user)->get('/app/wiki?tab=quality')->assertOk();
        $this->actingAs($user)->post('/app/wiki?tab=quality')->assertStatus(405);
    }

    // =========================================================================
    // Phase 8F-2: search and filtering — pages tab
    // =========================================================================

    public function test_pages_tab_search_filters_by_title(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $match = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'ISO 9001 sertifisering');
        $noMatch = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Miljøpolicy');

        $response = $this->actingAs($user)->get('/app/wiki?tab=pages&search=ISO');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($match, $noMatch): bool {
            $ids = collect(data_get($inertia, 'props.pages', []))->pluck('id');

            return $ids->contains($match->id) && ! $ids->contains($noMatch->id);
        });
    }

    public function test_pages_tab_search_is_case_insensitive(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $match = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Kompetansekrav');

        $response = $this->actingAs($user)->get('/app/wiki?tab=pages&search=kompetanse');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($match): bool {
            $ids = collect(data_get($inertia, 'props.pages', []))->pluck('id');

            return $ids->contains($match->id);
        });
    }

    public function test_pages_tab_page_type_filter_works(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $concept = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Kvalitetsbegrep', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $article = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'ISO artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE);

        $response = $this->actingAs($user)->get('/app/wiki?tab=pages&page_type=concept');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($concept, $article): bool {
            $ids = collect(data_get($inertia, 'props.pages', []))->pluck('id');

            return $ids->contains($concept->id) && ! $ids->contains($article->id);
        });
    }

    public function test_pages_tab_status_filter_works(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $draft = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'Utkast side');
        $approved = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Godkjent side');

        $response = $this->actingAs($owner)->get('/app/wiki?tab=pages&status=draft');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($draft, $approved): bool {
            $ids = collect(data_get($inertia, 'props.pages', []))->pluck('id');

            return $ids->contains($draft->id) && ! $ids->contains($approved->id);
        });
    }

    public function test_pages_tab_lint_filter_errors_returns_pages_with_error_findings(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $errorPage = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'Side med feil');
        $cleanPage = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'Ren side');
        $this->createLintFinding($customer, $errorPage, EnterpriseWikiLintFinding::SEVERITY_ERROR);

        $response = $this->actingAs($user)->get('/app/wiki?tab=pages&lint=errors');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($errorPage, $cleanPage): bool {
            $ids = collect(data_get($inertia, 'props.pages', []))->pluck('id');

            return $ids->contains($errorPage->id) && ! $ids->contains($cleanPage->id);
        });
    }

    public function test_pages_tab_lint_filter_warnings_returns_pages_with_warning_findings(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $warnPage = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'Side med advarsel');
        $cleanPage = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'Ren side');
        $this->createLintFinding($customer, $warnPage, EnterpriseWikiLintFinding::SEVERITY_WARNING);

        $response = $this->actingAs($user)->get('/app/wiki?tab=pages&lint=warnings');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($warnPage, $cleanPage): bool {
            $ids = collect(data_get($inertia, 'props.pages', []))->pluck('id');

            return $ids->contains($warnPage->id) && ! $ids->contains($cleanPage->id);
        });
    }

    public function test_pages_tab_lint_filter_ok_returns_pages_without_open_findings(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $cleanPage = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'Ren side');
        $errorPage = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'Side med feil');
        $this->createLintFinding($customer, $errorPage, EnterpriseWikiLintFinding::SEVERITY_ERROR);

        $response = $this->actingAs($user)->get('/app/wiki?tab=pages&lint=ok');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($cleanPage, $errorPage): bool {
            $ids = collect(data_get($inertia, 'props.pages', []))->pluck('id');

            return $ids->contains($cleanPage->id) && ! $ids->contains($errorPage->id);
        });
    }

    public function test_pages_tab_combined_search_and_page_type_filter(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $match = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'ISO konsept', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $wrongType = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'ISO artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
        $wrongTitle = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Annet konsept', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);

        $response = $this->actingAs($user)->get('/app/wiki?tab=pages&search=ISO&page_type=concept');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($match, $wrongType, $wrongTitle): bool {
            $ids = collect(data_get($inertia, 'props.pages', []))->pluck('id');

            return $ids->contains($match->id)
                && ! $ids->contains($wrongType->id)
                && ! $ids->contains($wrongTitle->id);
        });
    }

    public function test_pages_tab_invalid_page_type_is_ignored_safely(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Normal side');

        $response = $this->actingAs($user)->get('/app/wiki?tab=pages&page_type=invalid_type');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($page): bool {
            $ids = collect(data_get($inertia, 'props.pages', []))->pluck('id');

            return $ids->contains($page->id);
        });
    }

    public function test_pages_tab_invalid_lint_value_is_ignored_safely(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Normal side');

        $response = $this->actingAs($user)->get('/app/wiki?tab=pages&lint=blahblah');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($page): bool {
            $ids = collect(data_get($inertia, 'props.pages', []))->pluck('id');

            return $ids->contains($page->id);
        });
    }

    public function test_pages_tab_invalid_status_not_in_visible_statuses_is_ignored(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $approved = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Godkjent');

        // contributor cannot see draft, so ?status=draft should be ignored
        $response = $this->actingAs($user)->get('/app/wiki?tab=pages&status=draft');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($approved): bool {
            $ids = collect(data_get($inertia, 'props.pages', []))->pluck('id');

            return $ids->contains($approved->id);
        });
    }

    public function test_pages_tab_sort_title_asc_orders_alphabetically(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $b = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'Beta side');
        $a = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'Alfa side');

        $response = $this->actingAs($user)->get('/app/wiki?tab=pages&sort=title_asc');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($a, $b): bool {
            $ids = collect(data_get($inertia, 'props.pages', []))->pluck('id')->values();
            $posA = $ids->search($a->id);
            $posB = $ids->search($b->id);

            return $posA !== false && $posB !== false && $posA < $posB;
        });
    }

    public function test_pages_tab_invalid_sort_falls_back_to_default(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $response = $this->actingAs($user)->get('/app/wiki?tab=pages&sort=not_a_valid_sort');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia): bool {
            $filters = data_get($inertia, 'props.pages_filters');

            return $filters !== null && $filters['sort'] === 'updated_at_desc';
        });
    }

    public function test_pages_tab_returns_pages_filters_prop(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $response = $this->actingAs($user)->get('/app/wiki?tab=pages&search=foo&page_type=concept&lint=errors&sort=title_asc');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia): bool {
            $f = data_get($inertia, 'props.pages_filters');

            return $f !== null
                && $f['search'] === 'foo'
                && $f['page_type'] === 'concept'
                && $f['lint'] === 'errors'
                && $f['sort'] === 'title_asc';
        });
    }

    public function test_pages_tab_returns_pages_meta_prop(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $response = $this->actingAs($user)->get('/app/wiki?tab=pages');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia): bool {
            $meta = data_get($inertia, 'props.pages_meta');

            return $meta !== null
                && array_key_exists('current_page', $meta)
                && array_key_exists('per_page', $meta)
                && array_key_exists('total', $meta)
                && array_key_exists('last_page', $meta);
        });
    }

    public function test_pages_tab_filter_is_scoped_to_customer(): void
    {
        $customer = $this->createCustomer('Eigen kunde');
        $other = $this->createCustomer('Annen kunde');
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $ownPage = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'ISO sertifikat');
        $foreignPage = $this->createPage($other, EnterpriseWikiPage::STATUS_APPROVED, 'ISO rutine');

        $response = $this->actingAs($user)->get('/app/wiki?tab=pages&search=ISO');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($ownPage, $foreignPage): bool {
            $ids = collect(data_get($inertia, 'props.pages', []))->pluck('id');

            return $ids->contains($ownPage->id) && ! $ids->contains($foreignPage->id);
        });
    }

    // =========================================================================
    // Phase 8F-2: search and filtering — sources tab
    // =========================================================================

    public function test_sources_tab_search_filters_by_filename(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $matchDoc = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'iso9001-kvalitetsmanual.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki-documents/match.pdf',
            'file_hash_sha256' => hash('sha256', 'match'),
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
        $noMatchDoc = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'miljopolicy.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki-documents/nomatch.pdf',
            'file_hash_sha256' => hash('sha256', 'nomatch'),
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);

        $response = $this->actingAs($user)->get('/app/wiki?tab=sources&src_q=iso');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($matchDoc, $noMatchDoc): bool {
            $ids = collect(data_get($inertia, 'props.sources', []))->pluck('id');

            return $ids->contains($matchDoc->id) && ! $ids->contains($noMatchDoc->id);
        });
    }

    public function test_sources_tab_status_filter_works(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $extracted = $this->createDocument($customer, EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED);
        $failed = $this->createDocument($customer, EnterpriseWikiDocument::DOCUMENT_STATUS_FAILED);

        $response = $this->actingAs($user)->get('/app/wiki?tab=sources&src_status=extracted');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($extracted, $failed): bool {
            $ids = collect(data_get($inertia, 'props.sources', []))->pluck('id');

            return $ids->contains($extracted->id) && ! $ids->contains($failed->id);
        });
    }

    public function test_sources_tab_combined_search_and_status_filter(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $match = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'iso-extracted.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki-documents/iso-e.pdf',
            'file_hash_sha256' => hash('sha256', 'iso-e'),
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
        $wrongStatus = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'iso-failed.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki-documents/iso-f.pdf',
            'file_hash_sha256' => hash('sha256', 'iso-f'),
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_FAILED,
        ]);

        $response = $this->actingAs($user)->get('/app/wiki?tab=sources&src_q=iso&src_status=extracted');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($match, $wrongStatus): bool {
            $ids = collect(data_get($inertia, 'props.sources', []))->pluck('id');

            return $ids->contains($match->id) && ! $ids->contains($wrongStatus->id);
        });
    }

    public function test_sources_tab_invalid_status_is_ignored_safely(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);

        $response = $this->actingAs($user)->get('/app/wiki?tab=sources&src_status=not_a_status');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($doc): bool {
            $ids = collect(data_get($inertia, 'props.sources', []))->pluck('id');

            return $ids->contains($doc->id);
        });
    }

    public function test_sources_tab_returns_sources_filters_prop(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $response = $this->actingAs($user)->get('/app/wiki?tab=sources&src_q=test&src_status=extracted');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia): bool {
            $f = data_get($inertia, 'props.sources_filters');

            return $f !== null
                && $f['search'] === 'test'
                && $f['status'] === 'extracted';
        });
    }

    public function test_sources_tab_filter_is_scoped_to_customer(): void
    {
        $customer = $this->createCustomer('Eigen kunde');
        $other = $this->createCustomer('Annen kunde');
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $ownDoc = $this->createDocument($customer);
        $foreignDoc = $this->createDocument($other);

        $response = $this->actingAs($user)->get('/app/wiki?tab=sources&src_status=extracted');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($ownDoc, $foreignDoc): bool {
            $ids = collect(data_get($inertia, 'props.sources', []))->pluck('id');

            return $ids->contains($ownDoc->id) && ! $ids->contains($foreignDoc->id);
        });
    }

    // =========================================================================
    // Phase 8F-4: runs tab — history and filtering
    // =========================================================================

    public function test_runs_tab_returns_runs_for_customer(): void
    {
        $customer = $this->createCustomer();
        $other = $this->createCustomer('Annen kunde');
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);
        $foreignDoc = $this->createDocument($other);
        $ownRun = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        $foreignRun = $this->createIngestRun($other, $foreignDoc, EnterpriseWikiIngestRun::STATUS_COMPLETED);

        $response = $this->actingAs($user)->get('/app/wiki?tab=runs');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($ownRun, $foreignRun): bool {
            $ids = collect(data_get($inertia, 'props.runs', []))->pluck('id');

            return $ids->contains($ownRun->id) && ! $ids->contains($foreignRun->id);
        });
    }

    public function test_runs_tab_includes_counts_and_filename(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Testside');
        $this->createIngestRunPage($run, $page);

        $response = $this->actingAs($user)->get('/app/wiki?tab=runs');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($run, $doc): bool {
            $runs = data_get($inertia, 'props.runs', []);
            $found = collect($runs)->firstWhere('id', $run->id);

            return $found !== null
                && $found['source_document_filename'] === $doc->original_filename
                && isset($found['pages_count'])
                && isset($found['sections_count'])
                && isset($found['lint_count'])
                && isset($found['updated_at'])
                && isset($found['last_progress_at'])
                && $found['pages_count'] === 1
                && $found['sections_count'] === 0
                && $found['lint_count'] === 0;
        });
    }

    public function test_runs_tab_filter_by_status(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);
        $completed = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        $failed = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_FAILED);

        $response = $this->actingAs($user)->get('/app/wiki?tab=runs&run_status=completed');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($completed, $failed): bool {
            $ids = collect(data_get($inertia, 'props.runs', []))->pluck('id');

            return $ids->contains($completed->id) && ! $ids->contains($failed->id);
        });
    }

    public function test_runs_tab_filter_by_decision_applied(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);
        $applied = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        $applied->update(['maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED]);
        $pending = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        $pending->update(['maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_PENDING]);

        $response = $this->actingAs($user)->get('/app/wiki?tab=runs&run_decision=applied');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($applied, $pending): bool {
            $ids = collect(data_get($inertia, 'props.runs', []))->pluck('id');

            return $ids->contains($applied->id) && ! $ids->contains($pending->id);
        });
    }

    public function test_runs_tab_filter_by_decision_none(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);
        $noDecision = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        $withDecision = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        $withDecision->update(['maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_PENDING]);

        $response = $this->actingAs($user)->get('/app/wiki?tab=runs&run_decision=none');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($noDecision, $withDecision): bool {
            $ids = collect(data_get($inertia, 'props.runs', []))->pluck('id');

            return $ids->contains($noDecision->id) && ! $ids->contains($withDecision->id);
        });
    }

    public function test_runs_tab_filter_by_source_document(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $docA = $this->createDocument($customer);
        $docB = $this->createDocument($customer);
        $runA = $this->createIngestRun($customer, $docA, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        $runB = $this->createIngestRun($customer, $docB, EnterpriseWikiIngestRun::STATUS_COMPLETED);

        $response = $this->actingAs($user)->get("/app/wiki?tab=runs&run_src={$docA->id}");

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($runA, $runB): bool {
            $ids = collect(data_get($inertia, 'props.runs', []))->pluck('id');

            return $ids->contains($runA->id) && ! $ids->contains($runB->id);
        });
    }

    public function test_runs_tab_invalid_filters_are_ignored(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_COMPLETED);

        $response = $this->actingAs($user)->get('/app/wiki?tab=runs&run_status=not_a_status&run_decision=bogus&run_src=abc');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($run): bool {
            $ids = collect(data_get($inertia, 'props.runs', []))->pluck('id');

            return $ids->contains($run->id);
        });
    }

    public function test_runs_tab_returns_runs_filters_prop(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $response = $this->actingAs($user)->get('/app/wiki?tab=runs&run_status=completed&run_decision=applied');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia): bool {
            $f = data_get($inertia, 'props.runs_filters');

            return $f !== null
                && $f['status'] === 'completed'
                && $f['decision'] === 'applied';
        });
    }

    // =========================================================================
    // Runs tab — can_cancel flag
    // =========================================================================

    public function test_runs_tab_can_cancel_true_for_system_owner_on_non_terminal_run(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL);

        $response = $this->actingAs($user)->get('/app/wiki?tab=runs');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($run): bool {
            $found = collect(data_get($inertia, 'props.runs', []))->firstWhere('id', $run->id);

            return $found !== null && $found['can_cancel'] === true;
        });
    }

    public function test_runs_tab_can_cancel_false_for_terminal_run(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_COMPLETED);

        $response = $this->actingAs($user)->get('/app/wiki?tab=runs');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($run): bool {
            $found = collect(data_get($inertia, 'props.runs', []))->firstWhere('id', $run->id);

            return $found !== null && $found['can_cancel'] === false;
        });
    }

    public function test_runs_tab_can_cancel_false_for_contributor_without_ownership(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $doc = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL);

        $response = $this->actingAs($user)->get('/app/wiki?tab=runs');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($run): bool {
            $found = collect(data_get($inertia, 'props.runs', []))->firstWhere('id', $run->id);

            return $found !== null && $found['can_cancel'] === false;
        });
    }

    public function test_runs_tab_can_cancel_true_for_contributor_who_owns_document(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $doc = $this->createDocument($customer);
        $doc->update(['owner_user_id' => $user->id]);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL);

        $response = $this->actingAs($user)->get('/app/wiki?tab=runs');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($run): bool {
            $found = collect(data_get($inertia, 'props.runs', []))->firstWhere('id', $run->id);

            return $found !== null && $found['can_cancel'] === true;
        });
    }

    // =========================================================================
    // Runs tab — PATCH /app/wiki/runs/{run}/cancel
    // =========================================================================

    public function test_cancel_run_sets_status_cancelled_for_system_owner(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL);

        $response = $this->actingAs($user)->patch("/app/wiki/runs/{$run->id}/cancel");

        $response->assertRedirect(route('app.wiki.index', ['tab' => 'runs']));
        $response->assertSessionHas('success');
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_CANCELLED, $run->fresh()->status);
        $this->assertNotNull($run->fresh()->finished_at);
    }

    public function test_cancel_run_allows_document_owner(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $doc = $this->createDocument($customer);
        $doc->update(['owner_user_id' => $owner->id]);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL);

        $response = $this->actingAs($owner)->patch("/app/wiki/runs/{$run->id}/cancel");

        $response->assertSessionHas('success');
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_CANCELLED, $run->fresh()->status);
    }

    public function test_cancel_run_rejects_contributor_without_ownership(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $doc = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL);

        $response = $this->actingAs($user)->patch("/app/wiki/runs/{$run->id}/cancel");

        $response->assertForbidden();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL, $run->fresh()->status);
    }

    public function test_cancel_run_rejects_run_from_another_customer(): void
    {
        $customer = $this->createCustomer('Eier');
        $other = $this->createCustomer('Fremmed');
        $user = $this->createUser($other, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL);

        $response = $this->actingAs($user)->patch("/app/wiki/runs/{$run->id}/cancel");

        $response->assertNotFound();
    }

    public function test_cancel_run_on_already_terminal_run_returns_error_and_leaves_status_unchanged(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_COMPLETED);

        $response = $this->actingAs($user)->patch("/app/wiki/runs/{$run->id}/cancel");

        $response->assertRedirect(route('app.wiki.index', ['tab' => 'runs']));
        $response->assertSessionHas('error');
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $run->fresh()->status);
    }

    public function test_cancelling_a_run_makes_its_document_deletable(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL);

        $blockedPreview = $this->actingAs($user)->getJson("/app/wiki/sources/{$doc->id}/delete-preview");
        $blockedPreview->assertOk();
        $this->assertTrue($blockedPreview->json('blocked'));

        $this->actingAs($user)->patch("/app/wiki/runs/{$run->id}/cancel")->assertSessionHas('success');

        $unblockedPreview = $this->actingAs($user)->getJson("/app/wiki/sources/{$doc->id}/delete-preview");
        $unblockedPreview->assertOk();
        $this->assertFalse($unblockedPreview->json('blocked'));
    }

    // =========================================================================
    // Runs tab — GET /app/wiki/runs/{run}/pages (Sider detail panel)
    // =========================================================================

    public function test_run_pages_count_matches_unique_pages_in_detail_list(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        $pageA = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Side A');
        $pageB = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Side B');
        $versionA = $this->createVersion($pageA, true);
        $versionB = $this->createVersion($pageB, true);
        $this->createRunPage($run, $pageA, $versionA, EnterpriseWikiIngestRunPage::ACTION_CREATED);
        $this->createRunPage($run, $pageB, $versionB, EnterpriseWikiIngestRunPage::ACTION_UPDATED);

        $tabResponse = $this->actingAs($user)->get('/app/wiki?tab=runs');
        $pagesCount = collect(data_get($tabResponse->viewData('page'), 'props.runs', []))
            ->firstWhere('id', $run->id)['pages_count'] ?? null;

        $response = $this->actingAs($user)->getJson("/app/wiki/runs/{$run->id}/pages");

        $response->assertOk();
        $ids = collect($response->json('pages'))->pluck('page_id')->unique();
        $this->assertSame($pagesCount, $ids->count());
        $this->assertSame(2, $response->json('summary.total'));
    }

    public function test_run_pages_classifies_created_and_updated(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        $created = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Ny side');
        $updated = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Eksisterende side');
        $createdVersion = $this->createVersion($created, true);
        $updatedVersion = $this->createVersion($updated, true);
        $this->createRunPage($run, $created, $createdVersion, EnterpriseWikiIngestRunPage::ACTION_CREATED);
        $this->createRunPage($run, $updated, $updatedVersion, EnterpriseWikiIngestRunPage::ACTION_UPDATED);

        $response = $this->actingAs($user)->getJson("/app/wiki/runs/{$run->id}/pages");

        $response->assertOk();
        $pages = collect($response->json('pages'))->keyBy('page_id');
        $this->assertSame('created', $pages[$created->id]['action']);
        $this->assertSame('updated', $pages[$updated->id]['action']);
    }

    public function test_run_pages_returns_empty_list_for_run_without_pages(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_COMPLETED);

        $response = $this->actingAs($user)->getJson("/app/wiki/runs/{$run->id}/pages");

        $response->assertOk();
        $this->assertSame([], $response->json('pages'));
        $this->assertSame(0, $response->json('summary.total'));
    }

    public function test_run_pages_document_owner_status_pending_is_awaiting(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $owner = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $doc = $this->createDocument($customer);
        $doc->update(['owner_user_id' => $owner->id]);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Venter side');
        $version = $this->createVersion($page, true);
        $this->createRunPage($run, $page, $version, EnterpriseWikiIngestRunPage::ACTION_UPDATED);
        $claim = $this->createClaim($page, $version, 'Testpåstand', 0, ['content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED]);
        $this->createDocumentSourceReference($claim, $doc);
        $this->createDocumentOwnerApproval($customer, $page, $version, $owner, [$doc->id], EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_PENDING);

        $response = $this->actingAs($user)->getJson("/app/wiki/runs/{$run->id}/pages");

        $response->assertOk();
        $row = collect($response->json('pages'))->firstWhere('page_id', $page->id);
        $this->assertContains($row['document_owner_status']['state'], ['pending', 'mixed']);
        $this->assertSame(1, $response->json('summary.awaiting_document_owner'));
        $this->assertNotNull($response->json('stall_explanation'));
    }

    public function test_run_pages_document_owner_status_approved(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $owner = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $doc = $this->createDocument($customer);
        $doc->update(['owner_user_id' => $owner->id]);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Godkjent side');
        $version = $this->createVersion($page, true);
        $this->createRunPage($run, $page, $version, EnterpriseWikiIngestRunPage::ACTION_CREATED);
        $claim = $this->createClaim($page, $version, 'Testpåstand', 0, ['content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED]);
        $this->createDocumentSourceReference($claim, $doc);
        $this->createDocumentOwnerApproval($customer, $page, $version, $owner, [$doc->id], EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_APPROVED);

        $response = $this->actingAs($user)->getJson("/app/wiki/runs/{$run->id}/pages");

        $response->assertOk();
        $row = collect($response->json('pages'))->firstWhere('page_id', $page->id);
        $this->assertSame('approved', $row['document_owner_status']['state']);
        $this->assertSame(1, $response->json('summary.done'));
    }

    public function test_run_pages_document_owner_status_rejected(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $owner = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $doc = $this->createDocument($customer);
        $doc->update(['owner_user_id' => $owner->id]);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Avvist side');
        $version = $this->createVersion($page, true);
        $this->createRunPage($run, $page, $version, EnterpriseWikiIngestRunPage::ACTION_CREATED);
        $claim = $this->createClaim($page, $version, 'Testpåstand', 0, ['content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED]);
        $this->createDocumentSourceReference($claim, $doc);
        $this->createDocumentOwnerApproval($customer, $page, $version, $owner, [$doc->id], EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_REJECTED);

        $response = $this->actingAs($user)->getJson("/app/wiki/runs/{$run->id}/pages");

        $response->assertOk();
        $row = collect($response->json('pages'))->firstWhere('page_id', $page->id);
        $this->assertSame('rejected', $row['document_owner_status']['state']);
        $this->assertSame(1, $response->json('summary.done'));
    }

    public function test_run_pages_marks_superseded_version_distinctly(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Utdatert versjon');
        $oldVersion = $this->createVersion($page, false);
        $newVersion = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 2,
            'is_current' => true,
            'content_markdown' => '# Utdatert versjon v2',
        ]);
        $this->createRunPage($run, $page, $oldVersion, EnterpriseWikiIngestRunPage::ACTION_CREATED);

        $response = $this->actingAs($user)->getJson("/app/wiki/runs/{$run->id}/pages");

        $response->assertOk();
        $row = collect($response->json('pages'))->firstWhere('page_id', $page->id);
        $this->assertSame('superseded', $row['document_owner_status']['state']);
        $this->assertFalse($row['is_current_version']);
        $this->assertFalse($row['can_handle']);
    }

    public function test_run_pages_still_generating_page_is_processing(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_GENERATING_PAGES);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'Under generering');
        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            'generated_page_version_id' => null,
            'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_RUNNING,
        ]);

        $response = $this->actingAs($user)->getJson("/app/wiki/runs/{$run->id}/pages");

        $response->assertOk();
        $row = collect($response->json('pages'))->firstWhere('page_id', $page->id);
        $this->assertSame('processing', $row['document_owner_status']['state']);
        $this->assertNull($row['page_version_id']);
    }

    public function test_run_pages_can_handle_true_for_required_document_owner(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $doc = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Krever behandling');
        $version = $this->createVersion($page, true);
        $this->createRunPage($run, $page, $version, EnterpriseWikiIngestRunPage::ACTION_UPDATED);
        $this->createDocumentOwnerApproval($customer, $page, $version, $owner, [$doc->id], EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_PENDING);

        $response = $this->actingAs($owner)->getJson("/app/wiki/runs/{$run->id}/pages");

        $response->assertOk();
        $row = collect($response->json('pages'))->firstWhere('page_id', $page->id);
        $this->assertTrue($row['can_handle']);
    }

    public function test_run_pages_can_handle_false_for_unrelated_contributor(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $unrelated = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $doc = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Krever behandling 2');
        $version = $this->createVersion($page, true);
        $this->createRunPage($run, $page, $version, EnterpriseWikiIngestRunPage::ACTION_UPDATED);
        $this->createDocumentOwnerApproval($customer, $page, $version, $owner, [$doc->id], EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_PENDING);

        $response = $this->actingAs($unrelated)->getJson("/app/wiki/runs/{$run->id}/pages");

        $response->assertOk();
        $row = collect($response->json('pages'))->firstWhere('page_id', $page->id);
        $this->assertFalse($row['can_handle']);
    }

    public function test_run_pages_url_points_to_wiki_show(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Lenket side');
        $version = $this->createVersion($page, true);
        $this->createRunPage($run, $page, $version, EnterpriseWikiIngestRunPage::ACTION_CREATED);

        $response = $this->actingAs($user)->getJson("/app/wiki/runs/{$run->id}/pages");

        $response->assertOk();
        $row = collect($response->json('pages'))->firstWhere('page_id', $page->id);
        $this->assertSame(route('app.wiki.show', $page->slug), $row['url']);
    }

    public function test_run_pages_rejects_run_from_another_customer(): void
    {
        $customer = $this->createCustomer('Eier');
        $other = $this->createCustomer('Fremmed');
        $user = $this->createUser($other, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_COMPLETED);

        $response = $this->actingAs($user)->getJson("/app/wiki/runs/{$run->id}/pages");

        $response->assertNotFound();
    }

    public function test_run_pages_rejects_manipulated_run_id(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $response = $this->actingAs($user)->getJson('/app/wiki/runs/999999/pages');

        $response->assertNotFound();
    }

    public function test_run_pages_stall_explanation_null_when_run_not_awaiting_owner(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Ferdig side');
        $version = $this->createVersion($page, true);
        $this->createRunPage($run, $page, $version, EnterpriseWikiIngestRunPage::ACTION_CREATED);

        $response = $this->actingAs($user)->getJson("/app/wiki/runs/{$run->id}/pages");

        $response->assertOk();
        $this->assertNull($response->json('stall_explanation'));
    }

    public function test_run_pages_stall_explanation_reports_needs_resync_when_nothing_awaiting(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $owner = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $doc = $this->createDocument($customer);
        $doc->update(['owner_user_id' => $owner->id]);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Foreldet status');
        $version = $this->createVersion($page, true);
        $this->createRunPage($run, $page, $version, EnterpriseWikiIngestRunPage::ACTION_CREATED);
        $claim = $this->createClaim($page, $version, 'Testpåstand', 0, ['content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED]);
        $this->createDocumentSourceReference($claim, $doc);
        $this->createDocumentOwnerApproval($customer, $page, $version, $owner, [$doc->id], EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_APPROVED);

        $response = $this->actingAs($user)->getJson("/app/wiki/runs/{$run->id}/pages");

        $response->assertOk();
        $this->assertSame(0, $response->json('summary.awaiting_document_owner'));
        $this->assertNotNull($response->json('stall_explanation'));
        $this->assertStringContainsString(
            __('procynia.wiki.runs_pages_stall_needs_resync'),
            (string) $response->json('stall_explanation'),
        );
    }

    private function createRunPage(
        EnterpriseWikiIngestRun $run,
        EnterpriseWikiPage $page,
        EnterpriseWikiPageVersion $version,
        string $action,
    ): EnterpriseWikiIngestRunPage {
        return EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => $action,
            'generated_page_version_id' => $version->id,
            'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
        ]);
    }

    // =========================================================================
    // Runs tab — GET /app/wiki/runs/{run}/findings (Funn detail panel)
    // =========================================================================

    public function test_run_findings_count_matches_total_in_response(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Funn-side');
        $version = $this->createVersion($page, true);
        $this->createRunPage($run, $page, $version, EnterpriseWikiIngestRunPage::ACTION_CREATED);

        $this->createRunLintFinding($customer, $run, $page, $version, EnterpriseWikiLintFinding::SEVERITY_ERROR, EnterpriseWikiLintFinding::STATUS_OPEN);
        $this->createRunLintFinding($customer, $run, $page, $version, EnterpriseWikiLintFinding::SEVERITY_WARNING, EnterpriseWikiLintFinding::STATUS_OPEN);
        $this->createRunLintFinding($customer, $run, $page, $version, EnterpriseWikiLintFinding::SEVERITY_INFO, EnterpriseWikiLintFinding::STATUS_RESOLVED);
        $this->createClaimDefect($page, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR);

        $lintCountFromTab = null;
        $this->actingAs($user)->get('/app/wiki?tab=runs')->assertViewHas('page', function (array $inertia) use ($run, &$lintCountFromTab): bool {
            $lintCountFromTab = collect(data_get($inertia, 'props.runs', []))->firstWhere('id', $run->id)['lint_count'] ?? null;

            return true;
        });

        $response = $this->actingAs($user)->getJson("/app/wiki/runs/{$run->id}/findings");

        $response->assertOk();
        $this->assertSame(4, $response->json('summary.total'));
        $this->assertSame($lintCountFromTab, $response->json('summary.total'));
        $this->assertCount(4, $response->json('findings'));
    }

    public function test_run_findings_returns_empty_list_for_run_without_findings(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_COMPLETED);

        $response = $this->actingAs($user)->getJson("/app/wiki/runs/{$run->id}/findings");

        $response->assertOk();
        $this->assertSame([], $response->json('findings'));
        $this->assertSame(0, $response->json('summary.total'));
    }

    public function test_run_findings_classifies_open_blocking_finding(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Blokkerende funn');
        $version = $this->createVersion($page, true);
        $this->createRunPage($run, $page, $version, EnterpriseWikiIngestRunPage::ACTION_CREATED);
        $this->createRunLintFinding($customer, $run, $page, $version, EnterpriseWikiLintFinding::SEVERITY_ERROR, EnterpriseWikiLintFinding::STATUS_OPEN);

        $response = $this->actingAs($user)->getJson("/app/wiki/runs/{$run->id}/findings");

        $response->assertOk();
        $finding = $response->json('findings')[0];
        $this->assertSame('requires_action', $finding['status']);
        $this->assertTrue($finding['blocks_run']);
        $this->assertSame(1, $response->json('summary.open_blocking'));
    }

    public function test_run_findings_classifies_open_non_blocking_finding(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Ikke-blokkerende funn');
        $version = $this->createVersion($page, true);
        $this->createRunPage($run, $page, $version, EnterpriseWikiIngestRunPage::ACTION_CREATED);
        $this->createRunLintFinding($customer, $run, $page, $version, EnterpriseWikiLintFinding::SEVERITY_WARNING, EnterpriseWikiLintFinding::STATUS_OPEN);

        $response = $this->actingAs($user)->getJson("/app/wiki/runs/{$run->id}/findings");

        $response->assertOk();
        $finding = $response->json('findings')[0];
        $this->assertSame('open', $finding['status']);
        $this->assertFalse($finding['blocks_run']);
        $this->assertSame(1, $response->json('summary.open_non_blocking'));
    }

    public function test_run_findings_classifies_resolved_finding(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Løst funn');
        $version = $this->createVersion($page, true);
        $this->createRunPage($run, $page, $version, EnterpriseWikiIngestRunPage::ACTION_CREATED);
        $this->createRunLintFinding($customer, $run, $page, $version, EnterpriseWikiLintFinding::SEVERITY_WARNING, EnterpriseWikiLintFinding::STATUS_RESOLVED);

        $response = $this->actingAs($user)->getJson("/app/wiki/runs/{$run->id}/findings");

        $response->assertOk();
        $finding = $response->json('findings')[0];
        $this->assertSame('resolved', $finding['status']);
        $this->assertSame(1, $response->json('summary.resolved'));
    }

    public function test_run_findings_classifies_informative_finding(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Informativt funn');
        $version = $this->createVersion($page, true);
        $this->createRunPage($run, $page, $version, EnterpriseWikiIngestRunPage::ACTION_CREATED);
        $this->createRunLintFinding($customer, $run, $page, $version, EnterpriseWikiLintFinding::SEVERITY_INFO, EnterpriseWikiLintFinding::STATUS_OPEN);

        $response = $this->actingAs($user)->getJson("/app/wiki/runs/{$run->id}/findings");

        $response->assertOk();
        $finding = $response->json('findings')[0];
        $this->assertSame('informative', $finding['status']);
        $this->assertSame(1, $response->json('summary.informative'));
    }

    public function test_run_findings_does_not_duplicate_claim_missing_source_as_a_claim_defect(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Kilde mangler');
        $version = $this->createVersion($page, true);
        $this->createRunPage($run, $page, $version, EnterpriseWikiIngestRunPage::ACTION_CREATED);
        $claim = $this->createClaim($page, $version, 'Påstand uten kilde', 0, ['content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED]);

        EnterpriseWikiLintFinding::query()->create([
            'customer_id' => $customer->id,
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'enterprise_wiki_claim_id' => $claim->id,
            'code' => EnterpriseWikiLintFinding::CODE_CLAIM_MISSING_SOURCE,
            'severity' => EnterpriseWikiLintFinding::SEVERITY_WARNING,
            'message' => 'Claim has no source reference.',
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
            'detected_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson("/app/wiki/runs/{$run->id}/findings");

        $response->assertOk();
        $this->assertSame(1, $response->json('summary.total'));
        $this->assertCount(1, $response->json('findings'));
    }

    public function test_run_findings_run_level_finding_has_no_page_scope(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_COMPLETED);

        EnterpriseWikiLintFinding::query()->create([
            'customer_id' => $customer->id,
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => null,
            'code' => EnterpriseWikiLintFinding::CODE_APPLIED_RUN_WITHOUT_PAGES,
            'severity' => EnterpriseWikiLintFinding::SEVERITY_ERROR,
            'message' => 'Run produced no pages.',
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
            'detected_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson("/app/wiki/runs/{$run->id}/findings");

        $response->assertOk();
        $finding = $response->json('findings')[0];
        $this->assertSame('run', $finding['scope']);
        $this->assertNull($finding['page_id']);
        $this->assertNull($finding['url']);
    }

    public function test_run_findings_marks_finding_on_superseded_version_as_not_blocking(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Utdatert funn');
        $oldVersion = $this->createVersion($page, false);
        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 2,
            'is_current' => true,
            'content_markdown' => '# v2',
        ]);
        $this->createRunPage($run, $page, $oldVersion, EnterpriseWikiIngestRunPage::ACTION_CREATED);
        $this->createRunLintFinding($customer, $run, $page, $oldVersion, EnterpriseWikiLintFinding::SEVERITY_ERROR, EnterpriseWikiLintFinding::STATUS_OPEN);

        $response = $this->actingAs($user)->getJson("/app/wiki/runs/{$run->id}/findings");

        $response->assertOk();
        $finding = $response->json('findings')[0];
        $this->assertSame('superseded', $finding['status']);
        $this->assertFalse($finding['blocks_run']);
        $this->assertSame(0, $response->json('summary.open_blocking'));
    }

    public function test_run_findings_explanation_passed_with_no_blocking(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        $run->update(['qa_status' => EnterpriseWikiIngestRun::QA_STATUS_PASSED]);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Bestått');
        $version = $this->createVersion($page, true);
        $this->createRunPage($run, $page, $version, EnterpriseWikiIngestRunPage::ACTION_CREATED);
        $this->createRunLintFinding($customer, $run, $page, $version, EnterpriseWikiLintFinding::SEVERITY_INFO, EnterpriseWikiLintFinding::STATUS_OPEN);

        $response = $this->actingAs($user)->getJson("/app/wiki/runs/{$run->id}/findings");

        $response->assertOk();
        $this->assertSame(
            __('procynia.wiki.runs_findings_explanation_passed_no_blocking', ['count' => 1]),
            $response->json('summary.explanation'),
        );
    }

    public function test_run_findings_explanation_flags_inconsistency_when_passed_but_blocking(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        $run->update(['qa_status' => EnterpriseWikiIngestRun::QA_STATUS_PASSED]);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Inkonsistent');
        $version = $this->createVersion($page, true);
        $this->createRunPage($run, $page, $version, EnterpriseWikiIngestRunPage::ACTION_CREATED);
        $this->createRunLintFinding($customer, $run, $page, $version, EnterpriseWikiLintFinding::SEVERITY_ERROR, EnterpriseWikiLintFinding::STATUS_OPEN);

        $response = $this->actingAs($user)->getJson("/app/wiki/runs/{$run->id}/findings");

        $response->assertOk();
        $this->assertSame(
            __('procynia.wiki.runs_findings_explanation_inconsistent_passed'),
            $response->json('summary.explanation'),
        );
    }

    public function test_run_findings_explanation_needs_resync_when_repair_required_but_no_blocking(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        $run->update(['qa_status' => EnterpriseWikiIngestRun::QA_STATUS_REPAIR_REQUIRED]);

        $response = $this->actingAs($user)->getJson("/app/wiki/runs/{$run->id}/findings");

        $response->assertOk();
        $this->assertSame(0, $response->json('summary.open_blocking'));
        $this->assertSame(
            __('procynia.wiki.runs_findings_explanation_needs_resync'),
            $response->json('summary.explanation'),
        );
    }

    public function test_run_findings_rejects_run_from_another_customer(): void
    {
        $customer = $this->createCustomer('Eier Funn');
        $other = $this->createCustomer('Fremmed Funn');
        $user = $this->createUser($other, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_COMPLETED);

        $response = $this->actingAs($user)->getJson("/app/wiki/runs/{$run->id}/findings");

        $response->assertNotFound();
    }

    public function test_run_findings_rejects_manipulated_run_id(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $response = $this->actingAs($user)->getJson('/app/wiki/runs/999999/findings');

        $response->assertNotFound();
    }

    public function test_run_findings_hides_technical_diagnostics_from_ordinary_contributor(): void
    {
        $customer = $this->createCustomer();
        $contributor = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $doc = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Teknisk skjult');
        $version = $this->createVersion($page, true);
        $this->createRunPage($run, $page, $version, EnterpriseWikiIngestRunPage::ACTION_CREATED);
        $this->createRunLintFinding($customer, $run, $page, $version, EnterpriseWikiLintFinding::SEVERITY_ERROR, EnterpriseWikiLintFinding::STATUS_OPEN);

        $response = $this->actingAs($contributor)->getJson("/app/wiki/runs/{$run->id}/findings");

        $response->assertOk();
        $finding = $response->json('findings')[0];
        $this->assertArrayNotHasKey('technical', $finding);
    }

    public function test_run_findings_includes_technical_diagnostics_for_system_owner(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Teknisk synlig');
        $version = $this->createVersion($page, true);
        $this->createRunPage($run, $page, $version, EnterpriseWikiIngestRunPage::ACTION_CREATED);
        $this->createRunLintFinding($customer, $run, $page, $version, EnterpriseWikiLintFinding::SEVERITY_ERROR, EnterpriseWikiLintFinding::STATUS_OPEN);

        $response = $this->actingAs($owner)->getJson("/app/wiki/runs/{$run->id}/findings");

        $response->assertOk();
        $finding = $response->json('findings')[0];
        $this->assertArrayHasKey('technical', $finding);
        $this->assertSame('lint_finding', $finding['technical']['source']);
    }

    public function test_run_findings_open_and_handle_shown_when_document_owner_can_handle_claim(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $doc = $this->createDocument($customer);
        $doc->update(['owner_user_id' => $owner->id]);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Kan behandles');
        $version = $this->createVersion($page, true);
        $this->createRunPage($run, $page, $version, EnterpriseWikiIngestRunPage::ACTION_CREATED);
        $claim = $this->createClaim($page, $version, 'Krever kilde', 0, ['content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED]);
        $this->createDocumentSourceReference($claim, $doc);

        EnterpriseWikiLintFinding::query()->create([
            'customer_id' => $customer->id,
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'enterprise_wiki_claim_id' => $claim->id,
            'code' => EnterpriseWikiLintFinding::CODE_SOURCE_REFERENCE_MISSING_EXCERPT,
            'severity' => EnterpriseWikiLintFinding::SEVERITY_WARNING,
            'message' => 'Source reference has no excerpt.',
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
            'detected_at' => now(),
        ]);

        $response = $this->actingAs($owner)->getJson("/app/wiki/runs/{$run->id}/findings");

        $response->assertOk();
        $finding = $response->json('findings')[0];
        $this->assertTrue($finding['can_handle']);
        $this->assertSame('open_and_handle', $finding['action']);
    }

    private function createRunLintFinding(
        Customer $customer,
        EnterpriseWikiIngestRun $run,
        EnterpriseWikiPage $page,
        EnterpriseWikiPageVersion $version,
        string $severity,
        string $status,
    ): EnterpriseWikiLintFinding {
        return EnterpriseWikiLintFinding::query()->create([
            'customer_id' => $customer->id,
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'code' => EnterpriseWikiLintFinding::CODE_EMPTY_PAGE_CONTENT,
            'severity' => $severity,
            'message' => 'Testfunn for Funn-panelet.',
            'status' => $status,
            'detected_at' => now(),
            'resolved_at' => $status === EnterpriseWikiLintFinding::STATUS_RESOLVED ? now() : null,
        ]);
    }

    private function createClaimDefect(
        EnterpriseWikiPage $page,
        EnterpriseWikiPageVersion $version,
        string $contentOrigin,
    ): EnterpriseWikiClaim {
        return $this->createClaim($page, $version, 'Defekt påstand', 0, ['content_origin' => $contentOrigin]);
    }
}
