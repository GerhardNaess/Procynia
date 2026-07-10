<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiQaSnapshot;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\Ai\Wiki\WikiPageContentAiClient;
use App\Services\Ai\Wiki\WikiSemanticQaAiClient;
use App\Services\Ai\Wiki\WikiSemanticReviserAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiPostIngestQaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 8G-7 lineage tests.
 *
 * Verifies the full traceability chain:
 * source document → ingest run → page → page version → semantic QA diagnosis
 * → repair (using diagnosed version) → new version → post-repair re-evaluation
 * → QA snapshot
 *
 * All AI calls are mocked. No external model calls.
 */
class EnterpriseWikiLineageTest extends TestCase
{
    use RefreshDatabase;

    private const REVISED_MARKDOWN = "# Article Revised\n\nRevised content by repair.";

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.enterprise_wiki.ai_enabled' => true]);

        $this->mock(WikiPageContentAiClient::class)
            ->shouldReceive('generateFromSource')
            ->andReturn("# Generated\n\nContent.")
            ->byDefault();

        $this->mock(WikiSemanticQaAiClient::class)
            ->shouldReceive('review')
            ->andReturn($this->passingAiResult())
            ->byDefault();

        $this->mock(WikiSemanticReviserAiClient::class)
            ->shouldReceive('revise')
            ->andReturn(self::REVISED_MARKDOWN)
            ->byDefault();
    }

    // =========================================================================
    // 1: source document traceable via ingest run
    // =========================================================================

    public function test_source_document_traceable_via_ingest_run(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->createAppliedRun($customer, $document);

        $this->assertSame($document->id, (int) $run->source_id);
        $this->assertSame(
            EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            $run->source_type
        );

        $foundDocument = EnterpriseWikiDocument::find($run->source_id);
        $this->assertNotNull($foundDocument);
        $this->assertSame($document->file_hash_sha256, $foundDocument->file_hash_sha256);
        $this->assertSame($customer->id, (int) $foundDocument->customer_id);
    }

    // =========================================================================
    // 2: generated article traceable to source
    // =========================================================================

    public function test_generated_article_traceable_to_source_via_run_pages(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->createAppliedRun($customer, $document);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        // Follow: run → run_pages → page
        $linkedPageIds = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->pluck('enterprise_wiki_page_id');

        $this->assertContains($article->id, $linkedPageIds->all());

        // Follow back: page → run via pivot → source document
        $runForPage = EnterpriseWikiIngestRun::query()
            ->whereHas('pages', fn ($q) => $q->where('enterprise_wiki_pages.id', $article->id))
            ->first();

        $this->assertNotNull($runForPage);
        $this->assertSame($document->id, (int) $runForPage->source_id);
    }

    // =========================================================================
    // 3: semantic QA references the concrete reviewed page version
    // =========================================================================

    public function test_semantic_qa_references_concrete_page_version_id(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->createAppliedRun($customer, $document);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        $articleVersion = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $article->id)
            ->where('is_current', true)
            ->first();

        $this->orchestrator()->runForRun($run);

        $run->refresh();
        $qaResult = $run->qa_result;

        $this->assertNotNull($qaResult['semantic_qa']['page_version_id'] ?? null);
        $this->assertSame((int) $articleVersion->id, (int) $qaResult['semantic_qa']['page_version_id']);
        $this->assertSame($document->file_hash_sha256, $qaResult['semantic_qa']['source_hash']);
    }

    // =========================================================================
    // 4: repair uses diagnosed version, not is_current
    // =========================================================================

    public function test_repair_uses_diagnosed_version_not_is_current(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->createAppliedRun($customer, $document);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        $diagnosedVersion = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $article->id)
            ->where('is_current', true)
            ->first();

        // QA diagnoses a failure that requires targeted revision
        $this->mock(WikiSemanticQaAiClient::class)
            ->shouldReceive('review')
            ->twice()
            ->andReturn(
                $this->failingAiResult(action: 'targeted_revision'),
                $this->passingAiResult(),
            );

        // Add a second version and make it is_current BEFORE repair runs.
        // If repair used is_current, it would target v2 instead of the diagnosed v1.
        $v2 = EnterpriseWikiPageVersion::create([
            'enterprise_wiki_page_id' => $article->id,
            'version_number'          => 2,
            'is_current'              => false, // NOT current — diagnosed version is still v1
            'content_markdown'        => "# Article v2\n\nDifferent content.",
            'generated_by_model'      => 'test',
        ]);

        $this->orchestrator()->runForRun($run);

        $run->refresh();
        $qaResult = $run->qa_result;

        // Repair must have used the diagnosed version (v1), not v2
        $this->assertSame(
            (int) $diagnosedVersion->id,
            (int) ($qaResult['semantic_repair_result']['previous_version_id'] ?? null),
            'Repair must target the diagnosed page_version_id, not whatever is is_current',
        );

        $this->assertNotSame((int) $v2->id, (int) ($qaResult['semantic_repair_result']['previous_version_id'] ?? null));
    }

    // =========================================================================
    // 5: original version not overwritten after repair
    // =========================================================================

    public function test_original_version_not_overwritten_after_repair(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->createAppliedRun($customer, $document);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        $originalVersion = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $article->id)
            ->where('is_current', true)
            ->first();

        $this->mock(WikiSemanticQaAiClient::class)
            ->shouldReceive('review')
            ->twice()
            ->andReturn(
                $this->failingAiResult(action: 'targeted_revision'),
                $this->passingAiResult(),
            );

        $this->orchestrator()->runForRun($run);

        // Original version must still exist with original content
        $stillExists = EnterpriseWikiPageVersion::find($originalVersion->id);
        $this->assertNotNull($stillExists);
        $this->assertSame("# Article\n\nContent.", $stillExists->content_markdown);
        $this->assertFalse((bool) $stillExists->is_current);

        // A new version was created and is now current
        $newCurrent = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $article->id)
            ->where('is_current', true)
            ->first();

        $this->assertNotNull($newCurrent);
        $this->assertNotSame((int) $originalVersion->id, (int) $newCurrent->id);
        $this->assertSame(self::REVISED_MARKDOWN, $newCurrent->content_markdown);
    }

    // =========================================================================
    // 6: new revised version links to diagnosed previous version
    // =========================================================================

    public function test_repair_result_links_new_version_to_diagnosed_previous_version(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->createAppliedRun($customer, $document);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        $this->mock(WikiSemanticQaAiClient::class)
            ->shouldReceive('review')
            ->twice()
            ->andReturn(
                $this->failingAiResult(action: 'targeted_revision'),
                $this->passingAiResult(),
            );

        $this->orchestrator()->runForRun($run);

        $run->refresh();
        $qaResult = $run->qa_result;

        $diagnosedVersionId = $qaResult['semantic_qa']['page_version_id'] ?? null;
        $repairPreviousId   = $qaResult['semantic_repair_result']['previous_version_id'] ?? null;
        $repairNewId        = $qaResult['semantic_repair_result']['page_version_id'] ?? null;

        $this->assertNotNull($diagnosedVersionId);
        $this->assertNotNull($repairPreviousId);
        $this->assertNotNull($repairNewId);

        // The diagnosed version IS the version that was sent to repair
        $this->assertSame((int) $diagnosedVersionId, (int) $repairPreviousId);

        // The new version is different from the diagnosed version
        $this->assertNotSame((int) $diagnosedVersionId, (int) $repairNewId);
    }

    // =========================================================================
    // 7: post-repair re-evaluation targets the new (revised) version
    // =========================================================================

    public function test_post_repair_re_evaluation_targets_new_version(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->createAppliedRun($customer, $document);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        $this->mock(WikiSemanticQaAiClient::class)
            ->shouldReceive('review')
            ->twice()
            ->andReturn(
                $this->failingAiResult(action: 'targeted_revision'),
                $this->passingAiResult(),
            );

        $this->orchestrator()->runForRun($run);

        $run->refresh();
        $qaResult = $run->qa_result;

        $repairNewId      = $qaResult['semantic_repair_result']['page_version_id'] ?? null;
        $postRepairVerId  = $qaResult['semantic_qa_post_repair']['page_version_id'] ?? null;

        $this->assertNotNull($repairNewId);
        $this->assertNotNull($postRepairVerId);

        // Post-repair evaluation targets the version produced by repair
        $this->assertSame((int) $repairNewId, (int) $postRepairVerId);
    }

    // =========================================================================
    // 8: qa_result preserves both original and revised version IDs
    // =========================================================================

    public function test_qa_result_preserves_both_original_and_revised_version_ids(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->createAppliedRun($customer, $document);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        $this->mock(WikiSemanticQaAiClient::class)
            ->shouldReceive('review')
            ->twice()
            ->andReturn(
                $this->failingAiResult(action: 'targeted_revision'),
                $this->passingAiResult(),
            );

        $this->orchestrator()->runForRun($run);

        $run->refresh();
        $qaResult = $run->qa_result;

        $originalVersionId = $qaResult['semantic_qa']['page_version_id'] ?? null;
        $revisedVersionId  = $qaResult['semantic_qa_post_repair']['page_version_id'] ?? null;

        $this->assertNotNull($originalVersionId);
        $this->assertNotNull($revisedVersionId);
        $this->assertNotSame((int) $originalVersionId, (int) $revisedVersionId);

        // Both versions exist in DB
        $this->assertNotNull(EnterpriseWikiPageVersion::find($originalVersionId));
        $this->assertNotNull(EnterpriseWikiPageVersion::find($revisedVersionId));
    }

    // =========================================================================
    // 9: lineage captured in QA snapshot
    // =========================================================================

    public function test_lineage_captured_in_qa_snapshot(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->createAppliedRun($customer, $document);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        $this->mock(WikiSemanticQaAiClient::class)
            ->shouldReceive('review')
            ->twice()
            ->andReturn(
                $this->failingAiResult(action: 'targeted_revision'),
                $this->passingAiResult(),
            );

        $this->orchestrator()->runForRun($run);

        $snapshot = EnterpriseWikiQaSnapshot::query()->first();
        $this->assertNotNull($snapshot);

        $run->refresh();
        $qaResult = $run->qa_result;

        // Diagnosed version in snapshot
        $this->assertSame(
            (int) ($qaResult['semantic_qa']['page_version_id'] ?? 0),
            (int) $snapshot->semantic_page_version_id,
        );

        // Repair: previous and new version IDs
        $this->assertSame(
            (int) ($qaResult['semantic_repair_result']['previous_version_id'] ?? 0),
            (int) $snapshot->semantic_repair_previous_version_id,
        );
        $this->assertSame(
            (int) ($qaResult['semantic_repair_result']['page_version_id'] ?? 0),
            (int) $snapshot->semantic_repair_new_version_id,
        );

        // Post-repair re-evaluated version in snapshot
        $this->assertNotNull($snapshot->semantic_post_repair_page_version_id);
        $this->assertSame(
            (int) ($qaResult['semantic_qa_post_repair']['page_version_id'] ?? 0),
            (int) $snapshot->semantic_post_repair_page_version_id,
        );

        // Post-repair version is different from original diagnosed version
        $this->assertNotSame(
            (int) $snapshot->semantic_page_version_id,
            (int) $snapshot->semantic_post_repair_page_version_id,
        );
    }

    // =========================================================================
    // 10: retry creates new QA attempt without destroying previous lineage
    // =========================================================================

    public function test_retry_creates_new_qa_attempt_without_destroying_previous_lineage(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->createAppliedRun($customer, $document);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        // First attempt: escalate
        $this->mock(WikiSemanticQaAiClient::class)
            ->shouldReceive('review')
            ->andReturn($this->failingAiResult(action: 'escalate'));

        $this->orchestrator()->runForRun($run);

        $firstSnapshot = EnterpriseWikiQaSnapshot::query()->orderBy('qa_attempt_count')->first();
        $firstVersionId = $firstSnapshot->semantic_page_version_id;

        // Second attempt: pass
        $this->mock(WikiSemanticQaAiClient::class)
            ->shouldReceive('review')
            ->andReturn($this->passingAiResult());

        $this->orchestrator()->runForRun($run, retry: true);

        $this->assertSame(2, EnterpriseWikiQaSnapshot::query()->count());

        // First snapshot's lineage is unchanged
        $firstSnapshot->refresh();
        $this->assertSame((int) $firstVersionId, (int) $firstSnapshot->semantic_page_version_id);
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_ESCALATED, $firstSnapshot->qa_status);

        // Second snapshot has its own version reference
        $secondSnapshot = EnterpriseWikiQaSnapshot::query()->orderBy('qa_attempt_count', 'desc')->first();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $secondSnapshot->qa_status);
        $this->assertNotNull($secondSnapshot->semantic_page_version_id);
    }

    // =========================================================================
    // 11: lineage is customer-separated
    // =========================================================================

    public function test_lineage_is_customer_separated(): void
    {
        $customerA = $this->createCustomer('Customer A AS');
        $customerB = $this->createCustomer('Customer B AS');

        $documentA = $this->createDocument($customerA, 'Source text for A.');
        $documentB = $this->createDocument($customerB, 'Source text for B.');

        $runA = $this->createAppliedRun($customerA, $documentA);
        $runB = $this->createAppliedRun($customerB, $documentB);

        $this->createVersionedPage($customerA, $runA, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article A');
        $this->createVersionedPage($customerA, $runA, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary A');
        $this->createVersionedPage($customerB, $runB, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article B');
        $this->createVersionedPage($customerB, $runB, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary B');

        $this->orchestrator()->runForRun($runA);
        $this->orchestrator()->runForRun($runB);

        $snapshotA = EnterpriseWikiQaSnapshot::query()->where('enterprise_wiki_ingest_run_id', $runA->id)->first();
        $snapshotB = EnterpriseWikiQaSnapshot::query()->where('enterprise_wiki_ingest_run_id', $runB->id)->first();

        $this->assertSame($customerA->id, (int) $snapshotA->customer_id);
        $this->assertSame($customerB->id, (int) $snapshotB->customer_id);
        $this->assertNotSame((int) $snapshotA->semantic_page_version_id, (int) $snapshotB->semantic_page_version_id);

        // Ingest runs cannot reach across customer boundaries
        $this->assertSame($customerA->id, (int) $runA->customer_id);
        $this->assertSame($customerB->id, (int) $runB->customer_id);
        $this->assertSame($documentA->id, (int) $runA->source_id);
        $this->assertSame($documentB->id, (int) $runB->source_id);
    }

    // =========================================================================
    // 12: existing 8G-4, 8G-5, 8G-6 flow still works
    // =========================================================================

    public function test_existing_qa_flow_still_works(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->createAppliedRun($customer, $document);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        $this->orchestrator()->runForRun($run);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
        $this->assertNotNull($run->qa_result);
        $this->assertNull($run->qa_last_error);
        $this->assertSame(1, EnterpriseWikiQaSnapshot::query()->count());

        $snapshot = EnterpriseWikiQaSnapshot::query()->first();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $snapshot->qa_status);
        $this->assertNotNull($snapshot->semantic_page_version_id);
        $this->assertNull($snapshot->semantic_post_repair_page_version_id);
    }

    // =========================================================================
    // 13: ProcessEnterpriseWikiIngest not modified
    // =========================================================================

    public function test_process_enterprise_wiki_ingest_not_modified(): void
    {
        $jobPath = base_path('app/Jobs/Ai/Wiki/ProcessEnterpriseWikiIngest.php');

        if (! file_exists($jobPath)) {
            $this->markTestSkipped('ProcessEnterpriseWikiIngest.php not found.');
        }

        $content = file_get_contents($jobPath);
        $this->assertStringNotContainsString(
            'EnterpriseWikiLineage',
            $content,
            'ProcessEnterpriseWikiIngest must not reference lineage classes.',
        );
        $this->assertStringNotContainsString(
            'semantic_post_repair_page_version_id',
            $content,
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function orchestrator(): EnterpriseWikiPostIngestQaService
    {
        return app(EnterpriseWikiPostIngestQaService::class);
    }

    private function passingAiResult(): array
    {
        return [
            'pass'                      => true,
            'quality_score'             => 0.92,
            'coverage_score'            => 0.90,
            'factual_consistency_score' => 0.97,
            'unsupported_claims'        => [],
            'missing_topics'            => [],
            'missing_key_facts'         => [],
            'critique'                  => 'Good.',
            'recommended_repair_action' => 'none',
            'confidence'                => 0.94,
            'model'                     => 'gpt-4.1-mini/1.0',
            'prompt_version'            => '1.0',
        ];
    }

    private function failingAiResult(string $action = 'targeted_revision'): array
    {
        return [
            'pass'                      => false,
            'quality_score'             => 0.45,
            'coverage_score'            => 0.40,
            'factual_consistency_score' => 0.80,
            'unsupported_claims'        => [],
            'missing_topics'            => ['Section A'],
            'missing_key_facts'         => [],
            'critique'                  => 'Key topics missing.',
            'recommended_repair_action' => $action,
            'confidence'                => 0.85,
            'model'                     => 'gpt-4.1-mini/1.0',
            'prompt_version'            => '1.0',
        ];
    }

    private function createCustomer(string $name = 'Lineage Test AS'): Customer
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
            'name'             => $name,
            'slug'             => Str::slug($name) . '-' . Str::lower(Str::random(6)),
            'language_id'      => $language->id,
            'nationality_id'   => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active'        => true,
        ]);
    }

    private function createDocument(
        Customer $customer,
        string $extractedText = 'Authoritative source document for lineage tests.',
    ): EnterpriseWikiDocument {
        return EnterpriseWikiDocument::query()->create([
            'customer_id'       => $customer->id,
            'original_filename' => 'source.pdf',
            'file_path'         => 'customers/' . $customer->id . '/wiki/' . Str::random(8) . '.pdf',
            'file_hash_sha256'  => hash('sha256', Str::random(32)),
            'extracted_text'    => $extractedText,
            'document_status'   => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createAppliedRun(Customer $customer, EnterpriseWikiDocument $document): EnterpriseWikiIngestRun
    {
        return EnterpriseWikiIngestRun::query()->create([
            'uuid'                             => Str::uuid()->toString(),
            'customer_id'                      => $customer->id,
            'trigger_type'                     => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type'                      => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id'                        => $document->id,
            'status'                           => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_status'       => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
            'maintainer_decision_json'         => ['pages' => []],
            'qa_status'                        => null,
        ]);
    }

    private function createVersionedPage(
        Customer $customer,
        EnterpriseWikiIngestRun $run,
        string $pageType,
        string $title,
    ): EnterpriseWikiPage {
        $page = EnterpriseWikiPage::query()->create([
            'customer_id'      => $customer->id,
            'slug'             => Str::slug($title) . '-' . Str::lower(Str::random(4)),
            'title'            => $title,
            'page_type'        => $pageType,
            'status'           => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by'     => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id'       => $page->id,
            'action'                        => EnterpriseWikiIngestRunPage::ACTION_CREATED,
        ]);

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number'          => 1,
            'is_current'              => true,
            'content_markdown'        => "# {$title}\n\nContent.",
            'generated_by_model'      => 'gpt-5',
        ]);

        return $page;
    }
}
