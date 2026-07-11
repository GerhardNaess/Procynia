<?php

namespace App\Jobs\EnterpriseWiki;

use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Checks whether the current applied-page-generation phase for a run has finished
 * (successfully or not) and advances the run, or marks it failed.
 *
 * Two-phase design: article/summary pages must all finish before concept/entity pages are
 * dispatched, because concept/entity generation reads the finished article/summary content
 * as context. Dispatching everything in one wave would make that context dependent on queue
 * timing rather than guaranteed. The run's own status is the phase marker:
 *   - generating_pages                 -> phase 1 (article/summary) in flight
 *   - generating_concept_entity_pages  -> phase 2 (concept/entity) in flight
 *
 * Dispatched by every GenerateEnterpriseWikiAppliedPage job on completion or failure — the
 * page job that finishes last for a given phase is the one whose invocation actually advances
 * the run. All earlier invocations are cheap no-ops guarded by the locked run row and its
 * status, mirroring FinalizeEnterpriseWikiIngest's "last section wins" pattern for the legacy
 * section pipeline.
 */
class FinalizeEnterpriseWikiPageGeneration implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const ARTICLE_SUMMARY_TYPES = [
        EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
        EnterpriseWikiPage::PAGE_TYPE_SUMMARY,
    ];

    private const CONCEPT_ENTITY_TYPES = [
        EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
        EnterpriseWikiPage::PAGE_TYPE_ENTITY,
    ];

    public int $tries = 3;

    public int $timeout = 120;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $runId)
    {
        $this->queue = 'enterprise-wiki';
    }

    public function handle(): void
    {
        $result = DB::transaction(function (): array {
            $run = EnterpriseWikiIngestRun::query()
                ->lockForUpdate()
                ->find($this->runId);

            if (! $run instanceof EnterpriseWikiIngestRun) {
                return ['outcome' => 'missing'];
            }

            return match ($run->status) {
                EnterpriseWikiIngestRun::STATUS_GENERATING_PAGES => $this->finalizeArticleSummaryPhase($run),
                EnterpriseWikiIngestRun::STATUS_GENERATING_CONCEPT_ENTITY_PAGES => $this->finalizeConceptEntityPhase($run),
                default => ['outcome' => 'already_advanced'],
            };
        });

        match ($result['outcome']) {
            'dispatch_concept_entity' => $this->dispatchConceptEntityPhase($result['page_ids']),
            'completed' => ContinueEnterpriseWikiDocumentFlowAfterPages::dispatch($this->runId),
            default => null,
        };
    }

    /**
     * @return array{outcome: string, page_ids?: Collection}
     */
    private function finalizeArticleSummaryPhase(EnterpriseWikiIngestRun $run): array
    {
        $pivots = $this->pivotsForTypes(self::ARTICLE_SUMMARY_TYPES);

        if ($this->hasPending($pivots)) {
            return ['outcome' => 'pending'];
        }

        $failed = $this->failedPivots($pivots);

        if ($failed->isNotEmpty()) {
            $this->markRunFailed($run, $failed, $pivots->count(), 'article/summary');

            return ['outcome' => 'failed'];
        }

        // All article/summary pages are done — this is the single atomic claim that
        // guarantees concept/entity jobs are dispatched exactly once: a concurrent
        // invocation of this job blocks on the row lock, then sees status !== generating_pages
        // and returns 'already_advanced' above instead of dispatching a second wave.
        $run->update(['status' => EnterpriseWikiIngestRun::STATUS_GENERATING_CONCEPT_ENTITY_PAGES]);

        $conceptEntityPageIds = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $this->runId)
            ->whereNull('generated_page_version_id')
            ->whereHas('page', fn ($query) => $query->whereIn('page_type', self::CONCEPT_ENTITY_TYPES))
            ->pluck('enterprise_wiki_page_id');

        Log::info('[WIKI_PAGE_GENERATION_FINALIZE] Article/summary pages generated — dispatching concept/entity pages.', [
            'run_id' => $this->runId,
            'article_summary_pages' => $pivots->count(),
            'concept_entity_pages_dispatched' => $conceptEntityPageIds->count(),
        ]);

        return ['outcome' => 'dispatch_concept_entity', 'page_ids' => $conceptEntityPageIds];
    }

    /**
     * @return array{outcome: string}
     */
    private function finalizeConceptEntityPhase(EnterpriseWikiIngestRun $run): array
    {
        $pivots = $this->pivotsForTypes(self::CONCEPT_ENTITY_TYPES);

        if ($this->hasPending($pivots)) {
            return ['outcome' => 'pending'];
        }

        $failed = $this->failedPivots($pivots);

        if ($failed->isNotEmpty()) {
            $this->markRunFailed($run, $failed, $pivots->count(), 'concept/entity');

            return ['outcome' => 'failed'];
        }

        // Same single-claim guarantee as phase 1: this transition out of
        // generating_concept_entity_pages happens exactly once under the row lock.
        $run->update(['status' => EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING]);

        Log::info('[WIKI_PAGE_GENERATION_FINALIZE] All applied pages generated — continuing document flow.', [
            'run_id' => $this->runId,
            'concept_entity_pages' => $pivots->count(),
        ]);

        return ['outcome' => 'completed'];
    }

    private function dispatchConceptEntityPhase(Collection $pageIds): void
    {
        foreach ($pageIds as $pageId) {
            GenerateEnterpriseWikiAppliedPage::dispatch($this->runId, $pageId);
        }

        // Safety net for the case where every concept/entity page already has a version, or
        // there are none at all: no page job will fire to trigger the next phase check.
        FinalizeEnterpriseWikiPageGeneration::dispatch($this->runId);
    }

    private function pivotsForTypes(array $pageTypes): Collection
    {
        return EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $this->runId)
            ->whereHas('page', fn ($query) => $query->whereIn('page_type', $pageTypes))
            ->with('page')
            ->get();
    }

    private function hasPending(Collection $pivots): bool
    {
        return $pivots->whereIn('generation_status', [
            EnterpriseWikiIngestRunPage::GENERATION_STATUS_PENDING,
            EnterpriseWikiIngestRunPage::GENERATION_STATUS_RUNNING,
        ])->isNotEmpty();
    }

    private function failedPivots(Collection $pivots): Collection
    {
        return $pivots->where('generation_status', EnterpriseWikiIngestRunPage::GENERATION_STATUS_FAILED);
    }

    private function markRunFailed(EnterpriseWikiIngestRun $run, Collection $failed, int $total, string $phaseLabel): void
    {
        $titles = $failed
            ->map(fn (EnterpriseWikiIngestRunPage $p) => $p->page?->title ?? "page #{$p->enterprise_wiki_page_id}")
            ->implode(', ');

        $run->update([
            'status' => EnterpriseWikiIngestRun::STATUS_FAILED,
            'error_message' => mb_substr(sprintf(
                '%d of %d %s page(s) failed to generate: %s.',
                $failed->count(),
                $total,
                $phaseLabel,
                $titles,
            ), 0, 1000),
            'finished_at' => now(),
        ]);

        Log::error('[WIKI_PAGE_GENERATION_FINALIZE] Run failed — one or more applied pages failed to generate.', [
            'run_id' => $this->runId,
            'phase' => $phaseLabel,
            'failed_pages' => $failed->count(),
            'total_pages' => $total,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $run = EnterpriseWikiIngestRun::query()->find($this->runId);

        if ($run instanceof EnterpriseWikiIngestRun && ! $run->isTerminal()) {
            $run->update([
                'status' => EnterpriseWikiIngestRun::STATUS_FAILED,
                'error_message' => mb_substr($exception->getMessage(), 0, 1000),
                'finished_at' => now(),
            ]);
        }
    }
}
