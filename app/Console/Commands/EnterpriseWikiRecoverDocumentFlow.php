<?php

namespace App\Console\Commands;

use App\Jobs\EnterpriseWiki\ContinueEnterpriseWikiDocumentFlowAfterPages;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPageLink;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiQaSnapshot;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentFlowService;
use Illuminate\Console\Attribute\AsCommand;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Recovers a run stuck at status=failed by computing a deterministic resume plan from
 * existing checkpoints and artifacts, rather than always restarting the pipeline from
 * verification_linking.
 *
 * Run execution status (`status`) and semantic QA result (`qa_status`) are two distinct,
 * orthogonal states — a technical exception anywhere in continueAfterPagesGenerated() sets
 * status=failed, but qa_status may already hold a legitimate terminal verdict (passed,
 * escalated, or failed) recorded by a QA attempt that completed before the exception. This
 * command tells those two cases apart and picks exactly one of two safe outcomes:
 *
 *   - direct_finalize: qa_status is already terminal and its snapshot + required artifacts
 *     validate — finalize straight from that recorded result (no stage re-execution, no AI
 *     calls, no dispatch).
 *   - resume_continuation: qa_status never reached a terminal state (the failure happened
 *     during or before QA) — restore the run to verification_linking and dispatch a fresh
 *     continuation job. Every stage in continueAfterPagesGenerated() is independently safe to
 *     re-run against work it already finished (see the per-stage service class docs), so
 *     resuming from the top never repeats completed work or duplicates artifacts.
 *
 * This is deliberately NOT a general-purpose "set run status" command — every guard below
 * must pass, using QA snapshots and existing artifacts as the source of truth, or the command
 * refuses outright.
 */
#[AsCommand(name: 'wiki:recover-document-flow')]
class EnterpriseWikiRecoverDocumentFlow extends Command
{
    protected $signature = 'wiki:recover-document-flow
                            {--run-id= : The ingest run to recover}
                            {--dry-run : Report the observed checkpoints and resume plan without changing anything}';

    protected $description = 'Recover an Enterprise Wiki ingest run stuck at status=failed by resuming or finalizing from its already-recorded checkpoints.';

    private const QA_TERMINAL_STATUSES = [
        EnterpriseWikiIngestRun::QA_STATUS_PASSED,
        EnterpriseWikiIngestRun::QA_STATUS_ESCALATED,
        EnterpriseWikiIngestRun::QA_STATUS_FAILED,
    ];

    public function handle(EnterpriseWikiDocumentFlowService $flowService): int
    {
        $runIdOption = $this->option('run-id');

        if (! $runIdOption) {
            $this->error('[WIKI_RECOVERY] Provide --run-id=<id>.');

            return self::FAILURE;
        }

        $runId = (int) $runIdOption;
        $dryRun = (bool) $this->option('dry-run');

        $run = EnterpriseWikiIngestRun::query()->find($runId);

        if ($run === null) {
            $this->error("[WIKI_RECOVERY] Run [{$runId}] not found.");

            return self::FAILURE;
        }

        if (in_array($run->status, [EnterpriseWikiIngestRun::STATUS_COMPLETED, EnterpriseWikiIngestRun::STATUS_ESCALATED], true)) {
            $this->error("[WIKI_RECOVERY] Run [{$runId}] has status [{$run->status}] — already terminal, nothing to recover.");

            return self::FAILURE;
        }

        if ($run->status !== EnterpriseWikiIngestRun::STATUS_FAILED) {
            $this->error("[WIKI_RECOVERY] Run [{$runId}] has status [{$run->status}], expected [failed] — refusing recovery.");

            return self::FAILURE;
        }

        if ($run->maintainer_decision_status !== EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED) {
            $this->error("[WIKI_RECOVERY] Run [{$runId}] has maintainer_decision_status [{$run->maintainer_decision_status}], expected [applied] — refusing recovery.");

            return self::FAILURE;
        }

        $observed = $this->observe($run);

        $this->printObserved($runId, $observed);

        $plan = $this->computePlan($run, $observed);

        $this->printPlan($plan);

        if ($plan['outcome'] === 'refused') {
            $this->error("[WIKI_RECOVERY] {$plan['reason']}");

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->info('[WIKI_RECOVERY] --dry-run: no changes made, no job dispatched.');

            return self::SUCCESS;
        }

        if ($plan['outcome'] === 'direct_finalize') {
            $this->executeDirectFinalize($run, $flowService, $observed);
        } else {
            $this->executeResumeContinuation($run, $observed);
        }

        return self::SUCCESS;
    }

