<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiQaRegression;
use App\Models\EnterpriseWikiQaSnapshot;

/**
 * Deterministic threshold policy for QA regression detection.
 *
 * All thresholds live here so the maintenance logic stays explicit and testable.
 * The policy compares only explicit metrics/statuses from immutable QA snapshots.
 */
class EnterpriseWikiQaRegressionPolicy
{
    public const QUALITY_DROP_THRESHOLD = 0.10;

    public const COVERAGE_DROP_THRESHOLD = 0.10;

    public const FACTUAL_DROP_THRESHOLD = 0.10;

    public const UNSUPPORTED_CLAIMS_INCREASE_THRESHOLD = 1;

    public const MISSING_TOPICS_INCREASE_THRESHOLD = 1;

    public const MISSING_KEY_FACTS_INCREASE_THRESHOLD = 1;

    public const LINT_ERROR_INCREASE_THRESHOLD = 1;

    public function thresholds(): array
    {
        return [
            'quality_drop' => self::QUALITY_DROP_THRESHOLD,
            'coverage_drop' => self::COVERAGE_DROP_THRESHOLD,
            'factual_drop' => self::FACTUAL_DROP_THRESHOLD,
            'unsupported_claims_increase' => self::UNSUPPORTED_CLAIMS_INCREASE_THRESHOLD,
            'missing_topics_increase' => self::MISSING_TOPICS_INCREASE_THRESHOLD,
            'missing_key_facts_increase' => self::MISSING_KEY_FACTS_INCREASE_THRESHOLD,
            'lint_error_increase' => self::LINT_ERROR_INCREASE_THRESHOLD,
        ];
    }

