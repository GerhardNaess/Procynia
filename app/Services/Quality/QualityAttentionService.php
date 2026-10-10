<?php

namespace App\Services\Quality;

use App\Models\QualityItem;
use App\Models\QualityItemDocument;
use App\Models\QualityItemRelation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What in the kvalitetssystem needs attention — read straight off the rows that already exist.
 *
 * Each finding is one fixed rule over Quality's own tables, answered the same way every time. No
 * finding is stored: the list is recomputed on every read, so it can never disagree with the
 * objects it points at, and fixing an object is the only way to clear it.
 *
 * Retired items are left out throughout. They are no longer in force, so a gap in one is history,
 * not something anybody needs to act on.
 *
 * Deliberately not here: "a governing policy changed after the process was last approved". A
 * policy has no revisions and no record of when its content changed — `updated_at` also moves when
 * its owner, number, status or review date is edited, and the files and Wiki pages that carry its
 * actual text are separate rows that never touch it — so "changed" could not be told from "touched".
 *
 * Nor "activity without a responsible role". A flow cannot record that state
 * reliably — normalisation puts every activity in a lane, and the placeholder lane for "no role"
 * is only recognisable by a translated label or by a key one write path uses and the other does
 * not — so a rule over it would be a guess.
 */
class QualityAttentionService
{
    public const CONTROLS_WITHOUT_EVIDENCE = 'controls_without_evidence';

    public const CONTROLS_WITHOUT_ACTIVITY = 'controls_without_activity';

    public const PROCESSES_WITHOUT_GOVERNING_POLICY = 'processes_without_governing_policy';

    public const PROCESSES_OVERDUE_FOR_REVIEW = 'processes_overdue_for_review';

    public function __construct(
        private readonly QualityActivityControlService $activityControls,
    ) {}

    /**
     * Every finding, in a fixed order, each with the objects it is about. A finding with nothing
     * in it is still returned, so the page can say the check ran and came back clean.
     *
     * @return list<array{key: string, items: list<array{id: int, title: string, code: ?string, url: string}>}>
     */
    public function findings(int $customerId): array
    {
        return [
            [
                'key' => self::CONTROLS_WITHOUT_EVIDENCE,
                'items' => $this->rows($this->controlsWithoutEvidence($customerId)),
            ],
            [
                'key' => self::CONTROLS_WITHOUT_ACTIVITY,
                'items' => $this->rows($this->controlsWithoutActivity($customerId)),
            ],
            [
                'key' => self::PROCESSES_WITHOUT_GOVERNING_POLICY,
                'items' => $this->rows($this->processesWithoutGoverningDocument($customerId)),
            ],
            [
                'key' => self::PROCESSES_OVERDUE_FOR_REVIEW,
                'items' => $this->rows($this->processesOverdueForReview($customerId)),
            ],
        ];
    }

    /**
     * A control with no evidence row at all. Evidence whose file has since been deleted still
     * counts: it stays on the control as a record that the control was carried out.
     *
     * @return Collection<int, QualityItem>
     */
    private function controlsWithoutEvidence(int $customerId): Collection
    {
        return $this->inForce($customerId, QualityItem::TYPE_CONTROL)
            ->whereNotExists(function ($query) use ($customerId): void {
                $query->selectRaw('1')
                    ->from('quality_item_documents')
                    ->whereColumn('quality_item_documents.quality_item_id', 'quality_items.id')
                    ->where('quality_item_documents.customer_id', $customerId)
                    ->where('quality_item_documents.relation_type', QualityItemDocument::RELATION_TYPE_EVIDENCE);
            })
            ->get();
    }

    /**
     * A control not placed on any activity that exists. A placement whose activity has since been
     * removed from the flow is no placement — the Kontroller tab already says so for the same row.
     *
     * @return Collection<int, QualityItem>
     */
    private function controlsWithoutActivity(int $customerId): Collection
    {
        $placements = $this->activityControls->placementsByControl($customerId);

        return $this->inForce($customerId, QualityItem::TYPE_CONTROL)
            ->get()
            ->reject(static fn (QualityItem $control): bool => collect($placements[(int) $control->id] ?? [])
                ->contains(static fn (array $placement): bool => $placement['activity_exists']))
            ->values();
    }

    /**
     * A process no styrende dokument in force governs — a policy, procedure, work instruction or
     * checklist alike. `governs` is the one relation that names a process's governing document, and
     * its type matrix says which kinds may stand at its start — see QualityItemRelation::TYPE_GOVERNS.
     * The finding keeps its original key, which Ledelsens gjennomgåelse snapshots by name.
     *
     * @return Collection<int, QualityItem>
     */
    private function processesWithoutGoverningDocument(int $customerId): Collection
    {
        return $this->inForce($customerId, QualityItem::TYPE_PROCESS)
            ->whereNotExists(function ($query) use ($customerId): void {
                $query->selectRaw('1')
                    ->from('quality_item_relations')
                    ->join('quality_items as documents', 'documents.id', '=', 'quality_item_relations.from_item_id')
                    ->whereColumn('quality_item_relations.to_item_id', 'quality_items.id')
                    ->where('quality_item_relations.customer_id', $customerId)
                    ->where('quality_item_relations.relation_type', QualityItemRelation::TYPE_GOVERNS)
                    ->where('documents.customer_id', $customerId)
                    ->whereIn('documents.quality_type', QualityItemRelation::allowedFromTypes(QualityItemRelation::TYPE_GOVERNS))
                    ->where('documents.status', '!=', QualityItem::STATUS_RETIRED);
            })
            ->get();
    }

    /**
     * A process whose next review date has passed. The date is never typed: QualityItemService
     * derives it from the last review and the interval, so a process with no review cycle has no
     * date and is not overdue. Due today is not yet overdue.
     *
     * @return Collection<int, QualityItem>
     */
    private function processesOverdueForReview(int $customerId): Collection
    {
        return $this->inForce($customerId, QualityItem::TYPE_PROCESS)
            ->whereNotNull('next_review_at')
            ->whereDate('next_review_at', '<', today()->toDateString())
            ->get();
    }

    /** @return Builder<QualityItem> */
    private function inForce(int $customerId, string $type): Builder
    {
        return QualityItem::query()
            ->where('customer_id', $customerId)
            ->where('quality_type', $type)
            ->where('status', '!=', QualityItem::STATUS_RETIRED)
            ->select(['id', 'customer_id', 'title', 'code', 'status', 'quality_type']);
    }

    /**
     * @param  Collection<int, QualityItem>  $items
     * @return list<array{id: int, title: string, code: ?string, url: string}>
     */
    private function rows(Collection $items): array
    {
        return $items
            ->sortBy(static fn (QualityItem $item): string => mb_strtolower((string) $item->title))
            ->map(static fn (QualityItem $item): array => [
                'id' => (int) $item->id,
                'title' => (string) $item->title,
                'code' => $item->code,
                'url' => route('app.quality.items.show', ['item' => $item->id]),
            ])
            ->values()
            ->all();
    }
}
