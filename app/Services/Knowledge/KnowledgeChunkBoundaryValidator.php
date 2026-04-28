<?php

namespace App\Services\Knowledge;

class KnowledgeChunkBoundaryValidator
{
    private const PREFERRED_MIN_WORDS = 100;

    private const PREFERRED_TARGET_WORDS = 300;

    private const PREFERRED_MAX_WORDS = 700;

    private const ABSOLUTE_MAX_WORDS = 1200;

    /**
     * Purpose: Validate AI boundary suggestions and produce final chunk plans.
     * Inputs: The canonical source structure and the raw analysis groups from the boundary service.
     * Returns: Ordered, validated chunk plans that can be persisted safely.
     * Side effects: None.
     *
     * @param array{source_text: string, elements: array<int, array<string, mixed>>} $structure
     * @param array<int, array<string, mixed>> $analysisGroups
     * @return array<int, array<string, mixed>>
     */
    public function validate(array $structure, array $analysisGroups): array
    {
        $sourceText = trim((string) data_get($structure, 'source_text', ''));
        $elements = array_values(array_filter(
            (array) data_get($structure, 'elements', []),
            static fn ($element): bool => is_array($element),
        ));

        if ($sourceText === '' || $elements === []) {
            return [];
        }

        $validated = [];

        foreach ($analysisGroups as $group) {
            if (! is_array($group)) {
                continue;
            }

            $groupPlans = $this->validateGroup($sourceText, $elements, $group);

            foreach ($groupPlans as $plan) {
                $validated[] = $plan;
            }
        }

        if ($validated === []) {
            return $this->packGroupDeterministically($sourceText, 0, mb_strlen($sourceText, 'UTF-8'), $elements, 0);
        }

        usort(
            $validated,
            static function (array $left, array $right): int {
                if ($left['start_offset'] !== $right['start_offset']) {
                    return $left['start_offset'] <=> $right['start_offset'];
                }

                if ($left['end_offset'] !== $right['end_offset']) {
                    return $left['end_offset'] <=> $right['end_offset'];
                }

                return $left['order_index'] <=> $right['order_index'];
            },
        );

        return array_values($validated);
    }

    /**
     * Purpose: Validate one analysis group into chunk plans.
     * Inputs: The canonical source text, the global element list, and one AI analysis group.
     * Returns: One or more validated chunk plans for that group.
     * Side effects: None.
     *
     * @param array<int, array<string, mixed>> $elements
     * @return array<int, array<string, mixed>>
     */
    private function validateGroup(string $sourceText, array $elements, array $group): array
    {
        $groupStart = (int) data_get($group, 'start_offset', 0);
        $groupEnd = (int) data_get($group, 'end_offset', $groupStart);
        $groupText = trim((string) data_get($group, 'text', ''));
        $groupElements = array_values(array_filter(
            (array) data_get($group, 'elements', []),
            static fn ($element): bool => is_array($element),
        ));
        $suggestions = array_values(array_filter(
            (array) data_get($group, 'suggested_chunks', []),
            static fn ($suggestion): bool => is_array($suggestion),
        ));

        if ($groupText === '' || $groupElements === []) {
            return [];
        }

        $normalized = $this->normalizeSuggestions($sourceText, $groupStart, $groupEnd, $groupElements, $suggestions);

        if ($normalized !== [] && $this->hasValidCoverage($groupStart, $groupEnd, $normalized)) {
            return $normalized;
        }

        return $this->packGroupDeterministically(
            $sourceText,
            $groupStart,
            $groupEnd,
            $groupElements,
            (int) data_get($group, 'group_index', 0),
        );
    }

