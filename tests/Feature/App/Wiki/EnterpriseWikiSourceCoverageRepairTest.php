<?php

namespace Tests\Feature\App\Wiki;

use App\Data\Ai\AiCallContext;
use App\Models\EnterpriseWikiDocument;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentSectionMap;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionPrompt;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionService;
use App\Services\EnterpriseWiki\EnterpriseWikiPlanningContext;
use App\Services\EnterpriseWiki\EnterpriseWikiSourceCoverageValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\TestCase;

/**
 * Plan §18, P0-1 through EnterpriseWikiMaintainerDecisionService: a coverage gap goes through the
 * EXISTING bounded delta repair (at most MAX_REPAIR_ROUNDS calls, scoped to source_article, with
 * the uncovered sections routed in full text), and a gap the repair cannot close never fails the
 * plan — it stays for QA. Run on the real run 962 source elements.
 */
class EnterpriseWikiSourceCoverageRepairTest extends TestCase
{
    use CreatesEnterpriseWikiFixtures;
    use RefreshDatabase;

    public function test_a_coverage_gap_is_closed_by_one_bounded_repair_of_the_article(): void
    {
        [$customerId, $document, $planning] = $this->planning();
        $sectionKey = $this->sectionKeyFor($planning);
        $repairPrompts = [];

        $this->partialMock(EnterpriseWikiMaintainerDecisionAiClient::class, function (MockInterface $mock) use ($sectionKey, &$repairPrompts): void {
            $this->stubCapacity($mock);
            $mock->shouldReceive('repairGroup')
                ->once()
                ->andReturnUsing(function ($planning, $language, array $decision, array $group) use ($sectionKey, &$repairPrompts): array {
                    $repairPrompts[] = $group;

                    $article = $decision['source_article'];
                    $article['owned_topics'] = $this->fullCoverageTopics();
                    $article['excluded_sections'] = [
                        ['section_key' => $sectionKey('paragraph-0'), 'reason' => 'front_matter', 'justification' => 'Innledende notat om dokumentets status, ikke fagkunnskap.', 'existing_page_id' => null],
                        ['section_key' => $sectionKey('paragraph-25'), 'reason' => 'example', 'justification' => 'Konstruert eksempel som bare illustrerer metoden.', 'existing_page_id' => null],
                    ];

                    return ['operations' => [[
                        'collection' => 'source_article',
                        'object_id' => 'source_article',
                        'operation' => 'replace',
                        'object' => $article,
                    ]], 'notes' => null];
                });
        });

        $result = app(EnterpriseWikiMaintainerDecisionService::class)->validateAndRepairForDocument(
            $customerId, $document, 'no', $this->thinDecision(), AiCallContext::none(), $planning,
        );

        $this->assertSame(['source_article'], $repairPrompts[0]['object_ids']);
        $this->assertNotEmpty(array_filter(
            $repairPrompts[0]['issues'],
            static fn (string $issue): bool => str_contains($issue, '['.$sectionKey('paragraph-5').']'),
        ), 'The roles section is named to the repair.');

        $report = app(EnterpriseWikiSourceCoverageValidator::class)->evaluate($result, $planning->elements);
        $this->assertSame(EnterpriseWikiSourceCoverageValidator::STATUS_COMPLETE, $report['status']);
        $this->assertSame([], $result['concept_pages'], 'Coverage never creates extra pages.');
    }

    public function test_a_gap_the_repair_cannot_close_is_left_for_qa_and_never_fails_the_plan(): void
    {
        [$customerId, $document, $planning] = $this->planning();

        $this->partialMock(EnterpriseWikiMaintainerDecisionAiClient::class, function (MockInterface $mock): void {
            $this->stubCapacity($mock);
            // The model "repairs" without adding anything — no progress, so the loop stops.
            $mock->shouldReceive('repairGroup')
                ->once()
                ->andReturnUsing(fn ($planning, $language, array $decision): array => ['operations' => [[
                    'collection' => 'source_article',
                    'object_id' => 'source_article',
                    'operation' => 'replace',
                    'object' => $decision['source_article'],
                ]], 'notes' => null]);
        });

        $result = app(EnterpriseWikiMaintainerDecisionService::class)->validateAndRepairForDocument(
            $customerId, $document, 'no', $this->thinDecision(), AiCallContext::none(), $planning,
        );

        $report = app(EnterpriseWikiSourceCoverageValidator::class)->evaluate($result, $planning->elements);
        $this->assertSame(EnterpriseWikiSourceCoverageValidator::STATUS_INCOMPLETE, $report['status']);
    }

    public function test_the_repair_prompt_receives_the_uncovered_sections_in_full_text(): void
    {
        [, , $planning] = $this->planning();
        $sectionKey = $this->sectionKeyFor($planning);
        $decision = $this->thinDecision();
        $group = [
            'object_ids' => ['source_article'],
            'issues' => app(EnterpriseWikiSourceCoverageValidator::class)->findIssues($decision, $planning->elements),
        ];

        $method = new \ReflectionMethod(EnterpriseWikiMaintainerDecisionAiClient::class, 'repairSectionKeys');
        $routed = $method->invoke(null, $decision, $group, $planning->catalogElements);

        $this->assertContains($sectionKey('paragraph-1'), $routed, 'Sections the article already cites.');
        $this->assertContains($sectionKey('paragraph-5'), $routed, 'The uncovered roles section.');
        $this->assertContains($sectionKey('paragraph-19'), $routed, 'The uncovered feedback section.');
    }

