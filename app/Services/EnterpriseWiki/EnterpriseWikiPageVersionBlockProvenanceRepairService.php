<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPageVersion;

/**
 * Reconstructs content_blocks_json for a page's current version when it is empty but an earlier
 * version of the same page has real blocks with source provenance (source_elements) — the exact
 * drift left by EnterpriseWikiLinkSemanticRepairService::writeNewCurrentVersion(), which only
 * ever sets content_markdown, never content_blocks_json.
 *
 * Reconstruction only ever happens when every one of the prior version's blocks maps to exactly
 * one segment of the current version's content_markdown, and vice versa (a clean bijection) —
 * matched by comparing each side's VISIBLE text (wikilink markup resolved to its anchor text, so
 * a link that moved within an otherwise-unchanged sentence still matches). Any page where the
 * segment/block counts differ, a block matches zero or multiple segments, or two blocks/segments
 * normalize identically, is left completely untouched — no partial or best-guess reconstruction.
 *
 * Never edits content_markdown and never creates a new page version: this only backfills
 * content_blocks_json metadata for the EXISTING current version row, whose visible text is
 * byte-for-byte unchanged.
 *
 * Once a page's blocks are reconstructed, claims on that page whose content_block_key is empty
 * are re-linked to whichever reconstructed block's text uniquely contains the claim's anchor
 * (page_excerpt, falling back to claim_text) — again, only when exactly one block matches. This
 * does not itself create EnterpriseWikiSourceReference rows or re-verify anything — it only
 * restores the block anchor a subsequent, separately-triggered re-verification needs to compare
 * a claim against the actual excerpt(s) that support it, instead of falling back to unrelated
 * text (see EnterpriseWikiVerifyPageClaimsService::applyDeterministicSafetyNet()).
 */
class EnterpriseWikiPageVersionBlockProvenanceRepairService
{
    public function __construct(
        private readonly EnterpriseWikiClaimAnchorTextNormalizer $textNormalizer,
    ) {}

