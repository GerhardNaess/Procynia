<?php

namespace Tests\Unit\Services\Ai;

use App\Models\EnterpriseWikiClaim;
use App\Services\Ai\Wiki\RequirementWikiCatalogBuilder;
use App\Services\Ai\Wiki\RequirementWikiPageRanker;
use App\Services\Ai\Wiki\RequirementWikiTermNormalizer;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

class RequirementWikiPageRankerTest extends TestCase
{
    use CreatesEnterpriseWikiFixtures;
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    public function test_a_title_hit_outranks_a_page_that_only_matches_in_body_content(): void
    {
        $customer = $this->createWikiCustomer();
        $titleMatch = $this->createWikiPageWithVersion($customer, 'Incident Management', 'Generell tekst uten mange gjentakelser.');
        $bodyOnlyMatch = $this->createWikiPageWithVersion($customer, 'Urelatert side', 'Denne siden nevner incident bare en gang i forbifarten.');

        $catalog = app(RequirementWikiCatalogBuilder::class)->build($customer->id);
        $tokens = RequirementWikiTermNormalizer::tokenize('Beskriv rutinen for Incident Management.');
        $ranked = app(RequirementWikiPageRanker::class)->rank($catalog, $tokens, $customer->id);

        $this->assertSame($titleMatch->id, $ranked[0]['page_id']);
    }

    public function test_heading_hits_contribute_to_the_score(): void
    {
        $customer = $this->createWikiCustomer();
        $withHeading = $this->createWikiPageWithVersion($customer, 'Driftsprosesser', "# Driftsprosesser\n\nIntroduksjon.\n\n## Rotårsaksanalyse\n\nDetaljer om rotårsaksanalyse.");
        $withoutHeading = $this->createWikiPageWithVersion($customer, 'Andre prosesser', "# Andre prosesser\n\nIntroduksjon uten relevant overskrift.");

        $catalog = app(RequirementWikiCatalogBuilder::class)->build($customer->id);
        $tokens = RequirementWikiTermNormalizer::tokenize('Beskriv rotårsaksanalyse.');
        $ranked = app(RequirementWikiPageRanker::class)->rank($catalog, $tokens, $customer->id);
        $byId = collect($ranked)->keyBy('page_id');

        $this->assertGreaterThan($byId[$withoutHeading->id]['score'] ?? 0, $byId[$withHeading->id]['score']);
    }

    public function test_content_overlap_count_and_ratio_contribute_to_the_score(): void
    {
        $customer = $this->createWikiCustomer();
        $focused = $this->createWikiPageWithVersion($customer, 'Fokusert side', 'Endringshåndtering og endringsstyre er kjernen i denne siden om endring.');
        $diffuse = $this->createWikiPageWithVersion($customer, 'Diffus side', 'Endring nevnes kort her, men siden handler mest om andre uavhengige tema, prosjekter, historie og organisasjon.');

        $catalog = app(RequirementWikiCatalogBuilder::class)->build($customer->id);
        $tokens = RequirementWikiTermNormalizer::tokenize('Beskriv endringshåndtering og endringsstyre.');
        $ranked = app(RequirementWikiPageRanker::class)->rank($catalog, $tokens, $customer->id);

        $this->assertSame($focused->id, $ranked[0]['page_id']);
    }

    public function test_claims_improve_recall_without_being_the_primary_signal(): void
    {
        $customer = $this->createWikiCustomer();
        $titlePage = $this->createWikiPageWithVersion($customer, 'Kapasitetsstyring', 'Beskrivelse av kapasitetsstyring.');
        $claimOnlyPage = $this->createWikiPageWithVersion($customer, 'Urelatert tema', 'Denne siden handler om noe helt annet.');
        $this->createWikiClaim($claimOnlyPage, 'Kapasitetsstyring rapporteres månedlig til kunden.');

        $catalog = app(RequirementWikiCatalogBuilder::class)->build($customer->id);
        $tokens = RequirementWikiTermNormalizer::tokenize('Beskriv kapasitetsstyring.');
        $ranked = app(RequirementWikiPageRanker::class)->rank($catalog, $tokens, $customer->id);
        $byId = collect($ranked)->keyBy('page_id');

        // The claim-only page must still surface (recall) …
        $this->assertArrayHasKey($claimOnlyPage->id, $byId);
        // … but never outrank a genuine title/content match.
        $this->assertSame($titlePage->id, $ranked[0]['page_id']);
    }

