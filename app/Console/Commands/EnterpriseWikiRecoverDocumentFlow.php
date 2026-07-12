<?php

namespace App\Console\Commands;

use App\Jobs\EnterpriseWiki\ContinueEnterpriseWikiDocumentFlowAfterPages;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPageLink;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiQaSnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Narrow, single-purpose recovery for one known incident class: a run stuck at
 * status=failed with error_message="Post-ingest QA did not claim run [id]." — the run-24
 * race between ContinueEnterpriseWikiDocumentFlowAfterPages and the scheduled post-ingest QA
 * sweep, where the run's execution status was marked failed but its semantic QA result had
 * already legitimately passed (with a recorded snapshot) before the exception occurred.
 *
 * This is deliberately NOT a general-purpose "set run status" command — every guard below
 * must pass, using the QA snapshot as the source of truth, or the command refuses outright.
 */
#[\Illuminate\Console\Attribute\AsCommand(name: 'wiki:recover-document-flow')]
class EnterpriseWikiRecoverDocumentFlow extends Command
{
    protected $signature = 'wiki:recover-document-flow
                            {--run-id= : The ingest run to recover}
                            {--dry-run : Report whether every recovery guard passes without changing anything}';

    protected $description = 'Recover an Enterprise Wiki ingest run stuck by the known post-ingest QA claim race (run 24 class of incident) — refuses unless every guard confirms it is safe.';

    private const KNOWN_ERROR_PATTERN = '/Post-ingest QA did not claim run \[\d+\]\.?/';

    public function handle(): int
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

        if ($run->status === EnterpriseWikiIngestRun::STATUS_COMPLETED) {
            $this->error("[WIKI_RECOVERY] Run [{$runId}] is already completed — nothing to recover.");

            return self::FAILURE;
        }

        if ($run->status !== EnterpriseWikiIngestRun::STATUS_FAILED) {
            $this->error("[WIKI_RECOVERY] Run [{$runId}] has status [{$run->status}], expected [failed] — refusing recovery.");

            return self::FAILURE;
        }

        if (! preg_match(self::KNOWN_ERROR_PATTERN, (string) $run->error_message)) {
            $this->error("[WIKI_RECOVERY] Run [{$runId}] error_message does not match the known post-ingest QA claim race — refusing recovery.");
            $this->line("[WIKI_RECOVERY] error_message: {$run->error_message}");

            return self::FAILURE;
        }

        $snapshot = EnterpriseWikiQaSnapshot::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->where('qa_attempt_count', $run->qa_attempt_count)
            ->first();

        if ($snapshot === null) {
            $this->error("[WIKI_RECOVERY] No QA snapshot found for run [{$runId}] attempt [{$run->qa_attempt_count}] — refusing recovery.");

            return self::FAILURE;
        }

        if ($snapshot->qa_status !== EnterpriseWikiIngestRun::QA_STATUS_PASSED) {
            $this->error("[WIKI_RECOVERY] Snapshot for run [{$runId}] has qa_status [{$snapshot->qa_status}], expected [passed] — refusing recovery.");

            return self::FAILURE;
        }

        if ((int) $snapshot->customer_id !== (int) $run->customer_id) {
            $this->error("[WIKI_RECOVERY] Snapshot customer_id [{$snapshot->customer_id}] does not match run customer_id [{$run->customer_id}] — refusing recovery.");

            return self::FAILURE;
        }

        $pivotPageIds = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->pluck('enterprise_wiki_page_id');

        if ($pivotPageIds->isEmpty()) {
            $this->error("[WIKI_RECOVERY] Run [{$runId}] has no applied pages — refusing recovery.");

            return self::FAILURE;
        }

        $hasCurrentVersions = EnterpriseWikiPageVersion::query()
            ->whereIn('enterprise_wiki_page_id', $pivotPageIds)
            ->where('is_current', true)
            ->exists();

        if (! $hasCurrentVersions) {
            $this->error("[WIKI_RECOVERY] Run [{$runId}] pages have no current page version — refusing recovery.");

            return self::FAILURE;
        }

        $hasLinks = EnterpriseWikiPageLink::query()
            ->where('customer_id', $run->customer_id)
            ->where(function ($q) use ($pivotPageIds): void {
                $q->whereIn('from_page_id', $pivotPageIds)->orWhereIn('to_page_id', $pivotPageIds);
            })
            ->exists();

        if (! $hasLinks) {
            $this->error("[WIKI_RECOVERY] Run [{$runId}] has no canonical page links — refusing recovery.");

            return self::FAILURE;
        }

        $hasClaims = EnterpriseWikiClaim::query()
            ->whereIn('enterprise_wiki_page_id', $pivotPageIds)
            ->exists();

        if (! $hasClaims) {
            $this->error("[WIKI_RECOVERY] Run [{$runId}] has no extracted claims — refusing recovery.");

            return self::FAILURE;
        }

        $this->info("[WIKI_RECOVERY] All guards passed for run [{$runId}].");
        $this->line("[WIKI_RECOVERY]   snapshot_id={$snapshot->id} qa_attempt_count={$snapshot->qa_attempt_count} qa_status={$snapshot->qa_status}");
        $this->line('[WIKI_RECOVERY]   pages='.$pivotPageIds->count());

        if ($dryRun) {
            $this->info('[WIKI_RECOVERY] --dry-run: no changes made.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($run, $snapshot): void {
            $run->update([
                'status' => EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING,
                'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_PASSED,
                'qa_attempt_count' => $snapshot->qa_attempt_count,
                'finished_at' => null,
                'error_message' => null,
            ]);
        });

        Log::info('[WIKI_RECOVERY] Run restored from known post-ingest QA claim race.', [
            'run_id' => $run->id,
            'customer_id' => $run->customer_id,
            'snapshot_id' => $snapshot->id,
            'qa_attempt_count' => $snapshot->qa_attempt_count,
        ]);

        ContinueEnterpriseWikiDocumentFlowAfterPages::dispatch($run->id);

        $this->info("[WIKI_RECOVERY] Run [{$runId}] restored to verification_linking/qa_status=passed and a fresh continuation job dispatched.");

        return self::SUCCESS;
    }
}
