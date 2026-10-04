<?php

namespace App\Services\Risk;

use App\Models\Risk;
use App\Models\RiskTreatmentAction;
use App\Models\User;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Validation\ValidationException;

/**
 * Tiltak on a risk: create, edit, complete, reopen — and the rows the risk page shows.
 *
 * The caller has already reached the risk through RiskAccessService::visibleRisks() and checked
 * risk.edit in its area. Nothing here looks a risk up on its own, so a tiltak can never be the way
 * to a risk someone cannot see.
 *
 * The responsible person must be an active user of the risk's own customer who can read risks in
 * the risk's area: someone who cannot open the risk cannot be responsible for acting on it.
 */
class RiskTreatmentService
{
    public function __construct(
        private readonly RiskAccessService $access,
    ) {}

    /**
     * Open actions first, by deadline; completed ones beneath, most recently completed first.
     *
     * @return list<array<string, mixed>>
     */
    public function actionsFor(Risk $risk): array
    {
        $today = now();

        return RiskTreatmentAction::query()
            ->where('risk_id', $risk->id)
            ->where('customer_id', $risk->customer_id)
            ->with('owner:id,name')
            ->get()
            ->sortBy(fn (RiskTreatmentAction $action): array => $action->isOpen()
                ? [0, $action->due_at?->toDateString() ?? '', $action->id]
                : [1, -($action->completed_at?->getTimestamp() ?? 0), -$action->id])
            ->values()
            ->map(fn (RiskTreatmentAction $action): array => [
                'id' => (int) $action->id,
                'title' => $action->title,
                'owner_user_id' => $action->owner_user_id !== null ? (int) $action->owner_user_id : null,
                'owner_name' => $action->owner?->name,
                'due_at' => $action->due_at?->toDateString(),
                'status' => $action->status,
                'completed_at' => $action->completed_at?->toIso8601String(),
                'outcome_note' => $action->outcome_note,
                'is_overdue' => $action->isOverdue($today),
            ])
            ->all();
    }

    /**
     * People who may be made responsible for an action on this risk.
     *
     * @return list<array{id: int, name: string}>
     */
    public function ownerOptions(Risk $risk): array
    {
        $viewerAreas = $this->access->viewerAreaIdsByUser((int) $risk->customer_id);
        $areaId = (int) $risk->business_area_id;

        return User::query()
            ->where('customer_id', (int) $risk->customer_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->filter(fn (User $user): bool => in_array($areaId, $viewerAreas[(int) $user->id] ?? [], true))
            ->map(fn (User $user): array => ['id' => (int) $user->id, 'name' => $user->name])
            ->values()
            ->all();
    }

    /** @param  array{title: string, owner_user_id: int|string, due_at: string, outcome_note?: ?string}  $data */
    public function create(User $actor, Risk $risk, array $data): RiskTreatmentAction
    {
        $this->guardOwner($risk, (int) $data['owner_user_id']);

        return RiskTreatmentAction::query()->create([
            'customer_id' => (int) $risk->customer_id,
            'risk_id' => (int) $risk->id,
            'title' => trim($data['title']),
            'owner_user_id' => (int) $data['owner_user_id'],
            'due_at' => $data['due_at'],
            'status' => RiskTreatmentAction::STATUS_OPEN,
            'outcome_note' => $this->normalizedText($data['outcome_note'] ?? null),
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }

    /** @param  array{title: string, owner_user_id: int|string, due_at: string, outcome_note?: ?string}  $data */
    public function update(User $actor, RiskTreatmentAction $action, Risk $risk, array $data): void
    {
        $this->guardOwner($risk, (int) $data['owner_user_id']);

        $action->fill([
            'title' => trim($data['title']),
            'owner_user_id' => (int) $data['owner_user_id'],
            'due_at' => $data['due_at'],
            // The result is written when the action is completed; an edit that does not send it
            // (an open action's form has no such field) leaves an earlier result in place.
            'outcome_note' => array_key_exists('outcome_note', $data)
                ? $this->normalizedText($data['outcome_note'])
                : $action->outcome_note,
            'updated_by' => $actor->id,
        ])->save();
    }

    /** Completing sets completed_at. An optional note records what came of it. */
    public function complete(User $actor, RiskTreatmentAction $action, ?string $outcomeNote): void
    {
        $note = $this->normalizedText($outcomeNote);

        $action->fill([
            'status' => RiskTreatmentAction::STATUS_COMPLETED,
            'completed_at' => $action->isOpen() ? now() : $action->completed_at,
            'outcome_note' => $note ?? $action->outcome_note,
            'updated_by' => $actor->id,
        ])->save();
    }

    /** Reopening clears completed_at; the note stays as a record of what was tried. */
    public function reopen(User $actor, RiskTreatmentAction $action): void
    {
        $action->fill([
            'status' => RiskTreatmentAction::STATUS_OPEN,
            'completed_at' => null,
            'updated_by' => $actor->id,
        ])->save();
    }

    /** The action, if it belongs to this risk. Anything else is the caller's 404. */
    public function findForRisk(Risk $risk, int $actionId): ?RiskTreatmentAction
    {
        return RiskTreatmentAction::query()
            ->where('risk_id', $risk->id)
            ->where('customer_id', $risk->customer_id)
            ->whereKey($actionId)
            ->first();
    }

    /**
     * One answer for a user of another tenant, an inactive user, a user who cannot see the risk and
     * an id that does not exist — the form cannot be used to probe who exists or who sees what.
     */
    private function guardOwner(Risk $risk, int $ownerId): void
    {
        $owner = User::query()
            ->where('customer_id', (int) $risk->customer_id)
            ->where('is_active', true)
            ->find($ownerId);

        if ($owner === null || ! $this->access->can($owner, CustomerPermissionCatalog::RISK_VIEW, $risk)) {
            throw ValidationException::withMessages([
                'owner_user_id' => __('procynia.risk.validation.action_owner_not_allowed'),
            ]);
        }
    }

    private function normalizedText(?string $value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }
}
