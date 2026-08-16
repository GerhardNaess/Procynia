<?php

namespace Tests\Unit\Services\EnterpriseWiki;

use App\Services\EnterpriseWiki\EnterpriseWikiDocumentSectionMap;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionPrompt;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionSplitCoordinator;
use App\Services\EnterpriseWiki\EnterpriseWikiPlannedSectionEvidenceResolver;
use App\Services\EnterpriseWiki\EnterpriseWikiPlanningContext;
use ReflectionClass;
use Tests\TestCase;

/**
 * Phase 1 reads the whole document; phase 2 reads a few sections of it. Until now both paid the same
 * per-element price, so the call that already ran longest carried 79 726 characters of catalog for
 * text it was never going to read closely — 128,4 s against a 180 s timeout, on a 113 811-character
 * prompt.
 *
 * The ORIENTATION VIEW is that call's own view. It differs from the reading view in exactly three
 * ways, and each one is a redundancy, not a reduction in what phase 1 can know:
 *
 *  - 90-character snippets instead of 240. Every key stays addressable; the routed phase-2 batch
 *    that judges a section's substance still gets that section in full.
 *  - no "(type)" label, because keys are minted per type (paragraph-0, listitem-0, tbl0-row0) and
 *    the label restates the key it follows.
 *  - no section overview, because the overview exists to disclose what a call was NOT given, and
 *    phase 1 is given every section — each line would read "full text below" above the very
 *    "# [sec-N]" headers the catalog prints anyway.
 *
 * The fourth lever is on the output side: an owned topic may name at most
 * MAX_OWNED_TOPIC_EVIDENCE_KEYS evidence keys, because that is how many the generated section is
 * built from. One summary topic on the reference document named 24.
 *
 * What these tests defend is the boundary: phase 2, bounded repair and the figure contract must come
 * through byte-identical.
 */
class EnterpriseWikiPhaseAOrientationViewTest extends TestCase
{
    // =========================================================================
    // Every key stays addressable
    // =========================================================================

    public function test_the_orientation_view_keeps_every_element_key_addressable(): void
    {
        $elements = $this->manyElements(400);
        $block = $this->orientationCatalog($elements);

        foreach ($elements as $element) {
            $this->assertStringContainsString('['.$element['source_element_key'].']', $block);
        }

        $this->assertStringNotContainsString('further element(s) truncated', $block);
        $this->assertStringContainsString('SOURCE ELEMENTS (400 of 400):', $block);
    }

    public function test_no_element_is_dropped_when_the_document_is_large(): void
    {
        // 400 elements of ~600 characters is 240 000 characters of raw text — far past the catalog
        // budget at the reading length, comfortably inside it at the orientation length. This is the
        // failure mode the cap exists to prevent: elements silently falling off the end.
        $elements = $this->manyElements(400, 600);

        $this->assertStringNotContainsString('further element(s) truncated', $this->orientationCatalog($elements));
    }

    public function test_the_orientation_view_shortens_long_elements_but_not_short_ones(): void
    {
        $elements = [
            $this->element('paragraph-0', '1.', 'Alfa', 'Kort.'),
            $this->element('paragraph-1', '1.', 'Alfa', str_repeat('a', 300)),
        ];
        $block = $this->orientationCatalog($elements);

        $this->assertStringContainsString('[paragraph-0] Kort.', $block);
        $this->assertStringContainsString('[paragraph-1] '.str_repeat('a', 90).' […]', $block);
        $this->assertStringContainsString('the key still refers to the whole element', $block);
    }

    // =========================================================================
    // The three differences, stated as differences
    // =========================================================================

    public function test_the_orientation_view_drops_the_type_label_the_key_already_carries(): void
    {
        $block = $this->orientationCatalog($this->elements());

        $this->assertStringContainsString('[paragraph-0] Alfa first.', $block);
        $this->assertStringNotContainsString('(paragraph)', $block);
        $this->assertStringContainsString('[key] text', $block);
    }

