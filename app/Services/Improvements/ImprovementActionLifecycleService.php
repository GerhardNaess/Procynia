<?php

namespace App\Services\Improvements;

use App\Models\ImprovementAction;
use App\Models\ImprovementActionStatusChange;
use App\Models\ImprovementActionVerification;
use App\Models\ImprovementCase;
use App\Models\User;
use App\Services\Security\HumanDecisionGuard;
use App\Support\HumanDecision;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every write to a tiltak: Nytt tiltak, Rediger, Slett, and the lifecycle — the only way its status
 * changes.
 *
 *   (new) → planned                       create()    status is never taken from a form
 *   planned → in_progress                 start()     no note
 *   planned | in_progress → completed     complete()  «Hva ble gjort?» required
 *   planned | in_progress → cancelled     cancel()    begrunnelse required
 *   completed | cancelled → planned       reopen()    begrunnelse required
 *
 * And Effektverifisering, which is no status change: verify() judges whether the current completion
 * of a completed tiltak worked, with a comment, and leaves the tiltak completed either way. Ikke
 * effektivt does not reopen it — whether to work on is the person's decision (Gjenåpne tiltak).
 *
 * Two records, as for the case: the tiltak's status and completed_* are its current state (the
 * latest completion only), and ImprovementActionStatusChange rows are the history, never changed. A
 * reopening clears completed_* and completion_note because they describe a completion that no
 * longer stands; the completion itself stays in its history row.
 *
 * LOCKING. Every write locks the case row first, then the tiltak row, and checks state inside the
 * locks. The case lock is the one ImprovementCaseLifecycleService::close() takes before it looks for
 * unfinished tiltak, so a tiltak cannot be added or reopened under a case that is being closed. A
 * double submit finds the tiltak already moved and writes nothing.
 *
 * Tiltak are worked while the case is open or under arbeid. Once the case is closed or cancelled,
 * its tiltak are left as they stood until the case is reopened.
 *
 * AUTHORIZATION. verify() is a human decision about the outcome, not the work: it checks
 * HumanDecisionGuard (IMPROVEMENT_CLOSE) and improvement.close in the case's area itself, on the
 * locked case (phase 0 step 10). create() checks canCreateAction() — improvement.edit for a person,
 * the allowed API operation for an integration — and the other writes improvement.edit in the case's
 * area, the same way; the owner always with ImprovementCaseAccessService::isValidOwner().
 */
class ImprovementActionLifecycleService
{
    public function __construct(
        private readonly HumanDecisionGuard $guard,
        private readonly ImprovementCaseAccessService $access,
    ) {}

    /**
     * The fields of Nytt tiltak and Rediger, as a form or the API sends them. One set of rules for
     * every entry point; fields() turns the result into what create() and update() take. Status is
     * not among them: a tiltak is created planned and moves only through the lifecycle actions.
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'owner_user_id' => ['required', 'integer'],
            'due_date' => ['required', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated  validated with rules()
     * @return array{title: string, description: ?string, owner_user_id: int, due_date: string}
     */
    public static function fields(array $validated): array
    {
        $description = trim((string) ($validated['description'] ?? ''));

        return [
            'title' => trim((string) $validated['title']),
            'description' => $description !== '' ? $description : null,
            'owner_user_id' => (int) $validated['owner_user_id'],
            'due_date' => (string) $validated['due_date'],
        ];
    }

    /**
     * A new, planned tiltak. ImprovementCaseAccessService::canCreateAction(), asked of the locked case:
     * improvement.edit for a person, the allowed API operation for an integration.
     *
     * @param  array{title: string, description: ?string, owner_user_id: int, due_date: string}  $fields
     */
    public function create(ImprovementCase $case, User $actor, array $fields): ImprovementAction
    {
        return DB::transaction(function () use ($case, $actor, $fields): ImprovementAction {
            $locked = $this->lockActiveCase($case, 'title', fn (ImprovementCase $locked): bool => $this->access->canCreateAction($actor, $locked));
            $this->assertValidOwner($locked, (int) $fields['owner_user_id']);

            return ImprovementAction::query()->create($fields + [
                'customer_id' => (int) $locked->customer_id,
                'improvement_case_id' => (int) $locked->id,
                'created_by' => (int) $actor->id,
                'updated_by' => (int) $actor->id,
            ]);
        });
    }

