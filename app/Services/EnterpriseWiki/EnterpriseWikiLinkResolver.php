<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiPage;

/**
 * Resolves parsed wikilink occurrences (see EnterpriseWikiLinkParser) against a
 * customer's EnterpriseWikiPage catalog.
 *
 * Classifies every occurrence as valid, broken (unknown slug), or a self-link, and
 * deduplicates valid occurrences down to one logical relation per target page —
 * multiple inline mentions of the same slug in one page's markdown resolve to a
 * single result entry, matching the one-row-per-(from,to,link_type) materialization
 * this feeds into.
 *
 * Resolution is always scoped to a single customer_id: a slug belonging to another
 * customer is indistinguishable from an unknown slug and is classified as broken.
 */
class EnterpriseWikiLinkResolver
{
    /**
     * @param  list<array{target_slug: string, anchor_text: string, original_markup: string, occurrence_order: int}>  $parsedLinks
     * @return array{
     *     resolved: list<array{to_page: EnterpriseWikiPage, anchor_text: string}>,
     *     broken_slugs: list<string>,
     *     self_link_slugs: list<string>,
     *     occurrences_found: int,
     * }
     */
    public function resolve(int $customerId, EnterpriseWikiPage $sourcePage, array $parsedLinks): array
    {
        if (empty($parsedLinks)) {
            return [
                'resolved' => [],
                'broken_slugs' => [],
                'self_link_slugs' => [],
                'occurrences_found' => 0,
            ];
        }

        $uniqueSlugs = array_values(array_unique(array_column($parsedLinks, 'target_slug')));

        $pagesBySlug = EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->whereIn('slug', $uniqueSlugs)
            ->get()
            ->keyBy('slug');

        $resolvedByTargetPageId = [];
        $brokenSlugs = [];
        $selfLinkSlugs = [];

        foreach ($parsedLinks as $link) {
            $targetSlug = $link['target_slug'];

            /** @var EnterpriseWikiPage|null $targetPage */
            $targetPage = $pagesBySlug->get($targetSlug);

            if ($targetPage === null) {
                $brokenSlugs[] = $targetSlug;

                continue;
            }

            if ($targetPage->id === $sourcePage->id) {
                $selfLinkSlugs[] = $targetSlug;

                continue;
            }

            // First occurrence of a given target wins the anchor text used for materialization.
            $resolvedByTargetPageId[$targetPage->id] ??= [
                'to_page' => $targetPage,
                'anchor_text' => $link['anchor_text'],
            ];
        }

        return [
            'resolved' => array_values($resolvedByTargetPageId),
            'broken_slugs' => $brokenSlugs,
            'self_link_slugs' => $selfLinkSlugs,
            'occurrences_found' => count($parsedLinks),
        ];
    }
}
