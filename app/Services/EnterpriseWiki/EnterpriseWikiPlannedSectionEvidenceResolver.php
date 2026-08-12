<?php

namespace App\Services\EnterpriseWiki;

/**
 * Creates the generation-time evidence contract for already planned sections. The maintainer
 * decision remains authoritative for section identity; this class only binds each identity to
 * existing extracted source elements before an AI call is made.
 */
class EnterpriseWikiPlannedSectionEvidenceResolver
{
    private const MAX_ELEMENTS_PER_SECTION = 6;

    private const MAX_EVIDENCE_CHARS_PER_SECTION = 3600;

    /**
     * Stable document-control labels, not semantic similarity. A maintainer may use ordinary
     * page terms while a DOCX stores the same field under its canonical control label.
     *
     * @var list<array{topic_terms: list<string>, source_terms: list<string>}>
     */
    private const DOCUMENT_METADATA_BINDINGS = [
        [
            'topic_terms' => ['dokumentidentitet', 'document identity'],
            'source_terms' => ['dokument-id', 'dokument id', 'document id'],
        ],
        [
            'topic_terms' => ['formål', 'purpose'],
            'source_terms' => ['hensikt', 'formål', 'purpose'],
        ],
        [
            'topic_terms' => ['gyldighet', 'validity'],
            'source_terms' => ['gyldig fra', 'gyldig til', 'gyldighet', 'validity'],
        ],
    ];

    /** @var list<string> */
    private const STOPWORDS = [
        'eller', 'med', 'for', 'fra', 'som', 'skal', 'den', 'det', 'der', 'til', 'av', 'og',
        'the', 'and', 'with', 'from', 'that', 'this', 'into', 'for', 'are', 'is',
    ];

    /**
     * @param  list<string>  $plannedTopics
     * @param  list<array<string, mixed>>  $sourceElements
     * @return list<array{section_index: int, planned_topic: string, required_heading: string, section_purpose: string, source_element_keys: list<string>, source_evidence: list<array<string, mixed>>, source_element_count: int, evidence_char_count: int, required: bool}>
     */
    public function resolve(array $plannedTopics, array $sourceElements): array
    {
        $sections = [];

        foreach (array_values($plannedTopics) as $sectionIndex => $plannedTopic) {
            $plannedTopic = trim($plannedTopic);
            $matches = $this->matchingElements($plannedTopic, $sourceElements);
            $evidenceChars = array_sum(array_map(
                static fn (array $element): int => mb_strlen((string) ($element['reference_text'] ?? $element['display_text'] ?? '')),
                $matches,
            ));

            $sections[] = [
                'section_index' => $sectionIndex,
                'planned_topic' => $plannedTopic,
                'required_heading' => $plannedTopic,
                'section_purpose' => 'Explain this exact planned topic using only its assigned source evidence.',
                'source_element_keys' => array_values(array_filter(array_map(
                    static fn (array $element): string => trim((string) ($element['source_element_key'] ?? '')),
                    $matches,
                ))),
                'source_evidence' => $matches,
                'source_element_count' => count($matches),
                'evidence_char_count' => $evidenceChars,
                'required' => true,
            ];
        }

        return $sections;
    }

    /** @param list<array<string, mixed>> $sections @return list<string> */
    public function topicsWithoutEvidence(array $sections): array
    {
        return array_values(array_map(
            static fn (array $section): string => (string) $section['planned_topic'],
            array_filter($sections, fn (array $section): bool => ! $this->hasValidRequiredEvidence($section)),
        ));
    }

