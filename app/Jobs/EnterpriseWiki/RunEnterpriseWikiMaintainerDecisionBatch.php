<?php

namespace App\Jobs\EnterpriseWiki;

use App\Models\EnterpriseWikiIngestRun;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentFlowService;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionBatchEvaluator;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionBatchStateService;
use App\Support\Ai\RunsInAiCallContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

class RunEnterpriseWikiMaintainerDecisionBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use RunsInAiCallContext;

    public const QUEUE = 'enterprise-wiki-maintainer-batches';

    public int $tries = 1;

    public int $backoff = 60;

    public int $timeout = 1800;

    public function __construct(public readonly int $runId, public readonly int $batchNumber)
    {
        $this->onQueue(self::QUEUE);
    }

    public function handle(EnterpriseWikiMaintainerDecisionBatchStateService $state, EnterpriseWikiMaintainerDecisionBatchEvaluator $evaluator): void
    {
        $this->withinAiCallContext(
            $this->enterpriseWikiRunAiCallContext($this->runId, 'enterprise_wiki.maintainer_batch'),
            function () use ($state, $evaluator): void {
                $this->handleInAiCallContext($state, $evaluator);
            },
        );
    }

    private function handleInAiCallContext(EnterpriseWikiMaintainerDecisionBatchStateService $state, EnterpriseWikiMaintainerDecisionBatchEvaluator $evaluator): void
    {
        $run = EnterpriseWikiIngestRun::query()->find($this->runId);

        if ($run instanceof EnterpriseWikiIngestRun && $run->isTerminal()) {
            return;
        }

        $reservation = $state->reserve($this->runId, $this->batchNumber);
        if ($reservation === null) {
            return;
        }

        try {
            $result = $evaluator->evaluate($this->runId, $reservation['batch']);

            if (EnterpriseWikiIngestRun::query()->find($this->runId)?->isTerminal()) {
                return;
            }

            if (! $state->complete($this->runId, $this->batchNumber, $reservation['token'], $result)) {
                throw new RuntimeException("Maintainer candidate batch [{$this->batchNumber}] lost its lease before completion.");
            }
            FinalizeEnterpriseWikiMaintainerDecisionBatches::dispatch($this->runId);
        } catch (Throwable $exception) {
            if (EnterpriseWikiIngestRun::query()->find($this->runId)?->isTerminal()) {
                return;
            }

            $message = "Maintainer candidate batch [{$this->batchNumber}] failed: {$exception->getMessage()}";
            $state->fail($this->runId, $this->batchNumber, $reservation['token'], $message);
            throw new RuntimeException($message, 0, $exception);
        }
    }

    public function failed(Throwable $exception): void
    {
        if (EnterpriseWikiIngestRun::query()->find($this->runId)?->isTerminal()) {
            return;
        }

        app(EnterpriseWikiDocumentFlowService::class)->markMaintainerDecisionFailed($this->runId, $exception);
    }
}
