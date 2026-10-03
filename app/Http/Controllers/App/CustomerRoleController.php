<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\CustomerRole;
use App\Models\User;
use App\Support\CustomerContext;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Administration of customer-defined roles, inside Kundemiljø → Tilganger.
 *
 * System Owner only, exactly like the existing permission matrix next to it. That is what keeps the
 * surface impossible to lock yourself out of: the authority to edit roles does not come from a
 * role, so no combination of ticks here can remove it.
 *
 * Every read and every write is scoped to the acting user's own customer. A role from another
 * tenant is not an authorization failure to be reported — it is not found.
 */
class CustomerRoleController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        [, $customerId] = $this->roleAdministrationContext($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in(CustomerPermissionCatalog::all())],
        ]);

        $name = trim($validated['name']);
        $this->guardUniqueName($customerId, $name, null);

        DB::transaction(function () use ($customerId, $name, $validated): void {
            $role = CustomerRole::query()->create([
                'customer_id' => $customerId,
                'name' => $name,
                'description' => $this->normalizedDescription($validated['description'] ?? null),
                'is_active' => (bool) ($validated['is_active'] ?? true),
            ]);

            $role->syncPermissions($validated['permissions']);
        });

        return $this->backToPermissions();
    }

    /**
     * Partial by design. The domain tables in the gallery toggle a single permission and send only
     * `permissions`; the role dialogue sends name, description and active state as well. Requiring
     * the whole role on every write would make a checkbox depend on fields the checkbox does not
     * show.
     */
    public function update(Request $request, CustomerRole $customerRole): RedirectResponse
    {
        [, $customerId] = $this->roleAdministrationContext($request);

        abort_unless($customerRole->customer_id === $customerId, 404);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
            'permissions' => ['sometimes', 'present', 'array'],
            'permissions.*' => ['string', Rule::in(CustomerPermissionCatalog::all())],
        ]);

        if (array_key_exists('name', $validated)) {
            $validated['name'] = trim($validated['name']);
            $this->guardUniqueName($customerId, $validated['name'], $customerRole->id);
        }

        DB::transaction(function () use ($customerRole, $validated): void {
            $attributes = [];

            if (array_key_exists('name', $validated)) {
                $attributes['name'] = $validated['name'];
            }

            if (array_key_exists('description', $validated)) {
                $attributes['description'] = $this->normalizedDescription($validated['description']);
            }

            if (array_key_exists('is_active', $validated)) {
                $attributes['is_active'] = (bool) $validated['is_active'];
            }

            if ($attributes !== []) {
                $customerRole->fill($attributes)->save();
            }

            if (array_key_exists('permissions', $validated)) {
                $customerRole->syncPermissions($validated['permissions']);
            }
        });

        return $this->backToPermissions();
    }

    /**
     * Deleting takes the role's assignments with it (FK cascade). Deactivation is the reversible
     * alternative and is offered beside this in the UI, so a customer who only wants the role to
     * stop granting does not have to lose who held it.
     */
    public function destroy(Request $request, CustomerRole $customerRole): RedirectResponse
    {
        [, $customerId] = $this->roleAdministrationContext($request);

        abort_unless($customerRole->customer_id === $customerId, 404);

        $customerRole->delete();

        return $this->backToPermissions();
    }

    /**
     * Replace one user's set of customer roles. The user and every role must belong to the acting
     * user's customer; a role id from elsewhere is not found rather than silently skipped, because
     * silently skipping would show the administrator a saved assignment that does not exist.
     */
    public function updateUserRoles(Request $request, User $user): RedirectResponse
    {
        [$actor, $customerId] = $this->roleAdministrationContext($request);

        abort_unless(
            $user->customer_id === $customerId
            && $this->customerContext->canManageCustomerUser($actor, $user),
            404,
        );

        $validated = $request->validate([
            'role_ids' => ['present', 'array'],
            'role_ids.*' => ['integer'],
        ]);

        $roleIds = array_values(array_unique(array_map('intval', $validated['role_ids'])));

        $ownedRoleIds = CustomerRole::query()
            ->forCustomer($customerId)
            ->whereIn('id', $roleIds ?: [0])
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        abort_unless(count($ownedRoleIds) === count($roleIds), 404);

        $user->customerRoles()->sync(
            collect($ownedRoleIds)
                ->mapWithKeys(fn (int $id): array => [$id => ['customer_id' => $customerId]])
                ->all()
        );

        return $this->backToPermissions();
    }

    /** @return array{0: User, 1: int} */
    private function roleAdministrationContext(Request $request): array
    {
        /** @var User|null $actor */
        $actor = $request->user();
        $customerId = $this->customerContext->currentCustomerId($actor);

        abort_unless(
            $actor instanceof User
            && $actor->isSystemOwner()
            && $customerId !== null,
            403,
        );

        return [$actor, $customerId];
    }

    private function guardUniqueName(int $customerId, string $name, ?int $ignoreRoleId): void
    {
        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => __('procynia.customer_env.roles.name_required'),
            ]);
        }

        $query = CustomerRole::query()
            ->forCustomer($customerId)
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)]);

        if ($ignoreRoleId !== null) {
            $query->whereKeyNot($ignoreRoleId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => __('procynia.customer_env.roles.name_taken'),
            ]);
        }
    }

    private function normalizedDescription(?string $value): ?string
    {
        $description = trim((string) $value);

        return $description !== '' ? $description : null;
    }

    private function backToPermissions(): RedirectResponse
    {
        return redirect()->route('app.customer-environment.index', ['tab' => 'permissions']);
    }
}