    /**
     * Compare two snapshots and return a deterministic regression assessment.
     *
     * @return array{
     *   regression_detected: bool,
     *   classification: string,
     *   maintenance_action: string,
     *   signals: array<int, array<string, mixed>>,
     *   metric_deltas: array<string, mixed>,
     *   current_metrics: array<string, mixed>,
     *   baseline_metrics: array<string, mixed>|null
     * }
     */
    public function evaluate(EnterpriseWikiQaSnapshot $current, ?EnterpriseWikiQaSnapshot $baseline): array
    {
        $currentMetrics = $this->metricsForSnapshot($current);

        if ($baseline === null) {
            return [
                'regression_detected' => false,
                'classification' => EnterpriseWikiQaRegression::CLASSIFICATION_BASELINE,
                'maintenance_action' => EnterpriseWikiQaRegression::MAINTENANCE_ACTION_NONE,
                'signals' => [],
                'metric_deltas' => [],
                'current_metrics' => $currentMetrics,
                'baseline_metrics' => null,
            ];
        }

        $baselineMetrics = $this->metricsForSnapshot($baseline);
        $signals = [];
        $deltas = [];

        $semanticComparisonsAllowed = (bool) $currentMetrics['semantic_qa_ran']
            && (bool) $baselineMetrics['semantic_qa_ran'];

        if ($semanticComparisonsAllowed) {
            $this->addScoreSignal(
                $signals,
                $deltas,
                'quality_score',
                $currentMetrics['semantic_quality_score'],
                $baselineMetrics['semantic_quality_score'],
                self::QUALITY_DROP_THRESHOLD,
            );

            $this->addScoreSignal(
                $signals,
                $deltas,
                'coverage_score',
                $currentMetrics['semantic_coverage_score'],
                $baselineMetrics['semantic_coverage_score'],
                self::COVERAGE_DROP_THRESHOLD,
            );

            $this->addScoreSignal(
                $signals,
                $deltas,
                'factual_consistency_score',
                $currentMetrics['semantic_factual_score'],
                $baselineMetrics['semantic_factual_score'],
                self::FACTUAL_DROP_THRESHOLD,
            );

            $this->addCountSignal(
                $signals,
                $deltas,
                'unsupported_claims_count',
                $currentMetrics['semantic_unsupported_claims_count'],
                $baselineMetrics['semantic_unsupported_claims_count'],
                self::UNSUPPORTED_CLAIMS_INCREASE_THRESHOLD,
            );

            $this->addCountSignal(
                $signals,
                $deltas,
                'missing_topics_count',
                $currentMetrics['semantic_missing_topics_count'],
                $baselineMetrics['semantic_missing_topics_count'],
                self::MISSING_TOPICS_INCREASE_THRESHOLD,
            );

            $this->addCountSignal(
                $signals,
                $deltas,
                'missing_key_facts_count',
                $currentMetrics['semantic_missing_key_facts_count'],
                $baselineMetrics['semantic_missing_key_facts_count'],
                self::MISSING_KEY_FACTS_INCREASE_THRESHOLD,
            );
        }

        $this->addCountSignal(
            $signals,
            $deltas,
            'lint_error_count',
            $currentMetrics['lint_error_count'],
            $baselineMetrics['lint_error_count'],
            self::LINT_ERROR_INCREASE_THRESHOLD,
        );

        $this->addBooleanSignal(
            $signals,
            $deltas,
            'technical_qa_passed',
            $currentMetrics['technical_qa_passed'],
            $baselineMetrics['technical_qa_passed'],
        );

        $this->addBooleanSignal(
            $signals,
            $deltas,
            'structural_qa_passed',
            $currentMetrics['structural_qa_passed'],
            $baselineMetrics['structural_qa_passed'],
        );

        $this->addBooleanSignal(
            $signals,
            $deltas,
            'open_lint_errors',
            $currentMetrics['open_lint_errors'],
            $baselineMetrics['open_lint_errors'],
        );

        $this->addStatusSignal(
            $signals,
            $deltas,
            'qa_status',
            $currentMetrics['qa_status'],
            $baselineMetrics['qa_status'],
        );

        $regressionDetected = collect($signals)
            ->contains(fn (array $signal): bool => ($signal['impact'] ?? 'info') === 'regression');

        $hasSemanticSignals = collect($signals)
            ->contains(fn (array $signal): bool => ($signal['group'] ?? null) === 'semantic');

        $hasStructuralSignals = collect($signals)
            ->contains(fn (array $signal): bool => ($signal['group'] ?? null) === 'structural');

        $classification = match (true) {
            ! $regressionDetected => EnterpriseWikiQaRegression::CLASSIFICATION_WITHIN_TOLERANCE,
            $hasSemanticSignals && $hasStructuralSignals => EnterpriseWikiQaRegression::CLASSIFICATION_MIXED,
            $hasStructuralSignals => EnterpriseWikiQaRegression::CLASSIFICATION_STRUCTURAL,
            default => EnterpriseWikiQaRegression::CLASSIFICATION_SEMANTIC,
        };

        $maintenanceAction = match (true) {
            ! $regressionDetected => EnterpriseWikiQaRegression::MAINTENANCE_ACTION_NONE,
            $hasStructuralSignals => EnterpriseWikiQaRegression::MAINTENANCE_ACTION_DEEP_REPAIR,
            $hasSemanticSignals => EnterpriseWikiQaRegression::MAINTENANCE_ACTION_SEMANTIC_REPAIR,
            default => EnterpriseWikiQaRegression::MAINTENANCE_ACTION_ESCALATE,
        };

        return [
            'regression_detected' => $regressionDetected,
            'classification' => $classification,
            'maintenance_action' => $maintenanceAction,
            'signals' => $signals,
            'metric_deltas' => $deltas,
            'current_metrics' => $currentMetrics,
            'baseline_metrics' => $baselineMetrics,
        ];
    }

