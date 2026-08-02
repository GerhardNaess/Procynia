<?php

namespace App\Services\EnterpriseWiki;

/**
 * Deterministically checks whether a page's planned_figures (from maintainer_decision_json) were
 * actually materialized as genuine `block_type=image` content blocks — built for the Wiki run-587
 * incident: 4 of 4 professionally significant figures were extracted, classified, and made
 * citable, but zero were ever cited or materialized on any page, and nothing checked for this.
 *
 * Checks what was ACTUALLY PERSISTED (the final content_blocks/content_markdown), never AI output
 * alone — a figure the AI claims to have used but that never became a real image block is still a
 * miss. Mirrors EnterpriseWikiPlannedSectionCoverageValidator's shape and philosophy (same
 * required-vs-signal distinction, same H2-heading parsing for section placement), but is a
 * separate class: a planned figure and a planned prose section are different kinds of contract
 * with different failure modes (a figure either exists as a genuine block or it does not — there is
 * no "below minimum substance" concept for an image).
 */
class EnterpriseWikiPlannedFigureCoverageValidator
{
    public const TYPE_MISSING = 'planned_figure_missing';

    public const TYPE_WRONG_SECTION = 'planned_figure_wrong_section';

    public const TYPE_SOURCE_MISSING = 'planned_figure_source_missing';

    public const TYPE_DUPLICATE = 'planned_figure_duplicate';

    public const TYPE_CAPTION_MISSING = 'planned_figure_caption_missing';

    public const TYPE_ALT_TEXT_MISSING = 'planned_figure_alt_text_missing';

    /**
     * @param  list<array<string, mixed>>  $plannedFigures  This page's own planned_figures from
     *                                                      maintainer_decision_json (already scoped to one page by the caller).
     * @param  string  $contentMarkdown  The page's final (or in-flight) content_markdown.
     * @param  list<array<string, mixed>>  $contentBlocks  The page's final (or in-flight) content
     *                                                     blocks, as built by EnterpriseWikiPageContentBlockService/EnterpriseWikiImageBlockBuilder.
     * @param  string[]  $validSourceElementKeys  Real, currently-extractable figure keys — empty
     *                                            means "unknown", in which case the source_missing check is skipped (matches
     *                                            EnterpriseWikiMaintainerDecisionConsistencyValidator's same convention).
     * @return list<array{type: string, source_element_key: string, required: bool, planned_section: ?string}>
     */
    public function validate(
        array $plannedFigures,
        string $contentMarkdown,
        array $contentBlocks,
        array $validSourceElementKeys = [],
    ): array {
        if ($plannedFigures === []) {
            return [];
        }

        $imageBlocksByKey = $this->imageBlocksByKey($contentBlocks);
        $sections = $this->parseSections($contentMarkdown);
        $issues = [];

        foreach ($plannedFigures as $figure) {
            if (! is_array($figure)) {
                continue;
            }

            $sourceElementKey = (string) ($figure['source_element_key'] ?? '');
            $required = (bool) ($figure['required'] ?? false);
            $sectionPlacement = $figure['section_placement'] ?? null;
            $captionHint = $figure['caption_hint'] ?? null;

            if ($sourceElementKey === '') {
                continue;
            }

            if ($validSourceElementKeys !== [] && ! in_array($sourceElementKey, $validSourceElementKeys, true)) {
                $issues[] = $this->issue(self::TYPE_SOURCE_MISSING, $sourceElementKey, $required, $sectionPlacement);

                continue;
            }

            $matches = $imageBlocksByKey[$sourceElementKey] ?? [];

            if ($matches === []) {
                $issues[] = $this->issue(self::TYPE_MISSING, $sourceElementKey, $required, $sectionPlacement);

                continue;
            }

            if (count($matches) > 1) {
                $issues[] = $this->issue(self::TYPE_DUPLICATE, $sourceElementKey, true, $sectionPlacement);
            }

            $block = $matches[0];
            $imageData = (array) ($block['image_data'] ?? []);

            if ($sectionPlacement !== null && trim((string) $sectionPlacement) !== ''
                && ! $this->isBlockWithinSection($block, $sections, (string) $sectionPlacement)
            ) {
                $issues[] = $this->issue(self::TYPE_WRONG_SECTION, $sourceElementKey, $required, $sectionPlacement);
            }

            if (trim((string) ($imageData['caption'] ?? '')) === '' && $captionHint !== null && trim((string) $captionHint) !== '') {
                $issues[] = $this->issue(self::TYPE_CAPTION_MISSING, $sourceElementKey, $required, $sectionPlacement);
            }

            if ($required && trim((string) ($imageData['alt_text'] ?? '')) === '') {
                $issues[] = $this->issue(self::TYPE_ALT_TEXT_MISSING, $sourceElementKey, $required, $sectionPlacement);
            }
        }

        return $issues;
    }