    /**
     * @return array{
     *     page_versions_checked: int,
     *     page_versions_repaired: int,
     *     page_versions_skipped_already_has_blocks: int,
     *     page_versions_skipped_no_prior_blocks: int,
     *     page_versions_skipped_ambiguous: int,
     *     claims_checked: int,
     *     claims_linked: int,
     *     claims_already_linked: int,
     *     claims_ambiguous: int,
     *     ambiguous_page_ids: list<int>,
     * }
     */
    public function repair(?EnterpriseWikiIngestRun $onlyRun, bool $apply): array
    {
        $result = [
            'page_versions_checked' => 0,
            'page_versions_repaired' => 0,
            'page_versions_skipped_already_has_blocks' => 0,
            'page_versions_skipped_no_prior_blocks' => 0,
            'page_versions_skipped_ambiguous' => 0,
            'claims_checked' => 0,
            'claims_linked' => 0,
            'claims_already_linked' => 0,
            'claims_ambiguous' => 0,
            'ambiguous_page_ids' => [],
        ];

        $query = EnterpriseWikiIngestRun::query()
            ->where('maintainer_decision_status', EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED);

        if ($onlyRun !== null) {
            $query->where('id', $onlyRun->id);
        }

        $query->orderBy('id')->chunkById(50, function ($runs) use (&$result, $apply): void {
            foreach ($runs as $run) {
                $this->repairRun($run, $apply, $result);
            }
        });

        return $result;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function repairRun(EnterpriseWikiIngestRun $run, bool $apply, array &$result): void
    {
        $pivotRows = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->with('page.currentVersion')
            ->get();

        foreach ($pivotRows as $row) {
            $page = $row->page;
            $current = $page?->currentVersion;

            if ($page === null || $current === null) {
                continue;
            }

            $result['page_versions_checked']++;

            if (! empty($current->content_blocks_json)) {
                $result['page_versions_skipped_already_has_blocks']++;

                continue;
            }

            $prior = EnterpriseWikiPageVersion::query()
                ->where('enterprise_wiki_page_id', $page->id)
                ->where('version_number', '<', $current->version_number)
                ->whereNotNull('content_blocks_json')
                ->orderByDesc('version_number')
                ->get()
                ->first(fn (EnterpriseWikiPageVersion $v): bool => ! empty($v->content_blocks_json));

            if ($prior === null) {
                $result['page_versions_skipped_no_prior_blocks']++;

                continue;
            }

            $newBlocks = $this->reconstructBlocks($prior, $current);

            if ($newBlocks === null) {
                $result['page_versions_skipped_ambiguous']++;
                $result['ambiguous_page_ids'][] = $page->id;

                continue;
            }

            $result['page_versions_repaired']++;

            if ($apply) {
                $current->update(['content_blocks_json' => $newBlocks]);
            }

            $this->linkClaims($current, $newBlocks, $apply, $result);
        }
    }

    /**
     * @return list<array<string, mixed>>|null null when the mapping is ambiguous in any way
     */
    private function reconstructBlocks(EnterpriseWikiPageVersion $prior, EnterpriseWikiPageVersion $current): ?array
    {
        $oldBlocks = array_values(array_filter((array) $prior->content_blocks_json, 'is_array'));
        $segments = array_values(array_filter(
            array_map('trim', explode("\n\n", trim((string) $current->content_markdown))),
            fn (string $s): bool => $s !== '',
        ));

        if ($oldBlocks === [] || count($segments) !== count($oldBlocks)) {
            return null;
        }

        $oldNormalized = array_map(
            fn (array $b): string => $this->textNormalizer->normalize((string) ($b['markdown'] ?? '')),
            $oldBlocks,
        );
        $segmentNormalized = array_map(
            fn (string $s): string => $this->textNormalizer->normalize($s),
            $segments,
        );

        // Bijection required: every old block's normalized text must appear EXACTLY once among
        // the segments, and vice versa. A duplicate normalized value on either side, or a block
        // with zero matches, makes the assignment guesswork — refuse the whole page entirely
        // rather than guess at a partial mapping.
        if (count(array_unique($oldNormalized)) !== count($oldNormalized)
            || count(array_unique($segmentNormalized)) !== count($segmentNormalized)
        ) {
            return null;
        }

        $segmentIndexByNormalized = array_flip($segmentNormalized);
        $newBlocks = [];

        foreach ($oldBlocks as $i => $block) {
            $normalized = $oldNormalized[$i];

            if (! isset($segmentIndexByNormalized[$normalized])) {
                return null;
            }

            $segmentIndex = $segmentIndexByNormalized[$normalized];

            // Keep every field from the old block (source_elements, content_origin, etc.) — only
            // the markdown (now reflecting whatever the current version actually says, e.g. a
            // moved wikilink) and position are updated.
            $newBlocks[$segmentIndex] = array_merge($block, [
                'markdown' => $segments[$segmentIndex],
                'position' => $segmentIndex,
            ]);
        }

        ksort($newBlocks);

        return array_values($newBlocks);
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @param  array<string, mixed>  $result
     */
    private function linkClaims(EnterpriseWikiPageVersion $version, array $blocks, bool $apply, array &$result): void
    {
        $claims = EnterpriseWikiClaim::query()
            ->where('enterprise_wiki_page_version_id', $version->id)
            ->get();

        foreach ($claims as $claim) {
            $result['claims_checked']++;

            if (trim((string) $claim->content_block_key) !== '') {
                $result['claims_already_linked']++;

                continue;
            }

            $anchor = trim((string) ($claim->page_excerpt ?: $claim->claim_text));

            if ($anchor === '') {
                $result['claims_ambiguous']++;

                continue;
            }

            $matches = array_values(array_filter(
                $blocks,
                fn (array $b): bool => $this->textNormalizer->contains((string) ($b['markdown'] ?? ''), $anchor),
            ));

            if (count($matches) !== 1) {
                $result['claims_ambiguous']++;

                continue;
            }

            $result['claims_linked']++;

            if ($apply) {
                $claim->update(['content_block_key' => (string) $matches[0]['block_key']]);
            }
        }
    }
}
