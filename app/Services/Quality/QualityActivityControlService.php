<?php

namespace App\Services\Quality;

use App\Models\QualityActivityControl;
use App\Models\QualityItem;
use App\Models\QualityProcessBlueprint;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Controls placed on the activities of a process.
 *
 * A control is not a new kind of thing here: adding one registers an ordinary `control` quality
 * item through QualityItemService, with the description as its criterion, and records which
 * activity it sits on. Removing it takes it off the activity and nothing more — the control stays
 * in the register, where deleting it is a quality.delete decision of its own.
 *
 * The flow is never written. The activity is checked to exist in the working version and is then
 * referred to by its key only.
 */
class QualityActivityControlService
{
    public function __construct(
        private readonly QualityItemService $items,
        private readonly QualityActivityArticleService $activities,
    ) {}

    /**
     * Every control on one process's activities, keyed by activity key, read fresh from the
     * control items so a title edited in the register shows here as it now is.
     *
     * @return array<string, list<array{id: int, control_item_id: int, title: string, criterion: ?string, url: string}>>
     */
    public function describeForItem(int $customerId, int $itemId): array
    {
        $rows = QualityActivityControl::query()
            ->where('customer_id', $customerId)
            ->where('quality_item_id', $itemId)
            ->with('control.controlDetail')
            ->orderBy('id')
            ->get();

        $byActivity = [];

        foreach ($rows as $row) {
            $control = $row->control;

            if ($control === null || (int) $control->customer_id !== $customerId) {
                continue;
            }

            $byActivity[(string) $row->activity_key][] = [
                'id' => (int) $row->id,
                'control_item_id' => (int) $control->id,
                'title' => (string) $control->title,
                'criterion' => $control->controlDetail?->criterion,
                'url' => '/app/quality/items/'.$control->id,
            ];
        }

        return $byActivity;
    }

    /**
     * Where each control is placed: which process, which activity — keyed by control item id.
     *
     * The activity is resolved against the process's working version now, so a renamed step shows
     * under its current name. A placement whose activity has since been removed from the flow is
     * still listed, with `activity_exists` false: the row says where the control was put, and
     * hiding it would make the control look unused when nobody took it off.
     *
     * A control with no placements has no entry; it is still a control in the register.
     *
     * @param  list<int>|null  $controlIds  null for every control of the customer
     * @return array<int, list<array{id: int, process_id: int, process_title: string, process_code: ?string, activity_key: string, activity_label: ?string, activity_role: ?string, activity_exists: bool, url: string}>>
     */
    public function placementsByControl(int $customerId, ?array $controlIds = null): array
    {
        $rows = QualityActivityControl::query()
            ->where('customer_id', $customerId)
            ->when($controlIds !== null, fn ($query) => $query->whereIn('control_item_id', $controlIds))
            ->with('item:id,customer_id,title,code')
            ->orderBy('id')
            ->get();

        $blueprints = QualityProcessBlueprint::query()
            ->where('customer_id', $customerId)
            ->whereIn('quality_item_id', $rows->pluck('quality_item_id')->unique())
            ->get()
            ->keyBy('quality_item_id');

        $byControl = [];

        foreach ($rows as $row) {
            $process = $row->item;

            if ($process === null || (int) $process->customer_id !== $customerId) {
                continue;
            }

            $blueprint = $blueprints->get($row->quality_item_id);
            $activity = $blueprint === null ? null : $this->activities->activity($blueprint, (string) $row->activity_key);

            $byControl[(int) $row->control_item_id][] = [
                'id' => (int) $row->id,
                'process_id' => (int) $process->id,
                'process_title' => (string) $process->title,
                'process_code' => $process->code,
                'activity_key' => (string) $row->activity_key,
                'activity_label' => $activity['label'] ?? null,
                'activity_role' => ($activity['role'] ?? '') !== '' ? $activity['role'] : null,
                'activity_exists' => $activity !== null,
                // Straight to the activity in the flow, panel open — see QualityController::show().
                'url' => '/app/quality/items/'.$process->id.'?'.http_build_query([
                    'tab' => 'flow',
                    'activity' => (string) $row->activity_key,
                ]),
            ];
        }

        return $byControl;
    }

    /**
     * Registers a control and places it on the activity, as one write.
     *
     * The caller has already resolved the blueprint and checked the activity exists on it.
     */
    public function add(
        QualityItem $process,
        QualityProcessBlueprint $blueprint,
        string $activityKey,
        string $title,
        ?string $criterion,
        ?User $actor = null,
    ): QualityActivityControl {
        $customerId = (int) $process->customer_id;

        return DB::transaction(function () use ($customerId, $process, $activityKey, $title, $criterion, $actor): QualityActivityControl {
            $control = $this->items->createItem($customerId, [
                'quality_type' => QualityItem::TYPE_CONTROL,
                'title' => $title,
            ], $actor);

            $this->items->updateControlDetail($customerId, $control, ['criterion' => $criterion]);

            return QualityActivityControl::query()->create([
                'customer_id' => $customerId,
                'quality_item_id' => $process->id,
                'activity_key' => $activityKey,
                'control_item_id' => $control->id,
                'created_by_user_id' => $actor?->id,
            ]);
        });
    }

    public function remove(QualityActivityControl $link): void
    {
        $link->delete();
    }

    /** Whether the key names an activity on this flow. */
    public function hasActivity(QualityProcessBlueprint $blueprint, string $activityKey): bool
    {
        return $this->activities->activity($blueprint, $activityKey) !== null;
    }
}