    public function test_the_reading_view_keeps_the_type_label(): void
    {
        $block = EnterpriseWikiMaintainerDecisionAiClient::sourceContentBlock('', $this->elements(), 12000);

        $this->assertStringContainsString('[paragraph-0] (paragraph) Alfa first.', $block);
        $this->assertStringContainsString('[key] (type) text', $block);
    }

    public function test_the_orientation_view_omits_an_overview_that_would_withhold_nothing(): void
    {
        $block = $this->orientationCatalog($this->elements());

        $this->assertStringNotContainsString('DOCUMENT SECTION OVERVIEW', $block);

        // Every section is still named, keyed and in document order — that is what the overview
        // would have restated.
        foreach (['# [sec-0] 1. Alfa', '# [sec-1] 2. Beta', '# [sec-2] 3. Gamma'] as $header) {
            $this->assertStringContainsString($header, $block);
        }

        $this->assertStringContainsString('Loose element outside any section.', $block);
    }

    public function test_a_routed_view_still_gets_the_overview(): void
    {
        // Routing withholds sections, so the disclosure is load-bearing there and stays.
        $block = EnterpriseWikiMaintainerDecisionAiClient::sourceContentBlock('', $this->elements(), 12000, ['sec-1']);

        $this->assertStringContainsString('DOCUMENT SECTION OVERVIEW (3 sections):', $block);
        $this->assertStringNotContainsString('Alfa first.', $block);
    }

    // =========================================================================
    // Phase 2 and bounded repair are untouched
    // =========================================================================

    public function test_the_phase_two_routed_view_is_byte_identical_to_the_reading_view(): void
    {
        $elements = $this->elements();

        $this->assertSame(
            EnterpriseWikiMaintainerDecisionAiClient::sourceContentBlock('', $elements, 12000, ['sec-1'], false),
            EnterpriseWikiMaintainerDecisionAiClient::sourceContentBlock('', $elements, 12000, ['sec-1']),
        );
    }

    public function test_the_orientation_view_is_used_by_phase_one_and_by_nothing_else(): void
    {
        $coordinator = app(EnterpriseWikiMaintainerDecisionSplitCoordinator::class);
        $reflection = new ReflectionClass($coordinator);

        $phaseOne = $reflection->getMethod('documentPlanUserPrompt')->invoke($coordinator, $this->planning());

        $phaseTwo = $reflection->getMethod('candidateBatchUserPrompt')->invoke(
            $coordinator,
            ['title' => 'Et Dokument', 'filename' => 'Et Dokument.docx'],
            '',
            [],
            [
                'source_article' => $this->page('Et Dokument', 'et-dokument-ab1c2d'),
                'source_summary' => $this->page('Sammendrag', 'sammendrag-ab1c2d'),
                'entity_pages' => [],
            ],
            [$this->mention('Beta-konseptet', ['sec-1'])],
            $this->figures(),
            $this->elements(),
        );

        $this->assertStringNotContainsString('(paragraph)', $phaseOne);
        $this->assertStringNotContainsString('DOCUMENT SECTION OVERVIEW', $phaseOne);

        $this->assertStringContainsString('[paragraph-2] (paragraph) Beta first.', $phaseTwo);
        $this->assertStringContainsString('DOCUMENT SECTION OVERVIEW', $phaseTwo);
    }

    public function test_the_figure_contract_is_unchanged_by_the_orientation_view(): void
    {
        $coordinator = app(EnterpriseWikiMaintainerDecisionSplitCoordinator::class);
        $prompt = (new ReflectionClass($coordinator))->getMethod('documentPlanUserPrompt')->invoke($coordinator, $this->planning());

        // Figures are their own whole-document contract, rendered from the image elements and never
        // from the prose catalog — the orientation view does not touch them.
        $this->assertStringContainsString('FIGURE CANDIDATES', $prompt);
        $this->assertStringContainsString('"source_element_key":"img0"', $prompt);
        $this->assertSame(
            ['img0'],
            array_map(
                static fn (array $figure): string => (string) $figure['source_element_key'],
                $this->figures(),
            ),
        );
    }

