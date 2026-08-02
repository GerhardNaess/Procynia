<?php

namespace App\Services\EnterpriseWiki;

/**
 * Deterministically removes a verbatim-repeated sentence or paragraph within one generated page
 * (run 574's finding #5560: a full sentence appearing once as its own short paragraph and again
 * as the opening sentence of a later paragraph, verbatim). This is a defensive structural
 * safeguard against a known LLM failure mode, applied to the AI's own structured blocks before
 * EnterpriseWikiPageContentBlockService::buildBlocksFromStructuredResult() assigns block_key/
 * content_origin/source provenance — so that metadata is built fresh, correctly, for whatever
 * blocks remain after deduplication, with no renumbering step needed afterwards.
 *
 * Deliberately narrow, no AI, no new repair round:
 *  - Only a sentence/paragraph whose NORMALIZED text (surrounding whitespace and line breaks
 *    collapsed — nothing else) exactly matches an earlier one, anywhere earlier in the same page,
 *    is removed. Two sentences that merely read as similar are always both kept.
 *  - The FIRST occurrence is always kept untouched, byte-for-byte; only a later, identical
 *    occurrence is dropped.
 *  - A heading line (starts with 1-6 '#' characters) is never split or removed.
 *  - Scope is exactly one call to removeVerbatimDuplicates() — one generated page — so the exact
 *    same sentence legitimately repeated on a different Wiki page is never affected; there is no
 *    global or cross-page state.
 *  - A block whose entire markdown is dropped (every sentence in it was a duplicate) is removed
 *    from the returned list entirely, rather than persisting an empty block.
 */
class EnterpriseWikiDuplicateContentRemover
{
    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    public function removeVerbatimDuplicates(array $blocks): array
    {
        $seen = [];
        $result = [];

        foreach ($blocks as $block) {
            $markdown = (string) ($block['markdown'] ?? '');
            $deduplicated = $this->deduplicateBlockMarkdown($markdown, $seen);

            if (trim($deduplicated) === '') {
                continue;
            }

            $block['markdown'] = $deduplicated;
            $result[] = $block;
        }

        return $result;
    }

    /**
     * @param  array<string, true>  $seen
     */
    private function deduplicateBlockMarkdown(string $markdown, array &$seen): string
    {
        // Split into paragraphs on blank-line boundaries, keeping the separators themselves
        // (captured via the parenthesised group) so reassembly reproduces the original spacing
        // exactly for every paragraph that survives untouched.
        $chunks = preg_split('/(\n{2,})/u', $markdown, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($chunks === false) {
            return $markdown;
        }

        $kept = [];

        foreach ($chunks as $index => $chunk) {
            // Odd indices are the captured blank-line separators — never paragraph content.
            if ($index % 2 === 1) {
                $kept[] = $chunk;

                continue;
            }

            $kept[] = $this->deduplicateParagraph($chunk, $seen);
        }

        $joined = implode('', $kept);

        // Dropping a duplicate paragraph can strand its blank-line separator next to another
        // one; collapsing runs of 3+ newlines back to a single blank line, and trimming the
        // block's own ends, is surrounding-whitespace/line-break normalization only — it never
        // touches paragraph or sentence content.
        $joined = (string) preg_replace('/\n{3,}/u', "\n\n", $joined);

        return trim($joined);
    }

    /**
     * @param  array<string, true>  $seen
     */
    private function deduplicateParagraph(string $paragraph, array &$seen): string
    {
        if (trim($paragraph) === '') {
            return $paragraph;
        }

        // "Ikke fjern overskrifter" — a heading line is a hard boundary, never deduplicated
        // against anything, never split into sentences.
        if (preg_match('/^\s*#{1,6}\s/u', $paragraph) === 1) {
            return $paragraph;
        }

        $normalizedWhole = $this->normalize($paragraph);

        if ($normalizedWhole !== '' && isset($seen[$normalizedWhole])) {
            // The entire paragraph verbatim-repeats an earlier one — drop it entirely rather
            // than fall through to sentence-level handling (which would otherwise strip its
            // first sentence and keep the rest, subtly changing an intentional full repeat into
            // a fragment instead of removing it outright).
            return '';
        }

        $sentences = $this->splitIntoSentences($paragraph);
        $keptSentences = [];

        foreach ($sentences as $sentence) {
            $normalized = $this->normalize($sentence);

            if ($normalized === '') {
                $keptSentences[] = $sentence;

                continue;
            }

            if (isset($seen[$normalized])) {
                continue;
            }

            $seen[$normalized] = true;
            $keptSentences[] = $sentence;
        }

        $rebuilt = implode('', $keptSentences);

        if ($normalizedWhole !== '') {
            $seen[$normalizedWhole] = true;
        }

        return $rebuilt;
    }

    /**
     * Splits text into sentence tokens, each token retaining its own trailing sentence-ending
     * punctuation and whitespace — so implode('', $sentences) reproduces the original text
     * exactly when nothing is removed, and dropping one token never leaves a stray double space
     * or lost line break around the sentences that remain.
     *
     * @return list<string>
     */
    private function splitIntoSentences(string $text): array
    {
        $pieces = preg_split('/([.!?]+\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        if ($pieces === false || $pieces === []) {
            return [$text];
        }

        $sentences = [];
        $buffer = '';

        foreach ($pieces as $piece) {
            $buffer .= $piece;

            if (preg_match('/[.!?]+\s+$/u', $piece) === 1) {
                $sentences[] = $buffer;
                $buffer = '';
            }
        }

        if ($buffer !== '') {
            $sentences[] = $buffer;
        }

        return $sentences;
    }

    /**
     * The only normalization applied: collapse any run of whitespace (including line breaks) to
     * a single space, and trim the ends. Punctuation, wording, and case are never altered or
     * folded — two sentences must already be identical there to be treated as a duplicate.
     */
    private function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
