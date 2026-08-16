<?php

namespace App\Services\EnterpriseWiki;

use App\Exceptions\EnterpriseWikiWithdrawalNotRepresentableException;
use App\Models\EnterpriseWikiCanonicalFact;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Withdraws a document's substance from the ACTIVE Wiki when the document is deleted.
 *
 * Deleting a document used to remove the document, its runs and the pages only it produced — and
 * stop there. Two things were left behind, both of which surfaced as run 63: a SHARED page kept the
 * deleted document's paragraphs (only its source references were cleaned), and a page linking to a
 * deleted page kept the `[[slug|anchor]]` text in its markdown while the graph edge cascaded away.
 * The Wiki then asserted knowledge from a document that no longer existed, and pointed at pages that
 * no longer existed.
 *
 * Both are now deterministic, because provenance is atomic: a source-based block belongs to exactly
 * one document, so withdrawal is a FILTER, never a rewrite. No AI, no text heuristics, no fuzzy slug
 * matching — the block's own source_id decides, and a link is identified by the canonical slug the
 * backend itself wrote.
 *
 * Deliberately narrow (V1):
 *  - CURRENT state only. Historical page versions are audit history and are never rewritten; the
 *    guarantee is "no trace in the active Wiki", not "never existed".
 *  - No repair. If filtering would leave a page that cannot be represented safely — nothing left, or
 *    a version the writer's own invariants reject — the whole deletion fails closed and the page is
 *    named. We measure how often that happens before deciding whether bounded repair is worth it.
 */
class EnterpriseWikiDocumentWithdrawalService
{
    public function __construct(
        private readonly EnterpriseWikiPageVersionWriter $versionWriter,
        private readonly EnterpriseWikiLinkParser $linkParser,
    ) {}

    /**
     * Removes the document's own blocks from every current page that keeps other documents' work,
     * and reports the pages that keep nothing.
     *
     * A shared page with no surviving substance is not kept as an empty shell and does not block the
     * deletion: it is returned as DOOMED, and the caller deletes it with the sole-source pages
     * through the same path. The decision is made on CURRENT state alone — a page that once carried
     * another document's substance but no longer does is, today, a page this document alone holds up.
     * History is audit, not a reason to keep an empty page alive, and it is never restored.
     *
     * "Substance" is source-based prose OR a best-practice clause. Best practice carries no document
     * provenance — it is Procynia's own contribution, routed to human approval — so a page that still
     * holds one is still saying something of its own and survives. Headings and cross-references are
     * not substance: a page reduced to them asserts nothing.
     *
     * @param  Collection<int, int>  $sharedPageIds
     * @return array{pages_rewritten: int, blocks_removed: int, doomed_page_ids: Collection<int, int>}
     *
     * @throws EnterpriseWikiWithdrawalNotRepresentableException
     */
    public function withdrawBlocks(EnterpriseWikiDocument $document, Collection $sharedPageIds): array
    {
        $doomed = collect();

        if ($sharedPageIds->isEmpty()) {
            return ['pages_rewritten' => 0, 'blocks_removed' => 0, 'doomed_page_ids' => $doomed];
        }

        $pagesRewritten = 0;
        $blocksRemoved = 0;

        foreach ($this->currentVersionsFor($sharedPageIds) as $version) {
            $blocks = array_values(array_filter((array) ($version->content_blocks_json ?? []), 'is_array'));

            $kept = array_values(array_filter(
                $blocks,
                fn (array $block): bool => ! $this->belongsToDocument($block, $document),
            ));

            if (count($kept) === count($blocks)) {
                continue;
            }

            if ($this->hasNoSubstanceLeft($kept)) {
                // Nothing of its own left: the page goes with the document rather than being written
                // as an empty shell. No version is written for it — it is about to be deleted.
                $doomed->push((int) $version->enterprise_wiki_page_id);

                Log::info('[WIKI_WITHDRAWAL] Page has no substance left without this document — deleting it with the document.', [
                    'page_id' => $version->enterprise_wiki_page_id,
                    'document_id' => $document->id,
                    'blocks_before' => count($blocks),
                ]);

                continue;
            }

            $blocksRemoved += count($blocks) - count($kept);
            $pagesRewritten++;

            $this->writeVersion($version, $kept, 'block withdrawal');
        }

        return ['pages_rewritten' => $pagesRewritten, 'blocks_removed' => $blocksRemoved, 'doomed_page_ids' => $doomed->unique()->values()];
    }

