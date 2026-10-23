<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\CustomerAccessChange;
use App\Models\CustomerRole;
use App\Models\User;
use App\Services\Security\CustomerAccessChangeRecorder;
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
 *
 * Defining a role and handing it to a person are separate screens on purpose: this one answers
 * "what roles do we have", and Rediger bruker answers "what is this person". Assignment therefore
 * lives in UserController::update(), on the same save as the rest of the user's identity.
 */
class CustomerRoleController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        [$actor, $customerId] = $this->roleAdministrationContext($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in(CustomerPermissionCatalog::all())],
            'all_business_areas' => ['sometimes', 'boolean'],
            'business_area_ids' => ['sometimes', 'array'],
            'business_area_ids.*' => ['integer'],
        ], $this->nameMessages());

        $name = trim($validated['name']);
        $this->guardUniqueName($customerId, $name, null);

        DB::transaction(function () use ($customerId, $name, $validated, $actor): void {
            $role = CustomerRole::query()->create([
                'customer_id' => $customerId,
                'name' => $name,
                'description' => $this->normalizedDescription($validated['description'] ?? null),
                'is_active' => (bool) ($validated['is_active'] ?? true),
            ]);

            $role->syncPermissions($validated['permissions']);

            if (array_key_exists('all_business_areas', $validated) || array_key_exists('business_area_ids', $validated)) {
                $role->syncBusinessAreas(
                    (bool) ($validated['all_business_areas'] ?? false),
                    $validated['business_area_ids'] ?? [],
                );
            }

            $recorder = app(CustomerAccessChangeRecorder::class);
            $recorder->record($customerId, $actor, CustomerAccessChange::SUBJECT_CUSTOMER_ROLE, (int) $role->id, CustomerAccessChangeRecorder::ROLE_CREATED, null, $recorder->roleSnapshot($role));
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
        [$actor, $customerId] = $this->roleAdministrationContext($request);

        abort_unless($customerRole->customer_id === $customerId, 404);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
            'permissions' => ['sometimes', 'present', 'array'],
            'permissions.*' => ['string', Rule::in(CustomerPermissionCatalog::all())],
            // Where the role's rights apply: «Alle», or the listed fagområder. Same partial
            // semantics as `permissions`: both absent leaves the scope alone; sending either sets
            // it as a whole (a missing flag means not «Alle», missing ids mean none). Ids of
            // another tenant are dropped by syncBusinessAreas(), never stored.
            'all_business_areas' => ['sometimes', 'boolean'],
            'business_area_ids' => ['sometimes', 'present', 'array'],
            'business_area_ids.*' => ['integer'],
        ], $this->nameMessages());

        if (array_key_exists('name', $validated)) {
            $validated['name'] = trim($validated['name']);
            $this->guardUniqueName($customerId, $validated['name'], $customerRole->id);
        }

        DB::transaction(function () use ($customerRole, $validated, $actor, $customerId): void {
            $recorder = app(CustomerAccessChangeRecorder::class);
            $before = $recorder->roleSnapshot($customerRole);
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

            if (array_key_exists('all_business_areas', $validated) || array_key_exists('business_area_ids', $validated)) {
                $customerRole->syncBusinessAreas(
                    (bool) ($validated['all_business_areas'] ?? false),
                    $validated['business_area_ids'] ?? [],
                );
            }

            // A new description changes no one's access and is not recorded (F4).
            $recorder->record($customerId, $actor, CustomerAccessChange::SUBJECT_CUSTOMER_ROLE, (int) $customerRole->id, CustomerAccessChangeRecorder::ROLE_CHANGED, $before, $recorder->roleSnapshot($customerRole));
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
        [$actor, $customerId] = $this->roleAdministrationContext($request);

        abort_unless($customerRole->customer_id === $customerId, 404);

        DB::transaction(function () use ($customerRole, $actor, $customerId): void {
            // Who held it goes with it (FK cascade), so the row keeps their ids (F4).
            $recorder = app(CustomerAccessChangeRecorder::class);
            $before = $recorder->roleSnapshot($customerRole) + [
                'user_ids' => $customerRole->users()->pluck('users.id')->map(static fn ($id): int => (int) $id)->sort()->values()->all(),
            ];

            $customerRole->delete();

            $recorder->record($customerId, $actor, CustomerAccessChange::SUBJECT_CUSTOMER_ROLE, (int) $customerRole->id, CustomerAccessChangeRecorder::ROLE_DELETED, $before, null);
        });

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

    /**
     * The middleware trims the name and turns an empty one into null, so «required» is what answers
     * a blank name — it carries the role's own message, not Laravel's default.
     *
     * @return array<string, string>
     */
    private function nameMessages(): array
    {
        return [
            'name.required' => __('procynia.customer_env.roles.name_required'),
            'name.string' => __('procynia.customer_env.roles.name_required'),
            'name.max' => __('procynia.customer_env.roles.name_too_long'),
            'description.max' => __('procynia.customer_env.roles.description_too_long'),
        ];
    }

    private function guardUniqueName(int $customerId, string $name, ?int $ignoreRoleId): void
    {
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
