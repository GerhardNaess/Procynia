<?php

namespace App\Services\Ai\Knowledge;

use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemChunk;
use Illuminate\Support\Str;

class KnowledgeChunkMetadataValidator
{
    private const AUTO_APPROVE_CONFIDENCE_THRESHOLD = 0.80;

    public function __construct(
        private readonly KnowledgeMetadataVocabularyService $vocabularyService,
    ) {
    }

    /**
     * Purpose: Validate and normalize one AI-produced metadata payload before persistence.
     * Inputs: The document, the chunk, the raw AI payload, and the approved vocabulary map.
     * Returns: A normalized metadata payload with a final status and suggestion list.
     * Side effects: None.
     */
    public function validate(
        KnowledgeItem $document,
        KnowledgeItemChunk $chunk,
        array $rawPayload,
        array $vocabularyMap,
    ): array {
        $chunkContent = trim((string) $chunk->content);

        $serviceProductTag = $this->normalizeScalarField(
            field: 'service_product_tag',
            rawValue: data_get($rawPayload, 'service_product_tag'),
            vocabularyMap: $vocabularyMap,
        );
        $themeTag = $this->normalizeScalarField(
            field: 'theme_tag',
            rawValue: data_get($rawPayload, 'theme_tag'),
            vocabularyMap: $vocabularyMap,
        );
        $topic = $this->normalizeScalarField(
            field: 'topic',
            rawValue: data_get($rawPayload, 'topic'),
            vocabularyMap: $vocabularyMap,
        );
        $subTopic = $this->normalizeScalarField(
            field: 'sub_topic',
            rawValue: data_get($rawPayload, 'sub_topic'),
            vocabularyMap: $vocabularyMap,
        );
        $keywords = $this->normalizeKeywordList(data_get($rawPayload, 'keywords'), $vocabularyMap);
        $matchedTerms = $this->normalizeMatchedTerms(data_get($rawPayload, 'matched_terms'), $chunkContent, $vocabularyMap);
        $summaryForRetrieval = $this->normalizeSummary(data_get($rawPayload, 'summary_for_retrieval'), $chunkContent);
        $confidenceScore = $this->normalizeConfidenceScore(data_get($rawPayload, 'confidence_score'));
        $suggestions = $this->normalizeSuggestions(data_get($rawPayload, 'new_term_suggestions'), $chunkContent, $vocabularyMap, [
            'service_product_tag' => $serviceProductTag,
            'theme_tag' => $themeTag,
            'topic' => $topic,
            'sub_topic' => $subTopic,
            'keywords' => $keywords,
        ]);

        $centralFieldValues = [
            'service_product_tag' => $serviceProductTag,
            'theme_tag' => $themeTag,
            'topic' => $topic,
            'sub_topic' => $subTopic,
        ];

        $hasMissingCentralField = collect($centralFieldValues)
            ->contains(static fn (mixed $value): bool => ! is_string($value) || trim($value) === '');

        $hasSuggestedValues = $suggestions !== [];
        $hasApprovedKeyword = $keywords !== [];
        $hasHighConfidence = $confidenceScore >= self::AUTO_APPROVE_CONFIDENCE_THRESHOLD;
        $allCentralFieldsApproved = ! $hasMissingCentralField
            && $this->allCentralFieldsApproved($centralFieldValues, $vocabularyMap);

        $metadataStatus = KnowledgeItemChunk::METADATA_STATUS_PENDING_REVIEW;

        if ($allCentralFieldsApproved && $hasHighConfidence && ! $hasSuggestedValues && $hasApprovedKeyword) {
            $metadataStatus = KnowledgeItemChunk::METADATA_STATUS_AUTO_APPROVED;
        } elseif ($summaryForRetrieval === '') {
            $metadataStatus = KnowledgeItemChunk::METADATA_STATUS_FAILED;
        }

        return [
            'service_product_tag' => $serviceProductTag,
            'theme_tag' => $themeTag,
            'topic' => $topic,
            'sub_topic' => $subTopic,
            'keywords' => $keywords,
            'matched_terms' => $matchedTerms,
            'summary_for_retrieval' => $summaryForRetrieval,
            'confidence_score' => $confidenceScore,
            'metadata_status' => $metadataStatus,
            'new_term_suggestions' => $suggestions,
            'approved_central_fields' => $allCentralFieldsApproved ? array_keys(array_filter($centralFieldValues, static fn (mixed $value): bool => is_string($value) && trim($value) !== '')) : [],
            'pending_review' => $metadataStatus === KnowledgeItemChunk::METADATA_STATUS_PENDING_REVIEW,
        ];
    }

    /**
     * Purpose: Validate and normalize one scalar metadata field.
     * Inputs: The field name, raw value, and approved vocabulary map.
     * Returns: A trimmed canonical or original value, or null when empty.
     * Side effects: None.
     */
    private function normalizeScalarField(string $field, mixed $rawValue, array $vocabularyMap): ?string
    {
        $cleanValue = trim((string) ($rawValue ?? ''));

        if ($cleanValue === '') {
            return null;
        }

        $canonicalValue = $this->vocabularyService->resolveCanonicalValue($vocabularyMap, $field, $cleanValue);

        return $canonicalValue ?? $cleanValue;
    }

