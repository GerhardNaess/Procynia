<?php

namespace App\Services\EnterpriseWiki;

use App\Exceptions\EnterpriseWikiOrphanConceptLinkException;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiLintFinding;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Resolves an open `orphan_concept_page` lint finding by creating a real outgoing canonical
 * [[wikilink]] from a `concept` page to an existing `article`/`summary` page the maintainer
 * picks from a list of documentably-related candidates.
 *
 * Deliberately not an AI recommendation engine (product requirement): every candidate is backed
 * by at least one already-materialized signal — an existing EnterpriseWikiPageLink row (incoming
 * canonical wikilink, or structural article/summary_to_concept pairing), a plain-text mention of
 * the concept's title in the candidate's current markdown (the same deterministic ILIKE pattern
 * EnterpriseWikiIncrementalRelinkService::findCandidates() already uses, just in the reverse
 * direction), or a shared EnterpriseWikiClaim.canonical_fact_id between the two pages' current
 * versions. A page with none of these signals is never returned.
 *
 * Link creation reuses the exact "new immutable version" pattern
 * EnterpriseWikiArticleSummaryLinkRepairService::appendLinkVersion() established: content_markdown
 * and content_blocks_json are always derived from the same blocks array (never independently
 * drifting), the previous current version is demoted inside the same transaction, and
 * EnterpriseWikiBuildPageLinksService::materializeWikilinksForPage() is the sole mechanism that
 * turns the new markdown into a materialized link_type=wikilink row — this service never writes
 * an EnterpriseWikiPageLink row directly.
 */
class EnterpriseWikiOrphanConceptLinkService
{
    private const MAX_CANDIDATES = 10;

    public const REASON_INCOMING_WIKILINK = 'incoming_wikilink';

    public const REASON_STRUCTURAL_PAIRING = 'structural_pairing';

    public const REASON_MENTIONS_TITLE = 'mentions_title';

    public const REASON_SHARED_CANONICAL_FACT = 'shared_canonical_fact';

    public function __construct(
        private readonly EnterpriseWikiLinkParser $linkParser,
        private readonly EnterpriseWikiLinkResolver $linkResolver,
        private readonly EnterpriseWikiBuildPageLinksService $buildPageLinksService,
        private readonly EnterpriseWikiDocumentWikiAnswerStalenessService $wikiAnswerStalenessService,
        private readonly EnterpriseWikiPageVersionClaimSyncService $claimSyncService,
        private readonly EnterpriseWikiAppliedRunLintService $lintService,
        private readonly EnterpriseWikiPageVersionWriter $versionWriter,
    ) {}

    /**
     * @return list<array{page_id: int, title: string, slug: string, page_type: string, reasons: list<string>}>
     */
    public function findCandidates(EnterpriseWikiPage $conceptPage): array
    {
        if ($conceptPage->page_type !== EnterpriseWikiPage::PAGE_TYPE_CONCEPT) {
            return [];
        }

        /** @var array<int, list<string>> $reasonsByPageId */
        $reasonsByPageId = [];

        $this->collectIncomingWikilinkSignal($conceptPage, $reasonsByPageId);
        $this->collectStructuralPairingSignal($conceptPage, $reasonsByPageId);
        $this->collectTitleMentionSignal($conceptPage, $reasonsByPageId);
        $this->collectSharedCanonicalFactSignal($conceptPage, $reasonsByPageId);

        if ($reasonsByPageId === []) {
            return [];
        }

        $pages = EnterpriseWikiPage::query()
            ->where('customer_id', $conceptPage->customer_id)
            ->whereIn('id', array_keys($reasonsByPageId))
            ->whereIn('page_type', [EnterpriseWikiPage::PAGE_TYPE_ARTICLE, EnterpriseWikiPage::PAGE_TYPE_SUMMARY])
            ->whereHas('currentVersion')
            ->orderBy('title')
            ->limit(self::MAX_CANDIDATES)
            ->get();

        return $pages->map(fn (EnterpriseWikiPage $page): array => [
            'page_id' => $page->id,
            'title' => $page->title,
            'slug' => $page->slug,
            'page_type' => $page->page_type,
            'reasons' => array_values(array_unique($reasonsByPageId[$page->id] ?? [])),
        ])->values()->all();
    }

