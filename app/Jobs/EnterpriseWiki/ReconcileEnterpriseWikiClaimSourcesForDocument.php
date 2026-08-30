<?php

namespace App\Jobs\EnterpriseWiki;

use App\Models\EnterpriseWikiDocument;
use App\Services\EnterpriseWiki\EnterpriseWikiClaimSourceReconciliationService;
use App\Support\Ai\RunsInAiCallContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dispatched once a new Enterprise Wiki document has finished text extraction
 * (document_status=extracted) — checks whether the document supports any of this customer's
 * existing claims that currently have no source reference, and attaches a real source
 * reference where it does. See EnterpriseWikiClaimSourceReconciliationService for the
 * reservation/lease protocol that makes duplicate dispatches/retries safe.
 *
 * Never regenerates pages, page versions, or claims — only ever adds an
 * EnterpriseWikiSourceReference to an existing claim.
 */
class ReconcileEnterpriseWikiClaimSourcesForDocument implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use RunsInAiCallContext;
    use SerializesModels;

    /**
     * Exposed as a class constant so EnterpriseWikiClaimSourceReconciliationService can derive
     * its per-(claim,document) lease duration from it — the same invariant established for
     * ContinueEnterpriseWikiDocumentFlowAfterPages: lease duration must exceed this timeout, or
     * a live worker could lose its reservation mid-AI-call.
     */
    public const TIMEOUT_SECONDS = 1800;

    public const QUEUE_NAME = 'enterprise-wiki-reconciliation';

    public int $tries = 1;

    public int $timeout = self::TIMEOUT_SECONDS;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $documentId)
    {
        $this->queue = self::QUEUE_NAME;
    }

    public function handle(EnterpriseWikiClaimSourceReconciliationService $service): void
    {
        $this->withinAiCallContext(
            $this->enterpriseWikiDocumentAiCallContext($this->documentId, 'enterprise_wiki.reconcile_claim_sources'),
            function () use ($service): void {
                $this->handleInAiCallContext($service);
            },
        );
    }

    private function handleInAiCallContext(EnterpriseWikiClaimSourceReconciliationService $service): void
    {
        $document = EnterpriseWikiDocument::query()->find($this->documentId);

        if ($document === null) {
            Log::warning('[WIKI_CLAIM_RECONCILIATION] Document not found.', [
                'document_id' => $this->documentId,
            ]);

            return;
        }

        $service->reconcileForDocument($document);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('[WIKI_CLAIM_RECONCILIATION] Job failed.', [
            'document_id' => $this->documentId,
            'exception' => get_class($exception),
            'error' => $exception->getMessage(),
        ]);
    }
}
