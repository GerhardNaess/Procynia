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

    public int $tries = 1;

    public int $timeout = 1800;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $runId)
    {
        $this->queue = 'enterprise-wiki';
    }

    public function handle(EnterpriseWikiDocumentFlowService $flowService): void
    {
        $flowService->continueAfterPagesGenerated($this->runId);
    }

    public function failed(Throwable $exception): void
    {
        $run = EnterpriseWikiIngestRun::query()->find($this->runId);

        if ($run === null || $run->isTerminal()) {
            return;
        }

        $run->update([
            'status' => EnterpriseWikiIngestRun::STATUS_FAILED,
            'error_message' => mb_substr($exception->getMessage(), 0, 1000),
            'finished_at' => now(),
        ]);

        Log::error('[WIKI_DOCUMENT_FLOW_CONTINUATION_JOB] Run failed.', [
            'run_id' => $this->runId,
            'error' => $exception->getMessage(),
        ]);
    }
}
