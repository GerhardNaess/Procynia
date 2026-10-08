<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\SupplierRequirementOverride;
use App\Models\User;
use App\Services\Suppliers\Assurance\SupplierRequirementOverrideService;
use App\Services\Suppliers\SupplierAccessService;
use App\Support\CustomerContext;
use App\Support\Suppliers\SupplierValidationMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Legg til krav · Gjelder ikke denne leverandøren · Tilbake til automatisk vurdering on a supplier's
 * Krav og kvalifikasjoner (docs/supplier-assurance-v2-plan.md §5.5): supplier.assure only. Every
 * action is a new immutable row with a begrunnelse; SupplierRequirementOverrideService decides,
 * inside the lock, whether it is allowed.
 */
class SupplierRequirementOverrideController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly SupplierAccessService $access,
        private readonly SupplierRequirementOverrideService $overrides,
    ) {}

    public function store(Request $request, int $supplierId): RedirectResponse
    {
        $user = $this->customerContext->currentUser();
        abort_unless($user instanceof User && $this->access->canOpenModule($user), 403);
        $supplier = $this->access->findVisibleSupplier($user, $supplierId) ?? abort(404);
        abort_unless($this->access->canAssure($user), 403);

        $validated = $request->validate([
            'requirement_id' => ['required', 'integer'],
            'action' => ['required', 'string', Rule::in(SupplierRequirementOverride::ACTIONS)],
            'reason' => ['required', 'string', 'max:5000'],
        ], SupplierValidationMessages::messages(), SupplierValidationMessages::attributes());

        $this->overrides->record($user, $supplier, (int) $validated['requirement_id'], $validated['action'], $validated['reason']);

        return back()->with('success', __('procynia.supplier_management.flash.override_'.$validated['action']));
    }
}
