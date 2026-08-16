<?php

namespace Tests\Unit\Services\EnterpriseWiki;

use App\Services\EnterpriseWiki\EnterpriseWikiGenerateAppliedPagesService;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionConsistencyValidator;
use App\Services\EnterpriseWiki\EnterpriseWikiPlannedFigureCoverageValidator;
use App\Services\EnterpriseWiki\EnterpriseWikiPlannedSectionCoverageValidator;
use Tests\TestCase;

/**
 * The planned-figure contract: a figure may only be planned into a section the page will actually
 * have, and what QA checks at the end must be decidable at planning time.
 *
 * Run 54 produced three different figure findings from one document, and none of them were figure
 * problems in the sense the finding names suggest:
 *  - img2 / page 188: planned into a named section on a SUMMARY page — a page type that renders no
 *    `## ` sections at all, so the deterministic placement had nowhere to insert and the figure was
 *    appended at the end. Reported as wrong_section, at the very end of the run.
 *  - img1 / page 193: planned onto the page, never cited by the model, therefore never materialized.
 *  - img3 / page 191: materialized correctly, then lost when a link repair promoted a version whose
 *    block reconstruction had failed (see EnterpriseWikiPageVersionWriter and its own tests).
 *
 * Domain-free throughout: no figure key, heading or document is special-cased.
 */
class EnterpriseWikiPlannedFigureContractTest extends TestCase
{
    // =========================================================================
    // Decision time — placement must be possible before anything is generated
    // =========================================================================

    public function test_a_figure_planned_under_one_of_the_pages_own_topics_is_valid(): void
    {
        $decision = $this->decisionWithConceptFigure(
            topics: ['Risikostyring i prosjekter'],
            placement: 'Risikostyring i prosjekter',
        );

        $this->assertSame([], $this->validator()->findIssues($decision, [], ['img3']));
    }

    public function test_a_figure_planned_under_a_section_the_page_does_not_own_is_rejected(): void
    {
        $decision = $this->decisionWithConceptFigure(
            topics: ['Risikostyring i prosjekter'],
            placement: 'En helt annen seksjon',
        );

        $issues = $this->validator()->findIssues($decision, [], ['img3']);

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('"img3"', $issues[0]);
        $this->assertStringContainsString('En helt annen seksjon', $issues[0]);
        $this->assertStringContainsString("not one of this page's owned topics", $issues[0]);
        $this->assertStringContainsString('concept_pages[0]', $issues[0], 'must be attributable for bounded repair');
    }

    /**
     * The run-54 img2 shape: a summary page renders no `## ` headings at all
     * (EnterpriseWikiPlannedSectionCoverageValidator::CHECKED_PAGE_TYPES), so ANY named placement
     * on one is unplaceable — regardless of what the page's topics say.
     */
    public function test_a_named_placement_on_a_page_type_without_sections_is_rejected(): void
    {
        $decision = $this->baseDecision();
        $decision['source_summary']['owned_topics'] = [['topic' => 'Hovedstruktur og faser', 'source_element_keys' => ['paragraph-0']]];
        $decision['source_summary']['planned_figures'] = [$this->figure('img2', 'Hovedstruktur og faser')];

        $issues = $this->validator()->findIssues($decision, [], ['img2']);

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('summary page has no sections', $issues[0]);
        $this->assertStringContainsString('set section_placement to null', $issues[0]);
        $this->assertNotContains('summary', EnterpriseWikiPlannedSectionCoverageValidator::CHECKED_PAGE_TYPES);
    }

    public function test_a_figure_without_a_named_section_is_always_valid(): void
    {
        $decision = $this->baseDecision();
        $decision['source_summary']['planned_figures'] = [$this->figure('img2', null)];
        $decision['concept_pages'][0]['planned_figures'] = [$this->figure('img3', null)];

        $this->assertSame([], $this->validator()->findIssues($decision, [], ['img2', 'img3']));
    }