    public function test_ranking_is_deterministic_across_repeated_calls(): void
    {
        $customer = $this->createWikiCustomer();
        $this->createWikiPageWithVersion($customer, 'Side A', 'Innhold om endring og styring.');
        $this->createWikiPageWithVersion($customer, 'Side B', 'Innhold om endring og prosess.');
        $this->createWikiPageWithVersion($customer, 'Side C', 'Innhold om endring og kontroll.');

        $catalog = app(RequirementWikiCatalogBuilder::class)->build($customer->id);
        $tokens = RequirementWikiTermNormalizer::tokenize('Beskriv endringshåndtering.');
        $ranker = app(RequirementWikiPageRanker::class);

        $first = array_column($ranker->rank($catalog, $tokens, $customer->id), 'page_id');
        $second = array_column($ranker->rank($catalog, $tokens, $customer->id), 'page_id');

        $this->assertSame($first, $second);
    }

    public function test_database_row_order_does_not_affect_the_ranked_result(): void
    {
        $customer = $this->createWikiCustomer();
        $this->createWikiPageWithVersion($customer, 'Side A', 'Innhold om endring og styring av endring.');
        $this->createWikiPageWithVersion($customer, 'Side B', 'Innhold om endring og styring av endring.');

        $catalog = app(RequirementWikiCatalogBuilder::class)->build($customer->id);
        $shuffledCatalog = array_reverse($catalog);
        $tokens = RequirementWikiTermNormalizer::tokenize('Beskriv endringshåndtering.');
        $ranker = app(RequirementWikiPageRanker::class);

        $fromOriginalOrder = array_column($ranker->rank($catalog, $tokens, $customer->id), 'page_id');
        $fromShuffledOrder = array_column($ranker->rank($shuffledCatalog, $tokens, $customer->id), 'page_id');

        $this->assertSame($fromOriginalOrder, $fromShuffledOrder);
    }

    public function test_page_id_is_the_stable_tie_break_for_equal_scores(): void
    {
        $customer = $this->createWikiCustomer();
        // Identical titles/content -> identical scores; only page_id can break the tie.
        $pageA = $this->createWikiPageWithVersion($customer, 'Identisk side', 'Nøyaktig samme innhold om endring.');
        $pageB = $this->createWikiPageWithVersion($customer, 'Identisk side', 'Nøyaktig samme innhold om endring.');

        $catalog = app(RequirementWikiCatalogBuilder::class)->build($customer->id);
        $tokens = RequirementWikiTermNormalizer::tokenize('Beskriv endring.');
        $ranked = app(RequirementWikiPageRanker::class)->rank($catalog, $tokens, $customer->id);

        $expectedOrder = $pageA->id < $pageB->id ? [$pageA->id, $pageB->id] : [$pageB->id, $pageA->id];
        $this->assertSame($expectedOrder, array_column($ranked, 'page_id'));
    }

    public function test_the_candidate_limit_is_enforced(): void
    {
        $customer = $this->createWikiCustomer();

        for ($i = 0; $i < RequirementWikiPageRanker::MAX_CANDIDATES + 5; $i++) {
            $this->createWikiPageWithVersion($customer, "Endringsside {$i}", 'Innhold om endring og endringsstyre for denne siden.');
        }

        $catalog = app(RequirementWikiCatalogBuilder::class)->build($customer->id);
        $tokens = RequirementWikiTermNormalizer::tokenize('Beskriv endring og endringsstyre.');
        $ranked = app(RequirementWikiPageRanker::class)->rank($catalog, $tokens, $customer->id);

        $this->assertCount(RequirementWikiPageRanker::MAX_CANDIDATES, $ranked);
    }

