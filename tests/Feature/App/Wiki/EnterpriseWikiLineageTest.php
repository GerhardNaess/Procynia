<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\Ai\Wiki\WikiPageContentAiClient;
use App\Services\Ai\Wiki\WikiSemanticQaAiClient;
use App\Services\Ai\Wiki\WikiSemanticReviserAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiSemanticQaService;
use App\Services\EnterpriseWiki\EnterpriseWikiSemanticRepairService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 8G-7 lineage tests.
 *
 * `EnterpriseWikiPostIngestQaService` (the post-ingest QA orchestrator) was redesigned into a
 * minimal, fully deterministic end check: it no longer calls `EnterpriseWikiSemanticQaService`,
 * `EnterpriseWikiSemanticRepairService`, or any AI client, and the old "diagnose a version with
 * semantic QA → repair that exact version → re-evaluate the new version" chain no longer exists
 * in the orchestrator. Coverage of the orchestrator's own (now purely deterministic) logic lives
 * in `EnterpriseWikiPostIngestQaServiceTest`.
 *
 * What remains genuinely valid here is version-lineage behavior that lives directly in
 * `EnterpriseWikiSemanticQaService`/`EnterpriseWikiSemanticRepairService` themselves — e.g. that
 * repair must act on the page_version_id it was diagnosed against, not whatever happens to be
 * `is_current` at repair time. These tests call those two services directly (no orchestrator,
 * no `runForRun()`), chaining review() → repair() → review() by hand to exercise the same
 * traceability chain that used to live inside the orchestrator.
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

        $this->mock(WikiPageContentAiClient::class)
            ->shouldReceive('generatePageFromSource')
            ->andReturn([
                'markdown' => "# Generated\n\nContent.",
                'blocks' => [[
                    'markdown' => "# Generated\n\nContent.",
                    'content_origin' => 'source_based',
                    'source_element_keys' => ['document-1-full-text'],
                    'source_element_types' => ['manual'],
                    'best_practice_reason' => null,
                    'link_intents' => [],
                ]],
            ])
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
    // 3: EnterpriseWikiSemanticQaService::review() references the concrete reviewed page version
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

        $result = $this->semanticQaService()->review($run);

        $this->assertSame((int) $articleVersion->id, (int) $result['page_version_id']);
        $this->assertSame($document->file_hash_sha256, $result['source_hash']);
    }

    // =========================================================================
    // 4: EnterpriseWikiSemanticRepairService::repair() uses the diagnosed version, not is_current
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

        // QA diagnoses a failure that requires targeted revision.
        $this->mock(WikiSemanticQaAiClient::class)
            ->shouldReceive('review')
            ->once()
            ->andReturn($this->failingAiResult(action: 'targeted_revision'));

        $diagnosis = $this->semanticQaService()->review($run);
        $this->assertSame((int) $diagnosedVersion->id, (int) $diagnosis['page_version_id']);

        // Add a second version and make it is_current AFTER diagnosis but BEFORE repair runs.
        // If repair used is_current, it would target v2 instead of the diagnosed v1.
        $v2 = EnterpriseWikiPageVersion::create([
            'enterprise_wiki_page_id' => $article->id,
            'version_number' => 2,
            'is_current' => true,
            'content_markdown' => "# Article v2\n\nDifferent content.",
            'generated_by_model' => 'test',
        ]);

        $repairResult = $this->semanticRepairService()->repair($run, $diagnosis);

        // Repair must have used the diagnosed version (v1), not v2.
        $this->assertSame(
            (int) $diagnosedVersion->id,
            (int) $repairResult['previous_version_id'],
            'Repair must target the diagnosed page_version_id, not whatever is is_current',
        );

        $this->assertNotSame((int) $v2->id, (int) $repairResult['previous_version_id']);
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
            ->once()
            ->andReturn($this->failingAiResult(action: 'targeted_revision'));

        $diagnosis = $this->semanticQaService()->review($run);
        $this->semanticRepairService()->repair($run, $diagnosis);

        // Original version must still exist with original content.
        $stillExists = EnterpriseWikiPageVersion::find($originalVersion->id);
        $this->assertNotNull($stillExists);
        $this->assertSame("# Article\n\nContent.", $stillExists->content_markdown);
        $this->assertFalse((bool) $stillExists->is_current);

        // A new version was created and is now current.
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
            ->once()
            ->andReturn($this->failingAiResult(action: 'targeted_revision'));

        $diagnosis = $this->semanticQaService()->review($run);
        $repairResult = $this->semanticRepairService()->repair($run, $diagnosis);

        $diagnosedVersionId = $diagnosis['page_version_id'] ?? null;
        $repairPreviousId = $repairResult['previous_version_id'] ?? null;
        $repairNewId = $repairResult['page_version_id'] ?? null;

        $this->assertNotNull($diagnosedVersionId);
        $this->assertNotNull($repairPreviousId);
        $this->assertNotNull($repairNewId);

        // The diagnosed version IS the version that was sent to repair.
        $this->assertSame((int) $diagnosedVersionId, (int) $repairPreviousId);

        // The new version is different from the diagnosed version.
        $this->assertNotSame((int) $diagnosedVersionId, (int) $repairNewId);
    }

    // =========================================================================
    // 7: re-running semantic QA after repair targets the new (revised) version
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

        $diagnosis = $this->semanticQaService()->review($run);
        $repairResult = $this->semanticRepairService()->repair($run, $diagnosis);

        // There is no orchestrator-level "post-repair re-evaluation" step any more — but calling
        // EnterpriseWikiSemanticQaService::review() again naturally targets whichever version is
        // now current, which repair() just made the new revised version.
        $postRepairResult = $this->semanticQaService()->review($run);

        $repairNewId = $repairResult['page_version_id'] ?? null;
        $postRepairVerId = $postRepairResult['page_version_id'] ?? null;

        $this->assertNotNull($repairNewId);
        $this->assertNotNull($postRepairVerId);

        // Re-evaluation targets the version produced by repair.
        $this->assertSame((int) $repairNewId, (int) $postRepairVerId);
    }

    // =========================================================================
    // 8: ProcessEnterpriseWikiIngest not modified
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

    private function semanticQaService(): EnterpriseWikiSemanticQaService
    {
        return app(EnterpriseWikiSemanticQaService::class);
    }

    private function semanticRepairService(): EnterpriseWikiSemanticRepairService
    {
        return app(EnterpriseWikiSemanticRepairService::class);
    }

    private function passingAiResult(): array
    {
        return [
            'pass' => true,
            'quality_score' => 0.92,
            'coverage_score' => 0.90,
            'factual_consistency_score' => 0.97,
            'unsupported_claims' => [],
            'missing_topics' => [],
            'missing_key_facts' => [],
            'critique' => 'Good.',
            'recommended_repair_action' => 'none',
            'confidence' => 0.94,
            'model' => 'gpt-4.1-mini/1.0',
            'prompt_version' => '1.0',
        ];
    }

    private function failingAiResult(string $action = 'targeted_revision'): array
    {
        return [
            'pass' => false,
            'quality_score' => 0.45,
            'coverage_score' => 0.40,
            'factual_consistency_score' => 0.80,
            'unsupported_claims' => [],
            'missing_topics' => ['Section A'],
            'missing_key_facts' => [],
            'critique' => 'Key topics missing.',
            'recommended_repair_action' => $action,
            'confidence' => 0.85,
            'model' => 'gpt-4.1-mini/1.0',
            'prompt_version' => '1.0',
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
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    private function createDocument(
        Customer $customer,
        string $extractedText = 'Authoritative source document for lineage tests.',
    ): EnterpriseWikiDocument {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'source.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => $extractedText,
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createAppliedRun(Customer $customer, EnterpriseWikiDocument $document): EnterpriseWikiIngestRun
    {
        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
            'maintainer_decision_json' => ['pages' => []],
            'qa_status' => null,
        ]);
    }

    private function createVersionedPage(
        Customer $customer,
        EnterpriseWikiIngestRun $run,
        string $pageType,
        string $title,
    ): EnterpriseWikiPage {
        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(4)),
            'title' => $title,
            'page_type' => $pageType,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
        ]);

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => "# {$title}\n\nContent.",
            'generated_by_model' => 'gpt-5',
        ]);

        return $page;
    }
}