    /** @param list<array<string, mixed>> $sourceElements @return list<array<string, mixed>> */
    private function matchingElements(string $plannedTopic, array $sourceElements): array
    {
        $keywords = $this->keywords($plannedTopic);
        $scored = [];

        foreach ($sourceElements as $index => $element) {
            if (! is_array($element)) {
                continue;
            }

            $text = implode(' ', array_filter([
                (string) ($element['section_title'] ?? ''),
                (string) ($element['page_reference'] ?? ''),
                (string) ($element['reference_text'] ?? ''),
                (string) ($element['display_text'] ?? ''),
            ], static fn (string $value): bool => trim($value) !== ''));
            $normalizedText = mb_strtolower($text);
            $score = 0;

            foreach ($keywords as $keyword) {
                if (str_contains($normalizedText, $keyword)) {
                    $score++;
                }
            }

            $score += $this->documentMetadataMatchScore(mb_strtolower($plannedTopic), $normalizedText);

            if ($score > 0) {
                $scored[] = ['score' => $score, 'index' => $index, 'element' => $element];
            }
        }

        usort($scored, static fn (array $left, array $right): int => ($right['score'] <=> $left['score']) ?: ($left['index'] <=> $right['index']));

        $selected = [];
        $remainingChars = self::MAX_EVIDENCE_CHARS_PER_SECTION;

        foreach ($scored as $item) {
            if (count($selected) >= self::MAX_ELEMENTS_PER_SECTION || $remainingChars <= 0) {
                break;
            }

            $element = $item['element'];
            $referenceText = (string) ($element['reference_text'] ?? $element['display_text'] ?? '');
            $element['reference_text'] = mb_substr($referenceText, 0, $remainingChars);
            $element['display_text'] = mb_substr((string) ($element['display_text'] ?? $referenceText), 0, min(700, $remainingChars));
            $remainingChars -= mb_strlen($element['reference_text']);
            $selected[] = $element;
        }

        // Non-DOCX imports deliberately expose one whole-document manual element rather than a
        // fabricated paragraph structure. It is still genuine source evidence and is the only
        // authoritative binding available for such a document.
        if ($selected === [] && count($sourceElements) === 1) {
            $element = $sourceElements[0];
            $key = trim((string) ($element['source_element_key'] ?? ''));
            $text = trim((string) ($element['reference_text'] ?? $element['display_text'] ?? ''));

            if ($key !== '' && $text !== '' && ($element['source_element_type'] ?? null) === 'manual') {
                $element['reference_text'] = mb_substr($text, 0, self::MAX_EVIDENCE_CHARS_PER_SECTION);
                $element['display_text'] = mb_substr((string) ($element['display_text'] ?? $text), 0, 700);
                $selected[] = $element;
            }
        }

        return $selected;
    }

    /**
     * Matches only explicit document-control aliases. This deliberately does not attempt fuzzy
     * or semantic matching: the source label and planned field must be a known deterministic pair.
     */
    private function documentMetadataMatchScore(string $normalizedTopic, string $normalizedSource): int
    {
        $score = 0;

        foreach (self::DOCUMENT_METADATA_BINDINGS as $binding) {
            $topicMatches = collect($binding['topic_terms'])
                ->contains(fn (string $term): bool => str_contains($normalizedTopic, $term));
            $sourceMatches = collect($binding['source_terms'])
                ->contains(fn (string $term): bool => str_contains($normalizedSource, $term));

            if ($topicMatches && $sourceMatches) {
                $score++;
            }
        }

        return $score;
    }

    /** @param array<string, mixed> $section */
    private function hasValidRequiredEvidence(array $section): bool
    {
        if (($section['required'] ?? false) !== true) {
            return true;
        }

        $keys = array_values(array_filter(
            (array) ($section['source_element_keys'] ?? []),
            static fn (mixed $key): bool => is_string($key) && trim($key) !== '',
        ));
        $evidence = array_values(array_filter(
            (array) ($section['source_evidence'] ?? []),
            static fn (mixed $element): bool => is_array($element)
                && trim((string) ($element['source_element_key'] ?? '')) !== ''
                && trim((string) ($element['reference_text'] ?? $element['display_text'] ?? '')) !== '',
        ));

        return $keys !== []
            && count($keys) === count($evidence)
            && (int) ($section['source_element_count'] ?? 0) === count($evidence)
            && (int) ($section['evidence_char_count'] ?? 0) > 0;
    }

    /** @return list<string> */
    private function keywords(string $plannedTopic): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($plannedTopic), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter($words, static fn (string $word): bool => mb_strlen($word) >= 4 && ! in_array($word, self::STOPWORDS, true))));
    }
}
