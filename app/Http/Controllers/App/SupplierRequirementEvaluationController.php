<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Suppliers\Assurance\SupplierRequirementEvaluationService;
use App\Services\Suppliers\SupplierAccessService;
use App\Support\CustomerContext;
use App\Support\Suppliers\SupplierValidationMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Kontroller krav and Bekreft kravene på nytt on a supplier's Krav og kvalifikasjoner
 * (docs/supplier-assurance-v2-plan.md §8, §10.3, §13.2): supplier.assure only — edit, assess and
 * delete grant nothing here, and supplier.view only reads. Every control is a new immutable row;
 * SupplierRequirementEvaluationService decides, inside the lock, whether it may be written.
 */
class SupplierRequirementEvaluationController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly SupplierAccessService $access,
        private readonly SupplierRequirementEvaluationService $evaluations,
    ) {}

    public function store(Request $request, int $supplierId): RedirectResponse
    {
        [$user, $supplier] = $this->assurableSupplier($supplierId);

        $validated = $request->validate(
            SupplierRequirementEvaluationService::rules(),
            [
                'evaluated_on.before_or_equal' => __('procynia.supplier_management.validation.evaluated_on_future'),
                'evaluated_on.date_format' => __('procynia.supplier_management.validation.rules.date'),
                'accepted_until.after_or_equal' => __('procynia.supplier_management.validation.accepted_until_range'),
                'accepted_until.before_or_equal' => __('procynia.supplier_management.validation.accepted_until_range'),
                'accepted_until.date_format' => __('procynia.supplier_management.validation.rules.date'),
                'document_ids.*.distinct' => __('procynia.supplier_management.validation.document_not_available'),
            ] + SupplierValidationMessages::messages(),
            SupplierValidationMessages::attributes(),
        );

        $this->evaluations->record($user, $supplier, $validated);

        return back()->with('success', __('procynia.supplier_management.flash.evaluation_recorded'));
    }

    /** Bekreft kravene på nytt, on the renewed edition of a document. */
    public function reconfirm(Request $request, int $supplierId, int $documentId): RedirectResponse
    {
        [$user, $supplier] = $this->assurableSupplier($supplierId);
        $supplier->documents()->find($documentId) ?? abort(404);

        $validated = $request->validate([
            'requirement_ids' => ['required', 'array', 'max:200'],
            'requirement_ids.*' => ['integer'],
            'rationale' => ['required', 'string', 'max:10000'],
        ], SupplierValidationMessages::messages(), SupplierValidationMessages::attributes());

        $written = $this->evaluations->reconfirm($user, $supplier, $documentId, array_map('intval', $validated['requirement_ids']), $validated['rationale']);

        return back()->with('success', trans_choice('procynia.supplier_management.flash.requirements_reconfirmed', count($written), ['count' => count($written)]));
    }

    /** @return array{0: User, 1: Supplier} */
    private function assurableSupplier(int $supplierId): array
    {
        $user = $this->customerContext->currentUser();
        abort_unless($user instanceof User && $this->access->canOpenModule($user), 403);
        $supplier = $this->access->findVisibleSupplier($user, $supplierId) ?? abort(404);
        abort_unless($this->access->canAssure($user), 403);

        return [$user, $supplier];
    }
}
