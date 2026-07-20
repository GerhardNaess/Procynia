<?php

namespace Tests\Feature\App\Wiki;

use App\Jobs\Ai\Wiki\ProcessEnterpriseWikiIngest;
use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiLintFinding;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use App\Models\EnterpriseWikiPageLinkQaAttempt;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\Ai\Wiki\WikiLinkRevisionAiClient;
use App\Services\Ai\Wiki\WikiLinkSemanticQaAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiAppliedRunLintService;
use App\Services\EnterpriseWiki\EnterpriseWikiBuildPageLinksService;
use App\Services\EnterpriseWiki\EnterpriseWikiGraphDataService;
use App\Services\EnterpriseWiki\EnterpriseWikiLinkSemanticRepairService;
use App\Services\EnterpriseWiki\EnterpriseWikiPageTraversalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 8I-6: deterministic wikilink lint codes, and AI-assisted semantic QA/repair of
 * inline wikilinks.
 */
class EnterpriseWikiLinkLintAndSemanticRepairTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Deterministic lint codes
    // =========================================================================

    public function test_broken_and_cross_customer_wikilink_is_detected(): void
    {
        $customerA = $this->createCustomer('Customer A');
        $customerB = $this->createCustomer('Customer B');
        $document = $this->createDocument($customerA);
        $foreignPage = $this->createPage($customerB, 'foreign-page', 'Foreign Page');
        $article = $this->createVersionedPage($customerA, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'See [[foreign-page]] here.');
        $run = $this->createAppliedRun($customerA, $document, [$article]);

        $this->lintService()->lint($run);

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $article->id,
            'code' => EnterpriseWikiLintFinding::CODE_BROKEN_WIKILINK,
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
        ]);

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $article->id,
            'code' => EnterpriseWikiLintFinding::CODE_CROSS_CUSTOMER_WIKILINK,
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
        ]);

        $this->assertNotNull($foreignPage);
    }

    public function test_malformed_wikilink_is_detected(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'See [[]] here.');
        $run = $this->createAppliedRun($customer, $document, [$article]);

        $this->lintService()->lint($run);

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $article->id,
            'code' => EnterpriseWikiLintFinding::CODE_MALFORMED_WIKILINK,
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
        ]);
    }

    public function test_self_wikilink_is_detected(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'See [[artikkel]] here.');
        $run = $this->createAppliedRun($customer, $document, [$article]);

        $this->lintService()->lint($run);

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $article->id,
            'code' => EnterpriseWikiLintFinding::CODE_SELF_WIKILINK,
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
        ]);
    }

    public function test_orphan_concept_page_without_incoming_wikilink_is_registered(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $concept = $this->createVersionedPage($customer, 'konsept', 'Konsept', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'A standalone concept.');
        $run = $this->createAppliedRun($customer, $document, [$concept]);

        $this->lintService()->lint($run);

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $concept->id,
            'code' => EnterpriseWikiLintFinding::CODE_CONCEPT_WITHOUT_INCOMING_WIKILINK,
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
        ]);
    }

    public function test_orphan_entity_page_without_incoming_wikilink_is_registered(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $entity = $this->createVersionedPage($customer, 'entitet', 'Entitet', EnterpriseWikiPage::PAGE_TYPE_ENTITY, 'A standalone entity.');
        $run = $this->createAppliedRun($customer, $document, [$entity]);

        $this->lintService()->lint($run);

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $entity->id,
            'code' => EnterpriseWikiLintFinding::CODE_ENTITY_WITHOUT_INCOMING_WIKILINK,
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
        ]);
    }

    public function test_article_with_available_run_targets_but_no_wikilink_is_detected(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'This article has no links at all.');
        $concept = $this->createVersionedPage($customer, 'konsept', 'Konsept', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'A concept.');
        $run = $this->createAppliedRun($customer, $document, [$article, $concept]);

        $this->lintService()->lint($run);

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $article->id,
            'code' => EnterpriseWikiLintFinding::CODE_RUN_TARGETS_AVAILABLE_BUT_NOT_LINKED,
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
        ]);
    }

    public function test_missing_wikilink_materialization_is_detected(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $target = $this->createVersionedPage($customer, 'target-page', 'Target Page', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Target.');
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'See [[target-page|Target Page]] here.');
        $run = $this->createAppliedRun($customer, $document, [$article, $target]);

        // Deliberately do NOT materialize — the current version has a valid wikilink but zero
        // EnterpriseWikiPageLink rows exist yet.
        $this->lintService()->lint($run);

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $article->id,
            'code' => EnterpriseWikiLintFinding::CODE_MISSING_WIKILINK_MATERIALIZATION,
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
        ]);
    }

    public function test_wikilink_projection_mismatch_is_detected_when_under_materialized(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $targetA = $this->createVersionedPage($customer, 'target-a', 'Target A', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'A.');
        $targetB = $this->createVersionedPage($customer, 'target-b', 'Target B', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'B.');
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'See [[target-a]] and [[target-b]] here.');
        $run = $this->createAppliedRun($customer, $document, [$article, $targetA, $targetB]);

        // Materialize only one of the two valid links, simulating drift.
        $this->createPageLinkRow($customer, $article, $targetA, $run);

        $this->lintService()->lint($run);

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $article->id,
            'code' => EnterpriseWikiLintFinding::CODE_WIKILINK_PROJECTION_MISMATCH,
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
        ]);
    }

    public function test_stale_wikilink_graph_edge_is_detected_when_over_materialized(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $target = $this->createVersionedPage($customer, 'target-page', 'Target Page', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Target.');
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'This content no longer links to anything.');
        $run = $this->createAppliedRun($customer, $document, [$article, $target]);

        // A materialized row exists from an earlier version, but the current content dropped it.
        $this->createPageLinkRow($customer, $article, $target, $run);

        $this->lintService()->lint($run);

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $article->id,
            'code' => EnterpriseWikiLintFinding::CODE_STALE_WIKILINK_GRAPH_EDGE,
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
        ]);
    }

    public function test_stale_projection_is_resolved_deterministically_after_rematerializing(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $target = $this->createVersionedPage($customer, 'target-page', 'Target Page', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Target.');
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'No links here anymore.');
        $run = $this->createAppliedRun($customer, $document, [$article, $target]);

        $this->createPageLinkRow($customer, $article, $target, $run);

        $this->lintService()->lint($run);
        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $article->id,
            'code' => EnterpriseWikiLintFinding::CODE_STALE_WIKILINK_GRAPH_EDGE,
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
        ]);

        // Deterministic repair: re-materializing from current content_markdown (no AI involved)
        // removes the stale row.
        app(EnterpriseWikiBuildPageLinksService::class)->materializeWikilinksForPage($article, $run->id);
        $this->lintService()->lint($run);

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $article->id,
            'code' => EnterpriseWikiLintFinding::CODE_STALE_WIKILINK_GRAPH_EDGE,
            'status' => EnterpriseWikiLintFinding::STATUS_RESOLVED,
        ]);
    }

    // =========================================================================
    // Semantic QA + repair
    // =========================================================================

    public function test_semantic_qa_recommends_a_missing_link_and_repair_adds_it(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $concept = $this->createVersionedPage($customer, 'konsept', 'Konsept', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'A concept.');
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'This mentions Konsept without linking it.');
        $run = $this->createAppliedRun($customer, $document, [$article, $concept]);

        $this->mockQaReview(EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'repair_recommended', missing: ['konsept'], remove: []);
        $this->mockRevision('This mentions [[konsept|Konsept]] without linking it.', changed: true);

        $result = $this->repairService()->repairForRun($run);

        $this->assertSame(1, $result['applied']);
        $this->assertDatabaseHas('enterprise_wiki_page_links', [
            'customer_id' => $customer->id,
            'from_page_id' => $article->id,
            'to_page_id' => $concept->id,
            'link_type' => EnterpriseWikiPageLink::LINK_TYPE_WIKILINK,
        ]);
    }

    public function test_repair_removes_a_flagged_invalid_link(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $concept = $this->createVersionedPage($customer, 'konsept', 'Konsept', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'A concept.');
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'This [[konsept|Konsept]] link is wrong here.');
        $run = $this->createAppliedRun($customer, $document, [$article, $concept]);

        $this->mockQaReview(EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'repair_recommended', missing: [], remove: ['konsept']);
        $this->mockRevision('This Konsept link is wrong here.', changed: true);

        $result = $this->repairService()->repairForRun($run);

        $this->assertSame(1, $result['applied']);
        $this->assertDatabaseMissing('enterprise_wiki_page_links', [
            'customer_id' => $customer->id,
            'from_page_id' => $article->id,
            'to_page_id' => $concept->id,
            'link_type' => EnterpriseWikiPageLink::LINK_TYPE_WIKILINK,
        ]);
    }

    public function test_repair_rejects_an_unrequested_added_link(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $concept = $this->createVersionedPage($customer, 'konsept', 'Konsept', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'A concept.');
        $other = $this->createVersionedPage($customer, 'annen-side', 'Annen Side', EnterpriseWikiPage::PAGE_TYPE_ENTITY, 'An entity.');
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'This mentions Konsept without linking it.');
        $run = $this->createAppliedRun($customer, $document, [$article, $concept, $other]);

        $this->mockQaReview(EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'repair_recommended', missing: ['konsept'], remove: []);
        // AI links the wrong (unrequested) target instead of the one asked for.
        $this->mockRevision('This mentions [[annen-side|Konsept]] without linking it.', changed: true);

        $result = $this->repairService()->repairForRun($run);

        $this->assertSame(1, $result['failed']);
        $this->assertDatabaseMissing('enterprise_wiki_page_links', [
            'from_page_id' => $article->id,
            'to_page_id' => $other->id,
        ]);
        $this->assertSame(1, $this->currentVersion($article)->version_number);
    }

    public function test_repair_rejects_a_revision_introducing_a_broken_link(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $concept = $this->createVersionedPage($customer, 'konsept', 'Konsept', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'A concept.');
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'This mentions Konsept without linking it.');
        $run = $this->createAppliedRun($customer, $document, [$article, $concept]);

        $this->mockQaReview(EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'repair_recommended', missing: ['konsept'], remove: []);
        $this->mockRevision('This mentions [[does-not-exist|Konsept]] without linking it.', changed: true);

        $result = $this->repairService()->repairForRun($run);

        $this->assertSame(1, $result['failed']);
        $this->assertSame(1, $this->currentVersion($article)->version_number);
    }

    public function test_repair_rejects_a_revision_introducing_a_self_link(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $concept = $this->createVersionedPage($customer, 'konsept', 'Konsept', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'A concept.');
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'This mentions Konsept without linking it.');
        $run = $this->createAppliedRun($customer, $document, [$article, $concept]);

        $this->mockQaReview(EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'repair_recommended', missing: ['konsept'], remove: []);
        $this->mockRevision('This mentions [[artikkel|Konsept]] without linking it.', changed: true);

        $result = $this->repairService()->repairForRun($run);

        $this->assertSame(1, $result['failed']);
        $this->assertSame(1, $this->currentVersion($article)->version_number);
    }

    public function test_no_change_assessment_creates_no_version(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'This content is fine as-is.');
        $run = $this->createAppliedRun($customer, $document, [$article]);

        $this->mockQaReview(EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'no_change', missing: [], remove: []);

        $result = $this->repairService()->repairForRun($run);

        $this->assertSame(1, $result['skipped']);
        $this->assertSame(1, $this->currentVersion($article)->version_number);
    }

    public function test_changed_repair_creates_exactly_one_version_and_rematerializes(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $concept = $this->createVersionedPage($customer, 'konsept', 'Konsept', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'A concept.');
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'This mentions Konsept without linking it.');
        $originalVersionId = $this->currentVersion($article)->id;
        $run = $this->createAppliedRun($customer, $document, [$article, $concept]);

        $this->mockQaReview(EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'repair_recommended', missing: ['konsept'], remove: []);
        $this->mockRevision('This mentions [[konsept|Konsept]] without linking it.', changed: true);

        $this->repairService()->repairForRun($run);

        $this->assertSame(
            2,
            EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $article->id)->count(),
        );

        $oldVersion = EnterpriseWikiPageVersion::query()->find($originalVersionId);
        $this->assertFalse($oldVersion->is_current);

        $incoming = app(EnterpriseWikiPageTraversalService::class)->incoming($concept);
        $this->assertTrue($incoming->pluck('id')->contains($article->id));

        $graph = app(EnterpriseWikiGraphDataService::class)->build($customer->id, EnterpriseWikiPage::STATUSES, pageId: $concept->id);
        $edgeExists = collect($graph['edges'])->contains(
            fn (array $edge) => $edge['from_page_id'] === $article->id && $edge['to_page_id'] === $concept->id,
        );
        $this->assertTrue($edgeExists);
    }

    public function test_double_repair_does_not_create_a_second_version(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $concept = $this->createVersionedPage($customer, 'konsept', 'Konsept', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'A concept.');
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'This mentions Konsept without linking it.');
        $run = $this->createAppliedRun($customer, $document, [$article, $concept]);

        $this->mockQaReview(EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'repair_recommended', missing: ['konsept'], remove: []);
        $this->mockRevision('This mentions [[konsept|Konsept]] without linking it.', changed: true, times: 1);

        $this->repairService()->repairForRun($run);
        $this->repairService()->repairForRun($run);

        $this->assertSame(
            2,
            EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $article->id)->count(),
        );
        $this->assertSame(
            1,
            EnterpriseWikiPageLinkQaAttempt::query()
                ->where('enterprise_wiki_ingest_run_id', $run->id)
                ->where('enterprise_wiki_page_id', $article->id)
                ->count(),
        );
    }

    public function test_a_new_lint_round_is_green_after_repair(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $concept = $this->createVersionedPage($customer, 'konsept', 'Konsept', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'A concept.');
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'This mentions Konsept without linking it.');
        $run = $this->createAppliedRun($customer, $document, [$article, $concept]);

        // Before repair: article has no outgoing wikilink despite another run page existing.
        $this->lintService()->lint($run);
        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $article->id,
            'code' => EnterpriseWikiLintFinding::CODE_RUN_TARGETS_AVAILABLE_BUT_NOT_LINKED,
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
        ]);

        $this->mockQaReview(EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'repair_recommended', missing: ['konsept'], remove: []);
        $this->mockRevision('This mentions [[konsept|Konsept]] without linking it.', changed: true);

        // repairForRun() re-runs deterministic lint internally after applying the repair.
        $this->repairService()->repairForRun($run);

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $article->id,
            'code' => EnterpriseWikiLintFinding::CODE_RUN_TARGETS_AVAILABLE_BUT_NOT_LINKED,
            'status' => EnterpriseWikiLintFinding::STATUS_RESOLVED,
        ]);
    }

    public function test_process_enterprise_wiki_ingest_not_modified(): void
    {
        $reflection = new \ReflectionClass(ProcessEnterpriseWikiIngest::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringNotContainsString('EnterpriseWikiLinkSemanticRepairService', $source);
        $this->assertStringNotContainsString('WikiLinkSemanticQaAiClient', $source);
        $this->assertStringNotContainsString('repairForRun', $source);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function lintService(): EnterpriseWikiAppliedRunLintService
    {
        return app(EnterpriseWikiAppliedRunLintService::class);
    }

    private function repairService(): EnterpriseWikiLinkSemanticRepairService
    {
        return app(EnterpriseWikiLinkSemanticRepairService::class);
    }

    /**
     * Mocks WikiLinkSemanticQaAiClient::review() so that only the given page type gets the
     * requested assessment — every other page reviewed in the same run (repairForRun() reviews
     * every pivot page) gets a neutral "no_change" response instead.
     */
    private function mockQaReview(string $targetPageType, string $assessment, array $missing, array $remove): void
    {
        $this->mock(WikiLinkSemanticQaAiClient::class)
            ->shouldReceive('review')
            ->andReturnUsing(function (string $content, string $pageType) use ($targetPageType, $assessment, $missing, $remove): array {
                if ($pageType !== $targetPageType) {
                    return [
                        'assessment' => 'no_change',
                        'missing_link_slugs' => [],
                        'remove_link_slugs' => [],
                        'critique' => '',
                        'model' => 'gpt-4.1-mini/1.0',
                    ];
                }

                return [
                    'assessment' => $assessment,
                    'missing_link_slugs' => $missing,
                    'remove_link_slugs' => $remove,
                    'critique' => 'Test critique.',
                    'model' => 'gpt-4.1-mini/1.0',
                ];
            });
    }

    private function mockRevision(string $markdown, bool $changed, int $times = 1): void
    {
        $this->mock(WikiLinkRevisionAiClient::class)
            ->shouldReceive('reviseLinks')
            ->times($times)
            ->andReturn(['changed' => $changed, 'markdown' => $markdown]);
    }

    private function currentVersion(EnterpriseWikiPage $page): EnterpriseWikiPageVersion
    {
        return EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->where('is_current', true)
            ->firstOrFail();
    }

    private function createPageLinkRow(
        Customer $customer,
        EnterpriseWikiPage $from,
        EnterpriseWikiPage $to,
        EnterpriseWikiIngestRun $run,
    ): EnterpriseWikiPageLink {
        return EnterpriseWikiPageLink::query()->create([
            'customer_id' => $customer->id,
            'enterprise_wiki_ingest_run_id' => $run->id,
            'from_page_id' => $from->id,
            'to_page_id' => $to->id,
            'link_type' => EnterpriseWikiPageLink::LINK_TYPE_WIKILINK,
            'source' => EnterpriseWikiPageLink::SOURCE_DETERMINISTIC,
            'confidence' => EnterpriseWikiPageLink::CONFIDENCE_CERTAIN,
        ]);
    }

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

    private function createPage(
        Customer $customer,
        string $slug,
        string $title,
        string $pageType = EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
    ): EnterpriseWikiPage {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => $slug,
            'title' => $title,
            'page_type' => $pageType,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }

    private function createVersionedPage(
        Customer $customer,
        string $slug,
        string $title,
        string $pageType,
        string $content,
    ): EnterpriseWikiPage {
        $page = $this->createPage($customer, $slug, $title, $pageType);

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => $content,
            'generated_by_model' => 'gpt-5',
        ]);

        return $page;
    }

    private function createAppliedRun(Customer $customer, EnterpriseWikiDocument $document, array $pages): EnterpriseWikiIngestRun
    {
        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
        ]);

        foreach ($pages as $page) {
            EnterpriseWikiIngestRunPage::query()->create([
                'enterprise_wiki_ingest_run_id' => $run->id,
                'enterprise_wiki_page_id' => $page->id,
                'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            ]);
        }

        return $run;
    }
}
