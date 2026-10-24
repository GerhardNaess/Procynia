<?php

namespace App\Services\Improvements;

use App\Models\ImprovementCase;
use App\Models\User;
use App\Support\CalendarDate;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Registering an avvik or a forbedring — the one place a case comes into being, whether someone
 * registers it in Avvik og forbedringer or a revisjonsfunn is handed off to it.
 *
 * The fields and their rules are the same for both: rules(). create() checks that the actor may
 * register in *the chosen* fagområde — not merely somewhere — and that the responsible person can
 * read cases there, and then writes the case open, reported by the one registering it. A person
 * registers with improvement.edit; an integration through the API only, with the operation its
 * System Owner allowed (ImprovementCaseAccessService::canRegisterIn). Both checks
 * answer with a 422 on the field: whether the id names an area of another customer, one outside the
 * person's scope or nothing at all, the answer is the same.
 *
 * Validation of the request itself is the caller's, with rules(); create() takes the result.
 */
class ImprovementCaseCreator
{
    public function __construct(
        private readonly ImprovementCaseAccessService $access,
    ) {}

    /**
     * The fields of a case, for registering and editing. Status is not among them: a case is created
     * open and moves only through the lifecycle actions.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(ImprovementCase::TYPES)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:10000'],
            'business_area_id' => ['required', 'integer'],
            'owner_user_id' => ['required', 'integer'],
            // Hendelsesdato is when something happened, so it cannot lie ahead.
            'occurred_at' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.CalendarDate::todayString()],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * Write a new, open case from validated fields, after checking the area and the owner.
     *
     * @param  array<string, mixed>  $validated  validated with rules()
     */
    public function create(User $actor, array $validated): ImprovementCase
    {
        $areaId = (int) $validated['business_area_id'];

        $this->assertCanRegisterIn($actor, $areaId);
        $this->assertValidOwner($actor, (int) $validated['owner_user_id'], $areaId);

        return ImprovementCase::query()->create($this->fields($validated) + [
            'customer_id' => (int) $actor->customer_id,
            'business_area_id' => $areaId,
            'reported_by_user_id' => $actor->id,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }

    /** improvement.edit in this area of the person's own customer, or a 422 on business_area_id. */
    /**
     * Change a case's fields, and move it to another fagområde: improvement.edit in the area it is in
     * now (403 otherwise), and in the target area too when it moves (422 on business_area_id), with the
     * owner checked against the area it will be in. Only while the case is open or under arbeid. All
     * of it asked of the case under its row lock (phase 0 step 10).
     *
     * @param  array<string, mixed>  $validated  validated with rules()
     */
    public function update(User $actor, ImprovementCase $case, array $validated): ImprovementCase
    {
        return DB::transaction(function () use ($actor, $case, $validated): ImprovementCase {
            $locked = $this->lockEditable($actor, $case);
            $targetAreaId = (int) $validated['business_area_id'];

            // Moving a case changes who can see it, so it takes edit authority on both sides.
            if ($targetAreaId !== (int) $locked->business_area_id) {
                $this->assertCanEditIn($actor, $targetAreaId);
            }

            $this->assertValidOwner($actor, (int) $validated['owner_user_id'], $targetAreaId);

            $locked->fill($this->fields($validated) + [
                'business_area_id' => $targetAreaId,
                'updated_by' => $actor->id,
            ])->save();

            return $locked;
        });
    }

    /** Årsaksanalyse: written, changed or emptied while the case is open or under arbeid. */
    public function updateCause(User $actor, ImprovementCase $case, ?string $causeAnalysis): ImprovementCase
    {
        return DB::transaction(function () use ($actor, $case, $causeAnalysis): ImprovementCase {
            $locked = $this->lockEditable($actor, $case);
            $text = trim((string) $causeAnalysis);

            $locked->forceFill([
                'cause_analysis' => $text !== '' ? $text : null,
                'updated_by' => $actor->id,
            ])->save();

            return $locked;
        });
    }

    /** The case, locked, if this person may edit it and it is still open or under arbeid. */
    private function lockEditable(User $actor, ImprovementCase $case): ImprovementCase
    {
        $locked = ImprovementCase::query()->whereKey($case->id)->lockForUpdate()->first();

        if ($locked === null || ! $this->access->canEdit($actor, $locked)) {
            throw new AuthorizationException('This user may not change this case.');
        }

        if (! $locked->isActive()) {
            throw ValidationException::withMessages([
                'case' => __('procynia.improvements.validation.reopen_before_edit'),
            ]);
        }

        return $locked;
    }

    /**
     * Registering in this area (ImprovementCaseAccessService::canRegisterIn): improvement.edit for a
     * person, the allowed API operation for an integration. A 422 on business_area_id otherwise.
     */
    public function assertCanRegisterIn(User $actor, int $areaId): void
    {
        if (! $this->access->canRegisterIn($actor, $areaId)) {
            throw ValidationException::withMessages([
                'business_area_id' => __('procynia.improvements.validation.area_not_allowed'),
            ]);
        }
    }

    public function assertCanEditIn(User $actor, int $areaId): void
    {
        if (! $this->access->canInArea($actor, CustomerPermissionCatalog::IMPROVEMENT_EDIT, (int) $actor->customer_id, $areaId)) {
            throw ValidationException::withMessages([
                'business_area_id' => __('procynia.improvements.validation.area_not_allowed'),
            ]);
        }
    }

    /** An active person of the customer who can read cases in the area, or a 422 on owner_user_id. */
    public function assertValidOwner(User $actor, int $ownerId, int $areaId): void
    {
        $owner = User::query()->where('customer_id', (int) $actor->customer_id)->find($ownerId);

        if (! $this->access->isValidOwner($owner, (int) $actor->customer_id, $areaId)) {
            throw ValidationException::withMessages([
                'owner_user_id' => __('procynia.improvements.validation.owner_not_allowed'),
            ]);
        }
    }

    /**
     * The validated form as columns. A forbedring has no Hendelsesdato, whatever the form sent.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function fields(array $validated): array
    {
        return [
            'type' => $validated['type'],
            'title' => trim($validated['title']),
            'description' => trim($validated['description']),
            'owner_user_id' => (int) $validated['owner_user_id'],
            'occurred_at' => $validated['type'] === ImprovementCase::TYPE_DEVIATION ? ($validated['occurred_at'] ?? null) : null,
            'due_date' => $validated['due_date'] ?? null,
        ];
    }
}
