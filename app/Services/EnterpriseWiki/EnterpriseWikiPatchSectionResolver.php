<?php

namespace App\Services\EnterpriseWiki;

use App\Exceptions\EnterpriseWikiPatchApplicationException;

/**
 * Fase 8K-3: locates the bounded area of a page's current version that one patch target is allowed
 * to touch — and nothing else.
 *
 * This is the class that makes "patch only what the patch target authorizes" mechanical rather than
 * aspirational. It never mutates anything; it answers one question: which content blocks of this
 * version belong to the section the target names?
 *
 * THE CONTENT MODEL IT WORKS AGAINST (verified against real stored versions, not assumed):
 *
 *  - a version's `content_markdown` is exactly implode("\n\n", every block's markdown) — the same
 *    serialization EnterpriseWikiGenerateAppliedPagesService::writeVersion() produces, so a patch can
 *    rebuild markdown from blocks without inventing a second representation
 *  - a heading is USUALLY its own block with content_origin=structural
 *  - but a heading can also be the first line of a block that continues with prose (observed:
 *    page 49 block-0018 holds "## …" followed by two paragraphs). Any resolver that assumed
 *    "heading == whole block" would mis-bound that section, so headings are found by scanning LINES
 *    inside each block, not by matching whole blocks
 *
 * SECTION BOUNDS: a section runs from the block holding its heading up to (not including) the block
 * holding the next heading at the same or a shallower level. Deeper sub-headings stay inside.
 *
 * Deliberately NOT introduced here: any new block identity, block ID scheme, or persisted section
 * model. The resolver derives bounds on the fly from the version it is given, every time. 8K-3 is
 * explicitly not the place to add a permanent block-ID architecture.
 */
class EnterpriseWikiPatchSectionResolver
{
    /**
     * Resolve the block range a target may touch.
     *
     * @param  list<array<string, mixed>>  $blocks  the version's content_blocks_json, in order
     * @return array{
     *     start_index: int,
     *     end_index: int,
     *     heading: string|null,
     *     heading_block_index: int|null,
     *     heading_line_index: int|null,
     * }
     *
     * @throws EnterpriseWikiPatchApplicationException when the area cannot be located unambiguously
     */
    public function resolve(array $blocks, ?string $targetHeading, string $targetTopic, string $context): array
    {
        if ($blocks === []) {
            throw EnterpriseWikiPatchApplicationException::noContentBlocks($context);
        }

        $headings = $this->headingOccurrences($blocks);

        if ($targetHeading !== null && trim($targetHeading) !== '') {
            return $this->resolveByHeading($blocks, $headings, trim($targetHeading), $context);
        }

        return $this->resolveWithoutHeading($blocks, $headings, $targetTopic, $context);
    }

    /**
     * Non-root headings from a markdown version, in document order.
     *
     * Used to expose the authoritative heading choices for a page in prompt/context material.
     *
     * @return list<string>
     */
    public static function sectionHeadingsFromMarkdown(string $markdown): array
    {
        $headings = [];
        $lines = preg_split('/\R/', $markdown) ?: [];

        foreach ($lines as $line) {
            if (preg_match('/^\s{0,3}(#{1,6})\s+(.*)$/u', (string) $line, $matches) !== 1) {
                continue;
            }

            if (mb_strlen($matches[1]) <= 1) {
                continue;
            }

            $text = preg_replace('/\s+#+\s*$/u', '', trim($matches[2])) ?? trim($matches[2]);
            $text = trim($text);

            if ($text !== '') {
                $headings[] = $text;
            }
        }

        return array_values($headings);
    }

