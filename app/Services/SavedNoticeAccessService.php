<?php

namespace App\Services;

use App\Models\SavedNotice;
use App\Models\SavedNoticeUserAccess;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

class SavedNoticeAccessService
{
    public function canManageContributorAccess(User $actor, SavedNotice $notice): bool
    {
        if (! $actor->canAccessCustomerFrontend() || $actor->customer_id === null) {
            return false;
        }

        if ($actor->isSystemOwner()) {
            return true;
        }

        if ($actor->isBidManager() && $this->canManage($actor, $notice)) {
            return true;
        }

        return $actor->id === $notice->bid_manager_user_id
            || $actor->id === $notice->opportunity_owner_user_id;
    }

    public function canComment(User $actor, SavedNotice $notice): bool
    {
        if ($notice->archived_at !== null) {
            return false;
        }

        // Commenting writes to the case: a per-case reader grant shows it, but does not let the
        // person write to it (A41).
        if (! $this->canManage($actor, $notice)) {
            return false;
        }

        return $actor->resolvedBidRole() !== User::BID_ROLE_VIEWER;
    }

    /**
     * May this person add an aksjon to the case (A41)? Case work on the case page: the right to
     * work on the case, never the reader role — as for comments, but also on an archived case,
     * where aksjoner could always be recorded.
     */
    public function canAddInfoItem(User $actor, SavedNotice $notice): bool
    {
        return $actor->resolvedBidRole() !== User::BID_ROLE_VIEWER
            && $this->canManage($actor, $notice);
    }

    public function canArchive(User $actor, SavedNotice $notice): bool
    {
        if ($notice->archived_at !== null) {
            return false;
        }

        if ($actor->resolvedBidRole() === User::BID_ROLE_VIEWER) {
            return false;
        }

        // The same as commenting: a reader grant never moves the case (A41).
        return $this->canManage($actor, $notice);
    }

    public function canReopenAfterNoGo(User $actor, SavedNotice $notice): bool
    {
        if ($notice->bid_status !== SavedNotice::BID_STATUS_NO_GO) {
            return false;
        }

        if (! $actor->canAccessCustomerFrontend() || $actor->customer_id === null) {
            return false;
        }

        if ($actor->isSystemOwner()) {
            return $this->canView($actor, $notice);
        }

        if (! $actor->isBidManager() || ! $this->canManage($actor, $notice)) {
            return false;
        }

        if ($actor->hasCompanyWideBidManagementScope()) {
            return true;
        }

        if ($actor->managedDepartmentIds() !== []
            && in_array($notice->organizational_department_id, $actor->managedDepartmentIds(), true)) {
            return true;
        }

        if (in_array($actor->id, [
            $notice->saved_by_user_id,
            $notice->opportunity_owner_user_id,
            $notice->bid_manager_user_id,
        ], true)) {
            return true;
        }

        return $notice->userAccesses()
            ->active()
            ->where('user_id', $actor->id)
            ->where('access_role', SavedNoticeUserAccess::ACCESS_ROLE_CONTRIBUTOR)
            ->exists();
    }

    public function visibleQueryFor(User $user): Builder
    {
        $query = SavedNotice::query();

        return $this->applyVisibility($query, $user, false);
    }

    public function manageableQueryFor(User $user): Builder
    {
        $query = SavedNotice::query();

        return $this->applyVisibility($query, $user, true);
    }

    public function cockpitScopeQueryFor(User $user, int $customerId): Builder
    {
        $query = SavedNotice::query()
            ->where('customer_id', $customerId);

        if ($user->isSystemOwner() || $user->isBidManager()) {
            return $query;
        }

        return $query->where(function (Builder $builder) use ($user): void {
            $builder
                ->where('bid_manager_user_id', $user->id)
                ->orWhere('opportunity_owner_user_id', $user->id);
        });
    }

    public function canView(User $user, SavedNotice $notice): bool
    {
        return $this->visibleQueryFor($user)
            ->whereKey($notice->getKey())
            ->exists();
    }

    public function canManage(User $user, SavedNotice $notice): bool
    {
        return $this->manageableQueryFor($user)
            ->whereKey($notice->getKey())
            ->exists();
    }