    /**
     * Purpose: Normalize and validate the AI keyword list.
     * Inputs: Raw keyword data and the approved vocabulary map.
     * Returns: A stable keyword list capped to a reasonable size.
     * Side effects: None.
     */
    private function normalizeKeywordList(mixed $rawValue, array $vocabularyMap): array
    {
        $keywords = $this->splitList($rawValue);
        $normalized = [];
        $seen = [];

        foreach ($keywords as $keyword) {
            $canonical = $this->vocabularyService->resolveCanonicalValue($vocabularyMap, 'keywords', $keyword);
            $value = $canonical ?? $keyword;
            $key = $this->comparisonKey($value);

            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = $value;
        }

        return array_slice($normalized, 0, 20);
    }

    /**
     * Purpose: Normalize and validate the AI matched terms.
     * Inputs: Raw matched-term data, the chunk content, and the approved vocabulary map.
     * Returns: A list of verified terms grounded in the chunk content or approved vocabulary.
     * Side effects: None.
     */
    private function normalizeMatchedTerms(mixed $rawValue, string $chunkContent, array $vocabularyMap): array
    {
        $terms = $this->splitList($rawValue);
        $normalized = [];
        $seen = [];

        foreach ($terms as $term) {
            $canonical = $this->vocabularyService->resolveCanonicalValue($vocabularyMap, 'keywords', $term)
                ?? $this->vocabularyService->resolveCanonicalValue($vocabularyMap, 'topic', $term)
                ?? $this->vocabularyService->resolveCanonicalValue($vocabularyMap, 'sub_topic', $term)
                ?? $this->vocabularyService->resolveCanonicalValue($vocabularyMap, 'service_product_tag', $term)
                ?? $this->vocabularyService->resolveCanonicalValue($vocabularyMap, 'theme_tag', $term);

            $candidate = $canonical ?? trim($term);

            if ($candidate === '') {
                continue;
            }

            if ($canonical === null && ! $this->chunkContainsTerm($chunkContent, $candidate)) {
                continue;
            }

            $key = $this->comparisonKey($candidate);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = $candidate;
        }

        return array_slice($normalized, 0, 20);
    }

    /**
     * Purpose: Validate and normalize the summary for retrieval.
     * Inputs: Raw summary text and the chunk content.
     * Returns: A short concrete summary string.
     * Side effects: None.
     */
    private function normalizeSummary(mixed $rawValue, string $chunkContent): string
    {
        $summary = trim((string) ($rawValue ?? ''));

        if ($summary === '') {
            $summary = $this->fallbackSummary($chunkContent);
        }

        return Str::limit(Str::squish($summary), 280, '...');
    }

    /**
     * Purpose: Validate and normalize the confidence score.
     * Inputs: Raw confidence data from the model.
     * Returns: A clamped floating-point confidence value.
     * Side effects: None.
     */
    private function normalizeConfidenceScore(mixed $rawValue): float
    {
        if (! is_numeric($rawValue)) {
            return 0.0;
        }

        $confidence = (float) $rawValue;

        if ($confidence < 0.0) {
            return 0.0;
        }

        if ($confidence > 1.0) {
            return 1.0;
        }

        return $confidence;
    }

    /**
     * Purpose: Normalize the AI suggestion list and add fallback suggestions for unknown values.
     * Inputs: Raw suggestions, the chunk content, the approved vocabulary map, and the normalized fields.
     * Returns: A stable list of suggestion rows suitable for persistence.
     * Side effects: None.
     */
    private function normalizeSuggestions(mixed $rawValue, string $chunkContent, array $vocabularyMap, array $normalizedFields): array
    {
        $suggestions = [];
        $seen = [];

        $addSuggestion = function (string $term, string $type, ?string $parent = null, ?string $reason = null) use (&$suggestions, &$seen, $vocabularyMap): void {
            $cleanTerm = trim($term);
            $cleanType = trim($type);

            if ($cleanTerm === '' || $cleanType === '') {
                return;
            }

            if ($this->suggestionMatchesApprovedVocabulary($vocabularyMap, $cleanType, $cleanTerm)) {
                return;
            }

            $key = $this->comparisonKey($cleanType.'|'.$cleanTerm.'|'.($parent !== null ? trim($parent) : ''));

            if (isset($seen[$key])) {
                return;
            }

            $seen[$key] = true;
            $suggestions[] = [
                'suggested_term' => $cleanTerm,
                'suggested_type' => $cleanType,
                'suggested_canonical_parent' => $parent !== null && trim($parent) !== '' ? trim($parent) : null,
                'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
            ];
        };

        foreach ($this->splitSuggestionList($rawValue) as $suggestion) {
            $addSuggestion(
                (string) data_get($suggestion, 'suggested_term', ''),
                (string) data_get($suggestion, 'suggested_type', ''),
                data_get($suggestion, 'suggested_canonical_parent'),
                data_get($suggestion, 'reason'),
            );
        }

        foreach (['service_product_tag', 'theme_tag', 'topic', 'sub_topic'] as $field) {
            $value = trim((string) ($normalizedFields[$field] ?? ''));

            if ($value === '') {
                continue;
            }

            if ($this->vocabularyService->resolveCanonicalValue($vocabularyMap, $field, $value) !== null) {
                continue;
            }

            $addSuggestion(
                $value,
                $field,
                null,
                sprintf('Verdien for %s finnes ikke i godkjent vokabular.', $field),
            );
        }

        foreach ($normalizedFields['keywords'] ?? [] as $keyword) {
            if (! is_string($keyword) || trim($keyword) === '') {
                continue;
            }

            if ($this->vocabularyService->resolveCanonicalValue($vocabularyMap, 'keywords', $keyword) !== null) {
                continue;
            }

            $addSuggestion(
                $keyword,
                'keyword',
                null,
                'Nøkkelordet finnes ikke i godkjent vokabular.',
            );
        }

        return array_values($suggestions);
    }

