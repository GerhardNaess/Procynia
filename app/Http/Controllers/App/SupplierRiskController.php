<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Risk;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Risk\RiskCreator;
use App\Services\Suppliers\SupplierAccessService;
use App\Services\Suppliers\SupplierRiskService;
use App\Support\CustomerContext;
use App\Support\Risk\RiskValidationMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Risikoer som gjelder leverandøren: «Opprett risiko», «Koble til eksisterende risiko» and removing
 * such a link (docs/supplier-management-v1-plan.md §7.2).
 *
 * supplier.edit on a supplier reached through SupplierAccessService — another customer's is a 404 —
 * together with the right in Risiko, which SupplierRiskService and RiskCreator check. The risk's
 * fields follow RiskCreator::rules(); Ny risiko asks what the risk is and who owns it, and the risk
 * starts as Identifisert, exactly as when it is registered in Risiko.
 */
class SupplierRiskController extends Controller
{
    private const CREATE_FIELDS = ['title', 'cause', 'event', 'consequence', 'business_area_id', 'owner_user_id'];

    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly SupplierAccessService $access,
        private readonly SupplierRiskService $risks,
    ) {}

    public function store(Request $request, int $supplierId): RedirectResponse
    {
        [$user, $supplier] = $this->editableSupplier($supplierId);

        $validated = $request->validate(
            array_intersect_key(RiskCreator::rules(), array_flip(self::CREATE_FIELDS)),
            RiskValidationMessages::messages(),
            RiskValidationMessages::attributes(),
        );

        $this->risks->create($user, $supplier, $validated + ['status' => Risk::STATUS_IDENTIFIED]);

        return back()->with('success', __('procynia.supplier_management.flash.risk_created'));
    }

    public function link(Request $request, int $supplierId): RedirectResponse
    {
        [$user, $supplier] = $this->editableSupplier($supplierId);

        $validated = $request->validate([
            'risk_id' => ['required', 'integer'],
        ], RiskValidationMessages::messages(), RiskValidationMessages::attributes());

        $this->risks->link($user, $supplier, (int) $validated['risk_id']);

        return back()->with('success', __('procynia.supplier_management.flash.risk_linked'));
    }

    public function unlink(int $supplierId, int $linkId): RedirectResponse
    {
        [$user, $supplier] = $this->editableSupplier($supplierId);

        $this->risks->unlink($user, $supplier, $linkId);

        return back()->with('success', __('procynia.supplier_management.flash.risk_unlinked'));
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
