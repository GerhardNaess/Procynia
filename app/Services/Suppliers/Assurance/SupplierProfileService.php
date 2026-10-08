<?php

namespace App\Services\Suppliers\Assurance;

use App\Models\Supplier;
use App\Models\SupplierProfile;
use App\Models\SupplierProfileChange;
use App\Models\User;
use App\Services\Suppliers\SupplierAccessService;
use App\Support\Suppliers\SupplierValidationMessages;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Fyll ut / Rediger profil — the only way a supplier's leverandørprofil changes
 * (docs/supplier-assurance-v2-plan.md §4.4, §13.2).
 *
 * save() writes the profile's current state and one immutable SupplierProfileChange holding the
 * whole profile before and after, in one transaction, so the two can never disagree. Inside the
 * transaction, with the supplier row locked:
 *
 *  - the supplier is looked up again through SupplierAccessService::visibleSuppliers() — another
 *    customer's supplier is not found (404) — and the actor must hold supplier.edit. supplier.assure
 *    does not change the profile: the person who controls a supplier cannot remove a requirement by
 *    changing the facts it is applied by;
 *  - an ended supplier is refused;
 *  - a question the profile does not ask for this supplier (SupplierProfile::visibleFields()) is
 *    stored as not answered, so a hidden answer can never linger;
 *  - the first save needs at least one answer and no begrunnelse; every later save needs a
 *    begrunnelse, and a save that changes nothing is refused — a double submit writes one row.
 *
 * completed_at is when the profile was last saved complete, and null while it is not.
 */
class SupplierProfileService
{
    public function __construct(
        private readonly SupplierAccessService $access,
    ) {}

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        $rules = [];

        foreach (SupplierProfile::ANSWER_FIELDS as $field) {
            $rules[$field] = ['nullable', 'string', Rule::in(SupplierProfile::ANSWERS)];
        }

        foreach (SupplierProfile::CHOICE_FIELDS as $field => $values) {
            $rules[$field] = ['nullable', 'string', Rule::in($values)];
        }

        foreach (SupplierProfile::LIST_FIELDS as $field => $codes) {
            $rules[$field] = ['nullable', 'array'];
            $rules["{$field}.*"] = ['string', Rule::in($codes)];
        }

        $rules['reason'] = ['nullable', 'string', 'max:5000'];

        return $rules;
    }

    /** @return array<string, string|array<string, string>> */
    public static function messages(): array
    {
        return SupplierValidationMessages::messages();
    }

    /**
     * The profile questions by their short names, so a message reads «Velg en gyldig verdi for Bransjer».
     *
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        $labels = (array) __('procynia.supplier_management.profile.labels');
        $attributes = SupplierValidationMessages::attributes() + $labels;

        foreach (array_keys(SupplierProfile::LIST_FIELDS) as $field) {
            $attributes["{$field}.*"] = $labels[$field] ?? $field;
        }

        return $attributes;
    }

    /**
     * The validated input as profile answers: every field, null when not answered, each list in the
     * fixed order of its codes and without repeats.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function answers(array $validated): array
    {
        $answers = SupplierProfile::emptyAnswers();

        foreach (SupplierProfile::fields() as $field) {
            $value = $validated[$field] ?? null;

            if (array_key_exists($field, SupplierProfile::LIST_FIELDS)) {
                $answers[$field] = is_array($value)
                    ? array_values(array_intersect(SupplierProfile::LIST_FIELDS[$field], $value))
                    : null;

                continue;
            }

            $answers[$field] = is_string($value) && $value !== '' ? $value : null;
        }

        return $answers;
    }

    /** @param  array<string, mixed>  $answers  as answers() returns them */
    public function save(Supplier $supplier, User $actor, array $answers, ?string $reason): SupplierProfileChange
    {
        $reason = trim((string) $reason);
        $reason = $reason !== '' ? $reason : null;

        return DB::transaction(function () use ($supplier, $actor, $answers, $reason): SupplierProfileChange {
            $locked = $this->access->visibleSuppliers($actor)->whereKey($supplier->id)->lockForUpdate()->firstOrFail();

            if (! $this->access->canEdit($actor)) {
                throw new AuthorizationException;
            }

            if ($locked->isEnded()) {
                throw ValidationException::withMessages(['profile' => __('procynia.supplier_management.validation.reopen_before_edit')]);
            }

            $visible = SupplierProfile::visibleFields($locked, $answers);
            $after = SupplierProfile::emptyAnswers();

            foreach ($visible as $field) {
                $after[$field] = $answers[$field] ?? null;
            }

            $profile = SupplierProfile::query()->whereKey($locked->id)->first();
            $before = $profile?->answers();

            if ($before === null && SupplierProfilePredicates::isEmpty($after)) {
                throw ValidationException::withMessages(['profile' => __('procynia.supplier_management.validation.profile_empty')]);
            }

            if ($before === $after) {
                throw ValidationException::withMessages(['profile' => __('procynia.supplier_management.validation.profile_unchanged')]);
            }

            if ($before !== null && $reason === null) {
                throw ValidationException::withMessages(['reason' => __('procynia.supplier_management.validation.reason_required')]);
            }

            $profile ??= (new SupplierProfile)->forceFill([
                'supplier_id' => (int) $locked->id,
                'customer_id' => (int) $locked->customer_id,
            ]);
            $profile->forceFill($after + [
                'completed_at' => SupplierProfile::isComplete($locked, $after) ? now() : null,
                'updated_by' => (int) $actor->id,
            ])->save();

            return SupplierProfileChange::query()->create([
                'customer_id' => (int) $locked->customer_id,
                'supplier_id' => (int) $locked->id,
                'from_profile' => $before,
                'to_profile' => $after,
                'reason' => $reason,
                'changed_by_user_id' => (int) $actor->id,
                'changed_at' => now(),
            ]);
        });
    }
}
