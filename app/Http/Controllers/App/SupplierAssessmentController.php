<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Suppliers\SupplierAccessService;
use App\Services\Suppliers\SupplierAssessmentService;
use App\Support\CustomerContext;
use App\Support\Suppliers\SupplierValidationMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Vurder leverandør: registers a supplier assessment — how the supplier performs now.
 *
 * supplier.assess, and only that: supplier.edit does not grant it, and supplier.assess grants
 * nothing else (plan §9.2). The supplier is reached through SupplierAccessService, so another
 * customer's supplier is a 404. Only an active supplier is assessed; the service checks that again
 * with the row locked.
 */
class SupplierAssessmentController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly SupplierAccessService $access,
        private readonly SupplierAssessmentService $assessments,
    ) {}

    public function store(Request $request, int $supplierId): RedirectResponse
    {
        $user = $this->customerContext->currentUser();
        abort_unless($this->access->canOpenModule($user), 403);

        $supplier = $this->access->findVisibleSupplier($user, $supplierId) ?? abort(404);
        abort_unless($user instanceof User && $this->access->canAssess($user), 403);

        $validated = $request->validate(
            SupplierAssessmentService::rules(),
            [
                'assessed_on.before_or_equal' => __('procynia.supplier_management.validation.assessed_on_future'),
                'assessed_on.date_format' => __('procynia.supplier_management.validation.rules.date'),
            ] + SupplierValidationMessages::messages(),
            SupplierValidationMessages::attributes(),
        );

        $this->assessments->record($supplier, $user, $validated);

        return back()->with('success', __('procynia.supplier_management.flash.assessed'));
    }
}
