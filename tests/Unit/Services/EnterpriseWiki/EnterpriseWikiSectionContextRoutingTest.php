<?php

namespace Tests\Unit\Services\EnterpriseWiki;

use App\Services\EnterpriseWiki\EnterpriseWikiDocumentSectionMap;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionPrompt;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionSplitCoordinator;
use App\Services\EnterpriseWiki\EnterpriseWikiPlannedSectionEvidenceResolver;
use ReflectionClass;
use Tests\TestCase;

/**
 * Section-based context routing between phase 1 and phase 2.
 *
 * Profiling the planning phase found that 69 % of every phase-2 prompt was the complete element
 * catalog (76 945 of 112 300 characters on a 77 586-character document), that the same catalog went
 * out in all nine planning calls, and that only ~1.4 % of a batch prompt differed between batches.
 * Routing gives each batch the sections its own candidates were placed in — using the document's own
 * structure and keys phase 1 cites explicitly, never similarity or keyword matching.
 *
 * The invariant every test here defends: routing may narrow what a call SEES, never what it can
 * KNOW ABOUT. The overview lists every section, section-less elements are unconditional, and
 * anything unroutable falls back to the whole catalog.
 */
class EnterpriseWikiSectionContextRoutingTest extends TestCase
{
    // =========================================================================
    // The section map itself
    // =========================================================================

    public function test_sections_are_keyed_deterministically_in_document_order(): void
    {
        $map = EnterpriseWikiDocumentSectionMap::build($this->elements());

        $this->assertSame(['sec-0', 'sec-1', 'sec-2'], EnterpriseWikiDocumentSectionMap::sectionKeys($map));
        $this->assertSame('1. Alfa', $map['sections'][0]['label']);
        $this->assertSame(['paragraph-0', 'paragraph-1', 'img0'], $map['sections'][0]['element_keys']);
        $this->assertSame(['loose-0'], $map['sectionless_element_keys']);

        // Stable across rebuilds — the routing keys behave like the element keys they sit next to.
        $this->assertSame($map, EnterpriseWikiDocumentSectionMap::build($this->elements()));
    }

    public function test_a_candidate_in_one_section_gets_that_sections_full_text(): void
    {
        $block = $this->routedCatalog(['sec-1']);

        $this->assertStringContainsString('[paragraph-2] (paragraph) Beta first.', $block);
        $this->assertStringContainsString('[paragraph-3] (paragraph) Beta second.', $block);
        $this->assertStringNotContainsString('Alfa first.', $block);
        $this->assertStringNotContainsString('Gamma first.', $block);
    }

    public function test_a_candidate_spanning_several_sections_gets_all_of_them(): void
    {
        $block = $this->routedCatalog(['sec-0', 'sec-2']);

        $this->assertStringContainsString('Alfa first.', $block);
        $this->assertStringContainsString('Gamma first.', $block);
        $this->assertStringNotContainsString('Beta first.', $block);
    }

    public function test_sectionless_elements_are_always_included(): void
    {
        foreach ([['sec-0'], ['sec-1'], ['sec-2']] as $keys) {
            $this->assertStringContainsString(
                'Loose element outside any section.',
                $this->routedCatalog($keys),
                'an element that belongs to no section can never be routed in, so it is unconditional',
            );
        }
    }

    public function test_the_section_overview_is_always_present_and_marks_what_is_shown(): void
    {
        $block = $this->routedCatalog(['sec-1']);

        $this->assertStringContainsString('DOCUMENT SECTION OVERVIEW (3 sections):', $block);
        $this->assertStringContainsString('[sec-0] 1. Alfa (2 elements', $block);
        $this->assertStringContainsString('[sec-2] 3. Gamma (1 elements', $block);
        // Exactly the routed section is marked as included.
        $this->assertMatchesRegularExpression('/\[sec-1\][^\n]*full text below/', $block);
        $this->assertDoesNotMatchRegularExpression('/\[sec-0\][^\n]*full text below/', $block);
        $this->assertStringContainsString('never assume a topic is absent', $block);
    }