    /**
     * Turns every current wikilink pointing at a page that is about to be deleted back into ordinary
     * visible text, and drops the structured intent that described it.
     *
     * Targets are found two ways, both exact: the recorded graph edges (`page_links`), and a scan of
     * current block markdown for the canonical slug of a doomed page. The second exists because the
     * edge and the text are separate representations — run 63 is precisely what happens when only one
     * of them is cleaned — and a link whose edge was never recorded must not survive as dead text.
     *
     * @param  Collection<int, int>  $deletedPageIds
     * @return array{pages_rewritten: int, links_dematerialized: int}
     *
     * @throws EnterpriseWikiWithdrawalNotRepresentableException
     */
    public function dematerializeIncomingLinks(Collection $deletedPageIds): array
    {
        if ($deletedPageIds->isEmpty()) {
            return ['pages_rewritten' => 0, 'links_dematerialized' => 0];
        }

        $doomed = EnterpriseWikiPage::query()
            ->whereIn('id', $deletedPageIds)
            ->pluck('slug', 'id');

        if ($doomed->isEmpty()) {
            return ['pages_rewritten' => 0, 'links_dematerialized' => 0];
        }

        $doomedSlugs = array_values(array_filter($doomed->all()));
        $doomedIds = array_map('intval', array_keys($doomed->all()));

        $pagesRewritten = 0;
        $linksRemoved = 0;

        foreach ($this->currentVersionsFor($this->linkingPageIds($doomedIds, $doomedSlugs)) as $version) {
            $blocks = array_values(array_filter((array) ($version->content_blocks_json ?? []), 'is_array'));
            $changed = false;

            foreach ($blocks as $index => $block) {
                [$markdown, $removed] = $this->dematerializeIn((string) ($block['markdown'] ?? ''), $doomedSlugs);

                if ($removed === 0) {
                    continue;
                }

                $blocks[$index]['markdown'] = $markdown;
                $blocks[$index]['link_intents'] = array_values(array_filter(
                    (array) ($block['link_intents'] ?? []),
                    static fn (mixed $intent): bool => ! is_array($intent)
                        || ! in_array((int) ($intent['target_page_id'] ?? 0), $doomedIds, true),
                ));

                $linksRemoved += $removed;
                $changed = true;
            }

            if (! $changed) {
                continue;
            }

            $pagesRewritten++;
            $this->writeVersion($version, $blocks, 'link dematerialization');
        }

        return ['pages_rewritten' => $pagesRewritten, 'links_dematerialized' => $linksRemoved];
    }

    /**
     * The state the active Wiki must be in for the deletion to be allowed to commit.
     *
     * Runs inside the deletion transaction, after everything else: a violation means the Wiki would
     * be left asserting something about a document it no longer has, so the whole deletion is rolled
     * back rather than leaving a half-withdrawn state.
     *
     * @param  list<string>  $deletedPageSlugs  Captured BEFORE the pages were deleted.
     *
     * @throws EnterpriseWikiWithdrawalNotRepresentableException
     */
    public function assertActiveWikiIsClean(int $documentId, int $customerId, Collection $deletedPageIds, array $deletedPageSlugs = []): void
    {
        $violations = [];

        foreach (EnterpriseWikiPageVersion::query()->where('is_current', true)->cursor() as $version) {
            foreach ((array) ($version->content_blocks_json ?? []) as $block) {
                if (! is_array($block)) {
                    continue;
                }

                if ($this->belongsToDocumentId($block, $documentId)) {
                    $violations[] = "page [{$version->enterprise_wiki_page_id}] still carries a block from document [{$documentId}]";

                    break;
                }
            }
        }

        $danglingReference = EnterpriseWikiSourceReference::query()
            ->where('source_type', EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT)
            ->where('source_id', $documentId)
            ->exists();

        if ($danglingReference) {
            $violations[] = "a source reference still cites document [{$documentId}]";
        }

        $danglingFact = EnterpriseWikiCanonicalFact::query()
            ->where('source_type', EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT)
            ->where('source_id', $documentId)
            ->where('customer_id', $customerId)
            ->exists();

        if ($danglingFact) {
            $violations[] = "a canonical fact still cites document [{$documentId}]";
        }

        if ($deletedPageIds->isNotEmpty()) {
            // The slugs are passed in because the pages themselves are gone by the time this runs —
            // reading them from the database here would silently check nothing.
            foreach ($this->currentVersionsFor($this->linkingPageIds($deletedPageIds->map('intval')->all(), $deletedPageSlugs)) as $version) {
                $violations[] = "page [{$version->enterprise_wiki_page_id}] still links to a page this deletion removes";
            }

            if (EnterpriseWikiPageLink::query()->whereIn('to_page_id', $deletedPageIds)->exists()) {
                $violations[] = 'a graph edge still points at a page this deletion removes';
            }
        }

        if ($violations !== []) {
            throw EnterpriseWikiWithdrawalNotRepresentableException::activeWikiNotClean($documentId, $violations);
        }
    }

