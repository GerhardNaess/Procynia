<?php

namespace Tests\Unit\Services\EnterpriseWiki;

use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionPrompt;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionSplitCoordinator;
use App\Services\EnterpriseWiki\EnterpriseWikiPlannedSectionEvidenceResolver;
use App\Services\EnterpriseWiki\EnterpriseWikiPlannedTopicEvidenceValidator;
use Tests\TestCase;

/**
 * The plan-to-evidence contract: an owned topic names the source elements it will be explained
 * from, that binding is checked at DECISION time, and generation binds by those keys instead of
 * guessing.
 *
 * Run 53 is the regression these tests exist for. Five concept pages planned sections
 * ("Godkjenningstidspunkt", "Budsjett og kostnadsrammer", "Fordeler og ulemper", "Insentiver og
 * kostnadsdeling", "Avhengigheter og milepæler") and a keyword heuristic then had to guess which of
 * 515 real source elements each one meant. It scored zero for four of them — the document says
 * "kostnadsramme" where the topic said "kostnadsrammer", and "godkjenning" where the topic said
 * "Godkjenningstidspunkt" — so the run died one expensive page-generation call at a time, after
 * planning had already succeeded.
 *
 * Everything below is domain-free: no topic name, heading or document term is special-cased.
 */
class EnterpriseWikiPlannedTopicEvidenceContractTest extends TestCase
{
    // =========================================================================
    // Decision-time gate — before any page-generation call exists
    // =========================================================================

    public function test_a_topic_bound_to_a_real_element_is_accepted(): void
    {
        $decision = $this->decisionWithTopics([
            $this->topic('Godkjenningstidspunkt', ['paragraph-7']),
        ]);

        $this->assertSame([], $this->validator()->findIssues($decision, $this->catalogKeys()));
    }

    public function test_a_topic_with_no_evidence_is_rejected_at_decision_time(): void
    {
        $decision = $this->decisionWithTopics([
            $this->topic('Insentiver og kostnadsdeling', []),
        ]);

        $issues = $this->validator()->findIssues($decision, $this->catalogKeys());

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('owns topic [Insentiver og kostnadsdeling]', $issues[0]);
        $this->assertStringContainsString('without naming any source element', $issues[0]);
        // Attributable to a specific decision object, so the bounded delta repair can fix exactly
        // this page instead of the run failing.
        $this->assertStringContainsString('concept_pages[0]', $issues[0]);
    }

    public function test_a_topic_citing_a_key_outside_the_catalog_is_rejected(): void
    {
        $decision = $this->decisionWithTopics([
            $this->topic('Budsjett og kostnadsrammer', ['paragraph-9999']),
        ]);

        $issues = $this->validator()->findIssues($decision, $this->catalogKeys());

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('paragraph-9999', $issues[0]);
        $this->assertStringContainsString("not in this document's source catalog", $issues[0]);
    }

    public function test_several_ungrounded_topics_on_one_page_are_each_reported(): void
    {
        $decision = $this->decisionWithTopics([
            $this->topic('Kjennetegn ved fastpris', ['paragraph-7']),
            $this->topic('Fordeler og ulemper', []),
            $this->topic('Risikoallokering', ['paragraph-9999']),
        ]);

        $issues = $this->validator()->findIssues($decision, $this->catalogKeys());

        $this->assertCount(2, $issues, 'the grounded topic must not be flagged');
        $this->assertStringContainsString('Fordeler og ulemper', $issues[0]);
        $this->assertStringContainsString('Risikoallokering', $issues[1]);
    }

    /** The run-53 shape, generically: five pages, each with one unsupported planned section. */
    public function test_the_run_53_shape_is_caught_before_generation_for_every_page(): void
    {
        $decision = $this->baseDecision();
        $decision['concept_pages'] = [];

        foreach (['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon'] as $index => $name) {
            $decision['concept_pages'][] = [
                'action' => 'create',
                'page_id' => null,
                'title' => "Concept {$name}",
                'proposed_slug' => 'concept-'.mb_strtolower($name),
                'reason' => 'Reusable concept.',
                'owned_topics' => [
                    $this->topic("Grounded topic {$name}", ['paragraph-'.$index]),
                    $this->topic("Plausible but unsupported {$name}", []),
                ],
                'reference_only_topics' => [],
                'excluded_topics' => [],
                'related_page_guidance' => [],
                'planned_figures' => [],
            ];
        }

        $issues = $this->validator()->findIssues($decision, $this->catalogKeys());

        $this->assertCount(5, $issues, 'one issue per unsupported section, all before any generation call');

        foreach (['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon'] as $index => $name) {
            $this->assertStringContainsString("concept_pages[{$index}]", $issues[$index]);
            $this->assertStringContainsString("Plausible but unsupported {$name}", $issues[$index]);
        }
    }