    public function test_the_full_catalog_still_carries_every_section_and_its_keys(): void
    {
        // Phase 1 keeps seeing everything — routing exists to serve phase 2, not to narrow phase 1.
        $block = EnterpriseWikiMaintainerDecisionAiClient::sourceContentBlock('', $this->elements(), 12000);

        foreach (['Alfa first.', 'Beta first.', 'Gamma first.', 'Loose element outside any section.'] as $text) {
            $this->assertStringContainsString($text, $block);
        }

        $this->assertStringContainsString('# [sec-0] 1. Alfa', $block, 'section keys must be citable from the catalog');
        $this->assertStringContainsString('# [sec-2] 3. Gamma', $block);
    }

    public function test_routing_shrinks_the_prompt_but_never_the_addressable_document(): void
    {
        $full = EnterpriseWikiMaintainerDecisionAiClient::sourceContentBlock('', $this->elements(), 12000);
        $routed = $this->routedCatalog(['sec-1']);

        $this->assertLessThan(mb_strlen($full), mb_strlen($routed));

        // Every section is still NAMED even when only one is shown in full: the two unrouted ones
        // appear once each (overview only), the routed one twice (overview + catalog heading).
        $this->assertSame(1, substr_count($routed, '] 1. Alfa'));
        $this->assertSame(1, substr_count($routed, '] 3. Gamma'));
        $this->assertSame(2, substr_count($routed, '] 2. Beta'));
    }

    // =========================================================================
    // Phase 2 batch routing
    // =========================================================================

    public function test_a_batch_prompt_carries_only_its_own_candidates_sections(): void
    {
        $prompt = $this->batchPrompt([$this->mention('Beta-konseptet', ['sec-1'])]);

        $this->assertStringContainsString('Beta first.', $prompt);
        $this->assertStringNotContainsString('Alfa first.', $prompt);
        $this->assertStringContainsString('Loose element outside any section.', $prompt);
        $this->assertStringContainsString('DOCUMENT SECTION OVERVIEW', $prompt);
    }

    public function test_a_batch_unions_the_sections_of_all_its_candidates(): void
    {
        $prompt = $this->batchPrompt([
            $this->mention('Alfa-konseptet', ['sec-0']),
            $this->mention('Gamma-konseptet', ['sec-2', 'sec-1']),
        ]);

        foreach (['Alfa first.', 'Beta first.', 'Gamma first.'] as $text) {
            $this->assertStringContainsString($text, $prompt);
        }
    }

    public function test_a_batch_with_no_routable_candidate_falls_back_to_the_whole_catalog(): void
    {
        // A stored plan predating section keys, or a phase 1 that could not place anything: routing
        // may only ever narrow context when the routing information is really there.
        foreach ([[], ['sec-does-not-exist']] as $keys) {
            $prompt = $this->batchPrompt([$this->mention('Uplassert', $keys)]);

            foreach (['Alfa first.', 'Beta first.', 'Gamma first.'] as $text) {
                $this->assertStringContainsString($text, $prompt);
            }
        }
    }

    public function test_the_batch_prompt_tells_the_model_it_may_not_see_everything(): void
    {
        $coordinator = app(EnterpriseWikiMaintainerDecisionSplitCoordinator::class);
        $developer = (new ReflectionClass($coordinator))
            ->getMethod('candidateBatchDeveloperPrompt')
            ->invoke($coordinator, 'Norwegian');

        $this->assertStringContainsString('YOU MAY NOT BE SEEING THE WHOLE DOCUMENT', $developer);
        $this->assertStringContainsString('choose the more', $developer);
    }

    public function test_existing_page_and_figure_context_still_reaches_the_batch(): void
    {
        $prompt = $this->batchPrompt([$this->mention('Beta-konseptet', ['sec-1'])]);

        $this->assertStringContainsString('EXISTING WIKI INDEX', $prompt);
        $this->assertStringContainsString('En Eksisterende Side', $prompt);
        $this->assertStringContainsString('FIGURE CANDIDATES', $prompt);
        $this->assertStringContainsString('img0', $prompt, 'a figure outside the routed section must still be offerable');
        $this->assertStringContainsString('ALREADY PLANNED PAGES FROM PHASE 1', $prompt);
        $this->assertStringContainsString('CANDIDATES TO DECIDE IN THIS BATCH', $prompt);
    }

