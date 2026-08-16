<?php

namespace App\Jobs\EnterpriseWiki;

use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Services\EnterpriseWiki\EnterpriseWikiBuildPageLinksService;
use App\Services\EnterpriseWiki\EnterpriseWikiGenerateAppliedPagesService;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionPrompt;
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
 * Two-phase design: article, summary, and independently-scoped concept pages run in the first
 * wave. Concept pages without explicit owned_topics, and entity pages, remain in the deferred
 * concept/entity wave so they can use finished article/summary content as context. The run's own
 * status is the phase marker:
 *   - generating_pages                 -> initial wave in flight
 *   - generating_concept_entity_pages  -> deferred concept/entity wave in flight
 *
 * Dispatched by every GenerateEnterpriseWikiAppliedPage job on completion or failure — the
 * page job that finishes last for a given phase is the one whose invocation actually advances
 * the run. All earlier invocations are cheap no-ops guarded by the locked run row and its
 * status, mirroring FinalizeEnterpriseWikiIngest's "last section wins" pattern for the legacy
 * section pipeline.
 *
 * Crash-recovery sentinel: also self-redispatched every CRASH_RECOVERY_DELAY_SECONDS with
 * $recoverStalePages=true whenever a wave has pages still outstanding (from
 * EnterpriseWikiDocumentFlowService::beginGeneratingPages() and dispatchConceptEntityPhase()) —
 * the page-generation equivalent of FinalizeEnterpriseWikiClaimVerification. A recovery pass
 * only ever redispatches a pivot EnterpriseWikiGenerateAppliedPagesService::redispatchablePageIdsForRun()
 * classifies as genuinely lost (stale dispatched or stale running); each candidate still goes
 * through reservePageForDispatch()'s own atomic compare-and-swap, so concurrent recovery
 * invocations can never enqueue the same page job twice.
 */
