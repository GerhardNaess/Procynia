<?php

namespace Tests\Unit\Services\EnterpriseWiki;

use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionConsistencyValidator;
use PHPUnit\Framework\TestCase;

/**
 * Pure, deterministic unit tests for the Wiki run-581 fix ("ITIL Incident Management" never
 * proposed as a concept page even though the article/summary pointed the reader onward to it).
 * No AI mocking needed — the validator never calls the model.
 */
class EnterpriseWikiMaintainerDecisionConsistencyValidatorTest extends TestCase
{
    private function validator(): EnterpriseWikiMaintainerDecisionConsistencyValidator
    {
        return new EnterpriseWikiMaintainerDecisionConsistencyValidator;
    }

    public function test_minimal_decision_with_no_guidance_or_candidates_has_no_issues(): void
    {
        $issues = $this->validator()->findIssues($this->baseDecision(), []);
        $this->assertSame([], $issues);
    }

    // =========================================================================
    // related_page_guidance dangling-reference check
    // =========================================================================

    public function test_related_page_guidance_pointing_to_existing_index_page_has_no_issue(): void
    {
        $decision = $this->baseDecision();
        $decision['source_article']['related_page_guidance'] = [
            ['page_title' => 'ITIL Incident Management', 'relationship' => 'Link here for detail.'],
        ];

        $index = [['title' => 'ITIL Incident Management', 'id' => 5]];

        $this->assertSame([], $this->validator()->findIssues($decision, $index));
    }

    public function test_related_page_guidance_pointing_to_a_page_planned_in_same_decision_has_no_issue(): void
    {
        $decision = $this->baseDecision();
        $decision['source_article']['related_page_guidance'] = [
            ['page_title' => 'ITIL Incident Management', 'relationship' => 'Link here for detail.'],
        ];
        $decision['concept_pages'] = [$this->conceptPageEntry('ITIL Incident Management')];

        $this->assertSame([], $this->validator()->findIssues($decision, []));
    }

    public function test_related_page_guidance_pointing_to_exact_run_source_page_title_has_no_issue(): void
    {
        $decision = $this->baseDecision();
        $decision['source_summary']['related_page_guidance'] = [
            ['page_title' => $decision['source_article']['title'], 'relationship' => 'Link to the article for detail.'],
        ];
        $decision['concept_pages'] = [
            $this->conceptPageEntry('ITIL', relatedTo: $decision['source_article']['title']),
        ];

        $this->assertSame([], $this->validator()->findIssues($decision, []));
    }

    public function test_run_source_page_matching_is_exact_and_does_not_hide_missing_concept_page(): void
    {
        $decision = $this->baseDecision();
        $decision['source_article']['related_page_guidance'] = [
            ['page_title' => 'Incident Management', 'relationship' => 'See the concept page.'],
        ];

        $issues = $this->validator()->findIssues($decision, []);

        $this->assertNotEmpty($issues);
        $this->assertStringContainsString('Incident Management', implode(' ', $issues));
    }

    /**
     * The literal run-581 bug shape: article and summary both point onward to a concept page
     * that concept_pages never created, and no existing page in the index covers it either.
     */
    public function test_related_page_guidance_pointing_to_a_nonexistent_page_is_an_issue(): void
    {
        $decision = $this->baseDecision();
        $decision['source_article']['related_page_guidance'] = [
            ['page_title' => 'ITIL Incident Management', 'relationship' => 'See the concept page.'],
        ];
        $decision['source_summary']['related_page_guidance'] = [
            ['page_title' => 'ITIL Incident Management', 'relationship' => 'See the concept page.'],
        ];

        $issues = $this->validator()->findIssues($decision, []);

        $this->assertNotEmpty($issues);
        $this->assertStringContainsString('ITIL Incident Management', implode(' ', $issues));
    }

    public function test_related_page_guidance_matches_via_title_variant_canonicalisation(): void
    {
        $decision = $this->baseDecision();
        $decision['source_article']['related_page_guidance'] = [
            ['page_title' => 'Incident management (ITIL)', 'relationship' => 'See the concept page.'],
        ];

        $index = [['title' => 'ITIL Incident Management', 'id' => 5]];

        $this->assertSame([], $this->validator()->findIssues($decision, $index));
    }

