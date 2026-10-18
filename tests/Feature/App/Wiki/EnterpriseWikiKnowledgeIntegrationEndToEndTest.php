<?php

namespace Tests\Feature\App\Wiki;

use App\Data\Ai\AiCallContext;
use App\Jobs\EnterpriseWiki\ContinueEnterpriseWikiDocumentFlowAfterPages;
use App\Jobs\EnterpriseWiki\FinalizeEnterpriseWikiPageGeneration;
use App\Jobs\EnterpriseWiki\GenerateEnterpriseWikiAppliedPage;
use App\Jobs\EnterpriseWiki\ProjectEnterpriseWikiPageToGraph;
use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use App\Models\EnterpriseWikiPageRelinkAttempt;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\Ai\Wiki\WikiPageContentAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentFlowService;
use App\Services\EnterpriseWiki\EnterpriseWikiExtractPageClaimsService;
use App\Services\EnterpriseWiki\EnterpriseWikiGenerateAppliedPagesService;
use App\Services\EnterpriseWiki\EnterpriseWikiInboundLinkInserter;
use App\Services\EnterpriseWiki\EnterpriseWikiLinkCatalogService;
use App\Services\EnterpriseWiki\EnterpriseWikiLinkIntegrationCheckService;
use App\Services\EnterpriseWiki\EnterpriseWikiLinkSemanticRepairService;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionService;
use App\Services\EnterpriseWiki\EnterpriseWikiPageContentBlockService;
use App\Services\EnterpriseWiki\EnterpriseWikiVerifyPageClaimsService;
use App\Services\OpenAi\OpenAiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\Concerns\GrantsWikiPermissions;
use Tests\TestCase;

/**
 * Arbeidspakke B, B6 (docs/enterprise-wiki-knowledge-integration-plan.md §11.1): proof that a new
 * source document is built ONTO existing Wiki knowledge — from the planner's reference, through the
 * prioritised link catalog, the model's structured link_intents, materialization, link rows and
 * inbound relinking, to the graph API — and that the technical link check agrees.
 *
 * The mock sits on OpenAiClient, not on WikiPageContentAiClient: the real schema (with the catalog
 * enum), the real prompt, the real parser, the real materializer and the real inbound-link inserter
 * all run. The model's answers are fixed, so the test is deterministic. The maintainer decision is
 * mocked at service level, as in EnterpriseWikiWikilinkEndToEndIntegrationTest; claim extraction,
 * verification and AI link repair are outside this test's subject and mocked.
 *
 * Nothing here injects ready-made [[wikilinks]]: every link in the result was written by the backend
 * from a link intent.
 */
class EnterpriseWikiKnowledgeIntegrationEndToEndTest extends TestCase
{
    use GrantsWikiPermissions;
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    private array $generationPayloads = [];

    /** @var list<string> */
    private array $inboundPrompts = [];

