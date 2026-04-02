<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\User;
use App\Support\CustomerContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CustomerEnvironmentController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
    ) {
    }

    public function index(Request $request): Response
    {
        [$actor, $customerId] = $this->customerBidManagerContext($request);
        $activeTab = in_array($request->query('tab'), ['departments', 'users'], true)
            ? (string) $request->query('tab')
            : 'departments';

        $departments = $this->scopedCustomerDepartmentsQuery($actor, $customerId)
            ->withCount('members')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (Department $department): array => $this->departmentListItem($department))
            ->all();

        $users = User::query()
            ->where('customer_id', $customerId)
            ->whereIn('role', [User::ROLE_CUSTOMER_ADMIN, User::ROLE_USER])
            ->with([
                'primaryDepartment:id,name',
                'departments:id,name,is_active',
                'managedDepartments:id,name,is_active',
            ])
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn (User $user): bool => $this->customerContext->canManageCustomerUser($actor, $user))
            ->values()
            ->map(fn (User $user): array => $this->userListItem($user, $actor, $customerId))
            ->all();

        return Inertia::render('App/CustomerEnvironment/Index', [
            'activeTab' => $activeTab,
            'departments' => $departments,
            'users' => $users,
            'bidRoleOptions' => $this->bidRoleOptions($actor),
            'bidManagerScopeOptions' => collect(User::bidManagerScopeOptions())
                ->map(fn (string $label, string $value): array => [
                    'value' => $value,
                    'label' => $label,
                ])
                ->values()
                ->all(),
            'departmentOptions' => $this->membershipDepartmentOptions($actor, $customerId),
            'managedDepartmentOptions' => $this->managedDepartmentOptions($actor, $customerId),
            'departmentFilterOptions' => $this->departmentFilterOptions($actor, $customerId),
            'canCreateDepartments' => $this->customerContext->canCreateCustomerDepartments($actor),
            'routes' => [
                'index' => route('app.customer-environment.index'),
                'departments_store' => route('app.departments.store'),
                'users_store' => route('app.users.store'),
                'users_create' => route('app.users.create'),
            ],
        ]);
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

    private function canToggleActive(User $user, User $actor, int $customerId): bool
    {
        if (! $user->is_active) {
            return true;
        }

        if ($user->is($actor)) {
            return false;
        }

        if (! $user->isSystemOwner()) {
            return true;
        }

        $activeSystemOwnerCount = User::query()
            ->where('customer_id', $customerId)
            ->whereIn('role', [User::ROLE_CUSTOMER_ADMIN, User::ROLE_USER])
            ->where('bid_role', User::BID_ROLE_SYSTEM_OWNER)
            ->where('is_active', true)
            ->count();

        return $activeSystemOwnerCount > 1;
    }

    private function scopedCustomerDepartmentsQuery(User $actor, int $customerId)
    {
        $query = Department::query()->where('customer_id', $customerId);

        if ($actor->hasCompanyWideBidManagementScope()) {
            return $query;
        }

        $manageableDepartmentIds = $this->customerContext->manageableDepartmentIds($actor);

        if ($manageableDepartmentIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('id', $manageableDepartmentIds);
    }

    private function departmentListItem(Department $department): array
    {
        return [
            'id' => $department->id,
            'name' => $department->name,
            'description' => $department->description,
            'is_active' => (bool) $department->is_active,
            'user_count' => (int) $department->members_count,
            'created_at' => optional($department->created_at)?->toIso8601String(),
            'updated_at' => optional($department->updated_at)?->toIso8601String(),
            'update_url' => route('app.departments.update', ['department' => $department->id]),
            'toggle_active_url' => route('app.departments.toggle-active', ['department' => $department->id]),
        ];
    }

    private function userListItem(User $user, User $actor, int $customerId): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'bid_role' => $user->bid_role_label,
            'bid_role_value' => $user->resolvedBidRole(),
            'bid_manager_scope_value' => $user->resolvedBidManagerScope(),
            'bid_manager_scope_label' => $user->bid_manager_scope_label,
            'bid_manager_scope_summary' => $this->bidManagerScopeSummary($user),
            'primary_affiliation_scope_value' => $user->resolvedPrimaryAffiliationScope(),
            'primary_affiliation_scope_label' => $user->primary_affiliation_scope_label,
            'is_active' => (bool) $user->is_active,
            'is_self' => $user->is($actor),
            'can_toggle_active' => $this->canToggleActive($user, $actor, $customerId),
            'primary_department' => $user->primaryDepartment ? [
                'id' => $user->primaryDepartment->id,
                'name' => $user->primaryDepartment->name,
            ] : null,
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
            'created_at' => optional($user->created_at)?->toIso8601String(),
            'edit_url' => route('app.users.edit', ['user' => $user->id]),
            'update_url' => route('app.users.update', ['user' => $user->id]),
            'toggle_active_url' => route('app.users.toggle-active', ['user' => $user->id]),
        ];
    }

    private function membershipDepartmentOptions(User $actor, int $customerId, array $selectedDepartmentIds = []): array
    {
        $query = Department::query()
            ->where('customer_id', $customerId)
            ->where(function ($query) use ($selectedDepartmentIds): void {
                $query->where('is_active', true);

                if ($selectedDepartmentIds !== []) {
                    $query->orWhereIn('id', $selectedDepartmentIds);
                }
            });

        if (! $actor->hasCompanyWideBidManagementScope()) {
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

    private function bidRoleOptions(User $actor): array
    {
        $allowedRoles = $actor->isSystemOwner()
            ? User::BID_ROLES
            : [
                User::BID_ROLE_CONTRIBUTOR,
                User::BID_ROLE_VIEWER,
            ];

        return collect(User::bidRoleOptions())
            ->only($allowedRoles)
            ->map(fn (string $label, string $value): array => [
                'value' => $value,
                'label' => $label,
            ])
            ->values()
            ->all();
    }

    private function managedDepartmentOptions(User $actor, int $customerId, array $selectedDepartmentIds = []): array
    {
        $query = Department::query()
            ->where('customer_id', $customerId)
            ->where(function ($query) use ($selectedDepartmentIds): void {
                $query->where('is_active', true);

                if ($selectedDepartmentIds !== []) {
                    $query->orWhereIn('id', $selectedDepartmentIds);
                }
            });

        if (! $actor->hasCompanyWideBidManagementScope()) {
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

    private function departmentFilterOptions(User $actor, int $customerId): array
    {
        return $this->membershipDepartmentOptions($actor, $customerId);
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
}
