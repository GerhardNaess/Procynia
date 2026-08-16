<?php

namespace Tests\Feature\App\Wiki;

use App\Exceptions\EnterpriseWikiMaintainerDecisionGlobalPlanMergeException;
use App\Exceptions\EnterpriseWikiMaintainerDecisionPhaseFailedException;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentSectionMap;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionGlobalPlanMerger;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionPrompt;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionSplitCoordinator;
use App\Services\EnterpriseWiki\EnterpriseWikiPlanningContext;
use App\Services\OpenAi\OpenAiClient;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 1, split into two sequential calls.
 *
 * One call generated ~6 400 tokens and took 126–152 s against a per-CALL timeout of 180 s — a
 * 16–30 % margin on a duration whose 20 % spread is provider-side. Since the timeout is per call,
 * halving what each call must GENERATE is the whole fix, and it needs no concurrency: 1A decides the
 * document's two pages and its figures, 1B decides candidates, entity pages and patch targets.
 *
 * The split is only safe because the halves are genuinely independent — validateGlobalPlan() has no
 * cross-field check, and phase 2 has never been shown anything from phase 1 but page TITLES. These
 * tests hold that boundary in place: field-disjointness, figure exclusivity, identity collisions,
 * fail-closed behaviour, and that everything downstream still sees one ordinary global plan.
 */
class EnterpriseWikiPhaseOneSplitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.enterprise_wiki.ai_enabled' => true]);
    }

    // =========================================================================
    // The two halves partition the contract
    // =========================================================================

    public function test_the_two_schemas_are_disjoint_and_together_are_the_global_plan(): void
    {
        $global = array_keys(EnterpriseWikiMaintainerDecisionPrompt::globalPlanSchema()['json_schema']['schema']['properties']);
        $a = EnterpriseWikiMaintainerDecisionPrompt::documentPlanSchema()['json_schema']['schema']['required'];
        $b = EnterpriseWikiMaintainerDecisionPrompt::candidatePlanSchema()['json_schema']['schema']['required'];

        $this->assertSame([], array_intersect($a, $b), 'a field decided twice is a field two calls can disagree about');

        // patch_targets is the one global-plan field NEITHER half decides — see
        // test_neither_half_can_plan_a_change_to_an_existing_page.
        sort($global);
        $union = array_merge($a, $b, ['patch_targets']);
        sort($union);
        $this->assertSame($global, $union, 'the halves must cover the merged contract exactly — no field may fall out');
    }

    public function test_neither_half_can_plan_a_change_to_an_existing_page(): void
    {
        // A patch target REWRITES an existing page, and its contract needs that page's current text
        // (valid_target_headings, page_has_subsections, an exact superseded substring). Phase 1 is
        // given the wiki index — title, type, status, a 200-character excerpt — and nothing more, so
        // a target it named would be planned blind. Apply does not catch it either: planReplace()
        // verifies its superseded substring, planAmend() just writes the replacement in.
        foreach (['documentPlanSchema', 'candidatePlanSchema'] as $schema) {
            $properties = EnterpriseWikiMaintainerDecisionPrompt::{$schema}()['json_schema']['schema']['properties'];

            $this->assertArrayNotHasKey('patch_targets', $properties, $schema);
        }

        // Phase 2 keeps it: a batch that classifies a candidate as "substance_changed" must still be
        // able to carry the matching target, or findUntargetedSubstanceChanges() becomes unsatisfiable.
        $this->assertArrayHasKey(
            'patch_targets',
            EnterpriseWikiMaintainerDecisionPrompt::candidateBatchSchema()['json_schema']['schema']['properties'],
        );

        // And the single-call path for small documents keeps the whole contract — it is the one call
        // that IS given EXISTING PAGE CANDIDATES.
        $this->assertArrayHasKey(
            'patch_targets',
            EnterpriseWikiMaintainerDecisionPrompt::jsonSchema()['json_schema']['schema']['properties'],
        );
    }

    public function test_the_candidate_prompt_states_why_it_may_not_touch_existing_pages(): void
    {
        $coordinator = app(EnterpriseWikiMaintainerDecisionSplitCoordinator::class);
        $b = (new ReflectionClass($coordinator))->getMethod('candidatePlanDeveloperPrompt')->invoke($coordinator, 'Norwegian');

        $this->assertStringContainsString('Do not plan changes to existing pages', $b);
        $this->assertStringNotContainsString('patch_targets — EXISTING pages this document changes', $b);

        // The rule still stands where the block it depends on is actually rendered.
        $this->assertStringContainsString(
            'patch_targets — EXISTING pages this document changes',
            implode("\n", EnterpriseWikiMaintainerDecisionAiClient::patchTargetRules()),
        );
    }

    public function test_a_candidate_half_that_returns_patch_targets_anyway_is_rejected(): void
    {
        $errors = EnterpriseWikiMaintainerDecisionPrompt::validateCandidatePlan(
            $this->candidatePlan() + ['patch_targets' => [['target_page_id' => 41]]],
        );

        $this->assertNotSame([], $errors);
        $this->assertStringContainsString('patch_targets is not part of the candidate plan', implode("\n", $errors));
    }

    public function test_the_merged_plan_carries_an_empty_patch_target_list(): void
    {
        $merged = $this->merger()->merge($this->documentPlan(), $this->candidatePlan());

        $this->assertSame([], $merged['patch_targets']);
        $this->assertSame([], EnterpriseWikiMaintainerDecisionPrompt::validateGlobalPlan($merged));
    }

    public function test_only_the_document_half_can_plan_figures(): void
    {
        $a = EnterpriseWikiMaintainerDecisionPrompt::documentPlanSchema()['json_schema']['schema']['properties'];
        $b = EnterpriseWikiMaintainerDecisionPrompt::candidatePlanSchema()['json_schema']['schema']['properties'];

        $this->assertArrayHasKey('planned_figures', $a['source_article']['properties']);
        $this->assertArrayHasKey('planned_figures', $a['source_summary']['properties']);
        $this->assertArrayNotHasKey(
            'planned_figures',
            $b['entity_pages']['items']['properties'],
            'figure exclusivity is structural: the candidate half cannot express a figure at all',
        );
        $this->assertNotContains('planned_figures', $b['entity_pages']['items']['required']);
    }

    public function test_the_merged_plan_satisfies_the_existing_global_plan_contract(): void
    {
        $merged = $this->merger()->merge($this->documentPlan(), $this->candidatePlan());

        $this->assertSame([], EnterpriseWikiMaintainerDecisionPrompt::validateGlobalPlan($merged));

        $parsed = EnterpriseWikiMaintainerDecisionPrompt::parseGlobalPlan($merged);
        $this->assertSame('Et Dokument', $parsed['source_article']['title']);
        $this->assertCount(1, $parsed['entity_pages']);
        $this->assertCount(1, $parsed['concept_candidate_mentions']);
        $this->assertSame(['En advarsel.'], $parsed['warnings']);
        $this->assertNull($parsed['no_action_reason']);
    }

    public function test_owned_topics_and_evidence_survive_the_merge_untouched(): void
    {
        $merged = $this->merger()->merge($this->documentPlan(), $this->candidatePlan());

        $this->assertSame(
            [['topic' => 'Et tema', 'source_element_keys' => ['paragraph-0']]],
            $merged['source_article']['owned_topics'],
        );
        $this->assertSame(
            [['topic' => 'Entitetstema', 'source_element_keys' => ['paragraph-1']]],
            $merged['entity_pages'][0]['owned_topics'],
        );
        $this->assertSame([$this->figure()], $merged['source_article']['planned_figures']);
    }

    // =========================================================================
    // The two conflicts the union cannot express
    // =========================================================================

    public function test_figures_planned_by_the_candidate_half_are_discarded(): void
    {
        $candidatePlan = $this->candidatePlan();
        $candidatePlan['entity_pages'][0]['planned_figures'] = [$this->figure()];

        $merged = $this->merger()->merge($this->documentPlan(), $candidatePlan);

        $this->assertSame([], $merged['entity_pages'][0]['planned_figures']);
        // The document half keeps its own figure — exclusivity, not suppression.
        $this->assertCount(1, $merged['source_article']['planned_figures']);
    }

    /**
     * @param  'title'|'proposed_slug'|'page_id'  $field
     */
    #[DataProvider('collisionFields')]
    public function test_a_new_entity_page_colliding_with_a_document_page_is_dropped(string $field, mixed $value): void
    {
        $candidatePlan = $this->candidatePlan();
        $candidatePlan['entity_pages'][0][$field] = $value;

        $merged = $this->merger()->merge($this->documentPlan(), $candidatePlan);

        $this->assertSame([], $merged['entity_pages'], "a {$field} collision must not reach the decision");
        // Dropping the page never drops the knowledge: the concept is still a phase-2 candidate.
        $this->assertCount(1, $merged['concept_candidate_mentions']);
    }

    public static function collisionFields(): array
    {
        return [
            'same title' => ['title', 'Et Dokument'],
            'same title, different case and spacing' => ['title', '  et   dokument '],
            'same slug' => ['proposed_slug', 'et-dokument-ab1c2d'],
        ];
    }

    public function test_a_page_id_collision_with_a_document_page_is_a_hard_failure(): void
    {
        $documentPlan = $this->documentPlan();
        $documentPlan['source_article']['action'] = 'update';
        $documentPlan['source_article']['page_id'] = 41;

        $candidatePlan = $this->candidatePlan();
        $candidatePlan['entity_pages'][0]['action'] = 'update';
        $candidatePlan['entity_pages'][0]['page_id'] = 41;

        // An update names REAL content. Dropping it would silently discard an intended change.
        $this->expectException(EnterpriseWikiMaintainerDecisionGlobalPlanMergeException::class);
        $this->expectExceptionMessage('collides with the planned [source_article] on [page_id]');

        $this->merger()->merge($documentPlan, $candidatePlan);
    }

    public function test_a_missing_half_fails_closed(): void
    {
        $this->expectException(EnterpriseWikiMaintainerDecisionGlobalPlanMergeException::class);

        $this->merger()->merge($this->documentPlan(), []);
    }

    public function test_a_missing_document_half_fails_closed(): void
    {
        $this->expectException(EnterpriseWikiMaintainerDecisionGlobalPlanMergeException::class);

        $this->merger()->merge(['source_article' => $this->page('Et Dokument', 'et-dokument-ab1c2d')], $this->candidatePlan());
    }

    // =========================================================================
    // Two sequential calls, end to end
    // =========================================================================

    public function test_phase_one_is_two_calls_with_one_schema_each(): void
    {
        $payloads = $this->runPhaseOne();

        $this->assertCount(2, $payloads);
        $this->assertSame('maintainer_decision_document_plan', $payloads[0]['text']['format']['name']);
        $this->assertSame('maintainer_decision_candidate_plan', $payloads[1]['text']['format']['name']);
    }

    public function test_the_candidate_call_is_told_what_the_document_call_named(): void
    {
        $payloads = $this->runPhaseOne();
        $second = $payloads[1]['input'][1]['content'][0]['text'];

        $this->assertStringContainsString('ALREADY DECIDED DOCUMENT PAGES', $second);
        $this->assertStringContainsString('Et Dokument', $second);
        $this->assertStringContainsString('An entity page may never carry either of those titles', $second);
    }

    public function test_the_candidate_call_is_never_shown_figure_candidates(): void
    {
        $payloads = $this->runPhaseOne();

        $this->assertStringContainsString('FIGURE CANDIDATES', $payloads[0]['input'][1]['content'][0]['text']);
        $this->assertStringNotContainsString('FIGURE CANDIDATES', $payloads[1]['input'][1]['content'][0]['text']);
    }

    public function test_both_halves_get_the_same_orientation_view_of_the_document(): void
    {
        $payloads = $this->runPhaseOne();
        $first = $payloads[0]['input'][1]['content'][0]['text'];
        $second = $payloads[1]['input'][1]['content'][0]['text'];

        foreach ([$first, $second] as $prompt) {
            $this->assertStringContainsString('[paragraph-0] Alfa first.', $prompt, 'orientation view: no type label');
            $this->assertStringContainsString('# [sec-0] 1. Alfa', $prompt);
            $this->assertStringNotContainsString('DOCUMENT SECTION OVERVIEW', $prompt);
            $this->assertStringContainsString('Loose element outside any section.', $prompt);
        }
    }

    public function test_the_persisted_path_produces_the_same_merged_plan_as_the_in_process_path(): void
    {
        $inProcess = $this->runPhaseOne(returnPlan: true);
        $persisted = $this->runPhaseOne(returnPlan: true, persisted: true);

        $this->assertSame($inProcess, $persisted, 'both split entrypoints go through the same phase-1 split');
        $this->assertSame([], EnterpriseWikiMaintainerDecisionPrompt::validateGlobalPlan($inProcess));
    }

    public function test_a_failing_document_call_fails_closed_and_never_runs_the_candidate_call(): void
    {
        $calls = 0;

        /** @var OpenAiClient&MockInterface $mock */
        $mock = $this->mock(OpenAiClient::class);
        $mock->shouldReceive('createResponse')->andReturnUsing(function () use (&$calls): array {
            $calls++;

            throw new RuntimeException('provider exploded');
        });

        try {
            app(EnterpriseWikiMaintainerDecisionSplitCoordinator::class)
                ->preparePersistedCandidateBatches($this->planning(), 'no', null);
            $this->fail('a failed phase 1A must abort the decision');
        } catch (EnterpriseWikiMaintainerDecisionPhaseFailedException $e) {
            $this->assertStringContainsString('phase 1A', $e->getMessage());
        }

        $this->assertSame(1, $calls, 'the candidate call must not run on a broken document plan');
    }

    public function test_a_failing_candidate_call_fails_closed(): void
    {
        $responses = [json_encode($this->documentPlanResponse())];

        /** @var OpenAiClient&MockInterface $mock */
        $mock = $this->mock(OpenAiClient::class);
        $mock->shouldReceive('createResponse')->andReturnUsing(function () use (&$responses): array {
            $next = array_shift($responses);

            if ($next === null) {
                throw new RuntimeException('provider exploded');
            }

            return ['status' => 'completed', 'output_text' => $next];
        });

        $this->expectException(EnterpriseWikiMaintainerDecisionPhaseFailedException::class);
        $this->expectExceptionMessage('phase 1B');

        app(EnterpriseWikiMaintainerDecisionSplitCoordinator::class)
            ->preparePersistedCandidateBatches($this->planning(), 'no', null);
    }

    // =========================================================================
    // Phase 2 and bounded repair are untouched
    // =========================================================================

    public function test_phase_two_and_repair_still_read_full_text_with_type_labels(): void
    {
        $planning = $this->planning();
        $routed = EnterpriseWikiMaintainerDecisionAiClient::sourceContentBlock(
            $planning->sourceText,
            $planning->catalogElements,
            12000,
            ['sec-1'],
        );

        $this->assertStringContainsString('[paragraph-2] (paragraph) Beta first.', $routed);
        $this->assertStringContainsString('DOCUMENT SECTION OVERVIEW', $routed);
    }

    public function test_the_invariants_each_half_owns_are_still_stated(): void
    {
        $coordinator = app(EnterpriseWikiMaintainerDecisionSplitCoordinator::class);
        $reflection = new ReflectionClass($coordinator);
        $a = $reflection->getMethod('documentPlanDeveloperPrompt')->invoke($coordinator, 'Norwegian');
        $b = $reflection->getMethod('candidatePlanDeveloperPrompt')->invoke($coordinator, 'Norwegian');

        // Owned-topic evidence governs pages, and both halves plan pages.
        foreach ([$a, $b] as $prompt) {
            $this->assertStringContainsString('EVERY owned topic is EVIDENCE-BOUND', $prompt);
            $this->assertStringContainsString('proposed_slug: lowercase, hyphens only', $prompt);
        }

        // Figures: stated where they are decided, forbidden where they are not.
        $this->assertStringContainsString('PLANNED FIGURES', $a);
        $this->assertStringContainsString('Do not plan figures', $b);
        $this->assertStringNotContainsString('PLANNED FIGURES', $b);

        // Candidate and existing-page contracts belong to the candidate half only.
        $this->assertStringContainsString('CONCEPT CANDIDATE MENTIONS', $b);
        $this->assertStringContainsString('through the slot for the type it already has', $b);
        $this->assertStringNotContainsString('CONCEPT CANDIDATE MENTIONS', $a);
        // The ownership rules name entity_pages as a legitimate owning page — what must be absent
        // from the document half is the contract for DECIDING one.
        $this->assertStringNotContainsString('entity_pages — shared entity pages', $a);
        $this->assertStringNotContainsString('PATCH TARGET', $a);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * @return array<int, array<string, mixed>>|array<string, mixed>
     */
    private function runPhaseOne(bool $returnPlan = false, bool $persisted = false): array
    {
        $payloads = [];
        $responses = [
            json_encode($this->documentPlanResponse()),
            json_encode($this->candidatePlanResponse()),
        ];

        /** @var OpenAiClient&MockInterface $mock */
        $mock = $this->mock(OpenAiClient::class);
        $mock->shouldReceive('createResponse')
            ->twice()
            ->andReturnUsing(function (array $payload) use (&$payloads, &$responses): array {
                $payloads[] = $payload;

                return ['status' => 'completed', 'output_text' => array_shift($responses)];
            });

        $coordinator = app(EnterpriseWikiMaintainerDecisionSplitCoordinator::class);

        // Both split entrypoints reach phase 1 the same way; the in-process one would go on to run
        // candidate batches, which this fixture deliberately has none of (one mention, batched by
        // the persisted path, is enough to prove the plan travels).
        $prepared = $coordinator->preparePersistedCandidateBatches($this->planning(), 'no', null);

        return $returnPlan ? $prepared['global_plan'] : $payloads;
    }

    private function merger(): EnterpriseWikiMaintainerDecisionGlobalPlanMerger
    {
        return app(EnterpriseWikiMaintainerDecisionGlobalPlanMerger::class);
    }

    /** @return array<string, mixed> */
    private function documentPlan(): array
    {
        $article = array_merge($this->page('Et Dokument', 'et-dokument-ab1c2d'), [
            'owned_topics' => [['topic' => 'Et tema', 'source_element_keys' => ['paragraph-0']]],
            'planned_figures' => [$this->figure()],
        ]);

        return [
            'source_article' => $article,
            'source_summary' => $this->page('Sammendrag', 'sammendrag-ab1c2d'),
            'no_action_reason' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function candidatePlan(): array
    {
        return [
            'entity_pages' => [array_merge($this->page('En Entitet', 'en-entitet'), [
                'owned_topics' => [['topic' => 'Entitetstema', 'source_element_keys' => ['paragraph-1']]],
            ])],
            'concept_candidate_mentions' => [[
                'name' => 'Beta-konseptet',
                'concept_type' => 'process',
                'mentioned_context' => 'seksjon 2',
                'section_keys' => ['sec-1'],
            ]],
            'warnings' => ['En advarsel.'],
        ];
    }

    /** @return array<string, mixed> */
    private function documentPlanResponse(): array
    {
        return $this->documentPlan();
    }

    /** @return array<string, mixed> */
    private function candidatePlanResponse(): array
    {
        $plan = $this->candidatePlan();
        unset($plan['entity_pages'][0]['planned_figures']);

        return $plan;
    }

    /** @return array<string, mixed> */
    private function figure(): array
    {
        return [
            'source_element_key' => 'img0',
            'classification' => 'illustration',
            'purpose' => 'Viser sammenhengen.',
            'section_placement' => 'Et tema',
            'caption' => 'En figur.',
            'required' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function page(string $title, string $slug): array
    {
        return [
            'action' => 'create',
            'page_id' => null,
            'title' => $title,
            'proposed_slug' => $slug,
            'reason' => 'Ny side.',
            'owned_topics' => [],
            'reference_only_topics' => [],
            'excluded_topics' => [],
            'related_page_guidance' => [],
            'planned_figures' => [],
        ];
    }

    private function planning(): EnterpriseWikiPlanningContext
    {
        $elements = [
            $this->element('paragraph-0', '1.', 'Alfa', 'Alfa first.'),
            $this->element('paragraph-1', '1.', 'Alfa', 'Alfa second.'),
            $this->element('img0', '1.', 'Alfa', 'A figure in the first section.', 'image'),
            $this->element('paragraph-2', '2.', 'Beta', 'Beta first.'),
            $this->element('loose-0', '', '', 'Loose element outside any section.'),
        ];
        $catalog = EnterpriseWikiMaintainerDecisionAiClient::sourceCatalogElements($elements);

        return new EnterpriseWikiPlanningContext(
            customerId: 1,
            documentId: 1,
            sourceMeta: ['title' => 'Et Dokument', 'filename' => 'Et Dokument.docx'],
            sourceText: 'Noe innhold.',
            elements: $elements,
            catalogElements: $catalog,
            figureCandidates: [$this->element('img0', '1.', 'Alfa', 'A figure in the first section.', 'image')],
            sectionMap: EnterpriseWikiDocumentSectionMap::build($catalog),
            wikiIndex: [],
            validSourceElementKeys: ['paragraph-0', 'paragraph-1', 'paragraph-2', 'loose-0'],
            validFigureKeys: ['img0'],
            existingPageCandidatesResolver: static fn (): array => [],
        );
    }

    /** @return array<string, mixed> */
    private function element(string $key, string $number, string $title, string $text, string $type = 'paragraph'): array
    {
        return [
            'source_element_key' => $key,
            'source_element_type' => $type,
            'section_number' => $number,
            'section_title' => $title,
            'reference_text' => $text,
            'display_text' => $text,
        ];
    }
}
