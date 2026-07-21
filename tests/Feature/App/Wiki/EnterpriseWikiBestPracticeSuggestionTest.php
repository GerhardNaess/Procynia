<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\EnterpriseWiki\EnterpriseWikiVerifyPageClaimsService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A legitimate best_practice claim (content_origin=best_practice, block-level best_practice_reason
 * already validated by EnterpriseWikiPageContentBlockService) must present as a neutral, harmless
 * suggestion everywhere it surfaces — never as a critical defect, never blocking, and always
 * directly navigable to the concrete text via the Kjøringer "Funn" panel.
 *
 * Reuses (does not reimplement): EnterpriseWikiPostIngestQaService::findClaimIntegrityDefects()
 * (already excludes best_practice — verified here, not changed), WikiClaimController's existing
 * approve/reject/edit-and-approve flow and canonical-fact cascade (545df5e — verified via the
 * existing WikiClaimControllerTest suite, not duplicated here), EnterpriseWikiRunFindingsService
 * (378527a) extended with a best-practice normalization path.
 */
class EnterpriseWikiBestPracticeSuggestionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    // =========================================================================
    // Del 1/Del 10 (1-2): classification in the Funn panel
    // =========================================================================

    public function test_pending_best_practice_finding_is_neutral_suggestion_not_critical(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Tilgangsstyring');
        $version = $this->createVersion($page, true);
        $this->createRunPage($run, $page, $version);
        $this->createClaim($page, $version, 'Det anbefales at tilgangsrettigheter gjennomgås kvartalsvis.', 0, [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'review_reason' => 'Regelmessig tilgangskontroll reduserer risikoen for utdaterte eller unødvendige rettigheter.',
        ]);

        $response = $this->actingAs($user)->getJson("/app/wiki/runs/{$run->id}/findings");

        $response->assertOk();
        $finding = collect($response->json('findings'))->firstWhere('category', 'best_practice_suggestion');
        $this->assertNotNull($finding);
        $this->assertSame('suggestion', $finding['severity']);
        $this->assertNotSame('critical', $finding['severity']);
        $this->assertFalse($finding['blocks_run']);
        $this->assertSame('pending_review', $finding['status']);
        $this->assertSame('Det anbefales at tilgangsrettigheter gjennomgås kvartalsvis.', $finding['title']);
        $this->assertSame('Regelmessig tilgangskontroll reduserer risikoen for utdaterte eller unødvendige rettigheter.', $finding['explanation']);
    }

    public function test_best_practice_finding_does_not_count_as_open_blocking_in_summary(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Beste praksis side');
        $version = $this->createVersion($page, true);
        $this->createRunPage($run, $page, $version);
        $this->createClaim($page, $version, 'Forslag om beste praksis.', 0, [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'review_reason' => 'Begrunnelse.',
        ]);

        $response = $this->actingAs($user)->getJson("/app/wiki/runs/{$run->id}/findings");

        $response->assertOk();
        $this->assertSame(0, $response->json('summary.open_blocking'));
        $this->assertSame(1, $response->json('summary.best_practice_pending'));
    }

    public function test_deterministic_dimension_mismatch_is_never_a_user_facing_finding(): void
    {
        // v0.7 binding quality-strategy rule (docs/enterprise-llm-wiki-plan.md, "Arkitekturnotat
        // — v0.7"): a claim flagged only by an internal comparison-mechanism signal (here,
        // actor_mismatch — proven unreliable in isolation by the run-39 negation_mismatch
        // false-positive incident) is never a user-facing case anymore, regardless of how
        // confidently it was "verified". It stays available as raw claim data for technical
        // diagnostics, but must not appear in the Funn panel at all
        // (EnterpriseWikiClaimFindingExplainer::isUserFacingAddition()). Supersedes the previous
        // "still a confirmed, blocking defect" expectation this test asserted before v0.7.
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Udokumentert påstand');
        $version = $this->createVersion($page, true);
        $this->createRunPage($run, $page, $version);
        $claim = $this->createClaim($page, $version, 'Udokumentert faktapåstand.', 0, [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            'generation_issue' => 'unsupported_generated_content',
            'content_block_key' => 'block-0001',
            'review_metadata' => [
                'classification_basis' => 'semantic_verification',
                'verdict' => 'not_supported',
                'deterministic_reason' => 'actor_mismatch',
            ],
        ]);

        $response = $this->actingAs($user)->getJson("/app/wiki/runs/{$run->id}/findings");

        $response->assertOk();
        $this->assertNull(collect($response->json('findings'))->firstWhere('claim_id', $claim->id));
        $this->assertSame(0, $response->json('summary.open_blocking'));
        $this->assertSame(0, $response->json('summary.total'));
    }

    public function test_internal_error_is_never_a_user_facing_finding(): void
    {
        // v0.7 binding quality-strategy rule: internal_error ("technical uncertainty" — a missing
        // or ambiguous link between the claim, its text block, and a source paragraph) is a
        // system limitation, not content the system added — it must never create a user-facing
        // case (docs/enterprise-llm-wiki-plan.md, "Arkitekturnotat — v0.7"). Supersedes the
        // previous "shown as a non-blocking technical_uncertainty finding" expectation.
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Intern feil');
        $version = $this->createVersion($page, true);
        $this->createRunPage($run, $page, $version);
        $claim = $this->createClaim($page, $version, 'Intern genereringsfeil.', 0, [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR,
        ]);

        $response = $this->actingAs($user)->getJson("/app/wiki/runs/{$run->id}/findings");

        $response->assertOk();
        $this->assertNull(collect($response->json('findings'))->firstWhere('claim_id', $claim->id));
        $this->assertSame(0, $response->json('summary.open_blocking'));
        $this->assertSame(0, $response->json('summary.total'));
    }

    // =========================================================================
    // Del 10 (12, decisions): historical status after a human decision
    // =========================================================================

    public function test_approved_best_practice_finding_shows_approved_status(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Godkjent forslag');
        $version = $this->createVersion($page, true);
        $this->createRunPage($run, $page, $version);
        $claim = $this->createClaim($page, $version, 'Forslag som godkjennes.', 0, [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'review_reason' => 'Begrunnelse.',
        ]);

        $this->actingAs($owner)->patch("/app/wiki/{$page->slug}/claims/{$claim->id}/approve")->assertRedirect();

        $response = $this->actingAs($owner)->getJson("/app/wiki/runs/{$run->id}/findings");

        $response->assertOk();
        $finding = collect($response->json('findings'))->firstWhere('claim_id', $claim->id);
        $this->assertSame('approved', $finding['status']);
        $this->assertSame($owner->name, $finding['decided_by_name']);
        $this->assertSame(1, $response->json('summary.resolved'));
        $this->assertSame(0, $response->json('summary.best_practice_pending'));
    }

    public function test_edited_and_approved_best_practice_finding_shows_approved_edited_status(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Redigert forslag');
        $originalText = 'Opprinnelig forslag.';
        $version = $this->createVersion($page, true);
        $version->update([
            'content_markdown' => "# Redigert forslag\n\n{$originalText}",
            'content_blocks_json' => [
                ['block_key' => 'block-0001', 'position' => 0, 'markdown' => '# Redigert forslag'],
                ['block_key' => 'block-0002', 'position' => 1, 'markdown' => $originalText],
            ],
        ]);
        $this->createRunPage($run, $page, $version);
        $claim = $this->createClaim($page, $version, $originalText, 0, [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'review_reason' => 'Begrunnelse.',
            'content_block_key' => 'block-0002',
        ]);

        $this->actingAs($owner)->patch("/app/wiki/{$page->slug}/claims/{$claim->id}/approve", [
            'approved_text' => 'Redigert og godkjent forslag.',
        ])->assertRedirect();

        $response = $this->actingAs($owner)->getJson("/app/wiki/runs/{$run->id}/findings");

        $response->assertOk();
        $finding = collect($response->json('findings'))->firstWhere('claim_id', $claim->id);
        $this->assertSame('approved_edited', $finding['status']);
    }

    public function test_rejected_best_practice_finding_shows_rejected_status(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Avvist forslag');
        $version = $this->createVersion($page, true);
        $this->createRunPage($run, $page, $version);
        $claim = $this->createClaim($page, $version, 'Forslag som avvises.', 0, [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'review_reason' => 'Begrunnelse.',
        ]);

        $this->actingAs($owner)->patch("/app/wiki/{$page->slug}/claims/{$claim->id}/reject")->assertRedirect();

        $response = $this->actingAs($owner)->getJson("/app/wiki/runs/{$run->id}/findings");

        $response->assertOk();
        $finding = collect($response->json('findings'))->firstWhere('claim_id', $claim->id);
        $this->assertSame('rejected', $finding['status']);
    }

    // =========================================================================
    // Del 7 + Del 10 (10-11): role-scoped action
    // =========================================================================

    public function test_open_and_review_action_shown_when_document_owner_can_handle(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $doc = $this->createDocument($customer);
        $doc->update(['owner_user_id' => $owner->id]);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Dokumenteier kan behandle');
        $version = $this->createVersion($page, true);
        $this->createRunPage($run, $page, $version);
        $claim = $this->createClaim($page, $version, 'Forslag som dokumenteier kan behandle.', 0, [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'review_reason' => 'Begrunnelse.',
        ]);
        $this->createDocumentSourceReference($this->createClaim($page, $version, 'Kildepåstand.', 1, [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
        ]), $doc);

        $response = $this->actingAs($owner)->getJson("/app/wiki/runs/{$run->id}/findings");

        $response->assertOk();
        $finding = collect($response->json('findings'))->firstWhere('claim_id', $claim->id);
        $this->assertTrue($finding['can_handle']);
        $this->assertSame('open_and_review', $finding['action']);
    }

    public function test_open_and_review_action_not_shown_for_unrelated_contributor(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $unrelated = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $doc = $this->createDocument($customer);
        $doc->update(['owner_user_id' => $owner->id]);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Uvedkommende kan ikke behandle');
        $version = $this->createVersion($page, true);
        $this->createRunPage($run, $page, $version);
        $claim = $this->createClaim($page, $version, 'Forslag utilgjengelig for uvedkommende.', 0, [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'review_reason' => 'Begrunnelse.',
        ]);
        $this->createDocumentSourceReference($this->createClaim($page, $version, 'Kildepåstand.', 1, [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
        ]), $doc);

        $response = $this->actingAs($unrelated)->getJson("/app/wiki/runs/{$run->id}/findings");

        $response->assertOk();
        $finding = collect($response->json('findings'))->firstWhere('claim_id', $claim->id);
        $this->assertFalse($finding['can_handle']);
        $this->assertNotSame('open_and_review', $finding['action']);
    }

    // =========================================================================
    // Del 3/4/6/7: direct navigation via WikiController::show()'s review_reference
    // =========================================================================

    public function test_review_reference_resolves_correct_claim_version_and_block(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Direkte navigasjon');
        $version = $this->createVersion($page, true);
        $version->update([
            'content_blocks_json' => [
                ['block_key' => 'block-0001', 'position' => 0, 'markdown' => '# Direkte navigasjon'],
                ['block_key' => 'block-0002', 'position' => 1, 'markdown' => 'Forslagstekst.'],
            ],
        ]);
        $claim = $this->createClaim($page, $version, 'Forslagstekst.', 0, [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'content_block_key' => 'block-0002',
        ]);

        $response = $this->actingAs($user)->get("/app/wiki/{$page->slug}?claim_id={$claim->id}");

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($claim): bool {
            $ref = data_get($inertia, 'props.review_reference');

            return $ref['status'] === 'ready'
                && $ref['claim_id'] === $claim->id
                && $ref['block_key'] === 'block-0002';
        });
    }

    public function test_review_reference_distinguishes_two_blocks_with_identical_text(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Duplisert tekst');
        $version = $this->createVersion($page, true);
        $sameText = 'Denne teksten finnes i to blokker.';
        $version->update([
            'content_blocks_json' => [
                ['block_key' => 'block-0001', 'position' => 0, 'markdown' => $sameText],
                ['block_key' => 'block-0002', 'position' => 1, 'markdown' => $sameText],
            ],
        ]);
        $claimA = $this->createClaim($page, $version, $sameText, 0, [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'content_block_key' => 'block-0001',
        ]);
        $claimB = $this->createClaim($page, $version, $sameText, 1, [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'content_block_key' => 'block-0002',
        ]);

        $responseA = $this->actingAs($user)->get("/app/wiki/{$page->slug}?claim_id={$claimA->id}");
        $responseB = $this->actingAs($user)->get("/app/wiki/{$page->slug}?claim_id={$claimB->id}");

        $responseA->assertViewHas('page', fn (array $i): bool => data_get($i, 'props.review_reference.block_key') === 'block-0001');
        $responseB->assertViewHas('page', fn (array $i): bool => data_get($i, 'props.review_reference.block_key') === 'block-0002');
    }

    public function test_review_reference_manipulated_claim_id_is_not_found(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Manipulert claim');
        $this->createVersion($page, true);

        $response = $this->actingAs($user)->get("/app/wiki/{$page->slug}?claim_id=999999");

        $response->assertOk();
        $response->assertViewHas('page', fn (array $i): bool => data_get($i, 'props.review_reference.status') === 'not_found');
    }

    public function test_review_reference_claim_from_another_page_is_not_found(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $pageA = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Side A');
        $pageB = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Side B');
        $versionB = $this->createVersion($pageB, true);
        $this->createVersion($pageA, true);
        $foreignClaim = $this->createClaim($pageB, $versionB, 'Tilhører side B.', 0, [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
        ]);

        $response = $this->actingAs($user)->get("/app/wiki/{$pageA->slug}?claim_id={$foreignClaim->id}");

        $response->assertOk();
        $response->assertViewHas('page', fn (array $i): bool => data_get($i, 'props.review_reference.status') === 'not_found');
    }

    public function test_review_reference_superseded_version_reports_version_number(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Utdatert forslag');
        $oldVersion = $this->createVersion($page, false);
        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 2,
            'is_current' => true,
            'content_markdown' => '# v2',
        ]);
        $claim = $this->createClaim($page, $oldVersion, 'Utdatert forslagstekst.', 0, [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
        ]);

        $response = $this->actingAs($user)->get("/app/wiki/{$page->slug}?claim_id={$claim->id}");

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($claim): bool {
            $ref = data_get($inertia, 'props.review_reference');

            return $ref['status'] === 'superseded' && $ref['claim_id'] === $claim->id && $ref['version_number'] === 1;
        });
    }

    public function test_review_reference_missing_block_shows_technical_diagnostics_only_for_system_owner(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $contributor = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Manglende blokk');
        $version = $this->createVersion($page, true);
        $version->update(['content_blocks_json' => [
            ['block_key' => 'block-0001', 'position' => 0, 'markdown' => '# Manglende blokk'],
        ]]);
        $claim = $this->createClaim($page, $version, 'Blokken finnes ikke lenger.', 0, [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'content_block_key' => 'block-gone',
        ]);

        $ownerResponse = $this->actingAs($owner)->get("/app/wiki/{$page->slug}?claim_id={$claim->id}");
        $ownerResponse->assertViewHas('page', function (array $inertia) use ($claim): bool {
            $ref = data_get($inertia, 'props.review_reference');

            return $ref['status'] === 'block_missing' && $ref['claim_id'] === $claim->id && $ref['technical_block_key'] === 'block-gone';
        });

        $contributorResponse = $this->actingAs($contributor)->get("/app/wiki/{$page->slug}?claim_id={$claim->id}");
        $contributorResponse->assertViewHas('page', function (array $inertia): bool {
            $ref = data_get($inertia, 'props.review_reference');

            return $ref['status'] === 'block_missing' && ! array_key_exists('technical_block_key', $ref);
        });
    }

    public function test_content_blocks_are_exposed_with_stable_anchors_for_scroll_target(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Blokkanker');
        $version = $this->createVersion($page, true);
        $version->update(['content_blocks_json' => [
            ['block_key' => 'block-0001', 'position' => 0, 'markdown' => 'Blokktekst.'],
        ]]);

        $response = $this->actingAs($user)->get("/app/wiki/{$page->slug}");

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia): bool {
            $blocks = data_get($inertia, 'props.current_version.content_blocks_json');

            return is_array($blocks) && ($blocks[0]['block_key'] ?? null) === 'block-0001';
        });
    }

    // =========================================================================
    // Del 10 (36): Document Owner can reach the concrete review from Kjøringer
    // =========================================================================

    public function test_document_owner_can_reach_concrete_review_from_findings_url(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $doc = $this->createDocument($customer);
        $doc->update(['owner_user_id' => $owner->id]);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Ende til ende');
        $version = $this->createVersion($page, true);
        $version->update(['content_blocks_json' => [
            ['block_key' => 'block-0001', 'position' => 0, 'markdown' => 'Forslagstekst for ende-til-ende-test.'],
        ]]);
        $this->createRunPage($run, $page, $version);
        $claim = $this->createClaim($page, $version, 'Forslagstekst for ende-til-ende-test.', 0, [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'review_reason' => 'Begrunnelse.',
            'content_block_key' => 'block-0001',
        ]);
        $this->createDocumentSourceReference($this->createClaim($page, $version, 'Kildepåstand.', 1, [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
        ]), $doc);

        $findingsResponse = $this->actingAs($owner)->getJson("/app/wiki/runs/{$run->id}/findings");
        $findingsResponse->assertOk();
        $finding = collect($findingsResponse->json('findings'))->firstWhere('claim_id', $claim->id);
        $this->assertNotNull($finding['url']);
        $this->assertStringContainsString("claim_id={$claim->id}", $finding['url']);

        $showResponse = $this->actingAs($owner)->get($finding['url']);
        $showResponse->assertOk();
        $showResponse->assertViewHas('page', function (array $inertia) use ($claim): bool {
            $ref = data_get($inertia, 'props.review_reference');
            $matchingClaim = collect(data_get($inertia, 'props.claims', []))->firstWhere('id', $claim->id);

            return $ref['status'] === 'ready' && $ref['claim_id'] === $claim->id && ($matchingClaim['can_handle'] ?? false) === true;
        });
    }

    // =========================================================================
    // Del 2: QA status is not disturbed by a legitimate pending suggestion
    // =========================================================================

    public function test_findings_explanation_is_not_flagged_inconsistent_for_passed_run_with_only_pending_best_practice(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $doc = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $doc, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        $run->update(['qa_status' => EnterpriseWikiIngestRun::QA_STATUS_PASSED]);
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Bestått med forslag');
        $version = $this->createVersion($page, true);
        $this->createRunPage($run, $page, $version);
        $this->createClaim($page, $version, 'Forslag som ikke påvirker QA.', 0, [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'review_reason' => 'Begrunnelse.',
        ]);

        $response = $this->actingAs($user)->getJson("/app/wiki/runs/{$run->id}/findings");

        $response->assertOk();
        $this->assertSame(0, $response->json('summary.open_blocking'));
        $this->assertSame(
            __('procynia.wiki.runs_findings_explanation_passed_no_blocking', ['count' => 1]),
            $response->json('summary.explanation'),
        );
    }

    // =========================================================================
    // Del "Udokumentert faktapåstand blir ikke automatisk beste praksis"
    // =========================================================================

    public function test_claim_from_non_best_practice_block_that_fails_verification_stays_unsupported_not_best_practice(): void
    {
        $customer = $this->createCustomer();
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_APPROVED, 'Ikke automatisk beste praksis');
        $version = $this->createVersion($page, true);

        // A claim extracted as source_based (no best_practice block tag, no classification_basis
        // metadata) that later fails AI verification must classify conservatively — never assumed
        // best_practice just because it reads like a recommendation.
        $claim = $this->createClaim($page, $version, 'Udokumentert påstand uten beste-praksis-opprinnelse.', 0, [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
        ]);

        $service = app(EnterpriseWikiVerifyPageClaimsService::class);
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('isPositiveBestPracticeSuggestion');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($service, $claim->fresh()));
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createCustomer(string $name = 'Beste Praksis AS'): Customer
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
            'name' => 'Best Practice Tester',
            'email' => Str::lower(Str::random(8)).'@best-practice-test.invalid',
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
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'title' => $title,
            'page_type' => $pageType,
            'status' => $status,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }

    private function createVersion(EnterpriseWikiPage $page, bool $isCurrentTrue = false): EnterpriseWikiPageVersion
    {
        return EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => $isCurrentTrue && $page->versions()->exists() ? $page->versions()->max('version_number') + 1 : 1,
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
            'page_excerpt' => $text,
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

    private function createDocumentSourceReference(EnterpriseWikiClaim $claim, EnterpriseWikiDocument $document): void
    {
        EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $claim->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_label' => $document->original_filename,
            'source_hash' => hash('sha256', 'enterprise_wiki_document:'.$document->id),
            'excerpt' => 'Kildeutdrag.',
            'page_reference' => null,
        ]);
    }

    private function createRunPage(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $page, EnterpriseWikiPageVersion $version): EnterpriseWikiIngestRunPage
    {
        return EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            'generated_page_version_id' => $version->id,
            'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
        ]);
    }
}
