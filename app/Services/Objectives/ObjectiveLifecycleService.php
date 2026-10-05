<?php

namespace App\Services\Objectives;

use App\Models\Objective;
use App\Models\ObjectiveStatusChange;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Lukk mål and Gjenåpne — the only way an objective's status changes.
 *
 * Two records, kept apart on purpose:
 *
 *  - The objective's status and closed_* columns are its current state. Lists and the page read
 *    them, and they describe the latest closing only.
 *  - ObjectiveStatusChange rows are the history, one per closing and per reopening, never changed.
 *
 * Each change writes both in one transaction, with the objective row locked and its status checked
 * again inside the lock, so a double submit or two people at once cannot close an objective twice.
 * A reopening clears closed_* (closing_note included) because they describe a closing that no
 * longer stands; the closing itself, note and all, stays in its history row.
 *
 * Authorization (objective.edit in the objective's area) is the caller's. This only guards the
 * transition itself.
 */
class ObjectiveLifecycleService
{
    public function close(Objective $objective, User $actor, string $outcome, ?string $note): Objective
    {
        if (! in_array($outcome, Objective::CLOSED_STATUSES, true)) {
            throw new InvalidArgumentException("Not a closing outcome [{$outcome}].");
        }

        $note = $this->normalized($note);

        return DB::transaction(function () use ($objective, $actor, $outcome, $note): Objective {
            $locked = $this->lock($objective);

            if (! $locked->isActive()) {
                throw ValidationException::withMessages([
                    'status' => __('procynia.objectives.validation.already_closed'),
                ]);
            }

            $now = now();

            ObjectiveStatusChange::query()->create([
                'customer_id' => (int) $locked->customer_id,
                'objective_id' => (int) $locked->id,
                'from_status' => $locked->status,
                'to_status' => $outcome,
                'note' => $note,
                'changed_by_user_id' => (int) $actor->id,
                'changed_at' => $now,
            ]);

            $locked->forceFill([
                'status' => $outcome,
                'closed_at' => $now,
                'closed_by_user_id' => (int) $actor->id,
                'closing_note' => $note,
                'updated_by' => (int) $actor->id,
            ])->save();

            return $locked;
        });
    }

    public function reopen(Objective $objective, User $actor, string $reason): Objective
    {
        $reason = $this->normalized($reason);

        if ($reason === null) {
            throw ValidationException::withMessages([
                'reason' => __('procynia.objectives.validation.reopen_reason_required'),
            ]);
        }

        return DB::transaction(function () use ($objective, $actor, $reason): Objective {
            $locked = $this->lock($objective);

            if ($locked->isActive()) {
                throw ValidationException::withMessages([
                    'reason' => __('procynia.objectives.validation.already_active'),
                ]);
            }

            // History first: the closing being undone is already in its own row, and this row says
            // who undid it, when and why.
            ObjectiveStatusChange::query()->create([
                'customer_id' => (int) $locked->customer_id,
                'objective_id' => (int) $locked->id,
                'from_status' => $locked->status,
                'to_status' => Objective::STATUS_ACTIVE,
                'note' => $reason,
                'changed_by_user_id' => (int) $actor->id,
                'changed_at' => now(),
            ]);

            $locked->forceFill([
                'status' => Objective::STATUS_ACTIVE,
                'closed_at' => null,
                'closed_by_user_id' => null,
                'closing_note' => null,
                'updated_by' => (int) $actor->id,
            ])->save();

            return $locked;
        });
    }

    private function lock(Objective $objective): Objective
    {
        return Objective::query()->whereKey($objective->id)->lockForUpdate()->firstOrFail();
    }

    private function normalized(?string $value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }
}
