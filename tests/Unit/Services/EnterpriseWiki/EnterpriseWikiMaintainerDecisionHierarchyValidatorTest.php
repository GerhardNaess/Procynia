<?php

namespace Tests\Unit\Services\EnterpriseWiki;

use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionHierarchyValidator;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionMerger;
use PHPUnit\Framework\TestCase;

/**
 * Pure, deterministic unit tests for the Wiki overfragmentation fix ("short ITIL document exploded
 * into 9 pages" incident: six short practices under one framework each became their own concept
 * page). No AI mocking needed — the validator never calls the model.
 */
class EnterpriseWikiMaintainerDecisionHierarchyValidatorTest extends TestCase
{
    private function validator(): EnterpriseWikiMaintainerDecisionHierarchyValidator
    {
        return new EnterpriseWikiMaintainerDecisionHierarchyValidator;
    }

    public function test_minimal_decision_with_no_candidates_has_no_issues(): void
    {
        $this->assertSame([], $this->validator()->findIssues($this->baseDecision()));
    }

    // =========================================================================
    // 1: short framework document with several short practices — not one page each
    // =========================================================================

    public function test_short_practices_under_one_framework_are_all_flagged(): void
    {
        $decision = $this->baseDecision();
        $decision['concept_candidates'] = [
            $this->candidate('ITIL Incident Management', mentionedContext: 'framework overview', hasEvidence: true, hasReuse: true),
            $this->candidate('Practice A', mentionedContext: 'section 3.1', hasEvidence: false, hasReuse: false),
            $this->candidate('Practice B', mentionedContext: 'section 3.2', hasEvidence: false, hasReuse: false),
            $this->candidate('Practice C', mentionedContext: 'section 3.3', hasEvidence: false, hasReuse: false),
            $this->candidate('Practice D', mentionedContext: 'section 3.4', hasEvidence: false, hasReuse: false),
            $this->candidate('Practice E', mentionedContext: 'section 3.5', hasEvidence: false, hasReuse: false),
            $this->candidate('Practice F', mentionedContext: 'section 3.6', hasEvidence: false, hasReuse: false),
        ];

        $issues = $this->validator()->findIssues($decision);

        foreach (['Practice A', 'Practice B', 'Practice C', 'Practice D', 'Practice E', 'Practice F'] as $name) {
            $this->assertStringContainsString($name, implode(' | ', $issues));
        }
        // The framework itself has real evidence and reuse value — never flagged.
        $this->assertStringNotContainsString('"ITIL Incident Management"', implode(' | ', $issues));
    }

    // =========================================================================
    // 2: a substantial standalone subprocess is allowed
    // =========================================================================

    public function test_substantial_subprocess_with_evidence_and_reuse_value_is_not_flagged(): void
    {
        $decision = $this->baseDecision();
        $decision['concept_candidates'] = [
            $this->candidate('Incident Escalation Process', mentionedContext: 'dedicated section 4', hasEvidence: true, hasReuse: true),
        ];

        $this->assertSame([], $this->validator()->findIssues($decision));
    }

    // =========================================================================
    // 3: same short bullet list spawning several candidates
    // =========================================================================

    public function test_candidates_sharing_the_same_short_source_location_are_flagged_as_a_cluster(): void
    {
        $decision = $this->baseDecision();
        $decision['concept_candidates'] = [
            $this->candidate('Alpha', mentionedContext: 'bullet list under "Practices"', hasEvidence: true, hasReuse: true),
            $this->candidate('Beta', mentionedContext: 'bullet list under "Practices"', hasEvidence: true, hasReuse: true),
            $this->candidate('Gamma', mentionedContext: 'bullet list under "Practices"', hasEvidence: true, hasReuse: true),
            $this->candidate('Delta', mentionedContext: 'bullet list under "Practices"', hasEvidence: true, hasReuse: true),
            $this->candidate('Epsilon', mentionedContext: 'bullet list under "Practices"', hasEvidence: true, hasReuse: true),
        ];

        $issues = $this->validator()->findIssues($decision);

        $this->assertNotEmpty($issues);
        $combined = implode(' | ', $issues);
        $this->assertStringContainsString('same source location', $combined);
        foreach (['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon'] as $name) {
            $this->assertStringContainsString($name, $combined);
        }
    }

