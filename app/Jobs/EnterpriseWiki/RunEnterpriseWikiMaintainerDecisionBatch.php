<?php

namespace App\Jobs\EnterpriseWiki;

use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionBatchEvaluator;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionBatchStateService;
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

    public const QUEUE = 'enterprise-wiki-maintainer-batches';

    public int $tries = 1;

    public int $backoff = 60;

    public function __construct(public readonly int $runId, public readonly int $batchNumber)
    {
        $this->onQueue(self::QUEUE);
    }

    public function handle(EnterpriseWikiMaintainerDecisionBatchStateService $state, EnterpriseWikiMaintainerDecisionBatchEvaluator $evaluator): void
    {
        $reservation = $state->reserve($this->runId, $this->batchNumber);
        if ($reservation === null) {
            return;
        }

        try {
            $result = $evaluator->evaluate($this->runId, $reservation['batch']);
            if (! $state->complete($this->runId, $this->batchNumber, $reservation['token'], $result)) {
                throw new RuntimeException("Maintainer candidate batch [{$this->batchNumber}] lost its lease before completion.");
            }
        } catch (Throwable $exception) {
            $message = "Maintainer candidate batch [{$this->batchNumber}] failed: {$exception->getMessage()}";
            $state->fail($this->runId, $this->batchNumber, $reservation['token'], $message);
            throw new RuntimeException($message, 0, $exception);
        }
    }
}
