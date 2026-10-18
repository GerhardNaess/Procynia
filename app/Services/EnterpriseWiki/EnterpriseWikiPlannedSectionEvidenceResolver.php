<?php

namespace App\Services\EnterpriseWiki;

/**
 * Creates the generation-time evidence contract for already planned sections. The maintainer
 * decision remains authoritative for section identity; this class only binds each identity to
 * existing extracted source elements before an AI call is made.
 *
 * TWO BINDING PATHS, and the difference between them is the whole point:
 *
 *  - PLANNER-BOUND (every decision made under the current contract): the owned topic names its own
 *    source_element_keys, validated at decision time by EnterpriseWikiPlannedTopicEvidenceValidator.
 *    Binding here is then a pure lookup — no scoring, no guessing, and the planner and this class
 *    use one and the same evidence identity.
 *  - LEGACY KEYWORD (a stored decision whose owned_topics are plain strings, predating the
 *    binding): the old literal keyword overlap, kept only so stored runs remain regenerable.
 *
 * The legacy path is what run 53 died on, and it shows exactly why guessing cannot be the contract:
 * "Budsjett og kostnadsrammer" scored zero against 515 real elements because the document says
 * "kostnadsramme" and the topic says "kostnadsrammer"; "Godkjenningstidspunkt" scored zero although
 * 75 elements contain "godkjenning", because a Norwegian compound is not a substring of its parts.
 * No amount of tuning fixes that class of failure — only the planner knows which elements it meant,
 * so the planner is now required to say so.
 */
class EnterpriseWikiPlannedSectionEvidenceResolver
{
    public const BINDING_PLANNER = 'planner_bound';

    public const BINDING_LEGACY_KEYWORD = 'legacy_keyword';

    /**
     * How many source elements a generated section is ever built from. Public because it is also the
     * cap the planner is held to: a decision that names more evidence keys for one owned topic than
     * this is naming keys nothing downstream can use — see
     * EnterpriseWikiMaintainerDecisionPrompt::MAX_OWNED_TOPIC_EVIDENCE_KEYS.
     */
    public const MAX_ELEMENTS_PER_SECTION = 6;

    /** Public because the whole-document element of an unstructured source is sized to it. */
    public const MAX_EVIDENCE_CHARS_PER_SECTION = 3600;

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
     * @param  list<string|array{topic: string, source_element_keys: list<string>}>  $plannedTopics  Either
     *                                                                                               bound owned-topic entries (current contract) or plain strings (legacy) — normalise with
     *                                                                                               EnterpriseWikiMaintainerDecisionPrompt::ownedTopicEntries() before calling.
     * @param  list<array<string, mixed>>  $sourceElements
     * @return list<array{section_index: int, planned_topic: string, required_heading: string, section_purpose: string, source_element_keys: list<string>, source_evidence: list<array<string, mixed>>, source_element_count: int, evidence_char_count: int, required: bool, evidence_binding: string}>
     */
    public function resolve(array $plannedTopics, array $sourceElements): array
    {
        $elementsByKey = $this->elementsByKey($sourceElements);
        $sections = [];

        foreach (array_values($plannedTopics) as $sectionIndex => $plannedTopic) {
            [$topic, $plannedKeys] = $this->normalizeTopic($plannedTopic);

            if ($topic === '') {
                continue;
            }

            $binding = $plannedKeys !== [] ? self::BINDING_PLANNER : self::BINDING_LEGACY_KEYWORD;
            $omittedKeys = [];

            if ($plannedKeys !== []) {
                $bound = $this->bindPlannedElements($plannedKeys, $sourceElements);
                $matches = $bound['elements'];
                $omittedKeys = $bound['omitted_keys'];
            } else {
                $matches = $this->matchingElements($topic, $sourceElements);
            }
            $evidenceChars = array_sum(array_map(
                static fn (array $element): int => mb_strlen((string) ($element['reference_text'] ?? $element['display_text'] ?? '')),
                $matches,
            ));

            $sections[] = [
                'section_index' => $sectionIndex,
                'planned_topic' => $topic,
                'required_heading' => $topic,
                'section_purpose' => 'Explain this exact planned topic using only its assigned source evidence.',
                'source_element_keys' => array_values(array_filter(array_map(
                    static fn (array $element): string => trim((string) ($element['source_element_key'] ?? '')),
                    $matches,
                ))),
                'source_evidence' => $matches,
                'source_element_count' => count($matches),
                'evidence_char_count' => $evidenceChars,
                'required' => true,
                'evidence_binding' => $binding,
                // Plan §18 (P0-1): what this section was meant to carry but could not — never
                // cut silently. EnterpriseWikiSourceCoverageValidator counts these as uncovered.
                'omitted_source_element_keys' => $omittedKeys,
            ];
        }

        return $sections;
    }