    public function test_a_legacy_string_topic_is_never_retroactively_flagged(): void
    {
        // A decision stored before the binding existed still parses and still validates.
        $decision = $this->decisionWithTopics(['Godkjenningstidspunkt', 'Roller og ansvar']);

        $this->assertSame([], $this->validator()->findIssues($decision, $this->catalogKeys()));
    }

    public function test_the_catalog_check_is_skipped_when_the_caller_has_no_catalog(): void
    {
        // A non-DOCX import exposes no addressable elements; inventing a failure from missing
        // context is exactly what the patch-target rule refuses to do, and so does this one.
        $decision = $this->decisionWithTopics([$this->topic('Formål', ['manual-0'])]);

        $this->assertSame([], $this->validator()->findIssues($decision, []));
    }

    public function test_the_gate_covers_every_page_slot_not_just_concepts(): void
    {
        $decision = $this->baseDecision();
        $decision['source_article']['owned_topics'] = [$this->topic('Ungrounded article topic', [])];
        $decision['source_summary']['owned_topics'] = [$this->topic('Ungrounded summary topic', [])];
        $decision['entity_pages'] = [[
            'action' => 'create',
            'page_id' => null,
            'title' => 'Some Organisation',
            'proposed_slug' => 'some-organisation',
            'reason' => 'Entity in the document.',
            'owned_topics' => [$this->topic('Ungrounded entity topic', [])],
            'reference_only_topics' => [],
            'excluded_topics' => [],
            'related_page_guidance' => [],
            'planned_figures' => [],
        ]];
        $decision['concept_pages'] = [];

        $issues = $this->validator()->findIssues($decision, $this->catalogKeys());

        $this->assertCount(3, $issues);
        $this->assertStringContainsString('source_article', $issues[0]);
        $this->assertStringContainsString('source_summary', $issues[1]);
        $this->assertStringContainsString('entity_pages[0]', $issues[2]);
    }

    // =========================================================================
    // Schema contract
    // =========================================================================

    public function test_the_schema_requires_a_topic_and_its_evidence(): void
    {
        $items = EnterpriseWikiMaintainerDecisionPrompt::jsonSchema()['json_schema']['schema']['properties']['source_article']['properties']['owned_topics']['items'];

        $this->assertSame('object', $items['type']);
        $this->assertSame(['topic', 'source_element_keys'], $items['required']);
        $this->assertFalse($items['additionalProperties']);
    }

    public function test_validation_rejects_a_bound_topic_without_keys(): void
    {
        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate(
            $this->decisionWithTopics([['topic' => 'Fordeler og ulemper', 'source_element_keys' => []]])
        );

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('at least one source element', implode(' | ', $errors));
    }

    public function test_validation_rejects_a_topic_object_without_a_name(): void
    {
        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate(
            $this->decisionWithTopics([['topic' => '  ', 'source_element_keys' => ['paragraph-1']]])
        );

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('topic is required', implode(' | ', $errors));
    }

    public function test_both_shapes_normalise_through_one_accessor(): void
    {
        $mixed = [
            'Legacy topic',
            ['topic' => ' Bound topic ', 'source_element_keys' => [' paragraph-1 ', '', 'paragraph-1', 'paragraph-2']],
            ['topic' => ''],
        ];

        $this->assertSame(
            ['Legacy topic', 'Bound topic'],
            EnterpriseWikiMaintainerDecisionPrompt::ownedTopicNames($mixed),
        );
        $this->assertSame(
            [
                ['topic' => 'Legacy topic', 'source_element_keys' => []],
                ['topic' => 'Bound topic', 'source_element_keys' => ['paragraph-1', 'paragraph-2']],
            ],
            EnterpriseWikiMaintainerDecisionPrompt::ownedTopicEntries($mixed),
        );
    }

    // =========================================================================
    // Generation-time binding — a lookup, never a guess
    // =========================================================================

    public function test_generation_binds_exactly_the_elements_the_planner_named(): void
    {
        $sections = $this->resolver()->resolve(
            [$this->topic('Godkjenningstidspunkt', ['paragraph-7', 'paragraph-2'])],
            $this->sourceElements(),
        );

        $this->assertCount(1, $sections);
        $this->assertSame(['paragraph-7', 'paragraph-2'], $sections[0]['source_element_keys'], 'planner order is preserved');
        $this->assertSame(EnterpriseWikiPlannedSectionEvidenceResolver::BINDING_PLANNER, $sections[0]['evidence_binding']);
        $this->assertSame([], $this->resolver()->topicsWithoutEvidence($sections));
    }

