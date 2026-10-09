<?php

namespace App\Services\MyTasks\Sources;

use App\Models\User;
use App\Services\EnterpriseWiki\EnterpriseWikiQaTaskService;
use App\Services\EnterpriseWiki\EnterpriseWikiReviewTaskService;
use App\Services\Modules\ModuleEntitlementService;
use App\Services\MyTasks\MyTask;
use App\Services\MyTasks\MyTaskSource;
use App\Services\Permissions\CustomerPermissionService;
use App\Support\CustomerPermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Enterprise Wiki: pages handed to this person to review, and versions handed to them to quality
 * assure. Both rules stay in their own services (EnterpriseWikiReviewTaskService,
 * EnterpriseWikiQaTaskService) and are unchanged; this only puts them behind the shared contract.
 *
 * ACCESS. The assignment alone used to be enough to see the page title here, so a person who lost
 * the Wiki — the customer dropped the module, or their role lost wiki.view — still saw it. Both are
 * now required, the same two the Wiki's own routes ask for (EnsureModuleIsEnabled and
 * AuthorizesWikiPermissions). Every page of the customer is readable with wiki.view — approval
 * status gates actions, never reading — so no per-page check is needed beyond the customer scope
 * the services already apply.
 *
 * A Wiki task has no due date.
 */
class WikiTaskSource implements MyTaskSource
{
    public function __construct(
        private readonly EnterpriseWikiReviewTaskService $reviewTasks,
        private readonly EnterpriseWikiQaTaskService $qaTasks,
        private readonly ModuleEntitlementService $entitlements,
        private readonly CustomerPermissionService $permissions,
    ) {}

    public function module(): string
    {
        return 'wiki';
    }

    public function isAvailableFor(User $user): bool
    {
        $customer = $user->customer;

        return $customer !== null
            && $this->entitlements->hasModule($customer, 'wiki')
            && $this->permissions->has($user, CustomerPermissionCatalog::WIKI_VIEW);
    }

    public function openTasksFor(User $user, int $customerId, CarbonImmutable $today): Collection
    {
        if (! $this->isAvailableFor($user)) {
            return collect();
        }

        // Review first, then QA, each in the order its service gives — as the list always showed.
        return $this->reviewTasks->openTasksFor($user, $customerId)
            ->merge($this->qaTasks->openTasksFor($user, $customerId))
            ->map(fn (array $payload): MyTask => new MyTask(
                id: (string) $payload['id'],
                module: $this->module(),
                type: (string) $payload['type'],
                title: (string) $payload['subject_label'],
                subjectTitle: $payload['page_title'] ?? null,
                assigneeUserId: (int) $user->id,
                actionUrl: $payload['action_url'] ?? null,
                details: $payload,
            ))
            ->values();
    }
}
