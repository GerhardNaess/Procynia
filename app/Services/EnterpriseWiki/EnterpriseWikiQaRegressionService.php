<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiQaRegression;
use App\Models\EnterpriseWikiQaSnapshot;
use Illuminate\Support\Facades\Log;

/**
 * Snapshot-based regression detection and maintenance orchestration for Enterprise Wiki (8H-utvidelse).
 *
 * This service compares immutable QA snapshots within the same customer/source/page-type scope,
 * records the comparison in a dedicated regression table, and decides whether an existing repair
 * flow should be triggered or the run should be escalated.
 */
class EnterpriseWikiQaRegressionService
{
    /** @var array<int, array{signature: string, types: array<int, string>}> */
    private array $pageTypeCache = [];

    public function __construct(
        private readonly EnterpriseWikiQaRegressionPolicy $policy,
        private readonly EnterpriseWikiSemanticRepairService $semanticRepairService,
        private readonly EnterpriseWikiDeepRepairService $deepRepairService,
        private readonly EnterpriseWikiPostIngestQaService $qaService,
    ) {}

    /**
     * Process all terminal QA snapshots that have not yet been classified.
     *
     * Returns a simple summary for logging/diagnostics only.
     *
     * @return array{processed:int, baseline:int, within_tolerance:int, repaired:int, escalated:int, failed:int}
     */
    public function processPendingSnapshots(): array
    {
        $snapshots = EnterpriseWikiQaSnapshot::query()
            ->whereIn('qa_status', [
                EnterpriseWikiIngestRun::QA_STATUS_PASSED,
                EnterpriseWikiIngestRun::QA_STATUS_FAILED,
                EnterpriseWikiIngestRun::QA_STATUS_ESCALATED,
            ])
            ->whereDoesntHave('regression')
            ->orderBy('snapshotted_at')
            ->orderBy('id')
            ->get();

        $summary = [
            'processed' => 0,
            'baseline' => 0,
            'within_tolerance' => 0,
            'repaired' => 0,
            'escalated' => 0,
            'failed' => 0,
        ];

        foreach ($snapshots as $snapshot) {
            $result = $this->processSnapshot($snapshot);

            $summary['processed']++;
            $summary[$result['outcome']]++;
        }

        Log::info('[WIKI_QA_REGRESSION] Regression scan complete', $summary);

        return $summary;
    }

