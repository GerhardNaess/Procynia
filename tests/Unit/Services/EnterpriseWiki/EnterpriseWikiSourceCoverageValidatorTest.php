<?php

namespace Tests\Unit\Services\EnterpriseWiki;

use App\Services\EnterpriseWiki\EnterpriseWikiDocumentSectionMap;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionIssueAttributor;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionPrompt;
use App\Services\EnterpriseWiki\EnterpriseWikiPlannedSectionEvidenceResolver;
use App\Services\EnterpriseWiki\EnterpriseWikiSourceCoverageValidator;
use Tests\TestCase;

/**
 * Plan §18, P0-1, on the real run 962 material: the stored maintainer decision and the 51 source
 * elements of the test document (tests/Fixtures/EnterpriseWiki/run962). The planner bound 15
 * elements; the roles, the feedback loop and the pitfalls never reached a page and nothing noticed.
 */
class EnterpriseWikiSourceCoverageValidatorTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../../Fixtures/EnterpriseWiki/run962/';

    /**
     * The same element shape EnterpriseWikiDocumentSourceElementService::inspect() produces.
     *
     * @return list<array<string, mixed>>
     */
    private function elements(): array
    {
        $extraction = json_decode((string) file_get_contents(self::FIXTURES.'extraction.json'), true);

        return array_map(static fn (array $element): array => [
            'source_element_key' => $element['element_key'],
            'source_element_type' => $element['element_type'],
            'source_row_key' => null,
            'section_number' => $element['section_number'],
            'section_title' => $element['section_title'],
            'page_reference' => $element['section_title'],
            'reference_text' => $element['text'],
            'display_text' => mb_substr($element['text'], 0, 280),
        ], $extraction['text_elements']);
    }

    /** @return array<string, mixed> */
    private function decision(): array
    {
        return EnterpriseWikiMaintainerDecisionPrompt::parse(
            json_decode((string) file_get_contents(self::FIXTURES.'maintainer_decision.json'), true),
        );
    }

    private function validator(): EnterpriseWikiSourceCoverageValidator
    {
        return app(EnterpriseWikiSourceCoverageValidator::class);
    }

    /** @return array<string, array<string, mixed>> */
    private function sectionsByLabel(array $report): array
    {
        return array_column($report['sections'], null, 'label');
    }

    public function test_run_962_decision_loses_whole_sections_and_parts_of_others(): void
    {
        $report = $this->validator()->evaluate($this->decision(), $this->elements());
        $sections = $this->sectionsByLabel($report);

        $this->assertSame(EnterpriseWikiSourceCoverageValidator::STATUS_INCOMPLETE, $report['status']);

        // Knowledge that never reached a page in run 962.
        $this->assertSame('uncovered', $sections['Roller i gjennomgangen']['status']);
        $this->assertSame(['paragraph-5', 'paragraph-6'], $sections['Roller i gjennomgangen']['uncovered_element_keys']);
        $this->assertSame('uncovered', $sections['Tilbakeføring til kunnskap og trusselbilde']['status']);
        $this->assertSame('uncovered', $sections['Vanlige fallgruver']['status']);

        // Partly lost: cause/factor/barrier analysis, vulnerability and SOAR review.
        $analysis = $sections['Gjennomføring: fra forløp til årsaker'];
        $this->assertSame('partial', $analysis['status']);
        $this->assertSame(['paragraph-11', 'listitem-8', 'listitem-9', 'listitem-10', 'paragraph-13', 'paragraph-14'], $analysis['uncovered_element_keys']);
        $this->assertSame(['paragraph-18'], $sections['Verifisering av effekt']['uncovered_element_keys']);

        // The criteria list now follows its planned lead-in, so this section is complete.
        $this->assertSame('covered', $sections['Når en gjennomgang skal gjennomføres']['status']);
        // So does the three-source list behind paragraph-7.
        $this->assertNotContains('listitem-5', $sections['Forberedelse: rekonstruksjon av tidslinjen']['uncovered_element_keys']);

        // The fictitious example and the front matter are not excluded in the stored decision.
        $this->assertSame('uncovered', $sections['Illustrerende eksempel']['status']);

        $issues = $this->validator()->findIssues($this->decision(), $this->elements());
        $this->assertNotEmpty($issues);

        foreach ($issues as $issue) {
            $this->assertStringStartsWith(EnterpriseWikiSourceCoverageValidator::ISSUE_PREFIX, $issue);
            $this->assertStringContainsString(EnterpriseWikiSourceCoverageValidator::ATTRIBUTION_MARKER, $issue);
        }
    }

    public function test_coverage_issues_are_attributed_to_the_source_article_for_bounded_repair(): void
    {
        $decision = $this->decision();
        $issues = $this->validator()->findIssues($decision, $this->elements());

        $attribution = app(EnterpriseWikiMaintainerDecisionIssueAttributor::class)->attribute(
            $decision,
            $issues,
            fn (array $candidate): array => $this->validator()->findIssues($candidate, $this->elements()),
        );

        $this->assertSame([], $attribution['unattributed']);
        $this->assertCount(1, $attribution['groups']);
        $this->assertSame(['source_article'], $attribution['groups'][0]['object_ids']);
    }

    public function test_valid_exclusions_and_an_article_topic_per_section_complete_the_coverage(): void
    {
        $elements = $this->elements();
        $decision = $this->decision();
        $sectionKey = $this->sectionKeyFor($elements);

        $decision['source_article']['owned_topics'] = array_merge($decision['source_article']['owned_topics'], [
            ['topic' => 'Roller i gjennomgangen', 'source_element_keys' => ['paragraph-5', 'paragraph-6']],
            ['topic' => 'Rådata og loggbortfall', 'source_element_keys' => ['paragraph-9']],
            ['topic' => 'Årsaksanalyse', 'source_element_keys' => ['paragraph-11', 'paragraph-13', 'paragraph-14']],
            ['topic' => 'Retrospektiv trusseljakt', 'source_element_keys' => ['paragraph-18']],
            ['topic' => 'Tilbakeføring og deling', 'source_element_keys' => ['paragraph-19', 'paragraph-20']],
            ['topic' => 'Rapportering av indikatorer', 'source_element_keys' => ['paragraph-22', 'paragraph-23']],
            ['topic' => 'Vanlige fallgruver', 'source_element_keys' => ['listitem-19', 'listitem-20', 'listitem-21', 'listitem-22', 'listitem-23']],
        ]);
        $decision['source_article']['excluded_sections'] = [
            ['section_key' => $sectionKey('paragraph-0'), 'reason' => 'front_matter', 'justification' => 'Innledende notat om dokumentets status, ikke fagkunnskap.', 'existing_page_id' => null],
            ['section_key' => $sectionKey('paragraph-25'), 'reason' => 'example', 'justification' => 'Konstruert eksempel som bare illustrerer metoden.', 'existing_page_id' => null],
        ];

        $report = $this->validator()->evaluate($decision, $elements);

        $this->assertSame(EnterpriseWikiSourceCoverageValidator::STATUS_COMPLETE, $report['status'], json_encode($report['sections']));
        $this->assertCount(2, $report['excluded_section_keys']);
        $this->assertSame([], $this->validator()->findIssues($decision, $elements));
    }

    public function test_invalid_exclusions_are_rejected_and_do_not_hide_the_section(): void
    {
        $elements = $this->elements();
        $sectionKey = $this->sectionKeyFor($elements);
        $decision = $this->decision();
        $decision['source_article']['excluded_sections'] = [
            ['section_key' => 'sec-99', 'reason' => 'example', 'justification' => 'Finnes ikke i dokumentet i det hele tatt.', 'existing_page_id' => null],
            ['section_key' => $sectionKey('paragraph-5'), 'reason' => 'out_of_scope', 'justification' => 'kort', 'existing_page_id' => null],
            ['section_key' => $sectionKey('paragraph-1'), 'reason' => 'front_matter', 'justification' => 'Formålet er bare innledning til notatet.', 'existing_page_id' => null],
            ['section_key' => $sectionKey('paragraph-19'), 'reason' => 'duplicate_of_existing', 'justification' => 'Står allerede på siden om trusseletterretning.', 'existing_page_id' => null],
        ];

        $report = $this->validator()->evaluate($decision, $elements, [72]);
        $problems = array_column($report['invalid_exclusions'], 'problem', 'section_key');

        $this->assertStringContainsString('no such section', $problems['sec-99']);
        $this->assertStringContainsString('justification', $problems[$sectionKey('paragraph-5')]);
        $this->assertStringContainsString('also cited', $problems[$sectionKey('paragraph-1')]);
        $this->assertStringContainsString('existing_page_id', $problems[$sectionKey('paragraph-19')]);

        $sections = array_column($report['sections'], null, 'section_key');
        $this->assertSame('uncovered', $sections[$sectionKey('paragraph-5')]['status'], 'An invalid exclusion never hides the gap.');
        $this->assertSame([], $report['excluded_section_keys']);

        $decision['source_article']['excluded_sections'] = [
            ['section_key' => $sectionKey('paragraph-19'), 'reason' => 'duplicate_of_existing', 'justification' => 'Står allerede på siden om trusseletterretning.', 'existing_page_id' => 72],
        ];
        $this->assertSame([], $this->validator()->evaluate($decision, $elements, [72])['invalid_exclusions']);
    }

    public function test_excluding_most_of_the_document_is_not_a_way_around_coverage(): void
    {
        $elements = $this->elements();
        $decision = $this->decision();
        $decision['source_article']['owned_topics'] = [['topic' => 'Formål', 'source_element_keys' => ['paragraph-1']]];
        $decision['concept_pages'] = [];
        $decision['source_article']['excluded_sections'] = array_values(array_map(
            static fn (string $key): array => ['section_key' => $key, 'reason' => 'out_of_scope', 'justification' => 'Ikke relevant for denne Wikien etter vår vurdering.', 'existing_page_id' => null],
            array_values(array_unique(array_filter(
                array_map($this->sectionKeyFor($elements), array_column($elements, 'source_element_key')),
                fn (string $key): bool => $key !== $this->sectionKeyFor($elements)('paragraph-1'),
            ))),
        ));

        $report = $this->validator()->evaluate($decision, $elements);

        $this->assertTrue($report['excessive_exclusion']);
        $this->assertSame(EnterpriseWikiSourceCoverageValidator::STATUS_INCOMPLETE, $report['status']);
    }

    public function test_unstructured_documents_and_no_action_decisions_are_not_applicable(): void
    {
        $manual = [['source_element_key' => 'whole_document', 'source_element_type' => 'manual', 'reference_text' => 'Hele dokumentet.']];

        $this->assertSame('not_applicable', $this->validator()->evaluate($this->decision(), $manual)['status']);
        $this->assertSame('not_applicable', $this->validator()->evaluate(['source_article' => [], 'no_action_reason' => 'x'], $this->elements())['status']);
        $this->assertSame([], $this->validator()->findIssues(['source_article' => []], $this->elements()));
    }

    public function test_a_lead_in_brings_its_list_and_a_list_item_brings_its_lead_in(): void
    {
        $resolver = app(EnterpriseWikiPlannedSectionEvidenceResolver::class);

        $bound = $resolver->bindPlannedElements(['paragraph-3'], $this->elements());
        $this->assertSame(
            ['paragraph-3', 'listitem-0', 'listitem-1', 'listitem-2', 'listitem-3', 'listitem-4'],
            array_column($bound['elements'], 'source_element_key'),
        );
        $this->assertSame([], $bound['omitted_keys']);

        $bound = $resolver->bindPlannedElements(['listitem-9'], $this->elements());
        $this->assertSame(['paragraph-11', 'listitem-9'], array_column($bound['elements'], 'source_element_key'));

        // A paragraph that does not end with ":" brings nothing.
        $bound = $resolver->bindPlannedElements(['paragraph-4'], $this->elements());
        $this->assertSame(['paragraph-4'], array_column($bound['elements'], 'source_element_key'));
    }

    public function test_a_list_that_does_not_fit_the_budget_is_reported_not_cut(): void
    {
        $long = str_repeat('Langt kriterium med mye tekst. ', 40);
        $elements = [
            ['source_element_key' => 'paragraph-0', 'source_element_type' => 'paragraph', 'section_title' => 'A', 'reference_text' => 'Følgende kriterier gjelder:'],
            ['source_element_key' => 'listitem-0', 'source_element_type' => 'list_item', 'section_title' => 'A', 'reference_text' => $long],
            ['source_element_key' => 'listitem-1', 'source_element_type' => 'list_item', 'section_title' => 'A', 'reference_text' => $long],
            ['source_element_key' => 'listitem-2', 'source_element_type' => 'list_item', 'section_title' => 'A', 'reference_text' => $long],
            ['source_element_key' => 'listitem-3', 'source_element_type' => 'list_item', 'section_title' => 'A', 'reference_text' => $long],
        ];

        $resolver = app(EnterpriseWikiPlannedSectionEvidenceResolver::class);
        $bound = $resolver->bindPlannedElements(['paragraph-0'], $elements);

        $this->assertSame(['paragraph-0', 'listitem-0', 'listitem-1'], array_column($bound['elements'], 'source_element_key'));
        $this->assertSame(['listitem-2', 'listitem-3'], $bound['omitted_keys']);

        $sections = $resolver->resolve([['topic' => 'Kriterier', 'source_element_keys' => ['paragraph-0']]], $elements);
        $this->assertSame(['listitem-2', 'listitem-3'], $sections[0]['omitted_source_element_keys']);

        foreach ($bound['elements'] as $element) {
            $this->assertStringEndsWith(trim($element['reference_text']), trim(collect($elements)->firstWhere('source_element_key', $element['source_element_key'])['reference_text']), 'Included items are whole.');
        }

        // ...and the coverage check counts the omitted items as not covered.
        $report = $this->validator()->evaluate(
            ['source_article' => ['owned_topics' => [['topic' => 'Kriterier', 'source_element_keys' => ['paragraph-0']]]]],
            $elements,
        );
        $this->assertSame(['listitem-2', 'listitem-3'], $report['sections'][0]['uncovered_element_keys']);
    }

    /**
     * @param  list<array<string, mixed>>  $elements
     * @return \Closure(string): string
     */
    private function sectionKeyFor(array $elements): \Closure
    {
        $map = EnterpriseWikiDocumentSectionMap::build($elements);

        return static fn (string $elementKey): string => $map['section_by_element'][$elementKey];
    }
}
