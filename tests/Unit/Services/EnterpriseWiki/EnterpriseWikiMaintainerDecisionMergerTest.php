<?php

namespace Tests\Unit\Services\EnterpriseWiki;

use App\Exceptions\EnterpriseWikiMaintainerDecisionMergeConflictException;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionMerger;
use PHPUnit\Framework\TestCase;

/**
 * Pure, deterministic unit tests for the split flow's Phase C merge — no AI, no DB. Confirms the
 * merged result is byte-for-byte the same shape EnterpriseWikiMaintainerDecisionPrompt::parse()
 * already expects, so the existing consistency validator/repair pass/apply step need no awareness
 * that a decision was ever split.
 */
class EnterpriseWikiMaintainerDecisionMergerTest extends TestCase
{
    private function merger(): EnterpriseWikiMaintainerDecisionMerger
    {
        return new EnterpriseWikiMaintainerDecisionMerger;
    }

    // 7. Global plan + one batch gives a complete decision.
    public function test_global_plan_plus_one_batch_gives_a_complete_decision(): void
    {
        $merged = $this->merger()->merge($this->globalPlan(), [
            $this->batch([$this->candidate('Incident Management', 'create')], [$this->page('Incident Management')]),
        ]);

        $this->assertSame('Test Artikkel', $merged['source_article']['title']);
        $this->assertSame('Sammendrag: Test Artikkel', $merged['source_summary']['title']);
        $this->assertCount(1, $merged['concept_candidates']);
        $this->assertCount(1, $merged['concept_pages']);
        $this->assertSame([], $merged['entity_pages']);
        $this->assertNull($merged['no_action_reason']);
        $this->assertSame([], $merged['warnings']);
    }

    // 8. Global plan + several batches gives a complete decision; 9. every candidate appears
    // exactly once.
    public function test_global_plan_plus_several_batches_gives_a_complete_decision_with_every_candidate_once(): void
    {
        $merged = $this->merger()->merge($this->globalPlan(), [
            $this->batch([$this->candidate('Incident Management', 'create')], [$this->page('Incident Management')]),
            $this->batch([$this->candidate('Problem Management', 'create')], [$this->page('Problem Management')]),
            $this->batch([$this->candidate('Standard change types', 'exclude')], []),
        ]);

        $this->assertCount(3, $merged['concept_candidates']);
        $this->assertCount(2, $merged['concept_pages']);

        $names = array_column($merged['concept_candidates'], 'name');
        $this->assertSame(['Incident Management', 'Problem Management', 'Standard change types'], $names);
    }

    // 10. decision=create gives exactly one concept_page.
    public function test_create_decision_produces_exactly_one_concept_page(): void
    {
        $merged = $this->merger()->merge($this->globalPlan(), [
            $this->batch([$this->candidate('Incident Management', 'create')], [$this->page('Incident Management')]),
        ]);

        $this->assertCount(1, $merged['concept_pages']);
        $this->assertSame('Incident Management', $merged['concept_pages'][0]['title']);
    }

    // 11. reuse/reference_only/exclude are preserved as-is.
    public function test_reuse_reference_only_and_exclude_decisions_are_preserved(): void
    {
        $merged = $this->merger()->merge($this->globalPlan(), [
            $this->batch([
                $this->candidate('Change Management', 'reuse'),
                $this->candidate('Service Desk', 'reference_only'),
                $this->candidate('Illustration title', 'exclude'),
            ], []),
        ]);

        $decisions = array_column($merged['concept_candidates'], 'decision', 'name');

        $this->assertSame('reuse', $decisions['Change Management']);
        $this->assertSame('reference_only', $decisions['Service Desk']);
        $this->assertSame('exclude', $decisions['Illustration title']);
    }

    // 25. Stable ordering — batch order, then within-batch order, is preserved.
    public function test_candidate_order_follows_batch_and_within_batch_order(): void
    {
        $merged = $this->merger()->merge($this->globalPlan(), [
            $this->batch([$this->candidate('B', 'exclude'), $this->candidate('A', 'exclude')], []),
            $this->batch([$this->candidate('D', 'exclude'), $this->candidate('C', 'exclude')], []),
        ]);

        $this->assertSame(['B', 'A', 'D', 'C'], array_column($merged['concept_candidates'], 'name'));
    }

    // 12. Exact-duplicate candidates (same identity, same decision) are deduped, not doubled.
    public function test_exact_duplicate_candidate_across_batches_is_deduped(): void
    {
        $merged = $this->merger()->merge($this->globalPlan(), [
            $this->batch([$this->candidate('Incident Management', 'create')], [$this->page('Incident Management')]),
            $this->batch([$this->candidate('Incident Management', 'create')], [$this->page('Incident Management')]),
        ]);

        $this->assertCount(1, $merged['concept_candidates']);
        $this->assertCount(1, $merged['concept_pages']);
    }

