<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiIngestRun;
use App\Models\User;
use App\Support\HumanDecision;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * «Fjern fra oversikten» — whether a Wiki run may be cleared from Kjøringer, and clearing it.
 *
 * One rule, read both for the row's `can_dismiss` and again by the request that clears it, so the
 * button can never offer what the server would refuse. Who may do it is the caller's business —
 * the same authorization as «Avbryt kjøring» (WikiController) — on top of this.
 *
 * A run may be cleared once it is finished and nothing about it still needs anyone:
 *   - failed, cancelled or completed: finished. Terminal runs are never picked up by scheduled QA
 *     (EnterpriseWikiPostIngestQaService only reads non-terminal runs), so nothing is waiting.
 *   - escalated: only when EnterpriseWikiRunFindingsService::escalationFollowUpComplete() says
 *     so — QA's own follow-up state is complete and no page failed to generate. Any
 *     other escalation — unfinished steps, other quality findings, open findings, a deferred page —
 *     reads as still open, and an uncertain case is never cleared.
 *   - anything else is still running or waiting for a person, and stays.
 *
 * Clearing only sets dismissed_at/dismissed_by_user_id. The run's status, results and updated_at
 * stay as they were, and nothing that refers to the run is touched.
 */
class EnterpriseWikiRunDismissalService
{
    public const REFUSAL_NOT_FINISHED = 'not_finished';

    public const REFUSAL_FOLLOW_UP_OPEN = 'follow_up_open';

    public function __construct(
        private readonly EnterpriseWikiRunFindingsService $runFindingsService,
        private readonly EnterpriseWikiSourceAuthority $sourceAuthority,
    ) {}

    /**
     * Why the run may not be cleared, or null when it may. A run already cleared is not refused:
     * clearing it again changes nothing.
     *
     * @param  array<string, mixed>|null  $findingsSummary  EnterpriseWikiRunFindingsService::buildForRun()['summary'],
     *                                                      when the caller already has it
     */
    public function refusal(EnterpriseWikiIngestRun $run, ?array $findingsSummary = null): ?string
    {
        if (! in_array($run->status, EnterpriseWikiIngestRun::DISMISSABLE_STATUSES, true)
            || ! $run->isTerminal()
            || $run->expectsAutomaticProgress()
        ) {
            return self::REFUSAL_NOT_FINISHED;
        }

        if ($run->status !== EnterpriseWikiIngestRun::STATUS_ESCALATED) {
            return null;
        }

        return $this->runFindingsService->escalationFollowUpComplete($run, $findingsSummary)
            ? null
            : self::REFUSAL_FOLLOW_UP_OPEN;
    }

    /**
     * Clear the run from Kjøringer. Returns false when it already was (the first clearing is kept),
     * true when this call cleared it. The rule is checked again under a row lock, so a run that
     * started moving or reopened after the page was rendered is refused.
     *
     * @throws DomainException when the run may not be cleared (refusal() says why)
     */
    public function dismiss(EnterpriseWikiIngestRun $run, User $actor): bool
    {
        $this->sourceAuthority->assertMayManageRun($actor, $run, HumanDecision::WIKI_RUN_DISMISS);

        return DB::transaction(function () use ($run, $actor): bool {
            $locked = EnterpriseWikiIngestRun::query()->lockForUpdate()->findOrFail($run->id);

            if ($locked->isDismissed()) {
                return false;
            }

            $refusal = $this->refusal($locked);

            if ($refusal !== null) {
                throw new DomainException($refusal);
            }

            // toBase(): visibility only — updated_at is the run's own progress time and stays.
            EnterpriseWikiIngestRun::query()->whereKey($locked->id)->toBase()->update([
                'dismissed_at' => now(),
                'dismissed_by_user_id' => $actor->id,
            ]);

            return true;
        });
    }
}
