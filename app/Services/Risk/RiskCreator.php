<?php

namespace App\Services\Risk;

use App\Models\Risk;
use App\Models\User;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Registering a risk — the one place a risk comes into being, whether someone registers it in
 * Risiko or it is started from a supplier in Leverandøroppfølging.
 *
 * The fields and their rules are the same for both: rules(). create() checks that the person may
 * create in *the chosen* fagområde — not merely hold risk.create somewhere — and that the owner, if
 * any, can read risks there, and then writes the risk. Both checks answer with a 422 on the field:
 * whether the id names an area of another customer, one outside the person's scope or nothing at
 * all, the answer is the same.
 *
 * Validation of the request itself is the caller's, with rules(); create() takes the result.
 */
class RiskCreator
{
    public function __construct(
        private readonly RiskAccessService $access,
    ) {}

    /**
     * Årsak, hendelse and konsekvens are required on every save, edits included: an older risk
     * without them opens as before, but cannot be saved again until they are filled in.
     * `description` is the optional «Utfyllende informasjon».
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'cause' => ['required', 'string', 'max:1000'],
            'event' => ['required', 'string', 'max:1000'],
            'consequence' => ['required', 'string', 'max:1000'],
            'description' => ['nullable', 'string', 'max:5000'],
            'business_area_id' => ['required', 'integer'],
            'owner_user_id' => ['nullable', 'integer'],
            'status' => ['required', 'string', Rule::in(Risk::STATUSES)],
            'review_interval_months' => ['sometimes', 'nullable', 'integer', Rule::in(Risk::REVIEW_INTERVALS)],
            'treatment_strategy' => ['sometimes', 'nullable', 'string', Rule::in(Risk::TREATMENT_STRATEGIES)],
        ];
    }

    /**
     * Write a new risk from validated fields, after checking the area and the owner.
     *
     * @param  array<string, mixed>  $validated  validated with rules()
     */
    public function create(User $actor, array $validated): Risk
    {
        // The area is the scope the new risk will live in, so the user must be allowed to create
        // in *that* area — not merely hold risk.create somewhere.
        $this->assertAreaAllows($actor, CustomerPermissionCatalog::RISK_CREATE, (int) $validated['business_area_id']);
        $this->assertValidOwner($actor, $validated);

        return Risk::query()->create($this->fields($validated) + [
            'customer_id' => (int) $actor->customer_id,
            'review_interval_months' => $validated['review_interval_months'] ?? null,
            'treatment_strategy' => $validated['treatment_strategy'] ?? null,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }

    /**
     * The permission in this area of the person's own customer, or a 422 on business_area_id.
     */
    public function assertAreaAllows(User $actor, string $permissionKey, int $areaId): void
    {
        if (! $this->access->canInArea($actor, $permissionKey, (int) $actor->customer_id, $areaId)) {
            throw ValidationException::withMessages([
                'business_area_id' => __('procynia.risk.validation.area_not_allowed'),
            ]);
        }
    }

    /**
     * The owner must be an active person in the same customer who can read risks in the chosen
     * area. An owner who cannot open the risk they own is not an owner. No owner is allowed.
     *
     * @param  array<string, mixed>  $validated
     */
    public function assertValidOwner(User $actor, array $validated): void
    {
        $ownerId = $validated['owner_user_id'] ?? null;

        if ($ownerId === null) {
            return;
        }

        $owner = User::query()
            ->where('customer_id', (int) $actor->customer_id)
            ->where('is_active', true)
            ->find((int) $ownerId);

        if ($owner === null
            || ! $this->access->canInArea($owner, CustomerPermissionCatalog::RISK_VIEW, (int) $actor->customer_id, (int) $validated['business_area_id'])) {
            throw ValidationException::withMessages([
                'owner_user_id' => __('procynia.risk.validation.owner_not_allowed'),
            ]);
        }
    }

    /**
     * The validated form as the columns shared by registering and editing. The review interval and
     * the treatment strategy are not among them: an edit changes those only when they were sent.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function fields(array $validated): array
    {
        return [
            'business_area_id' => (int) $validated['business_area_id'],
            'title' => trim($validated['title']),
            'cause' => trim($validated['cause']),
            'event' => trim($validated['event']),
            'consequence' => trim($validated['consequence']),
            'description' => $this->normalizedText($validated['description'] ?? null),
            'owner_user_id' => $validated['owner_user_id'] ?? null,
            'status' => $validated['status'],
        ];
    }

    private function normalizedText(?string $value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }
}
