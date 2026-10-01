<?php

namespace App\Services\Quality;

use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\QualityItem;
use App\Models\QualityItemRelation;
use App\Models\QualityItemWikiLink;
use App\Services\EnterpriseWiki\GraphProjection\GraphProjectionService;

/**
 * Projects one quality item onto the graph: the node, the edges that leave it, and the Wiki pages
 * it draws on.
 *
 * Its own class rather than more methods on EnterpriseWikiGraphProjector because the two are
 * triggered by different things — the Wiki pipeline moves pages and wikilinks, Kvalitet moves items,
 * relations and links — and neither should have to rebuild the other's half to touch its own. They
 * share the writer, not the orchestration.
 *
 * SQL is the source of truth throughout. Nothing is ever read back out of the graph to decide what
 * SQL should hold, and a failed projection is a stale graph rather than lost data:
 * `wiki:graph-project` rebuilds a customer from SQL and repairs it.
 */
class QualityGraphProjector
{
    public function __construct(
        private readonly GraphProjectionService $projection,
    ) {}

    public function projectItem(int $itemId): void
    {
        $item = QualityItem::query()->find($itemId);

        if (! $item instanceof QualityItem) {
            return;
        }

        $customerId = (int) $item->customer_id;
        $itemId = (int) $item->id;

        $this->projection->upsertQualityItem($this->itemPayload($item));

        $this->projectRelations($customerId, $itemId);
        $this->projectWikiLinks($customerId, $itemId);
    }

    public function deleteItem(int $customerId, int $itemId): void
    {
        $this->projection->deleteQualityItem($customerId, $itemId);
    }

    private function projectRelations(int $customerId, int $itemId): void
    {
        $relations = QualityItemRelation::query()
            ->where('customer_id', $customerId)
            ->where('from_item_id', $itemId)
            ->orderBy('id')
            ->get();

        // The graph writer matches the target instead of creating it, so an edge to an item that
        // has not been projected yet would be dropped without a trace. Project the targets first;
        // only items that exist in SQL for this same customer qualify, so a cross-customer target
        // stays out of the graph rather than becoming a placeholder node.
        $projectable = [];

        $targetItems = QualityItem::query()
            ->where('customer_id', $customerId)
            ->whereIn('id', $relations->pluck('to_item_id')->map(fn ($id): int => (int) $id)->unique()->all())
            ->orderBy('id')
            ->get();

        foreach ($targetItems as $target) {
            $projectable[(int) $target->id] = true;

            if ((int) $target->id !== $itemId) {
                $this->projection->upsertQualityItem($this->itemPayload($target));
            }
        }

        $payloads = $relations
            ->filter(fn (QualityItemRelation $relation): bool => isset($projectable[(int) $relation->to_item_id]))
            ->map(fn (QualityItemRelation $relation): array => $this->relationPayload($relation))
            ->values()
            ->all();

        // Always called, including with an empty list: this is what removes stale edges.
        $this->projection->replaceOutgoingQualityItemRelations($customerId, $itemId, $payloads);
    }

    private function projectWikiLinks(int $customerId, int $itemId): void
    {
        $links = QualityItemWikiLink::query()
            ->where('customer_id', $customerId)
            ->where('quality_item_id', $itemId)
            ->orderBy('id')
            ->get();

        // Same reason as the relations: the Wiki page at the other end has to be a node already.
        // Unlike a quality item, the page may legitimately not be projected yet — the Wiki
        // projection runs on its own schedule — so the page is upserted here rather than skipped,
        // and the next Wiki projection overwrites it with the fuller payload.
        $pages = EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->whereIn('id', $links->pluck('enterprise_wiki_page_id')->map(fn ($id): int => (int) $id)->unique()->all())
            ->orderBy('id')
            ->get();

        $projectable = [];

        foreach ($pages as $page) {
            $projectable[(int) $page->id] = true;
            $this->projection->upsertWikiPage($this->pagePayload($page));
        }

        $payloads = $links
            ->filter(fn (QualityItemWikiLink $link): bool => isset($projectable[(int) $link->enterprise_wiki_page_id]))
            ->map(fn (QualityItemWikiLink $link): array => $this->wikiLinkPayload($link))
            ->values()
            ->all();

        $this->projection->replaceQualityItemWikiLinks($customerId, $itemId, $payloads);
    }

    /**
     * @return array<string, mixed>
     */
    private function itemPayload(QualityItem $item): array
    {
        return [
            'customer_id' => (int) $item->customer_id,
            'quality_item_id' => (int) $item->id,
            'quality_type' => $item->quality_type,
            'title' => $item->title,
            'code' => $item->code,
            'status' => $item->status,
            'owner_user_id' => $item->owner_user_id !== null ? (int) $item->owner_user_id : null,
            'next_review_at' => $item->next_review_at?->toDateString(),
            'updated_at' => $item->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The Wiki node as Kvalitet can describe it. Deliberately the same key set the Wiki projector
     * writes, so an upsert from this side cannot strip properties off a page that the Wiki
     * projection had already filled in.
     *
     * @return array<string, mixed>
     */
    private function pagePayload(EnterpriseWikiPage $page): array
    {
        // Resolved the same way the Wiki projector resolves it — there is no column for it; the
        // current version is the one flagged on the versions table.
        $currentVersionId = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->where('is_current', true)
            ->value('id');

        return [
            'customer_id' => (int) $page->customer_id,
            'page_id' => (int) $page->id,
            'slug' => $page->slug,
            'title' => $page->title,
            'page_type' => $page->page_type,
            'status' => $page->status,
            'current_version_id' => $currentVersionId !== null ? (int) $currentVersionId : null,
            'updated_at' => $page->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function relationPayload(QualityItemRelation $relation): array
    {
        return [
            'relation_id' => (int) $relation->id,
            'customer_id' => (int) $relation->customer_id,
            'from_item_id' => (int) $relation->from_item_id,
            'to_item_id' => (int) $relation->to_item_id,
            'relation_type' => $relation->relation_type,
            'source' => $relation->source,
            'updated_at' => $relation->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function wikiLinkPayload(QualityItemWikiLink $link): array
    {
        return [
            'link_id' => (int) $link->id,
            'customer_id' => (int) $link->customer_id,
            'quality_item_id' => (int) $link->quality_item_id,
            'page_id' => (int) $link->enterprise_wiki_page_id,
            'link_type' => $link->link_type,
            'source' => $link->source,
            'updated_at' => $link->updated_at?->toIso8601String(),
        ];
    }
}