    public function test_two_candidates_sharing_context_below_cluster_threshold_are_not_flagged_by_shared_context_rule(): void
    {
        $decision = $this->baseDecision();
        $decision['concept_candidates'] = [
            $this->candidate('Alpha', mentionedContext: 'section 2', hasEvidence: true, hasReuse: true),
            $this->candidate('Beta', mentionedContext: 'section 2', hasEvidence: true, hasReuse: true),
        ];

        $this->assertSame([], $this->validator()->findIssues($decision));
    }

    // =========================================================================
    // 4: overlapping sibling concepts
    // =========================================================================

    public function test_overlapping_sibling_concepts_are_flagged_for_consolidation(): void
    {
        $decision = $this->baseDecision();
        $decision['concept_candidates'] = [
            $this->candidate('Incident Management Process', mentionedContext: 'section 2', hasEvidence: true, hasReuse: true),
            $this->candidate('Incident Management', mentionedContext: 'section 4', hasEvidence: true, hasReuse: true),
        ];

        $issues = $this->validator()->findIssues($decision);

        $this->assertNotEmpty($issues);
        $combined = implode(' | ', $issues);
        $this->assertStringContainsString('Incident Management Process', $combined);
        $this->assertStringContainsString('Incident Management', $combined);
        $this->assertStringContainsString('near-duplicate', $combined);
    }

    public function test_unrelated_concepts_are_never_flagged_as_overlapping(): void
    {
        $decision = $this->baseDecision();
        $decision['concept_candidates'] = [
            $this->candidate('Incident Management', mentionedContext: 'section 2', hasEvidence: true, hasReuse: true),
            $this->candidate('Problem Management', mentionedContext: 'section 4', hasEvidence: true, hasReuse: true),
        ];

        $this->assertSame([], $this->validator()->findIssues($decision));
    }

    // =========================================================================
    // 5: role or activity without separate substantial grounds
    // =========================================================================

    public function test_role_or_activity_without_separate_evidence_is_flagged(): void
    {
        $decision = $this->baseDecision();
        $decision['concept_candidates'] = [
            $this->candidate('Service Desk Analyst', mentionedContext: 'mentioned in section 1', hasEvidence: false, hasReuse: false),
        ];

        $issues = $this->validator()->findIssues($decision);

        $this->assertNotEmpty($issues);
        $this->assertStringContainsString('Service Desk Analyst', implode(' ', $issues));
    }

    // =========================================================================
    // 6: several genuinely separate concepts remain allowed
    // =========================================================================

    public function test_three_genuinely_independent_concepts_are_all_allowed(): void
    {
        $decision = $this->baseDecision();
        $decision['concept_candidates'] = [
            $this->candidate('Incident Management', mentionedContext: 'section 2', hasEvidence: true, hasReuse: true),
            $this->candidate('Problem Management', mentionedContext: 'section 4', hasEvidence: true, hasReuse: true),
            $this->candidate('Change Advisory Board', mentionedContext: 'section 6', hasEvidence: true, hasReuse: true),
        ];

        $this->assertSame([], $this->validator()->findIssues($decision));
    }

    // =========================================================================
    // 7: overfragmentation spanning multiple maintainer batches is still caught after merge
    // =========================================================================

    public function test_overfragmentation_split_across_batches_is_still_caught_after_merge(): void
    {
        $globalPlan = [
            'source_article' => ['action' => 'create', 'title' => 'Rammeverk', 'proposed_slug' => 'rammeverk-ab12', 'reason' => 'r'],
            'source_summary' => ['action' => 'create', 'title' => 'Sammendrag: Rammeverk', 'proposed_slug' => 'sammendrag-rammeverk-ab12', 'reason' => 'r'],
            'entity_pages' => [],
            'no_action_reason' => null,
            'warnings' => [],
        ];

        $batch1 = [
            'concept_candidates' => [
                $this->candidate('Alpha', mentionedContext: 'bullet list under "Practices"', hasEvidence: true, hasReuse: true),
                $this->candidate('Beta', mentionedContext: 'bullet list under "Practices"', hasEvidence: true, hasReuse: true),
            ],
            'concept_pages' => [],
        ];

        $batch2 = [
            'concept_candidates' => [
                $this->candidate('Gamma', mentionedContext: 'bullet list under "Practices"', hasEvidence: true, hasReuse: true),
                $this->candidate('Delta', mentionedContext: 'bullet list under "Practices"', hasEvidence: true, hasReuse: true),
                $this->candidate('Epsilon', mentionedContext: 'bullet list under "Practices"', hasEvidence: true, hasReuse: true),
            ],
            'concept_pages' => [],
        ];

        $merged = (new EnterpriseWikiMaintainerDecisionMerger)->merge($globalPlan, [$batch1, $batch2]);

        $this->assertCount(5, $merged['concept_candidates'], 'Sanity check: merge combined both batches.');

        $issues = $this->validator()->findIssues($merged);

        $this->assertNotEmpty($issues);
        $this->assertStringContainsString('same source location', implode(' | ', $issues));
    }

