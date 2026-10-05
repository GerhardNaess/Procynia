<?php

namespace App\Services\Improvements;

use App\Models\ImprovementAction;
use App\Models\ImprovementCase;
use App\Models\ImprovementCaseStatusChange;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Start behandling, Lukk, Avbryt and Gjenåpne — the only way a case's status changes.
 *
 *   open → in_progress              start()   no note
 *   open | in_progress → closed     close()   resultat / avsluttende kommentar required, and
 *                                             every tiltak completed or cancelled
 *   open | in_progress → cancelled  cancel()  begrunnelse required
 *   closed | cancelled → open       reopen()  begrunnelse required
 *
 * Two records, kept apart on purpose: the case's status and closed_* columns are its current state
 * (the latest ending only), and ImprovementCaseStatusChange rows are the history, never changed.
 * Each change writes both in one transaction, with the case row locked and its status checked again
 * inside the lock, so a double submit or two people at once cannot write the same change twice. A
 * reopening clears closed_* because they describe an ending that no longer stands; the ending
 * itself, note and all, stays in its history row.
 *
 * Authorization is the caller's (improvement.edit for start, improvement.close for the rest, in the
 * case's area). This only guards the transition itself.
 */
class ImprovementCaseLifecycleService
{
    public function start(ImprovementCase $case, User $actor): ImprovementCase
    {
        return $this->transition($case, $actor, [ImprovementCase::STATUS_OPEN], ImprovementCase::STATUS_IN_PROGRESS, null, 'status');
    }

    public function close(ImprovementCase $case, User $actor, ?string $result): ImprovementCase
    {
        return $this->transition($case, $actor, ImprovementCase::ACTIVE_STATUSES, ImprovementCase::STATUS_CLOSED, $this->required($result, 'closing_note'), 'closing_note');
    }

    public function cancel(ImprovementCase $case, User $actor, ?string $reason): ImprovementCase
    {
        return $this->transition($case, $actor, ImprovementCase::ACTIVE_STATUSES, ImprovementCase::STATUS_CANCELLED, $this->required($reason, 'reason'), 'reason');
    }

    public function reopen(ImprovementCase $case, User $actor, ?string $reason): ImprovementCase
    {
        return $this->transition($case, $actor, ImprovementCase::ENDED_STATUSES, ImprovementCase::STATUS_OPEN, $this->required($reason, 'reason'), 'reason');
    }

    /**
     * @param  list<string>  $from  the statuses the case may be in for this change
     */
    private function transition(ImprovementCase $case, User $actor, array $from, string $to, ?string $note, string $errorField): ImprovementCase
    {
        return DB::transaction(function () use ($case, $actor, $from, $to, $note, $errorField): ImprovementCase {
            $locked = ImprovementCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, $from, true)) {
                throw ValidationException::withMessages([
                    $errorField => __('procynia.improvements.validation.transition_not_allowed'),
                ]);
            }

            // A case is closed once its tiltak are done with. Read inside the case lock: the tiltak
            // lifecycle takes the same lock first, so none can be reopened or added meanwhile.
            if ($to === ImprovementCase::STATUS_CLOSED && $this->hasUnfinishedActions($locked)) {
                throw ValidationException::withMessages([
                    $errorField => __('procynia.improvements.validation.actions_not_finished'),
                ]);
            }

            $now = now();

            ImprovementCaseStatusChange::query()->create([
                'customer_id' => (int) $locked->customer_id,
                'improvement_case_id' => (int) $locked->id,
                'from_status' => $locked->status,
                'to_status' => $to,
                'note' => $note,
                'changed_by_user_id' => (int) $actor->id,
                'changed_at' => $now,
            ]);

            $ending = in_array($to, ImprovementCase::ENDED_STATUSES, true);

            $locked->forceFill([
                'status' => $to,
                // The current ending only. Starting leaves none; reopening clears the one undone.
                'closed_at' => $ending ? $now : null,
                'closed_by_user_id' => $ending ? (int) $actor->id : null,
                'closing_note' => $ending ? $note : null,
                'updated_by' => (int) $actor->id,
            ])->save();

            return $locked;
        });
    }

    private function hasUnfinishedActions(ImprovementCase $case): bool
    {
        return ImprovementAction::query()
            ->where('improvement_case_id', (int) $case->id)
            ->whereIn('status', ImprovementAction::ACTIVE_STATUSES)
            ->exists();
    }

    private function required(?string $value, string $field): string
    {
        $text = trim((string) $value);

        if ($text === '') {
            throw ValidationException::withMessages([
                $field => __('procynia.improvements.validation.'.($field === 'closing_note' ? 'result_required' : 'reason_required')),
            ]);
        }

        return $text;
    }
}
