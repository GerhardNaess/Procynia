<?php

namespace Tests\Feature\App\Wiki;

use App\Data\Ai\AiCallContext;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use App\Models\EnterpriseWikiPageLinkQaAttempt;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use App\Services\Ai\Wiki\WikiClaimVerificationAiClient;
use App\Services\Ai\Wiki\WikiPageClaimExtractionAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiBuildPageLinksService;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentSectionMap;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentSourceElementService;
use App\Services\EnterpriseWiki\EnterpriseWikiExtractPageClaimsService;
use App\Services\EnterpriseWiki\EnterpriseWikiLinkSemanticRepairService;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionPrompt;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionService;
use App\Services\EnterpriseWiki\EnterpriseWikiPlanningContext;
use App\Services\EnterpriseWiki\EnterpriseWikiPostIngestQaService;
use App\Services\EnterpriseWiki\EnterpriseWikiRunFindingsService;
use App\Services\EnterpriseWiki\EnterpriseWikiVerifyPageClaimsService;
use App\Services\OpenAi\OpenAiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\Concerns\MarksWikiLinkAssessmentComplete;
use Tests\TestCase;

/**
 * Plan §18: the four run-962 corrections in one flow, on the real run-962 source elements, with
 * every AI call mocked and every other service real.
 *
 *  1. Planning (P0-1): the planner binds one section; the bounded repair adds the rest to the
 *     article and excludes the example and the front matter — the plan reaches full coverage.
 *  2. Link materialization (P1-1): an existing page that gets a new link in this run keeps its
 *     older relation's run.
 *  3. Claim verification (P0-2): the AI verifier calls the «bør» → «skal» block supported; the
 *     sentence-level check overrides it, while a faithful block stays source_based.
 *  4. Semantic link QA (P0-3 support): the reviewer suggests a slug outside the catalog; it is
 *     dropped and the review still completes.
 *  5. QA (P0-3): escalated on the normative finding only; Funn explains it; a reviewer's decision
 *     releases it and QA passes.
 */
class EnterpriseWikiRun962QualityIntegrationTest extends TestCase
{
    use CreatesEnterpriseWikiFixtures;
    use MarksWikiLinkAssessmentComplete;
    use RefreshDatabase;

    private const STRENGTHENED = 'Forbedringssløyfen skal måles som en egen prosess, ikke bare gjennom de operative responstidene.';

    private const FAITHFUL = 'Den som ledet hendelsen, normalt Incident Commander, eier gjennomgangen og er ansvarlig for at den blir gjennomført.';

