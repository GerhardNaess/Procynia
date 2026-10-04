<?php

namespace Tests\Feature\App\Wiki;

use App\Exceptions\EnterpriseWikiPlannedSectionEvidenceMissingException;
use App\Jobs\EnterpriseWiki\GenerateEnterpriseWikiAppliedPage;
use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\Ai\Wiki\WikiPageContentAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiGenerateAppliedPagesService;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionService;
use App\Services\EnterpriseWiki\EnterpriseWikiPageContentBlockService;
use App\Services\EnterpriseWiki\EnterpriseWikiPlanningContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * A short authored source (Markdown written in the app, as a Risk or Kvalitet handoff produces)
 * must become an article — and only an article the source supports.
 *
 * Such a document has no structured elements, so the planner sees its text flat, with no SOURCE
 * ELEMENTS catalog, and writes made-up evidence keys for its owned topics (run 31 used the topics'
 * own wording; run 26 the document's headings). Generation only knows the document's whole-text
 * element, so every section resolved to zero evidence and the run failed on
 * planned_section_no_evidence however well the text supported the plan.
 *
 * The contract: planning binds those topics to the whole-text element; generation then gets this
 * document's text, and nothing else, as each section's evidence. The evidence and citation guards
 * downstream are unchanged.
 */
class EnterpriseWikiUnstructuredSourcePlanningTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE_TEXT = "# Overvåking av backupjobber\n\n"
        .'Backupjobber bør ha automatisk varsling ved feil, og gjenoppretting bør testes jevnlig.';

    private const TOPICS = ['Automatisk varsling ved feil i backupjobber', 'Regelmessig test av gjenoppretting'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.enterprise_wiki.ai_enabled' => true]);
    }

    public function test_a_short_authored_source_becomes_an_article_grounded_only_in_that_source(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createAuthoredDocument($customer, self::SOURCE_TEXT);
        $wholeDocumentKey = EnterpriseWikiPageContentBlockService::wholeDocumentElementKey($document->id);

        $decision = $this->planWith($customer, $document, $this->decisionAsThePlannerWroteIt());

        // Planning: every owned topic is bound to the one element the document has.
        foreach ($decision['source_article']['owned_topics'] as $index => $topic) {
            $this->assertSame(self::TOPICS[$index], $topic['topic'], 'The plan itself is the planner\'s; only the binding changes.');
            $this->assertSame([$wholeDocumentKey], $topic['source_element_keys']);
        }

        [$run, $article] = $this->appliedRunFor($customer, $document, $decision);

        $captured = [];
        $this->mock(WikiPageContentAiClient::class)
            ->shouldReceive('generatePageFromSource')
            ->once()
            ->andReturnUsing(function (...$args) use (&$captured, $wholeDocumentKey): array {
                $captured = ['source_elements' => $args[6] ?? [], 'planned_sections' => $args[8] ?? []];

                return $this->sectionsCiting(self::TOPICS, $wholeDocumentKey);
            });

        Queue::fake();
        (new GenerateEnterpriseWikiAppliedPage($run->id, $article->id))->handle(app(EnterpriseWikiGenerateAppliedPagesService::class));

        // Generation: each planned section carries real evidence — this document's text, nothing else.
        $this->assertCount(2, $captured['planned_sections']);
        foreach ($captured['planned_sections'] as $index => $section) {
            $this->assertSame(self::TOPICS[$index], $section['planned_topic']);
            $this->assertSame([$wholeDocumentKey], $section['source_element_keys']);
            $this->assertCount(1, $section['source_evidence']);
            $this->assertSame(self::SOURCE_TEXT, $section['source_evidence'][0]['reference_text']);
        }
        $this->assertSame([$wholeDocumentKey], array_column($captured['source_elements'], 'source_element_key'));

        // The page has a working version whose structure is exactly the plan, every block source-based
        // and traceable to this one document.
        $version = EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $article->id)->sole();
        preg_match_all('/^## (.+)$/m', (string) $version->content_markdown, $headings);
        $this->assertSame(self::TOPICS, $headings[1]);

        foreach ((array) $version->content_blocks_json as $block) {
            $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED, $block['content_origin']);
            $this->assertSame($document->id, (int) $block['source_id']);
            $this->assertSame([$wholeDocumentKey], array_column($block['source_elements'], 'source_element_key'));
        }
    }

    public function test_a_block_citing_anything_but_the_source_is_still_rejected(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createAuthoredDocument($customer, self::SOURCE_TEXT);
        [$run, $article] = $this->appliedRunFor($customer, $document, $this->planWith($customer, $document, $this->decisionAsThePlannerWroteIt()));

        // The planner's made-up key is not evidence at generation either.
        $this->mock(WikiPageContentAiClient::class)
            ->shouldReceive('generatePageFromSource')
            ->andReturn($this->sectionsCiting(self::TOPICS, 'Automatisk varsling'));

        Queue::fake();

        try {
            (new GenerateEnterpriseWikiAppliedPage($run->id, $article->id))->handle(app(EnterpriseWikiGenerateAppliedPagesService::class));
            $this->fail('A block citing an unknown source element must not be persisted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('unknown source_element_key [Automatisk varsling]', $e->getMessage());
        }

        $this->assertFalse(EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $article->id)->exists());
    }

    public function test_a_source_with_no_text_still_fails_closed_on_planned_section_no_evidence(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createAuthoredDocument($customer, '');
        $decision = $this->planWith($customer, $document, $this->decisionAsThePlannerWroteIt());

        // Nothing to bind to: the planner's keys are left as written, and resolve to nothing.
        $this->assertSame(['Automatisk varsling'], $decision['source_article']['owned_topics'][0]['source_element_keys']);

        [$run, $article] = $this->appliedRunFor($customer, $document, $decision);
        $this->mock(WikiPageContentAiClient::class)->shouldNotReceive('generatePageFromSource');
        Queue::fake();

        $this->expectException(EnterpriseWikiPlannedSectionEvidenceMissingException::class);
        $this->expectExceptionMessage('planned_section_no_evidence');

        (new GenerateEnterpriseWikiAppliedPage($run->id, $article->id))->handle(app(EnterpriseWikiGenerateAppliedPagesService::class));
    }

    public function test_a_document_with_structured_elements_keeps_the_planners_own_keys(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createAuthoredDocument($customer, self::SOURCE_TEXT);
        $element = ['source_element_key' => 'paragraph-0', 'source_element_type' => 'paragraph', 'reference_text' => self::SOURCE_TEXT];
        $planning = new EnterpriseWikiPlanningContext(
            customerId: $customer->id,
            documentId: $document->id,
            sourceMeta: ['title' => 'Kilde', 'filename' => 'Kilde.docx'],
            sourceText: self::SOURCE_TEXT,
            elements: [$element],
            catalogElements: [$element],
            figureCandidates: [],
            sectionMap: ['sections' => [], 'section_by_element' => [], 'sectionless_element_keys' => ['paragraph-0']],
            wikiIndex: [],
            validSourceElementKeys: ['paragraph-0'],
            validFigureKeys: [],
            existingPageCandidatesResolver: static fn (): array => [],
        );

        $decision = $this->decisionAsThePlannerWroteIt();
        $decision['source_article']['owned_topics'] = [['topic' => self::TOPICS[0], 'source_element_keys' => ['paragraph-0']]];

        $this->mock(EnterpriseWikiMaintainerDecisionAiClient::class)->shouldNotReceive('repairGroup');

        $result = app(EnterpriseWikiMaintainerDecisionService::class)
            ->validateAndRepairForDocument($customer->id, $document, 'no', $decision, null, $planning);

        $this->assertSame(['paragraph-0'], $result['source_article']['owned_topics'][0]['source_element_keys']);
    }

    /**
     * The shape run 31's planner returned for exactly this source: two topics the text supports,
     * each "bound" to a key that exists nowhere.
     *
     * @return array<string, mixed>
     */
    private function decisionAsThePlannerWroteIt(): array
    {
        return [
            'source_article' => [
                'action' => 'create',
                'title' => 'Overvåking av backupjobber',
                'proposed_slug' => 'overvaking-av-backupjobber-ab1c2d',
                'reason' => 'New.',
                'owned_topics' => [
                    ['topic' => self::TOPICS[0], 'source_element_keys' => ['Automatisk varsling']],
                    ['topic' => self::TOPICS[1], 'source_element_keys' => ['Gjenoppretting']],
                ],
            ],
            'source_summary' => [
                'action' => 'create',
                'title' => 'Overvåking av backupjobber (sammendrag)',
                'proposed_slug' => 'overvaking-av-backupjobber-sammendrag-ab1c2d',
                'reason' => 'Companion.',
            ],
            'concept_pages' => [],
            'entity_pages' => [],
            'no_action_reason' => null,
            'warnings' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $plannerDecision
     * @return array<string, mixed>
     */
    private function planWith(Customer $customer, EnterpriseWikiDocument $document, array $plannerDecision): array
    {
        /** @var EnterpriseWikiMaintainerDecisionAiClient&MockInterface $mock */
        $mock = $this->mock(EnterpriseWikiMaintainerDecisionAiClient::class);
        $mock->shouldReceive('decide')->once()->andReturn($plannerDecision);
        $mock->shouldNotReceive('repairGroup');

        return app(EnterpriseWikiMaintainerDecisionService::class)->runForDocument($customer->id, $document->id, 'no');
    }

    /**
     * @param  list<string>  $topics
     * @return array{markdown: string, blocks: list<array<string, mixed>>}
     */
    private function sectionsCiting(array $topics, string $sourceElementKey): array
    {
        // One sentence of the source per section, so each section says only what the source says.
        $bodies = ['Backupjobber bør ha automatisk varsling ved feil.', 'Gjenoppretting bør testes jevnlig.'];
        $blocks = array_map(static fn (string $topic, string $body): array => [
            'markdown' => "## {$topic}\n\n{$body}",
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            'source_element_keys' => [$sourceElementKey],
            'source_element_types' => ['manual'],
            'best_practice_reason' => null,
            'link_intents' => [],
        ], $topics, $bodies);

        return ['markdown' => implode("\n\n", array_column($blocks, 'markdown')), 'blocks' => $blocks];
    }

    /**
     * @param  array<string, mixed>  $decision
     * @return array{0: EnterpriseWikiIngestRun, 1: EnterpriseWikiPage}
     */
    private function appliedRunFor(Customer $customer, EnterpriseWikiDocument $document, array $decision): array
    {
        $article = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => $decision['source_article']['proposed_slug'],
            'title' => $decision['source_article']['title'],
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_GENERATING_PAGES,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
            'maintainer_decision_json' => $decision,
        ]);

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $article->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
        ]);

        return [$run, $article];
    }

    private function createAuthoredDocument(Customer $customer, string $text): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'Overvåking av backupjobber.md',
            'file_path' => 'customers/'.$customer->id.'/wiki-documents/'.Str::random(12).'.md',
            'file_hash_sha256' => hash('sha256', $text.Str::random(8)),
            'extracted_text' => $text,
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createCustomer(): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO'],
        );

        return Customer::query()->create([
            'name' => 'Test AS',
            'slug' => 'test-as-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }
}
