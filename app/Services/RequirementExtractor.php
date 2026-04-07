<?php

namespace App\Services;

use Throwable;

class RequirementExtractor
{
    private const MIN_CANDIDATE_CHAR_LENGTH = 12;

    private const MIN_CANDIDATE_WORD_COUNT = 2;

    /**
     * Purpose: Extract simple rule-based requirement candidates from a chunk of text.
     * Inputs: Raw chunk text from an AI document chunk.
     * Returns: A list of normalized requirement candidate payloads.
     * Side effects: None.
     */
    public function extractFromChunk(string $chunkText): array
    {
        try {
            $normalizedText = $this->normalizeText($chunkText);

            if ($normalizedText === '') {
                return [];
            }

            $candidates = [];

            foreach ($this->splitIntoSegments($normalizedText) as $segment) {
                $candidateText = $this->normalizeCandidateText($segment);

                if (! $this->isMeaningfulCandidate($candidateText)) {
                    continue;
                }

                if (! $this->containsRequirementSignal($candidateText)) {
                    continue;
                }

                $dedupeKey = $this->buildDeduplicationKey($candidateText);

                if ($dedupeKey === '' || isset($candidates[$dedupeKey])) {
                    continue;
                }

                $candidates[$dedupeKey] = [
                    'requirement_text' => $candidateText,
                    'requirement_type' => $this->classifyRequirementType($candidateText),
                    'extraction_method' => 'rule_based',
                    'review_status' => 'pending',
                ];
            }

            return array_values($candidates);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Purpose: Normalize chunk text before rule evaluation.
     * Inputs: Raw extracted chunk text.
     * Returns: A lightly normalized string that preserves meaning.
     * Side effects: None.
     */
    private function normalizeText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text);
        $text = preg_replace('/\n{3,}/u', "\n\n", $text);

        return trim((string) $text);
    }