    /**
     * May this person open a new case — «Lagre» on a notice, or a private request (P4)? Everyone in
     * the customer frontend except the reader role: a reader reads the cases they are given, and is
     * given more through the case's own access controls, never by saving.
     */
    public function mayCreateCase(User $actor): bool
    {
        return $actor->canAccessCustomerFrontend()
            && $actor->customer_id !== null
            && $actor->resolvedBidRole() !== User::BID_ROLE_VIEWER;
    }

    /**
     * May this person do case work on this case (A41)? Uploading tender documents, adding, editing
     * and deleting requirements, generating and editing answers, assessments, the answer basis,
     * responsibility and tasks. Seeing a case is not enough: a reader (the bid role «viewer», or a
     * per-case reader grant) reads and exports. The right to work on the case, never the reader
     * role (as for comments and archiving — the role reads even on a case it saved), an active case,
     * the person's own customer, and a customer that holds Anbud.
     */
    public function mayWork(User $actor, SavedNotice $notice): bool
    {
        return $actor->customer_id !== null
            && (int) $actor->customer_id === (int) $notice->customer_id
            && $notice->archived_at === null
            && $actor->resolvedBidRole() !== User::BID_ROLE_VIEWER
            && (bool) $notice->customer?->hasModule('tender')
            && $this->canManage($actor, $notice);
    }

    /**
     * The same rule where the work is done, so a second way in cannot skip it. The controller asks
     * mayWork() first to answer early; the services call this.
     *
     * @throws AuthorizationException
     */
    public function authorizeWork(?User $actor, SavedNotice $notice): User
    {
        if (! $actor instanceof User || ! $this->mayWork($actor, $notice)) {
            throw new AuthorizationException('This person may not work on this case.');
        }

        return $actor;
    }

    private function applyVisibility(Builder $query, User $user, bool $requireManage): Builder
    {
        if (! $user->canAccessCustomerFrontend() || $user->customer_id === null) {
            return $query->whereRaw('1 = 0');
        }

        $query->where('customer_id', $user->customer_id);

        if ($user->canViewAllCasesViaSettings()) {
            if ($user->isSystemOwner()) {
                return $query;
            }

            if ($user->isBidManager() && $user->hasCompanyWideBidManagementScope()) {
                return $query;
            }

            if (! $requireManage && $user->hasCompanyPrimaryAffiliation()) {
                return $query;
            }

            if ($requireManage && $user->resolvedBidRole() !== User::BID_ROLE_VIEWER && $user->hasCompanyPrimaryAffiliation()) {
                return $query;
            }
        }

        return $query->where(function (Builder $builder) use ($user, $requireManage): void {
            $builder
                ->where('saved_by_user_id', $user->id)
                ->orWhere('opportunity_owner_user_id', $user->id)
                ->orWhere('bid_manager_user_id', $user->id);

            if ($requireManage) {
                $builder->orWhereHas('userAccesses', function (Builder $accessQuery) use ($user): void {
                    $accessQuery
                        ->active()
                        ->where('user_id', $user->id)
                        ->where('access_role', SavedNoticeUserAccess::ACCESS_ROLE_CONTRIBUTOR);
                });
            } else {
                $builder->orWhereHas('userAccesses', function (Builder $accessQuery) use ($user): void {
                    $accessQuery
                        ->active()
                        ->where('user_id', $user->id);
                });
            }

            if ($user->isBidManager()) {
                $managedDepartmentIds = $user->managedDepartmentIds();

                if ($managedDepartmentIds !== []) {
                    $builder->orWhereIn('organizational_department_id', $managedDepartmentIds);
                }

                return;
            }

            if (! $user->canViewAllCasesViaSettings()) {
                return;
            }

            $primaryDepartmentId = $user->primaryAffiliationDepartmentId();

            if ($primaryDepartmentId === null) {
                return;
            }

            if (! $requireManage && $user->resolvedBidRole() === User::BID_ROLE_VIEWER) {
                $builder->orWhere('organizational_department_id', $primaryDepartmentId);

                return;
            }

            if ($user->resolvedBidRole() === User::BID_ROLE_CONTRIBUTOR) {
                $builder->orWhere('organizational_department_id', $primaryDepartmentId);
            }
        });
    }
}
