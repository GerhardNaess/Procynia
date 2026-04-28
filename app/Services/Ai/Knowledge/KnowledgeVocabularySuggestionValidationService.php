<?php

namespace App\Services\Ai\Knowledge;

use App\Models\KnowledgeMetadataTermSuggestion;
use App\Models\KnowledgeVocabularyAnalysisBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class KnowledgeVocabularySuggestionValidationService
{
    public function __construct(
        private readonly KnowledgeMetadataVocabularyService $vocabularyService,
    ) {
    }

    /**
     * Purpose: Validate AI vocabulary output and persist pending suggestions.
     * Inputs: The analysis batch, raw AI payload, and approved vocabulary catalog.
     * Returns: A normalized validation summary.
     * Side effects: Creates pending suggestion rows and logs the normalization outcome.
     */
    public function validateAndPersist(
        KnowledgeVocabularyAnalysisBatch $batch,
        array $rawPayload,
        array $approvedVocabularyCatalog,
    ): array {
        $batchSummary = $this->normalizeSummary(data_get($rawPayload, 'batch_summary'));
        $rawSuggestions = data_get($rawPayload, 'suggestions', []);

        if (! is_array($rawSuggestions)) {
            throw new RuntimeException('The suggestions field must be an array.');
        }

        $createdCount = 0;
        $relatedCount = 0;
        $skippedCount = 0;
        $validatedSuggestions = [];

        DB::transaction(function () use (
            $batch,
            $rawSuggestions,
            $approvedVocabularyCatalog,
            &$createdCount,
            &$relatedCount,
            &$skippedCount,
            &$validatedSuggestions,
        ): void {
            foreach ($rawSuggestions as $rawSuggestion) {
                if (! is_array($rawSuggestion)) {
                    $skippedCount++;
                    continue;
                }

                $validatedSuggestion = $this->normalizeSuggestion($batch, $rawSuggestion, $approvedVocabularyCatalog);

                if ($validatedSuggestion === null) {
                    $skippedCount++;

                    continue;
                }

                $suggestion = KnowledgeMetadataTermSuggestion::query()->create($validatedSuggestion);
                $validatedSuggestions[] = $suggestion->id;

                if (($validatedSuggestion['related_existing_term_id'] ?? null) !== null) {
                    $relatedCount++;
                } else {
                    $createdCount++;
                }
            }
        });

        Log::info('[PROCYNIA][KNOWLEDGE_VOCABULARY] Vocabulary suggestions validated.', [
            'customer_id' => (int) $batch->customer_id,
            'batch_id' => $batch->id,
            'created_count' => $createdCount,
            'related_count' => $relatedCount,
            'skipped_count' => $skippedCount,
        ]);

        return [
            'batch_summary' => $batchSummary,
            'created_count' => $createdCount,
            'related_count' => $relatedCount,
            'skipped_count' => $skippedCount,
            'suggestion_ids' => $validatedSuggestions,
        ];
    }

    /**
     * Purpose: Normalize one AI suggestion and decide whether it should be persisted.
     * Inputs: The batch, raw suggestion payload, and approved vocabulary catalog.
     * Returns: A normalized persistence payload or null when the suggestion is a duplicate.
     * Side effects: None.
     */
    private function normalizeSuggestion(
        KnowledgeVocabularyAnalysisBatch $batch,
        array $rawSuggestion,
        array $approvedVocabularyCatalog,
    ): ?array {
        $type = $this->normalizeType(data_get($rawSuggestion, 'type'));
        $canonicalName = $this->normalizeString(data_get($rawSuggestion, 'canonical_name'));
        $synonyms = $this->normalizeStringList(data_get($rawSuggestion, 'synonyms'));
        $description = $this->normalizeNullableString(data_get($rawSuggestion, 'description'));
        $reason = $this->normalizeNullableString(data_get($rawSuggestion, 'reason'));
        $relatedExistingTerm = $this->resolveRelatedTerm($approvedVocabularyCatalog, $type, $canonicalName, $synonyms);
        $relatedExistingTermHint = $this->normalizeString(data_get($rawSuggestion, 'related_existing_term'));
        $confidenceScore = $this->normalizeConfidenceScore(data_get($rawSuggestion, 'confidence_score'));

        if ($relatedExistingTerm === null && $relatedExistingTermHint !== '') {
            $relatedExistingTerm = $this->vocabularyService->resolveCatalogTerm($approvedVocabularyCatalog, $type, $relatedExistingTermHint);
        }

        if ($type === '' || $canonicalName === '') {
            return null;
        }

        $filteredSynonyms = $this->normalizeStringList(array_values(array_filter(
            $synonyms,
            function (string $synonym) use ($approvedVocabularyCatalog, $type): bool {
                return $this->vocabularyService->resolveCatalogTerm($approvedVocabularyCatalog, $type, $synonym) === null;
            },
        )));

        if ($relatedExistingTerm !== null && $this->vocabularyService->resolveCatalogTerm($approvedVocabularyCatalog, $type, $canonicalName) === null) {
            $filteredSynonyms = $this->normalizeStringList(array_merge([$canonicalName], $filteredSynonyms));
        }

        if (
            $relatedExistingTerm === null
            && $this->vocabularyService->resolveCatalogTerm($approvedVocabularyCatalog, $type, $canonicalName) !== null
            && $filteredSynonyms === []
        ) {
            return null;
        }

        if ($relatedExistingTerm !== null && $filteredSynonyms === []) {
            return null;
        }

        return [
            'customer_id' => (int) $batch->customer_id,
            'batch_id' => $batch->id,
            'source_chunk_id' => null,
            'suggested_term' => $canonicalName,
            'suggested_canonical_name' => $canonicalName,
            'suggested_type' => $type,
            'suggested_synonyms' => $filteredSynonyms,
            'suggested_description' => $description,
            'suggested_canonical_parent' => $relatedExistingTerm['canonical_name'] ?? null,
            'related_existing_term_id' => $relatedExistingTerm['id'] ?? null,
            'reason' => $reason,
            'confidence_score' => $confidenceScore,
            'status' => KnowledgeMetadataTermSuggestion::STATUS_PENDING,
        ];
    }

    /**
     * Purpose: Resolve an approved term from the catalog when the AI suggestion matches an existing term.
     * Inputs: The approved catalog, the type, the canonical name, and the synonyms.
     * Returns: The matched approved term row or null.
     * Side effects: None.
     */
    private function resolveRelatedTerm(
        array $approvedVocabularyCatalog,
        string $type,
        string $canonicalName,
        array $synonyms,
    ): ?array {
        $resolved = $this->vocabularyService->resolveCatalogTerm($approvedVocabularyCatalog, $type, $canonicalName);

        if ($resolved !== null) {
            return $resolved;
        }

        foreach ($synonyms as $synonym) {
            $resolved = $this->vocabularyService->resolveCatalogTerm($approvedVocabularyCatalog, $type, $synonym);

            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * Purpose: Normalize a free-text string into a trimmed value.
     * Inputs: A raw scalar value.
     * Returns: A trimmed string.
     * Side effects: None.
     */
    private function normalizeString(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }

    /**
     * Purpose: Normalize a nullable text field.
     * Inputs: A raw scalar value.
     * Returns: A trimmed string or null.
     * Side effects: None.
     */
    private function normalizeNullableString(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text !== '' ? Str::limit($text, 2000, '') : null;
    }

    /**
     * Purpose: Normalize the AI batch summary into a safe display string.
     * Inputs: A raw scalar value.
     * Returns: A trimmed string or null.
     * Side effects: None.
     */
    private function normalizeSummary(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text !== '' ? Str::limit($text, 4000, '') : null;
    }

    /**
     * Purpose: Normalize the AI confidence score to a safe floating point value.
     * Inputs: A raw scalar value.
     * Returns: A clamped float between 0 and 1.
     * Side effects: None.
     */
    private function normalizeConfidenceScore(mixed $value): float
    {
        if (! is_numeric($value)) {
            return 0.0;
        }

        $confidence = (float) $value;

        if ($confidence < 0.0) {
            return 0.0;
        }

        if ($confidence > 1.0) {
            return 1.0;
        }

        return $confidence;
    }

    /**
     * Purpose: Normalize a metadata type string to an approved vocabulary type.
     * Inputs: The raw type value.
     * Returns: A valid type key or an empty string.
     * Side effects: None.
     */
    private function normalizeType(mixed $value): string
    {
        $type = trim((string) ($value ?? ''));

        return in_array($type, \App\Models\KnowledgeMetadataTerm::TYPES, true) ? $type : '';
    }

    /**
     * Purpose: Normalize a synonym list into stable unique strings.
     * Inputs: Raw synonym data.
     * Returns: A de-duplicated list of strings.
     * Side effects: None.
     */
    private function normalizeStringList(mixed $values): array
    {
        if (is_string($values)) {
            $values = preg_split('/[,\n;]+/u', str_replace(["\r\n", "\r"], "\n", $values), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        } elseif (! is_array($values)) {
            $values = $values === null ? [] : [(string) $values];
        }

        $normalized = [];
        $seen = [];

        foreach ($values as $value) {
            $cleanValue = trim(Str::squish((string) $value));

            if ($cleanValue === '') {
                continue;
            }

            $key = $this->comparisonKey($cleanValue);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = $cleanValue;
        }

        return $normalized;
    }

    /**
     * Purpose: Produce a stable comparison key for normalized string matching.
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
