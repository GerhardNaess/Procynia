<?php

namespace App\Services\Quality;

use App\Models\User;
use App\Services\Modules\ModuleEntitlementService;
use App\Services\Permissions\CustomerPermissionService;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Who may do what in Kvalitet, asked by the services themselves (phase 0 step 12,
 * docs/external-api-phase-0-plan.md §2f), so the rule holds for every entry point and not only a
 * web request.
 *
 * Kvalitet is not scoped by fagområde: a quality.* key from the person's roles, in their own
 * customer, with the Kvalitet module. The same rule QualityController::may() applied, plus the two
 * things the controller left to its route and its session — the module and the customer. The
 * services keep checking that each row belongs to the customer they are given.
 *
 * The human decisions — approving a flow, adopting an AI proposal, deleting — also pass
 * HumanDecisionGuard in their services. quality.approve and quality.delete are human-only keys,
 * which CustomerPermissionService::has() already withholds from an integration.
 */
class QualityAccessService
{
    public function __construct(
        private readonly CustomerPermissionService $permissions,
        private readonly ModuleEntitlementService $entitlements,
    ) {}

    public function may(?User $user, string $permissionKey, ?int $customerId): bool
    {
        return $user instanceof User
            && $customerId !== null
            && (int) $user->customer_id === $customerId
            && $user->customer !== null
            && $this->entitlements->hasModule($user->customer, 'quality')
            && $this->permissions->has($user, $permissionKey);
    }

    /** The rule, as a refusal (403). */
    public function authorize(User $user, string $permissionKey, int $customerId): void
    {
        if (! $this->may($user, $permissionKey, $customerId)) {
            throw new AuthorizationException("This user may not [{$permissionKey}] in this customer's Kvalitet.");
        }
    }
}
