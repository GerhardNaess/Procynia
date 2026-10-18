<?php

namespace App\Services\EnterpriseWiki;

/**
 * Inserts one inbound wikilink into an existing page's UNCHANGED text (relink v2, D8 —
 * docs/enterprise-wiki-knowledge-integration-plan.md §6.4).
 *
 * The AI names a block and the words that should carry the link. Everything else is decided and
 * proven here, deterministically:
 *
 * - The anchor must occur EXACTLY ONCE in that block as ordinary prose, on word boundaries. Text
 *   inside an existing wikilink or Markdown link, an image, a code span or fenced code block, a
 *   heading, a table row or an HTML tag is protected and never counts. Zero placeable occurrences
 *   rejects the proposal; more than one rejects it as ambiguous — a position is never guessed.
 * - The only change written is `[[slug|anchor]]` around those words. Afterwards three invariants
 *   are checked mechanically before the result is accepted: removing the inserted markup restores
 *   the old markdown byte for byte; the visible text with every wikilink stripped is identical; and
 *   every other block is byte-identical. Any violation rejects the insertion.
 *
 * Provenance is untouched: the block keeps its content_origin and source elements, because only
 * link markup — not substance — was added. The link intent, with the AI's reason, is appended to
 * the block's link_intents so the graph can show why the relation exists.
 */
class EnterpriseWikiInboundLinkInserter
{
    public const REJECT_UNKNOWN_BLOCK = 'unknown_block';

    public const REJECT_INELIGIBLE_BLOCK = 'ineligible_block';

    public const REJECT_INVALID_ANCHOR = 'invalid_anchor';

    public const REJECT_ANCHOR_NOT_FOUND = 'anchor_not_found';

    public const REJECT_ANCHOR_PROTECTED = 'anchor_in_protected_context';

    public const REJECT_AMBIGUOUS_ANCHOR = 'ambiguous_anchor';

    public const REJECT_BLOCK_NOT_LOCATABLE = 'block_not_locatable';

    public const REJECT_INVARIANT_VIOLATION = 'invariant_violation';

    /** Blocks that are rendered from structure rather than prose, and never carry an inbound link. */
    public const INELIGIBLE_BLOCK_TYPES = ['table', 'image'];

    private const MAX_ANCHOR_CHARS = 120;

    /**
     * @param  list<array<string, mixed>>  $blocks  the current version's content_blocks_json
     * @param  array{intent_id: string, target_page_id: int, anchor_text: string, reason: ?string}  $intent
     * @return array{ok: true, content_markdown: string, content_blocks_json: list<array<string, mixed>>}|array{ok: false, reason: string}
     */
    public function insert(string $contentMarkdown, array $blocks, string $blockKey, string $anchorText, string $targetSlug, array $intent): array
    {
        $blockIndex = null;

        foreach ($blocks as $index => $block) {
            if (is_array($block) && ($block['block_key'] ?? null) === $blockKey) {
                $blockIndex = $index;

                break;
            }
        }

        if ($blockIndex === null) {
            return $this->rejected(self::REJECT_UNKNOWN_BLOCK);
        }

        $block = $blocks[$blockIndex];
        $blockMarkdown = (string) ($block['markdown'] ?? '');

        if (! self::isEligibleBlock($block)) {
            return $this->rejected(self::REJECT_INELIGIBLE_BLOCK);
        }

        if (! $this->isValidAnchor($anchorText)) {
            return $this->rejected(self::REJECT_INVALID_ANCHOR);
        }

        [$placeable, $total] = $this->occurrences($blockMarkdown, $anchorText);

        if ($total === 0) {
            return $this->rejected(self::REJECT_ANCHOR_NOT_FOUND);
        }

        if ($placeable === []) {
            return $this->rejected(self::REJECT_ANCHOR_PROTECTED);
        }

        if (count($placeable) > 1) {
            return $this->rejected(self::REJECT_AMBIGUOUS_ANCHOR);
        }

        // The block's own text must be locatable in the page markdown exactly once, or the edit
        // could land in the wrong place.
        $blockStart = strpos($contentMarkdown, $blockMarkdown);

        if ($blockMarkdown === '' || $blockStart === false || substr_count($contentMarkdown, $blockMarkdown) !== 1) {
            return $this->rejected(self::REJECT_BLOCK_NOT_LOCATABLE);
        }

        $localOffset = $placeable[0];
        $markup = "[[{$targetSlug}|{$anchorText}]]";
        $newBlockMarkdown = substr_replace($blockMarkdown, $markup, $localOffset, strlen($anchorText));
        $globalOffset = $blockStart + $localOffset;
        $newContentMarkdown = substr_replace($contentMarkdown, $markup, $globalOffset, strlen($anchorText));

        $newBlocks = $blocks;
        $newBlocks[$blockIndex]['markdown'] = $newBlockMarkdown;
        $newBlocks[$blockIndex]['link_intents'] = [
            ...array_values(array_filter((array) ($block['link_intents'] ?? []), 'is_array')),
            $intent,
        ];

        if (! $this->onlyLinkMarkupAdded($contentMarkdown, $newContentMarkdown, $globalOffset, $markup, $anchorText)
            || ! $this->onlyLinkMarkupAdded($blockMarkdown, $newBlockMarkdown, $localOffset, $markup, $anchorText)
            || ! $this->otherBlocksUnchanged($blocks, $newBlocks, $blockIndex)) {
            return $this->rejected(self::REJECT_INVARIANT_VIOLATION);
        }

        return [
            'ok' => true,
            'content_markdown' => $newContentMarkdown,
            'content_blocks_json' => array_values($newBlocks),
        ];
    }

