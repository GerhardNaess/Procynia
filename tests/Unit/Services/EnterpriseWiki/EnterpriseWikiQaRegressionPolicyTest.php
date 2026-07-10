<?php

namespace Tests\Unit\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiQaRegression;
use App\Models\EnterpriseWikiQaSnapshot;
use App\Services\EnterpriseWiki\EnterpriseWikiQaRegressionPolicy;
use Tests\TestCase;

class EnterpriseWikiQaRegressionPolicyTest extends TestCase
{
    public function test_snapshot_without_baseline_is_classified_as_baseline(): void
    {
        $policy = new EnterpriseWikiQaRegressionPolicy();
        $current = $this->makeSnapshot();

        $result = $policy->evaluate($current, null);

        $this->assertFalse($result['regression_detected']);
        $this->assertSame(EnterpriseWikiQaRegression::CLASSIFICATION_BASELINE, $result['classification']);
        $this->assertSame(EnterpriseWikiQaRegression::MAINTENANCE_ACTION_NONE, $result['maintenance_action']);
        $this->assertSame([], $result['signals']);
    }

    public function test_small_quality_drop_stays_within_tolerance(): void
    {
        $policy = new EnterpriseWikiQaRegressionPolicy();
        $baseline = $this->makeSnapshot(semanticQualityScore: 0.90);
        $current = $this->makeSnapshot(semanticQualityScore: 0.84);

        $result = $policy->evaluate($current, $baseline);

        $this->assertFalse($result['regression_detected']);
        $this->assertSame(EnterpriseWikiQaRegression::CLASSIFICATION_WITHIN_TOLERANCE, $result['classification']);
        $this->assertSame(EnterpriseWikiQaRegression::MAINTENANCE_ACTION_NONE, $result['maintenance_action']);
        $this->assertSame(-0.06, $result['metric_deltas']['quality_score']);
    }

    public function test_large_quality_drop_registers_regression(): void
    {
        $policy = new EnterpriseWikiQaRegressionPolicy();
        $baseline = $this->makeSnapshot(semanticQualityScore: 0.93);
        $current = $this->makeSnapshot(semanticQualityScore: 0.80);

        $result = $policy->evaluate($current, $baseline);

        $this->assertTrue($result['regression_detected']);
        $this->assertSame(EnterpriseWikiQaRegression::CLASSIFICATION_SEMANTIC, $result['classification']);
        $this->assertSame(EnterpriseWikiQaRegression::MAINTENANCE_ACTION_SEMANTIC_REPAIR, $result['maintenance_action']);
        $this->assertSame(-0.13, $result['metric_deltas']['quality_score']);
    }

    public function test_unsupported_claims_increase_registers_regression(): void
    {
        $policy = new EnterpriseWikiQaRegressionPolicy();
        $baseline = $this->makeSnapshot(semanticUnsupportedClaimsCount: 0);
        $current = $this->makeSnapshot(semanticUnsupportedClaimsCount: 2);

        $result = $policy->evaluate($current, $baseline);

        $this->assertTrue($result['regression_detected']);
        $this->assertSame(EnterpriseWikiQaRegression::CLASSIFICATION_SEMANTIC, $result['classification']);
        $this->assertSame(EnterpriseWikiQaRegression::MAINTENANCE_ACTION_SEMANTIC_REPAIR, $result['maintenance_action']);
        $this->assertSame(2, $result['metric_deltas']['unsupported_claims_count']);
    }

    public function test_missing_topics_increase_registers_regression(): void
    {
        $policy = new EnterpriseWikiQaRegressionPolicy();
        $baseline = $this->makeSnapshot(semanticMissingTopicsCount: 0);
        $current = $this->makeSnapshot(semanticMissingTopicsCount: 1);

        $result = $policy->evaluate($current, $baseline);

        $this->assertTrue($result['regression_detected']);
        $this->assertSame(EnterpriseWikiQaRegression::MAINTENANCE_ACTION_SEMANTIC_REPAIR, $result['maintenance_action']);
        $this->assertSame(1, $result['metric_deltas']['missing_topics_count']);
    }

