<?php

namespace App\Jobs\EnterpriseWiki;

use App\Models\EnterpriseWikiIngestRun;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentFlowService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Resumes the Enterprise Wiki document flow once every applied page for a run has been
 * generated successfully: claim extraction, verification, linking, lint, and QA.
 *
 * Dispatched exactly once by FinalizeEnterpriseWikiPageGeneration. The atomic claim inside
 * EnterpriseWikiDocumentFlowService::continueAfterPagesGenerated() protects against a
 * duplicate dispatch re-entering this stage.
 */
class ContinueEnterpriseWikiDocumentFlowAfterPages implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Exposed as a class constant (rather than only the instance property below) so other
     * Enterprise Wiki components can derive a value from it at compile time instead of
     * duplicating the number — see EnterpriseWikiExtractPageClaimsService::LEASE_SECONDS /
     * EnterpriseWikiVerifyPageClaimsService::LEASE_SECONDS, which must always exceed this.
     */
    public const TIMEOUT_SECONDS = 1800;

    public int $tries = 1;

    public int $timeout = self::TIMEOUT_SECONDS;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $runId)
    {
        $this->queue = 'enterprise-wiki';
    }

    public function handle(EnterpriseWikiDocumentFlowService $flowService): void
    {
        $flowService->continueAfterPagesGenerated($this->runId);
    }

    /**
     * Safety net for a genuinely unexpected continuation failure — e.g. the worker process
     * itself was killed/restarted mid-flight, so EnterpriseWikiDocumentFlowService's own
     * try/catch never got to run markRunFailed(). Never overwrites an already-terminal run
     * (completed/failed/escalated), so a legitimate result recorded before the crash is
     * preserved rather than clobbered.
     */
    public function failed(Throwable $exception): void
    {
        $run = EnterpriseWikiIngestRun::query()->find($this->runId);

        if ($run === null) {
            return;
        }

        $phase = $run->status;
        $qaStatus = $run->qa_status;

        if (! $run->isTerminal()) {
            $run->update([
                'status' => EnterpriseWikiIngestRun::STATUS_FAILED,
                'error_message' => mb_substr($exception->getMessage(), 0, 1000),
                'finished_at' => now(),
            ]);
        }

        Log::error('[WIKI_DOCUMENT_FLOW] Continuation job failed.', [
            'run_id' => $this->runId,
            'status' => $phase,
            'qa_status' => $qaStatus,
            'phase' => $phase,
            'exception' => get_class($exception),
            'error' => $exception->getMessage(),
        ]);
    }
}
