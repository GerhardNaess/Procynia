<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Suppliers\Assurance\SupplierControlRequirementService;
use App\Services\Suppliers\Assurance\SupplierRequirementWikiGuidance;
use App\Services\Suppliers\SupplierAccessService;
use App\Support\CustomerContext;
use App\Support\Suppliers\SupplierValidationMessages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * «Veiledning fra Enterprise Wiki» on a control requirement (supplier-assurance-v2-plan §28): search
 * Wiki pages to link, link one, remove one. Reading the guidance is part of the Kontrollkrav and
 * supplier pages (SupplierRequirementPayload).
 *
 * Every action needs supplier.assure and Wiki read access (SupplierRequirementWikiGuidance::canManage),
 * checked again inside the service. A requirement of another customer, or for a supplier the person
 * cannot see, is a 404 like an id that does not exist; another customer's page is «not available»,
 * the same as a missing one.
 */
class SupplierRequirementWikiGuidanceController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly SupplierAccessService $access,
        private readonly SupplierControlRequirementService $requirements,
        private readonly SupplierRequirementWikiGuidance $guidance,
    ) {}

    public function search(Request $request): JsonResponse
    {
        $user = $this->managingUser();
        $term = $request->validate(['search' => ['nullable', 'string', 'max:200']])['search'] ?? '';

        return response()->json($this->guidance->search($user, (string) $term));
    }

    public function store(Request $request, int $requirementId): RedirectResponse
    {
        $user = $this->managingUser();
        $requirement = $this->requirements->findManageable($user, $requirementId) ?? abort(404);
        $validated = $request->validate(['wiki_page_id' => ['required', 'integer']], SupplierValidationMessages::messages(), SupplierValidationMessages::attributes());

        $this->guidance->link($user, $requirement, (int) $validated['wiki_page_id']);

        return back()->with('success', __('procynia.supplier_management.flash.wiki_guidance_linked'));
    }

    public function destroy(int $requirementId, int $pageId): RedirectResponse
    {
        $user = $this->managingUser();
        $requirement = $this->requirements->findManageable($user, $requirementId) ?? abort(404);

        $this->guidance->unlink($user, $requirement, $pageId);

        return back()->with('success', __('procynia.supplier_management.flash.wiki_guidance_unlinked'));
    }

    private function managingUser(): User
    {
        $user = $this->customerContext->currentUser();
        abort_unless($user instanceof User && $this->access->canOpenModule($user), 403);
        abort_unless($this->guidance->canManage($user), 403);

        return $user;
    }
}
