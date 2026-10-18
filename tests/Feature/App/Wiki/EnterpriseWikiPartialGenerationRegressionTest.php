<?php

namespace Tests\Feature\App\Wiki;

use App\Data\Ai\AiCallContext;
use App\Exceptions\EnterpriseWikiPlannedSectionEvidenceMissingException;
use App\Jobs\EnterpriseWiki\ContinueEnterpriseWikiDocumentFlowAfterPages;
use App\Jobs\EnterpriseWiki\FinalizeEnterpriseWikiPageGeneration;
use App\Jobs\EnterpriseWiki\GenerateEnterpriseWikiAppliedPage;
use App\Jobs\EnterpriseWiki\ProjectEnterpriseWikiPageToGraph;
use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiLintFinding;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\Ai\Wiki\WikiPageContentAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentFlowService;
use App\Services\EnterpriseWiki\EnterpriseWikiExtractPageClaimsService;
use App\Services\EnterpriseWiki\EnterpriseWikiGenerateAppliedPagesService;
use App\Services\EnterpriseWiki\EnterpriseWikiLinkSemanticRepairService;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionService;
use App\Services\EnterpriseWiki\EnterpriseWikiVerifyPageClaimsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Arbeidspakke A, main regression scenario (docs/enterprise-wiki-knowledge-integration-plan.md
 * §4.2 and §13 A): a controlled variant of Wiki run 26.
 *
 * The planned article fails before any AI call because one of its planned sections has no source
 * evidence (planned_section_no_evidence — a plan defect, not a transient fault). The summary and a
 * deferred concept generate. Before arbeidspakke A the finalizer failed the whole run at that
 * point, so the summary kept [[links]] in its text with no materialized rows, no graph edges, no
 * claims and no QA. Now the generated pages go through the real continuation — materialization,
 * lint and QA are real; only the AI-backed stages are mocked — and the run ends escalated.
 */
class EnterpriseWikiPartialGenerationRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_failed_article_does_not_leave_the_generated_pages_unlinked_or_the_run_falsely_complete(): void
    {
        config(['services.enterprise_wiki.ai_enabled' => true]);
        Queue::fake();

        $customer = $this->createCustomer();
        $viewer = $this->createUser($customer);
        $document = $this->createDocument($customer);
        $run = app(EnterpriseWikiDocumentFlowService::class)->prepareRunForDocument($customer->id, $document->id)['run'];

        $this->mockMaintainerDecision($customer, $document, [
            'source_article' => [
                'action' => 'create',
                'title' => 'Vurdering av betalingsbetingelser',
                'proposed_slug' => 'artikkel-test',
                'reason' => 'Source article.',
                // Bound to a source element the document does not have: the real
                // plannedSectionContractForGeneration() refuses to generate this page.
                'owned_topics' => [['topic' => 'Formål og tidspunkt for aktivering', 'source_element_keys' => ['element-that-does-not-exist']]],
            ],
            'source_summary' => ['action' => 'create', 'title' => 'Sammendrag', 'proposed_slug' => 'sammendrag-test', 'reason' => 'Summary.'],
            'concept_pages' => [['action' => 'create', 'title' => 'Konsept', 'proposed_slug' => 'konsept-test', 'reason' => 'Key concept.']],
            'entity_pages' => [],
            'no_action_reason' => null,
            'warnings' => [],
        ]);

        $this->mock(WikiPageContentAiClient::class)
            ->shouldReceive('generatePageFromSource')
            ->andReturnUsing(function (string $pageTitle, string $pageType, string $sourceText, string $languageCode, string $additionalContext = '', array $linkCatalog = [], array $sourceElements = []): array {
                $this->assertNotSame('article', $pageType, 'the failing article must never reach the AI');

                return $this->structuredPageResult(match ($pageType) {
                    'summary' => "# Sammendrag\n\nKort om [[konsept-test|begrepet]].",
                    'concept' => "# Konsept\n\nOppsummert i [[sammendrag-test|sammendraget]].",
                    default => '# Side',
                }, $sourceElements);
            });

        $this->mockClaimAndRepairStages();

        // --- Initial wave: the article fails on the plan defect, the summary generates. ---
        app(EnterpriseWikiDocumentFlowService::class)->run($run->id);

        $article = $this->page($customer, 'artikkel-test');
        $summary = $this->page($customer, 'sammendrag-test');
        $concept = $this->page($customer, 'konsept-test');

        try {
            (new GenerateEnterpriseWikiAppliedPage($run->id, $article->id))->handle(app(EnterpriseWikiGenerateAppliedPagesService::class));
            $this->fail('the article should have failed on planned_section_no_evidence');
        } catch (EnterpriseWikiPlannedSectionEvidenceMissingException) {
        }
        (new GenerateEnterpriseWikiAppliedPage($run->id, $summary->id))->handle(app(EnterpriseWikiGenerateAppliedPagesService::class));
        (new FinalizeEnterpriseWikiPageGeneration($run->id))->handle();

        // --- D7: the deferred concept wave still runs. ---
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_GENERATING_CONCEPT_ENTITY_PAGES, $run->fresh()->status);
        Queue::assertPushed(GenerateEnterpriseWikiAppliedPage::class, fn ($job) => $job->pageId === $concept->id);

        (new GenerateEnterpriseWikiAppliedPage($run->id, $concept->id))->handle(app(EnterpriseWikiGenerateAppliedPagesService::class));
        (new FinalizeEnterpriseWikiPageGeneration($run->id))->handle();

        Queue::assertPushed(ContinueEnterpriseWikiDocumentFlowAfterPages::class, 1);
        (new ContinueEnterpriseWikiDocumentFlowAfterPages($run->id))->handle(app(EnterpriseWikiDocumentFlowService::class));

        // --- The failed article is an incomplete shell with its reason kept. ---
        $articlePivot = $this->pivot($run, $article);
        $this->assertSame(EnterpriseWikiIngestRunPage::GENERATION_STATUS_FAILED, $articlePivot->generation_status);
        $this->assertNull($articlePivot->generated_page_version_id);
        $this->assertStringContainsString('planned_section_no_evidence', (string) $articlePivot->generation_error);
        $this->assertFalse(EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $article->id)->exists());

        // --- The generated pages' relations are materialized (run 26: page 111 had none). ---
        $this->assertTrue($this->wikilinkRow($summary, $concept));
        $this->assertTrue($this->wikilinkRow($concept, $summary));
        Queue::assertPushed(ProjectEnterpriseWikiPageToGraph::class, fn ($job) => $job->pageId === $summary->id);

        // The summary's text still carries its link to the article (the deterministic
        // article<->summary pairing), but no relation is materialized to the empty shell —
        // neither the wikilink row nor the structural article/summary pair.
        $summaryMarkdown = (string) $this->currentVersion($summary)->content_markdown;
        $this->assertStringContainsString('[[artikkel-test', $summaryMarkdown);
        $this->assertFalse($this->wikilinkRow($summary, $article));
        $this->assertSame(0, EnterpriseWikiPageLink::query()
            ->where(fn ($q) => $q->where('from_page_id', $article->id)->orWhere('to_page_id', $article->id))
            ->count());

        // --- The graph data shows the valid relations and nothing pointing at the shell. ---
        $graph = $this->actingAs($viewer)->getJson('/app/wiki/graph-data')->assertOk();
        $edges = collect($graph->json('edges'));
        $this->assertTrue($edges->contains(fn (array $e) => $e['from_page_id'] === $summary->id && $e['to_page_id'] === $concept->id));
        $this->assertTrue($edges->contains(fn (array $e) => $e['from_page_id'] === $concept->id && $e['to_page_id'] === $summary->id));
        $this->assertFalse($edges->contains(fn (array $e) => in_array($article->id, [$e['from_page_id'], $e['to_page_id']], true)));
        // The shell is not drawn as an ordinary knowledge node; the graph says one was left out.
        $this->assertFalse(collect($graph->json('nodes'))->contains('page_id', $article->id));
        $this->assertSame(1, $graph->json('summary.incomplete_count'));

        // --- Real lint does not report the deliberately unmaterialized shell link as a defect. ---
        $this->assertSame(0, EnterpriseWikiLintFinding::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->whereIn('code', [
                EnterpriseWikiLintFinding::CODE_MISSING_WIKILINK_MATERIALIZATION,
                EnterpriseWikiLintFinding::CODE_WIKILINK_PROJECTION_MISMATCH,
                EnterpriseWikiLintFinding::CODE_STALE_WIKILINK_GRAPH_EDGE,
                EnterpriseWikiLintFinding::CODE_BROKEN_WIKILINK,
            ])
            ->count());

        // --- Real QA identifies the partial completion; the run is escalated, never completed. ---
        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_ESCALATED, $run->status);
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_ESCALATED, $run->qa_status);
        $this->assertContains('page_generation_incomplete', (array) data_get($run->qa_result, 'incomplete_steps'));
        $this->assertStringContainsString('Vurdering av betalingsbetingelser', (string) $run->qa_last_error);
        $this->assertNotNull($run->finished_at);

        // --- Nothing was published or sent for review. ---
        foreach ([$article, $summary, $concept] as $page) {
            $page->refresh();
            $this->assertSame(EnterpriseWikiPage::STATUS_DRAFT, $page->status);
            $this->assertNull($page->published_version_id);
        }

        // --- Replaying the finished stages creates no duplicates. ---
        $linkCount = EnterpriseWikiPageLink::query()->where('customer_id', $customer->id)->count();
        $versionCount = EnterpriseWikiPageVersion::query()->whereIn('enterprise_wiki_page_id', [$article->id, $summary->id, $concept->id])->count();

        (new FinalizeEnterpriseWikiPageGeneration($run->id))->handle();
        (new ContinueEnterpriseWikiDocumentFlowAfterPages($run->id))->handle(app(EnterpriseWikiDocumentFlowService::class));
        app(EnterpriseWikiDocumentFlowService::class)->continueAfterClaimVerification($run->id);

        $this->assertSame($linkCount, EnterpriseWikiPageLink::query()->where('customer_id', $customer->id)->count());
        $this->assertSame($versionCount, EnterpriseWikiPageVersion::query()->whereIn('enterprise_wiki_page_id', [$article->id, $summary->id, $concept->id])->count());
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_ESCALATED, $run->fresh()->status);
    }

    public function test_a_transiently_failed_article_is_retried_and_the_run_then_completes(): void
    {
        config(['services.enterprise_wiki.ai_enabled' => true]);
        Queue::fake();

        $customer = $this->createCustomer();
        $owner = $this->createUser($customer);
        $document = $this->createDocument($customer);
        $run = app(EnterpriseWikiDocumentFlowService::class)->prepareRunForDocument($customer->id, $document->id)['run'];

        $this->mockMaintainerDecision($customer, $document, [
            'source_article' => ['action' => 'create', 'title' => 'Artikkel', 'proposed_slug' => 'artikkel-test', 'reason' => 'Source article.'],
            'source_summary' => ['action' => 'create', 'title' => 'Sammendrag', 'proposed_slug' => 'sammendrag-test', 'reason' => 'Summary.'],
            'concept_pages' => [['action' => 'create', 'title' => 'Konsept', 'proposed_slug' => 'konsept-test', 'reason' => 'Key concept.']],
            'entity_pages' => [],
            'no_action_reason' => null,
            'warnings' => [],
        ]);

        // The article's first attempt hits a transient upstream failure; the retry succeeds.
        $articleAttempts = 0;
        $this->mock(WikiPageContentAiClient::class)
            ->shouldReceive('generatePageFromSource')
            ->andReturnUsing(function (string $pageTitle, string $pageType, string $sourceText, string $languageCode, string $additionalContext = '', array $linkCatalog = [], array $sourceElements = []) use (&$articleAttempts): array {
                if ($pageType === 'article' && ++$articleAttempts === 1) {
                    throw new \RuntimeException('OpenAI request failed: HTTP status [503]: upstream unavailable');
                }

                return $this->structuredPageResult(match ($pageType) {
                    'article' => "# Artikkel\n\nSe [[konsept-test|begrepet]].",
                    'summary' => "# Sammendrag\n\nKort om [[konsept-test|begrepet]].",
                    'concept' => "# Konsept\n\nOppsummert i [[sammendrag-test|sammendraget]].",
                    default => '# Side',
                }, $sourceElements);
            });

        $this->mockClaimAndRepairStages();

        app(EnterpriseWikiDocumentFlowService::class)->run($run->id);
        $article = $this->page($customer, 'artikkel-test');
        $summary = $this->page($customer, 'sammendrag-test');
        $concept = $this->page($customer, 'konsept-test');

        try {
            (new GenerateEnterpriseWikiAppliedPage($run->id, $article->id))->handle(app(EnterpriseWikiGenerateAppliedPagesService::class));
        } catch (\RuntimeException) {
        }
        (new GenerateEnterpriseWikiAppliedPage($run->id, $summary->id))->handle(app(EnterpriseWikiGenerateAppliedPagesService::class));
        (new FinalizeEnterpriseWikiPageGeneration($run->id))->handle();
        (new GenerateEnterpriseWikiAppliedPage($run->id, $concept->id))->handle(app(EnterpriseWikiGenerateAppliedPagesService::class));
        (new FinalizeEnterpriseWikiPageGeneration($run->id))->handle();
        (new ContinueEnterpriseWikiDocumentFlowAfterPages($run->id))->handle(app(EnterpriseWikiDocumentFlowService::class));

        $this->assertSame(EnterpriseWikiIngestRun::STATUS_ESCALATED, $run->fresh()->status);
        $this->assertFalse($this->wikilinkRow($summary, $article));

        // «Prøv igjen for feilede sider».
        $this->actingAs($owner)
            ->patch("/app/wiki/runs/{$run->id}/retry-failed-pages", ['tab' => 'runs'])
            ->assertSessionHas('success');

        $summaryVersionId = $this->currentVersion($summary)->id;
        $conceptVersionId = $this->currentVersion($concept)->id;

        (new GenerateEnterpriseWikiAppliedPage($run->id, $article->id))->handle(app(EnterpriseWikiGenerateAppliedPagesService::class));
        (new FinalizeEnterpriseWikiPageGeneration($run->id))->handle();
        (new ContinueEnterpriseWikiDocumentFlowAfterPages($run->id))->handle(app(EnterpriseWikiDocumentFlowService::class));

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
        $this->assertSame(2, $articleAttempts);

        // The pages that had already generated were not regenerated.
        $this->assertSame($summaryVersionId, $this->currentVersion($summary)->id);
        $this->assertSame($conceptVersionId, $this->currentVersion($concept)->id);

        // The relations that waited for the article now exist.
        $this->assertTrue($this->wikilinkRow($summary, $article));
        $this->assertTrue($this->wikilinkRow($article, $concept));
        $this->assertTrue(EnterpriseWikiPageLink::query()
            ->where('from_page_id', $article->id)
            ->where('to_page_id', $summary->id)
            ->where('link_type', EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_SUMMARY)
            ->exists());

        // Still nothing published.
        $this->assertNull($article->fresh()->published_version_id);
        $this->assertSame(EnterpriseWikiPage::STATUS_DRAFT, $article->fresh()->status);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * @param  array<string, mixed>  $decision
     */
    private function mockMaintainerDecision(Customer $customer, EnterpriseWikiDocument $document, array $decision): void
    {
        $service = $this->mock(EnterpriseWikiMaintainerDecisionService::class);
        $service->shouldReceive('preparePersistedCandidateBatchesForDocument')
            ->once()
            ->with($customer->id, $document->id, 'no', Mockery::type(AiCallContext::class))
            ->andReturn(null);
        $service->shouldReceive('runForDocument')->once()->andReturn($decision);
    }

    /**
     * The AI-backed stages after generation. Claim extraction behaves like the real service for
     * this test's purpose: it records the checkpoint on every page that has a version and skips
     * the shell, exactly as EnterpriseWikiExtractPageClaimsService does.
     */
    private function mockClaimAndRepairStages(): void
    {
        $this->mock(EnterpriseWikiExtractPageClaimsService::class)
            ->shouldReceive('extract')
            ->andReturnUsing(function (EnterpriseWikiIngestRun $run): array {
                $withVersion = EnterpriseWikiPageVersion::query()->where('is_current', true)->pluck('enterprise_wiki_page_id');

                EnterpriseWikiIngestRunPage::query()
                    ->where('enterprise_wiki_ingest_run_id', $run->id)
                    ->whereIn('enterprise_wiki_page_id', $withVersion)
                    ->update(['claims_extracted_at' => now()]);

                return ['pages' => $withVersion->count(), 'claims' => 0, 'skipped' => 1];
            });

        $verify = $this->mock(EnterpriseWikiVerifyPageClaimsService::class);
        $verify->shouldReceive('unverifiedClaimIdsForRun')->andReturn([]);
        $verify->shouldReceive('hasActiveClaimLeaseForRun')->andReturn(false);
        $verify->shouldNotReceive('verify');

        $this->mock(EnterpriseWikiLinkSemanticRepairService::class)
            ->shouldReceive('repairForRun')
            ->andReturn(['pages_reviewed' => 2, 'applied' => 0, 'skipped' => 2, 'failed' => 0]);
    }

    private function wikilinkRow(EnterpriseWikiPage $from, EnterpriseWikiPage $to): bool
    {
        return EnterpriseWikiPageLink::query()
            ->where('from_page_id', $from->id)
            ->where('to_page_id', $to->id)
            ->where('link_type', EnterpriseWikiPageLink::LINK_TYPE_WIKILINK)
            ->exists();
    }

    private function page(Customer $customer, string $slug): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->where('customer_id', $customer->id)->where('slug', $slug)->firstOrFail();
    }

    private function pivot(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $page): EnterpriseWikiIngestRunPage
    {
        return EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->where('enterprise_wiki_page_id', $page->id)
            ->firstOrFail();
    }

    private function currentVersion(EnterpriseWikiPage $page): EnterpriseWikiPageVersion
    {
        return EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $page->id)->where('is_current', true)->firstOrFail();
    }

    private function createCustomer(): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => 'Run 26 AS',
            'slug' => 'run-26-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    private function createUser(Customer $customer): User
    {
        return User::query()->create([
            'name' => 'Wiki Viewer',
            'email' => Str::lower(Str::random(8)).'@test.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function createDocument(Customer $customer): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'betalingsbetingelser.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Betalingsbetingelser: 30 dager netto. Fakturaer sendes elektronisk.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $sourceElements
     * @return array{markdown: string, blocks: list<array<string, mixed>>}
     */
    private function structuredPageResult(string $markdown, array $sourceElements): array
    {
        $sourceElement = $sourceElements[0] ?? ['source_element_key' => 'document-1-full-text', 'source_element_type' => 'manual'];

        return [
            'markdown' => $markdown,
            'blocks' => [[
                'markdown' => $markdown,
                'content_origin' => 'source_based',
                'source_element_keys' => [(string) $sourceElement['source_element_key']],
                'source_element_types' => [(string) $sourceElement['source_element_type']],
                'best_practice_reason' => null,
                'link_intents' => [],
            ]],
        ];
    }
}