    /**
     * The exact failure mode run 53 hit: wording that no literal keyword match can bridge
     * ("kostnadsrammer" vs the document's "kostnadsramme", a compound vs its parts). With explicit
     * binding the wording is irrelevant — which is why this needs no synonym table, no stemming and
     * no heading rules.
     */
    public function test_a_semantically_worded_heading_binds_when_the_planner_named_the_evidence(): void
    {
        foreach ([
            'Budsjett og kostnadsrammer',
            'Godkjenningstidspunkt',
            'Fordeler og ulemper',
            'Insentiver og kostnadsdeling',
        ] as $heading) {
            $sections = $this->resolver()->resolve(
                [$this->topic($heading, ['paragraph-3'])],
                $this->sourceElements(),
            );

            $this->assertSame([], $this->resolver()->topicsWithoutEvidence($sections), $heading);
            $this->assertSame($heading, $sections[0]['required_heading']);
        }
    }

    public function test_unicode_and_punctuation_in_a_heading_do_not_affect_binding(): void
    {
        $sections = $this->resolver()->resolve(
            [$this->topic('Måloppnåelse – «krav» (nivå 2): ①', ['paragraph-1'])],
            $this->sourceElements(),
        );

        $this->assertSame([], $this->resolver()->topicsWithoutEvidence($sections));
        $this->assertSame('Måloppnåelse – «krav» (nivå 2): ①', $sections[0]['planned_topic']);
    }

    public function test_a_key_that_does_not_resolve_leaves_the_section_without_evidence(): void
    {
        $sections = $this->resolver()->resolve(
            [$this->topic('Topic bound to a vanished element', ['paragraph-9999'])],
            $this->sourceElements(),
        );

        $this->assertSame(0, $sections[0]['source_element_count']);
        $this->assertSame(
            ['Topic bound to a vanished element'],
            $this->resolver()->topicsWithoutEvidence($sections),
            'no fabricated fallback — the run must stop rather than generate an ungrounded section',
        );
    }

    public function test_several_sections_on_one_page_each_keep_their_own_evidence(): void
    {
        $sections = $this->resolver()->resolve([
            $this->topic('First topic', ['paragraph-1']),
            $this->topic('Second topic', ['paragraph-2', 'paragraph-3']),
            $this->topic('Third topic', ['paragraph-7']),
        ], $this->sourceElements());

        $this->assertSame(['paragraph-1'], $sections[0]['source_element_keys']);
        $this->assertSame(['paragraph-2', 'paragraph-3'], $sections[1]['source_element_keys']);
        $this->assertSame(['paragraph-7'], $sections[2]['source_element_keys']);
        $this->assertSame([0, 1, 2], array_column($sections, 'section_index'));
    }

    public function test_no_evidence_leaks_between_sections(): void
    {
        $sections = $this->resolver()->resolve([
            $this->topic('Alpha', ['paragraph-1']),
            $this->topic('Beta', ['paragraph-2']),
        ], $this->sourceElements());

        foreach ($sections as $section) {
            foreach ($section['source_evidence'] as $element) {
                $this->assertContains(
                    $element['source_element_key'],
                    $section['source_element_keys'],
                    'a section may only carry the evidence assigned to it',
                );
            }
        }

        $this->assertSame(['paragraph-1'], $sections[0]['source_element_keys']);
        $this->assertNotContains('paragraph-2', $sections[0]['source_element_keys']);
        $this->assertNotContains('paragraph-1', $sections[1]['source_element_keys']);
    }

    /**
     * The existing multi-element semantics, unchanged: the same source element may support more than
     * one section, exactly as the same key may authorise more than one patch target and the same
     * figure may be planned onto more than one page. Each section still gets its own bounded copy —
     * sharing an element is not sharing a section's evidence set.
     */
    public function test_the_same_element_may_support_more_than_one_section(): void
    {
        $sections = $this->resolver()->resolve([
            $this->topic('Alpha', ['paragraph-1']),
            $this->topic('Beta', ['paragraph-1', 'paragraph-2']),
        ], $this->sourceElements());

        $this->assertSame(['paragraph-1'], $sections[0]['source_element_keys']);
        $this->assertSame(['paragraph-1', 'paragraph-2'], $sections[1]['source_element_keys']);
        $this->assertSame([], $this->resolver()->topicsWithoutEvidence($sections));
    }

