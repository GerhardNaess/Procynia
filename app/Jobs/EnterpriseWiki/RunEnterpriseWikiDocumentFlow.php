<?php

namespace App\Jobs\EnterpriseWiki;

use App\Models\EnterpriseWikiIngestRun;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentFlowService;
use App\Support\EnterpriseWiki\EnterpriseWikiQueueTrace;
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

    public const QUEUE_NAME = 'enterprise-wiki';

    /**
     * Exposed as a class constant (not just the $timeout property below) so
     * EnterpriseWikiDocumentFlowService can compute how much of this job's own timeout budget
     * remains before making an AI call — see EnterpriseWikiAiRequestTimeoutPolicy /
     * EnterpriseWikiAiCapacityRetryExecutor (Wiki run-592) — without instantiating this job or
     * duplicating the number.
     */
    public const TIMEOUT_SECONDS = 1860;

    public int $tries = 1;

    public int $timeout = self::TIMEOUT_SECONDS;

    public int $backoff = 60;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $runId)
    {
        $this->queue = self::QUEUE_NAME;
    }

    public function handle(EnterpriseWikiDocumentFlowService $flowService): void
    {
        $payload = $this->job?->payload() ?? [];
        $delaySeconds = isset($payload['delay']) ? (int) $payload['delay'] : null;
        $createdAt = isset($payload['createdAt']) ? (int) $payload['createdAt'] : null;

        EnterpriseWikiQueueTrace::log('handle_start', [
            'run_id' => $this->runId,
            'queue_name' => $this->job?->getQueue() ?? self::QUEUE_NAME,
            'job_connection_name' => $this->job?->getConnectionName(),
            'job_id' => $this->job?->getJobId(),
            'job_uuid' => $this->job?->uuid(),
            'payload_delay_seconds' => $delaySeconds,
            'payload_created_at_epoch' => $createdAt,
            'payload_available_at_epoch' => $delaySeconds !== null && $createdAt !== null
                ? $createdAt + $delaySeconds
                : null,
        ], true, true);

        $flowService->run($this->runId);
    }

    public function failed(Throwable $exception): void
    {
        $run = EnterpriseWikiIngestRun::query()->find($this->runId);

        if ($run === null || $run->isTerminal()) {
            return;
        }

        EnterpriseWikiIngestRun::query()->whereKey($run->id)->nonTerminal()->update([
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