    public function test_missing_key_facts_increase_registers_regression(): void
    {
        $policy = new EnterpriseWikiQaRegressionPolicy();
        $baseline = $this->makeSnapshot(semanticMissingKeyFactsCount: 0);
        $current = $this->makeSnapshot(semanticMissingKeyFactsCount: 2);

        $result = $policy->evaluate($current, $baseline);

        $this->assertTrue($result['regression_detected']);
        $this->assertSame(EnterpriseWikiQaRegression::MAINTENANCE_ACTION_SEMANTIC_REPAIR, $result['maintenance_action']);
        $this->assertSame(2, $result['metric_deltas']['missing_key_facts_count']);
    }

    public function test_new_lint_errors_register_structural_regression(): void
    {
        $policy = new EnterpriseWikiQaRegressionPolicy();
        $baseline = $this->makeSnapshot(lintErrorCount: 0, openLintErrors: false);
        $current = $this->makeSnapshot(lintErrorCount: 1, openLintErrors: true);

        $result = $policy->evaluate($current, $baseline);

        $this->assertTrue($result['regression_detected']);
        $this->assertSame(EnterpriseWikiQaRegression::CLASSIFICATION_STRUCTURAL, $result['classification']);
        $this->assertSame(EnterpriseWikiQaRegression::MAINTENANCE_ACTION_DEEP_REPAIR, $result['maintenance_action']);
        $this->assertSame(1, $result['metric_deltas']['lint_error_count']);
    }

    public function test_qa_status_drop_registers_structural_regression(): void
    {
        $policy = new EnterpriseWikiQaRegressionPolicy();
        $baseline = $this->makeSnapshot(qaStatus: EnterpriseWikiIngestRun::QA_STATUS_PASSED);
        $current = $this->makeSnapshot(qaStatus: EnterpriseWikiIngestRun::QA_STATUS_FAILED);

        $result = $policy->evaluate($current, $baseline);

        $this->assertTrue($result['regression_detected']);
        $this->assertSame(EnterpriseWikiQaRegression::CLASSIFICATION_STRUCTURAL, $result['classification']);
        $this->assertSame(EnterpriseWikiQaRegression::MAINTENANCE_ACTION_DEEP_REPAIR, $result['maintenance_action']);
        $this->assertLessThan(0, $result['metric_deltas']['qa_status']);
    }

    private function makeSnapshot(
        string $qaStatus = EnterpriseWikiIngestRun::QA_STATUS_PASSED,
        float $semanticQualityScore = 0.92,
        float $semanticCoverageScore = 0.90,
        float $semanticFactualScore = 0.97,
        int $semanticMissingTopicsCount = 0,
        int $semanticMissingKeyFactsCount = 0,
        int $semanticUnsupportedClaimsCount = 0,
        int $lintErrorCount = 0,
        bool $openLintErrors = false,
    ): EnterpriseWikiQaSnapshot {
        $snapshot = new EnterpriseWikiQaSnapshot();

        $snapshot->forceFill([
            'qa_status' => $qaStatus,
            'technical_qa_passed' => true,
            'structural_qa_passed' => ! $openLintErrors && $lintErrorCount === 0,
            'open_lint_errors' => $openLintErrors,
            'lint_error_count' => $lintErrorCount,
            'lint_warning_count' => 0,
            'semantic_qa_ran' => true,
            'semantic_pass' => $qaStatus === EnterpriseWikiIngestRun::QA_STATUS_PASSED,
            'semantic_quality_score' => $semanticQualityScore,
            'semantic_coverage_score' => $semanticCoverageScore,
            'semantic_factual_score' => $semanticFactualScore,
            'semantic_missing_topics_count' => $semanticMissingTopicsCount,
            'semantic_missing_key_facts_count' => $semanticMissingKeyFactsCount,
            'semantic_unsupported_claims_count' => $semanticUnsupportedClaimsCount,
            'deep_repair_attempted' => false,
            'deep_repair_source_hash' => null,
        ]);

        return $snapshot;
    }
}
