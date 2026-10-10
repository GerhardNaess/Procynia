<?php

namespace App\Services\ManagementReview;

use App\Models\ManagementReview;
use App\Models\User;
use App\Services\Modules\ModuleEntitlementService;
use App\Services\Permissions\CustomerPermissionService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one place that answers "may this user do this, to management reviews?"
 * (docs/management-review-v1-plan.md §8).
 *
 * TWO LAYERS.
 *
 *  1. The review itself — period, scope, participants, conclusion, the manual sections, every
 *     decision and tiltak, corrections — is read with management_review.view. The domain is
 *     customer-wide and not explicit-grant, so System Owner reads and runs reviews.
 *  2. A section built from another module — its basis, and the management's judgement and comment on
 *     it — is shown only to someone who may read that module's data now: the module's own view
 *     permission, and for Risiko, Mål og KPI and Avvik og forbedringer a fagområde. Being able to read
 *     reviews never widens what a person may see of Risiko, Leverandører or anything else, and System
 *     Owner gets no fagområde and no explicit-grant domain implicitly (BusinessAreaGrants,
 *     explicitGrantDomains()). Section gates live in ManagementReviewSectionCatalog.
 *
 * Every read of reviews on a user's behalf starts from visibleReviews(). Another customer's review is
 * absent — not listed, counted or found, and a 404 by URL.
 */
class ManagementReviewAccessService
{
    public function __construct(
        private readonly CustomerPermissionService $permissions,
        private readonly ModuleEntitlementService $entitlements,
    ) {}

    /** management_review.view and the module. Says nothing about the sections of a review. */
    public function canOpenModule(?User $user): bool
    {
        return $user instanceof User
            && $user->customer_id !== null
            && $user->customer !== null
            && $this->entitlements->hasModule($user->customer, 'management_review')
            && $this->permissions->has($user, CustomerPermissionCatalog::MANAGEMENT_REVIEW_VIEW);
    }

    /**
     * Every review the user may read, and nothing else.
     *
     * @return Builder<ManagementReview>
     */
    public function visibleReviews(User $user): Builder
    {
        $query = ManagementReview::query();

        if (! $this->canOpenModule($user)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('management_reviews.customer_id', (int) $user->customer_id);
    }

    public function findVisible(User $user, int $reviewId): ?ManagementReview
    {
        return $this->visibleReviews($user)->whereKey($reviewId)->first();
    }

    public function canEdit(User $user): bool
    {
        return $this->canOpenModule($user) && $this->permissions->has($user, CustomerPermissionCatalog::MANAGEMENT_REVIEW_EDIT);
    }

    public function canFinalize(User $user): bool
    {
        return $this->canOpenModule($user) && $this->permissions->has($user, CustomerPermissionCatalog::MANAGEMENT_REVIEW_FINALIZE);
    }

    public function canDelete(User $user): bool
    {
        return $this->canOpenModule($user) && $this->permissions->has($user, CustomerPermissionCatalog::MANAGEMENT_REVIEW_DELETE);
    }

    /** Whether the user holds a permission key directly — for the section gates. */
    public function holds(User $user, string $permissionKey): bool
    {
        return $this->permissions->has($user, $permissionKey);
    }

    /**
     * Who may be responsible for a review or a tiltak followed up here: an active person of the same
     * customer who can read reviews. Being responsible grants nothing.
     */
    public function isValidOwner(?User $owner, int $customerId): bool
    {
        return $owner instanceof User
            && (bool) $owner->is_active
            && (int) $owner->customer_id === $customerId
            && $this->permissions->has($owner, CustomerPermissionCatalog::MANAGEMENT_REVIEW_VIEW);
    }

    /**
     * The people who could be responsible, for the forms. The same rule as isValidOwner(), which the
     * server applies again on submit.
     *
     * @return list<array{id: int, name: string}>
     */
    public function ownerCandidates(User $user): array
    {
        return User::query()
            ->where('customer_id', (int) $user->customer_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->filter(fn (User $candidate): bool => $this->permissions->has($candidate, CustomerPermissionCatalog::MANAGEMENT_REVIEW_VIEW))
            ->map(fn (User $candidate): array => ['id' => (int) $candidate->id, 'name' => $candidate->name])
            ->values()
            ->all();
    }
}
