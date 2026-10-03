<?php

namespace App\Services\Quality;

use App\Jobs\Quality\ProjectQualityItemToGraph;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiPage;
use App\Models\QualityChecklistItem;
use App\Models\QualityControlDetail;
use App\Models\QualityItem;
use App\Models\QualityItemDocument;
use App\Models\QualityItemRelation;
use App\Models\QualityItemWikiLink;
use App\Models\QualityProcessIo;
use App\Models\QualityProcessStep;
use App\Models\User;
use App\Services\EnterpriseWiki\EnterpriseWikiPageDeletionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every write to the quality domain goes through here.
 *
 * The rules this class owns are the ones nothing else can enforce:
 *
 *  - A relation may only join a pair of types the matrix allows. The types sit on the rows being
 *    joined, so no database constraint can express it.
 *  - An item's type never changes. Its structure rows belong to one type and could not follow it
 *    across, so retyping would either strand them or silently delete authored work.
 *  - Structure belongs to the type that has it: steps only on a process, items only on a checklist,
 *    a criterion only on a control.
 *  - The graph is a projection, so it is refreshed from whatever SQL ends up holding — after the
 *    transaction commits, never inside it.
 *
 * Tenancy is a precondition, not a check: callers resolve the customer through CustomerContext and
 * pass it in, and every row this class touches is verified to belong to it first.
 */
