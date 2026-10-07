<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Improvements\ImprovementCaseCreator;
use App\Services\Suppliers\SupplierAccessService;
use App\Services\Suppliers\SupplierImprovementHandoffService;
use App\Support\CustomerContext;
use App\Support\Improvements\ImprovementValidationMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Avvik og forbedringer hos leverandøren: «Følg opp i Avvik og forbedringer» (from the supplier or
 * one of its assessments), «Koble til eksisterende sak» and removing such a link
 * (docs/supplier-management-v1-plan.md §7.4).
 *
 * supplier.edit on a supplier reached through SupplierAccessService — another customer's is a 404 —
 * together with the right in Avvik og forbedringer, which SupplierImprovementHandoffService and
 * ImprovementCaseCreator check. The case's fields follow ImprovementCaseCreator::rules(), except
 * Hendelsesdato, which the hand-off does not ask for.
 */
class SupplierImprovementController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly SupplierAccessService $access,
        private readonly SupplierImprovementHandoffService $handoff,
    ) {}

    public function store(Request $request, int $supplierId): RedirectResponse
    {
        [$user, $supplier] = $this->editableSupplier($supplierId);

        $rules = array_intersect_key(ImprovementCaseCreator::rules(), array_flip(['type', 'title', 'description', 'business_area_id', 'owner_user_id', 'due_date']));
        $validated = $request->validate($rules + [
            'handoff_key' => ['required', 'uuid'],
            'supplier_assessment_id' => ['nullable', 'integer'],
        ], ImprovementValidationMessages::messages(), ImprovementValidationMessages::attributes());

        $this->handoff->handOff($user, $supplier, $validated);

        return back()->with('success', __('procynia.supplier_management.flash.handed_off'));
    }

    public function link(Request $request, int $supplierId): RedirectResponse
    {
        [$user, $supplier] = $this->editableSupplier($supplierId);

        $validated = $request->validate([
            'improvement_case_id' => ['required', 'integer'],
        ], ImprovementValidationMessages::messages(), ImprovementValidationMessages::attributes());

        $this->handoff->link($user, $supplier, (int) $validated['improvement_case_id']);

        return back()->with('success', __('procynia.supplier_management.flash.case_linked'));
    }

    public function unlink(int $supplierId, int $linkId): RedirectResponse
    {
        [$user, $supplier] = $this->editableSupplier($supplierId);

        $this->handoff->unlink($user, $supplier, $linkId);

        return back()->with('success', __('procynia.supplier_management.flash.case_unlinked'));
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