    /**
     * Title, description, owner and frist — only while the tiltak is still to be done.
     *
     * @param  array{title: string, description: ?string, owner_user_id: int, due_date: string}  $fields
     */
    public function update(ImprovementAction $action, User $actor, array $fields): ImprovementAction
    {
        return DB::transaction(function () use ($action, $actor, $fields): ImprovementAction {
            $locked = $this->lockAction($action, 'title', $this->mayEdit($actor));

            if (! $locked->isActive()) {
                throw ValidationException::withMessages([
                    'title' => __('procynia.improvements.actions.validation.reopen_before_edit'),
                ]);
            }

            $this->assertValidOwner(ImprovementCase::query()->findOrFail($locked->improvement_case_id), (int) $fields['owner_user_id']);

            $locked->fill($fields + ['updated_by' => (int) $actor->id])->save();

            return $locked;
        });
    }

    /** Only a tiltak added by mistake that nobody has touched (ImprovementAction::isDeletable()). */
    public function delete(ImprovementAction $action, User $actor): void
    {
        DB::transaction(function () use ($action, $actor): void {
            $locked = $this->lockAction($action, 'action', $this->mayEdit($actor));

            if (! $locked->isDeletable()) {
                throw ValidationException::withMessages([
                    'action' => __('procynia.improvements.actions.validation.not_deletable'),
                ]);
            }

            $locked->delete();
        });
    }

    public function start(ImprovementAction $action, User $actor): ImprovementAction
    {
        return $this->transition($action, $actor, [ImprovementAction::STATUS_PLANNED], ImprovementAction::STATUS_IN_PROGRESS, null, 'action');
    }

    public function complete(ImprovementAction $action, User $actor, ?string $whatWasDone): ImprovementAction
    {
        return $this->transition($action, $actor, ImprovementAction::ACTIVE_STATUSES, ImprovementAction::STATUS_COMPLETED, $this->required($whatWasDone, 'completion_note'), 'completion_note');
    }

    public function cancel(ImprovementAction $action, User $actor, ?string $reason): ImprovementAction
    {
        return $this->transition($action, $actor, ImprovementAction::ACTIVE_STATUSES, ImprovementAction::STATUS_CANCELLED, $this->required($reason, 'reason'), 'reason');
    }

    public function reopen(ImprovementAction $action, User $actor, ?string $reason): ImprovementAction
    {
        return $this->transition($action, $actor, ImprovementAction::ENDED_STATUSES, ImprovementAction::STATUS_PLANNED, $this->required($reason, 'reason'), 'reason');
    }

    /**
     * Effektverifisering of the tiltak's current completion: a new, immutable row tied to the status
     * change that completed it. An earlier judgement of the same completion stays and is no longer
     * the current one. The tiltak itself is not touched.
     */
    public function verify(ImprovementAction $action, User $actor, ?string $result, ?string $note): ImprovementActionVerification
    {
        if (! in_array($result, ImprovementActionVerification::RESULTS, true)) {
            throw ValidationException::withMessages([
                'result' => __('procynia.improvements.actions.validation.result_required'),
            ]);
        }

        $note = trim((string) $note);

        if ($note === '') {
            throw ValidationException::withMessages([
                'note' => __('procynia.improvements.actions.validation.verification_note_required'),
            ]);
        }

        $actor = $this->guard->assert($actor, HumanDecision::IMPROVEMENT_CLOSE);

        return DB::transaction(function () use ($action, $actor, $result, $note): ImprovementActionVerification {
            $locked = $this->lockAction($action, 'result', fn (ImprovementCase $case): bool => $this->access->canClose($actor, $case));

            // The completion being judged, read inside the locks: no reopening can come between.
            $completion = $locked->status === ImprovementAction::STATUS_COMPLETED
                ? ImprovementActionStatusChange::query()
                    ->where('improvement_action_id', (int) $locked->id)
                    ->where('to_status', ImprovementAction::STATUS_COMPLETED)
                    ->orderByDesc('changed_at')
                    ->orderByDesc('id')
                    ->first()
                : null;

            if ($completion === null) {
                throw ValidationException::withMessages([
                    'result' => __('procynia.improvements.actions.validation.verify_not_completed'),
                ]);
            }

            return ImprovementActionVerification::query()->create([
                'customer_id' => (int) $locked->customer_id,
                'improvement_action_id' => (int) $locked->id,
                'completion_status_change_id' => (int) $completion->id,
                'result' => $result,
                'note' => $note,
                'verified_by_user_id' => (int) $actor->id,
                'verified_at' => now(),
            ]);
        });
    }

