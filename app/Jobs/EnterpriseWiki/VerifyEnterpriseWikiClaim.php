<?php

namespace App\Jobs\EnterpriseWiki;

use App\Models\EnterpriseWikiIngestRun;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentFlowService;
use App\Services\EnterpriseWiki\EnterpriseWikiVerifyPageClaimsService;
use App\Support\Ai\RunsInAiCallContext;
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
    use RunsInAiCallContext;
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

    public function handle(
        EnterpriseWikiVerifyPageClaimsService $verificationService,
        EnterpriseWikiDocumentFlowService $flowService,
    ): void {
        $this->withinAiCallContext(
            $this->enterpriseWikiRunAiCallContext($this->runId, 'enterprise_wiki.verify_claim'),
            function () use ($verificationService, $flowService): void {
                $this->handleInAiCallContext($verificationService, $flowService);
            },
        );
    }

    private function handleInAiCallContext(
        EnterpriseWikiVerifyPageClaimsService $verificationService,
        EnterpriseWikiDocumentFlowService $flowService,
    ): void {
        $run = EnterpriseWikiIngestRun::query()->find($this->runId);

        if (! $run instanceof EnterpriseWikiIngestRun || $run->isTerminal()) {
            return;
        }

        $verificationService->verifyClaimForRun($run, $this->claimId);

        if (EnterpriseWikiIngestRun::query()->find($this->runId)?->isTerminal()) {
            return;
        }

        $flowService->continueAfterClaimVerification($this->runId);
    }

    public function failed(Throwable $exception): void
    {
        if (EnterpriseWikiIngestRun::query()->find($this->runId)?->isTerminal()) {
            return;
        }

        app(EnterpriseWikiDocumentFlowService::class)->markClaimVerificationFailed($this->runId, $exception);
    }
}
