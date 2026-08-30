<?php

namespace App\Jobs\Ai\Wiki;

use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestSection;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Services\Ai\Wiki\EnterpriseWikiIngestService;
use App\Services\Ai\Wiki\WikiArticleAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentWikiAnswerStalenessService;
use App\Support\Ai\RunsInAiCallContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class FinalizeEnterpriseWikiIngest implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use RunsInAiCallContext;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $runId)
    {
        $this->queue = 'enterprise-wiki';
    }

    public function handle(
        EnterpriseWikiIngestService $service,
        WikiArticleAiClient $articleClient,
        EnterpriseWikiDocumentWikiAnswerStalenessService $wikiAnswerStalenessService,
    ): void {
        $this->withinAiCallContext(
            $this->enterpriseWikiRunAiCallContext($this->runId, 'enterprise_wiki.finalize_ingest'),
            function () use ($service, $articleClient, $wikiAnswerStalenessService): void {
                $this->failWithoutRetryOnCostControlBlock(fn (): mixed => $this->handleInAiCallContext($service, $articleClient, $wikiAnswerStalenessService));
            },
        );
    }

    private function handleInAiCallContext(
        EnterpriseWikiIngestService $service,
        WikiArticleAiClient $articleClient,
        EnterpriseWikiDocumentWikiAnswerStalenessService $wikiAnswerStalenessService,
    ): void {
        // All work — including DB reads and the AI call — happens inside one transaction
        // with the run row locked. This prevents two concurrent finalize instances from both
        // deciding to finalize the same run when the last sections complete at nearly the same time.
        DB::transaction(function () use ($articleClient, $wikiAnswerStalenessService): void {
            $run = EnterpriseWikiIngestRun::query()
                ->lockForUpdate()
                ->find($this->runId);

            if (! $run instanceof EnterpriseWikiIngestRun) {
                return;
            }

            if ($run->isTerminal()) {
                return;
            }

            $sections = EnterpriseWikiIngestSection::query()
                ->where('enterprise_wiki_ingest_run_id', $this->runId)
                ->get();

            // Some sections are still being processed — this finalize fired too early.
            // The section job that finishes last will dispatch another finalize.
            $hasPending = $sections->whereIn('status', [
                EnterpriseWikiIngestSection::STATUS_PENDING,
                EnterpriseWikiIngestSection::STATUS_RUNNING,
            ])->isNotEmpty();

            if ($hasPending) {
                return;
            }

            $failedCount = $sections->where('status', EnterpriseWikiIngestSection::STATUS_FAILED)->count();

            if ($failedCount > 0) {
                $run->update([
                    'status' => EnterpriseWikiIngestRun::STATUS_FAILED,
                    'error_message' => sprintf(
                        '%d of %d section(s) failed. See section error_message fields for details.',
                        $failedCount,
                        $sections->count(),
                    ),
                    'finished_at' => now(),
                ]);

                return;
            }

            // All sections completed — assemble wiki content from stored claims.
            if (! $run->enterprise_wiki_page_id) {
                $run->update([
                    'status' => EnterpriseWikiIngestRun::STATUS_FAILED,
                    'error_message' => 'Draft wiki page ID missing on run record.',
                    'finished_at' => now(),
                ]);

                return;
            }

            $pageVersion = EnterpriseWikiPageVersion::query()
                ->where('enterprise_wiki_page_id', $run->enterprise_wiki_page_id)
                ->where('version_number', 1)
                ->first();

            if (! $pageVersion instanceof EnterpriseWikiPageVersion) {
                $run->update([
                    'status' => EnterpriseWikiIngestRun::STATUS_FAILED,
                    'error_message' => 'Draft wiki page version (version_number=1) not found during finalize.',
                    'finished_at' => now(),
                ]);

                return;
            }

            $pageTitle = $pageVersion->page->title;

            $claimsData = EnterpriseWikiClaim::query()
                ->with('sourceReferences')
                ->where('enterprise_wiki_page_version_id', $pageVersion->id)
                ->orderBy('position_order')
                ->get()
                ->map(fn (EnterpriseWikiClaim $claim) => [
                    'text' => $claim->claim_text,
                    'confidence' => $claim->confidence,
                    'excerpt' => $claim->sourceReferences->first()?->excerpt ?? '',
                    'source' => $claim->sourceReferences->first()?->source_label ?? '',
                ])
                ->all();

            if (empty($claimsData)) {
                $run->update([
                    'status' => EnterpriseWikiIngestRun::STATUS_FAILED,
                    'error_message' => 'No claims were stored for this ingest run. Cannot generate article.',
                    'finished_at' => now(),
                ]);

                return;
            }

            // Guard: if the feature flag was disabled after the ingest was queued,
            // fail the run cleanly inside the transaction rather than relying on
            // a RuntimeException + failed() to recover.
            if (! WikiArticleAiClient::isAvailable()) {
                $run->update([
                    'status' => EnterpriseWikiIngestRun::STATUS_FAILED,
                    'error_message' => 'Enterprise wiki article generation is disabled (ENTERPRISE_WIKI_AI_ENABLED=false).',
                    'finished_at' => now(),
                ]);

                return;
            }

            $run->load('customer.language');
            $languageCode = $run->customer?->language?->code ?? 'no';

            $markdown = $articleClient->generateArticle($pageTitle, $claimsData, $languageCode);

            // Publish the generated article to the draft page version.
            // is_current=true makes this the active version (readable via Page::currentVersion()).
            // Page advances to pending_review — ready for human review, not yet approved.
            // Claims remain in approval_status='pending' — human approval is a separate step.
            $pageVersion->update([
                'content_markdown' => $markdown,
                'is_current' => true,
            ]);

            $wikiAnswerStalenessService->markAnswersStaleForWikiPageChange($pageVersion->enterprise_wiki_page_id);

            EnterpriseWikiPage::query()
                ->where('id', $run->enterprise_wiki_page_id)
                ->update(['status' => EnterpriseWikiPage::STATUS_PENDING_REVIEW]);

            $run->update([
                'status' => EnterpriseWikiIngestRun::STATUS_COMPLETED,
                'finished_at' => now(),
            ]);

            Log::info(sprintf(
                '[WIKI_FINALIZE][COMPLETED] run_id=%d page_id=%d version_id=%d',
                $run->id,
                $pageVersion->enterprise_wiki_page_id,
                $pageVersion->id,
            ));
        });
    }

    public function failed(Throwable $exception): void
    {
        $run = EnterpriseWikiIngestRun::query()->find($this->runId);

        if ($run && ! $run->isTerminal()) {
            $run->update([
                'status' => EnterpriseWikiIngestRun::STATUS_FAILED,
                'error_message' => mb_substr($exception->getMessage(), 0, 1000),
                'finished_at' => now(),
            ]);
        }
    }
}