    private function metricsForSnapshot(EnterpriseWikiQaSnapshot $snapshot): array
    {
        return [
            'qa_status' => $snapshot->qa_status,
            'technical_qa_passed' => (bool) $snapshot->technical_qa_passed,
            'structural_qa_passed' => (bool) $snapshot->structural_qa_passed,
            'open_lint_errors' => (bool) $snapshot->open_lint_errors,
            'lint_error_count' => (int) $snapshot->lint_error_count,
            'lint_warning_count' => (int) $snapshot->lint_warning_count,
            'semantic_qa_ran' => (bool) $snapshot->semantic_qa_ran,
            'semantic_pass' => $snapshot->semantic_pass !== null ? (bool) $snapshot->semantic_pass : null,
            'semantic_quality_score' => $snapshot->semantic_quality_score,
            'semantic_coverage_score' => $snapshot->semantic_coverage_score,
            'semantic_factual_score' => $snapshot->semantic_factual_score,
            'semantic_missing_topics_count' => (int) $snapshot->semantic_missing_topics_count,
            'semantic_missing_key_facts_count' => (int) $snapshot->semantic_missing_key_facts_count,
            'semantic_unsupported_claims_count' => (int) $snapshot->semantic_unsupported_claims_count,
            'deep_repair_attempted' => (bool) $snapshot->deep_repair_attempted,
            'deep_repair_source_hash' => $snapshot->deep_repair_source_hash,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $signals
     * @param  array<string, mixed>  $deltas
     */
    private function addScoreSignal(
        array &$signals,
        array &$deltas,
        string $metric,
        mixed $current,
        mixed $baseline,
        float $threshold,
    ): void {
        if ($current === null || $baseline === null) {
            return;
        }

        $delta = round((float) $current - (float) $baseline, 4);
        $deltas[$metric] = $delta;

        if ($delta <= (-1 * $threshold)) {
            $signals[] = [
                'group' => 'semantic',
                'metric' => $metric,
                'current' => (float) $current,
                'baseline' => (float) $baseline,
                'delta' => $delta,
                'threshold' => $threshold,
                'impact' => 'regression',
            ];
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $signals
     * @param  array<string, mixed>  $deltas
     */
    private function addCountSignal(
        array &$signals,
        array &$deltas,
        string $metric,
        mixed $current,
        mixed $baseline,
        int $threshold,
    ): void {
        $current = (int) $current;
        $baseline = (int) $baseline;
        $delta = $current - $baseline;
        $deltas[$metric] = $delta;

        if ($delta >= $threshold) {
            $signals[] = [
                'group' => in_array($metric, ['lint_error_count'], true) ? 'structural' : 'semantic',
                'metric' => $metric,
                'current' => $current,
                'baseline' => $baseline,
                'delta' => $delta,
                'threshold' => $threshold,
                'impact' => 'regression',
            ];
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $signals
     * @param  array<string, mixed>  $deltas
     */
    private function addBooleanSignal(
        array &$signals,
        array &$deltas,
        string $metric,
        bool $current,
        bool $baseline,
    ): void {
        $deltas[$metric] = $current === $baseline ? 0 : ($current ? 1 : -1);

        if ($baseline === true && $current === false) {
            $signals[] = [
                'group' => in_array($metric, ['technical_qa_passed', 'structural_qa_passed', 'open_lint_errors'], true)
                    ? 'structural'
                    : 'semantic',
                'metric' => $metric,
                'current' => $current,
                'baseline' => $baseline,
                'delta' => -1,
                'threshold' => 1,
                'impact' => 'regression',
            ];
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $signals
     * @param  array<string, mixed>  $deltas
     */
    private function addStatusSignal(
        array &$signals,
        array &$deltas,
        string $metric,
        mixed $current,
        mixed $baseline,
    ): void {
        if ($baseline === null || $current === null) {
            return;
        }

        $statusDelta = $this->statusDelta((string) $current, (string) $baseline);
        $deltas[$metric] = $statusDelta;

        if ($statusDelta < 0) {
            $signals[] = [
                'group' => 'structural',
                'metric' => $metric,
                'current' => $current,
                'baseline' => $baseline,
                'delta' => $statusDelta,
                'threshold' => 1,
                'impact' => 'regression',
            ];
        }
    }

    private function statusDelta(string $current, string $baseline): int
    {
        $order = [
            EnterpriseWikiIngestRun::QA_STATUS_PASSED => 3,
            EnterpriseWikiIngestRun::QA_STATUS_ESCALATED => 2,
            EnterpriseWikiIngestRun::QA_STATUS_FAILED => 1,
        ];

        $currentScore = $order[$current] ?? 0;
        $baselineScore = $order[$baseline] ?? 0;

        return $currentScore - $baselineScore;
    }
}