    // =========================================================================
    // Compact JSON is representation only
    // =========================================================================

    public function test_compact_json_carries_exactly_the_same_facts(): void
    {
        $index = [
            ['id' => 7, 'title' => 'En Side', 'slug' => 'en-side', 'page_type' => 'concept', 'status' => 'draft', 'excerpt' => 'Æøå — "sitat" / skråstrek.', 'open_lint_count' => 0, 'updated_at' => null],
        ];

        $coordinator = app(EnterpriseWikiMaintainerDecisionSplitCoordinator::class);
        $compact = (new ReflectionClass($coordinator))->getMethod('indexContextJson')->invoke($coordinator, $index);

        $this->assertSame($index, json_decode($compact, true));
        $this->assertStringNotContainsString("\n", $compact);
        $this->assertStringContainsString('Æøå', $compact, 'unicode must stay unescaped');
        $this->assertStringNotContainsString('\/', $compact, 'slashes must stay unescaped');
    }

    public function test_an_empty_index_still_reads_as_prose(): void
    {
        $coordinator = app(EnterpriseWikiMaintainerDecisionSplitCoordinator::class);

        $this->assertSame(
            'No pages yet.',
            (new ReflectionClass($coordinator))->getMethod('indexContextJson')->invoke($coordinator, []),
        );
    }

    // =========================================================================
    // The evidence-key cap
    // =========================================================================

    public function test_the_key_cap_is_the_number_of_elements_a_section_is_built_from(): void
    {
        $this->assertSame(
            EnterpriseWikiPlannedSectionEvidenceResolver::MAX_ELEMENTS_PER_SECTION,
            EnterpriseWikiMaintainerDecisionPrompt::MAX_OWNED_TOPIC_EVIDENCE_KEYS,
        );
    }

    public function test_evidence_keys_beyond_the_cap_are_dropped_from_the_head_of_the_list(): void
    {
        $entries = EnterpriseWikiMaintainerDecisionPrompt::ownedTopicEntries([
            ['topic' => 'Et tema', 'source_element_keys' => array_map(
                static fn (int $i): string => 'paragraph-'.$i,
                range(0, 23),
            )],
        ]);

        $this->assertSame(
            ['paragraph-0', 'paragraph-1', 'paragraph-2', 'paragraph-3', 'paragraph-4', 'paragraph-5'],
            $entries[0]['source_element_keys'],
            'the planner names keys in reading order, so the cap keeps the head — never a sample',
        );
    }

    public function test_the_cap_applies_after_trimming_and_deduplication(): void
    {
        $entries = EnterpriseWikiMaintainerDecisionPrompt::ownedTopicEntries([
            ['topic' => 'Et tema', 'source_element_keys' => [
                ' paragraph-0 ', 'paragraph-0', '', 'paragraph-1', 'paragraph-1',
                'paragraph-2', 'paragraph-3', 'paragraph-4', 'paragraph-5', 'paragraph-6',
            ]],
        ]);

        // Six DISTINCT keys survive: duplicates must not consume the budget.
        $this->assertSame(
            ['paragraph-0', 'paragraph-1', 'paragraph-2', 'paragraph-3', 'paragraph-4', 'paragraph-5'],
            $entries[0]['source_element_keys'],
        );
    }

    public function test_a_topic_within_the_cap_is_untouched(): void
    {
        $entries = EnterpriseWikiMaintainerDecisionPrompt::ownedTopicEntries([
            ['topic' => 'Et tema', 'source_element_keys' => ['paragraph-4', 'paragraph-1']],
        ]);

        $this->assertSame(['paragraph-4', 'paragraph-1'], $entries[0]['source_element_keys']);
    }

    public function test_the_cap_is_not_a_validation_error(): void
    {
        // Naming too much evidence is verbose, not wrong. Turning it into an issue would spend a
        // bounded repair round, and its AI call, on something the backend settles for free.
        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate($this->decisionWithOwnedTopic([
            'topic' => 'Et tema',
            'source_element_keys' => array_map(static fn (int $i): string => 'paragraph-'.$i, range(0, 23)),
        ]));

        $this->assertSame([], $errors);
    }

