<?php

namespace App\Services\ManagementReview;

use App\Models\User;

/**
 * Builds one section's basis (SectionPayload::toArray()) for a person, over a review's scope.
 *
 * A builder reads ONLY through its module's own access service (visibleRisks(), visibleCases(), …),
 * so it never sees more than the person could see in the module itself. $areaIds is the person's
 * fagområder narrowed to the review's scope, for area-scoped sections; null otherwise.
 */
interface SectionBuilder
{
    /**
     * @param  list<int>|null  $areaIds
     * @return array<string, mixed>
     */
    public function build(User $user, ReviewScope $scope, ?array $areaIds): array;
}
