<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Suppliers\Assurance\SupplierAssuranceDecisionService;
use App\Services\Suppliers\SupplierAccessService;
use App\Support\CustomerContext;
use App\Support\Suppliers\SupplierValidationMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Registrer beslutning under Kontrollstatus on a supplier (docs/supplier-assurance-v2-plan.md §9.3,
 * §13.2): supplier.assure only — edit, assess and delete grant nothing here, and supplier.view only
 * reads. Every decision is a new immutable row; SupplierAssuranceDecisionService decides, inside the
 * lock and against the control state now, whether it may be written.
 */
class SupplierAssuranceDecisionController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly SupplierAccessService $access,
        private readonly SupplierAssuranceDecisionService $decisions,
    ) {}

    public function store(Request $request, int $supplierId): RedirectResponse
    {
        $user = $this->customerContext->currentUser();
        abort_unless($user instanceof User && $this->access->canOpenModule($user), 403);
        $supplier = $this->access->findVisibleSupplier($user, $supplierId) ?? abort(404);
        abort_unless($this->access->canAssure($user), 403);

        $validated = $request->validate(
            SupplierAssuranceDecisionService::rules(),
            [
                'decided_on.before_or_equal' => __('procynia.supplier_management.validation.decided_on_future'),
                'decided_on.date_format' => __('procynia.supplier_management.validation.rules.date'),
                'follow_up_note.required' => __('procynia.supplier_management.validation.follow_up_note_required'),
            ] + SupplierValidationMessages::messages(),
            SupplierValidationMessages::attributes(),
        );

        $this->decisions->record($user, $supplier, $validated);

        return back()->with('success', __('procynia.supplier_management.flash.decision_recorded'));
    }
}
