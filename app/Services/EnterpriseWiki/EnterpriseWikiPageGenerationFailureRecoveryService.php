<?php

namespace App\Services\EnterpriseWiki;

use App\Jobs\EnterpriseWiki\ContinueEnterpriseWikiDocumentFlowAfterPages;
use App\Jobs\EnterpriseWiki\FinalizeEnterpriseWikiPageGeneration;
use App\Jobs\EnterpriseWiki\GenerateEnterpriseWikiAppliedPage;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The single, central decision-maker for whether — and how — a run that failed because one or
 * more individual applied-page generation jobs failed (GenerateEnterpriseWikiAppliedPage, via
 * FinalizeEnterpriseWikiPageGeneration::markRunFailed()) can be safely resumed by re-dispatching
 * ONLY the failed page job(s), leaving every already-completed page untouched. Built for the Wiki
 * run-593 incident: page 353 ("Styringsnivåer...") failed planned-section-coverage after one
 * bounded repair, while the other 7 pages for the same run (article, summary, 5 other concept
 * pages) all generated successfully — there was no existing way to retry just the one failed page
 * without regenerating (and re-billing AI calls for) the pages that already succeeded.
 *
 * Deliberately separate from EnterpriseWikiMaintainerDecisionFailureRecoveryService (which owns
 * status=failed + failed_phase=maintainer_decision, before any page exists) — this service owns
 * the opposite end of the pipeline: maintainer_decision_status=applied, pages already exist, and
 * SOME of them reached a terminal generation_status=failed while every other one is
 * generation_status=completed.
 *
 * Two entry points, same shape as EnterpriseWikiMaintainerDecisionFailureRecoveryService:
 *  - evaluate(): read-only preview, no lock, no mutation, no dispatch.
 *  - attempt(): the real, locked, mutating call. Locks the RUN row (not the pivot rows) so two
 *    concurrent callers can never both "win" — whichever transaction commits first moves the run's
 *    status away from `failed` (to generating_pages or generating_concept_entity_pages), so the
 *    other sees that fresh status and returns already_running. GenerateEnterpriseWikiAppliedPage's
 *    own generatePageForRun() claim (skips a pivot whose generated_page_version_id is already set)
 *    is a second, independent layer of protection against a genuine duplicate dispatch.
 *
 * Since controlled partial completion (docs/enterprise-wiki-knowledge-integration-plan.md §4.2,
 * decisions D1/D3) it also resumes an escalated, partially completed run. It never retries a plan
 * defect such as planned_section_no_evidence (Wiki run 26): that repeats on every attempt, and is
 * refused with EnterpriseWikiRunRecoveryResult::CODE_PLAN_DEFECT for the UI to explain. A transient
 * or unrecognised failure (failureKind()) may be retried — but only by a person ("Prøv igjen for
 * feilede sider"). No scheduler calls this service: no attempt limit exists that would bound an
 * automatic retry of a persistently "transient" failure.
 *
 * Resuming never touches a completed pivot: only the failed pivot(s) are reset to
 * generation_status=pending (clearing generation_started_at/generation_completed_at/
 * generation_error back to their original at-creation defaults) and re-dispatched. Once those jobs
 * finish (success or failure), GenerateEnterpriseWikiAppliedPage's own existing completion/failure
 * handling already dispatches FinalizeEnterpriseWikiPageGeneration exactly as it does for a fresh
 * run — that finalize logic is untouched and needs no awareness that this was a recovery.
 */
class EnterpriseWikiPageGenerationFailureRecoveryService
{
    /**
     * Failure markers that can only repeat on a retry, because the cause is in the plan or the
     * source, not in the attempt: a planned section with no source evidence (Wiki run 26), or an
     * existing page the decision may only patch. Matched against the pivot's generation_error,
     * which carries the original exception class (GenerateEnterpriseWikiAppliedPage::markPivotFailed()).
     */
    private const PLAN_DEFECT_MARKERS = [
        'EnterpriseWikiPlannedSectionEvidenceMissingException',
        'planned_section_no_evidence',
        'EnterpriseWikiPatchTargetRegenerationBlockedException',
    ];

    /** generation_error prefix for an update an open review deferred (EnterpriseWikiPageUnderReviewException). */
    public const REVIEW_DEFERRAL_MARKER = 'EnterpriseWikiPageUnderReviewException';

    public const FAILURE_PLAN_DEFECT = 'plan_defect';

    public const FAILURE_TRANSIENT = 'transient';

    public const FAILURE_UNKNOWN = 'unknown';

    /** Not a fault: the update waited for an open review (REVIEW_DEFERRAL_MARKER). */
    public const FAILURE_REVIEW_DEFERRED = 'review_deferred';

    /**
     * generation_error prefix the patch engine records when a deferred patch no longer applies to
     * the page as it is now (EnterpriseWikiPatchApplicationService) — the plan is stale, so a retry
     * would repeat it.
     */
    public const PATCH_APPLICATION_FAILURE_MARKER = 'EnterpriseWikiPatchApplicationException';

    /** What page generation records when it is handed a patch target (it refuses, by design). */
    private const REGENERATION_BLOCKED_MARKER = 'EnterpriseWikiPatchTargetRegenerationBlockedException';

    /** The page-generation crash sentinel interval (FinalizeEnterpriseWikiPageGeneration). */
    private const FINALIZE_SENTINEL_DELAY_SECONDS = 60;

    private const INITIAL_WAVE_TYPES = [
        EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
        EnterpriseWikiPage::PAGE_TYPE_SUMMARY,
        EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
    ];

    public function __construct(
        private readonly EnterpriseWikiGenerateAppliedPagesService $generateAppliedPagesService,
    ) {}

    /**
     * Read-only preview of what attempt() would do, without locking, mutating, or dispatching
     * anything.
     */
    public function evaluate(int $runId): EnterpriseWikiRunRecoveryResult
    {
        $run = EnterpriseWikiIngestRun::query()->find($runId);

        if ($run === null) {
            return EnterpriseWikiRunRecoveryResult::notRecoverable("Run [{$runId}] not found.");
        }

        return $this->decide($run)['result'];
    }

    /**
     * Attempts to re-dispatch only the failed page-generation job(s) if — and only if — the run
     * is genuinely recoverable right now. Idempotent: calling this twice for the same run
     * (concurrently or sequentially) never dispatches a second wave of jobs — the second call
     * observes the run's now-changed status (no longer `failed`) and returns already_running.
     */
    public function attempt(int $runId, string $caller): EnterpriseWikiRunRecoveryResult
    {
        return DB::transaction(function () use ($runId, $caller) {
            $run = EnterpriseWikiIngestRun::query()->lockForUpdate()->find($runId);

            if ($run === null) {
                return EnterpriseWikiRunRecoveryResult::notRecoverable("Run [{$runId}] not found.");
            }

            $decision = $this->decide($run);
            $result = $decision['result'];

            if ($result->isResumed()) {
                $this->resume($run, $decision['failedPivots']);

                Log::info('[WIKI_RUN_RECOVERY] Page-generation run resumed — retried only failed page(s).', [
                    'run_id' => $run->id,
                    'customer_id' => $run->customer_id,
                    'caller' => $caller,
                    'retried_page_ids' => $decision['failedPivots']->pluck('enterprise_wiki_page_id')->all(),
                ]);
            } else {
                Log::info('[WIKI_RUN_RECOVERY] Page-generation run recovery evaluated.', [
                    'run_id' => $run->id,
                    'customer_id' => $run->customer_id,
                    'caller' => $caller,
                    'outcome' => $result->outcome,
                    'reason' => $result->reason,
                ]);
            }

            return $result;
        });
    }

    /**
     * @return array{result: EnterpriseWikiRunRecoveryResult, failedPivots: Collection<int, EnterpriseWikiIngestRunPage>}
     */
    private function decide(EnterpriseWikiIngestRun $run): array
    {
        $none = collect();

        if (in_array($run->status, EnterpriseWikiIngestRun::EXPECTS_AUTOMATIC_PROGRESS_STATUSES, true)) {
            return [
                'result' => EnterpriseWikiRunRecoveryResult::alreadyRunning(
                    "Run [{$run->id}] has status [{$run->status}] — the ordinary pipeline already owns it."
                ),
                'failedPivots' => $none,
            ];
        }

        if (in_array($run->status, [
            EnterpriseWikiIngestRun::STATUS_COMPLETED,
            EnterpriseWikiIngestRun::STATUS_CANCELLED,
            EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL,
            EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
        ], true)) {
            return [
                'result' => EnterpriseWikiRunRecoveryResult::alreadyComplete(
                    "Run [{$run->id}] has status [{$run->status}] — nothing to recover."
                ),
                'failedPivots' => $none,
            ];
        }

        // failed: every page of a wave failed. escalated: a partially completed run
        // (docs/enterprise-wiki-knowledge-integration-plan.md §4.2) — the pages that generated went
        // through the rest of the flow and the failed ones are what keeps it from completing.
        if (! in_array($run->status, [EnterpriseWikiIngestRun::STATUS_FAILED, EnterpriseWikiIngestRun::STATUS_ESCALATED], true)) {
            return [
                'result' => EnterpriseWikiRunRecoveryResult::notRecoverable(
                    "Run [{$run->id}] has status [{$run->status}] — only status=failed or escalated is handled by this service."
                ),
                'failedPivots' => $none,
            ];
        }

        if ($run->maintainer_decision_status !== EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED) {
            return [
                'result' => EnterpriseWikiRunRecoveryResult::notRecoverable(
                    "Run [{$run->id}] has maintainer_decision_status [".($run->maintainer_decision_status ?? 'null').
                    '] — only a run whose decision was already applied has pages to retry.'
                ),
                'failedPivots' => $none,
            ];
        }

        $pivots = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->with('page')
            ->get();

        $decisionJson = (array) ($run->maintainer_decision_json ?? []);

        // A failed row for a page the decision only said to leave alone is history, not a failed
        // page (EnterpriseWikiIngestRunPage::isFailedNoChangePatch()): it is neither retried nor
        // counted among the other pages that must be settled.
        $failed = EnterpriseWikiIngestRunPage::outstandingFailures($pivots, $decisionJson);

        if ($failed->isEmpty()) {
            return [
                'result' => EnterpriseWikiRunRecoveryResult::notRecoverable(
                    "Run [{$run->id}] has no page(s) with generation_status=failed that still need doing — nothing for this service to retry."
                ),
                'failedPivots' => $none,
            ];
        }

        $others = $pivots->reject(
            fn (EnterpriseWikiIngestRunPage $p): bool => $p->generation_status === EnterpriseWikiIngestRunPage::GENERATION_STATUS_FAILED
        );

        // A patch is not part of either generation wave: the continuation applies it.
        $failedHasInitialWavePage = $failed->contains(
            fn (EnterpriseWikiIngestRunPage $p): bool => ! self::isPatchPivot($p) && $this->isInitialWavePage($p->page, $decisionJson)
        );

        // Every other page must be settled. The one legitimate exception: when the whole initial
        // wave failed, the deferred concept/entity pages were never dispatched and are still
        // pending — the ordinary finalizer dispatches them once the retried initial wave settles.
        if ($others->contains(fn (EnterpriseWikiIngestRunPage $p): bool => ! $this->isSettledOrAwaitingDeferredDispatch(
            $p,
            $failedHasInitialWavePage,
            $decisionJson,
        ))) {
            return [
                'result' => EnterpriseWikiRunRecoveryResult::notRecoverable(
                    "Run [{$run->id}] has page(s) still pending or running generation — refusing to retry while ".
                    'another page is still in flight.'
                ),
                'failedPivots' => $none,
            ];
        }

        if ($failed->contains(fn (EnterpriseWikiIngestRunPage $p): bool => $p->generated_page_version_id !== null)) {
            return [
                'result' => EnterpriseWikiRunRecoveryResult::notRecoverable(
                    "Run [{$run->id}] has a failed page that already has a generated_page_version_id — ".
                    'inconsistent state, refusing to retry.'
                ),
                'failedPivots' => $none,
            ];
        }

        // Decision D3: never offer a retry that can only repeat a known plan defect. A transient or
        // unrecognised failure may be retried — by a person only (see the class docblock); the UI
        // says which of the two it is (failureKind()).
        if ($failed->contains(fn (EnterpriseWikiIngestRunPage $p): bool => self::failureKindForPivot($p) === self::FAILURE_PLAN_DEFECT)) {
            return [
                'result' => EnterpriseWikiRunRecoveryResult::notRecoverable(
                    "Run [{$run->id}] has a page that failed on a plan defect — retrying would repeat it.",
                    [],
                    EnterpriseWikiRunRecoveryResult::CODE_PLAN_DEFECT,
                ),
                'failedPivots' => $none,
            ];
        }

        // A page in review right now refuses every automated write before any AI call
        // (EnterpriseWikiGenerateAppliedPagesService::guardAgainstOpenReview()), so a retry could
        // only record the same deferral again. Read from the page as it is now, not from the stored
        // error: a review opened after the failure blocks the retry just the same, and a closed one
        // no longer does.
        if ($failed->contains(fn (EnterpriseWikiIngestRunPage $p): bool => $p->page?->status === EnterpriseWikiPage::STATUS_PENDING_REVIEW)) {
            return [
                'result' => EnterpriseWikiRunRecoveryResult::notRecoverable(
                    "Run [{$run->id}] has a failed page that is in review — its update waits until the review is closed.",
                    [],
                    EnterpriseWikiRunRecoveryResult::CODE_PAGE_UNDER_REVIEW,
                ),
                'failedPivots' => $none,
            ];
        }

        return [
            'result' => EnterpriseWikiRunRecoveryResult::resumed(
                "Run [{$run->id}] has {$failed->count()} failed page-generation job(s) with no plan defect and every ".
                'other page settled — safe to re-dispatch only the failed page(s), reusing the same run_id, '.
                'document_id, and page_id(s).'
            ),
            'failedPivots' => $failed,
        ];
    }

    /**
     * What a recorded page-generation error says about a retry: a plan defect repeats by
     * construction; a review deferral is no fault at all; a transient failure is one
     * EnterpriseWikiTransientFailureClassifier's allow-list recognises; anything else is unknown —
     * never assumed to be either.
     *
     * @return self::FAILURE_*
     */
    public static function failureKind(?string $generationError): string
    {
        // An update deferred by an open review is its own kind, not a temporary technical fault:
        // the page is intact and the update waits for the review, which only a person closes. A
        // retry while the review is still open is refused (decide()); after it, a person's retry is
        // the way to apply it. Kept out of EnterpriseWikiTransientFailureClassifier on purpose —
        // that allow-list also drives automatic retries, and nothing here should loop against a
        // review.
        if ($generationError !== null && str_contains($generationError, self::REVIEW_DEFERRAL_MARKER)) {
            return self::FAILURE_REVIEW_DEFERRED;
        }

        foreach (self::PLAN_DEFECT_MARKERS as $marker) {
            if ($generationError !== null && str_contains($generationError, $marker)) {
                return self::FAILURE_PLAN_DEFECT;
            }
        }

        return EnterpriseWikiTransientFailureClassifier::isTransient($generationError)
            ? self::FAILURE_TRANSIENT
            : self::FAILURE_UNKNOWN;
    }

    /**
     * failureKind() for one failed row, aware of what kind of work the row records.
     *
     * A generated page is read from its error, as always. A patch row is not: the patch engine is
     * the only thing that ever applies it, and it records a failure for exactly one reason — the
     * page was in review — or, since a retry now re-runs it, because the patch no longer applies to
     * the page as it is now (PATCH_APPLICATION_FAILURE_MARKER, a stale plan). The generation guard's
     * refusal on a patch row (run 963) says only that an earlier retry sent it to the wrong job:
     * the patch itself has still not been tried, so it is the same waiting update as before.
     *
     * Callers judging a run skip isFailedNoChangePatch() rows first; this does not repeat that.
     *
     * @return self::FAILURE_*
     */
    public static function failureKindForPivot(EnterpriseWikiIngestRunPage $pivot): string
    {
        if (! self::isPatchPivot($pivot)) {
            return self::failureKind($pivot->generation_error);
        }

        $error = (string) $pivot->generation_error;

        if (str_contains($error, self::PATCH_APPLICATION_FAILURE_MARKER)) {
            return self::FAILURE_PLAN_DEFECT;
        }

        if (str_contains($error, self::REVIEW_DEFERRAL_MARKER) || str_contains($error, self::REGENERATION_BLOCKED_MARKER)) {
            return self::FAILURE_REVIEW_DEFERRED;
        }

        return self::FAILURE_UNKNOWN;
    }

    private static function isPatchPivot(EnterpriseWikiIngestRunPage $pivot): bool
    {
        return $pivot->action === EnterpriseWikiIngestRunPage::ACTION_PATCHED;
    }

    /**
     * @param  array<string, mixed>  $decisionJson
     */
    private function isSettledOrAwaitingDeferredDispatch(EnterpriseWikiIngestRunPage $pivot, bool $failedHasInitialWavePage, array $decisionJson): bool
    {
        if ($pivot->generation_status === EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED) {
            return true;
        }

        return $failedHasInitialWavePage
            && $pivot->generation_status === EnterpriseWikiIngestRunPage::GENERATION_STATUS_PENDING
            && $pivot->generation_dispatched_at === null
            && ! $this->isInitialWavePage($pivot->page, $decisionJson);
    }

    /**
     * Resets only the failed pivot(s) back to their original at-creation state
     * (generation_status=pending, no started/completed timestamp, no error) and re-dispatches
     * GenerateEnterpriseWikiAppliedPage for each — completed pivots are never read or written
     * here. The run's status is set back to whichever phase the failed page(s) belong to, so
     * FinalizeEnterpriseWikiPageGeneration's own two-phase match() continues to work unmodified
     * once the retried job(s) finish.
     *
     * @param  Collection<int, EnterpriseWikiIngestRunPage>  $failedPivots
     */
    private function resume(EnterpriseWikiIngestRun $run, Collection $failedPivots): void
    {
        // A deferred patch is never handed to page generation: that job regenerates a page from the
        // source alone and refuses a patch target by design (run 963). The patch engine applies it,
        // and it runs in the continuation after pages (EnterpriseWikiDocumentFlowService::
        // performApplyPatchTargets()), from the stored plan, against the page as it is now. Its row
        // is left as it is — reset to pending it would be picked up as generation work — and the
        // engine overwrites it once the patch is written, or records why it no longer applies.
        $patchPivots = $failedPivots->filter(fn (EnterpriseWikiIngestRunPage $p): bool => self::isPatchPivot($p));
        $generationPivots = $failedPivots->reject(fn (EnterpriseWikiIngestRunPage $p): bool => self::isPatchPivot($p));

        if ($generationPivots->isEmpty()) {
            $this->resumePatchesOnly($run, $patchPivots);

            return;
        }

        $failedPivots = $generationPivots;

        $decisionJson = (array) ($run->maintainer_decision_json ?? []);
        $initialWaveFailed = $failedPivots->filter(
            fn (EnterpriseWikiIngestRunPage $p): bool => $this->isInitialWavePage($p->page, $decisionJson)
        );

        // Failures in both waves: only the initial wave is retried directly. The deferred
        // concept/entity pages are reset to pending and dispatched by
        // FinalizeEnterpriseWikiPageGeneration once the initial wave settles — the same, single
        // dispatch point a first attempt uses, so no deferred page is ever enqueued twice.
        $targetStatus = $initialWaveFailed->isNotEmpty()
            ? EnterpriseWikiIngestRun::STATUS_GENERATING_PAGES
            : EnterpriseWikiIngestRun::STATUS_GENERATING_CONCEPT_ENTITY_PAGES;
        $dispatchNow = $initialWaveFailed->isNotEmpty() ? $initialWaveFailed : $failedPivots;

        $runUpdate = [
            'status' => $targetStatus,
            'finished_at' => null,
            'error_message' => null,
            'failed_phase' => null,
            'transient_failure' => null,
        ];

        // A partially completed run already carries a QA verdict for a page set that is about to
        // change. Clearing it lets the resumed flow run QA again, exactly as on a first attempt;
        // qa_result and qa_last_error stay until that new verdict replaces them.
        if ($run->qa_status !== null) {
            $runUpdate['qa_status'] = null;
        }

        $run->update($runUpdate);

        foreach ($failedPivots as $pivot) {
            $pivot->update([
                'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_PENDING,
                'generation_dispatched_at' => null,
                'generation_claimed_at' => null,
                'generation_claim_token' => null,
                'generation_started_at' => null,
                'generation_completed_at' => null,
                'generation_error' => null,
            ]);
        }

        foreach ($dispatchNow as $pivot) {
            // Same atomic reservation every other dispatch goes through.
            if ($this->generateAppliedPagesService->reservePageForDispatch($run->id, $pivot->enterprise_wiki_page_id)) {
                GenerateEnterpriseWikiAppliedPage::dispatch($run->id, $pivot->enterprise_wiki_page_id);
            }
        }

        // The same crash sentinel a first attempt starts with.
        FinalizeEnterpriseWikiPageGeneration::dispatch($run->id, true)->delay(now()->addSeconds(self::FINALIZE_SENTINEL_DELAY_SECONDS));
    }

    /**
     * Only deferred patches failed: no page needs generating, so the run goes straight back to the
     * continuation that applies patches — the same status and the same job
     * EnterpriseWikiEscalatedRunRecoveryService::resume() reopens a run with. The patch engine is
     * idempotent per page (a row that already carries a version is skipped), plans against the
     * current version, writes under the page lock and refuses a page in review, so re-running the
     * continuation applies each waiting patch at most once. No AI call is involved in the patch.
     *
     * @param  Collection<int, EnterpriseWikiIngestRunPage>  $patchPivots
     */
    private function resumePatchesOnly(EnterpriseWikiIngestRun $run, Collection $patchPivots): void
    {
        $run->update([
            'status' => EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING,
            'finished_at' => null,
            'error_message' => null,
            'failed_phase' => null,
            'transient_failure' => null,
            'qa_status' => $run->qaStatusForResumedContinuation(),
        ]);

        Log::info('[WIKI_RUN_RECOVERY] Deferred patch(es) handed back to the continuation — no page generation.', [
            'run_id' => $run->id,
            'patched_page_ids' => $patchPivots->pluck('enterprise_wiki_page_id')->all(),
        ]);

        ContinueEnterpriseWikiDocumentFlowAfterPages::dispatch($run->id);
    }

    /**
     * @param  array<string, mixed>  $decisionJson
     */
    private function isInitialWavePage(?EnterpriseWikiPage $page, array $decisionJson): bool
    {
        if (! $page instanceof EnterpriseWikiPage || ! in_array($page->page_type, self::INITIAL_WAVE_TYPES, true)) {
            return false;
        }

        if ($page->page_type !== EnterpriseWikiPage::PAGE_TYPE_CONCEPT) {
            return true;
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
}