class QualityItemService
{
    public function __construct(
        private readonly QualityActivityKnowledgeResolver $activityKnowledge,
        private readonly EnterpriseWikiPageDeletionService $wikiPageDeletion,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createItem(int $customerId, array $attributes, ?User $actor = null): QualityItem
    {
        $qualityType = (string) ($attributes['quality_type'] ?? '');

        if (! in_array($qualityType, QualityItem::TYPES, true)) {
            throw ValidationException::withMessages([
                'quality_type' => __('procynia.quality.errors.unknown_type'),
            ]);
        }

        $item = DB::transaction(function () use ($customerId, $attributes, $qualityType, $actor): QualityItem {
            $item = QualityItem::query()->create([
                'customer_id' => $customerId,
                'quality_type' => $qualityType,
                'title' => trim((string) $attributes['title']),
                'code' => $this->normaliseCode($customerId, $attributes['code'] ?? null, null),
                'purpose' => $this->nullableText($attributes['purpose'] ?? null),
                'owner_user_id' => $this->resolveUserId($customerId, $attributes['owner_user_id'] ?? null, 'owner_user_id'),
                'status' => $this->normaliseStatus($attributes['status'] ?? null),
                'created_by_user_id' => $actor?->id,
            ] + $this->reviewAttributes($customerId, $attributes));

            // A control is incomplete without its own fields, so the row exists from creation —
            // empty, but present. A later edit then updates a row instead of discovering it is
            // missing, and a listing can join rather than left-join.
            if ($qualityType === QualityItem::TYPE_CONTROL) {
                QualityControlDetail::query()->create([
                    'customer_id' => $customerId,
                    'quality_item_id' => $item->id,
                ]);
            }

            return $item;
        });

        $this->reproject([(int) $item->id]);

        return $item;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateItem(int $customerId, QualityItem $item, array $attributes, ?User $actor = null): QualityItem
    {
        $this->assertOwned($customerId, $item);

        // Retyping is refused rather than handled. A process's steps are not a checklist's items,
        // so "change the type" can only mean "delete the structure and keep the title" — which the
        // caller can do explicitly, and should have to.
        if (isset($attributes['quality_type']) && $attributes['quality_type'] !== $item->quality_type) {
            throw ValidationException::withMessages([
                'quality_type' => __('procynia.quality.errors.type_is_immutable'),
            ]);
        }

        DB::transaction(function () use ($customerId, $item, $attributes, $actor): void {
            $changes = [];

            if (array_key_exists('title', $attributes)) {
                $changes['title'] = trim((string) $attributes['title']);
            }

            if (array_key_exists('code', $attributes)) {
                $changes['code'] = $this->normaliseCode($customerId, $attributes['code'], (int) $item->id);
            }

            if (array_key_exists('purpose', $attributes)) {
                $changes['purpose'] = $this->nullableText($attributes['purpose']);
            }

            if (array_key_exists('owner_user_id', $attributes)) {
                $changes['owner_user_id'] = $this->resolveUserId($customerId, $attributes['owner_user_id'], 'owner_user_id');
            }

            if (array_key_exists('status', $attributes)) {
                $changes['status'] = $this->normaliseStatus($attributes['status']);
            }

            $changes += $this->reviewAttributes($customerId, $attributes, $item, $actor);

            $item->fill($changes)->save();
        });

        $this->reproject([(int) $item->id]);

        return $item->refresh();
    }

    /**
     * The Wiki pages this process produced, as pages rather than ids.
     *
     * One resolution, three readers: the list row that says how many there are, the authorisation
     * check that asks whether this user may delete each of them, and the deletion itself. Asking
     * three times is what would let the three answers disagree about the same process.
     *
     * Safety is QualityActivityKnowledgeResolver's, not a rule invented here: a page counts only
     * when a provenance row names this process AND the run that produced it recorded the page as
     * CREATED from this process's own source document. A page the run merely updated, or a page
     * that happens to share a run, is not this process's to remove and never appears here.
     *
     * @return Collection<int, EnterpriseWikiPage>
     */
    public function producedWikiPages(int $customerId, QualityItem $item): Collection
    {
        $this->assertOwned($customerId, $item);

        $resolved = $this->activityKnowledge->resolve($customerId, (int) $item->id);

        return collect($resolved['pages_by_row'])
            ->flatten(1)
            ->filter(static fn (mixed $page): bool => $page instanceof EnterpriseWikiPage)
            ->unique(static fn (EnterpriseWikiPage $page): int => (int) $page->id)
            ->values();
    }

    /**
     * Deleting a styrende dokument, and — only when asked — the Wiki knowledge it produced.
     *
     * $deleteProducedWikiPagesAs is the user on whose behalf the Wiki deletion is performed, not a
     * flag: Wiki will not delete a page without an actor, and a caller that has not decided who is
     * asking has not decided to delete anything. Null is the default and means the knowledge stays,
     * which is what it has always done.
     *
     * The Wiki pages go FIRST, through EnterpriseWikiPageDeletionService — the one place a Wiki
     * page is deleted — because the provenance rows that identify them cascade away with the item.
     * Quality implements none of that cleanup: it hands Wiki a set of pages and lets Wiki's own
     * withdrawal, cascades and fail-closed assertion decide what happens. If Wiki refuses, the
     * exception propagates and the process is still here, which is the right half to keep.
     *
     * The source documents are never touched either way. A file the virksomhet uploaded is the
     * virksomhet's, and that was true before this option existed.
     *
     * @return array{wiki_pages_deleted: int}
     */
    public function deleteItem(int $customerId, QualityItem $item, ?User $deleteProducedWikiPagesAs = null): array
    {
        $this->assertOwned($customerId, $item);

        $itemId = (int) $item->id;
        $wikiPagesDeleted = 0;

        if ($deleteProducedWikiPagesAs !== null) {
            $pageIds = $this->producedWikiPages($customerId, $item)
                ->map(static fn (EnterpriseWikiPage $page): int => (int) $page->id);

            if ($pageIds->isNotEmpty()) {
                $wikiPagesDeleted = $this->wikiPageDeletion
                    ->deletePages($customerId, $pageIds, $deleteProducedWikiPagesAs)['pages_deleted'];
            }
        }

        // The other end of every edge that touched this item has to be reprojected too: the graph
        // stores edges as the outgoing set of their start node, so an incoming edge is only removed
        // by rebuilding the node it comes from.
        $neighbourIds = QualityItemRelation::query()
            ->where('customer_id', $customerId)
            ->where('to_item_id', $itemId)
            ->pluck('from_item_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        // Structure rows, relations, wiki links and document links all cascade in the database.
        // The documents themselves are untouched — a file is the virksomhet's, not the item's.
        DB::transaction(static fn () => $item->delete());

        $this->deprojectItem($customerId, $itemId);
        $this->reproject($neighbourIds);

        return ['wiki_pages_deleted' => $wikiPagesDeleted];
    }

    // -----------------------------------------------------------------
    // Structure
    // -----------------------------------------------------------------

    /**
     * Replace a process's steps with the given list, in the order given.
     *
     * Wholesale rather than row by row: steps are reordered as often as they are edited, and a
     * partial update would have to reconcile positions against a unique index that forbids the
     * intermediate states. The rows are still rows — they are simply written as a set.
     *
     * @param  list<array<string, mixed>>  $steps
     */
    public function replaceProcessSteps(int $customerId, QualityItem $item, array $steps): void
    {
        $this->assertOwned($customerId, $item);
        $this->assertType($item, QualityItem::TYPE_PROCESS, 'steps');

        DB::transaction(function () use ($customerId, $item, $steps): void {
            QualityProcessStep::query()->where('quality_item_id', $item->id)->delete();

            foreach (array_values($steps) as $index => $step) {
                $title = trim((string) ($step['title'] ?? ''));

                if ($title === '') {
                    continue;
                }

                QualityProcessStep::query()->create([
                    'customer_id' => $customerId,
                    'quality_item_id' => $item->id,
                    'position' => $index + 1,
                    'title' => $title,
                    'description' => $this->nullableText($step['description'] ?? null),
                    'responsibility' => $this->nullableText($step['responsibility'] ?? null),
                ]);
            }
        });
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    public function replaceProcessIo(int $customerId, QualityItem $item, string $direction, array $entries): void
    {
        $this->assertOwned($customerId, $item);
        $this->assertType($item, QualityItem::TYPE_PROCESS, 'io');

        if (! in_array($direction, QualityProcessIo::DIRECTIONS, true)) {
            throw ValidationException::withMessages([
                'direction' => __('procynia.quality.errors.unknown_io_direction'),
            ]);
        }

        DB::transaction(function () use ($customerId, $item, $direction, $entries): void {
            QualityProcessIo::query()
                ->where('quality_item_id', $item->id)
                ->where('direction', $direction)
                ->delete();

            foreach (array_values($entries) as $index => $entry) {
                $label = trim((string) ($entry['label'] ?? ''));

                if ($label === '') {
                    continue;
                }

                QualityProcessIo::query()->create([
                    'customer_id' => $customerId,
                    'quality_item_id' => $item->id,
                    'direction' => $direction,
                    'position' => $index + 1,
                    'label' => $label,
                    'description' => $this->nullableText($entry['description'] ?? null),
                ]);
            }
        });
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    public function replaceChecklistItems(int $customerId, QualityItem $item, array $entries): void
    {
        $this->assertOwned($customerId, $item);
        $this->assertType($item, QualityItem::TYPE_CHECKLIST, 'checklist_items');

        DB::transaction(function () use ($customerId, $item, $entries): void {
            QualityChecklistItem::query()->where('quality_item_id', $item->id)->delete();

            foreach (array_values($entries) as $index => $entry) {
                $text = trim((string) ($entry['text'] ?? ''));

                if ($text === '') {
                    continue;
                }

                QualityChecklistItem::query()->create([
                    'customer_id' => $customerId,
                    'quality_item_id' => $item->id,
                    'position' => $index + 1,
                    'text' => $text,
                    'guidance' => $this->nullableText($entry['guidance'] ?? null),
                    'is_required' => (bool) ($entry['is_required'] ?? true),
                ]);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateControlDetail(int $customerId, QualityItem $item, array $attributes): QualityControlDetail
    {
        $this->assertOwned($customerId, $item);
        $this->assertType($item, QualityItem::TYPE_CONTROL, 'control');

        $frequency = $attributes['frequency'] ?? null;

        if ($frequency !== null && $frequency !== '' && ! in_array($frequency, QualityControlDetail::FREQUENCIES, true)) {
            throw ValidationException::withMessages([
                'frequency' => __('procynia.quality.errors.unknown_frequency'),
            ]);
        }

        return QualityControlDetail::query()->updateOrCreate(
            ['quality_item_id' => $item->id],
            [
                'customer_id' => $customerId,
                'criterion' => $this->nullableText($attributes['criterion'] ?? null),
                'responsibility' => $this->nullableText($attributes['responsibility'] ?? null),
                'frequency' => $frequency === '' ? null : $frequency,
                'method' => $this->nullableText($attributes['method'] ?? null),
            ],
        );
    }

    // -----------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------

    public function relate(
        int $customerId,
        QualityItem $fromItem,
        QualityItem $toItem,
        string $relationType,
        ?User $actor = null,
    ): QualityItemRelation {
        $this->assertOwned($customerId, $fromItem, 'from_item_id');
        $this->assertOwned($customerId, $toItem, 'to_item_id');

        if (! in_array($relationType, QualityItemRelation::TYPES, true)) {
            throw ValidationException::withMessages([
                'relation_type' => __('procynia.quality.errors.unknown_relation_type'),
            ]);
        }

        if ((int) $fromItem->id === (int) $toItem->id) {
            throw ValidationException::withMessages([
                'to_item_id' => __('procynia.quality.errors.relation_self_reference'),
            ]);
        }

        if (! QualityItemRelation::allows($relationType, $fromItem->quality_type, $toItem->quality_type)) {
            throw ValidationException::withMessages([
                'relation_type' => __('procynia.quality.errors.relation_not_allowed'),
            ]);
        }

        $relation = QualityItemRelation::query()->firstOrCreate(
            [
                'customer_id' => $customerId,
                'from_item_id' => $fromItem->id,
                'to_item_id' => $toItem->id,
                'relation_type' => $relationType,
            ],
            [
                'source' => QualityItemRelation::SOURCE_MANUAL,
                'created_by_user_id' => $actor?->id,
            ],
        );

        $this->reproject([(int) $fromItem->id]);

        return $relation;
    }

    public function unrelate(int $customerId, QualityItemRelation $relation): void
    {
        if ((int) $relation->customer_id !== $customerId) {
            throw ValidationException::withMessages([
                'relation' => __('procynia.quality.errors.relation_not_found'),
            ]);
        }

        $fromItemId = (int) $relation->from_item_id;

        $relation->delete();

        $this->reproject([$fromItemId]);
    }

    // -----------------------------------------------------------------
    // Wiki
    // -----------------------------------------------------------------

    /**
     * Attach a Wiki page to a quality item as documentation, background or evidence.
     *
     * Nothing is written to the page. That is the point: the Wiki catalogue is unchanged by being
     * referenced, and the same page may be referenced by any number of items.
     */
    public function linkWikiPage(
        int $customerId,
        QualityItem $item,
        EnterpriseWikiPage $page,
        string $linkType = QualityItemWikiLink::LINK_TYPE_DOCUMENTS,
        ?string $note = null,
        ?User $actor = null,
    ): QualityItemWikiLink {
        $this->assertOwned($customerId, $item);

        if ((int) $page->customer_id !== $customerId) {
            // Deliberately the message a missing page would give: a page in another customer's Wiki
            // must not be distinguishable from one that does not exist.
            throw ValidationException::withMessages([
                'enterprise_wiki_page_id' => __('procynia.quality.errors.page_not_found'),
            ]);
        }

        if (! in_array($linkType, QualityItemWikiLink::LINK_TYPES, true)) {
            throw ValidationException::withMessages([
                'link_type' => __('procynia.quality.errors.unknown_link_type'),
            ]);
        }

        $link = QualityItemWikiLink::query()->firstOrCreate(
            [
                'quality_item_id' => $item->id,
                'enterprise_wiki_page_id' => $page->id,
                'link_type' => $linkType,
            ],
            [
                'customer_id' => $customerId,
                'note' => $this->nullableText($note),
                'source' => QualityItemWikiLink::SOURCE_MANUAL,
                'created_by_user_id' => $actor?->id,
            ],
        );

        $this->reproject([(int) $item->id]);

        return $link;
    }

    public function unlinkWikiPage(int $customerId, QualityItemWikiLink $link): void
    {
        if ((int) $link->customer_id !== $customerId) {
            throw ValidationException::withMessages([
                'link' => __('procynia.quality.errors.link_not_found'),
            ]);
        }

        $itemId = (int) $link->quality_item_id;

        $link->delete();

        $this->reproject([$itemId]);
    }

    // -----------------------------------------------------------------
    // Documents
    // -----------------------------------------------------------------

    /**
     * Attach a file from the virksomhet's document store to a quality item.
     *
     * Nothing is written to the document, and nothing is copied: the file stays exactly where the
     * upload put it, and every other item that uses it keeps using the same bytes. Detaching later
     * removes this row alone — see unlinkDocument().
     *
     * No graph reprojection, unlike linkWikiPage(). Documents are not nodes in the Neo4j projection
     * — GraphProjectionService knows wiki pages and quality items and nothing else — so there is no
     * edge for this to keep in step, and dispatching a projection job would only burn a worker slot
     * rebuilding a node this write did not change.
     */
    public function linkDocument(
        int $customerId,
        QualityItem $item,
        EnterpriseWikiDocument $document,
        string $relationType = QualityItemDocument::RELATION_TYPE_SOURCE,
        ?string $note = null,
        ?User $actor = null,
    ): QualityItemDocument {
        $this->assertOwned($customerId, $item);

        if ((int) $document->customer_id !== $customerId) {
            // Deliberately the message a missing document would give, for the same reason
            // linkWikiPage() does it: another customer's file must not be distinguishable from one
            // that does not exist.
            throw ValidationException::withMessages([
                'enterprise_wiki_document_id' => __('procynia.quality.errors.document_not_found'),
            ]);
        }

        if (! in_array($relationType, QualityItemDocument::RELATION_TYPES, true)) {
            throw ValidationException::withMessages([
                'relation_type' => __('procynia.quality.errors.unknown_document_relation_type'),
            ]);
        }

        return QualityItemDocument::query()->firstOrCreate(
            [
                'quality_item_id' => $item->id,
                'enterprise_wiki_document_id' => $document->id,
                'relation_type' => $relationType,
            ],
            [
                'customer_id' => $customerId,
                'note' => $this->nullableText($note),
                'source' => QualityItemDocument::SOURCE_MANUAL,
                'created_by_user_id' => $actor?->id,
            ],
        );
    }

    /**
     * Detach a file from a quality item.
     *
     * The link row goes; the document, its bytes and its other attachments stay. Deleting the file
     * itself is a different act with a different authority — it lives in Wiki → Kildedokumenter,
     * where EnterpriseWikiDocumentDeletionService knows what else is built on it.
     */
    public function unlinkDocument(int $customerId, QualityItemDocument $link): void
    {
        if ((int) $link->customer_id !== $customerId) {
            throw ValidationException::withMessages([
                'link' => __('procynia.quality.errors.document_link_not_found'),
            ]);
        }

        $link->delete();
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Review metadata, with next_review_at derived rather than typed.
     *
     * A date somebody has to remember to move is a date that is wrong. Given an interval and a last
     * review, the next one follows; given neither, the field stays null and the item simply has no
     * review cycle, which is a legitimate state for a work instruction nobody revisits on a clock.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function reviewAttributes(
        int $customerId,
        array $attributes,
        ?QualityItem $existing = null,
        ?User $actor = null,
    ): array {
        $touchesReview = array_key_exists('review_interval_months', $attributes)
            || array_key_exists('last_reviewed_at', $attributes);

        if (! $touchesReview) {
            return [];
        }

        $interval = array_key_exists('review_interval_months', $attributes)
            ? $attributes['review_interval_months']
            : $existing?->review_interval_months;

        $interval = $interval === null || $interval === '' ? null : (int) $interval;

        $lastReviewed = array_key_exists('last_reviewed_at', $attributes)
            ? $attributes['last_reviewed_at']
            : $existing?->last_reviewed_at;

        $lastReviewed = $this->toDate($lastReviewed);

        $changes = [
            'review_interval_months' => $interval,
            'last_reviewed_at' => $lastReviewed,
            'next_review_at' => $interval !== null && $lastReviewed !== null
                ? $lastReviewed->addMonths($interval)
                : null,
        ];

        // Recording a review is recording who did it. Only set when the date actually moved, so an
        // unrelated edit does not rewrite the reviewer.
        if ($lastReviewed !== null && ! $this->sameDate($lastReviewed, $existing?->last_reviewed_at)) {
            $changes['last_reviewed_by_user_id'] = $this->resolveUserId($customerId, $actor?->id, 'last_reviewed_by_user_id');
        }

        return $changes;
    }

    private function toDate(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value)->startOfDay();
    }

    private function sameDate(?CarbonImmutable $left, mixed $right): bool
    {
        $rightDate = $this->toDate($right);

        if ($left === null || $rightDate === null) {
            return $left === null && $rightDate === null;
        }

        return $left->isSameDay($rightDate);
    }

    /**
     * The code is unique per customer where it is given, so the collision is caught here with a
     * field-level message rather than surfacing as a database error.
     */
    private function normaliseCode(int $customerId, mixed $code, ?int $ignoreItemId): ?string
    {
        $code = $code === null ? null : trim((string) $code);

        if ($code === null || $code === '') {
            return null;
        }

        $taken = QualityItem::query()
            ->where('customer_id', $customerId)
            ->whereRaw('LOWER(code) = ?', [mb_strtolower($code)])
            ->when($ignoreItemId !== null, fn ($query) => $query->where('id', '!=', $ignoreItemId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'code' => __('procynia.quality.errors.code_taken'),
            ]);
        }

        return $code;
    }

    private function normaliseStatus(mixed $status): string
    {
        if ($status === null || $status === '') {
            return QualityItem::STATUS_DRAFT;
        }

        if (! in_array($status, QualityItem::STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => __('procynia.quality.errors.unknown_status'),
            ]);
        }

        return (string) $status;
    }

    /**
     * An owner must be a user of this customer. A super admin has no customer and is not an owner
     * candidate — accountability for a styrende dokument sits inside the virksomhet.
     */
    private function resolveUserId(int $customerId, mixed $userId, string $field): ?int
    {
        if ($userId === null || $userId === '') {
            return null;
        }

        $exists = User::query()
            ->where('id', (int) $userId)
            ->where('customer_id', $customerId)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                $field => __('procynia.quality.errors.user_not_found'),
            ]);
        }

        return (int) $userId;
    }

    private function nullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function assertOwned(int $customerId, QualityItem $item, string $field = 'quality_item_id'): void
    {
        if ((int) $item->customer_id === $customerId) {
            return;
        }

        throw ValidationException::withMessages([
            $field => __('procynia.quality.errors.item_not_found'),
        ]);
    }

    private function assertType(QualityItem $item, string $expected, string $field): void
    {
        if ($item->quality_type === $expected) {
            return;
        }

        throw ValidationException::withMessages([
            $field => __('procynia.quality.errors.structure_type_mismatch'),
        ]);
    }

    /**
     * @param  list<int>  $itemIds
     */
    private function reproject(array $itemIds): void
    {
        foreach (array_values(array_unique($itemIds)) as $itemId) {
            // After commit: the job reads SQL, and on the sync driver it would otherwise run
            // against a transaction that has not landed yet.
            ProjectQualityItemToGraph::dispatch($itemId)->afterCommit();
        }
    }

    private function deprojectItem(int $customerId, int $itemId): void
    {
        ProjectQualityItemToGraph::dispatch($itemId, $customerId)->afterCommit();
    }
}
