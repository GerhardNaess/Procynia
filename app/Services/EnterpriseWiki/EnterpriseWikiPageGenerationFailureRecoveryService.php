<?php

namespace App\Services\EnterpriseWiki;

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
 * Resuming never touches a completed pivot: only the failed pivot(s) are reset to
 * generation_status=pending (clearing generation_started_at/generation_completed_at/
 * generation_error back to their original at-creation defaults) and re-dispatched. Once those jobs
 * finish (success or failure), GenerateEnterpriseWikiAppliedPage's own existing completion/failure
 * handling already dispatches FinalizeEnterpriseWikiPageGeneration exactly as it does for a fresh
 * run — that finalize logic is untouched and needs no awareness that this was a recovery.
 */
class EnterpriseWikiPageGenerationFailureRecoveryService
{
    private const INITIAL_WAVE_TYPES = [
        EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
        EnterpriseWikiPage::PAGE_TYPE_SUMMARY,
        EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
    ];

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
            EnterpriseWikiIngestRun::STATUS_ESCALATED,
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

        if ($run->status !== EnterpriseWikiIngestRun::STATUS_FAILED) {
            return [
                'result' => EnterpriseWikiRunRecoveryResult::notRecoverable(
                    "Run [{$run->id}] has status [{$run->status}] — only status=failed is handled by this service."
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

        $failed = $pivots->where('generation_status', EnterpriseWikiIngestRunPage::GENERATION_STATUS_FAILED);

        if ($failed->isEmpty()) {
            return [
                'result' => EnterpriseWikiRunRecoveryResult::notRecoverable(
                    "Run [{$run->id}] has no page(s) with generation_status=failed — nothing for this service to retry."
                ),
                'failedPivots' => $none,
            ];
        }

        $others = $pivots->reject(
            fn (EnterpriseWikiIngestRunPage $p): bool => $p->generation_status === EnterpriseWikiIngestRunPage::GENERATION_STATUS_FAILED
        );

        if ($others->contains(
            fn (EnterpriseWikiIngestRunPage $p): bool => $p->generation_status !== EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED
        )) {
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

        $decisionJson = (array) ($run->maintainer_decision_json ?? []);
        $failedPhases = $failed
            ->map(fn (EnterpriseWikiIngestRunPage $p): string => $this->isInitialWavePage($p->page, $decisionJson) ? 'initial' : 'deferred')
            ->unique()
            ->values();

        if ($failedPhases->count() > 1) {
            return [
                'result' => EnterpriseWikiRunRecoveryResult::notRecoverable(
                    "Run [{$run->id}] has failed page(s) across both initial and deferred generation phases — ".
                    'refusing to retry a mixed-phase failure because it could duplicate deferred dispatch.'
                ),
                'failedPivots' => $none,
            ];
        }

        return [
            'result' => EnterpriseWikiRunRecoveryResult::resumed(
                "Run [{$run->id}] has {$failed->count()} failed page-generation job(s) with every other page ".
                'already completed — safe to re-dispatch only the failed page(s), reusing the same run_id, '.
                'document_id, and page_id(s).'
            ),
            'failedPivots' => $failed,
        ];
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
        $decisionJson = (array) ($run->maintainer_decision_json ?? []);
        $targetStatus = $failedPivots->contains(
            fn (EnterpriseWikiIngestRunPage $p): bool => $this->isInitialWavePage($p->page, $decisionJson)
        )
            ? EnterpriseWikiIngestRun::STATUS_GENERATING_PAGES
            : EnterpriseWikiIngestRun::STATUS_GENERATING_CONCEPT_ENTITY_PAGES;

        $run->update([
            'status' => $targetStatus,
            'finished_at' => null,
            'error_message' => null,
        ]);

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

            GenerateEnterpriseWikiAppliedPage::dispatch($run->id, $pivot->enterprise_wiki_page_id);
        }
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
