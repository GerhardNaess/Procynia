<?php

namespace App\Jobs\Ai\Requirements;

use App\Models\RequirementExtractionCall;
use App\Models\RequirementExtractionRun;
use App\Models\SavedNoticeAiDocument;
use App\Services\Ai\Requirements\RequirementExtractionRunService;
use App\Support\Ai\RunsInAiCallContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Purpose: Finalize one requirement extraction run after all chunk jobs have reached a terminal state.
 * Inputs: The extraction run id.
 * Returns: None.
 * Side effects: Marks the run and document as failed or completed, and promotes staged requirements when the run is successful.
 */
class FinalizeRequirementExtractionRun implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use RunsInAiCallContext;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $runId)
    {
        $this->queue = 'ai-requirements';
    }

    public function handle(RequirementExtractionRunService $service): void
    {
        $this->withinAiCallContext(
            $this->requirementExtractionRunAiCallContext($this->runId, 'saved_notice.requirement_extraction.finalize'),
            function () use ($service): void {
                $this->handleInAiCallContext($service);
            },
        );
    }

    private function handleInAiCallContext(RequirementExtractionRunService $service): void
    {
        DB::transaction(function () use ($service): void {
            $run = RequirementExtractionRun::query()
                ->lockForUpdate()
                ->find($this->runId);

            if (! $run instanceof RequirementExtractionRun || $run->isTerminal()) {
                return;
            }

            $document = SavedNoticeAiDocument::query()
                ->lockForUpdate()
                ->find($run->saved_notice_ai_document_id);

            if (! $document instanceof SavedNoticeAiDocument) {
                return;
            }

            $callsQuery = RequirementExtractionCall::query()
                ->where('requirement_extraction_run_id', $run->id);

            $activeCallCount = (clone $callsQuery)
                ->whereIn('status', [
                    RequirementExtractionCall::STATUS_QUEUED,
                    RequirementExtractionCall::STATUS_RUNNING,
                ])
                ->count();

            if ($activeCallCount > 0) {
                return;
            }

            $failedCall = (clone $callsQuery)
                ->where('status', RequirementExtractionCall::STATUS_FAILED)
                ->orderBy('id')
                ->first();

            if ($failedCall instanceof RequirementExtractionCall) {
                $service->markRunFailed(
                    $run,
                    $document,
                    'chunk_extraction',
                    (string) ($failedCall->error_type ?? 'chunk_failed'),
                    (string) ($failedCall->error_message ?? 'Requirement extraction chunk failed.'),
                    [
                        'candidate_count' => (int) $run->candidate_count,
                        'persisted_requirement_count' => (int) $run->persisted_requirement_count,
                        'openai_call_count' => (int) $run->openai_call_count,
                        'input_tokens_total' => (int) $run->input_tokens_total,
                        'output_tokens_total' => (int) $run->output_tokens_total,
                        'total_tokens_total' => (int) $run->total_tokens_total,
                    ],
                );

                return;
            }

            $totalCallCount = (clone $callsQuery)->count();

            if ($totalCallCount === 0) {
                return;
            }

            $service->deduplicateStagedRequirementsForRun($run);
            $service->markRunMerging($run, $document);
            $service->promoteRun($run, $document);
        });
    }
}