    private function stubCapacity(MockInterface $mock): void
    {
        $mock->shouldReceive('maxObjectsPerRepairCall')->andReturn(8);
        $mock->shouldReceive('repairGroupFitsOneCall')->andReturn(true);
    }

    /** @return list<array{topic: string, source_element_keys: list<string>}> */
    private function fullCoverageTopics(): array
    {
        return [
            ['topic' => 'Formål og avgrensning', 'source_element_keys' => ['paragraph-1', 'paragraph-2']],
            ['topic' => 'Når en gjennomgang skal gjennomføres', 'source_element_keys' => ['paragraph-3', 'paragraph-4']],
            ['topic' => 'Roller i gjennomgangen', 'source_element_keys' => ['paragraph-5', 'paragraph-6']],
            ['topic' => 'Tidslinjen', 'source_element_keys' => ['paragraph-7', 'paragraph-8', 'paragraph-9']],
            ['topic' => 'Årsaksanalyse', 'source_element_keys' => ['paragraph-10', 'paragraph-11', 'paragraph-12', 'paragraph-13', 'paragraph-14']],
            ['topic' => 'Forbedringstiltak', 'source_element_keys' => ['paragraph-15', 'paragraph-16']],
            ['topic' => 'Verifisering av effekt', 'source_element_keys' => ['paragraph-17', 'paragraph-18']],
            ['topic' => 'Tilbakeføring og deling', 'source_element_keys' => ['paragraph-19', 'paragraph-20']],
            ['topic' => 'Måling av forbedringssløyfen', 'source_element_keys' => ['paragraph-21', 'paragraph-22', 'paragraph-23']],
            ['topic' => 'Vanlige fallgruver', 'source_element_keys' => ['listitem-19', 'listitem-20', 'listitem-21', 'listitem-22', 'listitem-23']],
        ];
    }

    /** @return array<string, mixed> */
    private function thinDecision(): array
    {
        $page = static fn (string $title, string $slug, array $topics): array => [
            'action' => 'create',
            'title' => $title,
            'proposed_slug' => $slug,
            'reason' => 'Nytt kildedokument.',
            'owned_topics' => $topics,
            'reference_only_topics' => [],
            'excluded_topics' => [],
            'related_page_guidance' => [],
            'planned_figures' => [],
            'excluded_sections' => [],
        ];

        return EnterpriseWikiMaintainerDecisionPrompt::parse([
            'source_article' => $page('Etterhendelsesgjennomgang', 'etterhendelsesgjennomgang-ab1c2', [
                ['topic' => 'Formål og avgrensning', 'source_element_keys' => ['paragraph-1', 'paragraph-2']],
            ]),
            'source_summary' => $page('Etterhendelsesgjennomgang – Sammendrag', 'etterhendelsesgjennomgang-sammendrag-ab1c2', [
                ['topic' => 'Hovedpoenger', 'source_element_keys' => ['paragraph-1']],
            ]),
            'concept_candidates' => [],
            'concept_pages' => [],
            'entity_pages' => [],
            'patch_targets' => [],
            'no_action_reason' => null,
            'warnings' => [],
        ]);
    }

    /** @return array{0: int, 1: EnterpriseWikiDocument, 2: EnterpriseWikiPlanningContext} */
    private function planning(): array
    {
        $customer = $this->createWikiCustomer('Dekning AS');
        $extraction = json_decode((string) file_get_contents(base_path('tests/Fixtures/EnterpriseWiki/run962/extraction.json')), true);
        $elements = array_map(static fn (array $element): array => [
            'source_element_key' => $element['element_key'],
            'source_element_type' => $element['element_type'],
            'source_row_key' => null,
            'section_number' => $element['section_number'],
            'section_title' => $element['section_title'],
            'page_reference' => $element['section_title'],
            'reference_text' => $element['text'],
            'display_text' => mb_substr($element['text'], 0, 280),
        ], $extraction['text_elements']);

        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'etterhendelsesgjennomgang.docx',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.docx',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => $extraction['text'],
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);

        $catalog = EnterpriseWikiMaintainerDecisionAiClient::sourceCatalogElements($elements);

        return [$customer->id, $document, new EnterpriseWikiPlanningContext(
            customerId: $customer->id,
            documentId: $document->id,
            sourceMeta: ['title' => 'etterhendelsesgjennomgang', 'filename' => 'etterhendelsesgjennomgang.docx'],
            sourceText: $extraction['text'],
            elements: $elements,
            catalogElements: $catalog,
            figureCandidates: [],
            sectionMap: EnterpriseWikiDocumentSectionMap::build($catalog),
            wikiIndex: [],
            validSourceElementKeys: array_column($catalog, 'source_element_key'),
            validFigureKeys: [],
            existingPageCandidatesResolver: static fn (): array => [],
        )];
    }

    private function sectionKeyFor(EnterpriseWikiPlanningContext $planning): \Closure
    {
        return static fn (string $elementKey): string => $planning->sectionMap['section_by_element'][$elementKey];
    }
}