    /**
     * @param  array<int, list<string>>  $reasonsByPageId
     */
    private function collectIncomingWikilinkSignal(EnterpriseWikiPage $conceptPage, array &$reasonsByPageId): void
    {
        $fromPageIds = EnterpriseWikiPageLink::query()
            ->where('customer_id', $conceptPage->customer_id)
            ->where('to_page_id', $conceptPage->id)
            ->where('link_type', EnterpriseWikiPageLink::LINK_TYPE_WIKILINK)
            ->pluck('from_page_id');

        $this->addReason($reasonsByPageId, $fromPageIds, self::REASON_INCOMING_WIKILINK);
    }

    /**
     * @param  array<int, list<string>>  $reasonsByPageId
     */
    private function collectStructuralPairingSignal(EnterpriseWikiPage $conceptPage, array &$reasonsByPageId): void
    {
        $fromPageIds = EnterpriseWikiPageLink::query()
            ->where('customer_id', $conceptPage->customer_id)
            ->where('to_page_id', $conceptPage->id)
            ->whereIn('link_type', [
                EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_CONCEPT,
                EnterpriseWikiPageLink::LINK_TYPE_SUMMARY_TO_CONCEPT,
            ])
            ->pluck('from_page_id');

        $this->addReason($reasonsByPageId, $fromPageIds, self::REASON_STRUCTURAL_PAIRING);
    }

    /**
     * @param  array<int, list<string>>  $reasonsByPageId
     */
    private function collectTitleMentionSignal(EnterpriseWikiPage $conceptPage, array &$reasonsByPageId): void
    {
        $title = trim($conceptPage->title);

        if ($title === '') {
            return;
        }

        $pageIds = EnterpriseWikiPageVersion::query()
            ->where('is_current', true)
            ->where('content_markdown', 'ilike', '%'.addcslashes($title, '%_\\').'%')
            ->whereHas('page', fn ($query) => $query
                ->where('customer_id', $conceptPage->customer_id)
                ->whereIn('page_type', [EnterpriseWikiPage::PAGE_TYPE_ARTICLE, EnterpriseWikiPage::PAGE_TYPE_SUMMARY])
            )
            ->pluck('enterprise_wiki_page_id');

        $this->addReason($reasonsByPageId, $pageIds, self::REASON_MENTIONS_TITLE);
    }

    /**
     * @param  array<int, list<string>>  $reasonsByPageId
     */
    private function collectSharedCanonicalFactSignal(EnterpriseWikiPage $conceptPage, array &$reasonsByPageId): void
    {
        $conceptVersion = $conceptPage->currentVersion()->first();

        if ($conceptVersion === null) {
            return;
        }

        $canonicalFactIds = EnterpriseWikiClaim::query()
            ->where('enterprise_wiki_page_version_id', $conceptVersion->id)
            ->whereNotNull('canonical_fact_id')
            ->pluck('canonical_fact_id')
            ->unique();

        if ($canonicalFactIds->isEmpty()) {
            return;
        }

        $pageIds = EnterpriseWikiClaim::query()
            ->whereIn('canonical_fact_id', $canonicalFactIds)
            ->where('enterprise_wiki_page_id', '!=', $conceptPage->id)
            ->whereHas('version', fn ($query) => $query->where('is_current', true))
            ->pluck('enterprise_wiki_page_id');

        $this->addReason($reasonsByPageId, $pageIds, self::REASON_SHARED_CANONICAL_FACT);
    }

    /**
     * @param  array<int, list<string>>  $reasonsByPageId
     * @param  Collection<int, int>  $pageIds
     */
    private function addReason(array &$reasonsByPageId, $pageIds, string $reason): void
    {
        foreach ($pageIds->unique() as $pageId) {
            $reasonsByPageId[(int) $pageId][] = $reason;
        }
    }

