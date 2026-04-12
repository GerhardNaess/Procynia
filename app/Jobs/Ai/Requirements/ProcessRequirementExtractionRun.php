<?php

namespace App\Jobs\Ai\Requirements;

use App\Models\RequirementExtractionRun;
use App\Services\Ai\Requirements\RequirementExtractionRunService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessRequirementExtractionRun implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $runId)
    {
        $this->queue = 'ai-requirements';
    }

    public function handle(RequirementExtractionRunService $service): void
    {
        $run = RequirementExtractionRun::query()->find($this->runId);

        if (! $run instanceof RequirementExtractionRun) {
            return;
        }

        try {
            $service->processRun($run);
        } catch (Throwable $throwable) {
            $run->refresh();
            $document = $run->document()->first();

            if ($document instanceof \App\Models\SavedNoticeAiDocument) {
                $service->markRunFailed(
                    $run,
                    $document,
                    'unexpected',
                    'unknown_error',
                    $throwable->getMessage(),
                );
            }

            Log::error('[PROCYNIA][REQ_PIPELINE] Async requirement extraction job crashed.', [
                'run_id' => $run->uuid,
                'run_db_id' => $run->id,
                'failure_stage' => 'unexpected',
                'failure_type' => 'unknown_error',
                'message' => $throwable->getMessage(),
            ]);

            throw $throwable;
        }
    }
}
