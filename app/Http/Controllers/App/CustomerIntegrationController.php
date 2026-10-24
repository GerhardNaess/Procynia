<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Services\Api\ApiClientGrantService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Kundemiljø → Integrasjoner: the System Owner gives an approved application read access, changes
 * its roles, and takes the access back (D13). The rules — System Owner only, own customer only,
 * active roles only, recorded with actor and time — are ApiClientGrantService's; this only maps the
 * request onto it and back to the tab.
 */
class CustomerIntegrationController extends Controller
{
    public function __construct(private readonly ApiClientGrantService $grants) {}

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->grants->mayAdminister($request->user()), 403);

        $validated = $request->validate([
            'api_client_id' => ['required', 'integer'],
            'customer_role_ids' => ['present', 'array'],
            'customer_role_ids.*' => ['integer'],
        ]);

        $this->grants->grant($request->user(), (int) $validated['api_client_id'], $validated['customer_role_ids']);

        return $this->backToTab(__('procynia.customer_env.integrations.flash.granted'));
    }

    public function updateRoles(Request $request, int $grantId): RedirectResponse
    {
        abort_unless($this->grants->mayAdminister($request->user()), 403);

        $validated = $request->validate([
            'customer_role_ids' => ['present', 'array'],
            'customer_role_ids.*' => ['integer'],
        ]);

        $this->grants->updateRoles($request->user(), $grantId, $validated['customer_role_ids']);

        return $this->backToTab(__('procynia.customer_env.integrations.flash.roles_changed'));
    }

    public function destroy(Request $request, int $grantId): RedirectResponse
    {
        abort_unless($this->grants->mayAdminister($request->user()), 403);

        $this->grants->revoke($request->user(), $grantId);

        return $this->backToTab(__('procynia.customer_env.integrations.flash.revoked'));
    }

    private function backToTab(string $message): RedirectResponse
    {
        return redirect()
            ->route('app.customer-environment.index', ['tab' => 'integrations'])
            ->with('success', $message);
    }
}