    /**
     * Purpose: Convert AI suggestions into validated chunk plans aligned to element boundaries.
     * Inputs: The canonical source text, the group boundaries, and the group elements.
     * Returns: A normalized list of chunk plans or an empty array when the suggestions are unsafe.
     * Side effects: None.
     *
     * @param array<int, array<string, mixed>> $groupElements
     * @param array<int, array<string, mixed>> $suggestions
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSuggestions(
        string $sourceText,
        int $groupStart,
        int $groupEnd,
        array $groupElements,
        array $suggestions,
    ): array {
        $plans = [];

        foreach ($suggestions as $index => $suggestion) {
            $relativeStart = (int) data_get($suggestion, 'start_offset_relative', -1);
            $relativeEnd = (int) data_get($suggestion, 'end_offset_relative', -1);

            if ($relativeStart < 0 || $relativeEnd <= $relativeStart) {
                return [];
            }

            $absoluteStart = $groupStart + $relativeStart;
            $absoluteEnd = $groupStart + $relativeEnd;

            if ($absoluteStart < $groupStart || $absoluteEnd > $groupEnd) {
                return [];
            }

            $alignedStart = $this->alignStartToElementBoundary($groupElements, $absoluteStart);
            $alignedEnd = $this->alignEndToElementBoundary($groupElements, $absoluteEnd);

            if ($alignedStart === null || $alignedEnd === null || $alignedEnd <= $alignedStart) {
                return [];
            }

            $text = trim((string) mb_substr($sourceText, $alignedStart, $alignedEnd - $alignedStart, 'UTF-8'));
            $wordCount = $this->wordCount($text);

            if ($wordCount > self::ABSOLUTE_MAX_WORDS) {
                return [];
            }

            $chunkElements = $this->elementsInRange($groupElements, $alignedStart, $alignedEnd);

            if ($chunkElements === []) {
                return [];
            }

            $headingPath = $this->chunkHeadingPath($chunkElements);
            $sectionTitle = $this->chunkSectionTitle($chunkElements, $headingPath);

            $plans[] = [
                'start_offset' => $alignedStart,
                'end_offset' => $alignedEnd,
                'order_index' => $index,
                'chunk_type' => $this->chunkTypeForElements($chunkElements),
                'heading_path' => $headingPath,
                'section_title' => $sectionTitle,
                'section_path' => $headingPath,
                'title' => $sectionTitle,
                'topic' => $this->normalizeNullableString(data_get($suggestion, 'topic')),
                'sub_topic' => $this->normalizeNullableString(data_get($suggestion, 'sub_topic')),
                'keywords' => $this->normalizeKeywords(data_get($suggestion, 'keywords')),
                'short_reason' => $this->normalizeNullableString(data_get($suggestion, 'short_reason')),
            ];
        }

        if ($plans === []) {
            return [];
        }

        usort(
            $plans,
            static function (array $left, array $right): int {
                if ($left['start_offset'] !== $right['start_offset']) {
                    return $left['start_offset'] <=> $right['start_offset'];
                }

                return $left['end_offset'] <=> $right['end_offset'];
            },
        );

        for ($index = 1, $count = count($plans); $index < $count; $index++) {
            if ($plans[$index]['start_offset'] < $plans[$index - 1]['end_offset']) {
                return [];
            }
        }

        return array_values($plans);
    }

    /**
     * Purpose: Determine whether the validated chunk plans fully cover the analysis group.
     * Inputs: The group boundaries and the normalized chunk plans.
     * Returns: True when the chunk plans are contiguous and cover the group without gaps.
     * Side effects: None.
     *
     * @param array<int, array<string, mixed>> $plans
     */
    private function hasValidCoverage(int $groupStart, int $groupEnd, array $plans): bool
    {
        if ($plans === []) {
            return false;
        }

        $first = $plans[0];
        $last = $plans[count($plans) - 1];

        if ((int) data_get($first, 'start_offset', -1) !== $groupStart) {
            return false;
        }

        if ((int) data_get($last, 'end_offset', -1) !== $groupEnd) {
            return false;
        }

        $previousEnd = $groupStart;

        foreach ($plans as $plan) {
            $start = (int) data_get($plan, 'start_offset', -1);
            $end = (int) data_get($plan, 'end_offset', -1);

            if ($start !== $previousEnd || $end <= $start) {
                return false;
            }

            $previousEnd = $end;
        }

        return true;
    }

