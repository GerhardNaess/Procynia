<?php

namespace App\Services\EnterpriseWiki;

/**
 * Deterministically checks whether a generated Wiki page's `##` sections actually cover the
 * page's planned/owned topics (maintainer_decision_json's `owned_topics` for the page) with real
 * substance — built for the Wiki run-586 incident: a concept page ("Samhandlings- og
 * styringsmodell") was generated with its two planned `## ` headings present but with zero body
 * text under either, and nothing in the generation pipeline, schema, parser, or QA noticed.
 *
 * Only meaningful for page types whose prompt maps owned_topics to `## ` sections — article,
 * concept, entity (see WikiPageContentAiClient::developerPrompt()). Summary pages are explicitly
 * instructed to have no headings beyond the title, so they are never checked here.
 *
 * Heading matching is deliberately fuzzy, not exact: the model paraphrases/shortens a planned
 * topic into a heading (e.g. topic "Samhandlings- og styringsmodell for applikasjonsdrift
 * (strategisk, taktisk og operativt nivå)" became heading "## Samhandlings- og styringsmodell for
 * applikasjonsdrift") — normalize() strips parentheticals/punctuation/case before comparing, and a
 * heading matches a topic when one normalized string contains the other.
 *
 * `planned_section_empty`/`planned_section_only_links`/`planned_section_below_minimum_substance`
 * are always blocking: a heading that exists in the output is never a legitimate "thin page"
 * case — it is a generation defect, full stop. `planned_section_missing` (no matching heading at
 * all) is blocking only when the topic has detectable grounding in the source text — the
 * prompt's own contract explicitly permits "AT MOST one section per item" and fewer sections than
 * owned_topics when the source material does not support them (a short source document justifies
 * a short page), so a missing heading for a topic with no source grounding is reported as a
 * non-blocking planning signal, not a defect.
 */
class EnterpriseWikiPlannedSectionCoverageValidator
{
    public const TYPE_MISSING = 'planned_section_missing';

    public const TYPE_EMPTY = 'planned_section_empty';

    public const TYPE_ONLY_LINKS = 'planned_section_only_links';

    public const TYPE_BELOW_MINIMUM_SUBSTANCE = 'planned_section_below_minimum_substance';

    /** Page types whose prompt maps owned_topics onto `## ` sections. */
    private const CHECKED_PAGE_TYPES = ['article', 'concept', 'entity'];

    /**
     * Secondary signal only (per the task's own instruction: minimum length is never the sole
     * quality measure) — a section whose only content, after stripping wikilink markup, is
     * fewer than this many letters is flagged as below-minimum-substance rather than accepted
     * at face value just because it is technically non-empty.
     */
    private const MIN_SUBSTANCE_LETTERS = 15;

    private const STOPWORDS = [
        'og', 'i', 'for', 'til', 'av', 'på', 'med', 'den', 'det', 'de', 'som', 'en', 'et',
        'and', 'for', 'the', 'of', 'in', 'to', 'a', 'an', 'on', 'with',
    ];

    /**
     * @param  list<string>  $plannedTopics  owned_topics for this page, verbatim from maintainer_decision_json
     * @return list<array{type: string, planned_topic: string, heading: ?string, source_grounded: bool}>
     */
    public function validate(array $plannedTopics, string $contentMarkdown, string $pageType, string $sourceText = ''): array
    {
        if ($plannedTopics === [] || ! in_array($pageType, self::CHECKED_PAGE_TYPES, true)) {
            return [];
        }

        $sections = $this->parseSections($contentMarkdown);
        $issues = [];

        foreach ($plannedTopics as $topic) {
            $topic = trim($topic);

            if ($topic === '') {
                continue;
            }

            $matchedSection = $this->matchingSection($sections, $topic);

            if ($matchedSection === null) {
                $issues[] = [
                    'type' => self::TYPE_MISSING,
                    'planned_topic' => $topic,
                    'heading' => null,
                    'source_grounded' => $this->hasSourceGrounding($topic, $sourceText),
                ];

                continue;
            }

            $issueType = $this->classifyBody($matchedSection['body']);

            if ($issueType !== null) {
                $issues[] = [
                    'type' => $issueType,
                    'planned_topic' => $topic,
                    'heading' => $matchedSection['heading'],
                    'source_grounded' => true,
                ];
            }
        }

        return $issues;
    }

    /**
     * Returns the current body for the same deterministic topic/heading match used by validate().
     * Repair context uses this so the model sees the exact invalid body without maintaining a
     * divergent markdown parser or matching rule.
     */
    public function sectionBodyForPlannedTopic(string $contentMarkdown, string $plannedTopic): ?string
    {
        $matchedSection = $this->matchingSection($this->parseSections($contentMarkdown), trim($plannedTopic));

        return $matchedSection['body'] ?? null;
    }

