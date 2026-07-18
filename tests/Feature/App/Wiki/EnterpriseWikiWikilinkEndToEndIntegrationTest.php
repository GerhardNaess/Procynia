<?php

namespace Tests\Feature\App\Wiki;

use App\Jobs\EnterpriseWiki\ContinueEnterpriseWikiDocumentFlowAfterPages;
use App\Jobs\EnterpriseWiki\FinalizeEnterpriseWikiPageGeneration;
use App\Jobs\EnterpriseWiki\GenerateEnterpriseWikiAppliedPage;
use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\Ai\Wiki\WikiPageContentAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiAppliedRunLintService;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentFlowService;
use App\Services\EnterpriseWiki\EnterpriseWikiExtractPageClaimsService;
use App\Services\EnterpriseWiki\EnterpriseWikiGenerateAppliedPagesService;
use App\Services\EnterpriseWiki\EnterpriseWikiLinkSemanticRepairService;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionService;
use App\Services\EnterpriseWiki\EnterpriseWikiPageTraversalService;
use App\Services\EnterpriseWiki\EnterpriseWikiPostIngestQaService;
use App\Services\EnterpriseWiki\EnterpriseWikiVerifyPageClaimsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 8I-3/8I-4 end-to-end: EnterpriseWikiDocument -> maintainer decision -> apply ->
 * article/summary + concept/entity generation with inline [[wikilinks]] -> materialization
 * -> EnterpriseWikiPageLink -> backlinks -> graph edges -> traversal, with the existing
 * claims/verification/lint/QA stages continuing unchanged afterward.
 *
 * All AI calls are mocked. Queue::fake() means jobs are driven manually in this test in the
 * exact order the real staged queues would run them, since no queue worker is running.
 */
class EnterpriseWikiWikilinkEndToEndIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_flow_produces_clickable_links_backlinks_graph_edges_and_traversal(): void
    {
        config(['services.enterprise_wiki.ai_enabled' => true]);
        Queue::fake();

        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = app(EnterpriseWikiDocumentFlowService::class)->prepareRunForDocument($customer->id, $document->id)['run'];

        $decision = [
            'source_article' => ['action' => 'create', 'title' => 'Artikkel', 'proposed_slug' => 'artikkel-test', 'reason' => 'Source article.'],
            'source_summary' => ['action' => 'create', 'title' => 'Sammendrag', 'proposed_slug' => 'sammendrag-test', 'reason' => 'Summary.'],
            'concept_pages' => [['action' => 'create', 'title' => 'Konsept', 'proposed_slug' => 'konsept-test', 'reason' => 'Key concept.']],
            'entity_pages' => [['action' => 'create', 'title' => 'Entitet', 'proposed_slug' => 'entitet-test', 'reason' => 'Key entity.']],
            'no_action_reason' => null,
            'warnings' => [],
        ];

        $this->mock(EnterpriseWikiMaintainerDecisionService::class)
            ->shouldReceive('runForDocument')
            ->once()
            ->andReturn($decision);

        // The AI content generator: article/summary link to concept+entity, concept/entity
        // link back to the article. All slugs are known in advance from the decision above.
        $this->mock(WikiPageContentAiClient::class)
            ->shouldReceive('generatePageFromSource')
            ->andReturnUsing(function (
                string $pageTitle,
                string $pageType,
                string $sourceText,
                string $languageCode,
                string $additionalContext = '',
                array $linkCatalog = [],
                array $sourceElements = [],
            ): array {
                $markdown = match ($pageType) {
                    'article' => "# Artikkel\n\nProsjektets [[konsept-test|nøkkelkonsept]] eies av [[entitet-test|entiteten]].",
                    'summary' => "# Sammendrag\n\nKort om [[konsept-test]].",
                    'concept' => "# Konsept\n\nSe [[artikkel-test|hovedartikkelen]] for detaljer.",
                    'entity' => "# Entitet\n\nOmtalt i [[artikkel-test|hovedartikkelen]].",
                    default => '# Page',
                };

                return $this->structuredPageResult($markdown, $sourceElements);
            });

        // Claims/verification/lint/QA are mocked to isolate this test to the wikilink flow —
        // their own AI calls and behavior are covered by EnterpriseWikiDocumentFlowServiceTest.
        $this->mock(EnterpriseWikiExtractPageClaimsService::class)->shouldReceive('extract')->once()->andReturn(['pages' => 4, 'claims' => 0, 'skipped' => 0]);
        $this->mock(EnterpriseWikiVerifyPageClaimsService::class)->shouldReceive('verify')->once()->andReturn(['pages' => 4, 'claims' => 0, 'references' => 0, 'skipped' => 0, 'no_support' => 0]);
        $this->mock(EnterpriseWikiAppliedRunLintService::class)->shouldReceive('lint')->once()->andReturn(['pages_checked' => 4, 'claims_checked' => 0, 'source_refs_checked' => 0, 'links_checked' => 0, 'findings_created' => 0, 'findings_skipped' => 0, 'findings_resolved' => 0, 'errors' => 0, 'warnings' => 0, 'info' => 0]);
        $this->mock(EnterpriseWikiLinkSemanticRepairService::class)->shouldReceive('repairForRun')->once()->andReturn(['pages_reviewed' => 4, 'applied' => 0, 'skipped' => 4, 'failed' => 0]);
        $this->mock(EnterpriseWikiPostIngestQaService::class)
            ->shouldReceive('runForRun')
            ->once()
            ->andReturnUsing(function (EnterpriseWikiIngestRun $run) {
                $run->update([
                    'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_PASSED,
                    'qa_completed_at' => now(),
                ]);

                return ['pass' => true, 'quality_score' => 1.0];
            });

        // --- Drive the staged flow manually (Queue::fake() means nothing runs automatically) ---

        app(EnterpriseWikiDocumentFlowService::class)->run($run->id);

        // Phase 1: article + summary.
        Queue::assertPushed(GenerateEnterpriseWikiAppliedPage::class, 2);
        $article = EnterpriseWikiPage::query()->where('customer_id', $customer->id)->where('slug', 'artikkel-test')->firstOrFail();
        $summary = EnterpriseWikiPage::query()->where('customer_id', $customer->id)->where('slug', 'sammendrag-test')->firstOrFail();
        $concept = EnterpriseWikiPage::query()->where('customer_id', $customer->id)->where('slug', 'konsept-test')->firstOrFail();
        $entity = EnterpriseWikiPage::query()->where('customer_id', $customer->id)->where('slug', 'entitet-test')->firstOrFail();

        (new GenerateEnterpriseWikiAppliedPage($run->id, $article->id))->handle(app(EnterpriseWikiGenerateAppliedPagesService::class));
        (new GenerateEnterpriseWikiAppliedPage($run->id, $summary->id))->handle(app(EnterpriseWikiGenerateAppliedPagesService::class));
        (new FinalizeEnterpriseWikiPageGeneration($run->id))->handle();

        // Phase 2: concept + entity, dispatched once phase 1 completed.
        Queue::assertPushed(GenerateEnterpriseWikiAppliedPage::class, 4);
        (new GenerateEnterpriseWikiAppliedPage($run->id, $concept->id))->handle(app(EnterpriseWikiGenerateAppliedPagesService::class));
        (new GenerateEnterpriseWikiAppliedPage($run->id, $entity->id))->handle(app(EnterpriseWikiGenerateAppliedPagesService::class));
        (new FinalizeEnterpriseWikiPageGeneration($run->id))->handle();

        Queue::assertPushed(ContinueEnterpriseWikiDocumentFlowAfterPages::class, 1);
        (new ContinueEnterpriseWikiDocumentFlowAfterPages($run->id))->handle(app(EnterpriseWikiDocumentFlowService::class));

        // --- 45: canonical content_markdown keeps raw [[wikilinks]] ---
        $articleVersion = EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $article->id)->where('is_current', true)->first();
        $this->assertStringContainsString('[[konsept-test|nøkkelkonsept]]', $articleVersion->content_markdown);
        $this->assertStringContainsString('[[entitet-test|entiteten]]', $articleVersion->content_markdown);

        // --- 46: rendered_markdown gives clickable internal links (via the detail controller) ---
        $user = $this->createUser($customer);
        $response = $this->actingAs($user)->get('/app/wiki/'.$article->slug);
        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($concept, $entity): bool {
            $rendered = data_get($inertia, 'props.current_version.rendered_markdown');

            return str_contains($rendered, "[nøkkelkonsept](/app/wiki/{$concept->slug})")
                && str_contains($rendered, "[entiteten](/app/wiki/{$entity->slug})")
                && ! str_contains($rendered, '[[');
        });

        // --- 47: at least one canonical DB link is created ---
        $this->assertGreaterThanOrEqual(
            1,
            EnterpriseWikiPageLink::query()
                ->where('customer_id', $customer->id)
                ->where('link_type', EnterpriseWikiPageLink::LINK_TYPE_WIKILINK)
                ->count(),
        );
        $articleToConceptLink = EnterpriseWikiPageLink::query()
            ->where('customer_id', $customer->id)
            ->where('from_page_id', $article->id)
            ->where('to_page_id', $concept->id)
            ->where('link_type', EnterpriseWikiPageLink::LINK_TYPE_WIKILINK)
            ->first();
        $this->assertNotNull($articleToConceptLink);

        // --- 48: backlink exists on the target page ---
        $backlinkResponse = $this->actingAs($user)->get('/app/wiki/'.$concept->slug);
        $backlinkResponse->assertOk();
        $backlinkResponse->assertViewHas('page', function (array $inertia) use ($article): bool {
            $backlinks = data_get($inertia, 'props.backlinks', []);

            return collect($backlinks)->contains(fn (array $b) => $b['slug'] === $article->slug);
        });

        // --- 49: graph-data returns the same relation as an edge ---
        $graphResponse = $this->actingAs($user)->getJson('/app/wiki/graph-data');
        $graphResponse->assertOk();
        $edges = collect($graphResponse->json('edges'));
        $this->assertTrue($edges->contains(
            fn (array $edge) => $edge['from_page_id'] === $article->id && $edge['to_page_id'] === $concept->id,
        ));

        // --- 50: traversal returns the same relation ---
        $traversalService = app(EnterpriseWikiPageTraversalService::class);
        $this->assertTrue($traversalService->outgoing($article)->contains('id', $concept->id));
        $this->assertTrue($traversalService->incoming($concept)->contains('id', $article->id));

        // --- 51: no combinatoric relations were created by the new flow ---
        $this->assertSame(
            0,
            EnterpriseWikiPageLink::query()
                ->where('customer_id', $customer->id)
                ->whereIn('link_type', [
                    EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_SUMMARY,
                    EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_CONCEPT,
                    EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_ENTITY,
                    EnterpriseWikiPageLink::LINK_TYPE_SUMMARY_TO_CONCEPT,
                    EnterpriseWikiPageLink::LINK_TYPE_SUMMARY_TO_ENTITY,
                ])
                ->count(),
        );

        // --- 52: claims/verification/lint/QA ran and the run completed ---
        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createCustomer(string $name = 'Test AS'): Customer
    {
        $language = Language::query()->firstOrCreate(
            ['code' => 'no'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk'],
        );

        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO'],
        );

        return Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    private function createUser(Customer $customer): User
    {
        // System owner: draft pages created by the (mocked) apply-decision step must be
        // visible so this test can inspect their rendered_markdown/backlinks props.
        return User::query()->create([
            'name' => 'Test User',
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
            'original_filename' => 'source.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'This is the extracted text from the source document.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $sourceElements
     * @return array{markdown: string, blocks: list<array<string, mixed>>}
     */
    private function structuredPageResult(string $markdown, array $sourceElements): array
    {
        $sourceElement = $sourceElements[0] ?? [
            'source_element_key' => 'document-1-full-text',
            'source_element_type' => 'manual',
        ];

        return [
            'markdown' => $markdown,
            'blocks' => [
                [
                    'markdown' => $markdown,
                    'content_origin' => 'source_based',
                    'source_element_keys' => [(string) $sourceElement['source_element_key']],
                    'source_element_types' => [(string) $sourceElement['source_element_type']],
                    'best_practice_reason' => null,
                    'link_intents' => [],
                ],
            ],
        ];
    }
}