    /**
     * @param  string|array<string, mixed>  $plannedTopic
     * @return array{0: string, 1: list<string>}
     */
    private function normalizeTopic(mixed $plannedTopic): array
    {
        if (is_string($plannedTopic)) {
            return [trim($plannedTopic), []];
        }

        if (! is_array($plannedTopic)) {
            return ['', []];
        }

        return [
            trim((string) ($plannedTopic['topic'] ?? '')),
            array_values(array_filter(array_map(
                static fn (mixed $key): string => is_string($key) ? trim($key) : '',
                (array) ($plannedTopic['source_element_keys'] ?? []),
            ), static fn (string $key): bool => $key !== '')),
        ];
    }

    /**
     * A pure lookup of the keys the planner itself assigned to this topic, in the planner's own
     * order, bounded by the same per-section limits the keyword path uses.
     *
     * A key that resolves to nothing is silently absent rather than fabricated: the section then has
     * fewer (or zero) elements, and zero is what topicsWithoutEvidence() fails the run on. Decision-
     * time validation already rejects keys outside the catalog, so reaching this with an unresolvable
     * key means the document itself changed under the decision — which must fail, not degrade.
     *
     * Plan §18 (P0-1): a list belongs to the paragraph that introduces it. Run 962 planned
     * "Følgende kriterier anbefales som utløsende:" without its five criteria, so the page said
     * "three sources" and "three types" without naming them. A planned lead-in paragraph (one that
     * ends with ":") now brings the list items that directly follow it in the same section, and a
     * planned list item brings its lead-in, which carries the item's normative context. These
     * companions do not count against MAX_ELEMENTS_PER_SECTION — the planner named one unit — but
     * they do count against the character budget, and a companion that does not fit whole is
     * reported in omitted_keys rather than cut. So is every planned key past either limit.
     *
     * Public so EnterpriseWikiSourceCoverageValidator measures coverage on exactly what generation
     * will receive.
     *
     * @param  list<string>  $plannedKeys
     * @param  list<array<string, mixed>>  $sourceElements  in document order
     * @return array{elements: list<array<string, mixed>>, omitted_keys: list<string>}
     */
    public function bindPlannedElements(array $plannedKeys, array $sourceElements): array
    {
        $ordered = array_values(array_filter(
            $sourceElements,
            static fn (mixed $element): bool => is_array($element) && trim((string) ($element['source_element_key'] ?? '')) !== '',
        ));
        $positionByKey = [];

        foreach ($ordered as $position => $element) {
            $positionByKey[trim((string) $element['source_element_key'])] = $position;
        }

        $selected = [];
        $selectedKeys = [];
        $omitted = [];
        $plannerCount = 0;
        $remainingChars = self::MAX_EVIDENCE_CHARS_PER_SECTION;

        foreach (array_values(array_unique($plannedKeys)) as $key) {
            $position = $positionByKey[$key] ?? null;

            if ($position === null || isset($selectedKeys[$key])) {
                continue;
            }

            if ($plannerCount >= self::MAX_ELEMENTS_PER_SECTION || $remainingChars <= 0) {
                $omitted[] = $key;

                continue;
            }

            $referenceText = trim((string) ($ordered[$position]['reference_text'] ?? $ordered[$position]['display_text'] ?? ''));

            if ($referenceText === '') {
                continue;
            }

            $leadIn = $this->leadInPosition($ordered, $position);

            if ($leadIn !== null) {
                $this->addCompanion($ordered[$leadIn], $selected, $selectedKeys, $omitted, $remainingChars);
            }

            $element = $ordered[$position];
            $element['reference_text'] = mb_substr($referenceText, 0, $remainingChars);
            $element['display_text'] = mb_substr((string) ($element['display_text'] ?? $referenceText), 0, min(700, $remainingChars));
            $remainingChars -= mb_strlen($element['reference_text']);
            $selected[] = $element;
            $selectedKeys[$key] = true;
            $plannerCount++;

            foreach ($this->listItemPositions($ordered, $position) as $itemPosition) {
                $this->addCompanion($ordered[$itemPosition], $selected, $selectedKeys, $omitted, $remainingChars);
            }
        }

        return [
            'elements' => $selected,
            'omitted_keys' => array_values(array_unique(array_diff($omitted, array_keys($selectedKeys)))),
        ];
    }