    public function test_several_figures_on_one_page_are_each_checked(): void
    {
        $decision = $this->decisionWithConceptFigure(topics: ['Alfa', 'Beta'], placement: 'Alfa');
        $decision['concept_pages'][0]['planned_figures'][] = $this->figure('img4', 'Beta');
        $decision['concept_pages'][0]['planned_figures'][] = $this->figure('img5', 'Gamma');

        $issues = $this->validator()->findIssues($decision, [], ['img3', 'img4', 'img5']);

        $this->assertCount(1, $issues, 'only the placement that names no owned topic is flagged');
        $this->assertStringContainsString('"img5"', $issues[0]);
    }

    public function test_placement_matching_is_exact_not_fuzzy(): void
    {
        // Punctuation/case/whitespace drift is normalised (the same normalisation the rest of this
        // validator uses), but a different section is a different section — no fuzzy matching.
        $exact = $this->decisionWithConceptFigure(topics: ['Risikostyring i prosjekter'], placement: '  risikostyring i prosjekter  ');
        $different = $this->decisionWithConceptFigure(topics: ['Risikostyring i prosjekter'], placement: 'Risikostyring');

        $this->assertSame([], $this->validator()->findIssues($exact, [], ['img3']));
        $this->assertCount(1, $this->validator()->findIssues($different, [], ['img3']));
    }

    /** The run-54 shape, generically: three pages, three different unplaceable figures. */
    public function test_the_run_54_shape_is_caught_before_generation(): void
    {
        $decision = $this->baseDecision();
        $decision['source_summary']['owned_topics'] = [['topic' => 'Hovedstruktur', 'source_element_keys' => ['paragraph-0']]];
        $decision['source_summary']['planned_figures'] = [$this->figure('img2', 'Hovedstruktur')];
        $decision['concept_pages'][0]['owned_topics'] = [['topic' => 'Alfa', 'source_element_keys' => ['paragraph-0']]];
        $decision['concept_pages'][0]['planned_figures'] = [$this->figure('img3', 'En seksjon som ikke finnes')];
        $decision['entity_pages'] = [array_merge($this->page('Et Selskap', 'et-selskap', 'Organisering'), [
            'page_id' => null,
            'planned_figures' => [$this->figure('img1', 'En annen seksjon')],
        ])];

        $issues = $this->validator()->findIssues($decision, [], ['img1', 'img2', 'img3']);

        $this->assertCount(3, $issues);
        $this->assertStringContainsString('source_summary', $issues[0]);
        $this->assertStringContainsString('concept_pages[0]', $issues[1]);
        $this->assertStringContainsString('entity_pages[0]', $issues[2]);
    }

    // =========================================================================
    // Persisted-structure coverage — unchanged contract, re-stated
    // =========================================================================

    public function test_a_required_figure_in_its_planned_section_passes_coverage(): void
    {
        $issues = $this->coverage()->validate(
            [$this->figure('img3', 'Risikostyring', required: true)],
            "# Side\n\nIntro.\n\n## Risikostyring\n\nTekst.\n\n**Figur 4**\n_Kilde: dok → Figur 4_\n",
            [$this->imageBlock('img3')],
            ['img3'],
        );

        $this->assertSame([], $issues);
    }

    public function test_a_required_figure_with_no_block_at_all_is_blocking(): void
    {
        $issues = $this->coverage()->validate(
            [$this->figure('img3', 'Risikostyring', required: true)],
            "# Side\n\n## Risikostyring\n\nTekst.\n",
            [],
            ['img3'],
        );

        $this->assertCount(1, $issues);
        $this->assertSame(EnterpriseWikiPlannedFigureCoverageValidator::TYPE_MISSING, $issues[0]['type']);
        $this->assertTrue(EnterpriseWikiPlannedFigureCoverageValidator::isBlocking($issues[0]));
    }