    /**
     * @return array{
     *     pages_count: int,
     *     has_current_versions: bool,
     *     has_links: bool,
     *     has_claims: bool,
     *     snapshot: ?EnterpriseWikiQaSnapshot,
     *     snapshot_matches: bool,
     * }
     */
    private function observe(EnterpriseWikiIngestRun $run): array
    {
        $pivotPageIds = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->pluck('enterprise_wiki_page_id');

        $hasCurrentVersions = $pivotPageIds->isNotEmpty() && EnterpriseWikiPageVersion::query()
            ->whereIn('enterprise_wiki_page_id', $pivotPageIds)
            ->where('is_current', true)
            ->exists();

        $hasLinks = $pivotPageIds->isNotEmpty() && EnterpriseWikiPageLink::query()
            ->where('customer_id', $run->customer_id)
            ->where(function ($q) use ($pivotPageIds): void {
                $q->whereIn('from_page_id', $pivotPageIds)->orWhereIn('to_page_id', $pivotPageIds);
            })
            ->exists();

        $hasClaims = $pivotPageIds->isNotEmpty() && EnterpriseWikiClaim::query()
            ->whereIn('enterprise_wiki_page_id', $pivotPageIds)
            ->exists();

        $snapshot = EnterpriseWikiQaSnapshot::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->where('qa_attempt_count', $run->qa_attempt_count)
            ->first();

        $snapshotMatches = $snapshot !== null
            && $snapshot->qa_status === $run->qa_status
            && (int) $snapshot->customer_id === (int) $run->customer_id;

        return [
            'pages_count' => $pivotPageIds->count(),
            'has_current_versions' => $hasCurrentVersions,
            'has_links' => $hasLinks,
            'has_claims' => $hasClaims,
            'snapshot' => $snapshot,
            'snapshot_matches' => $snapshotMatches,
        ];
    }

    /**
     * @return array{outcome: string, reason?: string, target_qa_status?: string}
     */
    private function computePlan(EnterpriseWikiIngestRun $run, array $observed): array
    {
        if ($observed['pages_count'] === 0) {
            return ['outcome' => 'refused', 'reason' => "Run [{$run->id}] has no applied pages — refusing recovery."];
        }

        if (! in_array($run->qa_status, self::QA_TERMINAL_STATUSES, true)) {
            // qa_status never reached a terminal state — the failure happened during or
            // before QA. Safe to resume: every stage in continueAfterPagesGenerated() is
            // independently idempotent against work it already finished.
            return ['outcome' => 'resume_continuation'];
        }

        if (! $observed['snapshot_matches']) {
            $reason = $observed['snapshot'] === null
                ? "No QA snapshot found for run [{$run->id}] attempt [{$run->qa_attempt_count}] — refusing recovery."
                : "QA snapshot for run [{$run->id}] does not match the run's current qa_status/customer_id — refusing recovery.";

            return ['outcome' => 'refused', 'reason' => $reason];
        }

        // A passed result additionally requires the artifacts a successful pipeline run
        // produces — a snapshot alone is not proof the linking/claim stages actually
        // completed. escalated/failed results do not require this: those outcomes can be
        // legitimately reached with incomplete linking/claims, which is often exactly why
        // QA did not pass.
        if ($run->qa_status === EnterpriseWikiIngestRun::QA_STATUS_PASSED) {
            $missing = [];

            if (! $observed['has_current_versions']) {
                $missing[] = 'current page version';
            }

            if (! $observed['has_links']) {
                $missing[] = 'canonical page links';
            }

            if (! $observed['has_claims']) {
                $missing[] = 'extracted claims';
            }

            if ($missing !== []) {
                return [
                    'outcome' => 'refused',
                    'reason' => "Run [{$run->id}] qa_status is passed but required artifact(s) are missing: ".implode(', ', $missing).' — refusing recovery.',
                ];
            }
        }

        return ['outcome' => 'direct_finalize', 'target_qa_status' => $run->qa_status];
    }