    /**
     * Classify a single snapshot and, if needed, run the existing repair flow.
     *
     * @return array{
     *   outcome: string,
     *   record: EnterpriseWikiQaRegression,
     *   repaired: bool,
     *   repair_result: array<string, mixed>|null
     * }
     */
    public function processSnapshot(EnterpriseWikiQaSnapshot $snapshot): array
    {
        $snapshot->loadMissing('ingestRun');
        $run = $snapshot->ingestRun;

        if ($run === null) {
            throw new \RuntimeException("QA snapshot [{$snapshot->id}] has no ingest run.");
        }

        $currentPageTypes = $this->pageTypesForRun($run->id);
        $currentSignature = $this->pageTypeSignature($currentPageTypes);
        $baseline = $this->findPreviousRelevantSnapshot($snapshot, $currentSignature);
        $evaluation = $this->policy->evaluate($snapshot, $baseline);

        $sourceHash = $run->source_hash;
        $comparisonContext = [
            'customer_id' => $run->customer_id,
            'source_type' => $run->source_type,
            'source_id' => $run->source_id,
            'source_hash' => $sourceHash,
            'current_snapshot_id' => $snapshot->id,
            'baseline_snapshot_id' => $baseline?->id,
            'current_page_types' => $currentPageTypes,
            'baseline_page_types' => $baseline !== null && $baseline->ingestRun !== null
                ? $this->pageTypesForRun($baseline->ingestRun->id)
                : null,
            'page_type_signature' => $currentSignature,
        ];

        $baseRecordData = [
            'baseline_enterprise_wiki_qa_snapshot_id' => $baseline?->id,
            'enterprise_wiki_ingest_run_id' => $run->id,
            'customer_id' => $run->customer_id,
            'source_type' => $run->source_type,
            'source_id' => $run->source_id,
            'source_hash' => $sourceHash,
            'page_type_signature' => $currentSignature,
            'comparison_context' => $comparisonContext,
            'current_metrics' => $evaluation['current_metrics'],
            'baseline_metrics' => $evaluation['baseline_metrics'],
            'metric_deltas' => $evaluation['metric_deltas'],
            'thresholds' => $this->policy->thresholds(),
            'regression_signals' => $evaluation['signals'],
            'regression_classification' => $evaluation['classification'],
            'maintenance_action' => $evaluation['maintenance_action'],
        ];

        $existing = EnterpriseWikiQaRegression::query()
            ->where('enterprise_wiki_qa_snapshot_id', $snapshot->id)
            ->first();

        if ($existing !== null) {
            return [
                'outcome' => $this->outcomeForRecord($existing),
                'record' => $existing,
                'repaired' => $existing->repair_result !== null,
                'repair_result' => $existing->repair_result,
            ];
        }

        if ($baseline === null || ! $evaluation['regression_detected']) {
            $record = EnterpriseWikiQaRegression::create(array_merge($baseRecordData, [
                'enterprise_wiki_qa_snapshot_id' => $snapshot->id,
                'analysis_status' => EnterpriseWikiQaRegression::ANALYSIS_STATUS_COMPLETED,
                'analysis_started_at' => now(),
                'analysis_completed_at' => now(),
                'final_status' => $snapshot->qa_status,
                'error_message' => null,
            ]));

            Log::info('[WIKI_QA_REGRESSION] Snapshot classified without maintenance action', [
                'snapshot_id' => $snapshot->id,
                'baseline_snapshot_id' => $baseline?->id,
                'classification' => $evaluation['classification'],
            ]);

            return [
                'outcome' => $baseline === null
                    ? 'baseline'
                    : 'within_tolerance',
                'record' => $record,
                'repaired' => false,
                'repair_result' => null,
            ];
        }

        $summaryMessage = $this->buildSummaryMessage($snapshot, $baseline, $evaluation);
        $repairAction = $this->resolveMaintenanceAction($run, $evaluation);

        $record = EnterpriseWikiQaRegression::create(array_merge($baseRecordData, [
            'enterprise_wiki_qa_snapshot_id' => $snapshot->id,
            'analysis_status' => EnterpriseWikiQaRegression::ANALYSIS_STATUS_REPAIRING,
            'analysis_started_at' => now(),
            'repair_attempted_at' => null,
            'final_status' => null,
            'error_message' => $summaryMessage,
            'maintenance_action' => $repairAction['maintenance_action'],
        ]));

        if ($repairAction['maintenance_action'] === EnterpriseWikiQaRegression::MAINTENANCE_ACTION_ESCALATE) {
            $finalStatus = $this->escalateRunIfNeeded($run, $summaryMessage, $snapshot->qa_status);

            $record->update([
                'analysis_status' => EnterpriseWikiQaRegression::ANALYSIS_STATUS_COMPLETED,
                'analysis_completed_at' => now(),
                'final_status' => $finalStatus,
                'repair_result' => null,
                'error_message' => $summaryMessage,
            ]);

            Log::warning('[WIKI_QA_REGRESSION] Regression escalated without repair', [
                'snapshot_id' => $snapshot->id,
                'baseline_snapshot_id' => $baseline?->id,
                'final_status' => $finalStatus,
                'summary' => $summaryMessage,
            ]);

            return [
                'outcome' => 'escalated',
                'record' => $record->fresh(),
                'repaired' => false,
                'repair_result' => null,
            ];
        }

        $repairResult = null;

        try {
            $record->update(['repair_attempted_at' => now()]);

            $repairResult = $repairAction['maintenance_action'] === EnterpriseWikiQaRegression::MAINTENANCE_ACTION_DEEP_REPAIR
                ? $this->attemptDeepRepair($run, $summaryMessage)
                : $this->attemptSemanticRepair($run, $summaryMessage);

            $repairSucceeded = (bool) ($repairResult['success'] ?? false);

            if ($repairSucceeded) {
                $finalStatus = $repairResult['qa_status'] ?? $run->fresh()->qa_status ?? EnterpriseWikiIngestRun::QA_STATUS_FAILED;
                $outcome = 'repaired';
            } else {
                $finalStatus = $snapshot->qa_status === EnterpriseWikiIngestRun::QA_STATUS_PASSED
                    ? EnterpriseWikiIngestRun::QA_STATUS_ESCALATED
                    : ($repairResult['qa_status'] ?? $snapshot->qa_status);

                $outcome = $finalStatus === EnterpriseWikiIngestRun::QA_STATUS_FAILED
                    ? 'failed'
                    : 'escalated';
            }

            $record->update([
                'analysis_status' => EnterpriseWikiQaRegression::ANALYSIS_STATUS_COMPLETED,
                'analysis_completed_at' => now(),
                'final_status' => $finalStatus,
                'repair_result' => $repairResult,
                'error_message' => $repairResult['error_message'] ?? null,
            ]);

            Log::info('[WIKI_QA_REGRESSION] Regression maintenance completed', [
                'snapshot_id' => $snapshot->id,
                'baseline_snapshot_id' => $baseline?->id,
                'maintenance_action' => $repairAction['maintenance_action'],
                'final_status' => $finalStatus,
                'repaired' => $repairSucceeded,
            ]);

            return [
                'outcome' => $outcome,
                'record' => $record->fresh(),
                'repaired' => $repairSucceeded,
                'repair_result' => $repairResult,
            ];
        } catch (\Throwable $e) {
            $run->update([
                'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_FAILED,
                'qa_completed_at' => now(),
                'qa_last_error' => '[REGRESSION] ' . $e->getMessage(),
            ]);

            $record->update([
                'analysis_status' => EnterpriseWikiQaRegression::ANALYSIS_STATUS_FAILED,
                'analysis_completed_at' => now(),
                'final_status' => EnterpriseWikiIngestRun::QA_STATUS_FAILED,
                'repair_result' => $repairResult,
                'error_message' => $e->getMessage(),
            ]);

            Log::error('[WIKI_QA_REGRESSION] Regression maintenance failed', [
                'snapshot_id' => $snapshot->id,
                'baseline_snapshot_id' => $baseline?->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'outcome' => 'failed',
                'record' => $record->fresh(),
                'repaired' => $repairResult !== null,
                'repair_result' => $repairResult,
            ];
        }
    }

    private function resolveMaintenanceAction(
        EnterpriseWikiIngestRun $run,
        array $evaluation,
    ): array {
        $action = $evaluation['maintenance_action'];
        $sourceHash = $run->source_hash;

        if ($sourceHash === null) {
            return [
                'maintenance_action' => EnterpriseWikiQaRegression::MAINTENANCE_ACTION_ESCALATE,
                'reason' => 'source_hash_missing',
            ];
        }

        if ($action === EnterpriseWikiQaRegression::MAINTENANCE_ACTION_NONE) {
            return [
                'maintenance_action' => EnterpriseWikiQaRegression::MAINTENANCE_ACTION_ESCALATE,
                'reason' => 'no_repair_action_identified',
            ];
        }

        if ($this->repairAlreadyAttemptedForSignal($run, $sourceHash)) {
            return [
                'maintenance_action' => EnterpriseWikiQaRegression::MAINTENANCE_ACTION_ESCALATE,
                'reason' => 'repair_already_attempted_for_signal',
            ];
        }

        if ($action === EnterpriseWikiQaRegression::MAINTENANCE_ACTION_SEMANTIC_REPAIR) {
            $diagnosis = $this->semanticDiagnosis($run);

            if ($diagnosis === null) {
                return [
                    'maintenance_action' => EnterpriseWikiQaRegression::MAINTENANCE_ACTION_ESCALATE,
                    'reason' => 'semantic_diagnosis_missing',
                ];
            }

            return [
                'maintenance_action' => EnterpriseWikiQaRegression::MAINTENANCE_ACTION_SEMANTIC_REPAIR,
                'reason' => null,
            ];
        }

        if ($action === EnterpriseWikiQaRegression::MAINTENANCE_ACTION_DEEP_REPAIR) {
            return [
                'maintenance_action' => EnterpriseWikiQaRegression::MAINTENANCE_ACTION_DEEP_REPAIR,
                'reason' => null,
            ];
        }

        return [
            'maintenance_action' => EnterpriseWikiQaRegression::MAINTENANCE_ACTION_ESCALATE,
            'reason' => 'unsupported_maintenance_action',
        ];
    }

    private function repairAlreadyAttemptedForSignal(
        EnterpriseWikiIngestRun $run,
        string $sourceHash,
    ): bool {
        return EnterpriseWikiQaRegression::query()
            ->where('customer_id', $run->customer_id)
            ->where('source_type', $run->source_type)
            ->where('source_id', $run->source_id)
            ->where('source_hash', $sourceHash)
            ->where('page_type_signature', $this->pageTypeInfoForRunId($run->id)['signature'])
            ->where('maintenance_action', '!=', EnterpriseWikiQaRegression::MAINTENANCE_ACTION_NONE)
            ->exists();
    }

    private function attemptSemanticRepair(
        EnterpriseWikiIngestRun $run,
        string $summaryMessage,
    ): array {
        $this->escalateRunIfNeeded($run, $summaryMessage, $run->qa_status ?? EnterpriseWikiIngestRun::QA_STATUS_PASSED);

        $diagnosis = $this->semanticDiagnosis($run);

        if ($diagnosis === null) {
            return [
                'service' => 'semantic_repair',
                'attempted' => false,
                'success' => false,
                'reason' => 'semantic_diagnosis_missing',
                'qa_status' => $run->fresh()->qa_status ?? EnterpriseWikiIngestRun::QA_STATUS_FAILED,
                'error_message' => 'Semantic diagnosis missing for repair.',
            ];
        }

        $diagnosis['recommended_repair_action'] = 'targeted_revision';
        $diagnosis['critique'] = trim((string) ($diagnosis['critique'] ?? '')) !== ''
            ? (string) $diagnosis['critique']
            : $summaryMessage;
        $diagnosis['regression_summary'] = $summaryMessage;

        try {
            $repairResult = $this->semanticRepairService->repair($run->fresh(), $diagnosis);
        } catch (\Throwable $e) {
            return [
                'service' => 'semantic_repair',
                'attempted' => true,
                'success' => false,
                'reason' => 'semantic_repair_failed',
                'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ];
        }

        if (! ($repairResult['success'] ?? false)) {
            return [
                'service' => 'semantic_repair',
                'attempted' => true,
                'success' => false,
                'reason' => $repairResult['reason'] ?? 'semantic_repair_not_successful',
                'repair_result' => $repairResult,
                'qa_status' => $run->fresh()->qa_status ?? EnterpriseWikiIngestRun::QA_STATUS_ESCALATED,
                'error_message' => $repairResult['reason'] ?? 'Semantic repair not successful.',
            ];
        }

        try {
            $qaResult = $this->qaService->runForRun($run->fresh(), retry: true);
        } catch (\Throwable $e) {
            return [
                'service' => 'semantic_repair',
                'attempted' => true,
                'success' => true,
                'reason' => 'post_repair_qa_failed',
                'repair_result' => $repairResult,
                'qa_status' => $run->fresh()->qa_status ?? EnterpriseWikiIngestRun::QA_STATUS_FAILED,
                'qa_result' => null,
                'error_message' => $e->getMessage(),
            ];
        }

        $run->refresh();

        return [
            'service' => 'semantic_repair',
            'attempted' => true,
            'success' => true,
            'reason' => null,
            'repair_result' => $repairResult,
            'qa_status' => $run->qa_status,
            'qa_result' => $qaResult,
            'error_message' => null,
        ];
    }

    private function attemptDeepRepair(EnterpriseWikiIngestRun $run, string $summaryMessage): array
    {
        $this->escalateRunIfNeeded($run, $summaryMessage, $run->qa_status ?? EnterpriseWikiIngestRun::QA_STATUS_ESCALATED);

        $sourceHash = $run->source_hash;

        if ($sourceHash === null) {
            return [
                'service' => 'deep_repair',
                'attempted' => false,
                'success' => false,
                'reason' => 'source_hash_missing',
                'qa_status' => $run->fresh()->qa_status ?? EnterpriseWikiIngestRun::QA_STATUS_FAILED,
                'error_message' => 'Source hash missing for deep repair.',
            ];
        }

        $repairResult = $this->deepRepairService->attempt($run->fresh(), $sourceHash);

        return [
            'service' => 'deep_repair',
            'attempted' => (bool) ($repairResult['attempted'] ?? false),
            'success' => ($repairResult['qa_status'] ?? null) === EnterpriseWikiIngestRun::QA_STATUS_PASSED,
            'reason' => $repairResult['reason'] ?? null,
            'repair_result' => $repairResult,
            'qa_status' => $repairResult['qa_status'] ?? $run->fresh()->qa_status ?? EnterpriseWikiIngestRun::QA_STATUS_FAILED,
            'error_message' => $repairResult['reason'] ?? null,
        ];
    }

    private function semanticDiagnosis(EnterpriseWikiIngestRun $run): ?array
    {
        $result = $run->qa_result['semantic_qa'] ?? null;

        if (! is_array($result)) {
            return null;
        }

        if (empty($result['page_version_id'])) {
            return null;
        }

        if (! empty($result['skipped']) || ! empty($result['escalated'])) {
            return null;
        }

        return $result;
    }

    private function escalateRunIfNeeded(EnterpriseWikiIngestRun $run, string $summaryMessage, string $currentStatus): string
    {
        if ($currentStatus === EnterpriseWikiIngestRun::QA_STATUS_PASSED) {
            $run->update([
                'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_ESCALATED,
                'qa_last_error' => '[REGRESSION] ' . $summaryMessage,
            ]);

            return EnterpriseWikiIngestRun::QA_STATUS_ESCALATED;
        }

        return $currentStatus;
    }

    /**
     * @return array{signature: string, types: array<int, string>}
     */
    private function pageTypeInfoForRunId(int $runId): array
    {
        if (isset($this->pageTypeCache[$runId])) {
            return $this->pageTypeCache[$runId];
        }

        $types = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $runId)
            ->with('page')
            ->get()
            ->map(fn (EnterpriseWikiIngestRunPage $row): ?string => $row->page?->page_type)
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $this->pageTypeCache[$runId] = [
            'signature' => $this->pageTypeSignature($types),
            'types' => $types,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function pageTypesForRun(int $runId): array
    {
        return $this->pageTypeInfoForRunId($runId)['types'];
    }

    /**
     * @param  array<int, string>  $pageTypes
     */
    private function pageTypeSignature(array $pageTypes): string
    {
        if ($pageTypes === []) {
            return 'none';
        }

        return implode('|', $pageTypes);
    }

    private function buildSummaryMessage(
        EnterpriseWikiQaSnapshot $current,
        ?EnterpriseWikiQaSnapshot $baseline,
        array $evaluation,
    ): string {
        $parts = [
            "snapshot={$current->id}",
            "classification={$evaluation['classification']}",
            "action={$evaluation['maintenance_action']}",
        ];

        if ($baseline !== null) {
            $parts[] = "baseline={$baseline->id}";
        }

        if (! empty($evaluation['signals'])) {
            $parts[] = 'signals=' . collect($evaluation['signals'])->pluck('metric')->implode(',');
        }

        return implode(' ', $parts);
    }

    private function findPreviousRelevantSnapshot(
        EnterpriseWikiQaSnapshot $current,
        string $currentSignature,
    ): ?EnterpriseWikiQaSnapshot {
        $currentRun = $current->ingestRun;

        if ($currentRun === null) {
            return null;
        }

        $candidates = EnterpriseWikiQaSnapshot::query()
            ->with('ingestRun')
            ->where('id', '!=', $current->id)
            ->whereHas('ingestRun', function ($query) use ($currentRun): void {
                $query->where('customer_id', $currentRun->customer_id)
                    ->where('source_type', $currentRun->source_type)
                    ->where('source_id', $currentRun->source_id);
            })
            ->where(function ($query) use ($current): void {
                $query->where('snapshotted_at', '<', $current->snapshotted_at)
                    ->orWhere(function ($query) use ($current): void {
                        $query->where('snapshotted_at', '=', $current->snapshotted_at)
                            ->where('id', '<', $current->id);
                    });
            })
            ->orderByDesc('snapshotted_at')
            ->orderByDesc('id')
            ->get();

        foreach ($candidates as $candidate) {
            $candidateRun = $candidate->ingestRun;

            if ($candidateRun === null) {
                continue;
            }

            $candidateSignature = $this->pageTypeInfoForRunId($candidateRun->id)['signature'];

            if ($candidateSignature === $currentSignature) {
                return $candidate;
            }
        }

        return null;
    }

    private function outcomeForRecord(EnterpriseWikiQaRegression $record): string
    {
        if ($record->baseline_enterprise_wiki_qa_snapshot_id === null) {
            return 'baseline';
        }

        if ($record->final_status === EnterpriseWikiIngestRun::QA_STATUS_PASSED) {
            return $record->maintenance_action === EnterpriseWikiQaRegression::MAINTENANCE_ACTION_NONE
                ? 'within_tolerance'
                : 'repaired';
        }

        if ($record->final_status === EnterpriseWikiIngestRun::QA_STATUS_ESCALATED) {
            return 'escalated';
        }

        if ($record->final_status === EnterpriseWikiIngestRun::QA_STATUS_FAILED) {
            return 'failed';
        }

        return 'within_tolerance';
    }
}