    /**
     * @param  list<string>  $from  the statuses the tiltak may be in for this change
     */
    private function transition(ImprovementAction $action, User $actor, array $from, string $to, ?string $note, string $errorField): ImprovementAction
    {
        return DB::transaction(function () use ($action, $actor, $from, $to, $note, $errorField): ImprovementAction {
            $locked = $this->lockAction($action, $errorField, $this->mayEdit($actor));

            if (! in_array($locked->status, $from, true)) {
                throw ValidationException::withMessages([
                    $errorField => __('procynia.improvements.actions.validation.transition_not_allowed'),
                ]);
            }

            $now = now();

            ImprovementActionStatusChange::query()->create([
                'customer_id' => (int) $locked->customer_id,
                'improvement_action_id' => (int) $locked->id,
                'from_status' => $locked->status,
                'to_status' => $to,
                'note' => $note,
                'changed_by_user_id' => (int) $actor->id,
                'changed_at' => $now,
            ]);

            $completed = $to === ImprovementAction::STATUS_COMPLETED;

            $locked->forceFill([
                'status' => $to,
                // The current completion only. Anything else leaves none; a reopening clears the one undone.
                'completed_at' => $completed ? $now : null,
                'completed_by_user_id' => $completed ? (int) $actor->id : null,
                'completion_note' => $completed ? $note : null,
                'updated_by' => (int) $actor->id,
            ])->save();

            return $locked;
        });
    }

    /**
     * The case, locked, and still open or under arbeid. Call inside a transaction.
     *
     * @param  (\Closure(ImprovementCase): bool)|null  $authorized
     */
    private function lockActiveCase(ImprovementCase $case, string $errorField, ?\Closure $authorized = null): ImprovementCase
    {
        $locked = ImprovementCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();

        if ($authorized !== null && ! $authorized($locked)) {
            throw new AuthorizationException('This user may not act on this case.');
        }

        if (! $locked->isActive()) {
            throw ValidationException::withMessages([
                $errorField => __('procynia.improvements.actions.validation.case_not_active'),
            ]);
        }

        return $locked;
    }

    /** improvement.edit in the case's area — the work on its tiltak — asked of the locked case. */
    private function mayEdit(User $actor): \Closure
    {
        return fn (ImprovementCase $case): bool => $this->access->canEdit($actor, $case);
    }

    /** The responsible person: an active user of the case's customer who can read cases in its area. */
    private function assertValidOwner(ImprovementCase $case, int $ownerId): void
    {
        $owner = User::query()->where('customer_id', (int) $case->customer_id)->find($ownerId);

        if (! $this->access->isValidOwner($owner, (int) $case->customer_id, (int) $case->business_area_id)) {
            throw ValidationException::withMessages([
                'owner_user_id' => __('procynia.improvements.actions.validation.owner_not_allowed'),
            ]);
        }
    }

    /**
     * The case and then the tiltak, both locked, in that order. Call inside a transaction.
     *
     * $authorized is the access rule, asked of the locked case before its state is: a person who may
     * not act on the case is refused (403) before being told anything about it.
     *
     * @param  (\Closure(ImprovementCase): bool)|null  $authorized
     */
    private function lockAction(ImprovementAction $action, string $errorField, ?\Closure $authorized = null): ImprovementAction
    {
        $case = $this->lockActiveCase(ImprovementCase::query()->findOrFail($action->improvement_case_id), $errorField, $authorized);

        return ImprovementAction::query()
            ->whereKey($action->id)
            ->where('improvement_case_id', (int) $case->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function required(?string $value, string $field): string
    {
        $text = trim((string) $value);

        if ($text === '') {
            throw ValidationException::withMessages([
                $field => __('procynia.improvements.actions.validation.'.($field === 'completion_note' ? 'completion_required' : 'reason_required')),
            ]);
        }

        return $text;
    }
}