    /**
     * Purpose: Split the normalized document text into sentence-like segments.
     * Inputs: Lightly normalized extracted chunk text.
     * Returns: An ordered list of candidate text segments.
     * Side effects: None.
     */
    private function splitIntoSegments(string $text): array
    {
        $lines = preg_split('/\n+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $segments = [];

        foreach ($lines as $line) {
            $lineParts = preg_split('/(?<=[\p{Ll}\d])\s+(?=[A-ZÆØÅ])/u', (string) $line, -1, PREG_SPLIT_NO_EMPTY) ?: [(string) $line];

            foreach ($lineParts as $linePart) {
                $parts = preg_split('/(?<=[.!?;])\s+/u', (string) $linePart, -1, PREG_SPLIT_NO_EMPTY) ?: [(string) $linePart];

                foreach ($parts as $part) {
                    $candidate = $this->normalizeCandidateText((string) $part);

                    if ($candidate !== '') {
                        $segments[] = $candidate;
                    }
                }
            }
        }

        return $segments;
    }

    /**
     * Purpose: Normalize a single candidate segment before signal evaluation.
     * Inputs: Raw sentence-like text.
     * Returns: Cleaned candidate text.
     * Side effects: None.
     */
    private function normalizeCandidateText(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/^[\-\*\•\d\.\)\(\s]+/u', '', $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text);
        $text = preg_replace('/\s*[\-\–\—]+\s*$/u', '', $text);

        return trim((string) $text);
    }

    /**
     * Purpose: Determine whether a candidate has enough substance to be treated as a requirement.
     * Inputs: A normalized candidate text segment.
     * Returns: True when the segment is long enough and not merely a decorative heading.
     * Side effects: None.
     */
    private function isMeaningfulCandidate(string $text): bool
    {
        if ($text === '') {
            return false;
        }

        if (mb_strlen($text, 'UTF-8') < self::MIN_CANDIDATE_CHAR_LENGTH) {
            return false;
        }

        if ($this->wordCount($text) < self::MIN_CANDIDATE_WORD_COUNT) {
            return false;
        }

        return ! $this->looksLikeHeading($text);
    }

    /**
     * Purpose: Determine whether a candidate looks like a decorative heading rather than a requirement.
     * Inputs: A normalized candidate text segment.
     * Returns: True when the text should be ignored as heading-like noise.
     * Side effects: None.
     */
    private function looksLikeHeading(string $text): bool
    {
        if (preg_match('/[.!?;]/u', $text) === 1) {
            return false;
        }

        $wordCount = $this->wordCount($text);

        if ($wordCount > 4) {
            return false;
        }

        $normalized = mb_strtolower($text, 'UTF-8');

        if (
            preg_match('/\b(?:skal|må|obligatorisk|det kreves|må dokumenteres|skal dokumenteres|dokumenteres)\b/u', $normalized) === 1
        ) {
            return false;
        }

        if (
            preg_match('/\b(?:tilbudsfrist|frist|leveres|innen)\b/u', $normalized) === 1
            && preg_match('/\d/u', $normalized) === 1
        ) {
            return false;
        }

        return true;
    }

    /**
     * Purpose: Determine whether a sentence contains a requirement signal.
     * Inputs: A normalized sentence candidate.
     * Returns: True when the sentence looks like a requirement candidate.
     * Side effects: None.
     */
    private function containsRequirementSignal(string $text): bool
    {
        $normalized = mb_strtolower($text, 'UTF-8');

        return $this->containsDocumentationSignal($normalized)
            || $this->containsAdministrativeSignal($normalized)
            || $this->containsMandatorySignal($normalized);
    }

    /**
     * Purpose: Classify a requirement candidate using the canonical rule priority.
     * Inputs: A normalized sentence-like requirement candidate.
     * Returns: One canonical requirement type key.
     * Side effects: None.
     */
    private function classifyRequirementType(string $text): string
    {
        $normalized = mb_strtolower($text, 'UTF-8');

        if ($this->containsDocumentationSignal($normalized)) {
            return 'documentation';
        }

        if ($this->containsAdministrativeSignal($normalized)) {
            return 'administrative';
        }

        if ($this->containsMandatorySignal($normalized)) {
            return 'mandatory';
        }

        return 'unspecified';
    }

    /**
     * Purpose: Determine whether the text contains a documentation-style requirement signal.
     * Inputs: A lower-cased candidate segment.
     * Returns: True when the text indicates documentation or documentation evidence.
     * Side effects: None.
     */
    private function containsDocumentationSignal(string $normalized): bool
    {
        return preg_match('/\bmå dokumenteres\b/u', $normalized) === 1
            || preg_match('/\bskal dokumenteres\b/u', $normalized) === 1
            || preg_match('/\bdokumentasjon\b/u', $normalized) === 1
            || preg_match('/\bdokumenteres\b/u', $normalized) === 1;
    }

    /**
     * Purpose: Determine whether the text contains an administrative requirement signal.
     * Inputs: A lower-cased candidate segment.
     * Returns: True when the text points to a deadline, delivery, or submission rule.
     * Side effects: None.
     */
    private function containsAdministrativeSignal(string $normalized): bool
    {
        return preg_match('/\btilbudsfrist\b/u', $normalized) === 1
            || preg_match('/\bfrist\b/u', $normalized) === 1
            || preg_match('/\b(?:skal|må)\s+leveres\b/u', $normalized) === 1
            || preg_match('/\bleveres\s+innen\b/u', $normalized) === 1
            || preg_match('/\binnen\b/u', $normalized) === 1;
    }

    /**
     * Purpose: Determine whether the text contains a mandatory requirement signal.
     * Inputs: A lower-cased candidate segment.
     * Returns: True when the text indicates a mandatory action or obligation.
     * Side effects: None.
     */
    private function containsMandatorySignal(string $normalized): bool
    {
        return preg_match('/\bskal\b/u', $normalized) === 1
            || preg_match('/\bmå\b/u', $normalized) === 1
            || preg_match('/\bobligatorisk\b/u', $normalized) === 1
            || preg_match('/\bdet kreves\b/u', $normalized) === 1
            || preg_match('/\bkrav til\b/u', $normalized) === 1;
    }

    /**
     * Purpose: Build a canonical deduplication key for a candidate segment.
     * Inputs: A normalized candidate text segment.
     * Returns: A stable key that ignores punctuation-only differences.
     * Side effects: None.
     */
    private function buildDeduplicationKey(string $text): string
    {
        $normalized = mb_strtolower($text, 'UTF-8');
        $normalized = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $normalized);
        $normalized = preg_replace('/\s+/u', ' ', $normalized);

        return trim((string) $normalized);
    }

    /**
     * Purpose: Count words in a normalized requirement candidate.
     * Inputs: A normalized candidate text segment.
     * Returns: The number of whitespace-delimited words.
     * Side effects: None.
     */
    private function wordCount(string $text): int
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return count($words);
    }
}