    public function test_a_new_document_is_linked_into_existing_knowledge_from_link_intents_to_the_graph_api(): void
    {
        config(['services.enterprise_wiki.ai_enabled' => true]);
        Queue::fake();

        // --- Existing knowledge: two relevant, OLD pages, buried under newer unrelated ones ---
        $customer = $this->createCustomer('Kunde A');
        $owner = $this->createUser($customer);
        $supplierAssessment = $this->createExistingPage($customer, 'leverandorvurdering', 'Leverandørvurdering', [
            '# Leverandørvurdering',
            'En leverandørvurdering avgjør om en leverandør kan brukes. Funnene følges opp gjennom løpende leverandørkontroll.',
        ]);
        $riskAssessment = $this->createExistingPage($customer, 'risikovurdering', 'Risikovurdering', [
            '# Risikovurdering',
            'En risikovurdering identifiserer og vurderer risiko i leveranser.',
        ]);
        $supplierAssessment->update(['status' => EnterpriseWikiPage::STATUS_APPROVED, 'published_version_id' => $this->currentVersion($supplierAssessment)->id]);
        $publishedVersionId = $supplierAssessment->published_version_id;
        EnterpriseWikiPage::query()->whereKey([$supplierAssessment->id, $riskAssessment->id])->update(['updated_at' => now()->subYears(2)]);

        for ($i = 1; $i <= EnterpriseWikiLinkCatalogService::MAX_OTHER_PAGES + 5; $i++) {
            $this->createExistingPage($customer, "nyere-side-{$i}", "Nyere side {$i}", ["# Nyere side {$i}", 'Handler om noe helt annet.']);
        }

        $archived = $this->createExistingPage($customer, 'gammel-leverandorrutine', 'Gammel leverandørrutine', ['# Gammel leverandørrutine', 'Utgått.']);
        $archived->update(['status' => EnterpriseWikiPage::STATUS_ARCHIVED]);

        // --- Another customer with a page under the same slug ---
        $otherCustomer = $this->createCustomer('Kunde B');
        $foreign = $this->createExistingPage($otherCustomer, 'leverandorvurdering', 'Leverandørvurdering', ['# Leverandørvurdering', 'Kunde Bs egen side.']);
        $otherUser = $this->createUser($otherCustomer);

        // --- The new source document ---
        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'leverandorkontroll-prosedyre.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Leverandørkontroll gjennomføres årlig og bygger på leverandørvurdering og risikovurdering.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
            'owner_user_id' => $owner->id,
        ]);
        $run = app(EnterpriseWikiDocumentFlowService::class)->prepareRunForDocument($customer->id, $document->id)['run'];

        $decisionService = $this->mock(EnterpriseWikiMaintainerDecisionService::class);
        $decisionService->shouldReceive('preparePersistedCandidateBatchesForDocument')->once()
            ->with($customer->id, $document->id, 'no', Mockery::type(AiCallContext::class))->andReturn(null);
        $decisionService->shouldReceive('runForDocument')->once()->andReturn([
            'source_article' => [
                'action' => 'create',
                'title' => 'Leverandørkontroll',
                'proposed_slug' => 'leverandorkontroll-ab12cd',
                'reason' => 'Prosedyren for leverandørkontroll.',
                'related_page_guidance' => [
                    // The planner names existing pages by stable id (D5). Two are valid; one belongs
                    // to another customer and one is archived — both must be discarded by the backend.
                    ['page_title' => 'Leverandørvurdering', 'page_id' => $supplierAssessment->id, 'relationship' => 'Lenk til vurderingen kontrollen bygger på.'],
                    ['page_title' => 'Risikovurdering', 'page_id' => $riskAssessment->id, 'relationship' => 'Lenk til risikovurderingen.'],
                    ['page_title' => 'Leverandørvurdering', 'page_id' => $foreign->id, 'relationship' => 'Feil kunde.'],
                    ['page_title' => 'Gammel leverandørrutine', 'page_id' => $archived->id, 'relationship' => 'Arkivert.'],
                ],
            ],
            'source_summary' => ['action' => 'create', 'title' => 'Leverandørkontroll – sammendrag', 'proposed_slug' => 'leverandorkontroll-sammendrag-ab12cd', 'reason' => 'Sammendrag.'],
            'concept_pages' => [],
            'entity_pages' => [],
            'no_action_reason' => null,
            'warnings' => [],
        ]);

        $wholeDocumentKey = EnterpriseWikiPageContentBlockService::wholeDocumentElementKey((int) $document->id);
        $this->mockOpenAi(function (array $payload) use ($supplierAssessment, $riskAssessment, $archived, $foreign, $wholeDocumentKey): array {
            return match ($payload['text']['format']['name'] ?? null) {
                'wiki_page_content_generation' => $this->generationResponse($payload, $wholeDocumentKey, $supplierAssessment, $riskAssessment, $archived, $foreign),
                'wiki_inbound_link_proposal' => $this->inboundResponse($payload),
                default => throw new \RuntimeException('Unexpected AI operation in this test: '.json_encode($payload['text']['format']['name'] ?? null)),
            };
        });

        // First continuation: the claim step reports another worker busy, so the continuation
        // stops and is retried — the retry re-runs materialization and relinking from the top.
        $extractCalls = 0;
        $this->mock(EnterpriseWikiExtractPageClaimsService::class)->shouldReceive('extract')->twice()
            ->andReturnUsing(function (EnterpriseWikiIngestRun $run) use (&$extractCalls): array {
                if (++$extractCalls === 1) {
                    return ['pages' => 2, 'claims' => 0, 'skipped' => 0, 'busy' => 1];
                }

                EnterpriseWikiIngestRunPage::query()->where('enterprise_wiki_ingest_run_id', $run->id)->update(['claims_extracted_at' => now()]);

                return ['pages' => 2, 'claims' => 0, 'skipped' => 0, 'busy' => 0];
            });
        $verify = $this->mock(EnterpriseWikiVerifyPageClaimsService::class);
        $verify->shouldReceive('unverifiedClaimIdsForRun')->andReturn([]);
        $verify->shouldReceive('hasActiveClaimLeaseForRun')->andReturn(false);
        $verify->shouldNotReceive('verify');
        $this->mock(EnterpriseWikiLinkSemanticRepairService::class)->shouldReceive('repairForRun')->andReturn(['pages_reviewed' => 2, 'applied' => 0, 'skipped' => 2, 'failed' => 0]);

        // --- Drive the staged flow (Queue::fake(): nothing runs on its own) ---
        app(EnterpriseWikiDocumentFlowService::class)->run($run->id);
        $article = EnterpriseWikiPage::query()->where('customer_id', $customer->id)->where('slug', 'leverandorkontroll-ab12cd')->firstOrFail();
        $summary = EnterpriseWikiPage::query()->where('customer_id', $customer->id)->where('slug', 'leverandorkontroll-sammendrag-ab12cd')->firstOrFail();

        $generator = app(EnterpriseWikiGenerateAppliedPagesService::class);
        (new GenerateEnterpriseWikiAppliedPage($run->id, $article->id))->handle($generator);
        (new GenerateEnterpriseWikiAppliedPage($run->id, $summary->id))->handle($generator);
        (new FinalizeEnterpriseWikiPageGeneration($run->id))->handle();

        // 1. Existing knowledge reached the generator as link candidates: both planned pages are in
        //    the schema enum and the prompt (with an excerpt), although 65 newer pages exist; the
        //    archived page and the other customer's page are not.
        $articlePayload = collect($this->generationPayloads)->first(fn (array $p): bool => str_contains($this->promptText($p), 'Page title: Leverandørkontroll'."\n"));
        $this->assertNotNull($articlePayload);
        $enum = $this->targetEnum($articlePayload);
        $this->assertContains($supplierAssessment->id, $enum);
        $this->assertContains($riskAssessment->id, $enum);
        $this->assertNotContains($archived->id, $enum);
        $this->assertNotContains($foreign->id, $enum);
        $this->assertCount(count(array_unique($enum)), $enum, 'no candidate is offered twice');
        $this->assertLessThanOrEqual(EnterpriseWikiLinkCatalogService::MAX_OTHER_PAGES + 1, count($enum), 'catalog budget: 60 outside the run + the run\'s summary');
        $this->assertStringContainsString('En leverandørvurdering avgjør om en leverandør kan brukes.', $this->promptText($articlePayload), 'the generator sees what the page says, not only its title');

        $articlePivot = $this->pivot($run, $article);
        $this->assertSame([$supplierAssessment->id, $riskAssessment->id], $articlePivot->link_assessment['planned_references']['page_ids']);
        // The foreign id is discarded; its title is then resolved within THIS customer only, finds
        // the page already accepted, and is recorded as a duplicate — never as the foreign page.
        $this->assertEqualsCanonicalizing(
            [[$foreign->id, 'cross_customer'], [$supplierAssessment->id, 'duplicate'], [$archived->id, 'inactive_status']],
            array_map(fn (array $r): array => [$r['page_id'], $r['reason']], $articlePivot->link_assessment['planned_references']['rejected']),
        );

        // 2. The model's intents: two valid, two invalid. The invalid ones are rejected and
        //    recorded; the page is NOT failed and the valid links are kept.
        $this->assertSame(EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED, $articlePivot->generation_status);
        $this->assertSame('linked', $articlePivot->link_assessment['outcome']);
        $this->assertSame(4, $articlePivot->link_assessment['proposed']);
        $this->assertEqualsCanonicalizing([$supplierAssessment->id, $riskAssessment->id], array_column($articlePivot->link_assessment['materialized'], 'target_page_id'));
        $this->assertEqualsCanonicalizing(
            [[$archived->id, 'rejected_inactive_target'], [$foreign->id, 'rejected_cross_customer']],
            array_map(fn (array $r): array => [$r['target_page_id'], $r['outcome']], $articlePivot->link_assessment['rejected']),
        );
        $this->assertTrue($articlePivot->link_assessment['has_rejections']);

        // --- Continuation (twice: the first stops at the busy claim step) ---
        (new ContinueEnterpriseWikiDocumentFlowAfterPages($run->id))->handle(app(EnterpriseWikiDocumentFlowService::class));
        $afterFirstContinuation = $this->snapshotCounts($customer);
        (new ContinueEnterpriseWikiDocumentFlowAfterPages($run->id))->handle(app(EnterpriseWikiDocumentFlowService::class));

        // 3. Materialized markdown: the backend wrote canonical links on the model's anchors.
        $articleVersion = $this->currentVersion($article);
        $this->assertStringContainsString('[[leverandorvurdering|leverandørvurdering]]', $articleVersion->content_markdown);
        $this->assertStringContainsString('[[risikovurdering|risikovurdering]]', $articleVersion->content_markdown);
        $this->assertStringNotContainsString($archived->slug, $articleVersion->content_markdown);
        $this->assertSame(1, substr_count($articleVersion->content_markdown, '[[leverandorvurdering|'), 'the foreign page with the same slug never became a second link');

        // 4. Rows: the same relations, customer-scoped.
        $this->assertSame([$supplierAssessment->id, $riskAssessment->id, $summary->id], $this->wikilinkTargets($article));
        $this->assertSame(0, EnterpriseWikiPageLink::query()->where('customer_id', $customer->id)->whereIn('to_page_id', [$archived->id, $foreign->id])->count());
        $this->assertSame(0, EnterpriseWikiPageLink::query()->where('from_page_id', $article->id)->where('customer_id', '!=', $customer->id)->count());
        Queue::assertPushed(ProjectEnterpriseWikiPageToGraph::class, fn (ProjectEnterpriseWikiPageToGraph $job): bool => $job->pageId === $article->id);

        // 5. Inbound: the existing published page got ONE link to the new article, inserted into
        //    unchanged text, as a new draft working version; the published version still serves.
        $supplierAssessment->refresh();
        $inboundVersion = $this->currentVersion($supplierAssessment);
        $this->assertSame("# Leverandørvurdering\n\nEn leverandørvurdering avgjør om en leverandør kan brukes. Funnene følges opp gjennom [[leverandorkontroll-ab12cd|løpende leverandørkontroll]].", $inboundVersion->content_markdown);
        $this->assertSame(
            EnterpriseWikiPageVersion::query()->find($publishedVersionId)->content_markdown,
            EnterpriseWikiInboundLinkInserter::stripWikilinks($inboundVersion->content_markdown),
            'only link markup was added',
        );
        $this->assertSame(EnterpriseWikiPage::STATUS_DRAFT, $supplierAssessment->status);
        $this->assertSame($publishedVersionId, $supplierAssessment->published_version_id, 'nothing was published automatically');
        $this->assertSame([$article->id], $this->wikilinkTargets($supplierAssessment));
        $this->assertSame(1, $this->currentVersion($riskAssessment)->version_number, 'the model declined there, so nothing changed');
        $this->assertCount(2, $this->inboundPrompts, 'one bounded proposal call per candidate, none on the retry');

        // 6. Retry: the second continuation re-ran materialization and relinking, and nothing was
        //    duplicated — no rows, versions, attempts or assessments.
        $this->assertSame($afterFirstContinuation, $this->snapshotCounts($customer));
        $this->assertSame(1, EnterpriseWikiPageRelinkAttempt::query()->where('enterprise_wiki_ingest_run_id', $run->id)->where('status', EnterpriseWikiPageRelinkAttempt::STATUS_APPLIED)->count());
        (new GenerateEnterpriseWikiAppliedPage($run->id, $article->id))->handle($generator);
        $this->assertSame(1, EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $article->id)->count(), 'a repeated generation job is a no-op');

        // 7. The technical link check agrees, and QA recorded it.
        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status, (string) $run->qa_last_error);
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $run->status);
        $linkCheck = $run->qa_result['checks']['link_integration'];
        $this->assertSame(EnterpriseWikiLinkIntegrationCheckService::STATUS_CHECKED, $linkCheck['status']);
        $this->assertSame([], $linkCheck['mismatches']);
        $this->assertSame([], $linkCheck['assessment_missing']);
        $this->assertSame(3, $linkCheck['pages_checked'], 'article, summary and the relinked existing page');
        $this->assertSame(EnterpriseWikiLinkIntegrationCheckService::NEO4J_NOT_VERIFIED, $linkCheck['neo4j_projection']);
        $this->assertNotNull($this->pivot($run, $article)->links_assessed_at);

        // 8. Graph API returns exactly these relations, with the recorded reasons.
        $user = $this->createUser($customer);
        $edges = collect($this->actingAs($user)->getJson('/app/wiki/graph-data?page_id='.$article->id)->assertOk()->json('edges'));
        $pairs = $edges->map(fn (array $e): array => [$e['from_page_id'], $e['to_page_id']])->all();
        $this->assertEqualsCanonicalizing([
            [$article->id, $supplierAssessment->id],
            [$article->id, $riskAssessment->id],
            [$article->id, $summary->id],
            [$summary->id, $article->id],
            [$supplierAssessment->id, $article->id],
        ], $pairs);
        $toSupplier = $edges->first(fn (array $e): bool => $e['from_page_id'] === $article->id && $e['to_page_id'] === $supplierAssessment->id);
        $this->assertSame('Kontrollen bygger på leverandørvurderingen.', $toSupplier['intents'][0]['reason']);
        $inbound = $edges->first(fn (array $e): bool => $e['from_page_id'] === $supplierAssessment->id);
        $this->assertSame('Avsnittet omtaler oppfølgingen den nye artikkelen beskriver.', $inbound['intents'][0]['reason']);

        // 9. Customer isolation: the other customer sees none of it.
        $otherEdges = collect($this->actingAs($otherUser)->getJson('/app/wiki/graph-data')->assertOk()->json('edges'));
        $this->assertFalse($otherEdges->contains(fn (array $e): bool => in_array($article->id, [$e['from_page_id'], $e['to_page_id']], true)));
        $this->assertSame(1, $this->currentVersion($foreign)->version_number);
        $this->actingAs($otherUser)->getJson('/app/wiki/graph-data?page_id='.$article->id)->assertStatus(422);
    }

    public function test_an_inconsistent_relation_set_is_never_reported_as_technically_checked(): void
    {
        config(['services.enterprise_wiki.ai_enabled' => true]);
        Queue::fake();

        $customer = $this->createCustomer('Kunde A');
        $existing = $this->createExistingPage($customer, 'leverandorvurdering', 'Leverandørvurdering', ['# Leverandørvurdering', 'Tekst.']);
        $page = $this->createExistingPage($customer, 'leverandorkontroll', 'Leverandørkontroll', ['# Leverandørkontroll', 'Bygger på [[leverandorvurdering|leverandørvurdering]].']);
        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => 1,
            'status' => EnterpriseWikiIngestRun::STATUS_QA,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
        ]);
        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
            'link_assessment' => ['schema' => 1, 'outcome' => 'linked'],
            'links_assessed_at' => now(),
        ]);
        $check = app(EnterpriseWikiLinkIntegrationCheckService::class);

        // The markdown links, but no row was ever materialized.
        $result = $check->check($run);
        $this->assertSame(EnterpriseWikiLinkIntegrationCheckService::STATUS_MISMATCH, $result['status']);
        $this->assertSame([['page_id' => $page->id, 'kind' => 'missing_rows', 'target_page_ids' => [$existing->id]]], $result['mismatches']);

        // A stale row the text no longer supports.
        EnterpriseWikiPageLink::query()->create(['customer_id' => $customer->id, 'from_page_id' => $page->id, 'to_page_id' => $existing->id, 'link_type' => EnterpriseWikiPageLink::LINK_TYPE_WIKILINK, 'source' => EnterpriseWikiPageLink::SOURCE_DETERMINISTIC, 'confidence' => EnterpriseWikiPageLink::CONFIDENCE_CERTAIN]);
        $this->assertSame(EnterpriseWikiLinkIntegrationCheckService::STATUS_CHECKED, $check->check($run)['status']);
        EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $page->id)->update(['content_markdown' => "# Leverandørkontroll\n\nUten lenke."]);
        $this->assertSame('stale_rows', $check->check($run)['mismatches'][0]['kind']);

        // Not assessed at all — distinct from "assessed, no relations".
        EnterpriseWikiPageLink::query()->where('from_page_id', $page->id)->delete();
        EnterpriseWikiIngestRunPage::query()->where('enterprise_wiki_ingest_run_id', $run->id)->update(['links_assessed_at' => null]);
        $this->assertSame(EnterpriseWikiLinkIntegrationCheckService::STATUS_NOT_COMPLETED, $check->check($run)['status']);

        // All proposals rejected: consistent, but follow-up, never "checked".
        EnterpriseWikiIngestRunPage::query()->where('enterprise_wiki_ingest_run_id', $run->id)->update(['links_assessed_at' => now(), 'link_assessment' => json_encode(['schema' => 1, 'outcome' => 'rejected_only'])]);
        $this->assertSame(EnterpriseWikiLinkIntegrationCheckService::STATUS_NEEDS_FOLLOWUP, $check->check($run)['status']);
    }

    // =========================================================================
    // Mocked model answers
    // =========================================================================

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function generationResponse(array $payload, string $wholeDocumentKey, EnterpriseWikiPage $supplierAssessment, EnterpriseWikiPage $riskAssessment, EnterpriseWikiPage $archived, EnterpriseWikiPage $foreign): array
    {
        $this->generationPayloads[] = $payload;
        $isArticle = str_contains($this->promptText($payload), 'Page title: Leverandørkontroll'."\n");

        $blocks = $isArticle
            ? [
                ['markdown' => '# Leverandørkontroll', 'content_origin' => 'structural', 'source_element_keys' => [], 'source_element_types' => [], 'best_practice_reason' => null, 'link_intents' => []],
                [
                    'markdown' => 'Leverandørkontroll gjennomføres årlig og bygger på leverandørvurdering og risikovurdering. Arkiverte rutiner og leveransen omtales også.',
                    'content_origin' => 'source_based',
                    'source_element_keys' => [$wholeDocumentKey],
                    'source_element_types' => ['manual'],
                    'best_practice_reason' => null,
                    'link_intents' => [
                        ['intent_id' => 'lv', 'target_page_id' => $supplierAssessment->id, 'anchor_text' => 'leverandørvurdering', 'reason' => 'Kontrollen bygger på leverandørvurderingen.'],
                        ['intent_id' => 'rv', 'target_page_id' => $riskAssessment->id, 'anchor_text' => 'risikovurdering', 'reason' => 'Risikovurderingen styrer kontrollomfanget.'],
                        // The model returns two ids it may not use; the schema enum would normally
                        // stop this, so the backend must stop it too.
                        ['intent_id' => 'arkiv', 'target_page_id' => $archived->id, 'anchor_text' => 'Arkiverte rutiner', 'reason' => 'Ugyldig: arkivert.'],
                        ['intent_id' => 'fremmed', 'target_page_id' => $foreign->id, 'anchor_text' => 'leveransen', 'reason' => 'Ugyldig: annen kunde.'],
                    ],
                ],
            ]
            : [
                ['markdown' => "# Leverandørkontroll – sammendrag\n\nKort om årlig leverandørkontroll.", 'content_origin' => 'source_based', 'source_element_keys' => [$wholeDocumentKey], 'source_element_types' => ['manual'], 'best_practice_reason' => null, 'link_intents' => []],
            ];

        return $this->response(['page' => ['blocks' => $blocks], 'best_practice_review' => []]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function inboundResponse(array $payload): array
    {
        $prompt = $this->promptText($payload);
        $this->inboundPrompts[] = $prompt;

        if (str_contains($prompt, 'EXISTING PAGE: Leverandørvurdering')) {
            return $this->response([
                'decision' => 'link',
                'block_key' => 'block-0002',
                'anchor_text' => 'løpende leverandørkontroll',
                'reason' => 'Avsnittet omtaler oppfølgingen den nye artikkelen beskriver.',
            ]);
        }

        return $this->response(['decision' => 'no_link', 'block_key' => null, 'anchor_text' => null, 'reason' => null]);
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $answer
     */
    private function mockOpenAi(callable $answer): void
    {
        $this->mock(OpenAiClient::class)->shouldReceive('createResponse')
            ->andReturnUsing(fn (array $payload) => $answer($payload));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function response(array $body): array
    {
        return [
            'id' => 'resp_'.Str::lower(Str::random(6)),
            'status' => 'completed',
            'output_text' => json_encode($body, JSON_UNESCAPED_UNICODE),
            'output' => [],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 50, 'total_tokens' => 150],
            '_meta' => ['request_id' => 'req_test', 'http_status' => 200, 'provider' => 'openai'],
        ];
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** @param  array<string, mixed>  $payload */
    private function promptText(array $payload): string
    {
        return collect($payload['input'] ?? [])
            ->flatMap(fn (array $message): array => array_column((array) ($message['content'] ?? []), 'text'))
            ->implode("\n");
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<int>
     */
    private function targetEnum(array $payload): array
    {
        return (array) data_get($payload, 'text.format.schema.properties.page.properties.blocks.items.properties.link_intents.items.properties.target_page_id.enum', []);
    }

    /** @return array<string, int> */
    private function snapshotCounts(Customer $customer): array
    {
        $pageIds = EnterpriseWikiPage::query()->where('customer_id', $customer->id)->pluck('id');

        return [
            'links' => EnterpriseWikiPageLink::query()->where('customer_id', $customer->id)->count(),
            'versions' => EnterpriseWikiPageVersion::query()->whereIn('enterprise_wiki_page_id', $pageIds)->count(),
            'relink_attempts' => EnterpriseWikiPageRelinkAttempt::query()->where('customer_id', $customer->id)->count(),
            'assessment_hash' => crc32((string) json_encode(EnterpriseWikiIngestRunPage::query()->orderBy('id')->pluck('link_assessment')->all())),
        ];
    }

    /** @return list<int> */
    private function wikilinkTargets(EnterpriseWikiPage $page): array
    {
        return EnterpriseWikiPageLink::query()
            ->where('from_page_id', $page->id)
            ->where('link_type', EnterpriseWikiPageLink::LINK_TYPE_WIKILINK)
            ->orderBy('to_page_id')
            ->pluck('to_page_id')
            ->map(fn ($id): int => (int) $id)
            ->sort()
            ->values()
            ->all();
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

    /**
     * An existing page as the pipeline writes one: blocks with document provenance, joined into
     * content_markdown.
     *
     * @param  list<string>  $paragraphs
     */
    private function createExistingPage(Customer $customer, string $slug, string $title, array $paragraphs): EnterpriseWikiPage
    {
        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => $slug,
            'title' => $title,
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        $blocks = [];
        foreach ($paragraphs as $index => $markdown) {
            $blocks[] = [
                'block_key' => 'block-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                'position' => $index,
                'markdown' => $markdown,
                'content_origin' => $index === 0 ? 'structural' : 'source_based',
                'source_type' => $index === 0 ? null : 'enterprise_wiki_document',
                'source_id' => $index === 0 ? null : 9001,
                'source_elements' => $index === 0 ? [] : [['source_type' => 'enterprise_wiki_document', 'source_id' => 9001, 'source_element_key' => 'document-9001-full-text']],
                'link_intents' => [],
            ];
        }

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => implode("\n\n", $paragraphs),
            'content_blocks_json' => $blocks,
            'generated_by_model' => 'gpt-5',
        ]);

        return $page;
    }

    private function createCustomer(string $name): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

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
        return $this->grantWikiPermissions($customer, User::query()->create([
            'name' => 'Wiki-bruker',
            'email' => Str::lower(Str::random(8)).'@test.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]));
    }
}