    /**
     * Purpose: Fallback to deterministic element packing when AI suggestions are invalid.
     * Inputs: The canonical source text, group boundaries, and the group elements.
     * Returns: One or more safe chunk plans built from the original source text.
     * Side effects: None.
     *
     * @param array<int, array<string, mixed>> $groupElements
     * @return array<int, array<string, mixed>>
     */
    private function packGroupDeterministically(
        string $sourceText,
        int $groupStart,
        int $groupEnd,
        array $groupElements,
        int $groupIndex,
    ): array {
        $plans = [];
        $currentElements = [];
        $currentStart = null;
        $currentEnd = null;
        $currentWordCount = 0;

        foreach ($groupElements as $element) {
            $elementStart = (int) data_get($element, 'start_offset', 0);
            $elementEnd = (int) data_get($element, 'end_offset', $elementStart);
            $elementText = trim((string) data_get($element, 'text', ''));
            $elementWordCount = $this->wordCount($elementText);

            if ($elementText === '') {
                continue;
            }

            if ($currentElements !== []) {
                $candidateWordCount = $currentWordCount + $elementWordCount;

                if ($currentWordCount >= self::PREFERRED_TARGET_WORDS && $candidateWordCount > self::ABSOLUTE_MAX_WORDS) {
                    $plans[] = $this->buildPlanFromElements($sourceText, $currentElements, $currentStart, $currentEnd, count($plans) + $groupIndex);
                    $currentElements = [];
                    $currentStart = null;
                    $currentEnd = null;
                    $currentWordCount = 0;
                }
            }

            $currentElements[] = $element;
            $currentStart = $currentStart ?? $elementStart;
            $currentEnd = $elementEnd;
            $currentWordCount += $elementWordCount;
        }

        if ($currentElements !== []) {
            $plans[] = $this->buildPlanFromElements($sourceText, $currentElements, $currentStart, $currentEnd, count($plans) + $groupIndex);
        }

        if ($plans === []) {
            return [];
        }

        return array_values($plans);
    }

    /**
     * Purpose: Build one deterministic chunk plan from a contiguous element range.
     * Inputs: The source text, the element subset, and the absolute offsets of the plan.
     * Returns: A safe chunk plan ready for persistence.
     * Side effects: None.
     *
     * @param array<int, array<string, mixed>> $elements
     */
    private function buildPlanFromElements(
        string $sourceText,
        array $elements,
        ?int $startOffset,
        ?int $endOffset,
        int $orderIndex,
    ): array {
        $startOffset = $startOffset ?? 0;
        $endOffset = $endOffset ?? $startOffset;
        $text = trim((string) mb_substr($sourceText, $startOffset, max(0, $endOffset - $startOffset), 'UTF-8'));
        $headingPath = $this->chunkHeadingPath($elements);
        $sectionTitle = $this->chunkSectionTitle($elements, $headingPath);

        return [
            'start_offset' => $startOffset,
            'end_offset' => $endOffset,
            'order_index' => $orderIndex,
            'chunk_type' => $this->chunkTypeForElements($elements),
            'heading_path' => $headingPath,
            'section_title' => $sectionTitle,
            'section_path' => $headingPath,
            'title' => $sectionTitle,
            'topic' => null,
            'sub_topic' => null,
            'keywords' => [],
            'short_reason' => null,
            'content' => $text,
        ];
    }

    /**
     * Purpose: Locate the nearest valid element start boundary at or before the supplied offset.
     * Inputs: The group elements and a proposed absolute offset.
     * Returns: A safe start offset or null when no boundary exists.
     * Side effects: None.
     *
     * @param array<int, array<string, mixed>> $elements
     */
    private function alignStartToElementBoundary(array $elements, int $offset): ?int
    {
        $candidates = [];

        foreach ($elements as $element) {
            $start = (int) data_get($element, 'start_offset', 0);

            if ($start <= $offset) {
                $candidates[] = $start;
            }
        }

        return $candidates !== [] ? max($candidates) : null;
    }

    /**
     * Purpose: Locate the nearest valid element end boundary at or after the supplied offset.
     * Inputs: The group elements and a proposed absolute offset.
     * Returns: A safe end offset or null when no boundary exists.
     * Side effects: None.
     *
     * @param array<int, array<string, mixed>> $elements
     */
    private function alignEndToElementBoundary(array $elements, int $offset): ?int
    {
        $candidates = [];

        foreach ($elements as $element) {
            $end = (int) data_get($element, 'end_offset', 0);

            if ($end >= $offset) {
                $candidates[] = $end;
            }
        }

        return $candidates !== [] ? min($candidates) : null;
    }

