<?php

namespace App\Services\Suppliers\Assurance;

use App\Models\Supplier;
use App\Models\SupplierAssuranceDecision;
use App\Models\User;
use App\Services\Suppliers\SupplierAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Registrer beslutning — the only writer of a SupplierAssuranceDecision
 * (docs/supplier-assurance-v2-plan.md §7.1, §9.3, §13.2). A decision is always a person's: there is
 * no code path that writes one without a signed-in actor holding supplier.assure, and nothing here
 * or anywhere else decides on its own — not Godkjent when everything is documented, not «Ikke
 * godkjent for nye kjøp» when a mandatory requirement is open.
 *
 * Inside one transaction, with the supplier row locked:
 *  1. the supplier is looked up again through SupplierAccessService::visibleSuppliers() (another
 *     customer's is a 404) and the actor must hold supplier.assure — never edit, assess or delete;
 *  2. an ended supplier is refused;
 *  3. the control state is computed again by SupplierAssuranceResolver, so a page left open while a
 *     control changed decides on today's state; with no requirement applying there is nothing to
 *     decide;
 *  4. the decision must be one the state allows (§9.3);
 *  5. one new row is written with the snapshot of that state. Never an update, never a delete.
 */
class SupplierAssuranceDecisionService
{
    public function __construct(
        private readonly SupplierAccessService $access,
        private readonly SupplierAssuranceResolver $resolver,
    ) {}

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'decision' => ['required', 'string', Rule::in(SupplierAssuranceDecision::DECISIONS)],
            'rationale' => ['required', 'string', 'max:10000'],
            'follow_up_note' => [
                'exclude_unless:decision,'.SupplierAssuranceDecision::DECISION_APPROVED_WITH_FOLLOW_UP,
                'required',
                'string',
                'max:10000',
            ],
            'decided_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ];
    }

    /** @param  array<string, mixed>  $validated  rules() */
    public function record(User $actor, Supplier $supplier, array $validated): SupplierAssuranceDecision
    {
        $decision = (string) ($validated['decision'] ?? '');
        $rationale = trim((string) ($validated['rationale'] ?? ''));
        $followUp = $decision === SupplierAssuranceDecision::DECISION_APPROVED_WITH_FOLLOW_UP ? trim((string) ($validated['follow_up_note'] ?? '')) : null;

        if (! in_array($decision, SupplierAssuranceDecision::DECISIONS, true)) {
            throw ValidationException::withMessages(['decision' => __('procynia.supplier_management.validation.rules.choose')]);
        }

        if ($rationale === '') {
            throw ValidationException::withMessages(['rationale' => __('procynia.supplier_management.validation.rationale_required')]);
        }

        if ($followUp === '') {
            throw ValidationException::withMessages(['follow_up_note' => __('procynia.supplier_management.validation.follow_up_note_required')]);
        }

        return DB::transaction(function () use ($actor, $supplier, $decision, $rationale, $followUp, $validated): SupplierAssuranceDecision {
            $locked = $this->access->visibleSuppliers($actor)->whereKey($supplier->id)->lockForUpdate()->firstOrFail();

            if (! $this->access->canAssure($actor)) {
                throw new AuthorizationException;
            }

            if ($locked->isEnded()) {
                throw ValidationException::withMessages(['decision' => __('procynia.supplier_management.validation.reopen_before_edit')]);
            }

            $state = $this->resolver->forSupplier($locked);

            if ($state === null) {
                throw ValidationException::withMessages(['decision' => __('procynia.supplier_management.validation.decision_nothing_applies')]);
            }

            if (! in_array($decision, $state['allowed_decisions'], true)) {
                throw ValidationException::withMessages(['decision' => __('procynia.supplier_management.validation.decision_not_allowed.'.$decision)]);
            }

            return SupplierAssuranceDecision::query()->create([
                'customer_id' => (int) $locked->customer_id,
                'supplier_id' => (int) $locked->id,
                'decision' => $decision,
                'rationale' => $rationale,
                'follow_up_note' => $followUp,
                'decided_on' => (string) $validated['decided_on'],
                'decided_by_user_id' => (int) $actor->id,
                'recorded_at' => now(),
                'state_snapshot' => SupplierAssuranceResolver::snapshot($state),
                'supplier_name' => $locked->name,
                'criticality' => $locked->criticality,
            ]);
        });
    }
}
