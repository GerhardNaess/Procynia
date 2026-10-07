<?php

namespace App\Services\Improvements;

use App\Models\ImprovementCase;
use App\Models\User;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Registering an avvik or a forbedring — the one place a case comes into being, whether someone
 * registers it in Avvik og forbedringer or a revisjonsfunn is handed off to it.
 *
 * The fields and their rules are the same for both: rules(). create() checks that the person may
 * edit in *the chosen* fagområde — not merely somewhere — and that the responsible person can read
 * cases there, and then writes the case open, reported by the person registering it. Both checks
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
            'occurred_at' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
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

        $this->assertCanEditIn($actor, $areaId);
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
