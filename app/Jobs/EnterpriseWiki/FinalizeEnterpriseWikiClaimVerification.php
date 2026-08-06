<?php

namespace App\Jobs\EnterpriseWiki;

use App\Services\EnterpriseWiki\EnterpriseWikiDocumentFlowService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FinalizeEnterpriseWikiClaimVerification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $backoff = 60;

    public int $timeout = 1800;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $runId,
        public readonly bool $recoverUndispatchedClaims = false,
    ) {
        $this->onQueue('enterprise-wiki');
    }

    public function handle(EnterpriseWikiDocumentFlowService $flowService): void
    {
        $flowService->continueAfterClaimVerification($this->runId, $this->recoverUndispatchedClaims);
    }
}
