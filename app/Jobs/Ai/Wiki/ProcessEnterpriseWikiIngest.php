<?php

namespace App\Jobs\Ai\Wiki;

use App\Models\EnterpriseWikiIngestRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessEnterpriseWikiIngest implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $runId)
    {
        $this->queue = 'enterprise-wiki';
    }

    public function handle(): void
    {
        $run = EnterpriseWikiIngestRun::query()->find($this->runId);

        if (! $run instanceof EnterpriseWikiIngestRun || $run->isTerminal()) {
            return;
        }

        // Phase 1D (AI section extraction) is not yet implemented.
        // The run is left in STATUS_QUEUED so it can be re-dispatched once
        // the next phase is ready without being confused with a completed run.
        Log::info(sprintf(
            '[WIKI_INGEST][PHASE_1D_PENDING] Ingest run queued but AI processing is not yet implemented. run_id=%d',
            $this->runId,
        ));
    }
}