    /**
     * Purpose: Collect all elements that overlap a proposed absolute range.
     * Inputs: The group elements and absolute start/end offsets.
     * Returns: The ordered element subset that falls within the proposed range.
     * Side effects: None.
     *
     * @param array<int, array<string, mixed>> $elements
     * @return array<int, array<string, mixed>>
     */
    private function elementsInRange(array $elements, int $startOffset, int $endOffset): array
    {
        $selected = [];

        foreach ($elements as $element) {
            $elementStart = (int) data_get($element, 'start_offset', 0);
            $elementEnd = (int) data_get($element, 'end_offset', $elementStart);

            if ($elementEnd <= $startOffset || $elementStart >= $endOffset) {
                continue;
            }

            $selected[] = $element;
        }

        return $selected;
    }

    /**
     * Purpose: Build a stable heading path from a chunk's element set.
     * Inputs: The chunk elements.
     * Returns: The best heading path for the final chunk.
     * Side effects: None.
     *
     * @param array<int, array<string, mixed>> $elements
     */
    private function chunkHeadingPath(array $elements): ?string
    {
        foreach ($elements as $element) {
            $headingPath = trim((string) data_get($element, 'heading_path', ''));

            if ($headingPath !== '') {
                return $headingPath;
            }
        }

        return null;
    }

    /**
     * Purpose: Build a display title for one chunk.
     * Inputs: The chunk elements and the derived heading path.
     * Returns: A compact chunk title or null when no heading context exists.
     * Side effects: None.
     *
     * @param array<int, array<string, mixed>> $elements
     */
    private function chunkSectionTitle(array $elements, ?string $headingPath): ?string
    {
        foreach ($elements as $element) {
            $type = (string) data_get($element, 'type', '');

            if ($type === 'heading') {
                $text = trim((string) data_get($element, 'text', ''));

                if ($text !== '') {
                    return $text;
                }
            }
        }

        if ($headingPath !== null && $headingPath !== '') {
            $parts = array_values(array_filter(array_map(
                static fn (string $part): string => trim($part),
                explode(' > ', $headingPath),
            ), static fn (string $part): bool => $part !== ''));

            return $parts !== [] ? (string) end($parts) : $headingPath;
        }

        return null;
    }

    /**
     * Purpose: Classify the dominant chunk type from a chunk's element set.
     * Inputs: The chunk elements.
     * Returns: A stable chunk type label.
     * Side effects: None.
     *
     * @param array<int, array<string, mixed>> $elements
     */
    private function chunkTypeForElements(array $elements): string
    {
        $types = array_values(array_filter(array_map(
            static fn (array $element): string => trim((string) data_get($element, 'type', '')),
            $elements,
        ), static fn (string $type): bool => $type !== ''));

        if ($types === []) {
            return 'other';
        }

        if (count(array_diff($types, ['table'])) === 0) {
            return 'table';
        }

        if (count(array_diff($types, ['list', 'heading'])) === 0 || count(array_diff($types, ['list'])) === 0) {
            return 'list';
        }

        return 'semantic';
    }

    /**
     * Purpose: Count the approximate number of words in a text block.
     * Inputs: Raw text.
     * Returns: The word count.
     * Side effects: None.
     */
    private function wordCount(string $text): int
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        if ($normalized === '') {
            return 0;
        }

        $parts = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($parts) ? count($parts) : 0;
    }

    /**
     * Purpose: Normalize an optional string into a trimmed nullable string.
     * Inputs: A raw scalar or null.
     * Returns: A trimmed string or null.
     * Side effects: None.
     */
    private function normalizeNullableString(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text !== '' ? $text : null;
    }

    /**
     * Purpose: Normalize a keyword input into a stable list of strings.
     * Inputs: A keyword array, comma-separated string, or null.
     * Returns: A de-duplicated keyword list.
     * Side effects: None.
     */
    private function normalizeKeywords(mixed $keywords): array
    {
        if (is_string($keywords)) {
            $keywords = preg_split('/[,\n;]+/u', $keywords, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        if (! is_array($keywords)) {
            return [];
        }

        $normalized = [];
        $seen = [];

        foreach ($keywords as $keyword) {
            $text = trim((string) $keyword);

            if ($text === '') {
                continue;
            }

            $key = mb_strtolower($text, 'UTF-8');

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = $text;
        }

        return $normalized;
    }
}