    /**
     * v0.9 provenance-gap closure — acceptance (7): a source_based claim hit is weighted more
     * heavily than a best_practice claim hit, so a page documented in the customer's own sources
     * can outrank a page whose only matching claim is an undocumented best-practice suggestion —
     * useful for customer-fact questions, per the binding provenance rule.
     */
    public function test_a_source_based_claim_hit_outranks_a_best_practice_claim_hit_at_equal_recall(): void
    {
        $customer = $this->createWikiCustomer();
        $sourceBackedPage = $this->createWikiPageWithVersion($customer, 'Urelatert tittel en', 'Denne siden handler om noe helt annet.');
        $bestPracticeOnlyPage = $this->createWikiPageWithVersion($customer, 'Urelatert tittel to', 'Denne siden handler også om noe helt annet.');
        $this->createWikiClaim($sourceBackedPage, 'Kapasitetsstyring rapporteres månedlig til kunden.', [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
        ]);
        $this->createWikiClaim($bestPracticeOnlyPage, 'Kapasitetsstyring bør rapporteres månedlig til kunden.', [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
        ]);

        $catalog = app(RequirementWikiCatalogBuilder::class)->build($customer->id);
        $tokens = RequirementWikiTermNormalizer::tokenize('Beskriv kapasitetsstyring.');
        $ranked = app(RequirementWikiPageRanker::class)->rank($catalog, $tokens, $customer->id);
        $byId = collect($ranked)->keyBy('page_id');

        $this->assertGreaterThan(
            $byId[$bestPracticeOnlyPage->id]['score'],
            $byId[$sourceBackedPage->id]['score'],
        );
    }

    /**
     * v0.9 provenance-gap closure — acceptance (8): the lower best_practice claim-hit weight is a
     * tie-breaker only — the primary title/content overlap signals are unchanged, so a page whose
     * actual content is the stronger match for a recommendation-style query still ranks first even
     * though its only claim is best_practice-marked.
     */
    public function test_best_practice_content_can_still_rank_first_when_it_is_the_stronger_match(): void
    {
        $customer = $this->createWikiCustomer();
        $bestPracticePage = $this->createWikiPageWithVersion($customer, 'Kapasitetsstyring', 'Anbefalt fremgangsmåte for kapasitetsstyring og kapasitetsplanlegging.');
        $this->createWikiClaim($bestPracticePage, 'Kapasitetsstyring bør gjennomgås kvartalsvis som beste praksis.', [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
        ]);
        $weaklyRelatedPage = $this->createWikiPageWithVersion($customer, 'Urelatert tema', 'Denne siden nevner kapasitetsstyring bare i forbifarten.');

        $catalog = app(RequirementWikiCatalogBuilder::class)->build($customer->id);
        $tokens = RequirementWikiTermNormalizer::tokenize('Anbefal beste praksis for kapasitetsstyring.');
        $ranked = app(RequirementWikiPageRanker::class)->rank($catalog, $tokens, $customer->id);

        $this->assertSame($bestPracticePage->id, $ranked[0]['page_id']);
    }

    public function test_excluded_page_ids_never_appear_in_the_result(): void
    {
        $customer = $this->createWikiCustomer();
        $keep = $this->createWikiPageWithVersion($customer, 'Endringsside', 'Innhold om endring.');
        $exclude = $this->createWikiPageWithVersion($customer, 'Endringsside to', 'Innhold om endring.');

        $catalog = app(RequirementWikiCatalogBuilder::class)->build($customer->id);
        $tokens = RequirementWikiTermNormalizer::tokenize('Beskriv endring.');
        $ranked = app(RequirementWikiPageRanker::class)->rank($catalog, $tokens, $customer->id, [$exclude->id]);

        $this->assertSame([$keep->id], array_column($ranked, 'page_id'));
    }
}