    /**
     * @param  array<string, mixed>  $element
     * @param  list<array<string, mixed>>  $selected
     * @param  array<string, true>  $selectedKeys
     * @param  list<string>  $omitted
     */
    private function addCompanion(array $element, array &$selected, array &$selectedKeys, array &$omitted, int &$remainingChars): void
    {
        $key = trim((string) $element['source_element_key']);

        if (isset($selectedKeys[$key])) {
            return;
        }

        $text = trim((string) ($element['reference_text'] ?? $element['display_text'] ?? ''));

        if ($text === '') {
            return;
        }

        if (mb_strlen($text) > $remainingChars) {
            $omitted[] = $key;

            return;
        }

        $element['reference_text'] = $text;
        $element['display_text'] = mb_substr((string) ($element['display_text'] ?? $text), 0, 700);
        $remainingChars -= mb_strlen($text);
        $selected[] = $element;
        $selectedKeys[$key] = true;
    }

    /**
     * The list items directly following a lead-in paragraph ("...:") in the same section.
     *
     * @param  list<array<string, mixed>>  $ordered
     * @return list<int>
     */
    public function listItemPositions(array $ordered, int $position): array
    {
        if (! self::isLeadIn($ordered[$position])) {
            return [];
        }

        $positions = [];

        for ($next = $position + 1; $next < count($ordered); $next++) {
            if (! self::isListItem($ordered[$next]) || ! self::sameSection($ordered[$position], $ordered[$next])) {
                break;
            }

            $positions[] = $next;
        }

        return $positions;
    }

    /**
     * The lead-in paragraph of a list item, if the list it belongs to has one.
     *
     * @param  list<array<string, mixed>>  $ordered
     */
    public function leadInPosition(array $ordered, int $position): ?int
    {
        if (! self::isListItem($ordered[$position])) {
            return null;
        }

        for ($previous = $position - 1; $previous >= 0; $previous--) {
            if (self::isListItem($ordered[$previous]) && self::sameSection($ordered[$previous], $ordered[$position])) {
                continue;
            }

            return self::isLeadIn($ordered[$previous]) && self::sameSection($ordered[$previous], $ordered[$position])
                ? $previous
                : null;
        }

        return null;
    }

    /** @param array<string, mixed> $element */
    public static function isLeadIn(array $element): bool
    {
        return ($element['source_element_type'] ?? null) === 'paragraph'
            && str_ends_with(rtrim((string) ($element['reference_text'] ?? $element['display_text'] ?? '')), ':');
    }

    /** @param array<string, mixed> $element */
    public static function isListItem(array $element): bool
    {
        return in_array($element['source_element_type'] ?? null, ['list_item', 'listitem'], true);
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private static function sameSection(array $a, array $b): bool
    {
        return EnterpriseWikiDocumentSectionMap::sectionLabel($a) === EnterpriseWikiDocumentSectionMap::sectionLabel($b);
    }

    /**
     * @param  list<array<string, mixed>>  $sourceElements
     * @return array<string, array<string, mixed>>
     */
    private function elementsByKey(array $sourceElements): array
    {
        $byKey = [];

        foreach ($sourceElements as $element) {
            if (! is_array($element)) {
                continue;
            }

            $key = trim((string) ($element['source_element_key'] ?? ''));

            if ($key !== '') {
                $byKey[$key] = $element;
            }
        }

        return $byKey;
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
