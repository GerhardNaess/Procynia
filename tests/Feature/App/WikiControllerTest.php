<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiLintFinding;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
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
                && !empty($ref['excerpt']);
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

    public function test_index_sends_wiki_generation_available_as_false(): void
    {
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
            return collect($findings)->contains(fn(array $f) => $f['id'] === $finding->id);
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

        $response = $this->actingAs($user)->get('/app/wiki/'.$page->slug);

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia): bool {
            $summary = data_get($inertia, 'props.claim_summary');

            return $summary !== null
                && $summary['total'] === 3
                && $summary['source_found'] === 1
                && $summary['missing_excerpt'] === 1
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
    // Phase 8E-18: traversal data in show()
    // =========================================================================

    public function test_show_includes_page_type_in_page_prop(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE);

        $response = $this->actingAs($user)->get('/app/wiki/'.$page->slug);

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($page): bool {
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
        $this->createPageLink($customer, $article, $summary, EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_SUMMARY);

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
        $this->createPageLink($customer, $summary, $article, EnterpriseWikiPageLink::LINK_TYPE_SUMMARY_TO_ARTICLE);

        $response = $this->actingAs($user)->get('/app/wiki/'.$article->slug);

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($summary): bool {
            $links = data_get($inertia, 'props.incoming_links', []);
            return collect($links)->contains(fn(array $p) => $p['id'] === $summary->id);
        });
    }

    public function test_show_related_concepts_for_article_page(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $article = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Kildeartikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
        $concept = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Konsept', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $this->createPageLink($customer, $article, $concept, EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_CONCEPT);

        $response = $this->actingAs($user)->get('/app/wiki/'.$article->slug);

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($concept): bool {
            $concepts = data_get($inertia, 'props.related_concepts', []);
            return collect($concepts)->contains(fn(array $p) => $p['id'] === $concept->id);
        });
    }

    public function test_show_related_entities_for_article_page(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $article = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Kildeartikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
        $entity = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Entitet', EnterpriseWikiPage::PAGE_TYPE_ENTITY);
        $this->createPageLink($customer, $article, $entity, EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_ENTITY);

        $response = $this->actingAs($user)->get('/app/wiki/'.$article->slug);

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($entity): bool {
            $entities = data_get($inertia, 'props.related_entities', []);
            return collect($entities)->contains(fn(array $p) => $p['id'] === $entity->id);
        });
    }

    public function test_show_related_articles_for_concept_page(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $concept = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'Konsept', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $article = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT, 'Kildeartikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
        $this->createPageLink($customer, $concept, $article, EnterpriseWikiPageLink::LINK_TYPE_CONCEPT_TO_ARTICLE);

        $response = $this->actingAs($user)->get('/app/wiki/'.$concept->slug);

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($article): bool {
            $articles = data_get($inertia, 'props.related_articles', []);
            return collect($articles)->contains(fn(array $p) => $p['id'] === $article->id);
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
        $this->createPageLink($customer2, $page2, $page1, EnterpriseWikiPageLink::LINK_TYPE_SUMMARY_TO_ARTICLE);

        $response = $this->actingAs($user)->get('/app/wiki/'.$page1->slug);

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($page2): bool {
            $incoming = data_get($inertia, 'props.incoming_links', []);
            return ! collect($incoming)->contains(fn(array $p) => $p['id'] === $page2->id);
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
        $this->createPageLink($customer, $article, $summary, EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_SUMMARY);

        $linksBefore    = EnterpriseWikiPageLink::query()->count();
        $claimsBefore   = EnterpriseWikiClaim::query()->count();
        $findingsBefore = EnterpriseWikiLintFinding::query()->count();

        $this->actingAs($user)->get('/app/wiki/'.$article->slug)->assertOk();

        $this->assertSame($linksBefore, EnterpriseWikiPageLink::query()->count());
        $this->assertSame($claimsBefore, EnterpriseWikiClaim::query()->count());
        $this->assertSame($findingsBefore, EnterpriseWikiLintFinding::query()->count());
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
            'customer_id'  => $customer->id,
            'from_page_id' => $from->id,
            'to_page_id'   => $to->id,
            'link_type'    => $linkType,
            'source'       => EnterpriseWikiPageLink::SOURCE_DETERMINISTIC,
            'confidence'   => EnterpriseWikiPageLink::CONFIDENCE_CERTAIN,
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
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
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

    // =========================================================================
    // Phase 8E-8: maintainer decision prop in sources
    // =========================================================================

    public function test_index_decision_only_run_is_included_in_source_latest_ingest_run(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $document = $this->createDocument($customer);
        $this->createDecisionOnlyRun($customer, $document);

        $response = $this->actingAs($user)->get('/app/wiki');

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

        $response = $this->actingAs($user)->get('/app/wiki');

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

        $response = $this->actingAs($user)->get('/app/wiki');

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

        $response = $this->actingAs($user)->get('/app/wiki');

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($document): bool {
            $sources = data_get($inertia, 'props.sources', []);
            $source = collect($sources)->firstWhere('id', $document->id);
            return data_get($source, 'latest_ingest_run.maintainer_decision_generated_at') !== null;
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

        $response = $this->actingAs($user)->get('/app/wiki');

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

        $response = $this->actingAs($user)->get('/app/wiki');

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
}
