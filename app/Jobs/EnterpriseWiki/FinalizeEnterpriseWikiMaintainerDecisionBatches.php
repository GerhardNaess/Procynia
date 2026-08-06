<?php

namespace App\Jobs\EnterpriseWiki;

use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentFlowService;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionBatchStateService;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionService;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionSplitCoordinator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

class FinalizeEnterpriseWikiMaintainerDecisionBatches implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const QUEUE = 'enterprise-wiki-maintainer-batches';

    public int $tries = 1;

    public int $backoff = 60;

    public function __construct(public readonly int $runId)
    {
        $this->onQueue(self::QUEUE);
    }

    public function handle(EnterpriseWikiMaintainerDecisionBatchStateService $state, EnterpriseWikiMaintainerDecisionSplitCoordinator $coordinator, EnterpriseWikiMaintainerDecisionService $decisionService, EnterpriseWikiDocumentFlowService $flow): void
    {
        $run = EnterpriseWikiIngestRun::query()->findOrFail($this->runId);
        if ($run->isTerminal() || $run->maintainer_decision_generated_at !== null) {
            return;
        }
        $summary = $state->summary($this->runId);
        if ($summary['pending'] || $summary['running'] || $summary['total'] === 0) {
            if (EnterpriseWikiIngestRun::query()->find($this->runId)?->isTerminal()) {
                return;
            }

            self::dispatch($this->runId)->delay(now()->addSeconds(30));

            return;
        }
        if ($summary['failed'] !== []) {
            $failed = $summary['failed'][0];
            throw new RuntimeException("Maintainer candidate batch [{$failed['batch_number']}] failed: {$failed['error_message']}");
        }
        $global = $state->globalPlan($this->runId);
        if (! is_array($global)) {
            throw new RuntimeException('Maintainer batch global plan is missing.');
        }
        $merged = $coordinator->mergePersistedBatchResults($global, $state->completedResults($this->runId));
        $document = EnterpriseWikiDocument::query()->where('customer_id', $run->customer_id)->findOrFail($run->source_id);
        $language = $run->customer()->with('language')->first()?->language?->code ?? 'no';

        if (EnterpriseWikiIngestRun::query()->find($this->runId)?->isTerminal()) {
            return;
        }

        $final = $decisionService->validateAndRepairForDocument($run->customer_id, $document, $language, $merged);

        if (EnterpriseWikiIngestRun::query()->find($this->runId)?->isTerminal()) {
            return;
        }

        if ($flow->persistMaintainerDecision($run, $final)) {
            $flow->continueAfterMaintainerDecisionBatches($run->id);
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
