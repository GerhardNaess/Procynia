<?php

namespace App\Services;

use App\Models\SavedNotice;
use App\Models\SavedNoticeUserAccess;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class SavedNoticeAccessService
{
    public function canManageContributorAccess(User $actor, SavedNotice $notice): bool
    {
        if (! $actor->canAccessCustomerFrontend() || $actor->customer_id === null) {
            return false;
        }

        return $actor->id === $notice->bid_manager_user_id
            || $actor->id === $notice->opportunity_owner_user_id;
    }

    public function canComment(User $actor, SavedNotice $notice): bool
    {
        if ($notice->archived_at !== null) {
            return false;
        }

        if (! $this->canView($actor, $notice)) {
            return false;
        }

        return $actor->resolvedBidRole() !== User::BID_ROLE_VIEWER;
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

    private function applyVisibility(Builder $query, User $user, bool $requireManage): Builder
    {
        if (! $user->canAccessCustomerFrontend() || $user->customer_id === null) {
            return $query->whereRaw('1 = 0');
        }

        $query->where('customer_id', $user->customer_id);

        if ($user->isSystemOwner()) {
            return $query;
        }

        if ($user->isBidManager() && $user->hasCompanyWideBidManagementScope()) {
            return $query;
        }

        if (
            ! $requireManage
            && in_array($user->resolvedBidRole(), [User::BID_ROLE_CONTRIBUTOR, User::BID_ROLE_VIEWER], true)
            && $user->hasCompanyPrimaryAffiliation()
        ) {
            return $query;
        }

        if (
            $requireManage
            && $user->resolvedBidRole() === User::BID_ROLE_CONTRIBUTOR
            && $user->hasCompanyPrimaryAffiliation()
        ) {
            return $query;
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
