<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\SupplierControlRequirement;
use App\Models\User;
use App\Services\Suppliers\Assurance\SupplierControlRequirementService;
use App\Services\Suppliers\Assurance\SupplierRequirementPayload;
use App\Services\Suppliers\SupplierAccessService;
use App\Support\CustomerContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Leverandører → Kontrollkrav: the catalogue, and the requirements for one supplier that are written
 * from its page (docs/supplier-assurance-v2-plan.md §5.1, §13.2, §22.2).
 *
 * supplier.view reads the catalogue; supplier.assure — and nothing else — writes it. A requirement
 * of another customer, or one for a supplier the person cannot see, is a 404 like an id that does
 * not exist. SupplierControlRequirementService checks all of this again inside its transaction.
 */
class SupplierControlRequirementController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly SupplierAccessService $access,
        private readonly SupplierControlRequirementService $requirements,
        private readonly SupplierRequirementPayload $payload,
    ) {}

    public function index(): Response
    {
        $user = $this->authorizedUser();
        $canManage = $this->access->canAssure($user);

        return Inertia::render('App/SupplierManagement/ControlRequirements', [
            'requirements' => $this->payload->catalogue($user),
            'form' => $canManage ? $this->payload->formOptions($user) : null,
            'permissions' => ['can_manage' => $canManage],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->assuringUser();
        $this->requirements->create($user, $this->validated($request));

        return back()->with('success', __('procynia.supplier_management.flash.control_requirement_created'));
    }

    /** Krav for denne leverandøren: always applies to that supplier, no rule. */
    public function storeForSupplier(Request $request, int $supplierId): RedirectResponse
    {
        $user = $this->assuringUser();
        $supplier = $this->access->findVisibleSupplier($user, $supplierId) ?? abort(404);
        $this->requirements->create($user, $this->validated($request, forSupplier: true), $supplier);

        return back()->with('success', __('procynia.supplier_management.flash.control_requirement_created'));
    }

    public function update(Request $request, int $requirementId): RedirectResponse
    {
        $user = $this->assuringUser();
        $requirement = $this->manageable($user, $requirementId);
        $this->requirements->update($user, $requirement, $this->validated($request, forSupplier: $requirement->supplier_id !== null));

        return back()->with('success', __('procynia.supplier_management.flash.control_requirement_updated'));
    }

    public function retire(int $requirementId): RedirectResponse
    {
        $user = $this->assuringUser();
        $this->requirements->retire($user, $this->manageable($user, $requirementId));

        return back()->with('success', __('procynia.supplier_management.flash.control_requirement_retired'));
    }

    public function reactivate(int $requirementId): RedirectResponse
    {
        $user = $this->assuringUser();
        $this->requirements->reactivate($user, $this->manageable($user, $requirementId));

        return back()->with('success', __('procynia.supplier_management.flash.control_requirement_reactivated'));
    }

    public function destroy(int $requirementId): RedirectResponse
    {
        $user = $this->assuringUser();
        $this->requirements->delete($user, $this->manageable($user, $requirementId));

        return back()->with('success', __('procynia.supplier_management.flash.control_requirement_deleted'));
    }

    private function authorizedUser(): User
    {
        $user = $this->customerContext->currentUser();
        abort_unless($user instanceof User && $this->access->canOpenModule($user), 403);

        return $user;
    }

    private function assuringUser(): User
    {
        $user = $this->authorizedUser();
        abort_unless($this->access->canAssure($user), 403);

        return $user;
    }

    private function manageable(User $user, int $requirementId): SupplierControlRequirement
    {
        return $this->requirements->findManageable($user, $requirementId) ?? abort(404);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $forSupplier = false): array
    {
        return $request->validate(
            SupplierControlRequirementService::rules($forSupplier),
            SupplierControlRequirementService::messages(),
            SupplierControlRequirementService::attributes(),
        );
    }
}
