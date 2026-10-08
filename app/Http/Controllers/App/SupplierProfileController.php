<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Suppliers\Assurance\SupplierProfileService;
use App\Services\Suppliers\SupplierAccessService;
use App\Support\CustomerContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Leverandørprofil on the supplier page: Fyll ut profil / Rediger profil
 * (docs/supplier-assurance-v2-plan.md §4, §13.2). The profile is read by SupplierManagementController.
 *
 * supplier.edit, and only that: the profile is facts about the supplier, like its master data.
 * supplier.assure — Leverandørkontroll — grants nothing here, nor do supplier.assess and
 * supplier.delete. The supplier is reached through SupplierAccessService, so another customer's
 * supplier is a 404. An ended supplier is read-only until it is reopened; SupplierProfileService
 * checks all of this again with the supplier locked.
 */
class SupplierProfileController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly SupplierAccessService $access,
        private readonly SupplierProfileService $profiles,
    ) {}

    public function update(Request $request, int $supplierId): RedirectResponse
    {
        $user = $this->customerContext->currentUser();
        abort_unless($user instanceof User && $this->access->canOpenModule($user), 403);
        $supplier = $this->access->findVisibleSupplier($user, $supplierId) ?? abort(404);
        abort_unless($this->access->canEdit($user), 403);

        if ($supplier->isEnded()) {
            return back()->with('error', __('procynia.supplier_management.validation.reopen_before_edit'));
        }

        $validated = $request->validate(SupplierProfileService::rules(), SupplierProfileService::messages(), SupplierProfileService::attributes());

        $this->profiles->save($supplier, $user, SupplierProfileService::answers($validated), $validated['reason'] ?? null);

        return back()->with('success', __('procynia.supplier_management.flash.profile_saved'));
    }
}
