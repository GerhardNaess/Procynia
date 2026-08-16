<?php

namespace App\Services\Ai\Wiki;

/**
 * Reads one Wiki catalog entry's content_markdown for inclusion in a research context — the
 * "actually read the article" step, deliberately never reducing a page to a handful of
 * disconnected claim sentences.
 *
 * Short/medium pages (see FULL_CONTENT_MAX_CHARS) are sent in full — headings, paragraphs and
 * lists intact. Long pages are reduced by Markdown heading boundaries, never mid-sentence: the
 * page's own lead section (its H1 title plus intro paragraph — the first heading block in the
 * document) is always kept for framing, and further sections are kept whole, in original document
 * order, for as long as they are relevant to the query and the per-page section budget allows.
 */
class RequirementWikiPageReader
{
    public function __construct(
        private readonly RequirementWikiFigureCatalog $figureCatalog = new RequirementWikiFigureCatalog,
    ) {}

    /**
     * Pages at or under this length are sent whole. Chosen from real data: a real customer's
     * approved Wiki pages ranged 1904-16100 chars (median 2616); 4000 sends 27 of 31 pages (87%)
     * in full and only sections the handful of genuinely long ones.
     */
    public const FULL_CONTENT_MAX_CHARS = 4000;

    /**
     * Per-page cap on sectioned content, once a page is long enough to need sectioning. Chosen so
     * a single long page cannot dominate the overall context budget (see
     * RequirementWikiResearchService::MAX_CONTEXT_SIZE) — a page this long already carries several
     * full sections' worth of real content.
     */
    public const SECTION_BUDGET_CHARS = 3000;

    /**
     * Purpose: Read one catalog entry's content for inclusion in the research context.
     * Inputs: A RequirementWikiCatalogBuilder entry (must carry content_markdown) and the
     *         normalized query tokens driving relevance (requirement tokens, or the search terms
     *         that led to this page).
     * Returns: {content_mode: 'full'|'sections', content_markdown: string, selected_headings:
     *          list<string>, figures: list<array<string, mixed>>}.
     *          selected_headings is always [] for content_mode='full' (the whole page was used,
     *          there is nothing to itemize). figures are the page's own Wiki figures, narrowed to
     *          the ones whose block actually appears in the content this read returned — a
     *          sectioned read must not offer a figure from a section it skipped.
     * Side effects: None.
     *
     * @param  array<string, mixed>  $catalogEntry
     * @param  list<string>  $queryTokens
     * @return array{content_mode: string, content_markdown: string, selected_headings: list<string>, figures: list<array<string, mixed>>}
     */
    public function read(array $catalogEntry, array $queryTokens): array
    {
        $content = (string) $catalogEntry['content_markdown'];
        $figures = (array) ($catalogEntry['figures'] ?? []);

        if (mb_strlen($content, 'UTF-8') <= self::FULL_CONTENT_MAX_CHARS) {
            return [
                'content_mode' => 'full',
                'content_markdown' => $content,
                'selected_headings' => [],
                'figures' => array_values($figures),
            ];
        }

        $read = $this->readSections($content, $queryTokens);
        $read['figures'] = $this->figureCatalog->readable(array_values($figures), $read['content_markdown']);

        return $read;
    }

    /**
     * @param  list<string>  $queryTokens
     * @return array{content_mode: string, content_markdown: string, selected_headings: list<string>}
     */
    private function readSections(string $content, array $queryTokens): array
    {
        $sections = $this->splitIntoHeadingSections($content);

        if ($sections === []) {
            // No heading structure at all (unusual for this Wiki, but fail safe) — fall back to a
            // single hard truncation at the section budget rather than sending nothing.
            return [
                'content_mode' => 'sections',
                'content_markdown' => mb_substr($content, 0, self::SECTION_BUDGET_CHARS, 'UTF-8'),
                'selected_headings' => [],
            ];
        }

        $kept = [];
        $selectedHeadings = [];
        $usedChars = 0;

        foreach ($sections as $index => $section) {
            $isLeadSection = $index === 0;

            if (! $isLeadSection) {
                $sectionTokens = RequirementWikiTermNormalizer::tokenize($section['text']);
                [$overlapCount] = RequirementWikiTermNormalizer::overlap($queryTokens, $sectionTokens);

                if ($overlapCount === 0) {
                    continue;
                }

                $sectionLength = mb_strlen($section['text'], 'UTF-8');

                if ($usedChars + $sectionLength > self::SECTION_BUDGET_CHARS) {
                    // Stop rather than skip ahead — keeps document order faithful and avoids
                    // scattering non-adjacent fragments across the page.
                    break;
                }
            }

            $kept[] = $section['text'];
            $usedChars += mb_strlen($section['text'], 'UTF-8');

            if ($section['heading'] !== null) {
                $selectedHeadings[] = $section['heading'];
            }
        }

        return [
            'content_mode' => 'sections',
            'content_markdown' => implode("\n\n", $kept),
            'selected_headings' => $selectedHeadings,
        ];
    }

    /**
     * Purpose: Split Markdown into blocks at every ATX heading line (any level 1-6) — each block
     * is the heading line together with all body text up to (not including) the next heading.
     * Inputs: Raw content_markdown.
     * Returns: Ordered sections, each {heading: ?string, text: string}. The first section (index
     *          0) is the page's lead section — its own H1 title plus intro paragraph — and is
     *          always kept by the caller regardless of relevance scoring.
     * Side effects: None.
     *
     * @return list<array{heading: ?string, text: string}>
     */
    private function splitIntoHeadingSections(string $content): array
    {
        $sections = [];
        $current = null;

        foreach (explode("\n", $content) as $line) {
            if (preg_match('/^\s{0,3}#{1,6}\s+(.+?)\s*$/u', $line, $matches) === 1) {
                if ($current !== null) {
                    $sections[] = $current;
                }

                $current = ['heading' => trim($matches[1]), 'lines' => [$line]];

                continue;
            }

            if ($current === null) {
                // Body text before any heading at all — keep it as an untitled lead section.
                $current = ['heading' => null, 'lines' => []];
            }

            $current['lines'][] = $line;
        }

        if ($current !== null) {
            $sections[] = $current;
        }

        return array_map(
            static fn (array $section): array => [
                'heading' => $section['heading'],
                'text' => trim(implode("\n", $section['lines'])),
            ],
            array_values(array_filter($sections, static fn (array $section): bool => trim(implode("\n", $section['lines'])) !== '')),
        );
    }
}