class FinalizeEnterpriseWikiPageGeneration implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const INITIAL_WAVE_TYPES = [
        EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
        EnterpriseWikiPage::PAGE_TYPE_SUMMARY,
        EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
    ];

    private const CONCEPT_ENTITY_TYPES = [
        EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
        EnterpriseWikiPage::PAGE_TYPE_ENTITY,
    ];

    /**
     * Recovery-only fallback for a worker/process crash between dispatch and completion of
     * page-generation jobs — the same rationale as
     * EnterpriseWikiDocumentFlowService::CLAIM_VERIFICATION_CRASH_RECOVERY_DELAY_SECONDS. The
     * normal path is driven by each completing/failing GenerateEnterpriseWikiAppliedPage job.
     */
    private const CRASH_RECOVERY_DELAY_SECONDS = 60;

    public int $tries = 3;

    public int $timeout = 120;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $runId,
        public readonly bool $recoverStalePages = false,
    ) {
        $this->queue = 'enterprise-wiki';
    }

    public function handle(): void
    {
        // Resolved via the container rather than a typed handle() parameter — this job is
        // frequently constructed and its handle() called directly in tests (bypassing queue
        // dispatch, and therefore Laravel's automatic method-injection).
        $buildPageLinksService = app(EnterpriseWikiBuildPageLinksService::class);
        $generateAppliedPagesService = app(EnterpriseWikiGenerateAppliedPagesService::class);

        $result = DB::transaction(function () use ($buildPageLinksService): array {
            $run = EnterpriseWikiIngestRun::query()
                ->lockForUpdate()
                ->find($this->runId);

            if (! $run instanceof EnterpriseWikiIngestRun) {
                return ['outcome' => 'missing'];
            }

            return match ($run->status) {
                EnterpriseWikiIngestRun::STATUS_GENERATING_PAGES => $this->finalizeInitialPhase($run, $buildPageLinksService),
                EnterpriseWikiIngestRun::STATUS_GENERATING_CONCEPT_ENTITY_PAGES => $this->finalizeDeferredConceptEntityPhase($run),
                default => ['outcome' => 'already_advanced'],
            };
        });

        if (EnterpriseWikiIngestRun::query()->find($this->runId)?->isTerminal()) {
            return;
        }

        match ($result['outcome']) {
            'dispatch_concept_entity' => $this->dispatchConceptEntityPhase($result['page_ids'], $generateAppliedPagesService),
            'completed' => ContinueEnterpriseWikiDocumentFlowAfterPages::dispatch($this->runId),
            'pending' => $this->attemptStalePageRecovery($generateAppliedPagesService),
            default => null,
        };
    }

    /**
     * Fan-in-safe recovery pass: only redispatches pivots that
     * EnterpriseWikiGenerateAppliedPagesService::redispatchablePageIdsForRun() classifies as
     * genuinely lost (stale dispatched or stale running) — never one still legitimately queued
     * or actively leased. Each candidate still goes through reservePageForDispatch()'s own
     * atomic compare-and-swap, so two concurrent recovery-sentinel invocations for the same run
     * can never both enqueue the same page job. A no-op (this->recoverStalePages === false) for
     * every ordinary invocation dispatched by a completing/failing page job — only the delayed
     * self-redispatch below opts back in.
     */
    private function attemptStalePageRecovery(EnterpriseWikiGenerateAppliedPagesService $service): void
    {
        if (! $this->recoverStalePages) {
            return;
        }

        if (EnterpriseWikiIngestRun::query()->find($this->runId)?->isTerminal()) {
            return;
        }

        $candidatePageIds = $service->redispatchablePageIdsForRun($this->runId);
        $redispatched = 0;

        foreach ($candidatePageIds as $pageId) {
            if ($service->reservePageForDispatch($this->runId, $pageId)) {
                GenerateEnterpriseWikiAppliedPage::dispatch($this->runId, $pageId);
                $redispatched++;
            }
        }

        Log::info('[WIKI_PAGE_GENERATION_FINALIZE] Stale page-generation recovery pass evaluated.', [
            'run_id' => $this->runId,
            'candidates' => count($candidatePageIds),
            'redispatched' => $redispatched,
        ]);

        self::dispatch($this->runId, true)->delay(now()->addSeconds(self::CRASH_RECOVERY_DELAY_SECONDS));
    }

    /**
     * @return array{outcome: string, page_ids?: Collection}
     */
    private function finalizeInitialPhase(EnterpriseWikiIngestRun $run, EnterpriseWikiBuildPageLinksService $buildPageLinksService): array
    {
        $pivots = $this->initialWavePivots($run);

        if ($this->hasPending($pivots)) {
            return ['outcome' => 'pending'];
        }

        $failed = $this->failedPivots($pivots);

        if ($failed->isNotEmpty()) {
            $this->markRunFailed($run, $failed, $pivots->count(), 'initial');

            return ['outcome' => 'failed'];
        }

        // Article and summary pages exist and generated successfully before any later flow stage
        // runs — this is the exact, single, guaranteed-once point to build the structural
        // article<->summary link graph (co-membership, not content-derived — see
        // EnterpriseWikiBuildPageLinksService::buildArticleSummaryLinks()). Deliberately narrower
        // than build(): concept/entity combinatoric linking stays an explicit, opt-in operation
        // (wiki:build-page-links), unaffected by this automatic step.
        $buildPageLinksService->buildArticleSummaryLinks($run);

        $deferredPageIds = $this->deferredConceptEntityPivots($run)
            ->whereNull('generated_page_version_id')
            ->pluck('enterprise_wiki_page_id')
            ->values();

        if ($deferredPageIds->isEmpty()) {
            $run->update(['status' => EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING]);

            Log::info('[WIKI_PAGE_GENERATION_FINALIZE] All initial-wave pages generated — continuing document flow.', [
                'run_id' => $this->runId,
                'initial_pages' => $pivots->count(),
            ]);

            return ['outcome' => 'completed'];
        }

        // All initial-wave pages are done — this is the single atomic claim that guarantees
        // deferred concept/entity jobs are dispatched exactly once: a concurrent invocation
        // blocks on the row lock, then sees status !== generating_pages and returns
        // 'already_advanced' above instead of dispatching a second wave.
        $run->update(['status' => EnterpriseWikiIngestRun::STATUS_GENERATING_CONCEPT_ENTITY_PAGES]);

        Log::info('[WIKI_PAGE_GENERATION_FINALIZE] Initial-wave pages generated — dispatching deferred concept/entity pages.', [
            'run_id' => $this->runId,
            'initial_pages' => $pivots->count(),
            'concept_entity_pages_dispatched' => $deferredPageIds->count(),
        ]);

        return ['outcome' => 'dispatch_concept_entity', 'page_ids' => $deferredPageIds];
    }

    /**
     * @return array{outcome: string}
     */
    private function finalizeDeferredConceptEntityPhase(EnterpriseWikiIngestRun $run): array
    {
        $pivots = $this->deferredConceptEntityPivots($run);

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
            'deferred_concept_entity_pages' => $pivots->count(),
        ]);

        return ['outcome' => 'completed'];
    }

    private function dispatchConceptEntityPhase(Collection $pageIds, EnterpriseWikiGenerateAppliedPagesService $service): void
    {
        foreach ($pageIds as $pageId) {
            if (EnterpriseWikiIngestRun::query()->find($this->runId)?->isTerminal()) {
                return;
            }

            if ($service->reservePageForDispatch($this->runId, $pageId)) {
                GenerateEnterpriseWikiAppliedPage::dispatch($this->runId, $pageId);
            }
        }

        if (EnterpriseWikiIngestRun::query()->find($this->runId)?->isTerminal()) {
            return;
        }

        // Safety net for the case where every concept/entity page already has a version, or
        // there are none at all: no page job will fire to trigger the next phase check.
        FinalizeEnterpriseWikiPageGeneration::dispatch($this->runId);

        // Crash-recovery sentinel for this wave, mirroring the initial wave's fallback dispatched
        // from EnterpriseWikiDocumentFlowService::beginGeneratingPages().
        self::dispatch($this->runId, true)->delay(now()->addSeconds(self::CRASH_RECOVERY_DELAY_SECONDS));
    }

    private function pivotsForTypes(array $pageTypes): Collection
    {
        return EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $this->runId)
            ->whereHas('page', fn ($query) => $query->whereIn('page_type', $pageTypes))
            ->with('page')
            ->get();
    }

    private function initialWavePivots(EnterpriseWikiIngestRun $run): Collection
    {
        $decisionJson = (array) ($run->maintainer_decision_json ?? []);

        return $this->pivotsForTypes(self::INITIAL_WAVE_TYPES)
            ->filter(fn (EnterpriseWikiIngestRunPage $row): bool => $this->isInitialWavePage($row->page, $decisionJson))
            ->values();
    }

    private function deferredConceptEntityPivots(EnterpriseWikiIngestRun $run): Collection
    {
        $decisionJson = (array) ($run->maintainer_decision_json ?? []);

        return $this->pivotsForTypes(self::CONCEPT_ENTITY_TYPES)
            ->filter(fn (EnterpriseWikiIngestRunPage $row): bool => ! $this->isInitialWavePage($row->page, $decisionJson))
            ->values();
    }

    /**
     * @param  array<string, mixed>  $decisionJson
     */
    private function isInitialWavePage(?EnterpriseWikiPage $page, array $decisionJson): bool
    {
        if (! $page instanceof EnterpriseWikiPage) {
            return false;
        }

        return match ($page->page_type) {
            EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            EnterpriseWikiPage::PAGE_TYPE_SUMMARY => true,
            EnterpriseWikiPage::PAGE_TYPE_CONCEPT => $this->conceptCanGenerateWithoutArticleSummaryContext($page, $decisionJson),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $decisionJson
     */
    private function conceptCanGenerateWithoutArticleSummaryContext(EnterpriseWikiPage $page, array $decisionJson): bool
    {
        if ($page->page_type !== EnterpriseWikiPage::PAGE_TYPE_CONCEPT) {
            return false;
        }

        $entry = $this->conceptDecisionEntry($page, $decisionJson);

        return $entry !== null && EnterpriseWikiMaintainerDecisionPrompt::ownedTopicNames($entry['owned_topics'] ?? []) !== [];
    }

    /**
     * @param  array<string, mixed>  $decisionJson
     * @return array<string, mixed>|null
     */
    private function conceptDecisionEntry(EnterpriseWikiPage $page, array $decisionJson): ?array
    {
        foreach ((array) data_get($decisionJson, 'concept_pages', []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (($entry['title'] ?? null) === $page->title) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function nonEmptyStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn ($item): string => trim((string) $item), $value),
            fn (string $item): bool => $item !== '',
        ));
    }

    private function hasPending(Collection $pivots): bool
    {
        return $pivots->whereIn('generation_status', [
            EnterpriseWikiIngestRunPage::GENERATION_STATUS_PENDING,
            EnterpriseWikiIngestRunPage::GENERATION_STATUS_DISPATCHED,
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

        // Per-page detail (title, page_type, and the concrete reason — which already carries
        // its original exception type, e.g. "[EnterpriseWikiInvalidWikilinksException] ...",
        // via GenerateEnterpriseWikiAppliedPage::markPivotFailed()) so the run-level
        // error_message is understandable without a stacktrace or queue log lookup.
        $details = $failed
            ->map(function (EnterpriseWikiIngestRunPage $p) {
                $title = $p->page?->title ?? "page #{$p->enterprise_wiki_page_id}";
                $pageType = $p->page?->page_type ?? 'unknown';
                $reason = $p->generation_error ?? 'unknown error';

                return "{$title} ({$pageType}): {$reason}";
            })
            ->implode(' | ');

        $run->update([
            'status' => EnterpriseWikiIngestRun::STATUS_FAILED,
            'error_message' => mb_substr(sprintf(
                '%d of %d %s page(s) failed to generate: %s. Details — %s',
                $failed->count(),
                $total,
                $phaseLabel,
                $titles,
                $details,
            ), 0, 1000),
            'finished_at' => now(),
        ]);

        Log::error('[WIKI_PAGE_GENERATION_FINALIZE] Run failed — one or more applied pages failed to generate.', [
            'run_id' => $this->runId,
            'phase' => $phaseLabel,
            'failed_pages' => $failed->count(),
            'total_pages' => $total,
            'details' => $details,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $run = EnterpriseWikiIngestRun::query()->find($this->runId);

        if ($run instanceof EnterpriseWikiIngestRun && ! $run->isTerminal()) {
            EnterpriseWikiIngestRun::query()->whereKey($run->id)->nonTerminal()->update([
                'status' => EnterpriseWikiIngestRun::STATUS_FAILED,
                'error_message' => mb_substr($exception->getMessage(), 0, 1000),
                'finished_at' => now(),
            ]);
        }
    }
}