    public function test_related_page_guidance_dangling_reference_on_a_concept_page_entry_is_detected(): void
    {
        $decision = $this->baseDecision();
        $decision['concept_pages'] = [$this->conceptPageEntry('Change Management', relatedTo: 'Incident Management')];

        $issues = $this->validator()->findIssues($decision, []);

        $this->assertNotEmpty($issues);
        $this->assertStringContainsString('Incident Management', implode(' ', $issues));
    }

    // =========================================================================
    // concept_candidates self-contradiction check
    // =========================================================================

    public function test_candidate_decided_create_with_matching_concept_page_has_no_issue(): void
    {
        $decision = $this->baseDecision();
        $decision['concept_candidates'] = [$this->candidate('ITIL Incident Management', 'create')];
        $decision['concept_pages'] = [$this->conceptPageEntry('ITIL Incident Management')];

        $this->assertSame([], $this->validator()->findIssues($decision, []));
    }

    public function test_candidate_decided_create_without_matching_concept_page_is_an_issue(): void
    {
        $decision = $this->baseDecision();
        $decision['concept_candidates'] = [$this->candidate('ITIL Incident Management', 'create')];

        $issues = $this->validator()->findIssues($decision, []);

        $this->assertNotEmpty($issues);
        $this->assertStringContainsString('ITIL Incident Management', implode(' ', $issues));
    }

    public function test_candidate_reference_only_and_necessary_without_owning_page_is_an_issue(): void
    {
        $decision = $this->baseDecision();
        $decision['concept_candidates'] = [
            $this->candidate('ITIL Incident Management', 'reference_only', necessary: true, owningPageTitle: null),
        ];

        $issues = $this->validator()->findIssues($decision, []);

        $this->assertNotEmpty($issues);
        $this->assertStringContainsString('ITIL Incident Management', implode(' ', $issues));
    }

    public function test_candidate_reference_only_and_necessary_with_resolvable_owning_page_has_no_issue(): void
    {
        $decision = $this->baseDecision();
        $decision['concept_candidates'] = [
            $this->candidate('ITIL Incident Management', 'reference_only', necessary: true, owningPageTitle: 'Service Management Overview'),
        ];

        $index = [['title' => 'Service Management Overview', 'id' => 9]];

        $this->assertSame([], $this->validator()->findIssues($decision, $index));
    }

    public function test_candidate_excluded_and_not_necessary_has_no_issue(): void
    {
        $decision = $this->baseDecision();
        $decision['concept_candidates'] = [
            $this->candidate('Some Passing Term', 'exclude', necessary: false, owningPageTitle: null),
        ];

        $this->assertSame([], $this->validator()->findIssues($decision, []));
    }

    public function test_candidate_excluded_but_marked_necessary_without_owning_page_is_an_issue(): void
    {
        $decision = $this->baseDecision();
        $decision['concept_candidates'] = [
            $this->candidate('ITIL Incident Management', 'exclude', necessary: true, owningPageTitle: null),
        ];

        $issues = $this->validator()->findIssues($decision, []);
        $this->assertNotEmpty($issues);
    }

    /**
     * Structural decision must be stable regardless of how the free-text justification/reason
     * is worded — the validator inspects only structural fields (decision enum, title identity,
     * necessary_for_article boolean), never justification/reason text content.
     */
    public function test_validation_outcome_is_unaffected_by_varying_justification_text(): void
    {
        $decisionA = $this->baseDecision();
        $decisionA['concept_candidates'] = [
            array_merge($this->candidate('ITIL Incident Management', 'create'), [
                'justification' => 'Central ITIL process, well documented in the source.',
            ]),
        ];
        $decisionA['concept_pages'] = [$this->conceptPageEntry('ITIL Incident Management')];

        $decisionB = $this->baseDecision();
        $decisionB['concept_candidates'] = [
            array_merge($this->candidate('ITIL Incident Management', 'create'), [
                'justification' => 'Reader needs this to understand the escalation flow described.',
            ]),
        ];
        $decisionB['concept_pages'] = [$this->conceptPageEntry('ITIL Incident Management')];

        $this->assertSame(
            $this->validator()->findIssues($decisionA, []),
            $this->validator()->findIssues($decisionB, []),
        );
    }