    public function test_the_run_962_corrections_work_together(): void
    {
        config(['services.enterprise_wiki.ai_enabled' => true]);

        $customer = $this->createWikiCustomer('Advania-lik testkunde');
        $elements = $this->run962Elements();
        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'wiki-knowledge-integration-test-source.docx',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.docx',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => implode("\n\n", array_column($elements, 'reference_text')),
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
        $this->mock(EnterpriseWikiDocumentSourceElementService::class)->shouldReceive('inspect')->andReturn(['elements' => $elements]);

        // ── 1. Planning with bounded coverage repair ─────────────────────────
        $planning = $this->planning($customer->id, $document, $elements);
        $sectionOf = $planning->sectionMap['section_by_element'];

        $this->partialMock(EnterpriseWikiMaintainerDecisionAiClient::class, function (MockInterface $mock) use ($sectionOf): void {
            $mock->shouldReceive('maxObjectsPerRepairCall')->andReturn(8);
            $mock->shouldReceive('repairGroupFitsOneCall')->andReturn(true);
            $mock->shouldReceive('repairGroup')->once()->andReturnUsing(function ($planning, $language, array $decision) use ($sectionOf): array {
                $article = $decision['source_article'];
                $article['owned_topics'] = $this->fullCoverageTopics();
                $article['excluded_sections'] = [
                    ['section_key' => $sectionOf['paragraph-0'], 'reason' => 'front_matter', 'justification' => 'Statusnotat om dokumentet, ikke fagkunnskap.', 'existing_page_id' => null],
                    ['section_key' => $sectionOf['paragraph-25'], 'reason' => 'example', 'justification' => 'Konstruert eksempel som bare illustrerer metoden.', 'existing_page_id' => null],
                ];

                return ['operations' => [['collection' => 'source_article', 'object_id' => 'source_article', 'operation' => 'replace', 'object' => $article]], 'notes' => null];
            });
        });

        $decision = app(EnterpriseWikiMaintainerDecisionService::class)->validateAndRepairForDocument(
            $customer->id, $document, 'no', $this->thinDecision(), AiCallContext::none(), $planning,
        );

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
            'maintainer_decision_json' => $decision,
        ]);

        // ── Generated pages (as generation would store them) ─────────────────
        $article = $this->page($customer, $run, 'Etterhendelsesgjennomgang', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, [
            $this->sourceBlock('block-0001', 0, self::FAITHFUL, $document, 'paragraph-5', $elements),
            $this->sourceBlock('block-0002', 1, self::STRENGTHENED, $document, 'paragraph-21', $elements),
        ]);
        $this->page($customer, $run, 'Etterhendelsesgjennomgang – Sammendrag', EnterpriseWikiPage::PAGE_TYPE_SUMMARY, [[
            'block_key' => 'block-0001', 'position' => 0, 'markdown' => '# Sammendrag', 'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_STRUCTURAL,
        ]]);

        // ── 2. An existing page with a relation from an earlier run gets a new inbound link ──
        $earlierRun = $run->replicate(['uuid'])->fill(['uuid' => (string) Str::uuid()]);
        $earlierRun->save();
        $tiPage = $this->createWikiPage($customer, 'Trusseletterretning', ['slug' => 'trusseletterretning']);
        $irt = $this->createWikiPage($customer, 'Incident Response Team (IRT)', ['slug' => 'incident-response-team-irt']);
        $this->version($tiPage, 1, '# Trusseletterretning');
        $this->version($irt, 1, 'Se [[trusseletterretning]].');
        app(EnterpriseWikiBuildPageLinksService::class)->materializeWikilinksForPage($irt, $earlierRun->id);
        $this->version($irt, 2, 'Se [[trusseletterretning]] og [['.$article->slug.'|gjennomgangen]].');
        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $irt->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_UPDATED,
            'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
        ]);
        app(EnterpriseWikiBuildPageLinksService::class)->materializeWikilinksForRun($run);
        $this->markLinkAssessmentComplete($run);

        $this->assertSame($earlierRun->id, EnterpriseWikiPageLink::query()->where('from_page_id', $irt->id)->where('to_page_id', $tiPage->id)->value('enterprise_wiki_ingest_run_id'));
        $this->assertSame($run->id, EnterpriseWikiPageLink::query()->where('from_page_id', $irt->id)->where('to_page_id', $article->id)->value('enterprise_wiki_ingest_run_id'));

        // ── 3. Claim extraction + verification ───────────────────────────────
        $this->mock(WikiPageClaimExtractionAiClient::class)->shouldNotReceive('extractClaims');
        $this->mock(WikiClaimVerificationAiClient::class)->shouldReceive('verifyClaim')
            ->andReturnUsing(fn (string $claimText, array $sourceElements): array => $this->verificationResult(
                supportingSourceElementKeys: array_column($sourceElements, 'key'),
            ));

        app(EnterpriseWikiExtractPageClaimsService::class)->extract($run->fresh());
        app(EnterpriseWikiVerifyPageClaimsService::class)->verify($run->fresh());

        $claims = EnterpriseWikiClaim::query()->where('enterprise_wiki_page_id', $article->id)->get()->keyBy('content_block_key');
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED, $claims['block-0001']->content_origin);
        $this->assertSame('normative_strengthening', $claims['block-0002']->review_metadata['deterministic_reason']);

        // ── 4. Semantic link QA with a guessed slug ──────────────────────────
        $this->mock(OpenAiClient::class)->shouldReceive('createResponse')->andReturn([
            'id' => 'resp_test',
            'status' => 'completed',
            'output_text' => json_encode(['assessment' => 'repair_recommended', 'missing_link_slugs' => ['deteksjonsgap'], 'remove_link_slugs' => [], 'critique' => 'Lenk til deteksjonsgap.']),
            'output' => [],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'total_tokens' => 15],
            '_meta' => ['request_id' => 'req_test', 'http_status' => 200, 'provider' => 'openai'],
        ]);

        $linkQa = app(EnterpriseWikiLinkSemanticRepairService::class)->repairForRun($run->fresh());
        $this->assertSame(0, $linkQa['failed']);
        $this->assertSame(0, EnterpriseWikiPageLinkQaAttempt::query()->where('enterprise_wiki_ingest_run_id', $run->id)->where('status', EnterpriseWikiPageLinkQaAttempt::STATUS_FAILED)->count());

        // ── 5. QA ────────────────────────────────────────────────────────────
        $result = app(EnterpriseWikiPostIngestQaService::class)->runForRun($run->fresh());

        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_ESCALATED, $run->fresh()->qa_status);
        $this->assertSame([EnterpriseWikiPostIngestQaService::FINDING_NORMATIVE_STRENGTHENING], $result['quality_findings']);
        $this->assertSame('completed', $result['checks']['semantic_link_qa']['status']);
        $this->assertSame('complete', $result['checks']['source_coverage']['status']);
        $this->assertSame('checked', $result['checks']['link_integration']['status']);

        $findings = app(EnterpriseWikiRunFindingsService::class)->buildForRun($run->fresh(), null, false);
        $this->assertContains($claims['block-0002']->id, array_column($findings['findings'], 'claim_id'));
        $this->assertSame(__('procynia.wiki.runs_findings_explanation_quality.normative_strengthening'), $findings['summary']['explanation']);

        // A reviewer decides on the finding (here: "not blocking"); QA then passes.
        $claims['block-0002']->update(['blocking_override' => false]);
        app(EnterpriseWikiPostIngestQaService::class)->runForRun($run->fresh(), retry: true);
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->fresh()->qa_status);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** @param list<array<string, mixed>> $blocks */
    private function page($customer, EnterpriseWikiIngestRun $run, string $title, string $type, array $blocks): EnterpriseWikiPage
    {
        $page = $this->createWikiPage($customer, $title, ['page_type' => $type]);
        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => implode("\n\n", array_column($blocks, 'markdown')),
            'content_blocks_json' => $blocks,
            'generated_by_model' => 'gpt-5',
        ]);
        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            'generated_page_version_id' => $version->id,
            'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
        ]);

        return $page;
    }

    private function version(EnterpriseWikiPage $page, int $number, string $markdown): void
    {
        EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $page->id)->update(['is_current' => false]);
        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => $number,
            'is_current' => true,
            'content_markdown' => $markdown,
            'generated_by_model' => 'gpt-5',
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $elements
     * @return array<string, mixed>
     */
    private function sourceBlock(string $key, int $position, string $markdown, EnterpriseWikiDocument $document, string $elementKey, array $elements): array
    {
        $element = collect($elements)->firstWhere('source_element_key', $elementKey);

        return [
            'block_key' => $key,
            'position' => $position,
            'markdown' => $markdown,
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_elements' => [[
                'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
                'source_id' => $document->id,
                'source_label' => $document->original_filename,
                'source_element_key' => $elementKey,
                'source_element_type' => EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_PARAGRAPH,
                'source_excerpt' => $element['reference_text'],
                'page_reference' => $element['section_title'],
            ]],
        ];
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
            'action' => 'create', 'title' => $title, 'proposed_slug' => $slug, 'reason' => 'Nytt kildedokument.',
            'owned_topics' => $topics, 'reference_only_topics' => [], 'excluded_topics' => [],
            'related_page_guidance' => [], 'planned_figures' => [], 'excluded_sections' => [],
        ];

        return EnterpriseWikiMaintainerDecisionPrompt::parse([
            'source_article' => $page('Etterhendelsesgjennomgang', 'etterhendelsesgjennomgang-ab1c2', [
                ['topic' => 'Formål og avgrensning', 'source_element_keys' => ['paragraph-1', 'paragraph-2']],
            ]),
            'source_summary' => $page('Etterhendelsesgjennomgang – Sammendrag', 'etterhendelsesgjennomgang-sammendrag-ab1c2', [
                ['topic' => 'Hovedpoenger', 'source_element_keys' => ['paragraph-1']],
            ]),
            'concept_candidates' => [], 'concept_pages' => [], 'entity_pages' => [], 'patch_targets' => [],
            'no_action_reason' => null, 'warnings' => [],
        ]);
    }

    /** @param list<array<string, mixed>> $elements */
    private function planning(int $customerId, EnterpriseWikiDocument $document, array $elements): EnterpriseWikiPlanningContext
    {
        $catalog = EnterpriseWikiMaintainerDecisionAiClient::sourceCatalogElements($elements);

        return new EnterpriseWikiPlanningContext(
            customerId: $customerId,
            documentId: $document->id,
            sourceMeta: ['title' => 'wiki-knowledge-integration-test-source', 'filename' => $document->original_filename],
            sourceText: (string) $document->extracted_text,
            elements: $elements,
            catalogElements: $catalog,
            figureCandidates: [],
            sectionMap: EnterpriseWikiDocumentSectionMap::build($catalog),
            wikiIndex: [],
            validSourceElementKeys: array_column($catalog, 'source_element_key'),
            validFigureKeys: [],
            existingPageCandidatesResolver: static fn (): array => [],
        );
    }

    /** @return list<array<string, mixed>> */
    private function run962Elements(): array
    {
        $extraction = json_decode((string) file_get_contents(base_path('tests/Fixtures/EnterpriseWiki/run962/extraction.json')), true);

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
}