    /**
     * `planned_section_empty`/`_only_links`/`_below_minimum_substance` are always blocking
     * (a heading that exists is never a legitimate "thin page" case). `planned_section_missing`
     * blocks only when the topic has detectable source grounding — otherwise it is a signal, not
     * a defect (a short source document legitimately does not support every planned section).
     */
    public static function isBlocking(array $issue): bool
    {
        return $issue['type'] !== self::TYPE_MISSING || $issue['source_grounded'] === true;
    }

    /**
     * @return list<array{heading: string, body: string}>
     */
    private function parseSections(string $contentMarkdown): array
    {
        // \R includes NEL (U+0085). Without the Unicode modifier PCRE can mistake the trailing
        // byte 0x85 of the valid UTF-8 character Å (C3 85) for that line break, leaving a lone
        // C3 byte in the preceding section body. This parser feeds repair context, so line
        // splitting must remain character-aware.
        $lines = preg_split('/\R/u', $contentMarkdown) ?: [];
        $sections = [];
        $currentHeading = null;
        $currentBody = [];

        foreach ($lines as $line) {
            if (preg_match('/^##\s+(.+?)\s*$/', $line, $matches) === 1) {
                if ($currentHeading !== null) {
                    $sections[] = ['heading' => $currentHeading, 'body' => trim(implode("\n", $currentBody))];
                }

                $currentHeading = $matches[1];
                $currentBody = [];

                continue;
            }

            // A new H1 also closes the current H2 section (e.g. a stray title-level line).
            if (preg_match('/^#\s+(.+?)\s*$/', $line) === 1 && $currentHeading !== null) {
                $sections[] = ['heading' => $currentHeading, 'body' => trim(implode("\n", $currentBody))];
                $currentHeading = null;
                $currentBody = [];

                continue;
            }

            if ($currentHeading !== null) {
                $currentBody[] = $line;
            }
        }

        if ($currentHeading !== null) {
            $sections[] = ['heading' => $currentHeading, 'body' => trim(implode("\n", $currentBody))];
        }

        return $sections;
    }

    /** @param list<array{heading: string, body: string}> $sections @return array{heading: string, body: string}|null */
    private function matchingSection(array $sections, string $plannedTopic): ?array
    {
        $normalizedTopic = self::normalize($plannedTopic);

        foreach ($sections as $section) {
            $normalizedHeading = self::normalize($section['heading']);

            if ($normalizedHeading !== ''
                && (str_contains($normalizedTopic, $normalizedHeading) || str_contains($normalizedHeading, $normalizedTopic))
            ) {
                return $section;
            }
        }

        return null;
    }

    private function classifyBody(string $body): ?string
    {
        if (trim($body) === '') {
            return self::TYPE_EMPTY;
        }

        $withoutLinks = preg_replace('/\[\[[^\]]+\]\]/', '', $body) ?? '';
        $letters = preg_replace('/[^\p{L}]/u', '', $withoutLinks) ?? '';

        if ($letters === '') {
            return self::TYPE_ONLY_LINKS;
        }

        if (mb_strlen($letters) < self::MIN_SUBSTANCE_LETTERS) {
            return self::TYPE_BELOW_MINIMUM_SUBSTANCE;
        }

        return null;
    }

    /**
     * Coarse, deterministic heuristic: does at least one significant (non-stopword, 4+ letter)
     * word from the topic name appear in the source text? Never a semantic/AI judgement — this
     * only distinguishes "clearly no source basis" (safe to omit a section for) from everything
     * else, which is treated as grounded (the safer default when this can't be determined).
     */
    private function hasSourceGrounding(string $topic, string $sourceText): bool
    {
        if (trim($sourceText) === '') {
            return true;
        }

        $normalizedSource = mb_strtolower($sourceText);
        $words = preg_split('/[^\p{L}]+/u', mb_strtolower($topic), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $significantWords = array_filter(
            $words,
            fn (string $word): bool => mb_strlen($word) >= 4 && ! in_array($word, self::STOPWORDS, true),
        );

        if ($significantWords === []) {
            return true;
        }

        foreach ($significantWords as $word) {
            if (str_contains($normalizedSource, $word)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalizes a topic/heading for fuzzy comparison: strips parenthetical asides (topics often
     * carry a clarifying suffix a heading omits), lowercases, and collapses punctuation/whitespace.
     *
     * Public (unchanged logic, visibility only) so EnterpriseWikiGenerateAppliedPagesService's own
     * section-splice matching (Wiki run-593: precise section repair) uses the EXACT same rule this
     * validator itself re-checks the repaired page against, rather than a second, divergent copy.
     */
    public static function normalize(string $text): string
    {
        $withoutParens = preg_replace('/\([^)]*\)/', '', $text) ?? $text;
        $lower = mb_strtolower($withoutParens);
        $lettersOnly = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $lower) ?? $lower;

        return trim(preg_replace('/\s+/', ' ', $lettersOnly) ?? $lettersOnly);
    }
}
