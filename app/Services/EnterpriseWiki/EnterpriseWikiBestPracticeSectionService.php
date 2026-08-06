<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiPageVersion;

/**
 * Single source of truth for grouping a page version's best_practice content blocks into
 * meaningful QA-sized sections — a heading block plus every immediately-following best_practice
 * block, up to (not including) the next heading of the same or a shallower level.
 *
 * Why this exists: WikiPageContentAiClient/EnterpriseWikiPageContentBlockService already split
 * generated content into one block per heading and one block per paragraph (see
 * buildBlocksFromStructuredResult()) — a real structural split, not a bug — but that leaves each
 * heading and each following paragraph as its own content_block_key. Grouping best-practice
 * claims/blocks purely by content_block_key (the pre-existing rule, v0.7 #4) therefore produced
 * one QA case per paragraph instead of one per faglig seksjon. This service restores the
 * heading/paragraph relationship deterministically from data that already exists — block order,
 * each block's own markdown (its leading heading line, if any), and content_origin — no new AI
 * round, no new column, no semantic text comparison.
 *
 * Used by EnterpriseWikiRunFindingsService (groups best-practice claims into one QA finding per
 * section) and WikiController (annotates each rendered block so the Wiki page can merge
 * consecutive blocks of the same section into one visual "Beste praksis" frame) — the heading
 * detection/section-boundary logic lives here ONCE; neither caller re-derives it.
 */
class EnterpriseWikiBestPracticeSectionService
{
    /**
     * Maps every best_practice block's block_key to the section it belongs to. A block whose
     * content_origin is not best_practice is never included in the map (and acts as a hard
     * boundary — see below) — this grouping only ever applies within best-practice content.
     *
     * @return array<string, array{section_key: string, heading_text: ?string, heading_block_key: ?string}>
     */
    public function mapBlocksToSections(EnterpriseWikiPageVersion $version): array
    {
        $blocks = collect((array) ($version->content_blocks_json ?? []))
            ->filter(fn (mixed $block): bool => is_array($block))
            ->sortBy(fn (array $block): int => (int) ($block['position'] ?? 0))
            ->values();

        $map = [];
        $sectionKey = null;
        $level = 0;
        $headingText = null;
        $headingBlockKey = null;

        foreach ($blocks as $block) {
            $blockKey = trim((string) ($block['block_key'] ?? ''));

            if ($blockKey === '') {
                continue;
            }

            if (! $this->hasRenderableBestPracticeMetadata($block)) {
                // A non-best-practice block (source_based, table, image, link, ...) is a hard
                // boundary: never merge best-practice text across it, and never include it in any
                // section itself, regardless of its own markdown content.
                $sectionKey = null;
                $level = 0;
                $headingText = null;
                $headingBlockKey = null;

                continue;
            }

            $heading = $this->leadingHeading((string) ($block['markdown'] ?? ''));

            if ($heading !== null && ($sectionKey === null || $heading['level'] <= $level)) {
                // A heading at the same or a shallower level than the currently open section ends
                // it and starts a new one anchored at this heading block. A deeper heading (a
                // subheading) continues the current section instead — its content still belongs
                // to the same faglig seksjon.
                $sectionKey = $version->id.'|'.$blockKey;
                $level = $heading['level'];
                $headingText = $heading['text'];
                $headingBlockKey = $blockKey;
            } elseif ($sectionKey === null) {
                // No heading has been seen yet in this run of best-practice blocks — still a
                // deterministic, stable section anchored at this very block, just without a
                // heading title (falls back to the primary claim's own text as the display title).
                $sectionKey = $version->id.'|'.$blockKey;
                $level = 0;
                $headingText = null;
                $headingBlockKey = null;
            }

            $map[$blockKey] = [
                'section_key' => $sectionKey,
                'heading_text' => $headingText,
                'heading_block_key' => $headingBlockKey,
            ];
        }

        return $map;
    }

    /**
     * @return array{level: int, text: string}|null null when the markdown does not start with a
     *                                              markdown heading line (# through ######)
     */
    private function leadingHeading(string $markdown): ?array
    {
        $firstLine = trim(explode("\n", trim($markdown), 2)[0] ?? '');

        if (preg_match('/^(#{1,6})\s+(.+)$/', $firstLine, $matches) !== 1) {
            return null;
        }

        return [
            'level' => strlen($matches[1]),
            'text' => trim($matches[2]),
        ];
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function hasRenderableBestPracticeMetadata(array $block): bool
    {
        return ($block['content_origin'] ?? null) === EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE
            && trim((string) ($block['best_practice_reason'] ?? '')) !== '';
    }
}