    // =========================================================================
    // Phase 1 contract — routing metadata, not a decision
    // =========================================================================

    public function test_the_mention_contract_carries_section_keys(): void
    {
        $schema = EnterpriseWikiMaintainerDecisionPrompt::globalPlanSchema()['json_schema']['schema'];
        $mention = $schema['properties']['concept_candidate_mentions']['items'];

        $this->assertSame(['name', 'concept_type', 'mentioned_context', 'section_keys'], $mention['required']);
        $this->assertSame(['type' => 'string'], $mention['properties']['section_keys']['items']);
    }

    public function test_a_stored_plan_without_section_keys_still_parses(): void
    {
        $plan = EnterpriseWikiMaintainerDecisionPrompt::parseGlobalPlan([
            'source_article' => $this->page('Et Dokument', 'et-dokument-ab1c2d'),
            'source_summary' => $this->page('Sammendrag', 'sammendrag-ab1c2d'),
            'entity_pages' => [],
            'patch_targets' => [],
            'concept_candidate_mentions' => [['name' => 'X', 'concept_type' => 'process', 'mentioned_context' => 'seksjon 2']],
            'no_action_reason' => null,
            'warnings' => [],
        ]);

        $this->assertCount(1, $plan['concept_candidate_mentions']);
        $this->assertSame([], EnterpriseWikiMaintainerDecisionPrompt::mentionSectionKeys($plan['concept_candidate_mentions'][0]));
    }

    public function test_section_keys_are_normalised_and_never_trusted_blindly(): void
    {
        $this->assertSame(
            ['sec-1', 'sec-4'],
            EnterpriseWikiMaintainerDecisionPrompt::mentionSectionKeys([
                'section_keys' => [' sec-1 ', '', 'sec-1', 'sec-4', 42],
            ]),
        );
    }

    // =========================================================================
    // Bounded repair uses the same routing
    // =========================================================================

    public function test_a_repair_group_is_routed_by_the_evidence_its_objects_cite(): void
    {
        $prompt = $this->repairPrompt(
            ['topic' => 'Beta-temaet', 'source_element_keys' => ['paragraph-2']],
            ['concept_pages[0]'],
        );

        $this->assertStringContainsString('Beta first.', $prompt);
        $this->assertStringNotContainsString('Alfa first.', $prompt);
        $this->assertStringContainsString('Loose element outside any section.', $prompt);
        $this->assertStringContainsString('DOCUMENT SECTION OVERVIEW', $prompt);
    }

    public function test_a_repair_group_that_cites_nothing_gets_the_whole_catalog(): void
    {
        // This is the "topic has no evidence" fault itself — the repair has to be able to go
        // looking, so it must not be routed to a slice derived from the evidence it lacks.
        $prompt = $this->repairPrompt(['topic' => 'Ubundet tema', 'source_element_keys' => []], ['concept_pages[0]']);

        foreach (['Alfa first.', 'Beta first.', 'Gamma first.'] as $text) {
            $this->assertStringContainsString($text, $prompt);
        }
    }

    // =========================================================================
    // Downstream contracts are untouched
    // =========================================================================

    public function test_evidence_binding_still_resolves_against_the_full_element_set(): void
    {
        // Generation never routes: the evidence resolver keeps seeing every element, so a topic
        // bound to a section that phase 2 did not read in full still binds.
        $sections = app(EnterpriseWikiPlannedSectionEvidenceResolver::class)->resolve(
            [['topic' => 'Gamma-temaet', 'source_element_keys' => ['paragraph-4']]],
            $this->elements(),
        );

        $this->assertSame(['paragraph-4'], $sections[0]['source_element_keys']);
        $this->assertSame(
            EnterpriseWikiPlannedSectionEvidenceResolver::BINDING_PLANNER,
            $sections[0]['evidence_binding'],
        );
        $this->assertSame([], app(EnterpriseWikiPlannedSectionEvidenceResolver::class)->topicsWithoutEvidence($sections));
    }

