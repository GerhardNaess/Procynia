<?php

namespace App\Services\Api\Presenters;

use App\Models\QualityItem;
use App\Models\QualityItemDocument;
use App\Services\Quality\QualityAttentionService;
use App\Services\Quality\QualityToolService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Kvalitet as the external API presents it: structured fields only (D8), and nothing that depends
 * on a version of a process flow.
 *
 * In: the register's items (type, title, code, status, ansvarlig, review dates) and their attention
 * codes; for controls also the frequency, how much evidence is registered and the tools from the
 * library. Out: purpose, steps, inputs and outputs, checklists, the control's criterion, method and
 * responsibility, evidence and tool descriptions, files and downloads, every person who added or
 * withdrew something, the Wiki links and web routes — and everything read from a process flow: the
 * flow itself, its publication state, where a control is placed in it and the attention code
 * controls_without_activity. Which version of a flow the API may show (the approved one or the
 * working version) is not decided (KVA-02).
 *
 * The attention codes are QualityAttentionService's, the tools QualityToolService's.
 */
class QualityPresenter
{
    /** The finding left out: it is judged against the working version of the process flows. */
    public const FLOW_DEPENDENT_FINDINGS = [QualityAttentionService::CONTROLS_WITHOUT_ACTIVITY];

    public function __construct(
        private readonly QualityAttentionService $attention,
        private readonly QualityToolService $tools,
    ) {}

    /**
     * @param  Collection<int, QualityItem>  $items  of one customer, with owner loaded
     * @return list<array<string, mixed>>
     */
    public function rows(Collection $items, int $customerId): array
    {
        $codes = $this->attentionByItem($customerId);

        return $items->map(fn (QualityItem $item): array => $this->row($item, $codes[(int) $item->id] ?? []))->values()->all();
    }

    /**
     * Controls, with what the control register shows about them that is not free text.
     *
     * @param  Collection<int, QualityItem>  $controls  of one customer, with owner and controlDetail loaded
     * @return list<array<string, mixed>>
     */
    public function controlRows(Collection $controls, int $customerId): array
    {
        $codes = $this->attentionByItem($customerId);
        // Active evidence only, as the control register counts it: withdrawn evidence is history.
        $evidence = QualityItemDocument::query()
            ->where('customer_id', $customerId)
            ->whereIn('quality_item_id', $controls->modelKeys() ?: [0])
            ->where('relation_type', QualityItemDocument::RELATION_TYPE_EVIDENCE)
            ->whereNull('withdrawn_at')
            ->selectRaw('quality_item_id, count(*) as evidence_count, max(created_at) as latest_at')
            ->groupBy('quality_item_id')
            ->get()
            ->keyBy('quality_item_id');

        return $controls->map(function (QualityItem $control) use ($codes, $evidence, $customerId): array {
            $registered = $evidence->get($control->id);

            return $this->row($control, $codes[(int) $control->id] ?? []) + [
                'frequency' => $control->controlDetail?->frequency,
                'evidence' => [
                    'count' => (int) ($registered->evidence_count ?? 0),
                    'latest_added_at' => $registered?->latest_at !== null ? CarbonImmutable::parse($registered->latest_at)->utc()->toIso8601ZuluString() : null,
                ],
                'tools' => array_map(fn (array $tool): array => [
                    'id' => $tool['id'],
                    'title' => $tool['title'],
                    'category' => $tool['category'],
                ], $this->tools->forControl($customerId, $control)),
            ];
        })->values()->all();
    }

    /**
     * Processes linked from another module, at process level: a version of the flow is never read
     * for it. The links' own groupedContext() shape, without activity names, roles and routes.
     *
     * @param  list<array<string, mixed>>  $grouped  from QualityProcessContextReader::groupedContext()
     * @return list<array{id: int, title: string, code: ?string, whole_process: bool, activity_count: int}>
     */
    public static function linkedProcesses(array $grouped): array
    {
        return array_map(fn (array $process): array => [
            'id' => $process['id'],
            'title' => $process['title'],
            'code' => $process['code'],
            'whole_process' => $process['whole_process'],
            'activity_count' => count($process['activities']),
        ], $grouped);
    }

    /**
     * The attention codes per item, in QualityAttentionService's order, without the flow-dependent one.
     *
     * @return array<int, list<string>>
     */
    public function attentionByItem(int $customerId): array
    {
        $codes = [];

        foreach ($this->attention->findings($customerId) as $finding) {
            if (in_array($finding['key'], self::FLOW_DEPENDENT_FINDINGS, true)) {
                continue;
            }

            foreach ($finding['items'] as $item) {
                $codes[(int) $item['id']][] = $finding['key'];
            }
        }

        return $codes;
    }

    /**
     * @param  list<string>  $attention
     * @return array<string, mixed>
     */
    private function row(QualityItem $item, array $attention): array
    {
        return [
            'id' => (int) $item->id,
            'quality_type' => $item->quality_type,
            'title' => $item->title,
            'code' => $item->code,
            'status' => $item->status,
            'owner' => $item->owner_user_id !== null
                ? ['id' => (int) $item->owner_user_id, 'name' => $item->owner?->name]
                : null,
            'review_interval_months' => $item->review_interval_months,
            'last_reviewed_on' => $item->last_reviewed_at?->toDateString(),
            'next_review_on' => $item->next_review_at?->toDateString(),
            'attention' => $attention,
            'created_at' => $item->created_at?->utc()->toIso8601ZuluString(),
            'updated_at' => $item->updated_at?->utc()->toIso8601ZuluString(),
        ];
    }
}
