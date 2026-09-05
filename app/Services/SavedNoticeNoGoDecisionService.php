<?php

namespace App\Services;

use App\Models\SavedNotice;
use App\Models\SavedNoticeNoGoDecision;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SavedNoticeNoGoDecisionService
{
    public function closeAsNoGo(
        SavedNotice $notice,
        User $actor,
        string $closureReason,
        ?string $closureNote = null,
    ): SavedNotice {
        return DB::transaction(function () use ($notice, $actor, $closureReason, $closureNote): SavedNotice {
            /** @var SavedNotice $lockedNotice */
            $lockedNotice = SavedNotice::query()->lockForUpdate()->findOrFail($notice->getKey());

            $lockedNotice->transitionBidStatus(
                SavedNotice::BID_STATUS_NO_GO,
                $closureReason,
                $closureNote,
            )->save();

            SavedNoticeNoGoDecision::query()->create([
                'saved_notice_id' => $lockedNotice->id,
                'customer_id' => $lockedNotice->customer_id,
                'closed_by_user_id' => $actor->id,
                'closure_reason' => $lockedNotice->bid_closure_reason,
                'closure_note' => $lockedNotice->bid_closure_note,
                'closed_at' => $lockedNotice->bid_closed_at,
            ]);

            return $lockedNotice;
        });
    }

    public function reopenAfterNoGo(
        SavedNotice $notice,
        User $actor,
        string $reopenReason,
        bool $confirmed,
    ): SavedNotice {
        $normalizedReason = trim($reopenReason);

        if (! $confirmed) {
            throw new \InvalidArgumentException('No-Go reopening must be explicitly confirmed.');
        }

        if ($normalizedReason === '') {
            throw new \InvalidArgumentException('A reason is required when reopening a No-Go case.');
        }

        return DB::transaction(function () use ($notice, $actor, $normalizedReason): SavedNotice {
            /** @var SavedNotice $lockedNotice */
            $lockedNotice = SavedNotice::query()->lockForUpdate()->findOrFail($notice->getKey());

            if ($lockedNotice->bid_status !== SavedNotice::BID_STATUS_NO_GO) {
                throw new \InvalidArgumentException('Only No-Go cases can be reopened.');
            }

            /** @var SavedNoticeNoGoDecision|null $decision */
            $decision = SavedNoticeNoGoDecision::query()
                ->where('saved_notice_id', $lockedNotice->id)
                ->whereNull('reopened_at')
                ->orderByDesc('closed_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            // Covers legacy rows that predate the audit table but have not yet been backfilled.
            $decision ??= SavedNoticeNoGoDecision::query()->create([
                'saved_notice_id' => $lockedNotice->id,
                'customer_id' => $lockedNotice->customer_id,
                'closed_by_user_id' => null,
                'closure_reason' => $lockedNotice->bid_closure_reason,
                'closure_note' => $lockedNotice->bid_closure_note,
                'closed_at' => $lockedNotice->bid_closed_at,
            ]);

            $wasArchivedAt = $lockedNotice->archived_at;
            $previousHistoryType = $lockedNotice->history_type;

            $decision->forceFill([
                'reopened_by_user_id' => $actor->id,
                'reopen_reason' => $normalizedReason,
                'reopened_at' => now(),
                'reopened_from_archived_at' => $wasArchivedAt,
                'reopened_from_history_type' => $previousHistoryType,
            ])->save();

            $lockedNotice->forceFill([
                'bid_status' => SavedNotice::BID_STATUS_GO_NO_GO,
                'bid_closure_reason' => null,
                'bid_closure_note' => null,
                'bid_closed_at' => null,
                'archived_at' => null,
                'history_type' => null,
            ])->save();

            return $lockedNotice;
        });
    }
}