    public function test_a_topic_with_no_evidence_at_all_is_still_an_error(): void
    {
        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate($this->decisionWithOwnedTopic([
            'topic' => 'Et tema',
            'source_element_keys' => [],
        ]));

        $this->assertNotSame([], $errors);
        $this->assertStringContainsString('source_element_keys must name at least one', implode("\n", $errors));
    }

    public function test_the_cap_is_stated_in_the_prompt_so_the_tokens_are_never_generated(): void
    {
        $rules = implode("\n", EnterpriseWikiMaintainerDecisionAiClient::ownedTopicEvidenceRules());

        $this->assertStringContainsString(
            'at most '.EnterpriseWikiMaintainerDecisionPrompt::MAX_OWNED_TOPIC_EVIDENCE_KEYS.' keys per topic',
            $rules,
        );
        // The rules are wrapped prompt lines, so match within one line.
        $this->assertStringContainsString('every later key is discarded', $rules);
    }

    public function test_the_evidence_invariants_survive_the_rewritten_rules(): void
    {
        $rules = implode("\n", EnterpriseWikiMaintainerDecisionAiClient::ownedTopicEvidenceRules());

        foreach ([
            'EVERY owned topic is EVIDENCE-BOUND',
            'At least one, copied exactly, never invented',
            'A topic you cannot bind to a real source element',
            'A page you CREATE must own at least one evidence-bound topic',
        ] as $invariant) {
            $this->assertStringContainsString($invariant, $rules);
        }
    }

    public function test_the_planned_figure_contract_names_one_key_and_is_not_capped(): void
    {
        $schema = EnterpriseWikiMaintainerDecisionPrompt::globalPlanSchema()['json_schema']['schema'];
        $figure = $schema['properties']['source_article']['properties']['planned_figures']['items'];

        $this->assertSame(['type' => 'string'], $figure['properties']['source_element_key']);
        $this->assertContains('source_element_key', $figure['required']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function planning(): EnterpriseWikiPlanningContext
    {
        $elements = $this->elements();

        return new EnterpriseWikiPlanningContext(
            customerId: 1,
            documentId: 1,
            sourceMeta: ['title' => 'Et Dokument', 'filename' => 'Et Dokument.docx'],
            sourceText: '',
            elements: $elements,
            catalogElements: EnterpriseWikiMaintainerDecisionAiClient::sourceCatalogElements($elements),
            figureCandidates: $this->figures(),
            sectionMap: EnterpriseWikiDocumentSectionMap::build(
                EnterpriseWikiMaintainerDecisionAiClient::sourceCatalogElements($elements),
            ),
            wikiIndex: [],
            validSourceElementKeys: [],
            validFigureKeys: ['img0'],
            existingPageCandidatesResolver: static fn (): array => [],
        );
    }

    /** @param list<array<string, mixed>> $elements */
    private function orientationCatalog(array $elements): string
    {
        return EnterpriseWikiMaintainerDecisionAiClient::sourceContentBlock('', $elements, 12000, null, true);
    }

    /** @return array<string, mixed> */
    private function decisionWithOwnedTopic(array $ownedTopic): array
    {
        return [
            'source_article' => array_merge($this->page('Et Dokument', 'et-dokument-ab1c2d'), ['owned_topics' => [$ownedTopic]]),
            'source_summary' => $this->page('Sammendrag', 'sammendrag-ab1c2d'),
            'concept_candidates' => [],
            'concept_pages' => [],
            'entity_pages' => [],
            'patch_targets' => [],
            'no_action_reason' => null,
            'warnings' => [],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function manyElements(int $count, int $chars = 120): array
    {
        $elements = [];

        for ($i = 0; $i < $count; $i++) {
            $elements[] = $this->element(
                'paragraph-'.$i,
                (string) (1 + intdiv($i, 20)).'.',
                'Seksjon '.(1 + intdiv($i, 20)),
                str_repeat('x', $chars).' '.$i,
            );
        }

        return $elements;
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