    // =========================================================================
    // 8: existing-page reuse is never blocked by hierarchy validation
    // =========================================================================

    public function test_reuse_decision_is_never_flagged_regardless_of_evidence_or_shared_context(): void
    {
        $decision = $this->baseDecision();
        $decision['concept_candidates'] = [
            $this->candidate('Incident Management', mentionedContext: 'bullet list under "Practices"', hasEvidence: false, hasReuse: false, decision: 'reuse'),
            $this->candidate('Incident Handling', mentionedContext: 'bullet list under "Practices"', hasEvidence: false, hasReuse: false, decision: 'reuse'),
        ];

        $this->assertSame([], $this->validator()->findIssues($decision));
    }

    public function test_reuse_decision_does_not_participate_in_near_duplicate_check_against_a_create_decision(): void
    {
        $decision = $this->baseDecision();
        $decision['concept_candidates'] = [
            $this->candidate('Incident Management', mentionedContext: 'section 2', hasEvidence: true, hasReuse: true, decision: 'reuse'),
            $this->candidate('Problem Management', mentionedContext: 'section 4', hasEvidence: true, hasReuse: true, decision: 'create'),
        ];

        $this->assertSame([], $this->validator()->findIssues($decision));
    }

    // =========================================================================
    // Legacy/backward-compatible decisions (fields absent) are never retroactively flagged
    // =========================================================================

