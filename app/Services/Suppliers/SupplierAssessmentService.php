<?php

namespace App\Services\Suppliers;

use App\Models\Supplier;
use App\Models\SupplierAssessment;
use App\Models\User;
use App\Services\Security\HumanDecisionGuard;
use App\Support\CalendarDate;
use App\Support\CustomerPermissionCatalog;
use App\Support\HumanDecision;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Vurder leverandør: the only writer of a SupplierAssessment (docs/supplier-management-v1-plan.md
 * §4.3).
 *
 * The assessor rates the four fixed criteria and chooses the overall result; nothing here averages,
 * scores, suggests or overrules it. The begrunnelse is required. The assessment snapshots the
 * supplier's name, criticality and review interval as they are, and changes neither — criticality
 * is a separate question.
 *
 * Only an active supplier is assessed: not one under review (not in use yet) and not an ended one.
 * The supplier row is locked and its status checked again inside the lock, so an assessment can
 * never land on a supplier being ended at the same moment.
 *
 * A person's judgement (HumanDecision::SUPPLIER_ASSESS): the guard refuses an integration and the
 * API channel first, then supplier.assess is required in the supplier's own customer, with the module
 * (SupplierAccessService::authorize()). The controller checks the same before it calls.
 */
class SupplierAssessmentService
{
    public function __construct(
        private readonly HumanDecisionGuard $guard,
        private readonly SupplierAccessService $access,
    ) {}

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        $rules = [];

        foreach (SupplierAssessment::CRITERIA as $criterion) {
            $rules[$criterion] = ['required', 'string', Rule::in(SupplierAssessment::RATINGS)];
        }

        return $rules + [
            'overall_result' => ['required', 'string', Rule::in(SupplierAssessment::RESULTS)],
            'rationale' => ['required', 'string', 'max:10000'],
            'assessed_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.CalendarDate::todayString()],
        ];
    }

    /** @param  array<string, mixed>  $validated */
    public function record(Supplier $supplier, User $actor, array $validated): SupplierAssessment
    {
        $actor = $this->guard->assert($actor, HumanDecision::SUPPLIER_ASSESS);
        $this->access->authorize($actor, CustomerPermissionCatalog::SUPPLIER_ASSESS, (int) $supplier->customer_id);

        $rationale = trim((string) ($validated['rationale'] ?? ''));

        if ($rationale === '') {
            throw ValidationException::withMessages(['rationale' => __('procynia.supplier_management.validation.rationale_required')]);
        }

        return DB::transaction(function () use ($supplier, $actor, $validated, $rationale): SupplierAssessment {
            $locked = Supplier::query()->whereKey($supplier->id)->where('customer_id', (int) $actor->customer_id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== Supplier::STATUS_ACTIVE) {
                throw ValidationException::withMessages(['overall_result' => __('procynia.supplier_management.validation.assess_only_active')]);
            }

            $row = [
                'customer_id' => (int) $locked->customer_id,
                'supplier_id' => (int) $locked->id,
                'assessed_on' => $validated['assessed_on'] instanceof CarbonInterface ? $validated['assessed_on']->toDateString() : (string) $validated['assessed_on'],
                'assessed_by_user_id' => (int) $actor->id,
                'overall_result' => (string) $validated['overall_result'],
                'rationale' => $rationale,
                'supplier_name' => $locked->name,
                'criticality' => $locked->criticality,
                'review_interval_months' => $locked->review_interval_months,
                'recorded_at' => now(),
            ];

            foreach (SupplierAssessment::CRITERIA as $criterion) {
                $row[$criterion] = (string) $validated[$criterion];
            }

            return SupplierAssessment::query()->create($row);
        });
    }
}
