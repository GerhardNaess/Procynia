<?php

namespace App\Services\Ai\Knowledge;

use App\Models\KnowledgeMetadataTerm;
use App\Models\KnowledgeMetadataTermSuggestion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class KnowledgeVocabularyApprovalService
{
    public function __construct(
        private readonly KnowledgeMetadataVocabularyService $vocabularyService,
    ) {
    }

    /**
     * Purpose: Approve one pending suggestion as a new authoritative vocabulary term.
     * Inputs: The suggestion id and the approving user id.
     * Returns: The updated suggestion row.
     * Side effects: May create a new approved term or merge into an existing term.
     */
    public function approveSuggestion(int $suggestionId, int $userId): KnowledgeMetadataTermSuggestion
    {
        return DB::transaction(function () use ($suggestionId, $userId): KnowledgeMetadataTermSuggestion {
            $suggestion = $this->loadSuggestionForUpdate($suggestionId);
            $catalog = $this->vocabularyService->buildCatalogForCustomer((int) $suggestion->customer_id);
            $suggestedType = $this->normalizeType((string) $suggestion->suggested_type);
            $canonicalName = $this->normalizeString((string) ($suggestion->suggested_canonical_name ?: $suggestion->suggested_term));
            $synonyms = $this->normalizeStringList($suggestion->suggested_synonyms);
            $description = $this->normalizeNullableString($suggestion->suggested_description);
            $existingTerm = $this->resolveExistingTerm($catalog, $suggestedType, $canonicalName, $synonyms);

            if ($suggestion->status !== KnowledgeMetadataTermSuggestion::STATUS_PENDING) {
                throw new RuntimeException('Only pending suggestions can be approved.');
            }

            if ($existingTerm !== null) {
                $this->mergeSuggestionIntoExistingTerm($suggestion, (int) $existingTerm['id'], $userId);

                return $suggestion->refresh();
            }

            KnowledgeMetadataTerm::query()->create([
                'customer_id' => (int) $suggestion->customer_id,
                'type' => $suggestedType,
                'canonical_name' => $canonicalName,
                'synonyms' => $synonyms,
                'description' => $description,
                'approved' => true,
            ]);

            $suggestion->forceFill([
                'status' => KnowledgeMetadataTermSuggestion::STATUS_APPROVED,
            ])->save();

            Log::info('[PROCYNIA][KNOWLEDGE_VOCABULARY] Vocabulary suggestion approved.', [
                'customer_id' => (int) $suggestion->customer_id,
                'suggestion_id' => $suggestion->id,
                'user_id' => $userId,
            ]);

            return $suggestion->refresh();
        });
    }

    /**
     * Purpose: Reject one pending suggestion without changing the approved vocabulary catalog.
     * Inputs: The suggestion id and the approving user id.
     * Returns: The updated suggestion row.
     * Side effects: Marks the suggestion as rejected.
     */
    public function rejectSuggestion(int $suggestionId, int $userId): KnowledgeMetadataTermSuggestion
    {
        return DB::transaction(function () use ($suggestionId, $userId): KnowledgeMetadataTermSuggestion {
            $suggestion = $this->loadSuggestionForUpdate($suggestionId);

            if ($suggestion->status !== KnowledgeMetadataTermSuggestion::STATUS_PENDING) {
                throw new RuntimeException('Only pending suggestions can be rejected.');
            }

            $suggestion->forceFill([
                'status' => KnowledgeMetadataTermSuggestion::STATUS_REJECTED,
            ])->save();

            Log::info('[PROCYNIA][KNOWLEDGE_VOCABULARY] Vocabulary suggestion rejected.', [
                'customer_id' => (int) $suggestion->customer_id,
                'suggestion_id' => $suggestion->id,
                'user_id' => $userId,
            ]);

            return $suggestion->refresh();
        });
    }

    /**
     * Purpose: Merge a pending suggestion into an existing approved term.
     * Inputs: The suggestion id, the target term id, and the approving user id.
     * Returns: The updated suggestion row.
     * Side effects: Adds only new synonyms to the target term.
     */
    public function mergeSuggestion(int $suggestionId, int $existingTermId, int $userId): KnowledgeMetadataTermSuggestion
    {
        return DB::transaction(function () use ($suggestionId, $existingTermId, $userId): KnowledgeMetadataTermSuggestion {
            $suggestion = $this->loadSuggestionForUpdate($suggestionId);
            $existingTerm = $this->loadApprovedTermForUpdate((int) $suggestion->customer_id, $existingTermId);

            if ($suggestion->status !== KnowledgeMetadataTermSuggestion::STATUS_PENDING) {
                throw new RuntimeException('Only pending suggestions can be merged.');
            }

            $synonymsToAdd = $this->normalizeStringList(array_merge(
                [
                    $suggestion->suggested_canonical_name ?: $suggestion->suggested_term,
                ],
                (array) ($suggestion->suggested_synonyms ?? []),
            ));

            $newSynonyms = $this->filterNewSynonyms($existingTerm, $synonymsToAdd);

            if ($newSynonyms !== []) {
                $this->addSynonymsToExistingTerm($existingTerm->id, $newSynonyms, $userId);
            }

            $suggestion->forceFill([
                'status' => KnowledgeMetadataTermSuggestion::STATUS_MERGED,
                'related_existing_term_id' => $existingTerm->id,
            ])->save();

            Log::info('[PROCYNIA][KNOWLEDGE_VOCABULARY] Vocabulary suggestion merged.', [
                'customer_id' => (int) $suggestion->customer_id,
                'suggestion_id' => $suggestion->id,
                'existing_term_id' => $existingTerm->id,
                'new_synonym_count' => count($newSynonyms),
                'user_id' => $userId,
            ]);

            return $suggestion->refresh();
        });
    }

    /**
     * Purpose: Edit one pending suggestion and then approve it as either a new term or a merge.
     * Inputs: The suggestion id, normalized payload, and the approving user id.
     * Returns: The updated suggestion row.
     * Side effects: May create a new approved term or merge into an existing term.
     */
    public function editAndApproveSuggestion(int $suggestionId, array $payload, int $userId): KnowledgeMetadataTermSuggestion
    {
        return DB::transaction(function () use ($suggestionId, $payload, $userId): KnowledgeMetadataTermSuggestion {
            $suggestion = $this->loadSuggestionForUpdate($suggestionId);

            if ($suggestion->status !== KnowledgeMetadataTermSuggestion::STATUS_PENDING) {
                throw new RuntimeException('Only pending suggestions can be edited and approved.');
            }

            $suggestion->forceFill([
                'suggested_type' => $this->normalizeType((string) ($payload['suggested_type'] ?? $suggestion->suggested_type)),
                'suggested_canonical_name' => $this->normalizeString((string) ($payload['suggested_canonical_name'] ?? $suggestion->suggested_canonical_name ?: $suggestion->suggested_term)),
                'suggested_term' => $this->normalizeString((string) ($payload['suggested_canonical_name'] ?? $suggestion->suggested_canonical_name ?: $suggestion->suggested_term)),
                'suggested_synonyms' => $this->normalizeStringList($payload['suggested_synonyms'] ?? $suggestion->suggested_synonyms ?? []),
                'suggested_description' => $this->normalizeNullableString($payload['suggested_description'] ?? $suggestion->suggested_description),
                'reason' => $this->normalizeNullableString($payload['reason'] ?? $suggestion->reason),
            ])->save();

            return $this->approveSuggestion($suggestionId, $userId);
        });
    }

    /**
     * Purpose: Append new synonyms to an existing approved term.
     * Inputs: The target term id, the synonym list, and the approving user id.
     * Returns: The updated term row.
     * Side effects: Saves the approved term when new synonyms are found.
     */
    public function addSynonymsToExistingTerm(int $termId, array $synonyms, int $userId): KnowledgeMetadataTerm
    {
        return DB::transaction(function () use ($termId, $synonyms, $userId): KnowledgeMetadataTerm {
            $term = $this->loadApprovedTermForUpdate(null, $termId);
            $existingSynonyms = $this->normalizeStringList($term->synonyms);
            $mergedSynonyms = $this->normalizeStringList(array_merge($existingSynonyms, $synonyms));

            $term->forceFill([
                'synonyms' => $mergedSynonyms,
            ])->save();

            Log::info('[PROCYNIA][KNOWLEDGE_VOCABULARY] Approved vocabulary synonyms updated.', [
                'customer_id' => (int) $term->customer_id,
                'term_id' => $term->id,
                'new_synonym_count' => count(array_diff($mergedSynonyms, $existingSynonyms)),
                'user_id' => $userId,
            ]);

            return $term->refresh();
        });
    }

    /**
     * Purpose: Load one suggestion row with row-level locking.
     * Inputs: The suggestion id.
     * Returns: The locked suggestion row.
     * Side effects: Throws when the suggestion does not exist.
     */
    private function loadSuggestionForUpdate(int $suggestionId): KnowledgeMetadataTermSuggestion
    {
        return KnowledgeMetadataTermSuggestion::query()
            ->lockForUpdate()
            ->findOrFail($suggestionId);
    }

    /**
     * Purpose: Load one approved term row with row-level locking and customer scoping.
     * Inputs: The customer id and term id.
     * Returns: The locked term row.
     * Side effects: Throws when the term does not exist.
     */
    private function loadApprovedTermForUpdate(?int $customerId, int $termId): KnowledgeMetadataTerm
    {
        $query = KnowledgeMetadataTerm::query()
            ->lockForUpdate()
            ->where('id', $termId)
            ->where('approved', true);

        if ($customerId !== null) {
            $query->where('customer_id', $customerId);
        }

        return $query->firstOrFail();
    }

    /**
     * Purpose: Resolve an approved term from the current catalog by canonical name or synonym.
     * Inputs: The catalog, the type, the canonical name, and the synonyms.
     * Returns: The matched approved term row or null.
     * Side effects: None.
     */
    private function resolveExistingTerm(array $catalog, string $type, string $canonicalName, array $synonyms): ?array
    {
        $resolved = $this->vocabularyService->resolveCatalogTerm($catalog, $type, $canonicalName);

        if ($resolved !== null) {
            return $resolved;
        }

        foreach ($synonyms as $synonym) {
            $resolved = $this->vocabularyService->resolveCatalogTerm($catalog, $type, $synonym);

            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * Purpose: Merge a suggestion into an existing approved term and mark the suggestion as merged.
     * Inputs: The suggestion row, the target term id, and the user id.
     * Returns: None.
     * Side effects: Updates the approved term and the suggestion row.
     */
    private function mergeSuggestionIntoExistingTerm(
        KnowledgeMetadataTermSuggestion $suggestion,
        int $existingTermId,
        int $userId,
    ): void {
        $existingTerm = $this->loadApprovedTermForUpdate((int) $suggestion->customer_id, $existingTermId);
        $synonymsToAdd = $this->normalizeStringList(array_merge(
            [
                $suggestion->suggested_canonical_name ?: $suggestion->suggested_term,
            ],
            (array) ($suggestion->suggested_synonyms ?? []),
        ));

        $newSynonyms = $this->filterNewSynonyms($existingTerm, $synonymsToAdd);

        if ($newSynonyms !== []) {
            $this->addSynonymsToExistingTerm($existingTerm->id, $newSynonyms, $userId);
        }

        $suggestion->forceFill([
            'status' => KnowledgeMetadataTermSuggestion::STATUS_MERGED,
            'related_existing_term_id' => $existingTerm->id,
        ])->save();

        Log::info('[PROCYNIA][KNOWLEDGE_VOCABULARY] Pending suggestion merged while approving.', [
            'customer_id' => (int) $suggestion->customer_id,
            'suggestion_id' => $suggestion->id,
            'existing_term_id' => $existingTerm->id,
            'new_synonym_count' => count($newSynonyms),
            'user_id' => $userId,
        ]);
    }

    /**
     * Purpose: Filter synonym candidates down to values that are not already present on the term.
     * Inputs: The existing term row and the candidate synonyms.
     * Returns: A de-duplicated list of new synonyms.
     * Side effects: None.
     */
    private function filterNewSynonyms(KnowledgeMetadataTerm $term, array $synonyms): array
    {
        $existingSynonyms = $this->normalizeStringList($term->synonyms);
        $existingValues = array_merge([$this->normalizeString($term->canonical_name)], $existingSynonyms);
        $existingKeys = array_fill_keys(array_map([$this, 'comparisonKey'], $existingValues), true);

        $newSynonyms = [];

        foreach ($this->normalizeStringList($synonyms) as $synonym) {
            $key = $this->comparisonKey($synonym);

            if (isset($existingKeys[$key])) {
                continue;
            }

            $newSynonyms[] = $synonym;
            $existingKeys[$key] = true;
        }

        return $newSynonyms;
    }

    /**
     * Purpose: Normalize a type string to an approved vocabulary type.
     * Inputs: A raw type value.
     * Returns: A valid type key or an empty string.
     * Side effects: None.
     */
    private function normalizeType(string $value): string
    {
        $type = trim(Str::squish($value));
        $type = KnowledgeMetadataTerm::TYPE_ALIASES[$type] ?? $type;

        return in_array($type, KnowledgeMetadataTerm::TYPES, true) ? $type : '';
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