    public function test_candidate_missing_evidence_and_reuse_flags_defaults_to_not_flagged(): void
    {
        $decision = $this->baseDecision();
        $candidate = $this->candidate('Incident Management', mentionedContext: 'section 2', hasEvidence: true, hasReuse: true);
        unset($candidate['has_separate_source_evidence'], $candidate['has_reuse_value']);
        $decision['concept_candidates'] = [$candidate];

        $this->assertSame([], $this->validator()->findIssues($decision));
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function baseDecision(): array
    {
        return [
            'source_article' => [
                'action' => 'create',
                'title' => 'Rammeverk',
                'proposed_slug' => 'rammeverk-ab12',
                'reason' => 'New source document.',
            ],
            'source_summary' => [
                'action' => 'create',
                'title' => 'Sammendrag: Rammeverk',
                'proposed_slug' => 'sammendrag-rammeverk-ab12',
                'reason' => 'Companion summary.',
            ],
            'concept_candidates' => [],
            'concept_pages' => [],
            'entity_pages' => [],
            'no_action_reason' => null,
            'warnings' => [],
        ];
    }

    // =========================================================================
    // Run 16 (Aurora/ITSM): reusable subject-matter concepts must not be blocked
    // =========================================================================

    /**
     * Run 16 planned 12 concept candidates from a ~1.9k-char ITSM document and created ZERO
     * concept pages: Hendelseshåndtering, Endringsstyring and SLA were all reported with
     * has_separate_source_evidence=false and has_reuse_value=false, and the gate rejected a
     * "create" that was missing EITHER signal. A standard professional process that other pages
     * will link to has reuse potential from its first source, so reuse value alone now carries it.
     */
    public function test_reusable_itsm_processes_are_not_blocked_on_thin_source_evidence(): void
    {
        $decision = $this->baseDecision();
        $decision['concept_candidates'] = [
            // Short sections in a small document — thin evidence, but genuinely reusable terms.
            $this->candidate('Hendelseshåndtering', mentionedContext: 'section 3', hasEvidence: false, hasReuse: true),
            $this->candidate('Endringsstyring', mentionedContext: 'section 4', hasEvidence: false, hasReuse: true),
            $this->candidate('Tjenestenivå (SLA)', mentionedContext: 'section 5', hasEvidence: false, hasReuse: true),
        ];

        $this->assertSame([], $this->validator()->findIssues($decision));
    }

    /**
     * The other half of the same scenario: concrete thresholds and purely local roles still must
     * not become concept pages. Both signals absent is what the gate still stops.
     */
    public function test_concrete_figures_and_local_roles_are_still_blocked(): void
    {
        $decision = $this->baseDecision();
        $decision['concept_candidates'] = [
            $this->candidate('Tilgjengelighet 99,5 %', mentionedContext: 'section 5', hasEvidence: false, hasReuse: false),
            $this->candidate('Tjenesteeier (rolle)', mentionedContext: 'section 2', hasEvidence: false, hasReuse: false),
        ];

        $issues = implode(' | ', $this->validator()->findIssues($decision));

        $this->assertStringContainsString('Tilgjengelighet 99,5 %', $issues);
        $this->assertStringContainsString('Tjenesteeier (rolle)', $issues);
    }

    /**
     * A named system stays an entity: routed through entity_pages, it is not a concept candidate
     * decided "create" at all, so the concept gate never sees it and never objects.
     */
    public function test_a_named_platform_routed_as_an_entity_is_untouched_by_the_concept_gate(): void
    {
        $decision = $this->baseDecision();
        $decision['entity_pages'] = [['title' => 'Aurora Serviceplattform', 'action' => 'create', 'page_id' => null]];
        $decision['concept_candidates'] = [
            // Reported as a reuse of the entity page, exactly as run 16 did — never "create".
            $this->candidate('Aurora Serviceplattform', mentionedContext: 'document title', hasEvidence: true, hasReuse: true, decision: 'reuse'),
            $this->candidate('Hendelseshåndtering', mentionedContext: 'section 3', hasEvidence: false, hasReuse: true),
        ];

        $issues = $this->validator()->findIssues($decision);

        $this->assertSame([], $issues);
        $this->assertSame('Aurora Serviceplattform', $decision['entity_pages'][0]['title']);
        $this->assertSame([], array_values(array_filter(
            $decision['concept_candidates'],
            static fn (array $c): bool => $c['name'] === 'Aurora Serviceplattform' && $c['decision'] === 'create',
        )), 'the platform must never be decided as a concept page');
    }

    /**
     * The whole Aurora mix in one pass: reusable processes survive, figures and local roles do
     * not, and the platform stays an entity — proving central reusable terms are no longer
     * systematically excluded while the overfragmentation guard still bites.
     */
    public function test_aurora_scenario_keeps_reusable_concepts_and_drops_local_facts(): void
    {
        $decision = $this->baseDecision();
        $decision['entity_pages'] = [['title' => 'Aurora Serviceplattform', 'action' => 'update', 'page_id' => 45]];
        $decision['concept_candidates'] = [
            $this->candidate('Hendelseshåndtering', mentionedContext: 'section 3', hasEvidence: false, hasReuse: true),
            $this->candidate('Endringsstyring', mentionedContext: 'section 4', hasEvidence: false, hasReuse: true),
            $this->candidate('Tjenestenivå (SLA)', mentionedContext: 'section 5', hasEvidence: true, hasReuse: true),
            $this->candidate('Tilgjengelighet 99,5 %', mentionedContext: 'section 5b', hasEvidence: false, hasReuse: false),
            $this->candidate('Tjenesterapport (månedlig)', mentionedContext: 'section 6', hasEvidence: false, hasReuse: false),
            $this->candidate('Driftsleder (rolle)', mentionedContext: 'section 2', hasEvidence: false, hasReuse: false),
            $this->candidate('Aurora Serviceplattform', mentionedContext: 'document title', hasEvidence: true, hasReuse: true, decision: 'reuse'),
        ];

        $issues = implode(' | ', $this->validator()->findIssues($decision));

        foreach (['Hendelseshåndtering', 'Endringsstyring', 'Tjenestenivå (SLA)'] as $keep) {
            $this->assertStringNotContainsString($keep, $issues, "{$keep} must remain a viable concept page");
        }

        foreach (['Tilgjengelighet 99,5 %', 'Tjenesterapport (månedlig)', 'Driftsleder (rolle)'] as $drop) {
            $this->assertStringContainsString($drop, $issues, "{$drop} must not become a concept page");
        }
    }

    private function candidate(
        string $name,
        string $mentionedContext,
        bool $hasEvidence,
        bool $hasReuse,
        string $decision = 'create',
    ): array {
        return [
            'name' => $name,
            'concept_type' => 'concept',
            'independent_reason' => "Reason for {$name}.",
            'mentioned_context' => $mentionedContext,
            'existing_page_title' => $decision === 'reuse' ? $name : null,
            'decision' => $decision,
            'justification' => "Justification for {$name}.",
            'owning_page_title' => null,
            'necessary_for_article' => false,
            'has_separate_source_evidence' => $hasEvidence,
            'has_reuse_value' => $hasReuse,
        ];
    }
}