    /**
     * The in-section text of one block. For the block that carries the section's own heading, only
     * the part from the heading line onward belongs to this section — content ABOVE that heading
     * belongs to the previous section and must stay outside the patch area even though it shares a
     * block. Everything else returns the block's full markdown.
     *
     * @param  array{start_index: int, heading_block_index: int|null, heading_line_index: int|null}  $area
     */
    public function inSectionText(array $area, int $blockIndex, string $blockMarkdown): string
    {
        if (
            $blockIndex !== $area['heading_block_index']
            || ($area['heading_line_index'] ?? 0) === 0
        ) {
            return $blockMarkdown;
        }

        $lines = preg_split('/\R/', $blockMarkdown) ?: [];

        return implode("\n", array_slice($lines, (int) $area['heading_line_index']));
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @param  list<array{block_index: int, line_index: int, level: int, text: string}>  $headings
     * @return array{start_index: int, end_index: int, heading: string|null, heading_block_index: int|null, heading_line_index: int|null}
     */
    private function resolveByHeading(array $blocks, array $headings, string $targetHeading, string $context): array
    {
        $needle = $this->normalizeHeading($targetHeading);
        $matches = [];

        foreach ($headings as $i => $heading) {
            if ($this->normalizeHeading($heading['text']) === $needle) {
                $matches[] = $i;
            }
        }

        if ($matches === []) {
            throw EnterpriseWikiPatchApplicationException::headingNotFound($context, $targetHeading);
        }

        // Two sections carrying the SAME heading text cannot be told apart from the decision alone.
        // Guessing would patch one occurrence and silently leave the other stale — exactly the class
        // of failure 8K-3 exists to prevent — so this stops instead.
        if (count($matches) > 1) {
            throw EnterpriseWikiPatchApplicationException::headingAmbiguous($context, $targetHeading, count($matches));
        }

        $index = $matches[0];
        $heading = $headings[$index];
        $endIndex = count($blocks) - 1;

        foreach (array_slice($headings, $index + 1) as $later) {
            if ($later['level'] > $heading['level']) {
                continue; // a deeper sub-heading is part of this section
            }

            // The next same-or-shallower heading ends this section. When that heading sits inside a
            // block that also begins it, the section ends at the previous block.
            $endIndex = $later['line_index'] === 0
                ? $later['block_index'] - 1
                : $later['block_index'];

            break;
        }

        return [
            'start_index' => $heading['block_index'],
            'end_index' => max($heading['block_index'], $endIndex),
            'heading' => $heading['text'],
            'heading_block_index' => $heading['block_index'],
            'heading_line_index' => $heading['line_index'],
        ];
    }

    /**
     * No heading named. 8K-2 records this as a legitimate, documented state: the Wiki has no stable
     * section identifier, so a target may carry only a topic descriptor.
     *
     * The smallest safe fallback is used — never "the whole page". The topic must match a heading by
     * normalized text; if it does, that section is the area. If it does not, there is no bounded area
     * to patch and the patch stops rather than widening its own scope.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @param  list<array{block_index: int, line_index: int, level: int, text: string}>  $headings
     * @return array{start_index: int, end_index: int, heading: string|null, heading_block_index: int|null, heading_line_index: int|null}
     */
    private function resolveWithoutHeading(array $blocks, array $headings, string $targetTopic, string $context): array
    {
        $topic = $this->normalizeHeading($targetTopic);

        if ($topic !== '') {
            foreach ($headings as $heading) {
                if ($this->normalizeHeading($heading['text']) === $topic) {
                    return $this->resolveByHeading($blocks, $headings, $heading['text'], $context);
                }
            }
        }

        if ($this->isFlatPage($headings)) {
            return $this->resolveFlatPageBody($blocks, $headings);
        }

        throw EnterpriseWikiPatchApplicationException::areaNotLocatable($context, $targetTopic);
    }

    /**
     * A page with no sub-sections at all: at most a single top-level H1 and no H2+ anywhere.
     *
     * This is the ordinary shape of a summary page, and of many short concept/entity pages — run 28
     * showed all four targets on a real summary failing because the page simply had nothing to name:
     * one H1 and a body. Refusing those is not caution, it is a blind spot, since a summary is exactly
     * the kind of page a change note supersedes.
     *
     * The test is deliberately structural, not a heading count: the moment a page has ANY heading
     * below H1, its body is divided into semantic sections and a target that names none of them is
     * genuinely unlocatable — the fallback must not fire there.
     *
     * @param  list<array{block_index: int, line_index: int, level: int, text: string}>  $headings
     */
    private function isFlatPage(array $headings): bool
    {
        foreach ($headings as $heading) {
            if ($heading['level'] > 1) {
                return false;
            }
        }

        return count($headings) <= 1;
    }

    /**
     * The body of a flat page — everything BELOW the H1, never the H1 itself.
     *
     * The title line is excluded on purpose: it carries the page's identity, and a patch must never be
     * able to rewrite it (an "area" that included the H1 would make the title reachable by a replace).
     * Exclusion reuses the existing shared-block mechanism rather than a new flag: heading_line_index
     * points one line PAST the H1, and inSectionText() slices from there, so the title is outside the
     * search area exactly the way content above a shared heading already is.
     *
     * This bounds WHERE a patch may look. It does not widen what a patch may do: replace still has to
     * find its exact authorized substance in the body, and amend still appends one local block. There
     * is no "rewrite the body" operation.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @param  list<array{block_index: int, line_index: int, level: int, text: string}>  $headings
     * @return array{start_index: int, end_index: int, heading: string|null, heading_block_index: int|null, heading_line_index: int|null}
     */
    private function resolveFlatPageBody(array $blocks, array $headings): array
    {
        $lastIndex = count($blocks) - 1;
        $title = $headings[0] ?? null;

        if ($title === null) {
            // No heading at all — the whole block list is the body.
            return [
                'start_index' => 0,
                'end_index' => $lastIndex,
                'heading' => null,
                'heading_block_index' => null,
                'heading_line_index' => null,
            ];
        }

        $titleBlockLines = preg_split('/\R/', (string) ($blocks[$title['block_index']]['markdown'] ?? '')) ?: [];
        $titleIsAloneInItsBlock = count(array_filter(
            $titleBlockLines,
            static fn (string $line): bool => trim($line) !== '',
        )) === 1;

        // The H1 owns its whole block: the body simply starts at the next block, and no block is
        // shared with the title.
        if ($titleIsAloneInItsBlock) {
            return [
                'start_index' => min($title['block_index'] + 1, $lastIndex),
                'end_index' => $lastIndex,
                'heading' => null,
                'heading_block_index' => null,
                'heading_line_index' => null,
            ];
        }

        // The H1 shares its block with body text: keep the block in range, but start the searchable
        // area one line below the title.
        return [
            'start_index' => $title['block_index'],
            'end_index' => $lastIndex,
            'heading' => null,
            'heading_block_index' => $title['block_index'],
            'heading_line_index' => $title['line_index'] + 1,
        ];
    }

    /**
     * Every ATX heading line in the version, in document order, with the block and line it sits on.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array{block_index: int, line_index: int, level: int, text: string}>
     */
    private function headingOccurrences(array $blocks): array
    {
        $occurrences = [];

        foreach ($blocks as $blockIndex => $block) {
            $lines = preg_split('/\R/', (string) ($block['markdown'] ?? '')) ?: [];

            foreach ($lines as $lineIndex => $line) {
                if (preg_match('/^\s{0,3}(#{1,6})\s+(.*)$/u', (string) $line, $matches) !== 1) {
                    continue;
                }

                $occurrences[] = [
                    'block_index' => (int) $blockIndex,
                    'line_index' => (int) $lineIndex,
                    'level' => mb_strlen($matches[1]),
                    'text' => trim($matches[2]),
                ];
            }
        }

        return $occurrences;
    }

    /**
     * Whitespace/case comparison with a trailing closed-ATX run removed — the same normalization
     * EnterpriseWikiPatchTargetResolver validates a target_heading with, so a heading accepted at
     * decision time is the heading found here.
     */
    private function normalizeHeading(string $value): string
    {
        $value = preg_replace('/\s+#+\s*$/u', '', trim($value)) ?? trim($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return mb_strtolower(trim($value));
    }
}
