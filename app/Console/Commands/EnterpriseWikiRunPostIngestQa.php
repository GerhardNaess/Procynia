<?php

namespace App\Console\Commands;

use App\Models\EnterpriseWikiIngestRun;
use App\Services\EnterpriseWiki\EnterpriseWikiPostIngestQaService;
use Illuminate\Console\Command;

#[\Illuminate\Console\Attribute\AsCommand(name: 'wiki:run-post-ingest-qa')]
class EnterpriseWikiRunPostIngestQa extends Command
{
    protected $signature = 'wiki:run-post-ingest-qa
                            {--run-id= : Run a specific applied run}
                            {--all-pending : Process all applied runs with null or pending QA status}
                            {--retry : Also process failed and escalated runs (requires --run-id or --all-pending)}';

    protected $description = 'Run post-ingest QA for applied Enterprise Wiki ingest runs.';

    public function handle(EnterpriseWikiPostIngestQaService $qaService): int
    {
        $runId     = $this->option('run-id');
        $allPending = $this->option('all-pending');

        if (! $runId && ! $allPending) {
            $this->error('[WIKI_QA] Provide --run-id=<id> or --all-pending.');

            return self::FAILURE;
        }

        if ($runId) {
            return $this->processOne((int) $runId, $qaService, (bool) $this->option('retry'));
        }

        return $this->processAllPending($qaService, (bool) $this->option('retry'));
    }

    private function processOne(int $runId, EnterpriseWikiPostIngestQaService $qaService, bool $retry): int
    {
        $run = EnterpriseWikiIngestRun::find($runId);

        if ($run === null) {
            $this->error("[WIKI_QA] Run [{$runId}] not found.");

            return self::FAILURE;
        }

        try {
            $result = $qaService->runForRun($run, retry: $retry);
        } catch (\InvalidArgumentException $e) {
            $this->error("[WIKI_QA] {$e->getMessage()}");

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error("[WIKI_QA] Run [{$runId}] failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($result === null) {
            $run->refresh();
            $this->line("[WIKI_QA] Run [{$runId}] skipped — already {$run->qa_status}.");

            return self::SUCCESS;
        }

        $this->printResult($runId, $result);

        return self::SUCCESS;
    }

    private function processAllPending(EnterpriseWikiPostIngestQaService $qaService, bool $retry): int
    {
        $runs = $retry
            ? $qaService->findRetryableRuns()
            : $qaService->findPendingRuns();

        if ($runs->isEmpty()) {
            $this->line('[WIKI_QA] No pending runs found.');

            return self::SUCCESS;
        }

        $this->line("[WIKI_QA] Found {$runs->count()} run(s) to process.");

        $processed = 0;
        $skipped   = 0;
        $failed    = 0;

        foreach ($runs as $run) {
            try {
                $result = $qaService->runForRun($run, retry: $retry);

                if ($result === null) {
                    $skipped++;
                    $this->line("[WIKI_QA] Run [{$run->id}] skipped.");
                } else {
                    $processed++;
                    $run->refresh();
                    $this->line("[WIKI_QA] Run [{$run->id}] → {$run->qa_status}.");
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->warn("[WIKI_QA] Run [{$run->id}] error: {$e->getMessage()}");
            }
        }

        $this->line("[WIKI_QA] Done. Processed: {$processed}, Skipped: {$skipped}, Failed: {$failed}.");

        return self::SUCCESS;
    }

    private function printResult(int $runId, array $result): void
    {
        $checks = $result['checks'] ?? [];

        $this->line("[WIKI_QA] Run [{$runId}] QA complete.");
        $this->line('[WIKI_QA] Article exists:       ' . ($checks['article_exists']      ? 'yes' : 'no'));
        $this->line('[WIKI_QA] Summary exists:       ' . ($checks['summary_exists']      ? 'yes' : 'no'));
        $this->line('[WIKI_QA] Article has content:  ' . ($checks['article_has_content'] ? 'yes' : 'no'));
        $this->line('[WIKI_QA] Summary has content:  ' . ($checks['summary_has_content'] ? 'yes' : 'no'));

        if ($result['repair_attempted'] ?? false) {
            $repaired = ($result['repair_result']['success'] ?? false) ? 'success' : 'failed';
            $this->line("[WIKI_QA] Repair attempted: {$repaired}.");
        }

        $cs = $result['coverage_summary'] ?? [];
        if (isset($cs['gap_count'])) {
            $this->line("[WIKI_QA] Coverage gap count:    {$cs['gap_count']}.");
            $this->line("[WIKI_QA] Open lint errors:      {$cs['open_errors']}.");
        }
    }
}
