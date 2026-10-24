<?php

namespace App\Services\Security;

use App\Models\Customer;
use App\Models\CustomerAccessChange;
use App\Models\CustomerRole;
use App\Models\User;

/**
 * Records who changed who may do what (F4). Called inside the transaction that made the change, with
 * snapshots taken before and after it; a write that changed nothing access-relevant records nothing.
 *
 * Snapshots hold only what decides access — never e-mail, password or other personal data.
 */
class CustomerAccessChangeRecorder
{
    public const USER_CREATED = 'user_created';

    public const USER_ACCESS_CHANGED = 'user_access_changed';

    public const USER_ACTIVATED = 'user_activated';

    public const USER_DEACTIVATED = 'user_deactivated';

    public const ROLE_CREATED = 'role_created';

    public const ROLE_CHANGED = 'role_changed';

    public const ROLE_DELETED = 'role_deleted';

    public const PERMISSION_SETTINGS_CHANGED = 'permission_settings_changed';

    public const CASE_ACCESS_GRANTED = 'case_access_granted';

    public const CASE_ACCESS_REVOKED = 'case_access_revoked';

    public const CASE_OPPORTUNITY_OWNER_CHANGED = 'case_opportunity_owner_changed';

    public const CASE_BID_MANAGER_CHANGED = 'case_bid_manager_changed';

    public const API_GRANT_CREATED = 'api_grant_created';

    public const API_GRANT_ROLES_CHANGED = 'api_grant_roles_changed';

    public const API_GRANT_REVOKED = 'api_grant_revoked';

    public const API_GRANT_OPERATIONS_CHANGED = 'api_grant_operations_changed';

    /** @return array<string, mixed> */
    public function userSnapshot(User $user): array
    {
        $user = $user->fresh() ?? $user;
        $ids = static fn ($relation): array => $relation->pluck('id')->map(static fn ($id): int => (int) $id)->sort()->values()->all();

        return [
            'role' => $user->role,
            'bid_role' => $user->bid_role,
            'is_qa' => (bool) $user->is_qa,
            'is_wiki_approver' => (bool) $user->is_wiki_approver,
            'is_active' => (bool) $user->is_active,
            'bid_manager_scope' => $user->bid_manager_scope,
            'managed_department_ids' => $ids($user->managedDepartments()->get(['departments.id'])),
            'primary_affiliation_scope' => $user->primary_affiliation_scope,
            'primary_department_id' => $user->primary_department_id !== null ? (int) $user->primary_department_id : null,
            'department_ids' => $ids($user->departments()->get(['departments.id'])),
            'customer_role_ids' => $ids($user->customerRoles()->get(['customer_roles.id'])),
        ];
    }

    /** @return array<string, mixed> */
    public function roleSnapshot(CustomerRole $role): array
    {
        $role = $role->fresh() ?? $role;
        $permissions = $role->permissionKeys();
        sort($permissions);

        return [
            'name' => $role->name,
            'is_active' => (bool) $role->is_active,
            'permissions' => array_values($permissions),
            'all_business_areas' => (bool) $role->all_business_areas,
            'business_area_ids' => $role->businessAreas()->pluck('business_areas.id')->map(static fn ($id): int => (int) $id)->sort()->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(int $customerId, User $actor, string $subjectType, ?int $subjectId, string $changeType, ?array $before, ?array $after): void
    {
        if ($before !== null && $after !== null && $before == $after) {
            return;
        }

        CustomerAccessChange::query()->create([
            'customer_id' => $customerId,
            'actor_user_id' => $actor->id,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'change_type' => $changeType,
            'before' => $before,
            'after' => $after,
            'created_at' => now(),
        ]);
    }

    /** The Tilganger matrix as the customer resolves it. */
    public function permissionSettingsSnapshot(Customer $customer): array
    {
        $settings = ($customer->fresh() ?? $customer)->resolvedPermissionSettings();
        ksort($settings);

        return $settings;
    }
}
