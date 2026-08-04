<?php

namespace App\Jobs\EnterpriseWiki;

use App\Models\EnterpriseWikiIngestRun;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentFlowService;
use App\Services\EnterpriseWiki\EnterpriseWikiVerifyPageClaimsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class VerifyEnterpriseWikiClaim implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const QUEUE = 'enterprise-wiki-claim-verification';

    public int $tries = 1;

    public int $backoff = 60;

    public int $timeout = 1800;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $runId, public readonly int $claimId)
    {
        $this->onQueue(self::QUEUE);
    }

    public function handle(EnterpriseWikiVerifyPageClaimsService $verificationService): void
    {
        $run = EnterpriseWikiIngestRun::query()->find($this->runId);

        if (! $run instanceof EnterpriseWikiIngestRun || $run->isTerminal()) {
            return;
        }

        $verificationService->verifyClaimForRun($run, $this->claimId);
    }

    public function failed(Throwable $exception): void
    {
        app(EnterpriseWikiDocumentFlowService::class)->markClaimVerificationFailed($this->runId, $exception);
    }
}