    /**
     * @return array{
     *     already_linked: bool,
     *     new_page_version_id: ?int,
     *     placement: ?string,
     *     resolved_finding: bool,
     * }
     *
     * @throws EnterpriseWikiOrphanConceptLinkException
     */
    public function linkConceptToTarget(
        EnterpriseWikiPage $conceptPage,
        int $targetPageId,
        int $expectedCurrentVersionId,
        User $actor,
    ): array {
        if (! $actor->is_active || ! $actor->canAccessCustomerFrontend() || ! $actor->canApproveWikiClaims()) {
            throw EnterpriseWikiOrphanConceptLinkException::unauthorized();
        }

        $targetPage = EnterpriseWikiPage::query()
            ->where('customer_id', $conceptPage->customer_id)
            ->find($targetPageId);

        if ($targetPage === null) {
            throw EnterpriseWikiOrphanConceptLinkException::targetNotFound($targetPageId);
        }

        if (! in_array($targetPage->page_type, [EnterpriseWikiPage::PAGE_TYPE_ARTICLE, EnterpriseWikiPage::PAGE_TYPE_SUMMARY], true)) {
            throw EnterpriseWikiOrphanConceptLinkException::invalidTargetType($targetPage->page_type);
        }

        $currentVersion = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $conceptPage->id)
            ->where('is_current', true)
            ->first();

        if ($currentVersion === null || (int) $currentVersion->id !== $expectedCurrentVersionId) {
            throw EnterpriseWikiOrphanConceptLinkException::staleVersion();
        }

        if ($this->hasLinkToPage($conceptPage, (string) $currentVersion->content_markdown, $targetPage)) {
            return [
                'already_linked' => true,
                'new_page_version_id' => null,
                'placement' => null,
                'resolved_finding' => false,
            ];
        }

        $blocks = $this->blocksWithLegacyFallback($currentVersion);
        [$blocks, $placement] = $this->placeLink($blocks, $targetPage);

        $markdown = trim(implode("\n\n", array_map(
            static fn (array $block): string => (string) ($block['markdown'] ?? ''),
            $blocks,
        )));

        $this->versionWriter->writeNewCurrentVersion($conceptPage, [
            'content_markdown' => $markdown,
            'content_blocks_json' => $blocks,
            'generated_by_model' => 'deterministic/orphan-concept-link',
        ]);

        $this->wikiAnswerStalenessService->markAnswersStaleForWikiPageChange($conceptPage->id);

        $this->buildPageLinksService->materializeWikilinksForPage($conceptPage->fresh());

        $affectedRunIds = $this->claimSyncService->markPageForResync($conceptPage);
        $this->claimSyncService->syncRuns($affectedRunIds);

        $resolvedFinding = $this->relintAffectedRuns($affectedRunIds, $conceptPage);

