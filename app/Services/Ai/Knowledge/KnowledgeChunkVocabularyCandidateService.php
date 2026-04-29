<?php

namespace App\Services\Ai\Knowledge;

use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemChunk;
use App\Models\KnowledgeMetadataTerm;
use App\Models\KnowledgeMetadataTermSuggestion;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class KnowledgeChunkVocabularyCandidateService
{
    public function __construct(
        private readonly KnowledgeMetadataVocabularyService $vocabularyService,
        private readonly KnowledgeVocabularySuggestionEnrichmentService $suggestionEnrichmentService,
    ) {
    }

    /**
     * Purpose: Create deterministic vocabulary suggestions from one persisted knowledge chunk.
     * Inputs: The parent knowledge document and one persisted knowledge chunk.
     * Returns: A compact summary with created and skipped counts.
     * Side effects: May create pending suggestion rows and emits observability logs.
     */
    public function syncForChunk(KnowledgeItem $document, KnowledgeItemChunk $chunk): array
    {
        $customerId = (int) $document->customer_id;
        $catalog = $this->vocabularyService->buildCatalogForCustomer($customerId);
        $createdCount = 0;
        $skippedCount = 0;

        foreach ($this->candidateEntriesFromChunk($chunk) as $entry) {
            $field = (string) data_get($entry, 'field', '');
            $term = (string) data_get($entry, 'term', '');

            if ($field === '' || $term === '') {
                continue;
            }

            $normalizedField = $this->normalizeField($field);

            if ($normalizedField === '') {
                $skippedCount++;

                Log::info('[PROCYNIA][KNOWLEDGE_VOCABULARY] Chunk vocabulary candidate skipped.', [
                    'customer_id' => $customerId,
                    'knowledge_item_id' => $document->id,
                    'knowledge_item_chunk_id' => $chunk->id,
                    'field' => $field,
                    'term' => $term,
                    'status' => KnowledgeMetadataTermSuggestion::STATUS_PENDING,
                    'created_or_skipped' => 'skipped',
                    'skip_reason' => 'unsupported_field',
                ]);

                continue;
            }

            $skipReason = $this->skipReasonForTerm($customerId, $normalizedField, $term, $catalog);

            if ($skipReason !== null) {
                $skippedCount++;

                Log::info('[PROCYNIA][KNOWLEDGE_VOCABULARY] Chunk vocabulary candidate skipped.', [
                    'customer_id' => $customerId,
                    'knowledge_item_id' => $document->id,
                    'knowledge_item_chunk_id' => $chunk->id,
                    'field' => $normalizedField,
                    'term' => $term,
                    'status' => KnowledgeMetadataTermSuggestion::STATUS_PENDING,
                    'created_or_skipped' => 'skipped',
                    'skip_reason' => $skipReason,
                ]);

                continue;
            }

            $enrichment = $this->suggestionEnrichmentService->enrichSuggestion($document, $chunk, $normalizedField, $term);
            $normalizedSuggestion = $this->normalizeEnrichedSuggestion($chunk, $normalizedField, $term, $enrichment);
            $finalSkipReason = $this->skipReasonForTerm($customerId, $normalizedField, $normalizedSuggestion['suggested_canonical_name'], $catalog);

            if ($finalSkipReason !== null) {
                $skippedCount++;

                Log::info('[PROCYNIA][KNOWLEDGE_VOCABULARY] Chunk vocabulary candidate skipped.', [
                    'customer_id' => $customerId,
                    'knowledge_item_id' => $document->id,
                    'knowledge_item_chunk_id' => $chunk->id,
                    'field' => $normalizedField,
                    'term' => $term,
                    'status' => KnowledgeMetadataTermSuggestion::STATUS_PENDING,
                    'created_or_skipped' => 'skipped',
                    'skip_reason' => $finalSkipReason,
                ]);

                continue;
            }

            KnowledgeMetadataTermSuggestion::query()->create([
                'customer_id' => $customerId,
                'source_chunk_id' => $chunk->id,
                'suggested_term' => $term,
                'suggested_canonical_name' => $normalizedSuggestion['suggested_canonical_name'],
                'suggested_type' => $normalizedField,
                'suggested_synonyms' => $normalizedSuggestion['suggested_synonyms'],
                'suggested_description' => $normalizedSuggestion['suggested_description'],
                'suggested_canonical_parent' => null,
                'related_existing_term_id' => null,
                'reason' => $normalizedSuggestion['reason'],
                'confidence_score' => null,
                'status' => KnowledgeMetadataTermSuggestion::STATUS_PENDING,
            ]);

            $createdCount++;

            Log::info('[PROCYNIA][KNOWLEDGE_VOCABULARY] Chunk vocabulary candidate created.', [
                'customer_id' => $customerId,
                'knowledge_item_id' => $document->id,
                'knowledge_item_chunk_id' => $chunk->id,
                'field' => $normalizedField,
                'term' => $term,
                'status' => KnowledgeMetadataTermSuggestion::STATUS_PENDING,
                'created_or_skipped' => 'created',
            ]);
        }

        return [
            'created_count' => $createdCount,
            'skipped_count' => $skippedCount,
        ];
    }

    /**
     * Purpose: Build the deterministic candidate list from one persisted knowledge chunk.
     * Inputs: The persisted chunk.
     * Returns: A list of field/term pairs ready for duplicate checks.
     * Side effects: None.
     *
     * @return array<int, array{field: string, term: string}>
     */
    private function candidateEntriesFromChunk(KnowledgeItemChunk $chunk): array
    {
        $entries = [];
        $topic = trim((string) ($chunk->topic ?? ''));
        $subTopic = trim((string) ($chunk->sub_topic ?? ''));
        $keywords = $this->normalizeExactList($chunk->keywords);

        if ($topic !== '') {
            $entries[] = [
                'field' => KnowledgeMetadataTerm::TYPE_TOPIC,
                'term' => $topic,
            ];
        }

        if ($subTopic !== '') {
            $entries[] = [
                'field' => KnowledgeMetadataTerm::TYPE_SUB_TOPIC,
                'term' => $subTopic,
            ];
        }

        foreach ($keywords as $keyword) {
            $cleanKeyword = trim((string) $keyword);

            if ($cleanKeyword === '') {
                continue;
            }

            $entries[] = [
                'field' => KnowledgeMetadataTerm::TYPE_KEYWORDS,
                'term' => $cleanKeyword,
            ];
        }

        return $entries;
    }

    /**
     * Purpose: Decide whether a candidate term should be skipped before persistence.
     * Inputs: The customer id, the field name, the candidate term, and the approved vocabulary catalog.
     * Returns: A skip reason string when the candidate should not be persisted, otherwise null.
     * Side effects: None.
     */
    private function skipReasonForTerm(int $customerId, string $field, string $term, array $catalog): ?string
    {
        if ($this->vocabularyService->resolveCatalogTerm($catalog, $field, $term) !== null) {
            return 'approved_vocabulary';
        }

        $comparisonKey = $this->comparisonKey($term);
        $existingSuggestions = KnowledgeMetadataTermSuggestion::query()
            ->where('customer_id', $customerId)
            ->whereIn('suggested_type', $this->candidateTypeAliases($field))
            ->get([
                'id',
                'suggested_term',
                'suggested_canonical_name',
                'status',
            ]);

        foreach ($existingSuggestions as $suggestion) {
            $existingTerms = [
                (string) ($suggestion->suggested_term ?? ''),
                (string) ($suggestion->suggested_canonical_name ?? ''),
            ];

            foreach ($existingTerms as $existingTerm) {
                if ($this->comparisonKey($existingTerm) === $comparisonKey) {
                    return 'existing_candidate';
                }
            }
        }

        return null;
    }

    /**
     * Purpose: Normalize a field key to the canonical vocabulary type name.
     * Inputs: A raw field value.
     * Returns: The canonical field name or an empty string.
     * Side effects: None.
     */
    private function normalizeField(string $field): string
    {
        $field = trim(mb_strtolower($field, 'UTF-8'));

        return match ($field) {
            KnowledgeMetadataTerm::TYPE_TOPIC,
            KnowledgeMetadataTerm::TYPE_SUB_TOPIC,
            KnowledgeMetadataTerm::TYPE_KEYWORDS => $field,
            'keyword' => KnowledgeMetadataTerm::TYPE_KEYWORDS,
            default => '',
        };
    }

    /**
     * Purpose: Return all field aliases that should be considered for duplicate checks.
     * Inputs: A canonical field name.
     * Returns: A stable list of canonical and legacy aliases.
     * Side effects: None.
     *
     * @return array<int, string>
     */
    private function candidateTypeAliases(string $field): array
    {
        return match ($field) {
            KnowledgeMetadataTerm::TYPE_KEYWORDS => [KnowledgeMetadataTerm::TYPE_KEYWORDS, KnowledgeMetadataTerm::TYPE_KEYWORD],
            default => [$field],
        };
    }

    /**
     * Purpose: Produce a stable comparison key for candidate deduplication.
     * Inputs: Raw text.
     * Returns: A trimmed, normalized comparison key.
     * Side effects: None.
     */
    private function comparisonKey(string $value): string
    {
        $value = trim(Str::squish($value));

        if ($value === '') {
            return '';
        }

        return $value;
    }

    /**
     * Purpose: Normalize a list of chunk keywords without changing the user-facing values.
     * Inputs: Raw keyword data from the persisted chunk record.
     * Returns: A trimmed, de-duplicated keyword list.
     * Side effects: None.
     *
     * @return array<int, string>
     */
    private function normalizeExactList(mixed $keywords): array
    {
        if (is_string($keywords)) {
            $keywords = preg_split('/[,\n;]+/u', $keywords, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        } elseif (! is_array($keywords)) {
            $keywords = $keywords === null ? [] : [(string) $keywords];
        }

        $normalized = [];
        $seen = [];

        foreach ($keywords as $keyword) {
            $text = trim(Str::squish((string) $keyword));

            if ($text === '') {
                continue;
            }

            if (isset($seen[$text])) {
                continue;
            }

            $seen[$text] = true;
            $normalized[] = $text;
        }

        return $normalized;
    }

    /**
     * Purpose: Normalize an enriched suggestion payload before persistence.
     * Inputs: The chunk context, the candidate field, the original term, and the enrichment result.
     * Returns: A normalized persistence payload with safe fallbacks.
     * Side effects: None.
     */
    private function normalizeEnrichedSuggestion(
        KnowledgeItemChunk $chunk,
        string $field,
        string $term,
        array $enrichment,
    ): array {
        $canonicalName = $this->normalizeText(data_get($enrichment, 'canonical_name'), $term, 191) ?? $term;
        $synonyms = $this->normalizeSynonyms(data_get($enrichment, 'synonyms'), $canonicalName);
        $description = $this->normalizeText(
            data_get($enrichment, 'description'),
            $this->fallbackDescription($chunk, $field),
            300,
        );
        $reason = $this->normalizeText(
            data_get($enrichment, 'reason'),
            $this->fallbackReason($chunk),
            300,
        );

        return [
            'suggested_canonical_name' => $canonicalName,
            'suggested_synonyms' => $synonyms,
            'suggested_description' => $description,
            'reason' => $reason,
        ];
    }

    /**
     * Purpose: Normalize one text value with an optional fallback.
     * Inputs: The raw value, a fallback value, and the maximum length.
     * Returns: A trimmed string capped to the requested length, or null.
     * Side effects: None.
     */
    private function normalizeText(mixed $value, mixed $fallbackValue = null, int $maxLength = 300): ?string
    {
        foreach ([$value, $fallbackValue] as $candidate) {
            $cleanValue = trim(Str::squish((string) ($candidate ?? '')));

            if ($cleanValue === '') {
                continue;
            }

            return Str::limit($cleanValue, $maxLength, '');
        }

        return null;
    }

    /**
     * Purpose: Normalize enriched synonyms and remove the canonical name from the list.
     * Inputs: Raw synonym data and the canonical name.
     * Returns: A trimmed, de-duplicated synonym list.
     * Side effects: None.
     *
     * @return array<int, string>
     */
    private function normalizeSynonyms(mixed $synonyms, string $canonicalName): array
    {
        if (is_string($synonyms)) {
            $synonyms = preg_split('/[,\n;]+/u', str_replace(["\r\n", "\r"], "\n", $synonyms), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        } elseif (! is_array($synonyms)) {
            $synonyms = $synonyms === null ? [] : [(string) $synonyms];
        }

        $normalized = [];
        $seen = [];
        $canonicalKey = $this->comparisonKey($canonicalName);

        foreach ($synonyms as $synonym) {
            $cleanValue = trim(Str::squish((string) $synonym));

            if ($cleanValue === '') {
                continue;
            }

            $comparisonKey = $this->comparisonKey($cleanValue);

            if ($comparisonKey === $canonicalKey) {
                continue;
            }

            if (isset($seen[$comparisonKey])) {
                continue;
            }

            $seen[$comparisonKey] = true;
            $normalized[] = $cleanValue;

            if (count($normalized) >= 5) {
                break;
            }
        }

        return $normalized;
    }

    /**
     * Purpose: Build a short fallback description from the persisted chunk context.
     * Inputs: The chunk context and the candidate field.
     * Returns: A short descriptive sentence or null.
     * Side effects: None.
     */
    private function fallbackDescription(KnowledgeItemChunk $chunk, string $field): ?string
    {
        $summary = trim((string) ($chunk->summary_for_retrieval ?? ''));

        if ($summary !== '') {
            return Str::limit(Str::squish($summary), 300, '');
        }

        $heading = $this->preferredHeadingLabel($chunk);
        $label = KnowledgeMetadataTerm::TYPE_LABELS[$field] ?? $field;

        if ($heading !== null) {
            return Str::limit('Kort beskrivelse av '.$label.' under '.$heading.'.', 300, '');
        }

        return Str::limit('Kort beskrivelse av '.$label.' i denne seksjonen.', 300, '');
    }

    /**
     * Purpose: Build a short fallback reason from the persisted chunk context.
     * Inputs: The chunk context.
     * Returns: A short Norwegian reason string.
     * Side effects: None.
     */
    private function fallbackReason(KnowledgeItemChunk $chunk): string
    {
        $heading = $this->preferredHeadingLabel($chunk);

        if ($heading !== null) {
            return Str::limit('Foreslått fra chunk-metadata basert på innhold under '.$heading.'.', 300, '');
        }

        return 'Foreslått fra chunk-metadata basert på innhold i denne seksjonen.';
    }

    /**
     * Purpose: Prefer the most precise heading label available on the chunk.
     * Inputs: The persisted chunk.
     * Returns: The leaf heading label, or the section title, or null.
     * Side effects: None.
     */
    private function preferredHeadingLabel(KnowledgeItemChunk $chunk): ?string
    {
        $heading = trim((string) ($chunk->heading_path ?? ''));

        if ($heading !== '') {
            $parts = preg_split('/\s*>\s*/u', $heading) ?: [$heading];
            $heading = trim((string) end($parts));
        }

        if ($heading === '') {
            $heading = trim((string) ($chunk->section_title ?? ''));
        }

        return $heading !== '' ? $heading : null;
    }
}