    /**
     * Purpose: Determine whether every central field matches the approved vocabulary.
     * Inputs: The normalized field values and the approved vocabulary map.
     * Returns: True when all central fields resolve to approved vocabulary values.
     * Side effects: None.
     */
    private function allCentralFieldsApproved(array $normalizedFields, array $vocabularyMap): bool
    {
        foreach (['service_product_tag', 'theme_tag', 'topic', 'sub_topic'] as $field) {
            $value = trim((string) ($normalizedFields[$field] ?? ''));

            if ($value === '') {
                return false;
            }

            if ($this->vocabularyService->resolveCanonicalValue($vocabularyMap, $field, $value) === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Purpose: Split a scalar-or-array payload into a deterministic string list.
     * Inputs: Raw payload data.
     * Returns: A trimmed list of string values.
     * Side effects: None.
     */
    private function splitList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[,\n;]+/u', str_replace(["\r\n", "\r"], "\n", $value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        } elseif (! is_array($value)) {
            $value = $value === null ? [] : [(string) $value];
        }

        $normalized = [];
        $seen = [];

        foreach ($value as $item) {
            $cleanItem = trim(Str::squish((string) $item));

            if ($cleanItem === '') {
                continue;
            }

            $key = $this->comparisonKey($cleanItem);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = $cleanItem;
        }

        return $normalized;
    }

    /**
     * Purpose: Split a raw suggestion payload into normalized item arrays.
     * Inputs: Raw suggestion data from the AI response.
     * Returns: A list of suggestion arrays.
     * Side effects: None.
     */
    private function splitSuggestionList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => is_array($item)));
    }

    /**
     * Purpose: Determine whether the chunk content contains a candidate matched term.
     * Inputs: The chunk content and a candidate term.
     * Returns: True when the term can be found in the chunk text.
     * Side effects: None.
     */
    private function chunkContainsTerm(string $chunkContent, string $term): bool
    {
        $normalizedChunk = $this->comparisonKey($chunkContent);
        $normalizedTerm = $this->comparisonKey($term);

        if ($normalizedChunk === '' || $normalizedTerm === '') {
            return false;
        }

        return str_contains($normalizedChunk, $normalizedTerm);
    }

    /**
     * Purpose: Determine whether a suggested term already exists in the approved vocabulary.
     * Inputs: The approved vocabulary map, the suggested type, and the suggested term.
     * Returns: True when the term resolves to an approved canonical value for that type.
     * Side effects: None.
     */
    private function suggestionMatchesApprovedVocabulary(array $vocabularyMap, string $type, string $term): bool
    {
        return $this->vocabularyService->resolveCanonicalValue($vocabularyMap, $type, $term) !== null;
    }

    /**
     * Purpose: Build a deterministic fallback summary from the chunk content.
     * Inputs: The chunk content.
     * Returns: A short summary derived from the raw chunk text.
     * Side effects: None.
     */
    private function fallbackSummary(string $chunkContent): string
    {
        $content = Str::squish($chunkContent);

        if ($content === '') {
            return '';
        }

        return Str::limit($content, 280, '...');
    }

    /**
     * Purpose: Normalize an optional string into a trimmed nullable string.
     * Inputs: A raw scalar or null.
     * Returns: A trimmed string or null.
     * Side effects: None.
     */
    private function normalizeNullableString(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * Purpose: Produce a stable comparison key for strings.
     * Inputs: Raw text.
     * Returns: A lowercased, accent-stripped comparison key.
     * Side effects: None.
     */
    private function comparisonKey(string $value): string
    {
        $value = trim(Str::squish($value));

        if ($value === '') {
            return '';
        }

        return trim(Str::ascii(mb_strtolower($value, 'UTF-8')));
    }
}