    /**
     * Pages whose CURRENT version links to one of the doomed pages — by recorded edge or by the
     * canonical slug appearing in block markdown. Exact matching only.
     *
     * @param  list<int>  $doomedIds
     * @param  list<string>  $doomedSlugs
     * @return Collection<int, int>
     */
    private function linkingPageIds(array $doomedIds, array $doomedSlugs): Collection
    {
        $byEdge = EnterpriseWikiPageLink::query()
            ->whereIn('to_page_id', $doomedIds)
            ->whereNotIn('from_page_id', $doomedIds)
            ->distinct()
            ->pluck('from_page_id');

        $byText = collect();

        foreach (EnterpriseWikiPageVersion::query()->where('is_current', true)->cursor() as $version) {
            if (in_array((int) $version->enterprise_wiki_page_id, $doomedIds, true)) {
                continue;
            }

            foreach ($doomedSlugs as $slug) {
                if ($this->markdownLinksTo((string) $version->content_markdown, $slug)) {
                    $byText->push((int) $version->enterprise_wiki_page_id);

                    break;
                }
            }
        }

        return $byEdge->map('intval')->merge($byText)->unique()->values();
    }

    /**
     * @param  list<string>  $doomedSlugs
     * @return array{0: string, 1: int}
     */
    private function dematerializeIn(string $markdown, array $doomedSlugs): array
    {
        $replacements = [];

        foreach ($this->linkParser->parse($markdown) as $link) {
            if (in_array((string) $link['target_slug'], $doomedSlugs, true)) {
                $replacements[(string) $link['original_markup']] = (string) $link['anchor_text'];
            }
        }

        if ($replacements === []) {
            return [$markdown, 0];
        }

        return [strtr($markdown, $replacements), count($replacements)];
    }

    private function markdownLinksTo(string $markdown, string $slug): bool
    {
        return $slug !== ''
            && (str_contains($markdown, '[['.$slug.'|') || str_contains($markdown, '[['.$slug.']]'));
    }

    /**
     * @param  Collection<int, int>  $pageIds
     * @return Collection<int, EnterpriseWikiPageVersion>
     */
    private function currentVersionsFor(Collection $pageIds): Collection
    {
        if ($pageIds->isEmpty()) {
            return collect();
        }

        return EnterpriseWikiPageVersion::query()
            ->whereIn('enterprise_wiki_page_id', $pageIds)
            ->where('is_current', true)
            ->get();
    }

    /** @param array<string, mixed> $block */
    private function belongsToDocument(array $block, EnterpriseWikiDocument $document): bool
    {
        return $this->belongsToDocumentId($block, (int) $document->id);
    }

    /**
     * A block belongs to the document when its own source_id says so. Atomic provenance is what makes
     * this a single, unambiguous check — every source element of a source-based block names the same
     * document, enforced by EnterpriseWikiPageVersionWriter.
     *
     * @param  array<string, mixed>  $block
     */
    private function belongsToDocumentId(array $block, int $documentId): bool
    {
        if (($block['content_origin'] ?? null) !== EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED) {
            return false;
        }

        if (($block['source_type'] ?? null) !== EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT) {
            return false;
        }

        return (int) ($block['source_id'] ?? 0) === $documentId;
    }

    /**
     * Whether anything with substance survives. Headings and cross-references alone are not a page:
     * a `## ` with nothing under it is what EnterpriseWikiPlannedSectionCoverageValidator already
     * treats as a defect, so leaving one behind would be knowingly writing a broken page.
     *
     * @param  list<array<string, mixed>>  $blocks
     */
    private function hasNoSubstanceLeft(array $blocks): bool
    {
        foreach ($blocks as $block) {
            $origin = $block['content_origin'] ?? null;

            if ($origin === EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED
                || $origin === EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     *
     * @throws EnterpriseWikiWithdrawalNotRepresentableException
     */
    private function writeVersion(EnterpriseWikiPageVersion $version, array $blocks, string $operation): void
    {
        $renumbered = [];

        foreach (array_values($blocks) as $index => $block) {
            $block['block_key'] = 'block-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT);
            $block['position'] = $index;
            $renumbered[] = $block;
        }

        $markdown = trim(implode("\n\n", array_map(
            static fn (array $block): string => trim((string) ($block['markdown'] ?? '')),
            $renumbered,
        )));

        try {
            $this->versionWriter->writeNewCurrentVersion((int) $version->enterprise_wiki_page_id, [
                'content_markdown' => $markdown,
                'content_blocks_json' => $renumbered,
                'generated_by_model' => $version->generated_by_model,
            ]);
        } catch (\RuntimeException $e) {
            // The writer's own invariants (block provenance, best-practice review, atomic provenance)
            // are the last word on whether a version may become current. A withdrawal that cannot
            // satisfy them is not a withdrawal we may complete.
            throw EnterpriseWikiWithdrawalNotRepresentableException::writerRejectedVersion(
                (int) $version->enterprise_wiki_page_id,
                $operation,
                $e,
            );
        }

        Log::info('[WIKI_WITHDRAWAL] Current version rewritten.', [
            'page_id' => $version->enterprise_wiki_page_id,
            'operation' => $operation,
            'blocks_before' => count((array) ($version->content_blocks_json ?? [])),
            'blocks_after' => count($renumbered),
        ]);
    }
}
