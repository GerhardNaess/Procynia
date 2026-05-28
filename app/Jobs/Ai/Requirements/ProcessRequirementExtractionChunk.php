<?php

namespace App\Jobs\Ai\Requirements;

use App\Models\RequirementExtractionCall;
use App\Services\Ai\Requirements\RequirementExtractionRunService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Purpose: Process one queued AI requirement extraction call for a single document chunk.
 * Inputs: One requirement extraction call id and, optionally, the parent extraction run id.
 * Returns: None.
 * Side effects: Marks the call as running/completed/failed, stages chunk-scoped requirements, and triggers run finalization.
 */
class ProcessRequirementExtractionChunk implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;
    public int $timeout = 600;
    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $callId,
        public readonly ?int $runId = null,
    ) {
        $this->queue = 'ai-requirements';
    }

    public function handle(RequirementExtractionRunService $service): void
    {
        $service->processRunCall($this->callId);
    }

    public function failed(Throwable $exception): void
    {
        $this->markFailureFromThrowable($exception);
    }

    /**
     * Purpose: Mark a chunk extraction call as failed when the queue worker crashes or times out.
     * Inputs: The throwable that caused the job failure.
     * Returns: None.
     * Side effects: Marks the call as failed and schedules run finalization for the parent run when possible.
     */
    private function markFailureFromThrowable(Throwable $throwable): void
    {
        $call = RequirementExtractionCall::query()->find($this->callId);

        if (! $call instanceof RequirementExtractionCall) {
            return;
        }

        if ($call->status === RequirementExtractionCall::STATUS_COMPLETED && $call->finished_at !== null) {
            return;
        }

        if ($call->status === RequirementExtractionCall::STATUS_FAILED && $call->finished_at !== null) {
            return;
        }

        $finishedAt = now();
        $isTimeout = $throwable instanceof TimeoutExceededException;
        $failureStage = $isTimeout ? 'worker_timeout' : 'unexpected';
        $errorType = $isTimeout ? 'timeout' : 'unknown_error';
        $errorMessage = $this->failureMessageForThrowable($throwable);

        $call->forceFill([
            'status' => RequirementExtractionCall::STATUS_FAILED,
            'error_type' => $errorType,
            'error_message' => $errorMessage,
            'finished_at' => $finishedAt,
        ])->save();

        Log::error('[Procynia][AI Requirements] Async requirement extraction chunk job failed.', [
            'call_id' => $call->id,
            'run_id' => $this->runId,
            'requirement_extraction_run_id' => $call->requirement_extraction_run_id,
            'document_id' => $call->saved_notice_ai_document_id,
            'saved_notice_ai_document_id' => $call->saved_notice_ai_document_id,
            'saved_notice_ai_document_chunk_id' => $call->saved_notice_ai_document_chunk_id,
            'saved_notice_id' => $call->saved_notice_id,
            'failure_stage' => $failureStage,
            'failure_type' => $errorType,
            'message' => $errorMessage,
            'exception_class' => $throwable::class,
        ]);

        if ($call->requirement_extraction_run_id !== null) {
            FinalizeRequirementExtractionRun::dispatch((int) $call->requirement_extraction_run_id)->onQueue($this->queue);
        }
    }

    /**
     * Purpose: Build a stable, user-readable error message for a chunk job failure.
     * Inputs: The throwable that caused the failure.
     * Returns: A short readable message for persistence and logs.
     * Side effects: None.
     */
    private function failureMessageForThrowable(Throwable $throwable): string
    {
        if ($throwable instanceof TimeoutExceededException) {
            return 'Requirement extraction chunk job timed out while waiting for the OpenAI request to complete.';
        }

        $message = trim($throwable->getMessage());

        return $message !== ''
            ? $message
            : 'Requirement extraction chunk job failed unexpectedly.';
    }
}
