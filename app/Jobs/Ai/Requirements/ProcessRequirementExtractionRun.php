<?php

namespace App\Jobs\Ai\Requirements;

use App\Models\RequirementExtractionRun;
use App\Models\SavedNoticeAiDocument;
use App\Services\Ai\Requirements\RequirementExtractionRunService;
use App\Support\Ai\RunsInAiCallContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessRequirementExtractionRun implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use RunsInAiCallContext;
    use SerializesModels;

    public int $tries = 1;

    /**
     * This job now only orchestrates chunk fan-out for large documents, but the higher execution
     * budget remains temporarily while the split workflow is still being completed.
     */
    public int $timeout = 1800;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $runId)
    {
        $this->queue = 'ai-requirements';
    }

    public function handle(RequirementExtractionRunService $service): void
    {
        $this->withinAiCallContext(
            $this->requirementExtractionRunAiCallContext($this->runId, 'saved_notice.requirement_extraction.run'),
            function () use ($service): void {
                $this->handleInAiCallContext($service);
            },
        );
    }

    private function handleInAiCallContext(RequirementExtractionRunService $service): void
    {
        $run = RequirementExtractionRun::query()->find($this->runId);

        if (! $run instanceof RequirementExtractionRun) {
            return;
        }

        try {
            $service->orchestrateRunChunks($run);
        } catch (Throwable $throwable) {
            $this->markFailureFromThrowable($throwable, $service);

            throw $throwable;
        }
    }

    public function failed(Throwable $exception): void
    {
        $this->markFailureFromThrowable($exception, app(RequirementExtractionRunService::class));
    }

    /**
     * Purpose: Mark the underlying extraction run as failed when the queue job crashes or times out.
     * Inputs: The throwable that caused the job failure and the extraction run service.
     * Returns: None.
     * Side effects: Marks the run/document as failed when the run is still active and logs the outcome.
     */
    private function markFailureFromThrowable(Throwable $throwable, RequirementExtractionRunService $service): void
    {
        $run = RequirementExtractionRun::query()->find($this->runId);

        if (! $run instanceof RequirementExtractionRun || $run->isTerminal()) {
            return;
        }

        $document = $run->document()->first();
        $isTimeout = $throwable instanceof TimeoutExceededException;
        $failureStage = $isTimeout ? 'worker_timeout' : 'unexpected';
        $errorType = $isTimeout ? 'timeout' : 'unknown_error';
        $errorMessage = $this->failureMessageForThrowable($throwable);

        if ($document instanceof SavedNoticeAiDocument) {
            $service->markRunFailed(
                $run,
                $document,
                $failureStage,
                $errorType,
                $errorMessage,
            );
        } else {
            $finishedAt = now();

            $run->forceFill([
                'status' => RequirementExtractionRun::STATUS_FAILED,
                'failure_stage' => $failureStage,
                'error_type' => $errorType,
                'error_message' => $errorMessage,
                'finished_at' => $finishedAt,
                'last_heartbeat_at' => $finishedAt,
            ])->save();
        }

        Log::error('[Procynia][AI Requirements] Async requirement extraction job failed.', [
            'run_id' => $run->uuid,
            'run_db_id' => $run->id,
            'document_id' => $document instanceof SavedNoticeAiDocument ? $document->id : null,
            'saved_notice_ai_document_id' => $document instanceof SavedNoticeAiDocument ? $document->id : null,
            'saved_notice_id' => $document instanceof SavedNoticeAiDocument ? $document->saved_notice_id : null,
            'failure_stage' => $failureStage,
            'failure_type' => $errorType,
            'message' => $errorMessage,
            'exception_class' => $throwable::class,
        ]);
    }

    /**
     * Purpose: Build a stable, user-readable error message for a job failure.
     * Inputs: The throwable that caused the failure.
     * Returns: A short readable message for persistence and logs.
     * Side effects: None.
     */
    private function failureMessageForThrowable(Throwable $throwable): string
    {
        if ($throwable instanceof TimeoutExceededException) {
            return 'Requirement extraction job timed out while waiting for the OpenAI request to complete.';
        }

        $message = trim($throwable->getMessage());

        return $message !== ''
            ? $message
            : 'Requirement extraction job failed unexpectedly.';
    }
}
