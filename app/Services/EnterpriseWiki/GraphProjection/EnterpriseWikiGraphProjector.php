<?php

namespace App\Services\EnterpriseWiki\GraphProjection;

use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\QualityItem;
use App\Models\QualityItemRelation;
use App\Models\QualityItemWikiLink;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class EnterpriseWikiGraphProjector
{
    public function __construct(
        private readonly GraphProjectionService $projection,
    ) {}

    public function projectPage(int $pageId): void
    {
        $page = EnterpriseWikiPage::query()->find($pageId);

        if (! $page instanceof EnterpriseWikiPage) {
            return;
        }

        $customerId = (int) $page->customer_id;
        $pageId = (int) $page->id;

        $this->projection->upsertWikiPage($this->pagePayload($page));

        $links = $this->outgoingWikilinks($customerId, $pageId);

        // The graph writer matches the target node instead of creating it, so an edge to a page
        // that has not been projected yet would be dropped without a trace. Project the targets
        // first. Only pages that exist in SQL for this same customer qualify: a missing or
        // cross-customer target must stay out of the graph rather than become a placeholder node.
        $projectableTargetIds = [];

        foreach ($this->existingTargetPages($customerId, $links->pluck('to_page_id')->all()) as $target) {
            $projectableTargetIds[(int) $target->id] = true;

            if ((int) $target->id !== $pageId) {
                $this->projection->upsertWikiPage($this->pagePayload($target));
            }
        }

        $payloads = $links
            ->filter(fn (EnterpriseWikiPageLink $link): bool => isset($projectableTargetIds[(int) $link->to_page_id]))
            ->map(fn (EnterpriseWikiPageLink $link): array => $this->linkPayload($link))
            ->values()
            ->all();

        // Always called, including with an empty list: this is what removes stale edges.
        $this->projection->replaceOutgoingWikilinks($customerId, $pageId, $payloads);
    }

    public function deletePage(int $customerId, int $pageId): void
    {
        $this->projection->deleteWikiPage($customerId, $pageId);
    }

    /**
     * Rebuild everything this customer has in the graph, from SQL.
     *
     * Quality travels with the Wiki rebuild rather than in a call of its own because
     * replaceCustomerWikiGraph deletes the customer's nodes first: quality left out here would be
     * dropped by a routine Wiki rebuild and never come back.
     */
    public function rebuildCustomer(int $customerId): void
    {
        $pages = EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->orderBy('id')
            ->get()
            ->map(fn (EnterpriseWikiPage $page): array => $this->pagePayload($page))
            ->values()
            ->all();

        $pageIds = collect($pages)->pluck('page_id')->all();

        $links = $this->wikilinkQuery($customerId)
            ->whereIn('from_page_id', $pageIds)
            ->whereIn('to_page_id', $pageIds)
            ->orderBy('id')
            ->get()
            ->map(fn (EnterpriseWikiPageLink $link): array => $this->linkPayload($link))
            ->values()
            ->all();

        $items = QualityItem::query()
            ->where('customer_id', $customerId)
            ->orderBy('id')
            ->get()
            ->map(fn (QualityItem $item): array => $this->qualityItemPayload($item))
            ->values()
            ->all();

        $itemIds = collect($items)->pluck('quality_item_id')->all();

        $qualityRelations = QualityItemRelation::query()
            ->where('customer_id', $customerId)
            ->whereIn('from_item_id', $itemIds)
            ->whereIn('to_item_id', $itemIds)
            ->orderBy('id')
            ->get()
            ->map(fn (QualityItemRelation $relation): array => $this->qualityRelationPayload($relation))
            ->values()
            ->all();

        // Both ends must be in the rebuild, or the MERGE would silently match nothing. A link to a
        // page outside this customer cannot exist in SQL, but a page the Wiki query skipped can.
        $qualityWikiLinks = QualityItemWikiLink::query()
            ->where('customer_id', $customerId)
            ->whereIn('quality_item_id', $itemIds)
            ->whereIn('enterprise_wiki_page_id', $pageIds)
            ->orderBy('id')
            ->get()
            ->map(fn (QualityItemWikiLink $link): array => $this->qualityWikiLinkPayload($link))
            ->values()
            ->all();

        $this->projection->replaceCustomerWikiGraph(
            $customerId,
            $pages,
            $links,
            $items,
            $qualityRelations,
            $qualityWikiLinks,
        );
    }

    private function pagePayload(EnterpriseWikiPage $page): array
    {
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
     * A quality item projects as its own node. It carries only what the graph is asked questions
     * about — type, title, number, state, ownership, when it is next due. The purpose text and the
     * authored structure stay in SQL, which is where they are read from.
     *
     * @return array<string, mixed>
     */
    private function qualityItemPayload(QualityItem $item): array
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
     * @return array<string, mixed>
     */
    private function qualityRelationPayload(QualityItemRelation $relation): array
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
    private function qualityWikiLinkPayload(QualityItemWikiLink $link): array
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

    /**
     * @param  list<mixed>  $targetPageIds
     * @return Collection<int, EnterpriseWikiPage>
     */
    private function existingTargetPages(int $customerId, array $targetPageIds): Collection
    {
        $targetPageIds = array_values(array_unique(array_map('intval', $targetPageIds)));

        if ($targetPageIds === []) {
            return EnterpriseWikiPage::query()->whereRaw('1 = 0')->get();
        }

        return EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->whereIn('id', $targetPageIds)
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, EnterpriseWikiPageLink>
     */
    private function outgoingWikilinks(int $customerId, int $fromPageId): Collection
    {
        return $this->wikilinkQuery($customerId)
            ->where('from_page_id', $fromPageId)
            ->orderBy('id')
            ->get();
    }

    private function wikilinkQuery(int $customerId): Builder
    {
        return EnterpriseWikiPageLink::query()
            ->where('customer_id', $customerId)
            ->where('link_type', EnterpriseWikiPageLink::LINK_TYPE_WIKILINK);
    }

    private function linkPayload(EnterpriseWikiPageLink $link): array
    {
        return [
            'link_id' => (int) $link->id,
            'customer_id' => (int) $link->customer_id,
            'from_page_id' => (int) $link->from_page_id,
            'to_page_id' => (int) $link->to_page_id,
            'from_page_version_id' => $link->from_page_version_id !== null ? (int) $link->from_page_version_id : null,
            'to_page_version_id' => $link->to_page_version_id !== null ? (int) $link->to_page_version_id : null,
            'link_type' => $link->link_type,
            'source' => $link->source,
            'confidence' => $link->confidence,
            'metadata' => $link->metadata ?? [],
            'updated_at' => $link->updated_at?->toIso8601String(),
        ];
    }
}
