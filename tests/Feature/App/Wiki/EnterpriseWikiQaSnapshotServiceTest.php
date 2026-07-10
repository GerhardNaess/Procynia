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
use App\Services\EnterpriseWiki\EnterpriseWikiQaSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tests for the 8G-6 QA snapshot service.
 *
 * Verifies that immutable snapshots are created at terminal QA status transitions,
 * that idempotence is enforced, and that snapshot fields correctly reflect the
 * QA result. No external AI calls — all AI clients are mocked.
 */
class EnterpriseWikiQaSnapshotServiceTest extends TestCase
{
    use RefreshDatabase;

    private const REVISED_MARKDOWN = "# Article\n\nRevised content.";

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
    // 1–3: Terminal statuses create snapshots
    // =========================================================================

    public function test_passed_creates_snapshot(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

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
        config(['services.enterprise_wiki.ai_enabled' => false]);

        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        $this->mock(WikiSemanticQaAiClient::class)->shouldReceive('review')->never();

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

        $this->mock(WikiSemanticQaAiClient::class)
            ->shouldReceive('review')
            ->andReturn($this->failingAiResult(action: 'escalate'));

        $this->orchestrator()->runForRun($run);

        $this->assertSame(1, EnterpriseWikiQaSnapshot::query()->count());
        $snapshot = EnterpriseWikiQaSnapshot::query()->first();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_ESCALATED, $snapshot->qa_status);
    }

    // =========================================================================
    // 4: repair_required is transient — only one snapshot per QA run
    // =========================================================================

    public function test_repair_required_does_not_create_extra_snapshot(): void
    {
        // Level 1/2 repair temporarily sets repair_required, but the run resolves
        // to a terminal status. Exactly one snapshot must be created.
        $customer = $this->createCustomer();
        // Create run with NO pages — will trigger level 1/2 repair_required → then generates pages
        $run = $this->createAppliedRun($customer);
        // No pages: article/summary will be generated via level 1/2 repair

        $this->orchestrator()->runForRun($run);

        // After level 1/2 repair, run should have pages generated and reach a terminal status.
        $this->assertSame(1, EnterpriseWikiQaSnapshot::query()->count());
        $run->refresh();
        $this->assertNotSame(EnterpriseWikiIngestRun::QA_STATUS_REPAIR_REQUIRED, $run->qa_status);
    }

    // =========================================================================
    // 5: semantic QA result preserved in snapshot
    // =========================================================================

    public function test_semantic_qa_result_preserved_in_snapshot(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        $articleVersion = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $article->id)
            ->where('is_current', true)
            ->first();

        $aiResult = $this->passingAiResult();
        $this->mock(WikiSemanticQaAiClient::class)
            ->shouldReceive('review')
            ->andReturn($aiResult);

        $this->orchestrator()->runForRun($run);

        $snapshot = EnterpriseWikiQaSnapshot::query()->first();
        $this->assertNotNull($snapshot);
        $this->assertTrue((bool) $snapshot->semantic_qa_ran);
        $this->assertTrue((bool) $snapshot->semantic_pass);
        $this->assertEqualsWithDelta(0.92, $snapshot->semantic_quality_score, 0.01);
        $this->assertEqualsWithDelta(0.90, $snapshot->semantic_coverage_score, 0.01);
        $this->assertEqualsWithDelta(0.97, $snapshot->semantic_factual_score, 0.01);
        $this->assertSame(0, (int) $snapshot->semantic_missing_topics_count);
        $this->assertSame(0, (int) $snapshot->semantic_missing_key_facts_count);
        $this->assertSame(0, (int) $snapshot->semantic_unsupported_claims_count);
        $this->assertNotEmpty($snapshot->semantic_model);
        $this->assertNotEmpty($snapshot->semantic_prompt_version);
        $this->assertNotNull($snapshot->semantic_page_version_id);
        $this->assertSame((int) $articleVersion->id, (int) $snapshot->semantic_page_version_id);
    }

    // =========================================================================
    // 6: repair and post-repair result preserved in snapshot
    // =========================================================================

    public function test_repair_and_post_repair_preserved_in_snapshot(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        $originalVersion = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $article->id)
            ->where('is_current', true)
            ->first();

        $this->mock(WikiSemanticReviserAiClient::class)
            ->shouldReceive('revise')->once()->andReturn(self::REVISED_MARKDOWN);

        $this->mock(WikiSemanticQaAiClient::class)
            ->shouldReceive('review')
            ->twice()
            ->andReturn($this->failingAiResult(action: 'targeted_revision'), $this->passingAiResult());

        $this->orchestrator()->runForRun($run);

        $snapshot = EnterpriseWikiQaSnapshot::query()->first();
        $this->assertNotNull($snapshot);
        $this->assertTrue((bool) $snapshot->semantic_repair_attempted);
        $this->assertTrue((bool) $snapshot->semantic_repair_success);
        $this->assertSame((int) $originalVersion->id, (int) $snapshot->semantic_repair_previous_version_id);
        $this->assertNotNull($snapshot->semantic_repair_new_version_id);
        $this->assertNotSame((int) $originalVersion->id, (int) $snapshot->semantic_repair_new_version_id);
        $this->assertNotEmpty($snapshot->semantic_repair_model);

        // Post-repair QA
        $this->assertTrue((bool) $snapshot->semantic_post_repair_pass);
        $this->assertNotNull($snapshot->semantic_post_repair_quality_score);
        $this->assertNotNull($snapshot->semantic_post_repair_coverage_score);
        $this->assertNotNull($snapshot->semantic_post_repair_factual_score);

        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $snapshot->qa_status);
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

        $this->mock(WikiSemanticQaAiClient::class)
            ->shouldReceive('review')
            ->andReturn($this->passingAiResult());

        $this->orchestrator()->runForRun($run);

        $snapshot = EnterpriseWikiQaSnapshot::query()->first();
        $this->assertNotNull($snapshot);
        $this->assertTrue((bool) $snapshot->technical_qa_passed);
        $this->assertTrue((bool) $snapshot->structural_qa_passed);
        $this->assertFalse((bool) $snapshot->open_lint_errors);
        $this->assertSame(0, (int) $snapshot->lint_error_count);
        $this->assertEqualsWithDelta(0.92, $snapshot->semantic_quality_score, 0.01);
        $this->assertEqualsWithDelta(0.90, $snapshot->semantic_coverage_score, 0.01);
        $this->assertEqualsWithDelta(0.97, $snapshot->semantic_factual_score, 0.01);
    }

    public function test_missing_topics_and_claims_counts_stored(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        $this->mock(WikiSemanticReviserAiClient::class)
            ->shouldReceive('revise')->once()->andReturn(self::REVISED_MARKDOWN);

        $this->mock(WikiSemanticQaAiClient::class)
            ->shouldReceive('review')
            ->twice()
            ->andReturn(
                $this->failingAiResult(
                    action: 'targeted_revision',
                    missingTopics: ['Topic A', 'Topic B'],
                    unsupportedClaims: ['Wrong claim'],
                ),
                $this->passingAiResult(),
            );

        $this->orchestrator()->runForRun($run);

        $snapshot = EnterpriseWikiQaSnapshot::query()->first();
        $this->assertSame(2, (int) $snapshot->semantic_missing_topics_count);
        $this->assertSame(1, (int) $snapshot->semantic_unsupported_claims_count);
    }

    // =========================================================================
    // 8: model, prompt_version, source_hash, page versions preserved
    // =========================================================================

    public function test_model_prompt_version_source_hash_preserved(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        $this->mock(WikiSemanticQaAiClient::class)
            ->shouldReceive('review')
            ->andReturn($this->passingAiResult());

        $this->orchestrator()->runForRun($run);

        $snapshot = EnterpriseWikiQaSnapshot::query()->first();
        $this->assertSame('gpt-4.1-mini/1.0', $snapshot->semantic_model);
        $this->assertSame('1.0', $snapshot->semantic_prompt_version);
        $this->assertNotNull($snapshot->semantic_source_hash);
        $this->assertSame(64, strlen($snapshot->semantic_source_hash));
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

        // First attempt: escalate
        $this->mock(WikiSemanticQaAiClient::class)
            ->shouldReceive('review')
            ->andReturn($this->failingAiResult(action: 'escalate'));

        $this->orchestrator()->runForRun($run);
        $this->assertSame(1, EnterpriseWikiQaSnapshot::query()->count());

        // Second attempt via retry: passes
        $this->mock(WikiSemanticQaAiClient::class)
            ->shouldReceive('review')
            ->andReturn($this->passingAiResult());

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

    public function test_snapshot_failure_on_passing_qa_gives_failed_not_passed(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        $this->mock(WikiSemanticQaAiClient::class)
            ->shouldReceive('review')
            ->andReturn($this->passingAiResult());

        $this->mock(EnterpriseWikiQaSnapshotService::class)
            ->shouldReceive('capture')
            ->andThrow(new \RuntimeException('DB connection lost during snapshot insert'));

        $this->orchestrator()->runForRun($run);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_FAILED, $run->qa_status);
        $this->assertSame(0, EnterpriseWikiQaSnapshot::query()->count());
    }

    public function test_snapshot_failure_sets_qa_last_error_describing_snapshot_failure(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        $this->mock(WikiSemanticQaAiClient::class)
            ->shouldReceive('review')
            ->andReturn($this->passingAiResult());

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

        $this->mock(WikiSemanticQaAiClient::class)
            ->shouldReceive('review')
            ->andReturn($this->passingAiResult());

        $this->mock(EnterpriseWikiQaSnapshotService::class)
            ->shouldReceive('capture')
            ->andThrow(new \RuntimeException('Disk full'));

        $this->orchestrator()->runForRun($run);

        $run->refresh();
        $this->assertNotNull($run->qa_result);
        $this->assertArrayHasKey('checks', $run->qa_result);
        $this->assertArrayHasKey('semantic_qa', $run->qa_result);
        $this->assertNotNull($run->qa_result['semantic_qa']);
        $this->assertTrue($run->qa_result['semantic_qa']['pass']);
    }

    public function test_snapshot_success_preserves_normal_terminal_status(): void
    {
        // Regression: when snapshot succeeds, the run must keep its QA-determined status.
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        $this->mock(WikiSemanticQaAiClient::class)
            ->shouldReceive('review')
            ->andReturn($this->passingAiResult());

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

        $this->mock(WikiSemanticQaAiClient::class)
            ->shouldReceive('review')
            ->andReturn($this->passingAiResult());

        // First attempt: snapshot fails → run ends as failed
        $snapshotMock = $this->mock(EnterpriseWikiQaSnapshotService::class);
        $snapshotMock->shouldReceive('capture')
            ->once()
            ->andThrow(new \RuntimeException('Transient DB error'));

        $this->orchestrator()->runForRun($run);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_FAILED, $run->qa_status);
        $this->assertSame(0, EnterpriseWikiQaSnapshot::query()->count());

        // Second attempt (retry): snapshot succeeds → run reaches passed
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
            'critique'                  => 'Content accurately represents the source.',
            'recommended_repair_action' => 'none',
            'confidence'                => 0.94,
            'model'                     => 'gpt-4.1-mini/1.0',
            'prompt_version'            => '1.0',
        ];
    }

    private function failingAiResult(
        string $action = 'targeted_revision',
        array $missingTopics = ['Section A'],
        array $unsupportedClaims = [],
    ): array {
        return [
            'pass'                      => false,
            'quality_score'             => 0.45,
            'coverage_score'            => 0.40,
            'factual_consistency_score' => 0.80,
            'unsupported_claims'        => $unsupportedClaims,
            'missing_topics'            => $missingTopics,
            'missing_key_facts'         => [],
            'critique'                  => 'Key topics missing.',
            'recommended_repair_action' => $action,
            'confidence'                => 0.85,
            'model'                     => 'gpt-4.1-mini/1.0',
            'prompt_version'            => '1.0',
        ];
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
        string $extractedText = 'Authoritative source document text for snapshot tests.',
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

    private function createAppliedRun(Customer $customer, ?string $qaStatus = null): EnterpriseWikiIngestRun
    {
        $document = $this->createDocument($customer);

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
            'qa_status'                        => $qaStatus,
        ]);
    }

    private function createPage(Customer $customer, string $pageType, string $title): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id'      => $customer->id,
            'slug'             => Str::slug($title) . '-' . Str::lower(Str::random(4)),
            'title'            => $title,
            'page_type'        => $pageType,
            'status'           => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by'     => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }

    private function addPageToRun(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $page): void
    {
        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id'       => $page->id,
            'action'                        => EnterpriseWikiIngestRunPage::ACTION_CREATED,
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
            'version_number'          => 1,
            'is_current'              => true,
            'content_markdown'        => $content !== '' ? $content : "# {$title}\n\nContent.",
            'generated_by_model'      => 'gpt-5',
        ]);

        return $page;
    }
}
