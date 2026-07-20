<?php

namespace Tests\Unit\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiClaim;
use App\Services\EnterpriseWiki\EnterpriseWikiClaimFindingExplainer;
use Tests\TestCase;

/**
 * Proves every product rule EnterpriseWikiClaimFindingExplainer exists to satisfy: a concrete,
 * per-case explanation instead of one generic default message; a clear split between genuine
 * content problems and technical (block/source linking) uncertainty; different underlying causes
 * never producing identical-looking findings; and the system's suggested blocking state being
 * exactly what the product rules describe (technical uncertainty never suggests blocking,
 * unsupported content always does, regardless of how much diagnostic detail is available).
 *
 * Uses the Laravel TestCase (not plain PHPUnit) because the explainer resolves translation
 * strings via __() — no database access is needed, claims are constructed in memory only.
 */
class EnterpriseWikiClaimFindingExplainerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Assertions below use the Norwegian copy directly — force it regardless of the test
        // environment's configured default locale.
        app()->setLocale('no');
    }

    private function explainer(): EnterpriseWikiClaimFindingExplainer
    {
        return app(EnterpriseWikiClaimFindingExplainer::class);
    }

    private function unsupportedClaim(array $overrides = []): EnterpriseWikiClaim
    {
        return new EnterpriseWikiClaim(array_merge([
            'claim_text' => 'Leverandøren skal svare innen 30 minutter.',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            'generation_issue' => 'unsupported_generated_content',
            'review_metadata' => null,
            'review_reason' => null,
        ], $overrides));
    }

    private function internalErrorClaim(?string $generationIssue): EnterpriseWikiClaim
    {
        return new EnterpriseWikiClaim([
            'claim_text' => 'En påstand.',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR,
            'generation_issue' => $generationIssue,
        ]);
    }

    // =========================================================================
    // Concrete explanations instead of one generic default message
    // =========================================================================

    public function test_actor_mismatch_gets_a_concrete_explanation(): void
    {
        $claim = $this->unsupportedClaim([
            'review_metadata' => ['deterministic_reason' => 'actor_mismatch'],
        ]);

        $finding = $this->explainer()->explain($claim);

        $this->assertSame('Feil aktør', $finding['title']);
        $this->assertStringContainsString('feil aktør', $finding['explanation']);
        $this->assertSame(EnterpriseWikiClaimFindingExplainer::CATEGORY_UNDOCUMENTED_OR_INCORRECT_CLAIM, $finding['category']);
    }

    public function test_modality_mismatch_gets_a_concrete_explanation(): void
    {
        $claim = $this->unsupportedClaim([
            'review_metadata' => ['deterministic_reason' => 'modality_mismatch'],
        ]);

        $finding = $this->explainer()->explain($claim);

        $this->assertSame('Sterkere enn kilden', $finding['title']);
        $this->assertStringContainsString('kan', $finding['explanation']);
        $this->assertStringContainsString('skal', $finding['explanation']);
    }

    public function test_missing_source_link_gets_a_concrete_explanation(): void
    {
        $claim = $this->internalErrorClaim('genuine_content_mismatch');

        $finding = $this->explainer()->explain($claim);

        $this->assertSame('Ingen sikker kildekobling', $finding['title']);
        $this->assertStringContainsString('kildeavsnitt', $finding['explanation']);
    }

    public function test_ambiguous_block_link_gets_a_concrete_explanation(): void
    {
        $claim = $this->internalErrorClaim('claim_missing_unique_content_block_anchor');

        $finding = $this->explainer()->explain($claim);

        $this->assertSame('Usikker blokk-kobling', $finding['title']);
        $this->assertStringContainsString('to mulige tekstblokker', $finding['explanation']);
    }

    public function test_ai_own_verification_reason_is_shown_verbatim_when_no_deterministic_reason_exists(): void
    {
        $claim = $this->unsupportedClaim([
            'review_metadata' => [
                'reason' => 'The source discusses request handling, not incident response times.',
                'checks' => ['actor' => 'match', 'modality' => 'match', 'negation' => 'match'],
            ],
        ]);

        $finding = $this->explainer()->explain($claim);

        $this->assertSame(
            'The source discusses request handling, not incident response times.',
            $finding['explanation'],
        );
    }

    public function test_no_stored_detail_at_all_still_gives_an_honest_specific_message(): void
    {
        $claim = $this->unsupportedClaim(); // no review_metadata, no review_reason

        $finding = $this->explainer()->explain($claim);

        $this->assertNotSame('', trim($finding['explanation']));
        $this->assertSame('Ingen kildedekning funnet', $finding['title']);
    }

    // =========================================================================
    // Technical uncertainty vs. content problem — never presented as "the claim is wrong"
    // =========================================================================

    public function test_internal_error_is_always_classified_as_technical_uncertainty(): void
    {
        foreach (['wrong_version', 'missing_block', 'claim_missing_unique_content_block_anchor', 'genuine_content_mismatch', null, 'something_unrecognized'] as $issue) {
            $claim = $this->internalErrorClaim($issue);
            $finding = $this->explainer()->explain($claim);

            $this->assertSame(
                EnterpriseWikiClaimFindingExplainer::CATEGORY_TECHNICAL_UNCERTAINTY,
                $finding['category'],
                "generation_issue [{$issue}] should classify as technical_uncertainty",
            );
        }
    }

    public function test_technical_uncertainty_does_not_suggest_blocking_by_default(): void
    {
        $claim = $this->internalErrorClaim('genuine_content_mismatch');

        $finding = $this->explainer()->explain($claim);

        $this->assertFalse($finding['suggested_blocking']);
        $this->assertFalse($this->explainer()->suggestedBlocking($claim));
    }

    public function test_unsupported_content_suggests_blocking_by_default(): void
    {
        // Real, existing factual claims that are simply unsupported must still suggest blocking —
        // regardless of how much diagnostic detail is available for the specific reason.
        $withDetail = $this->unsupportedClaim(['review_metadata' => ['deterministic_reason' => 'negation_mismatch']]);
        $withoutDetail = $this->unsupportedClaim();

        $this->assertTrue($this->explainer()->suggestedBlocking($withDetail));
        $this->assertTrue($this->explainer()->suggestedBlocking($withoutDetail));
    }

    public function test_ai_self_reported_mismatch_without_deterministic_confirmation_is_only_possible_deviation(): void
    {
        // A self-reported AI check mismatch (not deterministically confirmed) is known to be
        // unreliable — it must be hedged as "possible", never presented as a confirmed error.
        $claim = $this->unsupportedClaim([
            'review_metadata' => ['checks' => ['actor' => 'mismatch']],
        ]);

        $finding = $this->explainer()->explain($claim);

        $this->assertSame(EnterpriseWikiClaimFindingExplainer::CATEGORY_POSSIBLE_CONTENT_DEVIATION, $finding['category']);
        $this->assertStringContainsString('Ikke bekreftet', $finding['explanation']);
    }

    public function test_contradicted_verdict_is_a_confirmed_content_problem(): void
    {
        $claim = $this->unsupportedClaim([
            'generation_issue' => 'claim_contradicted_by_source',
            'review_metadata' => ['unsupported_parts' => 'The source states the opposite.'],
        ]);

        $finding = $this->explainer()->explain($claim);

        $this->assertSame(EnterpriseWikiClaimFindingExplainer::CATEGORY_UNDOCUMENTED_OR_INCORRECT_CLAIM, $finding['category']);
        $this->assertSame('The source states the opposite.', $finding['explanation']);
    }

    public function test_partially_supported_verdict_is_a_possible_deviation(): void
    {
        $claim = $this->unsupportedClaim([
            'generation_issue' => 'claim_partially_supported',
            'review_metadata' => ['unsupported_parts' => 'Only the first half is documented.'],
        ]);

        $finding = $this->explainer()->explain($claim);

        $this->assertSame(EnterpriseWikiClaimFindingExplainer::CATEGORY_POSSIBLE_CONTENT_DEVIATION, $finding['category']);
    }

    // =========================================================================
    // Different causes never look identical
    // =========================================================================

    public function test_different_causes_produce_different_titles_and_explanations(): void
    {
        $actor = $this->explainer()->explain($this->unsupportedClaim(['review_metadata' => ['deterministic_reason' => 'actor_mismatch']]));
        $modality = $this->explainer()->explain($this->unsupportedClaim(['review_metadata' => ['deterministic_reason' => 'modality_mismatch']]));
        $scope = $this->explainer()->explain($this->unsupportedClaim(['review_metadata' => ['deterministic_reason' => 'scope_mismatch']]));
        $noSource = $this->explainer()->explain($this->internalErrorClaim('genuine_content_mismatch'));
        $ambiguousBlock = $this->explainer()->explain($this->internalErrorClaim('claim_missing_unique_content_block_anchor'));

        $titles = [$actor['title'], $modality['title'], $scope['title'], $noSource['title'], $ambiguousBlock['title']];

        $this->assertSame($titles, array_unique($titles), 'Every distinct cause must render a distinct title.');

        $explanations = [$actor['explanation'], $modality['explanation'], $scope['explanation'], $noSource['explanation'], $ambiguousBlock['explanation']];
        $this->assertSame($explanations, array_unique($explanations), 'Every distinct cause must render a distinct explanation.');
    }
}