    // 27. Exact-duplicate page proposal (same title + same slug) is deduped.
    public function test_exact_duplicate_page_proposal_is_deduped(): void
    {
        $merged = $this->merger()->merge($this->globalPlan(), [
            $this->batch([], [$this->page('Incident Management', 'incident-management')]),
            $this->batch([], [$this->page('Incident Management', 'incident-management')]),
        ]);

        $this->assertCount(1, $merged['concept_pages']);
    }

    // 28. Title variants (identity match, not exact string) are recognised as the same candidate
    // — when batches also agree on the resulting page, they dedupe silently.
    public function test_title_variant_across_batches_with_matching_slug_is_deduped(): void
    {
        $merged = $this->merger()->merge($this->globalPlan(), [
            $this->batch([$this->candidate('ITIL Incident Management', 'create')], [$this->page('ITIL Incident Management', 'incident-management')]),
            $this->batch([$this->candidate('Incident Management', 'create')], [$this->page('Incident Management', 'incident-management')]),
        ]);

        // Same identity, same decision ("create") on both — deduped to one entry (the first seen).
        $this->assertCount(1, $merged['concept_candidates']);
        $this->assertSame('ITIL Incident Management', $merged['concept_candidates'][0]['name']);
        $this->assertCount(1, $merged['concept_pages']);
    }

    /**
     * A title variant recognised as the same identity, but with genuinely different proposed
     * slugs, is a real conflict — never silently deduped just because the names are "close
     * enough". Two batches independently phrasing the same concept differently enough to invent
     * different slugs is exactly the kind of disagreement the merger must not guess through.
     */
    public function test_title_variant_across_batches_with_different_slugs_throws_merge_conflict(): void
    {
        $this->expectException(EnterpriseWikiMaintainerDecisionMergeConflictException::class);

        $this->merger()->merge($this->globalPlan(), [
            $this->batch([$this->candidate('ITIL Incident Management', 'create')], [$this->page('ITIL Incident Management')]),
            $this->batch([$this->candidate('Incident Management', 'create')], [$this->page('Incident Management')]),
        ]);
    }

    // 13/26/29. Conflicting decisions for the same concept are rejected — never "last writer wins".
    public function test_conflicting_candidate_decisions_across_batches_throw_merge_conflict(): void
    {
        $this->expectException(EnterpriseWikiMaintainerDecisionMergeConflictException::class);
        $this->expectExceptionMessageMatches('/conflicting batch decisions/');

        $this->merger()->merge($this->globalPlan(), [
            $this->batch([$this->candidate('Incident Management', 'create')], [$this->page('Incident Management')]),
            $this->batch([$this->candidate('Incident Management', 'exclude')], []),
        ]);
    }

    public function test_conflicting_page_slug_for_the_same_title_throws_merge_conflict(): void
    {
        $this->expectException(EnterpriseWikiMaintainerDecisionMergeConflictException::class);
        $this->expectExceptionMessageMatches('/conflicting page proposals/');

        $this->merger()->merge($this->globalPlan(), [
            $this->batch([], [$this->page('Incident Management', 'incident-management')]),
            $this->batch([], [$this->page('Incident Management', 'incident-management-v2')]),
        ]);
    }

    public function test_merge_conflict_message_identifies_both_batch_indexes(): void
    {
        try {
            $this->merger()->merge($this->globalPlan(), [
                $this->batch([$this->candidate('Incident Management', 'create')], [$this->page('Incident Management')]),
                $this->batch([$this->candidate('Incident Management', 'exclude')], []),
            ]);
            $this->fail('Expected EnterpriseWikiMaintainerDecisionMergeConflictException.');
        } catch (EnterpriseWikiMaintainerDecisionMergeConflictException $e) {
            $this->assertStringContainsString('batch 0', $e->getMessage());
            $this->assertStringContainsString('batch 1', $e->getMessage());
        }
    }

    // 30. A candidate marked reference_only with no owning page is passed through as-is — the
    // merger never fills in or hides a missing owning_page_title; that is the consistency
    // validator's job, running after this merge.
    public function test_reference_only_candidate_without_owning_page_is_preserved_unmodified(): void
    {
        $candidate = $this->candidate('Service Desk', 'reference_only');
        $candidate['owning_page_title'] = null;
        $candidate['necessary_for_article'] = true;

        $merged = $this->merger()->merge($this->globalPlan(), [
            $this->batch([$candidate], []),
        ]);

        $this->assertNull($merged['concept_candidates'][0]['owning_page_title']);
        $this->assertTrue($merged['concept_candidates'][0]['necessary_for_article']);
    }

    // =========================================================================
    // Wiki run-587: planned_figures conflict detection
    // =========================================================================

    // A figure planned onto two different pages across batches must not be silently
    // "last-writer-wins" — it is a genuine conflict, same philosophy as page slug/candidate conflicts.
    public function test_same_figure_planned_onto_two_different_pages_throws_merge_conflict(): void
    {
        $this->expectException(EnterpriseWikiMaintainerDecisionMergeConflictException::class);

        $this->merger()->merge($this->globalPlan(), [
            $this->batch(
                [$this->candidate('Incident Management', 'create')],
                [$this->pageWithFigures('Incident Management', figures: [$this->figure('img1', required: true)])],
            ),
            $this->batch(
                [$this->candidate('Problem Management', 'create')],
                [$this->pageWithFigures('Problem Management', figures: [$this->figure('img1', required: false)])],
            ),
        ]);
    }

