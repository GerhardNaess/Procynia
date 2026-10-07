<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Suppliers\SupplierAccessService;
use App\Support\CustomerContext;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Leverandøroppfølging → Leverandører: the register.
 *
 * The route guard has already refused a customer without the `supplier` module; this controller
 * refuses a user without supplier.view (403). System Owner is no exception — supplier is an
 * explicit-grant domain, so the check below is the whole rule (see SupplierAccessService).
 *
 * The register itself arrives with the supplier records. Until then the page is the module's
 * entrance and says that nothing is registered yet.
 */
class SupplierManagementController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly SupplierAccessService $access,
    ) {}

    public function index(): Response
    {
        $this->authorizedUser();

        return Inertia::render('App/SupplierManagement/Index');
    }

    private function authorizedUser(): User
    {
        $user = $this->customerContext->currentUser();

        abort_unless($this->access->canOpenModule($user), 403);

        return $user;
    }
}
