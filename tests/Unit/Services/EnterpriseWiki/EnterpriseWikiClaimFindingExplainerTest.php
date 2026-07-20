<?php

namespace Tests\Unit\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiCanonicalFact;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiSourceReference;
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

    /**
     * An unsupported_generated_content claim that actually reached a semantic verdict — a
     * content_block_key, a linked source reference, and review_metadata carrying a real 'verdict'
     * key (every real write via EnterpriseWikiVerifyPageClaimsService::applyVerdictOutcome() sets
     * this alongside 'reason'/'checks', so a realistic fixture always has one). Contrast with the
     * "unchecked" claims built directly via unsupportedClaim() with review_metadata: null, which
     * represent a claim that never reached a verdict at all — see the
     * test_missing_block_link_.../test_ambiguous_source_candidate_.../
     * test_missing_confident_source_candidate_... tests below.
     */
    private function verifiedUnsupportedClaim(array $reviewMetadata, string $contentBlockKey = 'block-0001'): EnterpriseWikiClaim
    {
        $claim = $this->unsupportedClaim([
            'content_block_key' => $contentBlockKey,
            'review_metadata' => array_merge(['verdict' => 'not_supported'], $reviewMetadata),
        ]);
        $claim->setRelation('sourceReferences', collect([new EnterpriseWikiSourceReference(['source_element_key' => 'paragraph-1'])]));

        return $claim;
    }

    // =========================================================================
    // Concrete explanations instead of one generic default message
    // =========================================================================

    public function test_actor_mismatch_gets_a_concrete_explanation(): void
    {
        $claim = $this->verifiedUnsupportedClaim(['deterministic_reason' => 'actor_mismatch']);

        $finding = $this->explainer()->explain($claim);

        $this->assertSame('Feil aktør', $finding['title']);
        $this->assertStringContainsString('feil aktør', $finding['explanation']);
        $this->assertSame(EnterpriseWikiClaimFindingExplainer::CATEGORY_UNDOCUMENTED_OR_INCORRECT_CLAIM, $finding['category']);
    }

    public function test_modality_mismatch_gets_a_concrete_explanation(): void
    {
        $claim = $this->verifiedUnsupportedClaim(['deterministic_reason' => 'modality_mismatch']);

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
        $claim = $this->verifiedUnsupportedClaim([
            'reason' => 'The source discusses request handling, not incident response times.',
            'checks' => ['actor' => 'match', 'modality' => 'match', 'negation' => 'match'],
        ]);

        $finding = $this->explainer()->explain($claim);

        $this->assertSame(
            'The source discusses request handling, not incident response times.',
            $finding['explanation'],
        );
    }

    public function test_verified_verdict_with_no_stored_detail_still_gives_an_honest_specific_message(): void
    {
        // A verdict was actually reached (content_block_key + source reference + a real
        // review_metadata['verdict']), but no reason/checks detail was stored for it. Contrast
        // with test_missing_block_link_is_technical_uncertainty_...() below, which covers a claim
        // that never reached a verdict at all.
        $claim = $this->verifiedUnsupportedClaim([]);

        $finding = $this->explainer()->explain($claim);

        $this->assertNotSame('', trim($finding['explanation']));
        $this->assertSame('Ikke bekreftet innholdsavvik', $finding['title']);
        $this->assertSame(EnterpriseWikiClaimFindingExplainer::CATEGORY_POSSIBLE_CONTENT_DEVIATION, $finding['category']);
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

    public function test_unsupported_content_suggests_blocking_by_default_when_verified(): void
    {
        // Real, existing factual claims that were actually checked against a source and found
        // unsupported must still suggest blocking — regardless of how much diagnostic detail is
        // available for the specific reason. A claim that was NEVER checked (no block link, no
        // source candidate) is technical uncertainty instead — see
        // test_missing_block_link_is_technical_uncertainty_and_does_not_suggest_blocking().
        $withDetail = $this->verifiedUnsupportedClaim(['deterministic_reason' => 'negation_mismatch']);
        $withoutDetail = $this->verifiedUnsupportedClaim([]);

        $this->assertTrue($this->explainer()->suggestedBlocking($withDetail));
        $this->assertTrue($this->explainer()->suggestedBlocking($withoutDetail));
    }

    public function test_ai_self_reported_mismatch_without_deterministic_confirmation_is_only_possible_deviation(): void
    {
        // A self-reported AI check mismatch (not deterministically confirmed) is known to be
        // unreliable — it must be hedged as "possible", never presented as a confirmed error.
        $claim = $this->verifiedUnsupportedClaim(['checks' => ['actor' => 'mismatch']]);

        $finding = $this->explainer()->explain($claim);

        $this->assertSame(EnterpriseWikiClaimFindingExplainer::CATEGORY_POSSIBLE_CONTENT_DEVIATION, $finding['category']);
        $this->assertStringContainsString('Ikke bekreftet', $finding['explanation']);
    }

    public function test_contradicted_verdict_is_a_confirmed_content_problem(): void
    {
        $claim = $this->verifiedUnsupportedClaim([
            'verdict' => 'contradicted',
            'unsupported_parts' => 'The source states the opposite.',
        ]);
        $claim->generation_issue = 'claim_contradicted_by_source';

        $finding = $this->explainer()->explain($claim);

        $this->assertSame(EnterpriseWikiClaimFindingExplainer::CATEGORY_UNDOCUMENTED_OR_INCORRECT_CLAIM, $finding['category']);
        $this->assertSame('The source states the opposite.', $finding['explanation']);
    }

    public function test_partially_supported_verdict_is_a_possible_deviation(): void
    {
        $claim = $this->verifiedUnsupportedClaim([
            'verdict' => 'partially_supported',
            'unsupported_parts' => 'Only the first half is documented.',
        ]);
        $claim->generation_issue = 'claim_partially_supported';

        $finding = $this->explainer()->explain($claim);

        $this->assertSame(EnterpriseWikiClaimFindingExplainer::CATEGORY_POSSIBLE_CONTENT_DEVIATION, $finding['category']);
    }

    // =========================================================================
    // Cause-based classification for unsupported_generated_content (run-38 follow-up fix):
    // a claim that never actually reached a verified verdict is technical uncertainty, not a
    // confirmed content error, regardless of the unsupported_generated_content label.
    // =========================================================================

    public function test_missing_block_link_is_technical_uncertainty_and_does_not_suggest_blocking(): void
    {
        $claim = $this->unsupportedClaim(['content_block_key' => null, 'review_metadata' => null]);
        $claim->setRelation('sourceReferences', collect());

        $finding = $this->explainer()->explain($claim);

        $this->assertSame(EnterpriseWikiClaimFindingExplainer::CATEGORY_TECHNICAL_UNCERTAINTY, $finding['category']);
        $this->assertSame('Ingen blokk-kobling', $finding['title']);
        $this->assertFalse($finding['suggested_blocking']);
        $this->assertFalse($this->explainer()->suggestedBlocking($claim));
    }

    public function test_ambiguous_source_candidate_is_technical_uncertainty_and_does_not_suggest_blocking(): void
    {
        $claim = $this->unsupportedClaim(['content_block_key' => 'block-0004', 'review_metadata' => null]);
        $claim->setRelation('sourceReferences', collect([
            new EnterpriseWikiSourceReference(['source_element_key' => 'paragraph-1']),
            new EnterpriseWikiSourceReference(['source_element_key' => 'paragraph-2']),
        ]));

        $finding = $this->explainer()->explain($claim);

        $this->assertSame(EnterpriseWikiClaimFindingExplainer::CATEGORY_TECHNICAL_UNCERTAINTY, $finding['category']);
        $this->assertSame('Usikker kildekandidat', $finding['title']);
        $this->assertFalse($finding['suggested_blocking']);
    }

    public function test_missing_confident_source_candidate_is_technical_uncertainty_and_does_not_suggest_blocking(): void
    {
        $claim = $this->unsupportedClaim(['content_block_key' => 'block-0004', 'review_metadata' => null]);
        $claim->setRelation('sourceReferences', collect());

        $finding = $this->explainer()->explain($claim);

        $this->assertSame(EnterpriseWikiClaimFindingExplainer::CATEGORY_TECHNICAL_UNCERTAINTY, $finding['category']);
        $this->assertSame('Ingen sikker kildekandidat', $finding['title']);
        $this->assertFalse($finding['suggested_blocking']);
    }

    public function test_claim_reused_from_a_verified_unsupported_canonical_fact_is_a_confirmed_content_error(): void
    {
        // EnterpriseWikiVerifyPageClaimsService::persistReusedFact() never writes its own
        // review_metadata for a claim that reuses an identical/equivalent claim's already-
        // established outcome elsewhere — but that is not a technical link failure, it inherited a
        // real, already-confirmed verdict from its canonical fact.
        $claim = $this->unsupportedClaim(['content_block_key' => 'block-0002', 'review_metadata' => null]);
        $claim->setRelation('sourceReferences', collect([
            new EnterpriseWikiSourceReference(['source_element_key' => 'paragraph-9']),
            new EnterpriseWikiSourceReference(['source_element_key' => 'paragraph-11']),
        ]));
        $claim->setRelation('canonicalFact', new EnterpriseWikiCanonicalFact([
            'verification_status' => EnterpriseWikiCanonicalFact::VERIFICATION_STATUS_UNSUPPORTED,
            'verification_reason' => 'The source describes user support handling, not the claimed incident response time.',
        ]));

        $finding = $this->explainer()->explain($claim);

        $this->assertSame(EnterpriseWikiClaimFindingExplainer::CATEGORY_UNDOCUMENTED_OR_INCORRECT_CLAIM, $finding['category']);
        $this->assertSame('Gjenbrukt fra tidligere bekreftet avvik', $finding['title']);
        $this->assertSame('The source describes user support handling, not the claimed incident response time.', $finding['explanation']);
        $this->assertTrue($finding['suggested_blocking']);
        $this->assertTrue($this->explainer()->suggestedBlocking($claim));
    }

    public function test_claim_reused_from_a_canonical_fact_with_no_stored_reason_still_gives_an_honest_message(): void
    {
        $claim = $this->unsupportedClaim(['content_block_key' => 'block-0002', 'review_metadata' => null]);
        $claim->setRelation('sourceReferences', collect());
        $claim->setRelation('canonicalFact', new EnterpriseWikiCanonicalFact([
            'verification_status' => EnterpriseWikiCanonicalFact::VERIFICATION_STATUS_UNSUPPORTED,
            'verification_reason' => null,
        ]));

        $finding = $this->explainer()->explain($claim);

        $this->assertSame(EnterpriseWikiClaimFindingExplainer::CATEGORY_UNDOCUMENTED_OR_INCORRECT_CLAIM, $finding['category']);
        $this->assertNotSame('', trim($finding['explanation']));
        $this->assertTrue($finding['suggested_blocking']);
    }

    public function test_claim_with_canonical_fact_still_pending_falls_back_to_unverified_link(): void
    {
        // A canonical fact that has not itself reached a verdict yet must not be treated as a
        // confirmed basis — this claim is genuinely unverified, same as if it had no fact at all.
        $claim = $this->unsupportedClaim(['content_block_key' => null, 'review_metadata' => null]);
        $claim->setRelation('sourceReferences', collect());
        $claim->setRelation('canonicalFact', new EnterpriseWikiCanonicalFact([
            'verification_status' => EnterpriseWikiCanonicalFact::VERIFICATION_STATUS_PENDING,
        ]));

        $finding = $this->explainer()->explain($claim);

        $this->assertSame(EnterpriseWikiClaimFindingExplainer::CATEGORY_TECHNICAL_UNCERTAINTY, $finding['category']);
        $this->assertFalse($finding['suggested_blocking']);
    }

    public function test_verified_verdict_with_no_specific_mismatch_is_only_a_possible_deviation(): void
    {
        // Mirrors run-38 claim 3874: a first-pass semantic verification reached not_supported, but
        // every itemized check came back "match" — no specific dimension was flagged as wrong, so
        // this is not yet a confirmed content error.
        $claim = $this->unsupportedClaim([
            'content_block_key' => 'block-0001',
            'review_metadata' => [
                'classification_basis' => 'semantic_verification',
                'verdict' => 'not_supported',
                'reason' => 'Both excerpts describe the same controlled change process.',
                'checks' => ['actor' => 'match', 'modality' => 'match', 'subject_entity' => 'match'],
            ],
        ]);
        $claim->setRelation('sourceReferences', collect([new EnterpriseWikiSourceReference(['source_element_key' => 'paragraph-11'])]));

        $finding = $this->explainer()->explain($claim);

        $this->assertSame(EnterpriseWikiClaimFindingExplainer::CATEGORY_POSSIBLE_CONTENT_DEVIATION, $finding['category']);
        $this->assertSame('Both excerpts describe the same controlled change process.', $finding['explanation']);
        $this->assertTrue($finding['suggested_blocking']);
    }

    public function test_scoped_run_reevaluation_with_no_specific_mismatch_is_a_confirmed_content_error(): void
    {
        // Mirrors run-38 claim 4048: reached not_supported again under the stricter, combined-
        // evidence run re-evaluation — a more rigorous re-check that still found no support is a
        // confirmed error, not merely a possible one.
        $claim = $this->unsupportedClaim([
            'content_block_key' => 'block-0002',
            'review_metadata' => [
                'classification_basis' => 'scoped_run_reevaluation',
                'verdict' => 'not_supported',
                'reason' => 'The cited paragraphs describe support handling, not incident response times.',
                'checks' => ['actor' => 'match', 'modality' => 'match', 'subject_entity' => 'match'],
                'reevaluated_run_id' => 38,
            ],
        ]);
        $claim->setRelation('sourceReferences', collect([new EnterpriseWikiSourceReference(['source_element_key' => 'paragraph-9'])]));

        $finding = $this->explainer()->explain($claim);

        $this->assertSame(EnterpriseWikiClaimFindingExplainer::CATEGORY_UNDOCUMENTED_OR_INCORRECT_CLAIM, $finding['category']);
        $this->assertSame('The cited paragraphs describe support handling, not incident response times.', $finding['explanation']);
        $this->assertTrue($finding['suggested_blocking']);
    }

    public function test_misattribution_via_deterministic_subject_mismatch_remains_a_confirmed_content_error(): void
    {
        $claim = $this->verifiedUnsupportedClaim(['deterministic_reason' => 'subject_mismatch'], 'block-0003');

        $finding = $this->explainer()->explain($claim);

        $this->assertSame(EnterpriseWikiClaimFindingExplainer::CATEGORY_UNDOCUMENTED_OR_INCORRECT_CLAIM, $finding['category']);
        $this->assertTrue($finding['suggested_blocking']);
        $this->assertTrue($this->explainer()->suggestedBlocking($claim));
    }

    public function test_different_causes_produce_different_titles_and_explanations(): void
    {
        $actor = $this->explainer()->explain($this->verifiedUnsupportedClaim(['deterministic_reason' => 'actor_mismatch']));
        $modality = $this->explainer()->explain($this->verifiedUnsupportedClaim(['deterministic_reason' => 'modality_mismatch']));
        $scope = $this->explainer()->explain($this->verifiedUnsupportedClaim(['deterministic_reason' => 'scope_mismatch']));
        $noSource = $this->explainer()->explain($this->internalErrorClaim('genuine_content_mismatch'));
        $ambiguousBlock = $this->explainer()->explain($this->internalErrorClaim('claim_missing_unique_content_block_anchor'));

        $titles = [$actor['title'], $modality['title'], $scope['title'], $noSource['title'], $ambiguousBlock['title']];

        $this->assertSame($titles, array_unique($titles), 'Every distinct cause must render a distinct title.');

        $explanations = [$actor['explanation'], $modality['explanation'], $scope['explanation'], $noSource['explanation'], $ambiguousBlock['explanation']];
        $this->assertSame($explanations, array_unique($explanations), 'Every distinct cause must render a distinct explanation.');
    }

    // =========================================================================
    // blockingState(): system recommendation vs. user decision are separate facts (CLAUDE.md:
    // "Systemforslag er ikke brukerbeslutning") — never one collapsed "is_blocking" boolean.
    // =========================================================================

    public function test_no_decision_recorded_is_pending_not_an_implicit_decision(): void
    {
        $claim = $this->verifiedUnsupportedClaim(['deterministic_reason' => 'actor_mismatch']);
        $claim->blocking_override = null;

        $state = $this->explainer()->blockingState($claim);

        $this->assertTrue($state['system_recommends_blocking']);
        $this->assertSame(EnterpriseWikiClaimFindingExplainer::USER_DECISION_PENDING, $state['user_decision']);
        $this->assertTrue($state['requires_decision'], 'A confirmed content deviation with no decision must require one.');
        $this->assertTrue($state['blocks_gate'], 'An unhandled decision need still holds up final approval.');
    }

    public function test_blocking_override_true_is_a_recorded_blocking_decision(): void
    {
        $claim = $this->verifiedUnsupportedClaim(['deterministic_reason' => 'actor_mismatch']);
        $claim->blocking_override = true;

        $state = $this->explainer()->blockingState($claim);

        $this->assertSame(EnterpriseWikiClaimFindingExplainer::USER_DECISION_BLOCKING, $state['user_decision']);
        $this->assertFalse($state['requires_decision'], 'A decision has already been made — nothing is pending.');
        $this->assertTrue($state['blocks_gate']);
    }

    public function test_blocking_override_false_is_a_recorded_non_blocking_decision(): void
    {
        $claim = $this->verifiedUnsupportedClaim(['deterministic_reason' => 'actor_mismatch']);
        $claim->blocking_override = false;

        $state = $this->explainer()->blockingState($claim);

        $this->assertSame(EnterpriseWikiClaimFindingExplainer::USER_DECISION_NOT_BLOCKING, $state['user_decision']);
        $this->assertFalse($state['requires_decision']);
        $this->assertFalse($state['blocks_gate'], 'An explicit user decision not to block must never gate approval.');
    }

    public function test_technical_uncertainty_never_requires_a_blocking_decision(): void
    {
        $claim = $this->internalErrorClaim('genuine_content_mismatch');
        $claim->blocking_override = null;

        $state = $this->explainer()->blockingState($claim);

        $this->assertFalse($state['system_recommends_blocking']);
        $this->assertSame(EnterpriseWikiClaimFindingExplainer::USER_DECISION_PENDING, $state['user_decision']);
        $this->assertFalse($state['requires_decision'], 'Technical uncertainty must never require a document owner decision.');
        $this->assertFalse($state['blocks_gate']);
    }
}