    /**
     * `planned_figure_duplicate` is always blocking (a data-integrity defect regardless of
     * required/optional) — every other structural type (missing/wrong_section/source_missing)
     * blocks only when the underlying figure is required, matching the task's "required manglende
     * figur = severity error / blokkerende, optional manglende figur = warning/info" rule.
     *
     * caption_missing/alt_text_missing are NEVER blocking, regardless of required: caption/alt_text
     * live on the deterministic image_data block (EnterpriseWikiImageBlockBuilder, sourced from the
     * DOCX's own extracted caption/alt-text — never AI-authored), so the bounded AI repair
     * (WikiPageContentAiClient::repairPlannedFigures(), which only rewrites markdown/citations) has
     * no way to actually fix a source document that never had alt-text embedded in the first place.
     * Blocking generation on a defect no repair attempt could ever resolve would turn every such
     * figure into an unconditional hard failure — these two types remain visible as a soft signal
     * only (repair is still free to opportunistically improve them when it regenerates a citation).
     *
     * @param  array{type: string, required: bool}  $issue
     */
    public static function isBlocking(array $issue): bool
    {
        if (in_array($issue['type'], [self::TYPE_CAPTION_MISSING, self::TYPE_ALT_TEXT_MISSING], true)) {
            return false;
        }

        return $issue['type'] === self::TYPE_DUPLICATE || $issue['required'] === true;
    }

    /**
     * @param  list<array<string, mixed>>  $contentBlocks
     * @return array<string, list<array<string, mixed>>> source_element_key => matching image blocks
     */
    private function imageBlocksByKey(array $contentBlocks): array
    {
        $byKey = [];

        foreach ($contentBlocks as $block) {
            if (! is_array($block) || ($block['block_type'] ?? null) !== 'image') {
                continue;
            }

            $key = (string) ($block['source_element_key'] ?? ($block['image_data']['source_image_key'] ?? ''));

            if ($key === '') {
                continue;
            }

            $byKey[$key][] = $block;
        }

        return $byKey;
    }

    /**
     * Whether the image block's own markdown text physically appears within the named section of
     * content_markdown (between that section's `## ` heading and the next heading/EOF) — the same
     * deterministic placement EnterpriseWikiGenerateAppliedPagesService now performs, re-verified
     * against what actually persisted rather than trusted from generation time.
     *
     * @param  array<string, mixed>  $block
     * @param  list<array{heading: string, body: string}>  $sections
     */
    private function isBlockWithinSection(array $block, array $sections, string $sectionPlacement): bool
    {
        $blockMarkdown = trim((string) ($block['markdown'] ?? ''));

        if ($blockMarkdown === '') {
            return false;
        }

        $normalizedTarget = $this->normalize($sectionPlacement);

        foreach ($sections as $section) {
            $normalizedHeading = $this->normalize($section['heading']);

            if ($normalizedHeading === ''
                || (! str_contains($normalizedTarget, $normalizedHeading) && ! str_contains($normalizedHeading, $normalizedTarget))
            ) {
                continue;
            }

            return str_contains($section['body'], $blockMarkdown);
        }

        // The planned section itself does not exist in the final content at all — not a
        // "wrong section" case (nothing to be near), handled by the caller as a missing section
        // elsewhere (EnterpriseWikiPlannedSectionCoverageValidator's own concern, not this one) —
        // report it as wrong_section here too since the figure is provably not where planned.
        return false;
    }

    /**
     * @return list<array{heading: string, body: string}>
     */
    private function parseSections(string $contentMarkdown): array
    {
        $lines = preg_split('/\R/', $contentMarkdown) ?: [];
        $sections = [];
        $currentHeading = null;
        $currentBody = [];

        foreach ($lines as $line) {
            if (preg_match('/^##\s+(.+?)\s*$/', $line, $matches) === 1) {
                if ($currentHeading !== null) {
                    $sections[] = ['heading' => $currentHeading, 'body' => implode("\n", $currentBody)];
                }

                $currentHeading = $matches[1];
                $currentBody = [];

                continue;
            }

            if (preg_match('/^#\s+(.+?)\s*$/', $line) === 1 && $currentHeading !== null) {
                $sections[] = ['heading' => $currentHeading, 'body' => implode("\n", $currentBody)];
                $currentHeading = null;
                $currentBody = [];

                continue;
            }

            if ($currentHeading !== null) {
                $currentBody[] = $line;
            }
        }

        if ($currentHeading !== null) {
            $sections[] = ['heading' => $currentHeading, 'body' => implode("\n", $currentBody)];
        }

        return $sections;
    }

    private function normalize(string $text): string
    {
        $withoutParens = preg_replace('/\([^)]*\)/', '', $text) ?? $text;
        $lower = mb_strtolower($withoutParens);
        $lettersOnly = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $lower) ?? $lower;

        return trim(preg_replace('/\s+/', ' ', $lettersOnly) ?? $lettersOnly);
    }

    /** @return array{type: string, source_element_key: string, required: bool, planned_section: ?string} */
    private function issue(string $type, string $sourceElementKey, bool $required, ?string $plannedSection): array
    {
        return [
            'type' => $type,
            'source_element_key' => $sourceElementKey,
            'required' => $required,
            'planned_section' => $plannedSection,
        ];
    }
}
