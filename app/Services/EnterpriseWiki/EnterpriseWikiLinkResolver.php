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
    public const STATUS_VALID = 'valid';

    public const STATUS_BROKEN = 'broken';

    public const STATUS_SELF_LINK = 'self_link';

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
        $occurrences = $this->resolveOccurrences($customerId, $sourcePage, $parsedLinks);

        $resolvedByTargetPageId = [];
        $brokenSlugs = [];
        $selfLinkSlugs = [];

        foreach ($occurrences as $occurrence) {
            $targetSlug = $occurrence['link']['target_slug'];

            match ($occurrence['status']) {
                self::STATUS_BROKEN => $brokenSlugs[] = $targetSlug,
                self::STATUS_SELF_LINK => $selfLinkSlugs[] = $targetSlug,
                // First occurrence of a given target wins the anchor text used for materialization.
                self::STATUS_VALID => $resolvedByTargetPageId[$occurrence['target_page']->id] ??= [
                    'to_page' => $occurrence['target_page'],
                    'anchor_text' => $occurrence['link']['anchor_text'],
                ],
            };
        }

        return [
            'resolved' => array_values($resolvedByTargetPageId),
            'broken_slugs' => $brokenSlugs,
            'self_link_slugs' => $selfLinkSlugs,
            'occurrences_found' => count($parsedLinks),
        ];
    }

    /**
     * Classify every parsed occurrence individually, preserving order and duplicates —
     * unlike resolve(), nothing is deduplicated here. Used by callers that need to act on
     * each literal occurrence in the text (e.g. render-only link transformation), rather
     * than the one-row-per-target logical relation resolve() produces for materialization.
     *
     * @param  list<array{target_slug: string, anchor_text: string, original_markup: string, occurrence_order: int}>  $parsedLinks
     * @return list<array{link: array, status: string, target_page: ?EnterpriseWikiPage}>
     */
    public function resolveOccurrences(int $customerId, EnterpriseWikiPage $sourcePage, array $parsedLinks): array
    {
        if (empty($parsedLinks)) {
            return [];
        }

        $pagesBySlug = $this->fetchPagesBySlug($customerId, array_column($parsedLinks, 'target_slug'));

        return array_map(function (array $link) use ($sourcePage, $pagesBySlug): array {
            /** @var EnterpriseWikiPage|null $targetPage */
            $targetPage = $pagesBySlug->get($link['target_slug']);

            if ($targetPage === null) {
                return ['link' => $link, 'status' => self::STATUS_BROKEN, 'target_page' => null];
            }

            if ($targetPage->id === $sourcePage->id) {
                return ['link' => $link, 'status' => self::STATUS_SELF_LINK, 'target_page' => null];
            }

            return ['link' => $link, 'status' => self::STATUS_VALID, 'target_page' => $targetPage];
        }, $parsedLinks);
    }

    private function fetchPagesBySlug(int $customerId, array $slugs): \Illuminate\Support\Collection
    {
        $uniqueSlugs = array_values(array_unique($slugs));

        return EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->whereIn('slug', $uniqueSlugs)
            ->get()
            ->keyBy('slug');
    }
}
