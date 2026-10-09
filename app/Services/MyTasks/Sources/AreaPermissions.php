<?php

namespace App\Services\MyTasks\Sources;

use App\Models\User;

/**
 * One person's fagområder per permission, read once per list.
 *
 * The fagområde modules (Risiko, Avvik og forbedringer, Mål og KPI) answer «may this person do X to
 * this object» with canInArea(): same customer, and the object's fagområde among the areas the
 * person's roles give for X. Asking that per task re-reads the roles every time; this asks the
 * module's own areaIdsFor() once per permission and answers the same question from memory. The
 * module-level check (canOpenModule) is the source's isAvailableFor(), already passed.
 *
 * Lives for one openTasksFor() call — never across requests, so a role change is seen next time.
 */
final class AreaPermissions
{
    /** @var array<string, list<int>> */
    private array $areas = [];

    /**
     * @param  object  $access  a module access service with areaIdsFor(User, string): list<int>
     */
    public function __construct(
        private readonly object $access,
        private readonly User $user,
    ) {}

    public function allows(string $permissionKey, int $customerId, int $areaId): bool
    {
        if ((int) $this->user->customer_id !== $customerId) {
            return false;
        }

        $this->areas[$permissionKey] ??= $this->access->areaIdsFor($this->user, $permissionKey);

        return in_array($areaId, $this->areas[$permissionKey], true);
    }
}