    public function test_an_optional_figure_with_no_block_is_reported_but_not_blocking(): void
    {
        $issues = $this->coverage()->validate(
            [$this->figure('img1', 'Organisering', required: false)],
            "# Side\n\n## Organisering\n\nTekst.\n",
            [],
            ['img1'],
        );

        $this->assertCount(1, $issues);
        $this->assertSame(EnterpriseWikiPlannedFigureCoverageValidator::TYPE_MISSING, $issues[0]['type']);
        $this->assertFalse(EnterpriseWikiPlannedFigureCoverageValidator::isBlocking($issues[0]));
    }

    public function test_a_figure_outside_its_planned_section_is_wrong_section(): void
    {
        $issues = $this->coverage()->validate(
            [$this->figure('img2', 'Faser', required: true)],
            "# Side\n\n## Faser\n\nTekst.\n\n## Annet\n\n**Figur 3**\n_Kilde: dok → Figur 3_\n",
            [$this->imageBlock('img2', "**Figur 3**\n_Kilde: dok → Figur 3_")],
            ['img2'],
        );

        $this->assertCount(1, $issues);
        $this->assertSame(EnterpriseWikiPlannedFigureCoverageValidator::TYPE_WRONG_SECTION, $issues[0]['type']);
    }

    public function test_two_blocks_for_one_planned_figure_are_a_duplicate_and_always_blocking(): void
    {
        $issues = $this->coverage()->validate(
            [$this->figure('img2', null, required: false)],
            "# Side\n\n**Figur 3**\n_Kilde: dok → Figur 3_\n",
            [$this->imageBlock('img2', "**Figur 3**\n_Kilde: dok → Figur 3_"), $this->imageBlock('img2', "**Figur 3**\n_Kilde: dok → Figur 3_")],
            ['img2'],
        );

        $types = array_column($issues, 'type');

        $this->assertContains(EnterpriseWikiPlannedFigureCoverageValidator::TYPE_DUPLICATE, $types);
        $this->assertTrue(EnterpriseWikiPlannedFigureCoverageValidator::isBlocking($issues[0]));
    }

    public function test_several_figures_on_one_page_are_matched_independently(): void
    {
        $markdown = "# Side\n\n## Alfa\n\n**Figur 1**\n_Kilde: dok → Figur 1_\n\n## Beta\n\nTekst.\n";

        $issues = $this->coverage()->validate(
            [$this->figure('img1', 'Alfa', required: true), $this->figure('img2', 'Beta', required: true)],
            $markdown,
            [$this->imageBlock('img1', '**Figur 1**'.PHP_EOL.'_Kilde: dok → Figur 1_')],
            ['img1', 'img2'],
        );

        $this->assertCount(1, $issues, 'the correctly placed figure must not be flagged');
        $this->assertSame('img2', $issues[0]['source_element_key']);
        $this->assertSame(EnterpriseWikiPlannedFigureCoverageValidator::TYPE_MISSING, $issues[0]['type']);
    }

