<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiQaSnapshot;
use Illuminate\Support\Facades\Log;

/**
 * Creates immutable QA snapshots for trend history and regression detection (8G-6).
 *
 * One snapshot is created per terminal QA attempt (passed, failed, escalated).
 * Repair_required is an intermediate state within a single QA run and does not
 * produce a standalone snapshot.
 *
 * Idempotence is enforced by the unique constraint (enterprise_wiki_ingest_run_id, qa_attempt_count).
 * firstOrCreate ensures a duplicate call for the same attempt returns the existing snapshot.
 */
class EnterpriseWikiQaSnapshotService
{
    private const TERMINAL_STATUSES = [
        EnterpriseWikiIngestRun::QA_STATUS_PASSED,
        EnterpriseWikiIngestRun::QA_STATUS_FAILED,
        EnterpriseWikiIngestRun::QA_STATUS_ESCALATED,
    ];

    /**
     * Persist a snapshot for the given run's current QA state.
     *
     * Returns null if the run is not in a terminal status (e.g. still running or repair_required).
     * Errors during snapshot creation propagate to the caller — callers must wrap in try/catch
     * to prevent snapshot failures from masking the actual QA result.
     */
    public function capture(EnterpriseWikiIngestRun $run, array $result): ?EnterpriseWikiQaSnapshot
    {
        if (! in_array($run->qa_status, self::TERMINAL_STATUSES, true)) {
            return null;
        }

        [$snapshot, $created] = $this->firstOrCreateSnapshot($run, $result);

        if (! $created) {
            Log::info('[WIKI_QA_SNAPSHOT] Snapshot already exists for this attempt — skipped', [
                'run_id'           => $run->id,
                'qa_attempt_count' => $run->qa_attempt_count,
                'snapshot_id'      => $snapshot->id,
            ]);
        }

        return $snapshot;
    }

    // =========================================================================
    // Internal
    // =========================================================================

    /**
     * @return array{0: EnterpriseWikiQaSnapshot, 1: bool} [snapshot, wasCreated]
     */
    private function firstOrCreateSnapshot(EnterpriseWikiIngestRun $run, array $result): array
    {
        $uniqueKey = [
            'enterprise_wiki_ingest_run_id' => $run->id,
            'qa_attempt_count'              => $run->qa_attempt_count,
        ];

        $existing = EnterpriseWikiQaSnapshot::query()->where($uniqueKey)->first();

        if ($existing !== null) {
            return [$existing, false];
        }

        $snapshot = EnterpriseWikiQaSnapshot::create(
            array_merge($uniqueKey, $this->buildAttributes($run, $result))
        );

        Log::info('[WIKI_QA_SNAPSHOT] Snapshot created', [
            'snapshot_id'      => $snapshot->id,
            'run_id'           => $run->id,
            'customer_id'      => $run->customer_id,
            'qa_status'        => $run->qa_status,
            'qa_attempt_count' => $run->qa_attempt_count,
        ]);

        return [$snapshot, true];
    }

    private function buildAttributes(EnterpriseWikiIngestRun $run, array $result): array
    {
        $checks     = $result['checks'] ?? [];
        $lintSummary = $result['lint_summary'] ?? [];
        $openLintErrors = (bool) ($result['open_lint_errors'] ?? false);

        $hasCriticalGap = ! ($checks['article_exists'] ?? false)
            || ! ($checks['summary_exists'] ?? false)
            || ! ($checks['article_has_content'] ?? false)
            || ! ($checks['summary_has_content'] ?? false);

        $technicalQaPassed = ! $hasCriticalGap;
        $structuralQaPassed = ! $hasCriticalGap && ! $openLintErrors;

        $lintErrorCount   = (int) ($lintSummary['errors'] ?? 0);
        $lintWarningCount = (int) ($lintSummary['warnings'] ?? 0);

        $semanticQa  = $result['semantic_qa'] ?? null;
        $semanticQaRan = $semanticQa !== null
            && ! ($semanticQa['skipped'] ?? false)
            && ! ($semanticQa['escalated'] ?? false);

        $repairResult = $result['semantic_repair_result'] ?? null;
        $postRepair   = $result['semantic_qa_post_repair'] ?? null;

        return [
            'customer_id'                       => $run->customer_id,
            'qa_status'                         => $run->qa_status,
            'qa_attempt_count'                  => $run->qa_attempt_count,
            'snapshotted_at'                    => now(),

            // Technical + structural
            'technical_qa_passed'               => $technicalQaPassed,
            'structural_qa_passed'              => $structuralQaPassed,
            'open_lint_errors'                  => $openLintErrors,
            'lint_error_count'                  => $lintErrorCount,
            'lint_warning_count'                => $lintWarningCount,

            // Semantic QA
            'semantic_qa_ran'                   => $semanticQaRan,
            'semantic_pass'                     => $semanticQaRan ? (bool) ($semanticQa['pass'] ?? false) : null,
            'semantic_quality_score'            => $semanticQaRan ? ($semanticQa['quality_score'] ?? null) : null,
            'semantic_coverage_score'           => $semanticQaRan ? ($semanticQa['coverage_score'] ?? null) : null,
            'semantic_factual_score'            => $semanticQaRan ? ($semanticQa['factual_consistency_score'] ?? null) : null,
            'semantic_missing_topics_count'     => count($semanticQa['missing_topics'] ?? []),
            'semantic_missing_key_facts_count'  => count($semanticQa['missing_key_facts'] ?? []),
            'semantic_unsupported_claims_count' => count($semanticQa['unsupported_claims'] ?? []),
            'semantic_source_hash'              => $semanticQa['source_hash'] ?? null,
            'semantic_page_version_id'          => $semanticQa['page_version_id'] ?? null,
            'semantic_model'                    => $semanticQa['model'] ?? null,
            'semantic_prompt_version'           => $semanticQa['prompt_version'] ?? null,

            // Repair
            'semantic_repair_attempted'         => (bool) ($result['semantic_repair_attempted'] ?? false),
            'semantic_repair_success'           => $repairResult !== null ? (bool) ($repairResult['success'] ?? false) : null,
            'semantic_repair_previous_version_id' => $repairResult['previous_version_id'] ?? null,
            'semantic_repair_new_version_id'    => $repairResult['page_version_id'] ?? null,
            'semantic_repair_model'             => $repairResult['model'] ?? null,

            // Post-repair QA
            'semantic_post_repair_page_version_id' => $postRepair['page_version_id'] ?? null,
            'semantic_post_repair_pass'            => $postRepair !== null ? (bool) ($postRepair['pass'] ?? false) : null,
            'semantic_post_repair_quality_score'   => $postRepair['quality_score'] ?? null,
            'semantic_post_repair_coverage_score'  => $postRepair['coverage_score'] ?? null,
            'semantic_post_repair_factual_score'   => $postRepair['factual_consistency_score'] ?? null,
        ];
    }
}
