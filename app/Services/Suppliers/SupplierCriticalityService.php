<?php

namespace App\Services\Suppliers;

use App\Models\Supplier;
use App\Models\SupplierCriticalityChange;
use App\Models\User;
use App\Support\Suppliers\SupplierValidationMessages;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Hvor viktig er leverandøren for oss? — the only way a supplier's criticality changes after it is
 * registered (docs/supplier-management-v1-plan.md §4.2).
 *
 * The user chooses the level. The four ja/nei answers are the basis the choice was made on: they are
 * required and stored, and never summed, scored, turned into a suggestion or used to overrule the
 * choice. Viktig and Kritisk require a review interval; Standard may be without one.
 *
 * change() writes the new classification on the supplier and one immutable SupplierCriticalityChange
 * with the before and after, in one transaction, with the supplier row locked and checked again
 * inside the lock: an ended supplier is refused, and so is a change that changes nothing — a double
 * submit writes one row.
 *
 * Authorization is the caller's (supplier.edit through SupplierAccessService).
 */
class SupplierCriticalityService
{
    /**
     * The classification fields, for registering a supplier and for changing its criticality.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        $rules = [
            'criticality' => ['required', 'string', Rule::in(Supplier::CRITICALITIES)],
            'review_interval_months' => [
                'nullable',
                'required_if:criticality,'.Supplier::CRITICALITY_IMPORTANT.','.Supplier::CRITICALITY_CRITICAL,
                'integer',
                Rule::in(Supplier::REVIEW_INTERVALS),
            ],
        ];

        foreach (Supplier::CRITICALITY_QUESTIONS as $question) {
            $rules[$question] = ['required', 'boolean'];
        }

        return $rules;
    }

    /** @return array<string, string|array<string, string>> */
    public static function messages(): array
    {
        $messages = [
            'review_interval_months.required_if' => __('procynia.supplier_management.validation.interval_required'),
        ];

        foreach (Supplier::CRITICALITY_QUESTIONS as $question) {
            $messages["{$question}.required"] = __('procynia.supplier_management.validation.answer_required');
            $messages["{$question}.boolean"] = __('procynia.supplier_management.validation.answer_required');
        }

        return $messages + SupplierValidationMessages::messages();
    }

    /**
     * The validated input as the classification the supplier stores.
     *
     * @param  array<string, mixed>  $validated
     * @return array{criticality: string, review_interval_months: int|null, processes_personal_data: bool, has_system_access: bool, supports_critical_delivery: bool, hard_to_replace: bool}
     */
    public static function classification(array $validated): array
    {
        $interval = $validated['review_interval_months'] ?? null;
        $classification = [
            'criticality' => (string) $validated['criticality'],
            'review_interval_months' => $interval !== null && $interval !== '' ? (int) $interval : null,
        ];

        foreach (Supplier::CRITICALITY_QUESTIONS as $question) {
            $classification[$question] = filter_var($validated[$question], FILTER_VALIDATE_BOOLEAN);
        }

        return $classification;
    }

    /**
     * Vurder kritikalitet (first time) or Endre kritikalitet: the new classification and why.
     *
     * @param  array{criticality: string, review_interval_months: int|null, processes_personal_data: bool, has_system_access: bool, supports_critical_delivery: bool, hard_to_replace: bool}  $classification
     */
    public function change(Supplier $supplier, User $actor, array $classification, ?string $reason): SupplierCriticalityChange
    {
        $reason = trim((string) $reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => __('procynia.supplier_management.validation.reason_required')]);
        }

        return DB::transaction(function () use ($supplier, $actor, $classification, $reason): SupplierCriticalityChange {
            $locked = Supplier::query()->whereKey($supplier->id)->lockForUpdate()->firstOrFail();

            if ($locked->isEnded()) {
                throw ValidationException::withMessages(['criticality' => __('procynia.supplier_management.validation.reopen_before_edit')]);
            }

            $before = $locked->classification();

            if ($before === $classification) {
                throw ValidationException::withMessages(['criticality' => __('procynia.supplier_management.validation.criticality_unchanged')]);
            }

            $row = [
                'customer_id' => (int) $locked->customer_id,
                'supplier_id' => (int) $locked->id,
                'reason' => $reason,
                'changed_by_user_id' => (int) $actor->id,
                'changed_at' => now(),
            ];

            foreach ($classification as $field => $value) {
                $row["from_{$field}"] = $before[$field] ?? null;
                $row["to_{$field}"] = $value;
            }

            $change = SupplierCriticalityChange::query()->create($row);

            $locked->forceFill($classification + ['updated_by' => (int) $actor->id])->save();

            return $change;
        });
    }
}
