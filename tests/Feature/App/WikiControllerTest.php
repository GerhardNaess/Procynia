<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
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
                && collect($pages)->contains(fn(array $p) => $p['id'] === $page->id);
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

            return ! $pages->contains(fn(array $p) => $p['id'] === $draft->id);
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

            return ! $pages->contains(fn(array $p) => $p['id'] === $pending->id);
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

        $response = $this->actingAs($user)->get('/app/wiki');

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

        $response = $this->actingAs($user)->get('/app/wiki');

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

        $response = $this->actingAs($user)->get('/app/wiki');

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

        $response = $this->actingAs($user)->get('/app/wiki');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($document): bool {
            $sources = data_get($inertia, 'props.sources', []);
            $source = collect($sources)->firstWhere('id', $document->id);
            return data_get($source, 'latest_ingest_run.status') === EnterpriseWikiIngestRun::STATUS_QUEUED;
        });
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

        $response = $this->actingAs($user)->get('/app/wiki');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($document, $page): bool {
            $sources = data_get($inertia, 'props.sources', []);
            $source = collect($sources)->firstWhere('id', $document->id);
            $generatedPages = data_get($source, 'generated_pages', []);
            return collect($generatedPages)->contains(fn(array $p) => $p['id'] === $page->id);
        });
    }

    public function test_index_source_generated_pages_is_empty_when_no_run_exists(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $document = $this->createDocument($customer);

        $response = $this->actingAs($user)->get('/app/wiki');

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

        $response = $this->actingAs($user)->get('/app/wiki');

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

        $response = $this->actingAs($user)->get('/app/wiki');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($document): bool {
            $sources = data_get($inertia, 'props.sources', []);
            return collect($sources)->contains(fn(array $s) => $s['id'] === $document->id);
        });
    }

    public function test_index_does_not_return_other_customer_wiki_sources(): void
    {
        $customer = $this->createCustomer('Eigen kunde');
        $other = $this->createCustomer('Annen kunde');
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $ownDoc = $this->createDocument($customer);
        $foreignDoc = $this->createDocument($other);

        $response = $this->actingAs($user)->get('/app/wiki');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($ownDoc, $foreignDoc): bool {
            $ids = collect(data_get($inertia, 'props.sources', []))->pluck('id');
            return $ids->contains($ownDoc->id) && ! $ids->contains($foreignDoc->id);
        });
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

    private function createUser(Customer $customer, string $bidRole): User
    {
        return User::query()->create([
            'name' => 'Test User',
            'email' => Str::lower(Str::random(8)).'@test.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function createPage(Customer $customer, string $status, string $title): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(4)),
            'title' => $title,
            'status' => $status,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
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
    ): EnterpriseWikiClaim {
        return EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => $text,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'position_order' => 0,
        ]);
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
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
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
        string $excerpt,
    ): EnterpriseWikiSourceReference {
        return EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $claim->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_KNOWLEDGE_ITEM_VERSION,
            'source_id' => 1,
            'source_label' => $label,
            'source_hash' => str_pad('h', 64, '0'),
            'excerpt' => $excerpt,
        ]);
    }
}
