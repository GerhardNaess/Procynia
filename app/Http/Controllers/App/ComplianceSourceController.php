<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\ComplianceSource;
use App\Models\User;
use App\Services\Compliance\ComplianceAccessService;
use App\Support\Compliance\ComplianceValidationMessages;
use App\Support\CustomerContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Kravkilder, managed from the Krav register — not a main area of their own.
 *
 * compliance.edit registers and changes a source; compliance.delete deletes one, and only while no
 * requirement uses it. A source of another customer is a 404, like one that does not exist.
 */
class ComplianceSourceController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly ComplianceAccessService $access,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $user = $this->authorizedUser();
        abort_unless($this->access->canEdit($user), 403);

        ComplianceSource::query()->create($this->validated($request) + [
            'customer_id' => (int) $user->customer_id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        return back()->with('success', __('procynia.compliance.sources.flash.created'));
    }

    public function update(Request $request, int $sourceId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $source = $this->access->findVisibleSource($user, $sourceId) ?? abort(404);
        abort_unless($this->access->canEdit($user), 403);

        $source->fill($this->validated($request) + ['updated_by' => $user->id])->save();

        return back()->with('success', __('procynia.compliance.sources.flash.updated'));
    }

    public function destroy(int $sourceId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $source = $this->access->findVisibleSource($user, $sourceId) ?? abort(404);
        abort_unless($this->access->canDelete($user), 403);

        if ($source->isInUse()) {
            return back()->with('error', __('procynia.compliance.sources.in_use'));
        }

        try {
            $source->delete();
        } catch (QueryException) {
            // A requirement was registered under it since the check above; its foreign key refused.
            return back()->with('error', __('procynia.compliance.sources.in_use'));
        }

        return back()->with('success', __('procynia.compliance.sources.flash.deleted'));
    }

    private function authorizedUser(): User
    {
        $user = $this->customerContext->currentUser();

        abort_unless($this->access->canOpenModule($user), 403);

        return $user;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'version' => ['nullable', 'string', 'max:100'],
            'kind' => ['required', 'string', Rule::in(ComplianceSource::KINDS)],
            'description' => ['nullable', 'string', 'max:5000'],
        ], ComplianceValidationMessages::messages(), ComplianceValidationMessages::attributes());

        $version = trim((string) ($validated['version'] ?? ''));
        $description = trim((string) ($validated['description'] ?? ''));

        return [
            'name' => trim($validated['name']),
            'version' => $version !== '' ? $version : null,
            'kind' => $validated['kind'],
            'description' => $description !== '' ? $description : null,
        ];
    }
}
