<?php

namespace App\Jobs\EnterpriseWiki;

use App\Models\EnterpriseWikiIngestRun;
use App\Services\EnterpriseWiki\EnterpriseWikiPostIngestQaService;
use App\Support\Ai\RunsInAiCallContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Dispatches post-ingest QA for a single applied Enterprise Wiki run.
 *
 * The service handles idempotency and parallel-run protection.
 * A technical failure updates qa_status = 'failed' and re-throws so the queue marks the job failed.
 */
class RunPostIngestQa implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use RunsInAiCallContext;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $runId)
    {
        $this->queue = 'enterprise-wiki';
    }

    public function handle(EnterpriseWikiPostIngestQaService $qaService): void
    {
        $this->withinAiCallContext(
            $this->enterpriseWikiRunAiCallContext($this->runId, 'enterprise_wiki.post_ingest_qa'),
            function () use ($qaService): void {
                $this->handleInAiCallContext($qaService);
            },
        );
    }

    private function handleInAiCallContext(EnterpriseWikiPostIngestQaService $qaService): void
    {
        $run = EnterpriseWikiIngestRun::find($this->runId);

        if ($run === null) {
            Log::warning('[WIKI_QA] Job dispatched for non-existent run', ['run_id' => $this->runId]);

            return;
        }

        if ($run->isTerminal()) {
            return;
        }

        try {
            $qaService->runForRun($run);
        } catch (\InvalidArgumentException $e) {
            Log::warning('[WIKI_QA] Job skipped: invalid run state', [
                'run_id' => $this->runId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
