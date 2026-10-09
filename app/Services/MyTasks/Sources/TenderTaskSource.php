<?php

namespace App\Services\MyTasks\Sources;

use App\Models\SavedNoticeInfoItem;
use App\Models\User;
use App\Services\InfoCenter\InfoItemPayload;
use App\Services\Modules\ModuleEntitlementService;
use App\Services\MyTasks\MyTask;
use App\Services\MyTasks\MyTaskSource;
use App\Services\SavedNoticeAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Anbud: the open aksjoner with this person as ansvarlig (SavedNoticeInfoItem), on cases they may
 * see. The aksjon is the module's own work record — closing it is what retires the task — so this
 * reads it and writes nothing; a requirement assignment arrives here as the aksjon
 * RequirementResponsibilityTaskService keeps in step with it.
 *
 * Due is «Oppfølgingsfrist» (response_due_at), when set.
 */
class TenderTaskSource implements MyTaskSource
{
    public function __construct(
        private readonly SavedNoticeAccessService $savedNoticeAccess,
        private readonly ModuleEntitlementService $entitlements,
        private readonly InfoItemPayload $infoItemPayload,
    ) {}

    public function module(): string
    {
        return 'tender';
    }

    /**
     * Whether the person may read Anbud at all: the customer holds the module. Case by case
     * visibility is SavedNoticeAccessService's, applied to every query.
     */
    public function isAvailableFor(User $user): bool
    {
        $customer = $user->customer;

        return $customer !== null && $this->entitlements->hasModule($customer, 'tender');
    }

    public function openTasksFor(User $user, int $customerId, CarbonImmutable $today): Collection
    {
        if (! $this->isAvailableFor($user)) {
            return collect();
        }

        return SavedNoticeInfoItem::query()
            ->whereIn('saved_notice_id', $this->savedNoticeAccess->visibleQueryFor($user)->select('id'))
            ->where('owner_user_id', $user->id)
            ->where('status', '!=', SavedNoticeInfoItem::STATUS_CLOSED)
            ->with([
                'savedNotice:id,title,external_id,reference_number',
                'owner:id,name,customer_id',
                'createdBy:id,name,customer_id',
            ])
            ->orderBy('id')
            ->get()
            ->toBase()
            ->map(function (SavedNoticeInfoItem $item) use ($user, $customerId, $today): MyTask {
                $payload = $this->infoItemPayload->for($item, $customerId);
                $dueOn = $item->response_due_at !== null
                    ? CarbonImmutable::parse($item->response_due_at->toDateString())
                    : null;

                return new MyTask(
                    id: 'tender-item-'.$item->id,
                    module: $this->module(),
                    type: (string) $item->type,
                    title: (string) $payload['subject_label'],
                    subjectTitle: $item->savedNotice?->title,
                    assigneeUserId: (int) $user->id,
                    actionUrl: $payload['action_url'],
                    dueOn: $dueOn,
                    overdue: $dueOn !== null && $dueOn->lt($today),
                    details: ['item' => $payload],
                    // «Frister innen 7 dager» is Oppfølging's own rule for aksjoner.
                    dueSoonDays: MyTask::DEFAULT_DUE_SOON_DAYS,
                    subject: ['prefix' => 'bid', 'metadata' => ['info_item_id' => (int) $item->id], 'saved_notice_id' => (int) $item->saved_notice_id],
                );
            })
            ->values();
    }
}