    public function test_heading_identity_is_preserved_when_a_figure_sits_under_it(): void
    {
        // The heading text itself is never rewritten to match a figure — placement is checked
        // against the heading as generated.
        $markdown = "# Side\n\n## Måloppnåelse – «krav» (nivå 2)\n\n**Figur 2**\n_Kilde: dok → Figur 2_\n";

        $issues = $this->coverage()->validate(
            [$this->figure('img2', 'Måloppnåelse – «krav» (nivå 2)', required: true)],
            $markdown,
            [$this->imageBlock('img2', "**Figur 2**\n_Kilde: dok → Figur 2_")],
            ['img2'],
        );

        $this->assertSame([], $issues);
        $this->assertStringContainsString('## Måloppnåelse – «krav» (nivå 2)', $markdown);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function validator(): EnterpriseWikiMaintainerDecisionConsistencyValidator
    {
        return app(EnterpriseWikiMaintainerDecisionConsistencyValidator::class);
    }

    private function coverage(): EnterpriseWikiPlannedFigureCoverageValidator
    {
        return app(EnterpriseWikiPlannedFigureCoverageValidator::class);
    }

    private function figure(string $key, ?string $placement, bool $required = false): array
    {
        return [
            'source_element_key' => $key,
            'classification' => 'image',
            'section_placement' => $placement,
            'purpose' => 'Viser sammenhengen.',
            'required' => $required,
            'caption_hint' => null,
        ];
    }

    private function imageBlock(string $key, string $markdown = "**Figur 4**\n_Kilde: dok → Figur 4_"): array
    {
        return [
            'block_type' => 'image',
            'source_element_key' => $key,
            'markdown' => $markdown,
            'image_data' => ['source_image_key' => $key, 'caption' => 'Figur', 'alt_text' => 'Alt'],
        ];
    }

    /** @param list<string> $topics */
    private function decisionWithConceptFigure(array $topics, ?string $placement): array
    {
        $decision = $this->baseDecision();
        $decision['concept_pages'][0]['owned_topics'] = array_map(
            static fn (string $topic): array => ['topic' => $topic, 'source_element_keys' => ['paragraph-0']],
            $topics,
        );
        $decision['concept_pages'][0]['planned_figures'] = [$this->figure('img3', $placement)];

        return $decision;
    }

    private function baseDecision(): array
    {
        return [
            'source_article' => $this->page('Et Dokument', 'et-dokument-ab1c2d', 'Hovedinnhold'),
            'source_summary' => $this->page('Sammendrag: Et Dokument', 'sammendrag-et-dokument-ab1c2d', 'Kort oversikt'),
            'concept_candidates' => [],
            'concept_pages' => [array_merge($this->page('Et Konsept', 'et-konsept', 'Omfang'), ['page_id' => null])],
            'entity_pages' => [],
            'patch_targets' => [],
            'no_action_reason' => null,
            'warnings' => [],
        ];
    }

    private function page(string $title, string $slug, string $topic): array
    {
        return [
            'action' => 'create',
            'title' => $title,
            'proposed_slug' => $slug,
            'reason' => 'Ny side for denne kilden.',
            'owned_topics' => [['topic' => $topic, 'source_element_keys' => ['paragraph-0']]],
            'reference_only_topics' => [],
            'excluded_topics' => [],
            'related_page_guidance' => [],
            'planned_figures' => [],
        ];
    }
    // =========================================================================
    // Materialization — a planned figure does not depend on the model citing it
    // =========================================================================

    /**
     * Run 54, page 193: img1 was planned onto the page, the model never mentioned it in prose, so
     * nothing materialized it and only QA at the very end noticed. A planned figure is a backend
     * decision about a real extracted element — the model's citation cannot be its trigger.
     */
    public function test_a_planned_figure_is_materialized_even_when_the_model_never_cited_it(): void
    {
        $service = app(EnterpriseWikiGenerateAppliedPagesService::class);
        $indexes = (new \ReflectionClass($service))
            ->getMethod('plannedImageIndexes')
            ->invoke($service, [$this->figure('img1', null), $this->figure('img3', 'Alfa')]);

        $this->assertSame([1, 3], $indexes);
    }

    public function test_a_planned_key_that_is_not_an_extracted_image_key_is_ignored_here(): void
    {
        // Not this step's job to invent a figure: a key of another shape is already rejected by the
        // decision-time validators (planned figure with no matching extracted figure).
        $service = app(EnterpriseWikiGenerateAppliedPagesService::class);
        $indexes = (new \ReflectionClass($service))
            ->getMethod('plannedImageIndexes')
            ->invoke($service, [$this->figure('paragraph-4', null), $this->figure('img2', null), $this->figure('img2', 'Alfa')]);

        $this->assertSame([2], $indexes, 'duplicates collapse and non-image keys are left alone');
    }
}