    /** @param  array<string, mixed>  $block */
    public static function isEligibleBlock(array $block): bool
    {
        if (in_array($block['block_type'] ?? null, self::INELIGIBLE_BLOCK_TYPES, true)) {
            return false;
        }

        return trim((string) ($block['markdown'] ?? '')) !== '';
    }

    /**
     * Visible text of markdown with every wikilink replaced by what the reader sees.
     */
    public static function stripWikilinks(string $markdown): string
    {
        $markdown = (string) preg_replace('/\[\[[^\]|]+\|([^\]]+)\]\]/u', '$1', $markdown);

        return (string) preg_replace('/\[\[([^\]|]+)\]\]/u', '$1', $markdown);
    }

    private function isValidAnchor(string $anchorText): bool
    {
        if ($anchorText === '' || trim($anchorText) !== $anchorText || mb_strlen($anchorText) > self::MAX_ANCHOR_CHARS) {
            return false;
        }

        // Characters that would break, nest or escape the link markup this class writes.
        return preg_match('/[\[\]|`\r\n<>]/u', $anchorText) !== 1;
    }

    /**
     * Byte offsets of every placeable occurrence, and the total number of occurrences.
     *
     * @return array{0: list<int>, 1: int}
     */
    private function occurrences(string $markdown, string $anchorText): array
    {
        $protected = $this->protectedRanges($markdown);
        $placeable = [];
        $total = 0;
        $offset = 0;

        while (($found = strpos($markdown, $anchorText, $offset)) !== false) {
            $offset = $found + 1;
            $end = $found + strlen($anchorText);

            if (! $this->onWordBoundaries($markdown, $found, $end)) {
                continue;
            }

            $total++;

            foreach ($protected as [$start, $stop]) {
                if ($found < $stop && $end > $start) {
                    continue 2;
                }
            }

            $placeable[] = $found;
        }

        return [$placeable, $total];
    }

    private function onWordBoundaries(string $markdown, int $start, int $end): bool
    {
        $before = $start > 0 ? mb_substr(substr($markdown, 0, $start), -1) : '';
        $after = $end < strlen($markdown) ? mb_substr(substr($markdown, $end), 0, 1) : '';

        foreach ([$before, $after] as $neighbour) {
            if ($neighbour !== '' && preg_match('/[\p{L}\p{N}_]/u', $neighbour) === 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Byte ranges that are structure, not prose: links of either kind, images, inline and fenced
     * code, headings, table rows and HTML tags.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function protectedRanges(string $markdown): array
    {
        $patterns = [
            '/```.*?(```|\z)/su',          // fenced code, unterminated fence to end of text
            '/~~~.*?(~~~|\z)/su',
            '/`[^`\n]*`/u',                // inline code
            '/!\[[^\]]*\]\([^)]*\)/u',     // images
            '/\[\[[^\]]*\]\]/u',           // wikilinks
            '/\[[^\]]*\]\([^)]*\)/u',      // Markdown links
            '/<[^>\n]+>/u',                // HTML tags and autolinks
            '/^[ \t]*#{1,6}[ \t].*$/mu',   // headings
            '/^[ \t]*\|.*$/mu',            // table rows
        ];

        $ranges = [];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $markdown, $matches, PREG_OFFSET_CAPTURE) === false) {
                continue;
            }

            foreach ($matches[0] as [$text, $offset]) {
                $ranges[] = [$offset, $offset + strlen($text)];
            }
        }

        return $ranges;
    }

    private function onlyLinkMarkupAdded(string $old, string $new, int $offset, string $markup, string $anchorText): bool
    {
        if (substr($new, $offset, strlen($markup)) !== $markup) {
            return false;
        }

        if (substr_replace($new, $anchorText, $offset, strlen($markup)) !== $old) {
            return false;
        }

        return self::stripWikilinks($new) === self::stripWikilinks($old);
    }

    /**
     * @param  list<array<string, mixed>>  $old
     * @param  list<array<string, mixed>>  $new
     */
    private function otherBlocksUnchanged(array $old, array $new, int|string $changedIndex): bool
    {
        if (count($old) !== count($new)) {
            return false;
        }

        foreach ($old as $index => $block) {
            if ($index === $changedIndex) {
                continue;
            }

            if (json_encode($block) !== json_encode($new[$index] ?? null)) {
                return false;
            }
        }

        $oldChanged = $old[$changedIndex];
        $newChanged = $new[$changedIndex];
        unset($oldChanged['markdown'], $oldChanged['link_intents'], $newChanged['markdown'], $newChanged['link_intents']);

        return json_encode($oldChanged) === json_encode($newChanged);
    }

    /** @return array{ok: false, reason: string} */
    private function rejected(string $reason): array
    {
        return ['ok' => false, 'reason' => $reason];
    }
}