    public function test_routing_never_hides_a_figure_from_the_planner(): void
    {
        $prompt = $this->batchPrompt([$this->mention('Beta-konseptet', ['sec-1'])]);

        // img0 belongs to section 1. Alfa, which this batch was NOT routed to — the figure contract
        // is a separate, whole-document contract and must not become a casualty of prompt routing.
        // Compact JSON — representation only; the figure contract itself is unchanged.
        $this->assertStringContainsString('"source_element_key":"img0"', $prompt);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** @param list<string> $sectionKeys */
    private function routedCatalog(array $sectionKeys): string
    {
        return EnterpriseWikiMaintainerDecisionAiClient::sourceContentBlock('', $this->elements(), 12000, $sectionKeys);
    }

    /** @param list<array<string, mixed>> $mentions */
    private function batchPrompt(array $mentions): string
    {
        $coordinator = app(EnterpriseWikiMaintainerDecisionSplitCoordinator::class);

        return (new ReflectionClass($coordinator))->getMethod('candidateBatchUserPrompt')->invoke(
            $coordinator,
            ['title' => 'Et Dokument', 'filename' => 'Et Dokument.docx'],
            '',
            [['id' => 7, 'title' => 'En Eksisterende Side', 'slug' => 'en-eksisterende-side', 'page_type' => 'concept', 'status' => 'draft', 'excerpt' => 'Noe innhold.', 'open_lint_count' => 0, 'updated_at' => null]],
            [
                'source_article' => $this->page('Et Dokument', 'et-dokument-ab1c2d'),
                'source_summary' => $this->page('Sammendrag', 'sammendrag-ab1c2d'),
                'entity_pages' => [],
            ],
            $mentions,
            $this->figures(),
            $this->elements(),
        );
    }

    /**
     * @param  array<string, mixed>  $ownedTopic
     * @param  list<string>  $objectIds
     */
    private function repairPrompt(array $ownedTopic, array $objectIds): string
    {
        $decision = [
            'source_article' => $this->page('Et Dokument', 'et-dokument-ab1c2d'),
            'source_summary' => $this->page('Sammendrag', 'sammendrag-ab1c2d'),
            'concept_candidates' => [],
            'concept_pages' => [array_merge($this->page('Et Konsept', 'et-konsept'), [
                'page_id' => null,
                'owned_topics' => [$ownedTopic],
            ])],
            'entity_pages' => [],
            'patch_targets' => [],
            'no_action_reason' => null,
            'warnings' => [],
        ];

        $client = app(EnterpriseWikiMaintainerDecisionAiClient::class);
        $sectionKeys = (new ReflectionClass($client))->getMethod('repairSectionKeys')->invoke(
            null,
            $decision,
            ['object_ids' => $objectIds, 'issues' => ['issue']],
            $this->elements(),
        );

        return EnterpriseWikiMaintainerDecisionAiClient::sourceContentBlock('', $this->elements(), 12000, $sectionKeys);
    }

    /** @param list<string> $sectionKeys */
    private function mention(string $name, array $sectionKeys): array
    {
        return [
            'name' => $name,
            'concept_type' => 'process',
            'mentioned_context' => 'seksjon',
            'section_keys' => $sectionKeys,
        ];
    }

    private function page(string $title, string $slug): array
    {
        return [
            'action' => 'create',
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

    /** @return list<array<string, mixed>> */
    private function elements(): array
    {
        return [
            $this->element('paragraph-0', '1.', 'Alfa', 'Alfa first.'),
            $this->element('paragraph-1', '1.', 'Alfa', 'Alfa second.'),
            $this->element('img0', '1.', 'Alfa', 'A figure in the first section.', 'image'),
            $this->element('paragraph-2', '2.', 'Beta', 'Beta first.'),
            $this->element('paragraph-3', '2.', 'Beta', 'Beta second.'),
            $this->element('paragraph-4', '3.', 'Gamma', 'Gamma first.'),
            $this->element('loose-0', '', '', 'Loose element outside any section.'),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function figures(): array
    {
        return array_values(array_filter(
            $this->elements(),
            static fn (array $element): bool => $element['source_element_type'] === 'image',
        ));
    }

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
