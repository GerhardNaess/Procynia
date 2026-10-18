<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiIngestRun;

/**
 * The one place an Enterprise Wiki ingest run becomes terminal (completed, escalated, failed,
 * cancelled).
 *
 * Every terminal write used to be its own `->nonTerminal()->update([...])` (or a plain
 * `$run->update([...])`) spread over the flow service, the page-generation finalizer, the job
 * failure handlers and the legacy section pipeline. They agreed on the guard by convention only,
 * and a query-builder update fires no Eloquent event, so nothing could observe "this run just
 * finished" exactly once. This class makes that one observable fact explicit:
 *
 *   - The transition is a single conditional UPDATE … WHERE status NOT IN (terminal). Postgres
 *     re-evaluates the WHERE clause against the committed row when two writers race, so exactly
 *     one caller ever sees an affected row. That caller — and only that caller — owns the
 *     transition. Every later or concurrent attempt is a no-op that returns false and leaves the
 *     already-recorded status, error_message, failed_phase and qa fields untouched.
 *   - afterTerminal() runs only for the owning caller, right after the UPDATE: inside the caller's
 *     transaction when it holds one (cancelRun, the page-generation finalizer, the legacy
 *     finalizer), otherwise after the status has already committed on its own. It is the extension
 *     point for the run's final notification and result snapshot
 *     (docs/enterprise-wiki-knowledge-integration-plan.md, arbeidspakke C).
 *
 *     It runs at most once and is never retried: a later attempt sees a terminal row and returns
 *     false. Anything that must happen for every terminal run — the final notification in
 *     particular — can therefore not live here alone, nor behind DB::afterCommit(): a crash
 *     between the commit and the callback loses it for good. The plan's §9.3 records how C makes
 *     that delivery recoverable. Whatever is added here must not throw either: inside a caller's
 *     transaction an exception rolls the transition back.
 *
 * Moving a run OUT of a terminal status (recovery, retry) is deliberately not this class's
 * concern: those paths lock the run and decide for themselves, and the next terminal transition
 * goes through here again.
 *
 * tests/Feature/App/Wiki/EnterpriseWikiRunTerminalTransitionArchitectureTest.php keeps every
 * other terminal write out of app/.
 */
class EnterpriseWikiRunTerminalTransition
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function complete(int $runId, array $attributes = []): bool
    {
        return $this->apply($runId, EnterpriseWikiIngestRun::STATUS_COMPLETED, $attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function escalate(int $runId, array $attributes = []): bool
    {
        return $this->apply($runId, EnterpriseWikiIngestRun::STATUS_ESCALATED, $attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function fail(int $runId, array $attributes = []): bool
    {
        return $this->apply($runId, EnterpriseWikiIngestRun::STATUS_FAILED, $attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function cancel(int $runId, array $attributes = []): bool
    {
        return $this->apply($runId, EnterpriseWikiIngestRun::STATUS_CANCELLED, $attributes);
    }

    /**
     * Extension point for the owning caller of a terminal transition. Intentionally empty until
     * arbeidspakke C: it must not change today's behaviour.
     */
    protected function afterTerminal(int $runId, string $status): void {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function apply(int $runId, string $status, array $attributes): bool
    {
        unset($attributes['status']);

        $updated = EnterpriseWikiIngestRun::query()
            ->whereKey($runId)
            ->nonTerminal()
            ->update(['status' => $status] + $attributes + ['finished_at' => now()]);

        if ($updated === 0) {
            return false;
        }

        $this->afterTerminal($runId, $status);

        return true;
    }
}
