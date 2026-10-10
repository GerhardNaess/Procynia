<?php

namespace App\Services\Quality;

use App\Models\QualityActivityControl;
use App\Models\QualityItem;
use App\Models\QualityProcessBlueprint;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Controls placed on the activities of a process.
 *
 * A control is not a new kind of thing here: adding one registers an ordinary `control` quality
 * item through QualityItemService, with the description as its criterion, and records which
 * activity it sits on. Removing it takes it off the activity and nothing more — the control stays
 * in the register, where deleting it is a quality.delete decision of its own.
 *
 * A control that already exists is placed the same way, through place(): no copy is made, and its
 * owner, frequency, evidence and history stay on the control. One control may sit on several
 * activities — the unique key is (process, activity, control) — so placing it somewhere new never
 * takes it off where it already was; moving it is placing it and removing the old row.
 *
 * The flow is never written. The activity is checked to exist in the working version and is then
 * referred to by its key only, so an approved revision is never touched by a placement.
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

            return $this->link($customerId, $process, $activityKey, $control, $actor);
        });
    }

    /**
     * Places controls that already exist on activities, as one write: every placement is checked
     * first, and one that does not hold refuses the whole set.
     *
     * Each placement names a process, one of its activities and a control, all of which must be the
     * customer's own. The process must be a process with a working version holding that activity;
     * the control must be a control that is not retired — a retired control is history, not
     * something new work should be checked against. A placement that already exists is left as it
     * is, so sending it twice is not an error and never makes a second row.
     *
     * Used from both ends — the activity in the flow and the control's own page — so there is one
     * set of rules for where a control may go, whichever page the person started on.
     *
     * @param  list<array{process_id: int, activity_key: string, control_item_id: int}>  $placements
     * @return list<QualityActivityControl>
     */
    public function place(int $customerId, array $placements, ?User $actor = null): array
    {
        $items = QualityItem::query()
            ->where('customer_id', $customerId)
            ->whereIn('id', collect($placements)->flatMap(fn (array $placement): array => [
                (int) $placement['process_id'],
                (int) $placement['control_item_id'],
            ])->unique()->values())
            ->get()
            ->keyBy('id');

        $blueprints = QualityProcessBlueprint::query()
            ->where('customer_id', $customerId)
            ->whereIn('quality_item_id', collect($placements)->pluck('process_id')->map(fn ($id): int => (int) $id)->unique()->values())
            ->get()
            ->keyBy('quality_item_id');

        $resolved = [];

        foreach ($placements as $index => $placement) {
            $process = $items->get((int) $placement['process_id']);
            $control = $items->get((int) $placement['control_item_id']);
            $activityKey = (string) $placement['activity_key'];

            // Another customer's process or control reads exactly as one that does not exist.
            if ($process === null || $process->quality_type !== QualityItem::TYPE_PROCESS) {
                throw ValidationException::withMessages([
                    "placements.{$index}.process_id" => __('procynia.quality.errors.item_not_found'),
                ]);
            }

            if ($control === null || $control->quality_type !== QualityItem::TYPE_CONTROL) {
                throw ValidationException::withMessages([
                    "placements.{$index}.control_item_id" => __('procynia.quality.errors.item_not_found'),
                ]);
            }

            if ($control->status === QualityItem::STATUS_RETIRED) {
                throw ValidationException::withMessages([
                    "placements.{$index}.control_item_id" => __('procynia.quality.errors.control_retired'),
                ]);
            }

            $blueprint = $blueprints->get((int) $process->id);

            if ($blueprint === null || ! $this->hasActivity($blueprint, $activityKey)) {
                throw ValidationException::withMessages([
                    "placements.{$index}.activity_key" => __('procynia.quality.errors.activity_not_found'),
                ]);
            }

            $resolved[] = [$process, $activityKey, $control];
        }

        return DB::transaction(fn (): array => array_map(
            fn (array $row): QualityActivityControl => $this->link($customerId, $row[0], $row[1], $row[2], $actor),
            $resolved,
        ));
    }

    /**
     * Every activity a control could be placed on: each step of each process in force that has a
     * working version, read from that version. Retired processes are left out for the same reason
     * retired controls are.
     *
     * @return list<array{process_id: int, process_title: string, process_code: ?string, activity_key: string, activity_label: string, activity_role: ?string}>
     */
    public function activityOptions(int $customerId): array
    {
        $processes = QualityItem::query()
            ->where('customer_id', $customerId)
            ->where('quality_type', QualityItem::TYPE_PROCESS)
            ->where('status', '!=', QualityItem::STATUS_RETIRED)
            ->orderBy('title')
            ->get(['id', 'title', 'code']);

        $blueprints = QualityProcessBlueprint::query()
            ->where('customer_id', $customerId)
            ->whereIn('quality_item_id', $processes->pluck('id'))
            ->get()
            ->keyBy('quality_item_id');

        $options = [];

        foreach ($processes as $process) {
            $blueprint = $blueprints->get($process->id);

            if ($blueprint === null) {
                continue;
            }

            foreach ($blueprint->nodes() as $node) {
                $activity = $this->activities->activity($blueprint, (string) ($node['key'] ?? ''));

                if ($activity === null || $activity['label'] === '') {
                    continue;
                }

                $options[] = [
                    'process_id' => (int) $process->id,
                    'process_title' => (string) $process->title,
                    'process_code' => $process->code,
                    'activity_key' => $activity['key'],
                    'activity_label' => $activity['label'],
                    'activity_role' => $activity['role'] !== '' ? $activity['role'] : null,
                ];
            }
        }

        return $options;
    }

    /**
     * The controls that may be placed on an activity: the customer's own, not retired.
     *
     * @return list<array{id: int, title: string, code: ?string, status: string, criterion: ?string}>
     */
    public function controlOptions(int $customerId): array
    {
        return QualityItem::query()
            ->where('customer_id', $customerId)
            ->where('quality_type', QualityItem::TYPE_CONTROL)
            ->where('status', '!=', QualityItem::STATUS_RETIRED)
            ->with('controlDetail')
            ->orderBy('title')
            ->get()
            ->map(static fn (QualityItem $control): array => [
                'id' => (int) $control->id,
                'title' => (string) $control->title,
                'code' => $control->code,
                'status' => (string) $control->status,
                'criterion' => $control->controlDetail?->criterion,
            ])
            ->values()
            ->all();
    }

    public function remove(QualityActivityControl $link): void
    {
        $link->delete();
    }

    private function link(int $customerId, QualityItem $process, string $activityKey, QualityItem $control, ?User $actor): QualityActivityControl
    {
        return QualityActivityControl::query()->firstOrCreate(
            [
                'quality_item_id' => $process->id,
                'activity_key' => $activityKey,
                'control_item_id' => $control->id,
            ],
            [
                'customer_id' => $customerId,
                'created_by_user_id' => $actor?->id,
            ],
        );
    }

    /** Whether the key names an activity on this flow. */
    public function hasActivity(QualityProcessBlueprint $blueprint, string $activityKey): bool
    {
        return $this->activities->activity($blueprint, $activityKey) !== null;
    }
}