    public function test_casual_mention_with_no_related_page_guidance_and_no_candidate_has_no_issue(): void
    {
        // A term mentioned once, in passing, with no reference_only_topics/related_page_guidance
        // and no concept_candidates entry at all — nothing here claims a page is needed.
        $decision = $this->baseDecision();
        $decision['source_article']['reference_only_topics'] = ['A passing mention of a general industry term.'];

        $this->assertSame([], $this->validator()->findIssues($decision, []));
    }

    // =========================================================================
    // Wiki run-587/run-593: planned_figures dangling source-key check + document-scoped placement
    // =========================================================================

    public function test_planned_figure_with_a_known_source_element_key_has_no_issue(): void
    {
        $decision = $this->baseDecision();
        $decision['source_article']['planned_figures'] = [$this->plannedFigure('img1')];

        $this->assertSame([], $this->validator()->findIssues($decision, [], ['img1', 'img2']));
    }

    public function test_planned_figure_with_an_unknown_source_element_key_is_an_issue(): void
    {
        $decision = $this->baseDecision();
        $decision['source_article']['planned_figures'] = [$this->plannedFigure('img9')];

        $issues = $this->validator()->findIssues($decision, [], ['img1', 'img2']);

        $this->assertNotEmpty($issues);
        $this->assertStringContainsString('img9', implode(' ', $issues));
    }

    public function test_empty_valid_figure_keys_list_skips_the_dangling_figure_check(): void
    {
        // Matches the same "empty means unknown, not nothing is valid" convention as
        // findDanglingRelatedPageGuidance()'s own $indexContext handling.
        $decision = $this->baseDecision();
        $decision['source_article']['planned_figures'] = [$this->plannedFigure('img9')];

        $this->assertSame([], $this->validator()->findIssues($decision, [], []));
    }

    // A figure belongs to the source document, not to any single page.
    public function test_same_figure_planned_onto_article_and_one_concept_page_is_not_an_issue(): void
    {
        $decision = $this->baseDecision();
        $decision['source_article']['planned_figures'] = [$this->plannedFigure('img1')];
        $decision['concept_pages'] = [
            array_merge($this->conceptPageEntry('Change Management'), ['planned_figures' => [$this->plannedFigure('img1')]]),
        ];

        $this->assertSame([], $this->validator()->findIssues($decision, []));
    }

    public function test_same_figure_planned_onto_the_same_page_twice_has_no_conflict_issue(): void
    {
        $decision = $this->baseDecision();
        $decision['source_article']['planned_figures'] = [
            $this->plannedFigure('img1'),
            $this->plannedFigure('img1'),
        ];

        $this->assertSame([], $this->validator()->findIssues($decision, []));
    }

    // 9. The single-call path follows the exact same article+summary pairing exception as the
    // split-flow merger (Wiki run-588).
    public function test_same_figure_on_source_article_and_source_summary_has_no_conflict_issue(): void
    {
        $decision = $this->baseDecision();
        $decision['source_article']['planned_figures'] = [$this->plannedFigure('img1', required: true)];
        $decision['source_summary']['planned_figures'] = [$this->plannedFigure('img1', required: false)];

        $this->assertSame([], $this->validator()->findIssues($decision, []));
    }

    public function test_same_figure_on_source_summary_and_a_concept_page_is_not_an_issue(): void
    {
        $decision = $this->baseDecision();
        $decision['source_summary']['planned_figures'] = [$this->plannedFigure('img1')];
        $decision['concept_pages'] = [
            array_merge($this->conceptPageEntry('Change Management'), ['planned_figures' => [$this->plannedFigure('img1')]]),
        ];

        $this->assertSame([], $this->validator()->findIssues($decision, []));
    }

    public function test_same_figure_on_two_concept_pages_is_not_an_issue(): void
    {
        $decision = $this->baseDecision();
        $decision['concept_pages'] = [
            array_merge($this->conceptPageEntry('Change Management'), ['planned_figures' => [$this->plannedFigure('img1')]]),
            array_merge($this->conceptPageEntry('Problem Management'), ['planned_figures' => [$this->plannedFigure('img1')]]),
        ];

        $this->assertSame([], $this->validator()->findIssues($decision, []));
    }

