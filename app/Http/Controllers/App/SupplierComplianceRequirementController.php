<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Suppliers\SupplierAccessService;
use App\Services\Suppliers\SupplierComplianceRequirementService;
use App\Support\CustomerContext;
use App\Support\Suppliers\SupplierValidationMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Krav som gjelder leverandøren: «Legg til krav» and «Fjern krav» (docs/supplier-management-v1-plan.md
 * §7.3).
 *
 * supplier.edit on a supplier reached through SupplierAccessService — another customer's is a 404 —
 * together with compliance.view, which SupplierComplianceRequirementService checks. The requirement
 * itself is registered and worked in Etterlevelse og revisjon.
 */
class SupplierComplianceRequirementController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly SupplierAccessService $access,
        private readonly SupplierComplianceRequirementService $requirements,
    ) {}

    public function link(Request $request, int $supplierId): RedirectResponse
    {
        [$user, $supplier] = $this->editableSupplier($supplierId);

        $validated = $request->validate([
            'requirement_id' => ['required', 'integer'],
        ], SupplierValidationMessages::messages(), SupplierValidationMessages::attributes());

        $this->requirements->link($user, $supplier, (int) $validated['requirement_id']);

        return back()->with('success', __('procynia.supplier_management.flash.requirement_linked'));
    }

    public function unlink(int $supplierId, int $linkId): RedirectResponse
    {
        [$user, $supplier] = $this->editableSupplier($supplierId);

        $this->requirements->unlink($user, $supplier, $linkId);

        return back()->with('success', __('procynia.supplier_management.flash.requirement_unlinked'));
    }

    /** @return array{0: User, 1: Supplier} */
    private function editableSupplier(int $supplierId): array
    {
        $user = $this->customerContext->currentUser();
        abort_unless($this->access->canOpenModule($user), 403);

        $supplier = $this->access->findVisibleSupplier($user, $supplierId) ?? abort(404);
        abort_unless($user instanceof User && $this->access->canEdit($user), 403);

        return [$user, $supplier];
    }
}
