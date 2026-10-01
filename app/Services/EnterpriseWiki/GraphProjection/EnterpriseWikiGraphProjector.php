<?php

namespace App\Services\EnterpriseWiki\GraphProjection;

use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\QualityRelation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class EnterpriseWikiGraphProjector
{
    public function __construct(
        private readonly GraphProjectionService $projection,
    ) {}

    public function projectPage(int $pageId): void
    {
        $page = EnterpriseWikiPage::query()->with('qualityClassification')->find($pageId);

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
     * Project the quality layer for one page: its classification onto the node, and the quality
     * edges that leave it.
     *
     * Separate entry point from projectPage() because the two are triggered by different things —
     * the Wiki pipeline moves pages and links, Kvalitet moves classifications and relations — and
     * neither should have to rebuild the other's half to touch its own.
     */
    public function projectPageQuality(int $pageId): void
    {
        $page = EnterpriseWikiPage::query()->with('qualityClassification')->find($pageId);

        if (! $page instanceof EnterpriseWikiPage) {
            return;
        }

        $customerId = (int) $page->customer_id;
        $pageId = (int) $page->id;

        $this->projection->upsertWikiPage($this->pagePayload($page));

        $relations = QualityRelation::query()
            ->where('customer_id', $customerId)
            ->where('from_page_id', $pageId)
            ->orderBy('id')
            ->get();

        // Same reason as projectPage(): the graph writer matches the target instead of creating it,
        // so an edge to a page that has not been projected yet would vanish without a trace.
        $projectableTargetIds = [];

        foreach ($this->existingTargetPages($customerId, $relations->pluck('to_page_id')->all()) as $target) {
            $projectableTargetIds[(int) $target->id] = true;

            if ((int) $target->id !== $pageId) {
                $this->projection->upsertWikiPage($this->pagePayload($target));
            }
        }

        $payloads = $relations
            ->filter(fn (QualityRelation $relation): bool => isset($projectableTargetIds[(int) $relation->to_page_id]))
            ->map(fn (QualityRelation $relation): array => $this->qualityRelationPayload($relation))
            ->values()
            ->all();

        // Always called, including with an empty list: this is what removes stale edges.
        $this->projection->replaceOutgoingQualityRelations($customerId, $pageId, $payloads);
    }

    public function rebuildCustomer(int $customerId): void
    {
        $pages = EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->with('qualityClassification')
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

        // Rebuilt together with the pages and wikilinks, not alongside them: replaceCustomerWikiGraph
        // deletes the customer's nodes first, so quality edges left out here would be dropped by a
        // routine Wiki rebuild and never come back.
        $qualityRelations = QualityRelation::query()
            ->where('customer_id', $customerId)
            ->whereIn('from_page_id', $pageIds)
            ->whereIn('to_page_id', $pageIds)
            ->orderBy('id')
            ->get()
            ->map(fn (QualityRelation $relation): array => $this->qualityRelationPayload($relation))
            ->values()
            ->all();

        $this->projection->replaceCustomerWikiGraph($customerId, $pages, $links, $qualityRelations);
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
            // Null for every page Kvalitet has not classified, which is most of them. The graph
            // writer reads it to decide whether the node carries the :QualityItem label, so it must
            // be present in the payload even when it is null — a missing key would leave a stale
            // label behind after an unclassify.
            'quality_type' => $page->qualityClassification?->quality_type,
            'current_version_id' => $currentVersionId !== null ? (int) $currentVersionId : null,
            'updated_at' => $page->updated_at?->toIso8601String(),
        ];
    }

    private function qualityRelationPayload(QualityRelation $relation): array
    {
        return [
            'relation_id' => (int) $relation->id,
            'customer_id' => (int) $relation->customer_id,
            'from_page_id' => (int) $relation->from_page_id,
            'to_page_id' => (int) $relation->to_page_id,
            'relation_type' => $relation->relation_type,
            'source' => $relation->source,
            'updated_at' => $relation->updated_at?->toIso8601String(),
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
            // pagePayload() reads it for every node it writes, so eager-load rather than pay one
            // lazy query per target page.
            ->with('qualityClassification')
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