    public function test_a_repeated_key_within_one_section_is_bound_once(): void
    {
        $sections = $this->resolver()->resolve(
            [$this->topic('Alpha', ['paragraph-1', 'paragraph-1'])],
            $this->sourceElements(),
        );

        $this->assertSame(['paragraph-1'], $sections[0]['source_element_keys']);
        $this->assertSame(1, $sections[0]['source_element_count']);
    }

    public function test_a_legacy_string_topic_still_uses_the_old_keyword_path(): void
    {
        // Kept only so stored decisions remain regenerable — and labelled, so a run can be told
        // apart from one made under the current contract.
        $sections = $this->resolver()->resolve(['Behandling av avvik'], $this->sourceElements());

        $this->assertSame(EnterpriseWikiPlannedSectionEvidenceResolver::BINDING_LEGACY_KEYWORD, $sections[0]['evidence_binding']);
    }

    // =========================================================================
    // Planner and validator must state one and the same rule
    // =========================================================================

    public function test_every_planning_prompt_states_the_evidence_binding_rule(): void
    {
        $rules = implode("\n", EnterpriseWikiMaintainerDecisionAiClient::ownedTopicEvidenceRules());

        $this->assertStringContainsString('EVERY owned topic is EVIDENCE-BOUND', $rules);
        $this->assertStringContainsString('At least one, copied exactly, never invented', $rules);
        $this->assertStringContainsString('do NOT own it', $rules);

        // The single-call prompt, the split-flow batch prompt and the bounded repair prompt all
        // carry it — the same drift that made run 51's owning-page issues unrepairable.
        $client = app(EnterpriseWikiMaintainerDecisionAiClient::class);
        $reflection = new \ReflectionClass($client);

        $single = $reflection->getMethod('developerPrompt')->invoke($client, 'Norwegian');
        $this->assertStringContainsString('EVERY owned topic is EVIDENCE-BOUND', $single);

        $coordinator = app(EnterpriseWikiMaintainerDecisionSplitCoordinator::class);
        $coordinatorReflection = new \ReflectionClass($coordinator);
        $globalPlan = $coordinatorReflection->getMethod('globalPlanDeveloperPrompt')->invoke($coordinator, 'Norwegian');
        $batch = $coordinatorReflection->getMethod('candidateBatchDeveloperPrompt')->invoke($coordinator, 'Norwegian');

        $this->assertStringContainsString('EVERY owned topic is EVIDENCE-BOUND', $globalPlan);
        $this->assertStringContainsString('EVERY owned topic is EVIDENCE-BOUND', $batch);

        $this->assertStringContainsString(
            'EVERY owned topic is EVIDENCE-BOUND',
            implode("\n", EnterpriseWikiMaintainerDecisionAiClient::repairResolutionRules()),
        );
    }

