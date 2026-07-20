<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;

/**
 * Single source of truth for pairing a main article page with its summary page (and vice versa)
 * and for building/detecting the deterministic mutual `[[wikilink]]` between them.
 *
 * A page pair is defined purely by run co-membership (App\Models\EnterpriseWikiIngestRunPage) —
 * there is no separate "concept"/grouping column on enterprise_wiki_pages. A pair is only ever
 * considered UNAMBIGUOUS when a run has exactly one article page and exactly one summary page;
 * anything else (0, or 2+, of either type) is left untouched by every caller of this class, per
 * the product rule that automatic linking must never guess.
 *
 * Used by both EnterpriseWikiGenerateAppliedPagesService (new pages, injected into the very
 * first version so no extra version is ever created) and
 * EnterpriseWikiArticleSummaryLinkRepairService (existing pages, which DOES need a new version
 * since the page already has content). Both converge on the exact same block shape and link text
 * so a page looks identical whether it got its link at generation time or via repair.
 */
class EnterpriseWikiArticleSummaryLinkService
{
    /**
     * Stable block key for the injected "see also" block — lets a repair pass recognize its own
     * previously-injected block by key, though the authoritative idempotency check is always
     * hasLinkToPage() (a real markdown-level wikilink scan), never this key alone, so a user who
     * deletes or edits this block is never silently overwritten back to it.
     */
    public const BLOCK_KEY = 'article-summary-link';

    public function __construct(
        private readonly EnterpriseWikiLinkParser $linkParser,
        private readonly EnterpriseWikiLinkResolver $linkResolver,
    ) {}

    /**
     * The run's other article/summary page paired with $page, or null when the pairing is
     * ambiguous (not exactly one page of the opposite type in this run) or $page is not itself
     * an article/summary page.
     */
    public function findPairedPage(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $page): ?EnterpriseWikiPage
    {
        $oppositeType = match ($page->page_type) {
            EnterpriseWikiPage::PAGE_TYPE_ARTICLE => EnterpriseWikiPage::PAGE_TYPE_SUMMARY,
            EnterpriseWikiPage::PAGE_TYPE_SUMMARY => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            default => null,
        };

        if ($oppositeType === null) {
            return null;
        }

        $candidates = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->whereHas('page', fn ($query) => $query->where('page_type', $oppositeType))
            ->with('page')
            ->get()
            ->pluck('page')
            ->filter()
            ->values();

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    /**
     * Whether $markdown already contains a resolvable [[wikilink]] to $targetPage — the
     * authoritative "is this pair already linked" check, reused identically by generation (skip
     * injection if the freshly-generated content already happens to link the pair) and repair
     * (skip — and therefore never create a new page version — when the user already wrote their
     * own link to the same target, regardless of anchor text or position).
     */
    public function hasLinkToPage(EnterpriseWikiPage $sourcePage, string $markdown, EnterpriseWikiPage $targetPage): bool
    {
        if (trim($markdown) === '') {
            return false;
        }

        $parsed = $this->linkParser->parse($markdown);

        if ($parsed === []) {
            return false;
        }

        $resolution = $this->linkResolver->resolve($sourcePage->customer_id, $sourcePage, $parsed);

        foreach ($resolution['resolved'] as $target) {
            if ((int) $target['to_page']->id === (int) $targetPage->id) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{block_key: string, position: int, markdown: string}
     */
    public function buildLinkBlock(EnterpriseWikiPage $targetPage, int $position, string $languageCode): array
    {
        return [
            'block_key' => self::BLOCK_KEY,
            'position' => $position,
            'markdown' => sprintf('**%s:** [[%s|%s]]', $this->seeAlsoLabel($languageCode), $targetPage->slug, $targetPage->title),
        ];
    }

    private function seeAlsoLabel(string $languageCode): string
    {
        return $languageCode === 'no' ? 'Se også' : 'See also';
    }
}