    // The exact same figure key appearing twice within one page's OWN planned_figures list (e.g.
    // a batch response that happened to list it twice) is deduped, not treated as a conflict —
    // conflicts only concern the SAME figure landing on DIFFERENT pages.
    public function test_same_figure_key_twice_within_one_pages_own_planned_figures_is_deduped(): void
    {
        $merged = $this->merger()->merge($this->globalPlan(), [
            $this->batch(
                [$this->candidate('Incident Management', 'create')],
                [$this->pageWithFigures('Incident Management', figures: [
                    $this->figure('img1', required: true),
                    $this->figure('img1', required: true),
                ])],
            ),
        ]);

        $this->assertCount(1, $merged['concept_pages'][0]['planned_figures']);
    }

    public function test_figure_conflict_message_identifies_the_source_element_key(): void
    {
        try {
            $this->merger()->merge($this->globalPlan(), [
                $this->batch(
                    [$this->candidate('Incident Management', 'create')],
                    [$this->pageWithFigures('Incident Management', figures: [$this->figure('img1', required: true)])],
                ),
                $this->batch(
                    [$this->candidate('Problem Management', 'create')],
                    [$this->pageWithFigures('Problem Management', figures: [$this->figure('img1', required: false)])],
                ),
            ]);
            $this->fail('Expected EnterpriseWikiMaintainerDecisionMergeConflictException.');
        } catch (EnterpriseWikiMaintainerDecisionMergeConflictException $e) {
            $this->assertStringContainsString('img1', $e->getMessage());
        }
    }

    // No batches at all (global plan alone) still produces a complete, valid-shaped decision.
    public function test_empty_batch_list_produces_a_complete_decision_with_no_candidates(): void
    {
        $merged = $this->merger()->merge($this->globalPlan(), []);

        $this->assertSame([], $merged['concept_candidates']);
        $this->assertSame([], $merged['concept_pages']);
        $this->assertArrayHasKey('source_article', $merged);
        $this->assertArrayHasKey('source_summary', $merged);
    }

    public function test_merge_result_carries_global_plan_entity_pages_and_warnings_through(): void
    {
        $globalPlan = $this->globalPlan();
        $globalPlan['entity_pages'] = [['action' => 'create', 'page_id' => null, 'title' => 'Acme AS', 'proposed_slug' => 'acme-as', 'reason' => 'New client.']];
        $globalPlan['warnings'] = ['Language mismatch detected.'];
        $globalPlan['no_action_reason'] = null;

        $merged = $this->merger()->merge($globalPlan, []);

        $this->assertCount(1, $merged['entity_pages']);
        $this->assertSame('Acme AS', $merged['entity_pages'][0]['title']);
        $this->assertSame(['Language mismatch detected.'], $merged['warnings']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function globalPlan(): array
    {
        return [
            'source_article' => [
                'action' => 'create',
                'title' => 'Test Artikkel',
                'proposed_slug' => 'test-artikkel-ab1c2d',
                'reason' => 'New.',
            ],
            'source_summary' => [
                'action' => 'create',
                'title' => 'Sammendrag: Test Artikkel',
                'proposed_slug' => 'sammendrag-test-artikkel-ab1c2d',
                'reason' => 'Companion.',
            ],
            'entity_pages' => [],
            'concept_candidate_mentions' => [],
            'no_action_reason' => null,
            'warnings' => [],
        ];
    }

    private function batch(array $candidates, array $pages): array
    {
        return ['concept_candidates' => $candidates, 'concept_pages' => $pages];
    }

    private function candidate(string $name, string $decision): array
    {
        return [
            'name' => $name,
            'concept_type' => 'process',
            'independent_reason' => 'Independent concept.',
            'mentioned_context' => 'section 2',
            'existing_page_title' => null,
            'decision' => $decision,
            'justification' => 'Test justification.',
            'owning_page_title' => null,
            'necessary_for_article' => false,
        ];
    }

    private function page(string $title, ?string $slug = null): array
    {
        return [
            'action' => 'create',
            'page_id' => null,
            'title' => $title,
            'proposed_slug' => $slug ?? strtolower(str_replace(' ', '-', $title)),
            'reason' => 'Concept page.',
        ];
    }

    private function pageWithFigures(string $title, array $figures, ?string $slug = null): array
    {
        return array_merge($this->page($title, $slug), ['planned_figures' => $figures]);
    }

    private function figure(string $sourceElementKey, bool $required = false): array
    {
        return [
            'source_element_key' => $sourceElementKey,
            'classification' => 'diagram',
            'section_placement' => null,
            'purpose' => 'Illustrates the governance model.',
            'required' => $required,
            'caption_hint' => null,
        ];
    }
}
