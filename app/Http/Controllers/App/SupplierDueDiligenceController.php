<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\SupplierDueDiligenceAssessment;
use App\Models\User;
use App\Services\Suppliers\Assurance\SupplierDueDiligenceService;
use App\Services\Suppliers\SupplierAccessService;
use App\Support\CustomerContext;
use App\Support\Suppliers\SupplierValidationMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Registrer aktsomhetsvurdering on a supplier (docs/supplier-assurance-v2-plan.md §11, §13.2):
 * supplier.assure only — edit, assess and delete grant nothing here, and supplier.view only reads.
 * Every assessment is a new immutable row, written by SupplierDueDiligenceService inside the lock.
 */
class SupplierDueDiligenceController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly SupplierAccessService $access,
        private readonly SupplierDueDiligenceService $dueDiligence,
    ) {}

    public function store(Request $request, int $supplierId): RedirectResponse
    {
        $user = $this->customerContext->currentUser();
        abort_unless($user instanceof User && $this->access->canOpenModule($user), 403);
        $supplier = $this->access->findVisibleSupplier($user, $supplierId) ?? abort(404);
        abort_unless($this->access->canAssure($user), 403);

        $levelMessages = [];

        foreach (SupplierDueDiligenceAssessment::AREAS as $area) {
            $levelMessages[$area.'.required'] = __('procynia.supplier_management.validation.due_diligence_level_required');
            $levelMessages[$area.'.in'] = __('procynia.supplier_management.validation.due_diligence_level_required');
        }

        $validated = $request->validate(
            SupplierDueDiligenceService::rules(),
            $levelMessages + [
                'assessed_on.before_or_equal' => __('procynia.supplier_management.validation.assessed_on_future'),
                'assessed_on.date_format' => __('procynia.supplier_management.validation.rules.date'),
            ] + SupplierValidationMessages::messages(),
            SupplierValidationMessages::attributes(),
        );

        $this->dueDiligence->record($user, $supplier, $validated);

        return back()->with('success', __('procynia.supplier_management.flash.due_diligence_recorded'));
    }
}