        $newVersion = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $conceptPage->id)
            ->where('is_current', true)
            ->first();

        return [
            'already_linked' => false,
            'new_page_version_id' => $newVersion?->id,
            'placement' => $placement,
            'resolved_finding' => $resolvedFinding,
        ];
    }

    /**
     * @param  list<int>  $affectedRunIds
     */
    private function relintAffectedRuns(array $affectedRunIds, EnterpriseWikiPage $conceptPage): bool
    {
        foreach (array_unique($affectedRunIds) as $runId) {
            $run = EnterpriseWikiIngestRun::query()->find($runId);

            if ($run === null || $run->maintainer_decision_status !== EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED) {
                continue;
            }

            try {
                $this->lintService->lint($run);
            } catch (\Throwable $e) {
                Log::error('[WIKI_ORPHAN_CONCEPT_LINK] Re-lint failed after linking concept page.', [
                    'run_id' => $run->id,
                    'page_id' => $conceptPage->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $stillOpen = EnterpriseWikiLintFinding::query()
            ->where('enterprise_wiki_page_id', $conceptPage->id)
            ->where('code', EnterpriseWikiLintFinding::CODE_ORPHAN_CONCEPT_PAGE)
            ->where('status', EnterpriseWikiLintFinding::STATUS_OPEN)
            ->exists();

        return ! $stillOpen;
    }

    private function hasLinkToPage(EnterpriseWikiPage $sourcePage, string $markdown, EnterpriseWikiPage $targetPage): bool
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
     * @return list<array{block_key: string, position: int, markdown: string}>
     */
    private function blocksWithLegacyFallback(EnterpriseWikiPageVersion $version): array
    {
        $blocks = (array) ($version->content_blocks_json ?? []);

        if ($blocks === []) {
            $blocks[] = [
                'block_key' => 'legacy-content',
                'position' => 0,
                'markdown' => (string) $version->content_markdown,
            ];
        }

        return $blocks;
    }

    /**
     * Places the new link the safest way available, in order of preference:
     *   1. Rewrite a single unambiguous plain-text mention of the target's title (found in
     *      exactly one block, exactly once) into a [[slug|matched text]] wikilink — the block's
     *      key and every other block are untouched.
     *   2. Otherwise, append one new dedicated block (never rewriting or removing any existing
     *      block), mirroring EnterpriseWikiArticleSummaryLinkService::buildLinkBlock().
     *
     * Never guesses when placement is ambiguous (0 or 2+ mentions).
     *
     * @param  list<array{block_key: string, position: int, markdown: string}>  $blocks
     * @return array{0: list<array{block_key: string, position: int, markdown: string}>, 1: string}
     */
    private function placeLink(array $blocks, EnterpriseWikiPage $targetPage): array
    {
        $title = trim($targetPage->title);
        $matchBlockIndex = null;
        $matchOffset = null;
        $matchText = null;
        $totalOccurrences = 0;

        if ($title !== '') {
            foreach ($blocks as $index => $block) {
                $occurrences = $this->unlinkedTitleOccurrences((string) ($block['markdown'] ?? ''), $title);
                $totalOccurrences += count($occurrences);

                if (count($occurrences) === 1 && $matchBlockIndex === null) {
                    $matchBlockIndex = $index;
                    $matchOffset = $occurrences[0]['offset'];
                    $matchText = $occurrences[0]['text'];
                }
            }
        }

        if ($totalOccurrences === 1 && $matchBlockIndex !== null) {
            $blockMarkdown = (string) ($blocks[$matchBlockIndex]['markdown'] ?? '');
            $replacement = '[['.$targetPage->slug.'|'.$matchText.']]';
            $blocks[$matchBlockIndex]['markdown'] = substr_replace(
                $blockMarkdown,
                $replacement,
                $matchOffset,
                strlen($matchText),
            );

            return [$blocks, 'auto_embedded'];
        }

        $blocks[] = [
            'block_key' => 'concept-related-link-'.$targetPage->id,
            'position' => count($blocks),
            'markdown' => sprintf('**%s:** [[%s|%s]]', 'Relatert side', $targetPage->slug, $targetPage->title),
        ];

        return [$blocks, 'appended_block'];
    }

    /**
     * @return list<array{offset: int, text: string}>
     */
    private function unlinkedTitleOccurrences(string $blockMarkdown, string $title): array
    {
        $wikilinkSpans = [];

        if (preg_match_all('/\[\[[^\[\]]*\]\]/u', $blockMarkdown, $linkMatches, PREG_OFFSET_CAPTURE)) {
            foreach ($linkMatches[0] as $span) {
                $wikilinkSpans[] = [$span[1], $span[1] + strlen($span[0])];
            }
        }

        $pattern = '/(?<![\p{L}\p{N}_])'.preg_quote($title, '/').'(?![\p{L}\p{N}_])/ui';
        $occurrences = [];

        if (preg_match_all($pattern, $blockMarkdown, $titleMatches, PREG_OFFSET_CAPTURE)) {
            foreach ($titleMatches[0] as $occurrence) {
                [$matchedText, $offset] = $occurrence;
                $withinExistingLink = false;

                foreach ($wikilinkSpans as [$start, $end]) {
                    if ($offset >= $start && $offset < $end) {
                        $withinExistingLink = true;

                        break;
                    }
                }

                if (! $withinExistingLink) {
                    $occurrences[] = ['offset' => $offset, 'text' => $matchedText];
                }
            }
        }

        return $occurrences;
    }
}
