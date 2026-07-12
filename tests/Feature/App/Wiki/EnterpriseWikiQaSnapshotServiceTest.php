<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiQaSnapshot;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\EnterpriseWiki\EnterpriseWikiPostIngestQaService;
use App\Services\EnterpriseWiki\EnterpriseWikiQaSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tests for the 8G-6 QA snapshot service.
 *
 * Verifies that immutable snapshots are created at terminal QA status transitions,
 * that idempotence is enforced, and that snapshot fields correctly reflect the
 * QA result.
 *
 * Post-ingest QA (EnterpriseWikiPostIngestQaService) is now a minimal, fully deterministic
 * end check: it never calls OpenAI and never runs semantic QA or semantic repair. As a result
 * the semantic-QA and semantic-repair snapshot fields (semantic_qa_ran, semantic_pass,
 * semantic_quality_score/coverage_score/factual_score, semantic_missing_*_count,
 * semantic_repair_attempted, semantic_repair_success, semantic_post_repair_*, etc.) are always
 * false/null now — there is no remaining code path that populates them with real values. Tests
 * that used to assert specific non-trivial semantic values have been removed entirely; where a
 * test's actual point survives (e.g. "a snapshot is created for a terminal verdict", "repeat
 * attempts don't duplicate a snapshot"), the semantic-field assertions have been flipped to
 * assert the permanent false/null state instead of deleting the whole test.
 */
class EnterpriseWikiQaSnapshotServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    // =========================================================================
    // 1–3: Terminal statuses create snapshots
    // =========================================================================

    public function test_passed_creates_snapshot(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $this->markStepsComplete($run);

        $this->orchestrator()->runForRun($run);

        $this->assertSame(1, EnterpriseWikiQaSnapshot::query()->count());
        $snapshot = EnterpriseWikiQaSnapshot::query()->first();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $snapshot->qa_status);
        $this->assertSame($run->id, (int) $snapshot->enterprise_wiki_ingest_run_id);
        $this->assertSame($customer->id, (int) $snapshot->customer_id);
        $this->assertNotNull($snapshot->snapshotted_at);
    }

    public function test_failed_creates_snapshot(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        // Only an article is produced — the missing summary is a critical defect
        // (missing_article_or_summary), which is a concrete, understood content defect.
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->markStepsComplete($run);

        $this->orchestrator()->runForRun($run);

        $this->assertSame(1, EnterpriseWikiQaSnapshot::query()->count());
        $snapshot = EnterpriseWikiQaSnapshot::query()->first();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_FAILED, $snapshot->qa_status);
    }

    public function test_escalated_creates_snapshot(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        // Continuation steps (generation status / claim extraction / claim verification) are
        // deliberately left incomplete — evaluate() cannot safely judge the run yet and must
        // escalate rather than guess.

        $this->orchestrator()->runForRun($run);

        $this->assertSame(1, EnterpriseWikiQaSnapshot::query()->count());
        $snapshot = EnterpriseWikiQaSnapshot::query()->first();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_ESCALATED, $snapshot->qa_status);
    }

    // =========================================================================
    // 4: a run with no pages at all resolves to a single terminal snapshot
    // =========================================================================

    public function test_repair_required_does_not_create_extra_snapshot(): void
    {
        // Repair no longer exists in the orchestrator at all. A run with no applied pages
        // whatsoever goes straight to a terminal escalated verdict (evaluate() cannot judge a
        // run with zero pages) — it must never be left sitting in the old repair_required
        // status, and exactly one snapshot must be created for the attempt.
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        // No pages attached to the run at all.

        $this->orchestrator()->runForRun($run);

        $this->assertSame(1, EnterpriseWikiQaSnapshot::query()->count());
        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_ESCALATED, $run->qa_status);
        $this->assertNotSame(EnterpriseWikiIngestRun::QA_STATUS_REPAIR_REQUIRED, $run->qa_status);
    }

    // =========================================================================
    // 7: score and lint/coverage fields correct
    // =========================================================================

    public function test_score_and_structural_fields_stored_correctly(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $this->markStepsComplete($run);

        $this->orchestrator()->runForRun($run);

        $snapshot = EnterpriseWikiQaSnapshot::query()->first();
        $this->assertNotNull($snapshot);
        $this->assertTrue((bool) $snapshot->technical_qa_passed);
        $this->assertTrue((bool) $snapshot->structural_qa_passed);
        $this->assertFalse((bool) $snapshot->open_lint_errors);
        $this->assertSame(0, (int) $snapshot->lint_error_count);

        // Semantic QA never runs post-ingest anymore — these fields are permanently empty.
        $this->assertFalse((bool) $snapshot->semantic_qa_ran);
        $this->assertNull($snapshot->semantic_pass);
        $this->assertNull($snapshot->semantic_quality_score);
        $this->assertNull($snapshot->semantic_coverage_score);
        $this->assertNull($snapshot->semantic_factual_score);
    }

    // =========================================================================
    // 9: new QA attempt creates a new snapshot
    // =========================================================================

    public function test_new_qa_attempt_creates_new_snapshot(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        // First attempt: continuation steps are incomplete -> escalated.
        $this->orchestrator()->runForRun($run);
        $this->assertSame(1, EnterpriseWikiQaSnapshot::query()->count());

        // Second attempt via retry: steps are now complete -> passes.
        $run->refresh();
        $this->markStepsComplete($run);
        $this->orchestrator()->runForRun($run, retry: true);

        $this->assertSame(2, EnterpriseWikiQaSnapshot::query()->count());

        $snapshots = EnterpriseWikiQaSnapshot::query()->orderBy('qa_attempt_count')->get();
        $this->assertSame(1, (int) $snapshots[0]->qa_attempt_count);
        $this->assertSame(2, (int) $snapshots[1]->qa_attempt_count);
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_ESCALATED, $snapshots[0]->qa_status);
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $snapshots[1]->qa_status);
    }

    // =========================================================================
    // 10: calling capture twice for the same attempt does not create a duplicate
    // =========================================================================

    public function test_duplicate_capture_for_same_attempt_is_idempotent(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $this->markStepsComplete($run);

        $this->orchestrator()->runForRun($run);
        $this->assertSame(1, EnterpriseWikiQaSnapshot::query()->count());

        // Simulate a duplicate capture call for the same attempt.
        $run->refresh();
        $snapshotService = app(EnterpriseWikiQaSnapshotService::class);
        $snapshotService->capture($run, []);

        $this->assertSame(1, EnterpriseWikiQaSnapshot::query()->count());
    }

    // =========================================================================
    // 11: snapshots are customer-separated
    // =========================================================================

    public function test_snapshots_are_customer_separated(): void
    {
        $customerA = $this->createCustomer('Customer A AS');
        $customerB = $this->createCustomer('Customer B AS');

        $runA = $this->createAppliedRun($customerA);
        $runB = $this->createAppliedRun($customerB);

        $this->createVersionedPage($customerA, $runA, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article A');
        $this->createVersionedPage($customerA, $runA, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary A');
        $this->createVersionedPage($customerB, $runB, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article B');
        $this->createVersionedPage($customerB, $runB, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary B');

        $this->orchestrator()->runForRun($runA);
        $this->orchestrator()->runForRun($runB);

        $this->assertSame(2, EnterpriseWikiQaSnapshot::query()->count());

        $snapshotA = EnterpriseWikiQaSnapshot::query()->where('enterprise_wiki_ingest_run_id', $runA->id)->first();
        $snapshotB = EnterpriseWikiQaSnapshot::query()->where('enterprise_wiki_ingest_run_id', $runB->id)->first();

        $this->assertSame($customerA->id, (int) $snapshotA->customer_id);
        $this->assertSame($customerB->id, (int) $snapshotB->customer_id);
        $this->assertNotSame($snapshotA->customer_id, $snapshotB->customer_id);
    }

    // =========================================================================
    // 12: existing QA status flow still works
    // =========================================================================

    public function test_existing_qa_status_flow_still_works(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $this->markStepsComplete($run);

        $result = $this->orchestrator()->runForRun($run);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
        $this->assertNotNull($run->qa_result);
        $this->assertNotNull($run->qa_completed_at);
        $this->assertNotNull($result);
        $this->assertArrayHasKey('semantic_qa', $result);
        $this->assertArrayHasKey('checks', $result);
        $this->assertArrayHasKey('semantic_repair_attempted', $result);
    }

    // =========================================================================
    // 13: ProcessEnterpriseWikiIngest is not modified (contains no snapshot reference)
    // =========================================================================

    public function test_process_enterprise_wiki_ingest_not_modified(): void
    {
        $jobPath = base_path('app/Jobs/Ai/Wiki/ProcessEnterpriseWikiIngest.php');

        if (! file_exists($jobPath)) {
            $this->markTestSkipped('ProcessEnterpriseWikiIngest.php not found — test cannot verify.');
        }

        $content = file_get_contents($jobPath);
        $this->assertStringNotContainsString(
            'EnterpriseWikiQaSnapshotService',
            $content,
            'ProcessEnterpriseWikiIngest must not reference the snapshot service.',
        );
        $this->assertStringNotContainsString(
            'QaSnapshot',
            $content,
            'ProcessEnterpriseWikiIngest must not reference snapshot classes.',
        );
    }

    // =========================================================================
    // Snapshot failure handling (8G-6 rettelse)
    // =========================================================================

    public function test_snapshot_failure_on_passing_qa_gives_escalated_not_passed(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $this->markStepsComplete($run);

        $this->mock(EnterpriseWikiQaSnapshotService::class)
            ->shouldReceive('capture')
            ->andThrow(new \RuntimeException('DB connection lost during snapshot insert'));

        $this->orchestrator()->runForRun($run);

        $run->refresh();
        // A snapshot write failure is a technical problem, not a content verdict — it is always
        // escalated, never recorded as failed (failed is reserved for a genuine content defect).
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_ESCALATED, $run->qa_status);
        $this->assertSame(0, EnterpriseWikiQaSnapshot::query()->count());
    }

    public function test_snapshot_failure_sets_qa_last_error_describing_snapshot_failure(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $this->markStepsComplete($run);

        $this->mock(EnterpriseWikiQaSnapshotService::class)
            ->shouldReceive('capture')
            ->andThrow(new \RuntimeException('Unique constraint violation'));

        $this->orchestrator()->runForRun($run);

        $run->refresh();
        $this->assertNotNull($run->qa_last_error);
        $this->assertStringContainsString('[SNAPSHOT]', $run->qa_last_error);
        $this->assertStringContainsString('Unique constraint violation', $run->qa_last_error);
    }

    public function test_snapshot_failure_preserves_qa_result(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $this->markStepsComplete($run);

        $this->mock(EnterpriseWikiQaSnapshotService::class)
            ->shouldReceive('capture')
            ->andThrow(new \RuntimeException('Disk full'));

        $this->orchestrator()->runForRun($run);

        $run->refresh();
        $this->assertNotNull($run->qa_result);
        $this->assertArrayHasKey('checks', $run->qa_result);
        // Semantic QA no longer runs — the key is preserved in the result shape but always null.
        $this->assertArrayHasKey('semantic_qa', $run->qa_result);
        $this->assertNull($run->qa_result['semantic_qa']);
    }

    public function test_snapshot_success_preserves_normal_terminal_status(): void
    {
        // Regression: when snapshot succeeds, the run must keep its QA-determined status.
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $this->markStepsComplete($run);

        $this->orchestrator()->runForRun($run);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
        $this->assertNull($run->qa_last_error);
        $this->assertSame(1, EnterpriseWikiQaSnapshot::query()->count());
    }

    public function test_retry_after_snapshot_failure_can_reach_passed(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $this->markStepsComplete($run);

        // First attempt: snapshot fails -> escalated (a technical failure, not a content verdict).
        $snapshotMock = $this->mock(EnterpriseWikiQaSnapshotService::class);
        $snapshotMock->shouldReceive('capture')
            ->once()
            ->andThrow(new \RuntimeException('Transient DB error'));

        $this->orchestrator()->runForRun($run);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_ESCALATED, $run->qa_status);
        $this->assertSame(0, EnterpriseWikiQaSnapshot::query()->count());

        // Second attempt (retry): snapshot succeeds -> run reaches passed.
        $snapshotMock->shouldReceive('capture')
            ->once()
            ->andReturn(null);

        $this->orchestrator()->runForRun($run, retry: true);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
        $this->assertNull($run->qa_last_error);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function orchestrator(): EnterpriseWikiPostIngestQaService
    {
        return app(EnterpriseWikiPostIngestQaService::class);
    }

    /**
     * Marks every continuation step (page generation, claim extraction, claim verification)
     * complete for every page/claim belonging to the run, and clears any lease markers — the
     * state evaluate() requires before it can reach a passed/failed verdict instead of
     * escalating due to "cannot be safely determined yet".
     */
    private function markStepsComplete(EnterpriseWikiIngestRun $run): void
    {
        EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->update([
                'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
                'claims_extracted_at' => now(),
                'claims_claimed_at' => null,
                'claims_claim_token' => null,
            ]);

        $pageIds = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->pluck('enterprise_wiki_page_id');

        EnterpriseWikiClaim::query()
            ->whereIn('enterprise_wiki_page_id', $pageIds)
            ->whereNull('verified_at')
            ->update(['verified_at' => now(), 'verification_claimed_at' => null, 'verification_claim_token' => null]);
    }

    private function createCustomer(string $name = 'Snapshot Test AS'): Customer
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
        string $extractedText = 'Authoritative source document text for snapshot tests.',
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

    private function createAppliedRun(Customer $customer, ?string $qaStatus = null): EnterpriseWikiIngestRun
    {
        $document = $this->createDocument($customer);

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
            'qa_status' => $qaStatus,
        ]);
    }

    private function createPage(Customer $customer, string $pageType, string $title): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(4)),
            'title' => $title,
            'page_type' => $pageType,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }

    private function addPageToRun(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $page): void
    {
        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
        ]);
    }

    private function createVersionedPage(
        Customer $customer,
        EnterpriseWikiIngestRun $run,
        string $pageType,
        string $title,
        string $content = '',
    ): EnterpriseWikiPage {
        $page = $this->createPage($customer, $pageType, $title);
        $this->addPageToRun($run, $page);

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => $content !== '' ? $content : "# {$title}\n\nContent.",
            'generated_by_model' => 'gpt-5',
        ]);

        return $page;
    }
}
