<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\User;
use App\Support\CustomerContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
    ) {
    }

    public function index(Request $request): Response
    {
        [$actor, $customerId] = $this->customerBidManagerContext($request);

        $users = $this->scopedCustomerUsersQuery($customerId)
            ->with([
                'department:id,name',
                'departments:id,name,is_active',
                'managedDepartments:id,name,is_active',
            ])
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn (User $user): bool => $this->customerContext->canManageCustomerUser($actor, $user))
            ->values()
            ->map(fn (User $user): array => $this->userListItem($user, $actor, $customerId))
            ->all();

        return Inertia::render('App/Users/Index', [
            'users' => $users,
        ]);
    }

    public function create(Request $request): Response
    {
        [$actor, $customerId] = $this->customerBidManagerContext($request);

        return Inertia::render('App/Users/Create', [
            'redirectTo' => $this->pageRedirectTarget($request),
            'bidRoleOptions' => $this->bidRoleOptions($actor),
            'bidManagerScopeOptions' => $this->bidManagerScopeOptions(),
            'departmentOptions' => $this->membershipDepartmentOptions($actor, $customerId),
            'managedDepartmentOptions' => $this->managedDepartmentOptions($actor, $customerId),
            'canEditRole' => $actor->isSystemOwner(),
            'canEditBidManagerScope' => $actor->isSystemOwner(),
        ]);
    }

    public function edit(Request $request, int $user): Response
    {
        [$actor, $customerId] = $this->customerBidManagerContext($request);
        $record = $this->scopedCustomerUser($customerId, $user, $actor);
        $allowSelfScopeRecovery = $record->is($actor);

        return Inertia::render('App/Users/Edit', [
            'redirectTo' => $this->pageRedirectTarget($request),
            'user' => $this->editUserPayload($record, $actor, $customerId),
            'bidRoleOptions' => $this->bidRoleOptions($actor, $record),
            'bidManagerScopeOptions' => $this->bidManagerScopeOptions(),
            'departmentOptions' => $this->membershipDepartmentOptions(
                $actor,
                $customerId,
                $record->departments->pluck('id')->all(),
                $allowSelfScopeRecovery,
            ),
            'managedDepartmentOptions' => $this->managedDepartmentOptions(
                $actor,
                $customerId,
                $record->managedDepartments->pluck('id')->all(),
                $allowSelfScopeRecovery,
            ),
            'canEditRole' => $this->canEditBidRole($actor, $record),
            'canEditBidManagerScope' => $this->canEditBidManagerScope($actor, $record),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        [$actor, $customerId] = $this->customerBidManagerContext($request);

        $this->abortIfBidManagerAttemptsProtectedUserFields($actor, $request);

        $validated = $request->validate($this->storeValidationRules($actor));

        $targetBidRole = $actor->isSystemOwner()
            ? $validated['bid_role']
            : User::BID_ROLE_CONTRIBUTOR;

        if ($actor->isSystemOwner()) {
            $this->ensureActorCanAssignBidRole($actor, null, $targetBidRole);
        }

        [$bidManagerScope, $managedDepartmentIds] = $actor->isSystemOwner()
            ? $this->validatedBidManagerScope($actor, $customerId, array_merge($validated, [
                'bid_role' => $targetBidRole,
            ]))
            : [null, []];
        $departmentIds = $this->validatedMembershipDepartmentIds(
            $actor,
            $customerId,
            $validated,
            null,
            $targetBidRole,
        );

        DB::transaction(function () use ($validated, $customerId, $departmentIds, $bidManagerScope, $managedDepartmentIds, $targetBidRole): void {
            $user = User::create([
                'name' => Str::squish($validated['name']),
                'email' => Str::lower(trim($validated['email'])),
                'password' => $validated['password'],
                'role' => User::customerRoleForBidRole($targetBidRole),
                'bid_role' => $targetBidRole,
                'bid_manager_scope' => $bidManagerScope,
                'is_active' => true,
                'customer_id' => $customerId,
                'department_id' => null,
            ]);

            $this->syncUserDepartments($user, $departmentIds);
            $this->syncManagedDepartments($user, $bidManagerScope, $managedDepartmentIds);
        });

        return $this->successRedirect($request, 'app.users.index')
            ->with('success', 'Brukeren ble opprettet.');
    }

    public function update(Request $request, int $user): RedirectResponse
    {
        [$actor, $customerId] = $this->customerBidManagerContext($request);
        $record = $this->scopedCustomerUser($customerId, $user, $actor);
        $allowSelfScopeRecovery = $record->is($actor);

        $this->abortIfBidManagerAttemptsProtectedUserFields($actor, $request, $record);

        $validated = $request->validate($this->updateValidationRules($actor, $record));

        $nextBidRole = $this->canEditBidRole($actor, $record)
            ? $validated['bid_role']
            : $record->resolvedBidRole();
        $nextRole = User::customerRoleForBidRole($nextBidRole);

        if ($this->canEditBidRole($actor, $record)) {
            $this->ensureActorCanAssignBidRole($actor, $record, $nextBidRole);
        }

        [$bidManagerScope, $managedDepartmentIds] = $this->canEditBidManagerScope($actor, $record)
            ? $this->validatedBidManagerScope(
                $actor,
                $customerId,
                array_merge($validated, [
                    'bid_role' => $nextBidRole,
                ]),
                $record,
                $allowSelfScopeRecovery,
            )
            : [$record->resolvedBidManagerScope(), $record->managedDepartmentIds()];
        $departmentIds = $this->validatedMembershipDepartmentIds(
            $actor,
            $customerId,
            $validated,
            $record,
            $nextBidRole,
            $allowSelfScopeRecovery,
        );

        if ($this->wouldRemoveLastActiveSystemOwner($record, $nextBidRole, (bool) $record->is_active)) {
            throw ValidationException::withMessages([
                'bid_role' => 'Kunden må ha minst én aktiv systemeier.',
            ]);
        }

        DB::transaction(function () use ($record, $validated, $nextRole, $nextBidRole, $departmentIds, $bidManagerScope, $managedDepartmentIds): void {
            $attributes = [
                'name' => Str::squish($validated['name']),
                'role' => $nextRole,
                'bid_role' => $nextBidRole,
                'bid_manager_scope' => $bidManagerScope,
            ];

            if (! empty($validated['password'])) {
                $attributes['password'] = $validated['password'];
            }

            $record->fill($attributes)->save();

            $this->syncUserDepartments($record, $departmentIds);
            $this->syncManagedDepartments($record, $bidManagerScope, $managedDepartmentIds);
        });

        if ($record->is($actor) && $nextBidRole !== User::BID_ROLE_BID_MANAGER) {
            return redirect()
                ->route('app.notices.index')
                ->with('success', 'Brukeren ble oppdatert. Du har ikke lenger tilgang til brukeradministrasjon.');
        }

        return $this->successRedirect($request, 'app.users.index')
            ->with('success', 'Brukeren ble oppdatert.');
    }

    public function toggleActive(Request $request, int $user): RedirectResponse
    {
        [$actor, $customerId] = $this->customerBidManagerContext($request);
        $record = $this->scopedCustomerUser($customerId, $user, $actor);

        if ($record->is($actor)) {
            return back()->with('error', 'Du kan ikke deaktivere din egen bruker.');
        }

        $nextActive = ! (bool) $record->is_active;

        if ($this->wouldRemoveLastActiveSystemOwner($record, $record->resolvedBidRole(), $nextActive)) {
            return back()->with('error', 'Kunden må ha minst én aktiv systemeier.');
        }

        $record->forceFill([
            'is_active' => $nextActive,
        ])->save();

        return $this->successRedirect($request, 'app.users.index', true)
            ->with('success', $nextActive ? 'Brukeren ble aktivert.' : 'Brukeren ble deaktivert.');
    }

    private function customerBidManagerContext(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();
        $customerId = $this->customerContext->currentCustomerId($user);

        abort_unless(
            $user instanceof User
            && $this->customerContext->canManageCustomerUsers($user)
            && $customerId !== null,
            403,
        );

        return [$user, $customerId];
    }

    private function scopedCustomerUsersQuery(int $customerId)
    {
        return User::query()
            ->where('customer_id', $customerId)
            ->whereIn('role', [User::ROLE_CUSTOMER_ADMIN, User::ROLE_USER]);
    }

    private function scopedCustomerUser(int $customerId, int $userId, User $actor): User
    {
        $user = $this->scopedCustomerUsersQuery($customerId)
            ->with([
                'department:id,name',
                'departments:id,name,is_active',
                'managedDepartments:id,name,is_active',
            ])
            ->whereKey($userId)
            ->firstOrFail();

        abort_unless($this->customerContext->canManageCustomerUser($actor, $user), 404);

        return $user;
    }

    private function allowedBidRolesForActor(User $actor, ?User $record = null): array
    {
        if ($actor->isSystemOwner()) {
            if ($record instanceof User && $record->is($actor)) {
                return [$record->resolvedBidRole()];
            }

            return User::BID_ROLES;
        }

        if (! $actor->isBidManager()) {
            return [];
        }

        if ($record instanceof User) {
            return [$record->resolvedBidRole()];
        }

        return [
            User::BID_ROLE_CONTRIBUTOR,
            User::BID_ROLE_VIEWER,
        ];
    }

    private function canEditBidRole(User $actor, User $record): bool
    {
        return $actor->isSystemOwner() && ! $record->is($actor);
    }

    private function canEditBidManagerScope(User $actor, User $record): bool
    {
        return $actor->isSystemOwner() && ! $record->is($actor);
    }

    private function ensureActorCanAssignBidRole(User $actor, ?User $record, string $targetBidRole): void
    {
        $allowedRoles = $this->allowedBidRolesForActor($actor, $record);

        if (! in_array($targetBidRole, $allowedRoles, true)) {
            if ($record instanceof User && $record->is($actor)) {
                throw ValidationException::withMessages([
                    'bid_role' => 'Du kan ikke endre din egen rolle.',
                ]);
            }

            throw ValidationException::withMessages([
                'bid_role' => 'Du kan ikke velge denne rollen.',
            ]);
        }
    }

    private function bidRoleOptions(User $actor, ?User $record = null): array
    {
        return collect(User::bidRoleOptions())
            ->only($this->allowedBidRolesForActor($actor, $record))
            ->map(fn (string $label, string $value): array => [
                'value' => $value,
                'label' => $label,
            ])
            ->values()
            ->all();
    }

    private function bidManagerScopeOptions(): array
    {
        return collect(User::bidManagerScopeOptions())
            ->map(fn (string $label, string $value): array => [
                'value' => $value,
                'label' => $label,
            ])
            ->values()
            ->all();
    }

    private function membershipDepartmentOptions(
        User $actor,
        int $customerId,
        array $selectedDepartmentIds = [],
        bool $allowSelfScopeRecovery = false,
    ): array
    {
        $query = Department::query()
            ->where('customer_id', $customerId)
            ->where(function ($query) use ($selectedDepartmentIds): void {
                $query->where('is_active', true);

                if ($selectedDepartmentIds !== []) {
                    $query->orWhereIn('id', $selectedDepartmentIds);
                }
            });

        if (! $allowSelfScopeRecovery && ! $actor->hasCompanyWideBidManagementScope()) {
            $visibleDepartmentIds = array_values(array_unique(array_merge(
                $this->customerContext->manageableDepartmentIds($actor),
                $selectedDepartmentIds,
            )));

            if ($visibleDepartmentIds === []) {
                return [];
            }

            $query->whereIn('id', $visibleDepartmentIds);
        }

        return $query
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get(['id', 'name', 'is_active'])
            ->map(fn (Department $department): array => [
                'value' => $department->id,
                'label' => $department->is_active ? $department->name : "{$department->name} (inaktiv)",
            ])
            ->all();
    }

    private function managedDepartmentOptions(
        User $actor,
        int $customerId,
        array $selectedDepartmentIds = [],
        bool $allowSelfScopeRecovery = false,
    ): array
    {
        $query = Department::query()
            ->where('customer_id', $customerId)
            ->where(function ($query) use ($selectedDepartmentIds): void {
                $query->where('is_active', true);

                if ($selectedDepartmentIds !== []) {
                    $query->orWhereIn('id', $selectedDepartmentIds);
                }
            });

        if (! $allowSelfScopeRecovery && ! $actor->hasCompanyWideBidManagementScope()) {
            $visibleDepartmentIds = array_values(array_unique(array_merge(
                $this->customerContext->manageableDepartmentIds($actor),
                $selectedDepartmentIds,
            )));

            if ($visibleDepartmentIds === []) {
                return [];
            }

            $query->whereIn('id', $visibleDepartmentIds);
        }

        return $query
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get(['id', 'name', 'is_active'])
            ->map(fn (Department $department): array => [
                'value' => $department->id,
                'label' => $department->is_active ? $department->name : "{$department->name} (inaktiv)",
            ])
            ->all();
    }

    private function userListItem(User $user, User $actor, int $customerId): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'department_name' => $user->department?->name,
            'department_ids' => $user->departments->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
            'departments' => $user->departments
                ->map(fn (Department $department): array => [
                    'id' => $department->id,
                    'name' => $department->name,
                    'is_active' => (bool) $department->is_active,
                ])
                ->all(),
            'managed_department_ids' => $user->managedDepartments->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
            'managed_departments' => $user->managedDepartments
                ->map(fn (Department $department): array => [
                    'id' => $department->id,
                    'name' => $department->name,
                    'is_active' => (bool) $department->is_active,
                ])
                ->all(),
            'bid_role' => $user->bid_role_label,
            'bid_role_value' => $user->resolvedBidRole(),
            'bid_manager_scope_value' => $user->resolvedBidManagerScope(),
            'bid_manager_scope_label' => $user->bid_manager_scope_label,
            'bid_manager_scope_summary' => $this->bidManagerScopeSummary($user),
            'is_active' => (bool) $user->is_active,
            'is_self' => $user->is($actor),
            'can_toggle_active' => $this->canToggleActive($user, $actor, $customerId),
            'edit_url' => route('app.users.edit', ['user' => $user->id]),
            'toggle_active_url' => route('app.users.toggle-active', ['user' => $user->id]),
            'created_at' => optional($user->created_at)?->toIso8601String(),
        ];
    }

    private function editUserPayload(User $user, User $actor, int $customerId): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'department_id' => $user->department_id,
            'department_name' => $user->department?->name,
            'department_ids' => $user->departments->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
            'departments' => $user->departments
                ->map(fn (Department $department): array => [
                    'id' => $department->id,
                    'name' => $department->name,
                    'is_active' => (bool) $department->is_active,
                ])
                ->all(),
            'managed_department_ids' => $user->managedDepartments->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
            'managed_departments' => $user->managedDepartments
                ->map(fn (Department $department): array => [
                    'id' => $department->id,
                    'name' => $department->name,
                    'is_active' => (bool) $department->is_active,
                ])
                ->all(),
            'bid_role_value' => $user->resolvedBidRole(),
            'bid_role_label' => $user->bid_role_label,
            'bid_manager_scope_value' => $user->resolvedBidManagerScope(),
            'bid_manager_scope_label' => $user->bid_manager_scope_label,
            'bid_manager_scope_summary' => $this->bidManagerScopeSummary($user),
            'is_active' => (bool) $user->is_active,
            'is_self' => $user->is($actor),
            'can_toggle_active' => $this->canToggleActive($user, $actor, $customerId),
            'update_url' => route('app.users.update', ['user' => $user->id]),
            'toggle_active_url' => route('app.users.toggle-active', ['user' => $user->id]),
        ];
    }

    private function canToggleActive(User $user, User $actor, int $customerId): bool
    {
        if (! $user->is_active) {
            return true;
        }

        if ($user->is($actor)) {
            return false;
        }

        return ! $this->wouldRemoveLastActiveSystemOwner($user, $user->resolvedBidRole(), false, $customerId);
    }

    private function wouldRemoveLastActiveSystemOwner(User $user, string $nextBidRole, bool $nextActive, ?int $customerId = null): bool
    {
        if (! $user->is_active || ! $user->isSystemOwner()) {
            return false;
        }

        if ($nextBidRole === User::BID_ROLE_SYSTEM_OWNER && $nextActive) {
            return false;
        }

        $activeSystemOwnerCount = $this->scopedCustomerUsersQuery($customerId ?? (int) $user->customer_id)
            ->where('bid_role', User::BID_ROLE_SYSTEM_OWNER)
            ->where('is_active', true)
            ->count();

        return $activeSystemOwnerCount <= 1;
    }

    private function validatedMembershipDepartmentIds(
        User $actor,
        int $customerId,
        array $validated,
        ?User $record = null,
        ?string $targetBidRole = null,
        bool $allowSelfScopeRecovery = false,
    ): array
    {
        $submittedIds = collect($validated['department_ids'] ?? [])
            ->merge(isset($validated['department_id']) ? [$validated['department_id']] : [])
            ->filter(fn (mixed $value): bool => $value !== null && $value !== '')
            ->map(fn (mixed $value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->unique()
            ->values();

        if ($submittedIds->isEmpty()) {
            if ($actor->hasDepartmentScopedBidManagement() && $targetBidRole !== User::BID_ROLE_BID_MANAGER) {
                throw ValidationException::withMessages([
                    'department_ids' => 'Du må velge minst én avdeling innenfor ditt administrative ansvarsområde.',
                ]);
            }

            return [];
        }

        $departments = Department::query()
            ->where('customer_id', $customerId)
            ->whereIn('id', $submittedIds->all())
            ->get(['id', 'is_active'])
            ->keyBy('id');

        if ($departments->count() !== $submittedIds->count()) {
            throw ValidationException::withMessages([
                'department_id' => 'Du kan bare velge avdelinger i eget kundemiljø.',
                'department_ids' => 'Du kan bare velge avdelinger i eget kundemiljø.',
            ]);
        }

        if (! $allowSelfScopeRecovery && $actor->hasDepartmentScopedBidManagement()) {
            $manageableDepartmentIds = $this->customerContext->manageableDepartmentIds($actor);

            if ($this->hasOutOfScopeDepartmentIds($submittedIds->all(), $manageableDepartmentIds)) {
                throw ValidationException::withMessages([
                    'department_ids' => 'Du kan bare koble brukere til avdelinger innenfor ditt administrative ansvarsområde.',
                ]);
            }
        }

        $existingInactiveIds = $record instanceof User
            ? $record->departments()
                ->where('departments.is_active', false)
                ->pluck('departments.id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all()
            : [];

        $invalidInactiveSelection = $submittedIds
            ->reject(fn (int $id): bool => (bool) $departments->get($id)?->is_active || in_array($id, $existingInactiveIds, true))
            ->isNotEmpty();

        if ($invalidInactiveSelection) {
            throw ValidationException::withMessages([
                'department_id' => 'Du kan bare koble brukere til aktive avdelinger.',
                'department_ids' => 'Du kan bare koble brukere til aktive avdelinger.',
            ]);
        }

        return $submittedIds->all();
    }

    private function validatedBidManagerScope(
        User $actor,
        int $customerId,
        array $validated,
        ?User $record = null,
        bool $allowSelfScopeRecovery = false,
    ): array
    {
        if (($validated['bid_role'] ?? null) !== User::BID_ROLE_BID_MANAGER) {
            return [null, []];
        }

        if (! $actor->isSystemOwner()) {
            if ($record instanceof User && $record->is($actor)) {
                $currentScope = $record->resolvedBidManagerScope();
                $currentManagedDepartmentIds = collect($record->managedDepartmentIds())
                    ->sort()
                    ->values()
                    ->all();
                $submittedManagedDepartmentIds = collect($validated['managed_department_ids'] ?? [])
                    ->filter(fn (mixed $value): bool => $value !== null && $value !== '')
                    ->map(fn (mixed $value): int => (int) $value)
                    ->filter(fn (int $value): bool => $value > 0)
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();
                $submittedScope = (string) ($validated['bid_manager_scope'] ?? $currentScope ?? '');

                if ($submittedScope !== $currentScope || $submittedManagedDepartmentIds !== $currentManagedDepartmentIds) {
                    throw ValidationException::withMessages([
                        'bid_manager_scope' => 'Du kan ikke endre ditt eget administrative ansvarsområde.',
                    ]);
                }

                return [$currentScope, $currentManagedDepartmentIds];
            }

            throw ValidationException::withMessages([
                'bid_role' => 'Bare systemeier kan opprette eller endre bid-managere.',
            ]);
        }

        $scope = (string) ($validated['bid_manager_scope'] ?? '');

        if (! in_array($scope, User::BID_MANAGER_SCOPES, true)) {
            throw ValidationException::withMessages([
                'bid_manager_scope' => 'Velg et gyldig administrativt ansvarsområde.',
            ]);
        }

        if ($scope === User::BID_MANAGER_SCOPE_COMPANY) {
            return [$scope, []];
        }

        $submittedIds = collect($validated['managed_department_ids'] ?? [])
            ->filter(fn (mixed $value): bool => $value !== null && $value !== '')
            ->map(fn (mixed $value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->unique()
            ->values();

        if ($submittedIds->isEmpty()) {
            throw ValidationException::withMessages([
                'managed_department_ids' => 'Velg minst én avdeling for administrativt ansvarsområde.',
            ]);
        }

        $departments = Department::query()
            ->where('customer_id', $customerId)
            ->whereIn('id', $submittedIds->all())
            ->get(['id', 'is_active'])
            ->keyBy('id');

        if ($departments->count() !== $submittedIds->count()) {
            throw ValidationException::withMessages([
                'managed_department_ids' => 'Du kan bare velge avdelinger i eget kundemiljø.',
            ]);
        }

        $existingInactiveIds = $record instanceof User
            ? $record->managedDepartments()
                ->where('departments.is_active', false)
                ->pluck('departments.id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all()
            : [];

        $invalidInactiveSelection = $submittedIds
            ->reject(fn (int $id): bool => (bool) $departments->get($id)?->is_active || in_array($id, $existingInactiveIds, true))
            ->isNotEmpty();

        if ($invalidInactiveSelection) {
            throw ValidationException::withMessages([
                'managed_department_ids' => 'Du kan bare gi administrativt ansvar for aktive avdelinger.',
            ]);
        }

        return [$scope, $submittedIds->all()];
    }

    private function syncUserDepartments(User $user, array $departmentIds): void
    {
        $existingInactiveIds = $user->exists
            ? $user->departments()
                ->where('departments.is_active', false)
                ->pluck('departments.id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all()
            : [];

        $syncedIds = collect($departmentIds)
            ->merge($existingInactiveIds)
            ->unique()
            ->values()
            ->all();

        $user->departments()->sync($syncedIds);

        $user->forceFill([
            'department_id' => $this->resolvePrimaryDepartmentId($user, $departmentIds),
        ])->save();

        $user->load([
            'department:id,name',
            'departments:id,name,is_active',
        ]);
    }

    private function syncManagedDepartments(User $user, ?string $bidManagerScope, array $managedDepartmentIds): void
    {
        if ($bidManagerScope !== User::BID_MANAGER_SCOPE_DEPARTMENTS) {
            $user->managedDepartments()->sync([]);
            $user->load('managedDepartments:id,name,is_active');

            return;
        }

        $existingInactiveIds = $user->exists
            ? $user->managedDepartments()
                ->where('departments.is_active', false)
                ->pluck('departments.id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all()
            : [];

        $syncedIds = collect($managedDepartmentIds)
            ->merge($existingInactiveIds)
            ->unique()
            ->values()
            ->all();

        $user->managedDepartments()->sync($syncedIds);
        $user->load('managedDepartments:id,name,is_active');
    }

    private function resolvePrimaryDepartmentId(User $user, array $departmentIds): ?int
    {
        if ($departmentIds === []) {
            return null;
        }

        $activeDepartmentIds = Department::query()
            ->where('customer_id', $user->customer_id)
            ->where('is_active', true)
            ->whereIn('id', $departmentIds)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if ($activeDepartmentIds === []) {
            return null;
        }

        $currentDepartmentId = $user->department_id === null ? null : (int) $user->department_id;

        if ($currentDepartmentId !== null && in_array($currentDepartmentId, $activeDepartmentIds, true)) {
            return $currentDepartmentId;
        }

        foreach ($departmentIds as $departmentId) {
            if (in_array($departmentId, $activeDepartmentIds, true)) {
                return $departmentId;
            }
        }

        return null;
    }

    private function bidManagerScopeSummary(User $user): ?string
    {
        if ($user->isSystemOwner()) {
            return 'Full kontroll i kundemiljøet';
        }

        if (! $user->isBidManager()) {
            return null;
        }

        if ($user->hasCompanyWideBidManagementScope()) {
            return 'Hele selskapet';
        }

        if (! $user->hasDepartmentScopedBidManagement()) {
            return null;
        }

        $count = $user->managedDepartments->count();

        if ($count <= 0) {
            return 'Ingen avdelinger';
        }

        return $count === 1 ? '1 avdeling' : "{$count} avdelinger";
    }

    private function hasOutOfScopeDepartmentIds(array $candidateIds, array $allowedIds): bool
    {
        return array_diff($candidateIds, $allowedIds) !== [];
    }

    private function successRedirect(Request $request, string $fallbackRoute, bool $useBack = false): RedirectResponse
    {
        $redirectTo = $this->redirectTarget($request);

        if ($redirectTo !== null) {
            return redirect()->to($redirectTo);
        }

        if ($useBack) {
            return back();
        }

        return redirect()->route($fallbackRoute);
    }

    private function storeValidationRules(User $actor): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'redirect_to' => ['nullable', 'string', 'max:2048'],
            'role' => ['prohibited'],
            'bid_role' => $actor->isSystemOwner()
                ? ['required', 'string', Rule::in(User::BID_ROLES)]
                : ['prohibited'],
            'bid_manager_scope' => $actor->isSystemOwner()
                ? ['nullable', 'string', Rule::in(User::BID_MANAGER_SCOPES)]
                : ['prohibited'],
            'customer_id' => ['prohibited'],
            'department_id' => ['nullable', 'integer'],
            'department_ids' => ['nullable', 'array'],
            'department_ids.*' => ['integer'],
            'managed_department_ids' => $actor->isSystemOwner()
                ? ['nullable', 'array']
                : ['prohibited'],
            'managed_department_ids.*' => $actor->isSystemOwner()
                ? ['integer']
                : ['prohibited'],
            'nationality_id' => ['prohibited'],
            'preferred_language_id' => ['prohibited'],
            'is_active' => ['prohibited'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    private function updateValidationRules(User $actor, User $record): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'redirect_to' => ['nullable', 'string', 'max:2048'],
            'role' => ['prohibited'],
            'bid_role' => $this->canEditBidRole($actor, $record)
                ? ['required', 'string', Rule::in(User::BID_ROLES)]
                : ['prohibited'],
            'bid_manager_scope' => $this->canEditBidManagerScope($actor, $record)
                ? ['nullable', 'string', Rule::in(User::BID_MANAGER_SCOPES)]
                : ['prohibited'],
            'email' => ['prohibited'],
            'customer_id' => ['prohibited'],
            'department_id' => ['nullable', 'integer'],
            'department_ids' => ['nullable', 'array'],
            'department_ids.*' => ['integer'],
            'managed_department_ids' => $this->canEditBidManagerScope($actor, $record)
                ? ['nullable', 'array']
                : ['prohibited'],
            'managed_department_ids.*' => $this->canEditBidManagerScope($actor, $record)
                ? ['integer']
                : ['prohibited'],
            'nationality_id' => ['prohibited'],
            'preferred_language_id' => ['prohibited'],
            'is_active' => ['prohibited'],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ];
    }

    private function abortIfBidManagerAttemptsProtectedUserFields(User $actor, Request $request, ?User $record = null): void
    {
        if (! $actor->isBidManager()) {
            return;
        }

        $protectedFields = [
            'bid_role',
            'bid_manager_scope',
            'managed_department_ids',
        ];

        foreach ($protectedFields as $field) {
            if ($request->exists($field)) {
                abort(403);
            }
        }
    }

    private function redirectTarget(Request $request): ?string
    {
        $redirectTo = trim((string) $request->input('redirect_to'));

        if ($redirectTo === '' || ! str_starts_with($redirectTo, '/app/customer-environment')) {
            return null;
        }

        return $redirectTo;
    }

    private function pageRedirectTarget(Request $request): string
    {
        $redirectTo = trim((string) $request->query('redirect_to'));

        if ($redirectTo !== '' && str_starts_with($redirectTo, '/app/customer-environment')) {
            return $redirectTo;
        }

        return route('app.users.index');
    }
}
