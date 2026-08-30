<?php

namespace App\Jobs\EnterpriseWiki;

use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Services\EnterpriseWiki\EnterpriseWikiGenerateAppliedPagesService;
use App\Support\Ai\RunsInAiCallContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Generates exactly one Enterprise Wiki applied page (article, summary, concept, or entity)
 * for one run/page pair, on its own queue slot and timeout.
 *
 * This is the unit of work a slow or timed-out OpenAI call can no longer take down the whole
 * document flow with: each page gets its own job, its own retry budget, and its own failure.
 * Claims, verification, linking, lint, and QA are not touched here — see
 * FinalizeEnterpriseWikiPageGeneration for what runs once every page job for a run is done.
 */
class GenerateEnterpriseWikiAppliedPage implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use RunsInAiCallContext;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 420;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $runId,
        public readonly int $pageId,
    ) {
        $this->queue = 'enterprise-wiki-pages';
    }

    public function handle(EnterpriseWikiGenerateAppliedPagesService $service): void
    {
        $this->withinAiCallContext(
            $this->enterpriseWikiRunAiCallContext($this->runId, 'enterprise_wiki.generate_page'),
            function () use ($service): void {
                $this->handleInAiCallContext($service);
            },
        );
    }

    private function handleInAiCallContext(EnterpriseWikiGenerateAppliedPagesService $service): void
    {
        $run = EnterpriseWikiIngestRun::query()->find($this->runId);
        $page = EnterpriseWikiPage::query()->find($this->pageId);

        if ($run === null || $page === null) {
            Log::warning('[WIKI_PAGE_GENERATION] Job dispatched for missing run or page.', [
                'run_id' => $this->runId,
                'page_id' => $this->pageId,
            ]);

            return;
        }

        if ($run->isTerminal()) {
            return;
        }

        try {
            $service->generatePageForRun($run, $page);
        } catch (Throwable $e) {
            if (EnterpriseWikiIngestRun::query()->find($this->runId)?->isTerminal()) {
                return;
            }

            $this->markPivotFailed($e);

            Log::error('[WIKI_PAGE_GENERATION][FAILED]', [
                'run_id' => $this->runId,
                'page_id' => $this->pageId,
                'page_title' => $page->title,
                'page_type' => $page->page_type,
                'job' => self::class,
                'ai_client' => 'WikiPageContentAiClient',
                'exception' => get_class($e),
                'error' => mb_substr($e->getMessage(), 0, 500),
            ]);

            FinalizeEnterpriseWikiPageGeneration::dispatch($this->runId);

            throw $e;
        }

        if (EnterpriseWikiIngestRun::query()->find($this->runId)?->isTerminal()) {
            return;
        }

        Log::info('[WIKI_PAGE_GENERATION][COMPLETED]', [
            'run_id' => $this->runId,
            'page_id' => $this->pageId,
            'page_type' => $page->page_type,
        ]);

        FinalizeEnterpriseWikiPageGeneration::dispatch($this->runId);
    }

    public function failed(Throwable $exception): void
    {
        if (EnterpriseWikiIngestRun::query()->find($this->runId)?->isTerminal()) {
            return;
        }

        $this->markPivotFailed($exception);

        Log::error('[WIKI_PAGE_GENERATION][JOB_FAILED]', [
            'run_id' => $this->runId,
            'page_id' => $this->pageId,
            'job' => self::class,
            'exception' => get_class($exception),
            'error' => mb_substr($exception->getMessage(), 0, 500),
        ]);

        // Safety net: guarantees the run is not left stuck in generating_pages if this job
        // crashes without reaching the catch block in handle() (e.g. a timeout).
        FinalizeEnterpriseWikiPageGeneration::dispatch($this->runId);
    }

    /**
     * Records the failure reason with its exception type prefixed (e.g.
     * "[EnterpriseWikiInvalidWikilinksException] ...") so that the aggregated run-level
     * error_message built by FinalizeEnterpriseWikiPageGeneration is understandable without a
     * stacktrace — the concrete original exception type is visible right there in the run row.
     */
    private function markPivotFailed(Throwable $exception): void
    {
        DB::transaction(function () use ($exception): void {
            $run = EnterpriseWikiIngestRun::query()->lockForUpdate()->find($this->runId);

            if (! $run instanceof EnterpriseWikiIngestRun || $run->isTerminal()) {
                return;
            }

            $pivot = EnterpriseWikiIngestRunPage::query()
                ->where('enterprise_wiki_ingest_run_id', $this->runId)
                ->where('enterprise_wiki_page_id', $this->pageId)
                ->lockForUpdate()
                ->first();

            if ($pivot === null || $pivot->isGenerationTerminal()) {
                return;
            }

            $pivot->update([
                'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_FAILED,
                'generation_dispatched_at' => null,
                'generation_claimed_at' => null,
                'generation_claim_token' => null,
                'generation_error' => mb_substr(sprintf('[%s] %s', class_basename($exception), $exception->getMessage()), 0, 1000),
            ]);
        });
    }
}
