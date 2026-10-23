<?php

namespace App\Services;

use App\Models\SavedNotice;
use App\Models\SavedNoticeStatusChange;
use App\Models\User;
use App\Services\Security\HumanDecisionGuard;
use App\Support\HumanDecision;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * The one way a case's bid status changes: Kvalifisering, Go / No-Go, Go (I arbeid), No-Go,
 * Levert, Forhandling, Vunnet, Tapt, Trukket — and reopening a No-Go. Each is a person's decision
 * about the bid, so each:
 * - passes HumanDecisionGuard (TENDER_BID_STATUS): never an integration, the API or nobody;
 * - applies the rule the page applies, on the case as it stands under lock — the right to work on
 *   the case (SavedNoticeAccessService::canManage; for reopening, canReopenAfterNoGo), the actor's
 *   own customer, and a customer holding Anbud;
 * - writes a saved_notice_status_changes row naming the person, in the same transaction.
 *
 * The transition rules themselves stay in SavedNotice::transitionBidStatus(), and the No-Go record
 * in SavedNoticeNoGoDecisionService; this service is their only caller.
 */
class SavedNoticeStatusService
{
    public function __construct(
        private readonly HumanDecisionGuard $guard,
        private readonly SavedNoticeAccessService $access,
        private readonly SavedNoticeNoGoDecisionService $noGoDecisions,
    ) {}

    /** May this person change the bid status of this active case? Read by the controller too. */
    public function mayChangeStatus(User $actor, SavedNotice $notice): bool
    {
        return $notice->archived_at === null
            && $this->inCustomerWithTender($actor, $notice)
            && $this->access->canManage($actor, $notice);
    }

    public function mayReopenAfterNoGo(User $actor, SavedNotice $notice): bool
    {
        return $this->inCustomerWithTender($actor, $notice)
            && $this->access->canReopenAfterNoGo($actor, $notice);
    }

    /**
     * @throws \InvalidArgumentException when the transition is not allowed from the current status
     */
    public function transition(
        User $actor,
        SavedNotice $notice,
        string $toStatus,
        ?string $closureReason = null,
        ?string $closureNote = null,
    ): SavedNotice {
        $actor = $this->guard->assert($actor, HumanDecision::TENDER_BID_STATUS);

        return DB::transaction(function () use ($actor, $notice, $toStatus, $closureReason, $closureNote): SavedNotice {
            $locked = SavedNotice::query()->with('customer')->lockForUpdate()->findOrFail($notice->getKey());

            if (! $this->mayChangeStatus($actor, $locked)) {
                throw new AuthorizationException('This person may not change the status of this case.');
            }

            $fromStatus = $locked->bid_status;
            $wasArchived = $locked->archived_at !== null;

            if ($toStatus === SavedNotice::BID_STATUS_NO_GO) {
                $locked = $this->noGoDecisions->closeAsNoGo($locked, $actor, (string) $closureReason, $closureNote);
            } else {
                $locked->transitionBidStatus($toStatus, $closureReason, $closureNote)->save();
            }

            // A transition that moves the case to history names who did it, as «Flytt til historikk» does.
            if (! $wasArchived && $locked->archived_at !== null) {
                $locked->forceFill(['archived_by_user_id' => $actor->id])->save();
            }

            $this->record($locked, $fromStatus, $actor, $locked->bid_closure_reason, $locked->bid_closure_note);

            return $locked;
        });
    }

    /**
     * @throws \InvalidArgumentException when the case is no longer No-Go, or the reopening is not confirmed or reasoned
     */
    public function reopenAfterNoGo(User $actor, SavedNotice $notice, string $reason, bool $confirmed): SavedNotice
    {
        $actor = $this->guard->assert($actor, HumanDecision::TENDER_BID_STATUS);

        return DB::transaction(function () use ($actor, $notice, $reason, $confirmed): SavedNotice {
            $locked = SavedNotice::query()->with('customer')->lockForUpdate()->findOrFail($notice->getKey());

            if (! $this->mayReopenAfterNoGo($actor, $locked)) {
                throw new AuthorizationException('This person may not reopen this case.');
            }

            $fromStatus = $locked->bid_status;
            $locked = $this->noGoDecisions->reopenAfterNoGo($locked, $actor, $reason, $confirmed);

            $this->record($locked, $fromStatus, $actor, trim($reason), null);

            return $locked;
        });
    }

    private function inCustomerWithTender(User $actor, SavedNotice $notice): bool
    {
        return $actor->customer_id !== null
            && (int) $actor->customer_id === (int) $notice->customer_id
            && (bool) $notice->customer?->hasModule('tender');
    }

    private function record(SavedNotice $notice, ?string $fromStatus, User $actor, ?string $reason, ?string $note): void
    {
        SavedNoticeStatusChange::query()->create([
            'saved_notice_id' => $notice->id,
            'customer_id' => $notice->customer_id,
            'from_status' => $fromStatus,
            'to_status' => $notice->bid_status,
            'changed_by_user_id' => $actor->id,
            'reason' => $reason,
            'note' => $note,
            'changed_at' => now(),
        ]);
    }
}