    private function executeDirectFinalize(EnterpriseWikiIngestRun $run, EnterpriseWikiDocumentFlowService $flowService, array $observed): void
    {
        $flowService->finalizeFromExistingQaResult($run);

        Log::info('[WIKI_RECOVERY] Run finalized directly from existing QA result.', [
            'run_id' => $run->id,
            'customer_id' => $run->customer_id,
            'qa_status' => $run->qa_status,
            'snapshot_id' => $observed['snapshot']?->id,
            'qa_attempt_count' => $run->qa_attempt_count,
        ]);

        $this->info("[WIKI_RECOVERY] Run [{$run->id}] finalized directly from qa_status=[{$run->qa_status}] — no stages re-run, no job dispatched.");
    }

    private function executeResumeContinuation(EnterpriseWikiIngestRun $run, array $observed): void
    {
        $wasStaleQaClaim = $run->qa_status === EnterpriseWikiIngestRun::QA_STATUS_RUNNING;

        DB::transaction(function () use ($run, $wasStaleQaClaim): void {
            $updates = [
                'status' => EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING,
                'finished_at' => null,
                'error_message' => null,
            ];

            if ($wasStaleQaClaim) {
                // qa_status=running with the owning run already marked failed means the
                // worker that claimed QA died before it could reach a terminal state — that
                // claim is stale and must be released so a fresh continuation can reclaim it.
                $updates['qa_status'] = EnterpriseWikiIngestRun::QA_STATUS_PENDING;
            }

            $run->update($updates);
        });

        ContinueEnterpriseWikiDocumentFlowAfterPages::dispatch($run->id);

        Log::info('[WIKI_RECOVERY] Run restored to verification_linking and a fresh continuation job dispatched.', [
            'run_id' => $run->id,
            'customer_id' => $run->customer_id,
            'stale_qa_claim_released' => $wasStaleQaClaim,
            'pages_count' => $observed['pages_count'],
        ]);

        $this->info("[WIKI_RECOVERY] Run [{$run->id}] restored to verification_linking".($wasStaleQaClaim ? ' (stale qa_status=running released to pending)' : '').' — fresh continuation job dispatched.');
    }

    private function printObserved(int $runId, array $observed): void
    {
        $run = EnterpriseWikiIngestRun::query()->find($runId);

        $this->line("[WIKI_RECOVERY] Observed checkpoints for run [{$runId}]:");
        $this->line("[WIKI_RECOVERY]   status={$run->status} qa_status=".($run->qa_status ?? 'null')." qa_attempt_count={$run->qa_attempt_count}");
        $this->line('[WIKI_RECOVERY]   pages='.$observed['pages_count']
            .' has_current_versions='.($observed['has_current_versions'] ? 'yes' : 'no')
            .' has_links='.($observed['has_links'] ? 'yes' : 'no')
            .' has_claims='.($observed['has_claims'] ? 'yes' : 'no'));

        $snapshot = $observed['snapshot'];
        $this->line('[WIKI_RECOVERY]   snapshot='.($snapshot !== null ? "id={$snapshot->id} qa_status={$snapshot->qa_status}" : 'none')
            .' snapshot_matches='.($observed['snapshot_matches'] ? 'yes' : 'no'));
    }

    private function printPlan(array $plan): void
    {
        $this->line("[WIKI_RECOVERY] Chosen next step: {$plan['outcome']}");
        $this->line('[WIKI_RECOVERY] Direct finalize allowed: '.($plan['outcome'] === 'direct_finalize' ? 'yes' : 'no'));
    }
}