    public function test_same_figure_on_article_summary_and_one_concept_page_is_not_an_issue(): void
    {
        $decision = $this->baseDecision();
        $decision['source_article']['planned_figures'] = [$this->plannedFigure('img1', required: true)];
        $decision['source_summary']['planned_figures'] = [$this->plannedFigure('img1', required: false)];
        $decision['concept_pages'] = [
            array_merge($this->conceptPageEntry('Change Management'), ['planned_figures' => [$this->plannedFigure('img1')]]),
        ];

        $this->assertSame([], $this->validator()->findIssues($decision, []));
    }

    // =========================================================================
    // Wiki run-593: a figure belongs to the source document, not to any single page — any
    // combination of pages generated from the same document may show the same figure.
    // =========================================================================

    public function test_same_figure_on_article_and_one_entity_page_is_not_an_issue(): void
    {
        $decision = $this->baseDecision();
        $decision['source_article']['planned_figures'] = [$this->plannedFigure('img1')];
        $decision['entity_pages'] = [$this->entityPageEntry('Acme AS', figures: [$this->plannedFigure('img1')])];

        $this->assertSame([], $this->validator()->findIssues($decision, []));
    }

    public function test_same_figure_on_article_summary_and_one_entity_page_is_not_an_issue(): void
    {
        $decision = $this->baseDecision();
        $decision['source_article']['planned_figures'] = [$this->plannedFigure('img1', required: true)];
        $decision['source_summary']['planned_figures'] = [$this->plannedFigure('img1', required: false)];
        $decision['entity_pages'] = [$this->entityPageEntry('Acme AS', figures: [$this->plannedFigure('img1')])];

        $this->assertSame([], $this->validator()->findIssues($decision, []));
    }

    public function test_same_figure_on_two_entity_pages_is_not_an_issue(): void
    {
        $decision = $this->baseDecision();
        $decision['entity_pages'] = [
            $this->entityPageEntry('Acme AS', figures: [$this->plannedFigure('img1')]),
            $this->entityPageEntry('Beta AS', figures: [$this->plannedFigure('img1')]),
        ];

        $this->assertSame([], $this->validator()->findIssues($decision, []));
    }

    public function test_same_figure_on_a_concept_page_and_an_entity_page_is_not_an_issue(): void
    {
        $decision = $this->baseDecision();
        $decision['concept_pages'] = [
            array_merge($this->conceptPageEntry('Change Management'), ['planned_figures' => [$this->plannedFigure('img1')]]),
        ];
        $decision['entity_pages'] = [$this->entityPageEntry('Acme AS', figures: [$this->plannedFigure('img1')])];

        $this->assertSame([], $this->validator()->findIssues($decision, []));
    }

    public function test_same_figure_on_article_and_two_concept_pages_is_not_an_issue(): void
    {
        $decision = $this->baseDecision();
        $decision['source_article']['planned_figures'] = [$this->plannedFigure('img1')];
        $decision['concept_pages'] = [
            array_merge($this->conceptPageEntry('Change Management'), ['planned_figures' => [$this->plannedFigure('img1')]]),
            array_merge($this->conceptPageEntry('Problem Management'), ['planned_figures' => [$this->plannedFigure('img1')]]),
        ];

        $this->assertSame([], $this->validator()->findIssues($decision, []));
    }

    public function test_same_figure_on_article_summary_and_two_concept_pages_is_not_an_issue(): void
    {
        $decision = $this->baseDecision();
        $decision['source_article']['planned_figures'] = [$this->plannedFigure('img1', required: true)];
        $decision['source_summary']['planned_figures'] = [$this->plannedFigure('img1', required: false)];
        $decision['concept_pages'] = [
            array_merge($this->conceptPageEntry('Change Management'), ['planned_figures' => [$this->plannedFigure('img1')]]),
            array_merge($this->conceptPageEntry('Problem Management'), ['planned_figures' => [$this->plannedFigure('img1')]]),
        ];

        $this->assertSame([], $this->validator()->findIssues($decision, []));
    }

    public function test_same_figure_on_summary_and_an_entity_page_without_article_is_not_an_issue(): void
    {
        $decision = $this->baseDecision();
        $decision['source_summary']['planned_figures'] = [$this->plannedFigure('img1')];
        $decision['entity_pages'] = [$this->entityPageEntry('Acme AS', figures: [$this->plannedFigure('img1')])];

        $this->assertSame([], $this->validator()->findIssues($decision, []));
    }

