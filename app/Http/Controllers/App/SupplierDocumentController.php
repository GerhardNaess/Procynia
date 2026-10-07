<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\SupplierDocument;
use App\Models\User;
use App\Services\Suppliers\SupplierAccessService;
use App\Services\Suppliers\SupplierDocumentService;
use App\Support\CustomerContext;
use App\Support\Suppliers\SupplierValidationMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Dokumentasjon on the supplier page: registers, corrects, renews and deletes the description of a
 * supplier's documentation — never a file (plan §4.4).
 *
 * supplier.edit, and only that (plan §9.2): supplier.assess and supplier.delete grant nothing here,
 * and supplier.view only reads, which SupplierManagementController does. The supplier is reached
 * through SupplierAccessService, and the document only through that supplier, so another customer's
 * supplier or document is a 404. An ended supplier is read-only until it is reopened.
 */
class SupplierDocumentController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly SupplierAccessService $access,
        private readonly SupplierDocumentService $documents,
    ) {}

    public function store(Request $request, int $supplierId): RedirectResponse
    {
        [$user, $supplier] = $this->editableSupplier($supplierId);

        if ($supplier->isEnded()) {
            return $this->endedRefusal();
        }

        $this->documents->create($supplier, $user, $this->validated($request));

        return back()->with('success', __('procynia.supplier_management.flash.document_created'));
    }

    public function update(Request $request, int $supplierId, int $documentId): RedirectResponse
    {
        [$user, $supplier] = $this->editableSupplier($supplierId);
        $document = $this->documentOf($supplier, $documentId);

        if ($supplier->isEnded()) {
            return $this->endedRefusal();
        }

        $this->documents->update($document, $user, $this->validated($request));

        return back()->with('success', __('procynia.supplier_management.flash.document_updated'));
    }

    /** Registrer fornyet: the type is the renewed document's, never the form's. */
    public function renew(Request $request, int $supplierId, int $documentId): RedirectResponse
    {
        [$user, $supplier] = $this->editableSupplier($supplierId);
        $document = $this->documentOf($supplier, $documentId);

        if ($supplier->isEnded()) {
            return $this->endedRefusal();
        }

        $this->documents->renew($document, $user, $this->validated($request, renewal: true));

        return back()->with('success', __('procynia.supplier_management.flash.document_renewed'));
    }

    public function destroy(int $supplierId, int $documentId): RedirectResponse
    {
        [, $supplier] = $this->editableSupplier($supplierId);
        $document = $this->documentOf($supplier, $documentId);

        if ($supplier->isEnded()) {
            return $this->endedRefusal();
        }

        $this->documents->delete($document);

        return back()->with('success', __('procynia.supplier_management.flash.document_deleted'));
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

    private function documentOf(Supplier $supplier, int $documentId): SupplierDocument
    {
        $document = $supplier->documents()->find($documentId) ?? abort(404);
        $document->setRelation('supplier', $supplier);

        return $document;
    }

    private function endedRefusal(): RedirectResponse
    {
        return back()->with('error', __('procynia.supplier_management.validation.reopen_before_edit'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $renewal = false): array
    {
        $rules = SupplierDocumentService::rules();

        if ($renewal) {
            unset($rules['document_type']);
        }

        return $request->validate($rules, [
            'valid_until.after_or_equal' => __('procynia.supplier_management.validation.valid_until_before_valid_from'),
            'valid_from.date_format' => __('procynia.supplier_management.validation.rules.date'),
            'valid_until.date_format' => __('procynia.supplier_management.validation.rules.date'),
        ] + SupplierValidationMessages::messages(), SupplierValidationMessages::attributes());
    }
}
