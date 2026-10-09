<?php

namespace App\Services\MyTasks\Sources;

use App\Models\QualityItem;
use App\Models\User;
use App\Services\Modules\ModuleEntitlementService;
use App\Services\MyTasks\MyTask;
use App\Services\MyTasks\MyTaskSource;
use App\Services\Permissions\CustomerPermissionService;
use App\Services\Quality\QualityAttentionService as Attention;
use App\Support\CustomerPermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Kvalitet: the items in force the person is ansvarlig for that QualityAttentionService flags —
 * a control without evidence or not placed on an activity, a process no policy governs, a process
 * overdue for review. One task per item, each finding a reason; an item with no finding is no work.
 * A retired item is never a task. Due on the process's next review date when that is the reason.
 *
 * The findings are the service's, computed once for the customer and narrowed to the person's own
 * items, so «Mine oppgaver» and Kvalitet's Oversikt can never disagree.
 *
 * ACCESS. Kvalitet is customer-wide: the module and quality.view (QualityController's gate).
 * READ, NOT ACT. Fixing any of the four is quality.edit.
 */
class QualityTaskSource implements MyTaskSource
{
    public function __construct(
        private readonly Attention $attention,
        private readonly CustomerPermissionService $permissions,
        private readonly ModuleEntitlementService $entitlements,
    ) {}

    public function module(): string
    {
        return 'quality';
    }

    public function isAvailableFor(User $user): bool
    {
        $customer = $user->customer;

        return $customer !== null
            && $this->entitlements->hasModule($customer, 'quality')
            && $this->permissions->has($user, CustomerPermissionCatalog::QUALITY_VIEW);
    }

    public function openTasksFor(User $user, int $customerId, CarbonImmutable $today): Collection
    {
        if (! $this->isAvailableFor($user)) {
            return collect();
        }

        $owned = QualityItem::query()
            ->where('customer_id', $customerId)
            ->where('owner_user_id', $user->id)
            ->where('status', '!=', QualityItem::STATUS_RETIRED)
            ->orderBy('id')
            ->get(['id', 'customer_id', 'title', 'code', 'quality_type', 'status', 'next_review_at'])
            ->keyBy('id');

        if ($owned->isEmpty()) {
            return collect();
        }

        $reasonsByItem = [];

        foreach ($this->attention->findings($customerId) as $finding) {
            foreach ($finding['items'] as $item) {
                if ($owned->has($item['id'])) {
                    $reasonsByItem[(int) $item['id']][] = $finding['key'];
                }
            }
        }

        $canEdit = $this->permissions->has($user, CustomerPermissionCatalog::QUALITY_EDIT);

        return $owned->toBase()
            ->filter(fn (QualityItem $item): bool => isset($reasonsByItem[(int) $item->id]))
            ->map(function (QualityItem $item) use ($reasonsByItem, $canEdit, $user): MyTask {
                $rows = array_map(fn (string $key): array => [
                    'key' => $key,
                    'due_on' => $key === Attention::PROCESSES_OVERDUE_FOR_REVIEW ? $item->next_review_at?->toDateString() : null,
                    'overdue' => $key === Attention::PROCESSES_OVERDUE_FOR_REVIEW,
                    'can_act' => $canEdit,
                ], $reasonsByItem[(int) $item->id]);
                $dates = array_values(array_filter(array_column($rows, 'due_on')));

                return new MyTask(
                    id: 'quality-item-'.$item->id,
                    module: $this->module(),
                    type: 'quality_item',
                    title: $item->title,
                    subjectTitle: $item->code !== null ? $item->code.' '.$item->title : $item->title,
                    assigneeUserId: (int) $user->id,
                    actionUrl: route('app.quality.items.show', ['item' => $item->id], false),
                    dueOn: $dates !== [] ? CarbonImmutable::parse($dates[0]) : null,
                    overdue: in_array(true, array_column($rows, 'overdue'), true),
                    reasons: $rows,
                    canAct: $canEdit,
                    details: ['quality_item' => ['id' => (int) $item->id, 'quality_type' => $item->quality_type]],
                    subject: ['prefix' => 'quality', 'metadata' => ['quality_item_id' => (int) $item->id]],
                );
            })
            ->values();
    }
}