    // Same figure on four or more pages from the same document — article, summary, two concept
    // pages, and an entity page all showing it at once is legitimate.
    public function test_same_figure_on_four_or_more_pages_from_the_same_document_is_not_an_issue(): void
    {
        $decision = $this->baseDecision();
        $decision['source_article']['planned_figures'] = [$this->plannedFigure('img1', required: true)];
        $decision['source_summary']['planned_figures'] = [$this->plannedFigure('img1', required: false)];
        $decision['concept_pages'] = [
            array_merge($this->conceptPageEntry('Change Management'), ['planned_figures' => [$this->plannedFigure('img1')]]),
            array_merge($this->conceptPageEntry('Problem Management'), ['planned_figures' => [$this->plannedFigure('img1')]]),
        ];
        $decision['entity_pages'] = [$this->entityPageEntry('Acme AS', figures: [$this->plannedFigure('img1')])];

        $this->assertSame([], $this->validator()->findIssues($decision, []));
    }

    // The exact run-591 shape: article + one concept page — still no issue under the simplified
    // document-scoped rule.
    public function test_run_591_like_decision_has_no_conflict_issue(): void
    {
        $decision = $this->baseDecision();
        $decision['source_article']['planned_figures'] = [$this->plannedFigure('img1', required: false)];
        $decision['concept_pages'] = [
            array_merge($this->conceptPageEntry('Styrings- og samhandlingsmodell'), ['planned_figures' => [$this->plannedFigure('img1', required: true)]]),
        ];

        $this->assertSame([], $this->validator()->findIssues($decision, []));
    }