    public function test_the_prompt_rules_stay_domain_free(): void
    {
        $rules = mb_strtolower(implode("\n", EnterpriseWikiMaintainerDecisionAiClient::ownedTopicEvidenceRules()));

        foreach (['testplan', 'kostnadsstyring', 'fastpris', 'målpris', 'prosjektplan', 'godkjenningstidspunkt'] as $term) {
            $this->assertStringNotContainsString($term, $rules, "the contract must not name a concrete concept ({$term})");
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function validator(): EnterpriseWikiPlannedTopicEvidenceValidator
    {
        return app(EnterpriseWikiPlannedTopicEvidenceValidator::class);
    }

    private function resolver(): EnterpriseWikiPlannedSectionEvidenceResolver
    {
        return app(EnterpriseWikiPlannedSectionEvidenceResolver::class);
    }

    /** @param list<string> $keys */
    private function topic(string $topic, array $keys): array
    {
        return ['topic' => $topic, 'source_element_keys' => $keys];
    }

    /** @return list<string> */
    private function catalogKeys(): array
    {
        return array_column($this->sourceElements(), 'source_element_key');
    }

    /**
     * Domain-free elements in the shape EnterpriseWikiDocumentSourceElementService::inspect()
     * returns them. The texts deliberately share no words with the topic names used above: under
     * the old keyword path none of these tests could pass, which is the point.
     *
     * @return list<array<string, mixed>>
     */
    private function sourceElements(): array
    {
        $elements = [];

        foreach (range(0, 9) as $index) {
            $elements[] = [
                'source_element_key' => "paragraph-{$index}",
                'source_element_type' => 'paragraph',
                'section_number' => '2.',
                'section_title' => 'Section two',
                'reference_text' => "Body text number {$index} with substantive content.",
                'display_text' => "Body text number {$index}.",
            ];
        }

        return $elements;
    }

    /** @param list<string|array<string, mixed>> $topics */
    private function decisionWithTopics(array $topics): array
    {
        $decision = $this->baseDecision();
        $decision['concept_pages'][0]['owned_topics'] = $topics;

        return $decision;
    }

    private function baseDecision(): array
    {
        return [
            'source_article' => $this->page('Masterdata Prosjekt', 'masterdata-prosjekt-ab1c2d'),
            'source_summary' => $this->page('Sammendrag: Masterdata Prosjekt', 'sammendrag-masterdata-prosjekt-ab1c2d'),
            'concept_candidates' => [],
            'concept_pages' => [array_merge($this->page('Et Konsept', 'et-konsept'), ['page_id' => null])],
            'entity_pages' => [],
            'patch_targets' => [],
            'no_action_reason' => null,
            'warnings' => [],
        ];
    }

    private function page(string $title, string $slug): array
    {
        return [
            'action' => 'create',
            'title' => $title,
            'proposed_slug' => $slug,
            'reason' => 'New page for this source.',
            'owned_topics' => [$this->topic('Grounded topic', ['paragraph-0'])],
            'reference_only_topics' => [],
            'excluded_topics' => [],
            'related_page_guidance' => [],
            'planned_figures' => [],
        ];
    }

    /**
     * The escape hatch the first runtime verification of this contract walked straight into: told to
     * bind every owned topic, the planner created 14 concept pages that owned nothing at all.
     */
    public function test_a_created_concept_page_that_owns_nothing_is_rejected(): void
    {
        $decision = $this->decisionWithTopics([]);

        $issues = $this->validator()->findIssues($decision, $this->catalogKeys());

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('created without owning a single topic', $issues[0]);
        $this->assertStringContainsString('concept_pages[0]', $issues[0]);
    }

    public function test_an_updated_concept_page_may_add_no_scope(): void
    {
        $decision = $this->decisionWithTopics([]);
        $decision['concept_pages'][0]['action'] = 'update';
        $decision['concept_pages'][0]['page_id'] = 42;

        $this->assertSame([], $this->validator()->findIssues($decision, $this->catalogKeys()));
    }

    public function test_an_entity_page_without_owned_topics_stays_valid(): void
    {
        // Entity pages have always been allowed to carry no owned topics — see the generation waves
        // in FinalizeEnterpriseWikiPageGeneration. This contract does not change that.
        $decision = $this->baseDecision();
        $decision['concept_pages'] = [];
        $decision['entity_pages'] = [[
            'action' => 'create',
            'page_id' => null,
            'title' => 'Some Organisation',
            'proposed_slug' => 'some-organisation',
            'reason' => 'Entity in the document.',
            'owned_topics' => [],
            'reference_only_topics' => [],
            'excluded_topics' => [],
            'related_page_guidance' => [],
            'planned_figures' => [],
        ]];

        $this->assertSame([], $this->validator()->findIssues($decision, $this->catalogKeys()));
    }

    public function test_the_prompt_forbids_owning_nothing_as_a_way_around_the_binding(): void
    {
        $rules = implode("\n", EnterpriseWikiMaintainerDecisionAiClient::ownedTopicEvidenceRules());

        $this->assertStringContainsString('must own at least one evidence-bound topic', $rules);
        $this->assertStringContainsString('Owning nothing is not a way', $rules);
        $this->assertStringContainsString('normally 1-6', $rules, 'the planner must name the few carrying elements, not a whole section');
    }

    /**
     * Both halves of the responsibility policy must reach the prompt that actually creates concept
     * pages. Carrying only the evidence half there is what produced 14 scope-less pages.
     */
    public function test_the_batch_prompt_states_both_halves_of_the_policy(): void
    {
        $coordinator = app(EnterpriseWikiMaintainerDecisionSplitCoordinator::class);
        $batch = (new \ReflectionClass($coordinator))
            ->getMethod('candidateBatchDeveloperPrompt')
            ->invoke($coordinator, 'Norwegian');

        $this->assertStringContainsString('EVERY owned topic is EVIDENCE-BOUND', $batch);
        $this->assertStringContainsString('owned_topics: what THIS page, and only this page, explains in depth', $batch);
        $this->assertStringContainsString('typically 1-3 items', $batch);
    }
}
