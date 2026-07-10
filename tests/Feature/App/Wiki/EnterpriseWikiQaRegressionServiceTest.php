<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiQaRegression;
use App\Models\EnterpriseWikiQaSnapshot;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\EnterpriseWiki\EnterpriseWikiDeepRepairService;
use App\Services\EnterpriseWiki\EnterpriseWikiPostIngestQaService;
use App\Services\EnterpriseWiki\EnterpriseWikiQaRegressionService;
use App\Services\EnterpriseWiki\EnterpriseWikiSemanticRepairService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tests for snapshot-based QA regression detection and maintenance orchestration (8H-utvidelse).
 *
 * Verifies threshold evaluation, baseline selection, idempotence, repair routing, and
 * escalation behavior. The regression service dependencies are mocked so the tests focus on
 * orchestration and stored regression records.
 */
class EnterpriseWikiQaRegressionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.enterprise_wiki.ai_enabled' => true]);
    }

    public function test_first_snapshot_is_recorded_as_baseline(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->createRun($customer, $document, EnterpriseWikiIngestRun::QA_STATUS_PASSED);
        $snapshot = $this->createSnapshot($run, [
            'snapshotted_at' => now()->subHour(),
            'semantic_qa_ran' => false,
            'semantic_pass' => null,
        ]);

        $result = $this->service()->processSnapshot($snapshot);

        $this->assertSame('baseline', $result['outcome']);
        $this->assertFalse($result['repaired']);
        $this->assertNull($result['repair_result']);
        $this->assertSame(1, EnterpriseWikiQaRegression::query()->count());

        $record = $result['record'];
        $this->assertNull($record->baseline_enterprise_wiki_qa_snapshot_id);
        $this->assertSame(EnterpriseWikiQaRegression::CLASSIFICATION_BASELINE, $record->regression_classification);
        $this->assertSame(EnterpriseWikiQaRegression::MAINTENANCE_ACTION_NONE, $record->maintenance_action);
        $this->assertSame(EnterpriseWikiQaRegression::ANALYSIS_STATUS_COMPLETED, $record->analysis_status);
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $record->final_status);
    }

    public function test_regression_selection_ignores_unrelated_sources_and_different_page_signatures(): void
    {
        $customer = $this->createCustomer('Selection Test AS');
        $document = $this->createDocument($customer, 'doc-a-hash');
        $otherDocument = $this->createDocument($customer, 'doc-b-hash');

        $relevantBaselineRun = $this->createRun($customer, $document, EnterpriseWikiIngestRun::QA_STATUS_PASSED);
        $this->createRunPages($customer, $relevantBaselineRun, [EnterpriseWikiPage::PAGE_TYPE_ARTICLE, EnterpriseWikiPage::PAGE_TYPE_SUMMARY]);
        $relevantBaseline = $this->createSnapshot($relevantBaselineRun, [
            'snapshotted_at' => now()->subHours(3),
            'semantic_quality_score' => 0.88,
            'semantic_coverage_score' => 0.89,
            'semantic_factual_score' => 0.96,
        ]);

        $otherSourceRun = $this->createRun($customer, $otherDocument, EnterpriseWikiIngestRun::QA_STATUS_PASSED);
        $this->createRunPages($customer, $otherSourceRun, [EnterpriseWikiPage::PAGE_TYPE_ARTICLE, EnterpriseWikiPage::PAGE_TYPE_SUMMARY]);
        $otherSourceSnapshot = $this->createSnapshot($otherSourceRun, [
            'snapshotted_at' => now()->subHours(2),
            'semantic_quality_score' => 0.99,
            'semantic_coverage_score' => 0.99,
            'semantic_factual_score' => 0.99,
        ]);

        $differentSignatureRun = $this->createRun($customer, $document, EnterpriseWikiIngestRun::QA_STATUS_PASSED);
        $this->createRunPages($customer, $differentSignatureRun, [EnterpriseWikiPage::PAGE_TYPE_ARTICLE]);
        $differentSignatureSnapshot = $this->createSnapshot($differentSignatureRun, [
            'snapshotted_at' => now()->subHour(),
            'semantic_quality_score' => 0.80,
            'semantic_coverage_score' => 0.81,
            'semantic_factual_score' => 0.95,
        ]);

        $currentRun = $this->createRun($customer, $document, EnterpriseWikiIngestRun::QA_STATUS_PASSED, sourceHash: null);
        $this->createRunPages($customer, $currentRun, [EnterpriseWikiPage::PAGE_TYPE_ARTICLE, EnterpriseWikiPage::PAGE_TYPE_SUMMARY]);
        $currentSnapshot = $this->createSnapshot($currentRun, [
            'snapshotted_at' => now(),
            'semantic_quality_score' => 0.78,
            'semantic_coverage_score' => 0.79,
            'semantic_factual_score' => 0.94,
        ]);

        $record = $this->service()->processSnapshot($currentSnapshot)['record'];

        $this->assertSame($relevantBaseline->id, $record->baseline_enterprise_wiki_qa_snapshot_id);
        $this->assertSame($relevantBaseline->id, $record->comparison_context['baseline_snapshot_id']);
        $this->assertSame(['article', 'summary'], $record->comparison_context['baseline_page_types']);
        $this->assertSame(['article', 'summary'], $record->comparison_context['current_page_types']);
        $this->assertSame('article|summary', $record->page_type_signature);
        $this->assertNotSame($otherSourceSnapshot->id, $record->baseline_enterprise_wiki_qa_snapshot_id);
        $this->assertNotSame($differentSignatureSnapshot->id, $record->baseline_enterprise_wiki_qa_snapshot_id);
        $this->assertSame(EnterpriseWikiQaRegression::CLASSIFICATION_SEMANTIC, $record->regression_classification);
        $this->assertSame(EnterpriseWikiQaRegression::MAINTENANCE_ACTION_ESCALATE, $record->maintenance_action);
    }

    public function test_small_quality_drop_stays_within_tolerance(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);

        $baselineRun = $this->createRun($customer, $document, EnterpriseWikiIngestRun::QA_STATUS_PASSED);
        $this->createRunPages($customer, $baselineRun, [EnterpriseWikiPage::PAGE_TYPE_ARTICLE, EnterpriseWikiPage::PAGE_TYPE_SUMMARY]);
        $this->createSnapshot($baselineRun, [
            'snapshotted_at' => now()->subHour(),
            'semantic_quality_score' => 0.90,
            'semantic_coverage_score' => 0.91,
            'semantic_factual_score' => 0.97,
        ]);

        $currentRun = $this->createRun($customer, $document, EnterpriseWikiIngestRun::QA_STATUS_PASSED);
        $this->createRunPages($customer, $currentRun, [EnterpriseWikiPage::PAGE_TYPE_ARTICLE, EnterpriseWikiPage::PAGE_TYPE_SUMMARY]);
        $currentSnapshot = $this->createSnapshot($currentRun, [
            'snapshotted_at' => now(),
            'semantic_quality_score' => 0.84,
            'semantic_coverage_score' => 0.90,
            'semantic_factual_score' => 0.96,
        ]);

        $result = $this->service()->processSnapshot($currentSnapshot);

        $this->assertSame('within_tolerance', $result['outcome']);
        $this->assertFalse($result['repaired']);
        $this->assertSame(EnterpriseWikiQaRegression::CLASSIFICATION_WITHIN_TOLERANCE, $result['record']->regression_classification);
        $this->assertSame(EnterpriseWikiQaRegression::MAINTENANCE_ACTION_NONE, $result['record']->maintenance_action);
        $this->assertSame(-0.06, $result['record']->metric_deltas['quality_score']);
        $this->assertSame(0.10, $result['record']->thresholds['quality_drop']);
        $this->assertSame(0.90, $result['record']->baseline_metrics['semantic_quality_score']);
    }

    public function test_semantic_regression_routes_through_semantic_repair_and_requeues_qa(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);

        $baselineRun = $this->createRun($customer, $document, EnterpriseWikiIngestRun::QA_STATUS_PASSED);
        $this->createRunPages($customer, $baselineRun, [EnterpriseWikiPage::PAGE_TYPE_ARTICLE, EnterpriseWikiPage::PAGE_TYPE_SUMMARY]);
        $this->createSnapshot($baselineRun, [
            'snapshotted_at' => now()->subHours(2),
            'semantic_quality_score' => 0.94,
            'semantic_coverage_score' => 0.93,
            'semantic_factual_score' => 0.98,
        ]);

        $currentRun = $this->createRun($customer, $document, EnterpriseWikiIngestRun::QA_STATUS_PASSED);
        $pages = $this->createRunPages($customer, $currentRun, [EnterpriseWikiPage::PAGE_TYPE_ARTICLE, EnterpriseWikiPage::PAGE_TYPE_SUMMARY]);
        $currentSnapshot = $this->createSnapshot($currentRun, [
            'snapshotted_at' => now(),
            'semantic_quality_score' => 0.80,
            'semantic_coverage_score' => 0.84,
            'semantic_factual_score' => 0.95,
        ]);

        $currentRun->update([
            'qa_result' => [
                'semantic_qa' => $this->semanticQaResult(
                    $pages['article']['version']->id,
                    critique: '',
                    recommendedRepairAction: 'none',
                ),
            ],
        ]);

        $capturedDiagnosis = null;

        $this->mockSemanticRepairService()
            ->shouldReceive('repair')
            ->once()
            ->withArgs(function (EnterpriseWikiIngestRun $run, array $diagnosis) use (&$capturedDiagnosis, $currentRun, $pages): bool {
                $capturedDiagnosis = $diagnosis;

                return $run->id === $currentRun->id
                    && ($diagnosis['recommended_repair_action'] ?? null) === 'targeted_revision'
                    && ($diagnosis['page_version_id'] ?? null) === $pages['article']['version']->id;
            })
            ->andReturn([
                'success' => true,
                'page_id' => $pages['article']['page']->id,
                'page_version_id' => $pages['article']['version']->id + 1,
                'previous_version_id' => $pages['article']['version']->id,
                'model' => 'mock-semantic-repair',
                'reason' => null,
            ]);

        $this->mockQaService()
            ->shouldReceive('runForRun')
            ->once()
            ->with(\Mockery::on(fn (EnterpriseWikiIngestRun $run): bool => $run->id === $currentRun->id), true)
            ->andReturnUsing(function (EnterpriseWikiIngestRun $run): array {
                $run->update([
                    'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_PASSED,
                    'qa_last_error' => null,
                    'qa_result' => [
                        'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_PASSED,
                        'semantic_qa_post_repair' => ['pass' => true],
                    ],
                ]);

                return [
                    'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_PASSED,
                    'semantic_qa_post_repair' => ['pass' => true],
                ];
            });

        $result = $this->service()->processSnapshot($currentSnapshot);

        $this->assertSame('repaired', $result['outcome']);
        $this->assertTrue($result['repaired']);
        $this->assertSame(EnterpriseWikiQaRegression::MAINTENANCE_ACTION_SEMANTIC_REPAIR, $result['record']->maintenance_action);
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $result['record']->final_status);
        $this->assertTrue($result['record']->repair_result['success']);
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $result['record']->repair_result['qa_status']);
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $result['record']->repair_result['qa_result']['qa_status']);
        $this->assertSame('targeted_revision', $capturedDiagnosis['recommended_repair_action']);
        $this->assertNotEmpty($capturedDiagnosis['critique']);
        $this->assertStringContainsString('classification=semantic_regression', $capturedDiagnosis['regression_summary']);

        $currentRun->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $currentRun->qa_status);
        $this->assertNull($currentRun->qa_last_error);
    }

    public function test_structural_regression_routes_through_deep_repair(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);

        $baselineRun = $this->createRun($customer, $document, EnterpriseWikiIngestRun::QA_STATUS_PASSED);
        $this->createRunPages($customer, $baselineRun, [EnterpriseWikiPage::PAGE_TYPE_ARTICLE, EnterpriseWikiPage::PAGE_TYPE_SUMMARY]);
        $this->createSnapshot($baselineRun, [
            'snapshotted_at' => now()->subHours(2),
            'semantic_qa_ran' => false,
            'semantic_pass' => null,
        ]);

        $currentRun = $this->createRun($customer, $document, EnterpriseWikiIngestRun::QA_STATUS_FAILED);
        $this->createRunPages($customer, $currentRun, [EnterpriseWikiPage::PAGE_TYPE_ARTICLE, EnterpriseWikiPage::PAGE_TYPE_SUMMARY]);
        $currentSnapshot = $this->createSnapshot($currentRun, [
            'snapshotted_at' => now(),
            'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_FAILED,
            'open_lint_errors' => true,
            'lint_error_count' => 1,
            'structural_qa_passed' => false,
            'semantic_qa_ran' => false,
            'semantic_pass' => null,
            'semantic_quality_score' => null,
            'semantic_coverage_score' => null,
            'semantic_factual_score' => null,
        ]);

        $this->mockSemanticRepairService()->shouldNotReceive('repair');
        $this->mockQaService()->shouldNotReceive('runForRun');

        $this->mockDeepRepairService()
            ->shouldReceive('attempt')
            ->once()
            ->withArgs(function (EnterpriseWikiIngestRun $run, string $sourceHash) use ($currentRun, $document): bool {
                return $run->id === $currentRun->id
                    && $sourceHash === $document->file_hash_sha256;
            })
            ->andReturn([
                'attempted' => true,
                'reason' => null,
                'source_hash' => $document->file_hash_sha256,
                'diagnosis' => [
                    'claims' => true,
                    'source_references' => true,
                    'page_links' => false,
                ],
                'components_repaired' => ['claims', 'source_references'],
                'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_PASSED,
            ]);

        $result = $this->service()->processSnapshot($currentSnapshot);

        $this->assertSame('repaired', $result['outcome']);
        $this->assertTrue($result['repaired']);
        $this->assertSame(EnterpriseWikiQaRegression::MAINTENANCE_ACTION_DEEP_REPAIR, $result['record']->maintenance_action);
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $result['record']->final_status);
        $this->assertSame('deep_repair', $result['record']->repair_result['service']);
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $result['record']->repair_result['qa_status']);
    }

    public function test_missing_source_hash_escalates_without_attempting_repair(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);

        $baselineRun = $this->createRun($customer, $document, EnterpriseWikiIngestRun::QA_STATUS_PASSED);
        $this->createRunPages($customer, $baselineRun, [EnterpriseWikiPage::PAGE_TYPE_ARTICLE, EnterpriseWikiPage::PAGE_TYPE_SUMMARY]);
        $this->createSnapshot($baselineRun, [
            'snapshotted_at' => now()->subHour(),
            'semantic_quality_score' => 0.91,
            'semantic_coverage_score' => 0.90,
            'semantic_factual_score' => 0.97,
        ]);

        $currentRun = $this->createRun($customer, $document, EnterpriseWikiIngestRun::QA_STATUS_PASSED, sourceHash: null);
        $this->createRunPages($customer, $currentRun, [EnterpriseWikiPage::PAGE_TYPE_ARTICLE, EnterpriseWikiPage::PAGE_TYPE_SUMMARY]);
        $currentSnapshot = $this->createSnapshot($currentRun, [
            'snapshotted_at' => now(),
            'semantic_quality_score' => 0.80,
            'semantic_coverage_score' => 0.82,
            'semantic_factual_score' => 0.95,
            'semantic_source_hash' => null,
        ]);

        $this->mockSemanticRepairService()->shouldNotReceive('repair');
        $this->mockDeepRepairService()->shouldNotReceive('attempt');
        $this->mockQaService()->shouldNotReceive('runForRun');

        $result = $this->service()->processSnapshot($currentSnapshot);

        $this->assertSame('escalated', $result['outcome']);
        $this->assertFalse($result['repaired']);
        $this->assertSame(EnterpriseWikiQaRegression::MAINTENANCE_ACTION_ESCALATE, $result['record']->maintenance_action);
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_ESCALATED, $result['record']->final_status);
        $this->assertNull($result['repair_result']);
        $this->assertNull($result['record']->comparison_context['source_hash']);

        $currentRun->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_ESCALATED, $currentRun->qa_status);
        $this->assertStringContainsString('[REGRESSION]', (string) $currentRun->qa_last_error);
    }

    public function test_processing_same_snapshot_twice_reuses_existing_regression_record(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);

        $baselineRun = $this->createRun($customer, $document, EnterpriseWikiIngestRun::QA_STATUS_PASSED);
        $this->createRunPages($customer, $baselineRun, [EnterpriseWikiPage::PAGE_TYPE_ARTICLE, EnterpriseWikiPage::PAGE_TYPE_SUMMARY]);
        $this->createSnapshot($baselineRun, [
            'snapshotted_at' => now()->subHours(2),
            'semantic_quality_score' => 0.94,
            'semantic_coverage_score' => 0.93,
            'semantic_factual_score' => 0.98,
        ]);

        $currentRun = $this->createRun($customer, $document, EnterpriseWikiIngestRun::QA_STATUS_PASSED);
        $pages = $this->createRunPages($customer, $currentRun, [EnterpriseWikiPage::PAGE_TYPE_ARTICLE, EnterpriseWikiPage::PAGE_TYPE_SUMMARY]);
        $currentSnapshot = $this->createSnapshot($currentRun, [
            'snapshotted_at' => now(),
            'semantic_quality_score' => 0.80,
            'semantic_coverage_score' => 0.84,
            'semantic_factual_score' => 0.95,
        ]);

        $currentRun->update([
            'qa_result' => [
                'semantic_qa' => $this->semanticQaResult($pages['article']['version']->id),
            ],
        ]);

        $this->mockSemanticRepairService()
            ->shouldReceive('repair')
            ->once()
            ->andReturn([
                'success' => true,
                'page_id' => $pages['article']['page']->id,
                'page_version_id' => $pages['article']['version']->id + 1,
                'previous_version_id' => $pages['article']['version']->id,
                'model' => 'mock-semantic-repair',
                'reason' => null,
            ]);

        $this->mockQaService()
            ->shouldReceive('runForRun')
            ->once()
            ->andReturnUsing(function (EnterpriseWikiIngestRun $run): array {
                $run->update([
                    'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_PASSED,
                    'qa_last_error' => null,
                    'qa_result' => ['qa_status' => EnterpriseWikiIngestRun::QA_STATUS_PASSED],
                ]);

                return ['qa_status' => EnterpriseWikiIngestRun::QA_STATUS_PASSED];
            });

        $firstResult = $this->service()->processSnapshot($currentSnapshot);
        $secondResult = $this->service()->processSnapshot($currentSnapshot);

        $this->assertSame('repaired', $firstResult['outcome']);
        $this->assertSame('repaired', $secondResult['outcome']);
        $this->assertSame(1, EnterpriseWikiQaRegression::query()->count());
        $this->assertSame($firstResult['record']->id, $secondResult['record']->id);
    }

    public function test_repair_exception_marks_run_and_record_failed(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);

        $baselineRun = $this->createRun($customer, $document, EnterpriseWikiIngestRun::QA_STATUS_PASSED);
        $this->createRunPages($customer, $baselineRun, [EnterpriseWikiPage::PAGE_TYPE_ARTICLE, EnterpriseWikiPage::PAGE_TYPE_SUMMARY]);
        $this->createSnapshot($baselineRun, [
            'snapshotted_at' => now()->subHour(),
            'semantic_qa_ran' => false,
            'semantic_pass' => null,
        ]);

        $currentRun = $this->createRun($customer, $document, EnterpriseWikiIngestRun::QA_STATUS_FAILED);
        $this->createRunPages($customer, $currentRun, [EnterpriseWikiPage::PAGE_TYPE_ARTICLE, EnterpriseWikiPage::PAGE_TYPE_SUMMARY]);
        $currentSnapshot = $this->createSnapshot($currentRun, [
            'snapshotted_at' => now(),
            'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_FAILED,
            'open_lint_errors' => true,
            'lint_error_count' => 1,
            'structural_qa_passed' => false,
            'semantic_qa_ran' => false,
            'semantic_pass' => null,
        ]);

        $this->mockSemanticRepairService()->shouldNotReceive('repair');
        $this->mockQaService()->shouldNotReceive('runForRun');
        $this->mockDeepRepairService()
            ->shouldReceive('attempt')
            ->once()
            ->andThrow(new \RuntimeException('Deep repair exploded'));

        $result = $this->service()->processSnapshot($currentSnapshot);

        $this->assertSame('failed', $result['outcome']);
        $this->assertFalse($result['repaired']);
        $this->assertSame(EnterpriseWikiQaRegression::ANALYSIS_STATUS_FAILED, $result['record']->analysis_status);
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_FAILED, $result['record']->final_status);
        $this->assertNull($result['record']->repair_result);
        $this->assertSame('Deep repair exploded', $result['record']->error_message);

        $currentRun->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_FAILED, $currentRun->qa_status);
        $this->assertStringContainsString('[REGRESSION] Deep repair exploded', (string) $currentRun->qa_last_error);
    }

    private function service(): EnterpriseWikiQaRegressionService
    {
        return app(EnterpriseWikiQaRegressionService::class);
    }

    private function mockSemanticRepairService(): \Mockery\MockInterface
    {
        return $this->mock(EnterpriseWikiSemanticRepairService::class);
    }

    private function mockDeepRepairService(): \Mockery\MockInterface
    {
        return $this->mock(EnterpriseWikiDeepRepairService::class);
    }

    private function mockQaService(): \Mockery\MockInterface
    {
        return $this->mock(EnterpriseWikiPostIngestQaService::class);
    }

    private function createCustomer(string $name = 'Regression Test AS'): Customer
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
            'slug' => Str::slug($name) . '-' . Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    private function createDocument(Customer $customer, ?string $hash = null): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'regression-test.pdf',
            'file_path' => sprintf('customers/%d/wiki-documents/regression-test.pdf', $customer->id),
            'file_hash_sha256' => $hash ?? hash('sha256', Str::random(32)),
            'extracted_text' => 'Authoritative source text for regression tests.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createRun(
        Customer $customer,
        EnterpriseWikiDocument $document,
        string $qaStatus,
        ?string $sourceHash = null,
    ): EnterpriseWikiIngestRun {
        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_hash' => func_num_args() >= 4 ? $sourceHash : $document->file_hash_sha256,
            'status' => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
            'maintainer_decision_json' => ['pages' => []],
            'qa_status' => $qaStatus,
        ]);
    }

    /**
     * @param  array<int, string>  $pageTypes
     * @return array<string, array{page: EnterpriseWikiPage, version: EnterpriseWikiPageVersion}>
     */
    private function createRunPages(Customer $customer, EnterpriseWikiIngestRun $run, array $pageTypes): array
    {
        $created = [];

        foreach ($pageTypes as $pageType) {
            $title = ucfirst($pageType) . ' ' . Str::upper(Str::random(4));
            $page = $this->createPage($customer, $pageType, $title);

            EnterpriseWikiIngestRunPage::query()->create([
                'enterprise_wiki_ingest_run_id' => $run->id,
                'enterprise_wiki_page_id' => $page->id,
                'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            ]);

            $version = EnterpriseWikiPageVersion::query()->create([
                'enterprise_wiki_page_id' => $page->id,
                'version_number' => 1,
                'is_current' => true,
                'content_markdown' => "# {$title}\n\n{$pageType} content.",
                'generated_by_model' => 'gpt-5/test',
            ]);

            $created[$pageType] = [
                'page' => $page,
                'version' => $version,
            ];
        }

        return $created;
    }

    private function createPage(Customer $customer, string $pageType, string $title): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => Str::slug($title) . '-' . Str::lower(Str::random(6)),
            'title' => $title,
            'page_type' => $pageType,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => hash('sha256', Str::random(16)),
        ]);
    }

    private function createSnapshot(EnterpriseWikiIngestRun $run, array $overrides = []): EnterpriseWikiQaSnapshot
    {
        $qaStatus = $overrides['qa_status'] ?? $run->qa_status ?? EnterpriseWikiIngestRun::QA_STATUS_PASSED;
        $openLintErrors = (bool) ($overrides['open_lint_errors'] ?? false);
        $lintErrorCount = (int) ($overrides['lint_error_count'] ?? 0);
        $semanticQaRan = array_key_exists('semantic_qa_ran', $overrides)
            ? (bool) $overrides['semantic_qa_ran']
            : true;

        $attributes = [
            'enterprise_wiki_ingest_run_id' => $run->id,
            'customer_id' => $run->customer_id,
            'qa_status' => $qaStatus,
            'qa_attempt_count' => $overrides['qa_attempt_count'] ?? 1,
            'snapshotted_at' => $overrides['snapshotted_at'] ?? now(),
            'technical_qa_passed' => $overrides['technical_qa_passed'] ?? true,
            'structural_qa_passed' => $overrides['structural_qa_passed'] ?? (! $openLintErrors && $lintErrorCount === 0),
            'open_lint_errors' => $openLintErrors,
            'lint_error_count' => $lintErrorCount,
            'lint_warning_count' => $overrides['lint_warning_count'] ?? 0,
            'semantic_qa_ran' => $semanticQaRan,
            'semantic_pass' => array_key_exists('semantic_pass', $overrides)
                ? $overrides['semantic_pass']
                : ($semanticQaRan ? $qaStatus === EnterpriseWikiIngestRun::QA_STATUS_PASSED : null),
            'semantic_quality_score' => $semanticQaRan ? ($overrides['semantic_quality_score'] ?? 0.92) : null,
            'semantic_coverage_score' => $semanticQaRan ? ($overrides['semantic_coverage_score'] ?? 0.90) : null,
            'semantic_factual_score' => $semanticQaRan ? ($overrides['semantic_factual_score'] ?? 0.97) : null,
            'semantic_missing_topics_count' => $overrides['semantic_missing_topics_count'] ?? 0,
            'semantic_missing_key_facts_count' => $overrides['semantic_missing_key_facts_count'] ?? 0,
            'semantic_unsupported_claims_count' => $overrides['semantic_unsupported_claims_count'] ?? 0,
            'semantic_source_hash' => array_key_exists('semantic_source_hash', $overrides)
                ? $overrides['semantic_source_hash']
                : $run->source_hash,
            'semantic_page_version_id' => $overrides['semantic_page_version_id'] ?? null,
            'semantic_model' => $overrides['semantic_model'] ?? 'gpt-4.1-mini/1.0',
            'semantic_prompt_version' => $overrides['semantic_prompt_version'] ?? '1.0',
            'semantic_repair_attempted' => $overrides['semantic_repair_attempted'] ?? false,
            'semantic_repair_success' => $overrides['semantic_repair_success'] ?? null,
            'semantic_repair_previous_version_id' => $overrides['semantic_repair_previous_version_id'] ?? null,
            'semantic_repair_new_version_id' => $overrides['semantic_repair_new_version_id'] ?? null,
            'semantic_repair_model' => $overrides['semantic_repair_model'] ?? null,
            'semantic_post_repair_page_version_id' => $overrides['semantic_post_repair_page_version_id'] ?? null,
            'semantic_post_repair_pass' => $overrides['semantic_post_repair_pass'] ?? null,
            'semantic_post_repair_quality_score' => $overrides['semantic_post_repair_quality_score'] ?? null,
            'semantic_post_repair_coverage_score' => $overrides['semantic_post_repair_coverage_score'] ?? null,
            'semantic_post_repair_factual_score' => $overrides['semantic_post_repair_factual_score'] ?? null,
            'deep_repair_attempted' => $overrides['deep_repair_attempted'] ?? false,
            'deep_repair_source_hash' => array_key_exists('deep_repair_source_hash', $overrides)
                ? $overrides['deep_repair_source_hash']
                : null,
            'deep_repair_components_repaired' => $overrides['deep_repair_components_repaired'] ?? null,
        ];

        return EnterpriseWikiQaSnapshot::query()->create(array_merge($attributes, $overrides));
    }

    private function semanticQaResult(int $pageVersionId, string $critique = '', string $recommendedRepairAction = 'none'): array
    {
        return [
            'pass' => true,
            'quality_score' => 0.80,
            'coverage_score' => 0.84,
            'factual_consistency_score' => 0.95,
            'unsupported_claims' => [],
            'missing_topics' => [],
            'missing_key_facts' => [],
            'critique' => $critique,
            'recommended_repair_action' => $recommendedRepairAction,
            'confidence' => 0.83,
            'model' => 'gpt-4.1-mini/1.0',
            'prompt_version' => '1.0',
            'page_version_id' => $pageVersionId,
            'skipped' => false,
            'escalated' => false,
        ];
    }
}
