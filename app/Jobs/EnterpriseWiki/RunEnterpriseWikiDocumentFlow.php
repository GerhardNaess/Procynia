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
 * Runs the new Enterprise Wiki document flow on the dedicated enterprise-wiki queue.
 *
 * The orchestration service owns all business logic and terminal failure updates.
 * This job exists only to place the work on the correct queue and keep queue
 * retries/timeouts separate from the legacy section pipeline.
 */
class RunEnterpriseWikiDocumentFlow implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 1860;

    public int $backoff = 60;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $runId)
    {
        $this->queue = 'enterprise-wiki';
    }

    public function handle(EnterpriseWikiDocumentFlowService $flowService): void
    {
        $flowService->run($this->runId);
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

        Log::error('[WIKI_DOCUMENT_FLOW_JOB] Run failed.', [
            'run_id' => $this->runId,
            'error' => $exception->getMessage(),
        ]);
    }
}