    // The exact run-593 shape: article + a different concept page than 591's — also no issue.
    public function test_run_593_like_decision_has_no_conflict_issue(): void
    {
        $decision = $this->baseDecision();
        $decision['source_article']['planned_figures'] = [$this->plannedFigure('img1', required: false)];
        $decision['concept_pages'] = [
            array_merge($this->conceptPageEntry('Samhandlingsarenaer'), ['planned_figures' => [$this->plannedFigure('img1', required: true)]]),
        ];

        $this->assertSame([], $this->validator()->findIssues($decision, []));
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    // =========================================================================
    // Run 18: "reference_only" is only valid when it actually points somewhere
    // =========================================================================

    public function test_reference_only_with_an_existing_owning_page_is_valid(): void
    {
        $decision = $this->baseDecision();
        $decision['concept_candidates'] = [
            $this->candidate('Hendelseshåndtering', 'reference_only', necessary: true, owningPageTitle: 'ITIL Incident Management'),
        ];

        $issues = $this->validator()->findIssues($decision, [['title' => 'ITIL Incident Management']]);

        $this->assertSame([], $issues);
    }

    public function test_reference_only_with_a_page_planned_in_the_same_decision_is_valid(): void
    {
        $decision = $this->baseDecision();
        $decision['concept_pages'] = [['title' => 'Endringsstyring', 'action' => 'create', 'page_id' => null]];
        $decision['concept_candidates'] = [
            $this->candidate('Endringsmøte (CAB)', 'reference_only', necessary: true, owningPageTitle: 'Endringsstyring'),
        ];

        $this->assertSame([], $this->validator()->findIssues($decision, []));
    }

    /**
     * The run-18 failure itself: the check only fired when necessary_for_article was true, so a
     * targetless reference_only slipped through whenever that flag was false — leaving the article
     * pointing onward to nothing.
     */
    public function test_reference_only_without_any_target_is_flagged_even_when_not_necessary_for_the_article(): void
    {
        $decision = $this->baseDecision();
        $decision['concept_candidates'] = [
            $this->candidate('Tjenesterapport (månedlig)', 'reference_only', necessary: false, owningPageTitle: null),
        ];

        $issues = $this->validator()->findIssues($decision, []);

        $this->assertNotEmpty($issues);
        $this->assertStringContainsString('Tjenesterapport (månedlig)', implode(' | ', $issues));
        $this->assertStringContainsString('reference_only', implode(' | ', $issues));
    }

    public function test_reference_only_naming_a_page_that_does_not_exist_anywhere_is_flagged(): void
    {
        $decision = $this->baseDecision();
        $decision['concept_candidates'] = [
            $this->candidate('Tilgjengelighet 99,5 %', 'reference_only', necessary: true, owningPageTitle: 'Tjenestenivåstyring'),
        ];

        $issues = $this->validator()->findIssues($decision, []);

        $this->assertNotEmpty($issues);
        $this->assertStringContainsString('Tilgjengelighet 99,5 %', implode(' | ', $issues));
    }

    /**
     * Aurora Serviceplattform is a named platform, so the valid resolution is the entity track —
     * entity_pages counts as a real owning target (see plannedTitles()), and routing it there
     * must satisfy the check without it ever becoming a concept page.
     */
    public function test_aurora_serviceplattform_is_satisfied_by_the_entity_track(): void
    {
        $decision = $this->baseDecision();
        $decision['entity_pages'] = [['title' => 'Aurora Serviceplattform', 'action' => 'create', 'page_id' => null]];
        $decision['concept_candidates'] = [
            $this->candidate('Aurora Serviceplattform', 'reference_only', necessary: true, owningPageTitle: 'Aurora Serviceplattform'),
        ];

        $this->assertSame([], $this->validator()->findIssues($decision, []));
        $this->assertSame([], $decision['concept_pages'], 'the platform must never become a concept page');
    }

    /**
     * A concrete threshold is a local fact: "exclude" is a legitimate resolution and must NOT be
     * flagged into becoming its own concept page just because the article needs the number.
     */
    public function test_a_concrete_threshold_may_be_excluded_without_being_forced_onto_its_own_page(): void
    {
        $decision = $this->baseDecision();
        $decision['concept_candidates'] = [
            // Excluded and not claimed to be necessary — the number lives in the article's text.
            $this->candidate('Tilgjengelighet 99,5 %', 'exclude', necessary: false, owningPageTitle: null),
        ];

        $this->assertSame([], $this->validator()->findIssues($decision, []));
        $this->assertSame([], $decision['concept_pages']);
    }

    public function test_exclude_still_needs_an_owning_page_when_the_article_is_said_to_need_it(): void
    {
        $decision = $this->baseDecision();
        $decision['concept_candidates'] = [
            $this->candidate('Tilgjengelighet 99,5 %', 'exclude', necessary: true, owningPageTitle: null),
        ];

        $issues = $this->validator()->findIssues($decision, []);

        $this->assertNotEmpty($issues);
        $this->assertStringContainsString('necessary for the article', implode(' | ', $issues));
    }

    private function baseDecision(): array
    {
        return [
            'source_article' => [
                'action' => 'create',
                'title' => 'Illustrasjon av Incident Management',
                'proposed_slug' => 'illustrasjon-incident-management-ab12cd',
                'reason' => 'New source document.',
            ],
            'source_summary' => [
                'action' => 'create',
                'title' => 'Sammendrag: Illustrasjon av Incident Management',
                'proposed_slug' => 'sammendrag-illustrasjon-incident-management-ab12cd',
                'reason' => 'Companion summary.',
            ],
            'concept_candidates' => [],
            'concept_pages' => [],
            'entity_pages' => [],
            'no_action_reason' => null,
            'warnings' => [],
        ];
    }

    private function conceptPageEntry(string $title, ?string $relatedTo = null): array
    {
        $entry = [
            'action' => 'create',
            'page_id' => null,
            'title' => $title,
            'proposed_slug' => strtolower(str_replace(' ', '-', $title)),
            'reason' => 'Concept page.',
        ];

        if ($relatedTo !== null) {
            $entry['related_page_guidance'] = [
                ['page_title' => $relatedTo, 'relationship' => 'Link here for detail.'],
            ];
        }

        return $entry;
    }

    private function entityPageEntry(string $title, array $figures = []): array
    {
        return [
            'action' => 'create',
            'page_id' => null,
            'title' => $title,
            'proposed_slug' => strtolower(str_replace(' ', '-', $title)),
            'reason' => 'Entity page.',
            'planned_figures' => $figures,
        ];
    }

    private function candidate(
        string $name,
        string $decision,
        bool $necessary = true,
        ?string $owningPageTitle = null,
    ): array {
        return [
            'name' => $name,
            'concept_type' => 'framework process',
            'independent_reason' => 'Independent subject-matter concept.',
            'mentioned_context' => 'Named explicitly in the source document.',
            'existing_page_title' => null,
            'decision' => $decision,
            'justification' => 'Test justification.',
            'owning_page_title' => $owningPageTitle,
            'necessary_for_article' => $necessary,
        ];
    }

    private function plannedFigure(string $sourceElementKey, bool $required = false): array
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
