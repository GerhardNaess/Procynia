<?php

namespace App\Services\Ai\Retrieval;

use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemChunk;
use App\Services\KnowledgeChunkCoverageService;
use App\Support\PgVector;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MetadataCandidateRetrievalService
{
    private const MAX_QUERY_ROWS = 1000;

    private const MAX_RESULTS = 200;

    private const MAX_METADATA_SCORE = 5.0;

    private const MAX_DIRECT_EVIDENCE_SCORE = 2.5;

    /**
     * @var array<string, float>
     */
    private const DIRECT_EVIDENCE_FIELD_WEIGHTS = [
        'title' => 0.9,
        'content' => 0.6,
        'summary_for_retrieval' => 1.0,
        'table_text' => 1.2,
        'keywords' => 0.8,
    ];

    /**
     * @var array<int, string>
     */
    private const SEARCH_STOPWORDS = [
        'and',
        'are',
        'av',
        'be',
        'den',
        'det',
        'en',
        'er',
        'et',
        'for',
        'from',
        'had',
        'has',
        'ha',
        'har',
        'i',
        'in',
        'is',
        'it',
        'kan',
        'med',
        'må',
        'og',
        'of',
        'on',
        'over',
        'på',
        'skal',
        'som',
        'the',
        'til',
        'to',
        'we',
        'you',
    ];

    /**
     * @var array<string, float>
     */
    private const FIELD_WEIGHTS = [
        'topic' => 1.5,
        'sub_topic' => 1.25,
        'keywords' => 1.1,
        'service_product_tag' => 1.0,
        'theme_tag' => 0.9,
        'section_title' => 0.75,
        'section_path' => 0.6,
    ];

    public function __construct(
        private readonly KnowledgeChunkCoverageService $knowledgeChunkCoverageService,
    ) {
    }

    /**
     * Purpose: Retrieve a deterministic metadata-anchored candidate set for one customer.
     * Inputs: The customer id and a validated retrieval plan.
     * Returns: The metadata-filtered candidate chunks, already ranked by metadata affinity.
     * Side effects: Logs the candidate retrieval summary only.
     */
    public function retrieveForCustomer(int $customerId, array $validatedPlan, ?array $requirementEmbedding = null): Collection
    {
        $selectedMetadata = $this->selectedMetadataFromPlan($validatedPlan);
        $searchText = $this->searchTextFromPlan($validatedPlan);
        $searchTerms = $this->searchTermsFromText($searchText);

        if ($selectedMetadata === [] && $searchTerms === []) {
            return collect();
        }

        $query = $this->baseQuery($customerId)
            ->select($this->selectColumns())
            ->where(function (Builder $candidateQuery) use ($selectedMetadata, $searchTerms): void {
                $hasMetadataFilter = $selectedMetadata !== [];
                $hasSearchFilter = $searchTerms !== [];

                if ($hasMetadataFilter) {
                    $candidateQuery->where(function (Builder $metadataQuery) use ($selectedMetadata): void {
                        foreach ($selectedMetadata as $field => $values) {
                            if (! $this->isSupportedField($field) || $values === []) {
                                continue;
                            }

                            $metadataQuery->orWhere(function (Builder $fieldQuery) use ($field, $values): void {
                                if ($field === 'keywords') {
                                    foreach (array_values($values) as $index => $value) {
                                        if ($index === 0) {
                                            $fieldQuery->whereJsonContains('knowledge_item_chunks.keywords', $value);
                                        } else {
                                            $fieldQuery->orWhereJsonContains('knowledge_item_chunks.keywords', $value);
                                        }
                                    }

                                    return;
                                }

                                $fieldQuery->whereIn('knowledge_item_chunks.'.$field, $values);
                            });
                        }
                    });
                }

                if ($hasSearchFilter) {
                    $searchQuery = function (Builder $textQuery) use ($searchTerms): void {
                        $textQuery
                            ->where('knowledge_item_chunks.chunk_type', 'table')
                            ->where(function (Builder $termQuery) use ($searchTerms): void {
                                foreach (array_values($searchTerms) as $index => $term) {
                                    $likeTerm = '%'.mb_strtolower($term, 'UTF-8').'%';

                                    if ($index === 0) {
                                        $termQuery->where(function (Builder $fieldQuery) use ($likeTerm): void {
                                            $this->applyDirectEvidenceFilters($fieldQuery, $likeTerm);
                                        });
                                    } else {
                                        $termQuery->orWhere(function (Builder $fieldQuery) use ($likeTerm): void {
                                            $this->applyDirectEvidenceFilters($fieldQuery, $likeTerm);
                                        });
                                    }
                                }
                            });
                    };

                    if ($hasMetadataFilter) {
                        $candidateQuery->orWhere($searchQuery);
                    } else {
                        $candidateQuery->where($searchQuery);
                    }
                }
            });

        if (
            is_array($requirementEmbedding)
            && $requirementEmbedding !== []
            && count($requirementEmbedding) === 1536
            && Schema::hasColumn('knowledge_item_chunks', 'embedding_vector_pgvector')
        ) {
            $vectorLiteral = PgVector::literal($requirementEmbedding);

            $query->selectRaw(
                'CASE WHEN knowledge_item_chunks.embedding_vector_pgvector IS NULL THEN NULL ELSE 1 - (knowledge_item_chunks.embedding_vector_pgvector <=> ?::vector) END as embedding_similarity',
                [$vectorLiteral],
            );
            $query->reorder();
            $query->orderByRaw('CASE WHEN knowledge_item_chunks.embedding_vector_pgvector IS NULL THEN 1 ELSE 0 END');
            $query->orderByRaw('knowledge_item_chunks.embedding_vector_pgvector <=> ?::vector', [$vectorLiteral]);
            $query->orderByDesc('knowledge_items.updated_at');
            $query->orderByDesc('knowledge_items.id');
            $query->orderBy('knowledge_item_chunks.chunk_index');
            $query->orderBy('knowledge_item_chunks.id');
        }

        $rawRows = $query
            ->limit(self::MAX_QUERY_ROWS)
            ->get()
            ->map(fn (KnowledgeItemChunk $chunk): array => $this->chunkRowFromModel($chunk))
            ->values();

        if ($rawRows->isEmpty()) {
            Log::info('[PROCYNIA][METADATA_RETRIEVAL] Metadata candidate retrieval returned no rows.', [
                'customer_id' => $customerId,
                'selected_field_count' => count($selectedMetadata),
                'selected_value_count' => $this->countSelectedValues($selectedMetadata),
            ]);

            return collect();
        }

        $rankedRows = $rawRows
            ->map(function (array $row) use ($selectedMetadata, $searchTerms): array {
                [$metadataScore, $metadataMatches] = $this->metadataScoreForRow($row, $selectedMetadata);
                [$directEvidenceScore, $directEvidenceMatches] = $this->directEvidenceScoreForRow($row, $searchTerms);

                $row['metadata_score'] = min(self::MAX_METADATA_SCORE, $metadataScore + $directEvidenceScore);
                $row['metadata_matches'] = array_merge($metadataMatches, $directEvidenceMatches);
                $row['direct_evidence_score'] = $directEvidenceScore;
                $row['direct_evidence_matches'] = $directEvidenceMatches;

                return $row;
            })
            ->filter(static fn (array $row): bool => (float) data_get($row, 'metadata_score', 0) > 0)
            ->sort(function (array $left, array $right): int {
                if (abs(((float) data_get($left, 'metadata_score', 0)) - ((float) data_get($right, 'metadata_score', 0))) > 0.000001) {
                    return (float) data_get($right, 'metadata_score', 0) <=> (float) data_get($left, 'metadata_score', 0);
                }

                if (data_get($left, 'knowledge_item_updated_at') !== data_get($right, 'knowledge_item_updated_at')) {
                    return strcmp((string) data_get($right, 'knowledge_item_updated_at', ''), (string) data_get($left, 'knowledge_item_updated_at', ''));
                }

                if ((int) data_get($left, 'knowledge_item_id', 0) !== (int) data_get($right, 'knowledge_item_id', 0)) {
                    return (int) data_get($right, 'knowledge_item_id', 0) <=> (int) data_get($left, 'knowledge_item_id', 0);
                }

                if ((int) data_get($left, 'chunk_index', 0) !== (int) data_get($right, 'chunk_index', 0)) {
                    return (int) data_get($left, 'chunk_index', 0) <=> (int) data_get($right, 'chunk_index', 0);
                }

                return (int) data_get($left, 'chunk_id', 0) <=> (int) data_get($right, 'chunk_id', 0);
            })
            ->take(self::MAX_RESULTS)
            ->values();

        Log::info('[PROCYNIA][METADATA_RETRIEVAL] Metadata candidates retrieved.', [
            'customer_id' => $customerId,
            'selected_field_count' => count($selectedMetadata),
            'selected_value_count' => $this->countSelectedValues($selectedMetadata),
            'search_text' => $searchText,
            'search_term_count' => count($searchTerms),
            'candidate_count_before_metadata_score' => $rawRows->count(),
            'candidate_count_after_metadata_score' => $rankedRows->count(),
            'top_candidate_ids_before_metadata_score' => $rawRows->take(10)->pluck('chunk_id')->all(),
            'top_candidate_ids_after_metadata_score' => $rankedRows->take(10)->pluck('chunk_id')->all(),
        ]);

        return $rankedRows;
    }

    /**
     * Purpose: Resolve the candidate query scope shared with the base retrieval flow.
     * Inputs: The customer id.
     * Returns: A constrained query builder for active, completed knowledge chunks.
     * Side effects: None.
     */
    private function baseQuery(int $customerId): Builder
    {
        return KnowledgeItemChunk::query()
            ->join('knowledge_items', 'knowledge_items.id', '=', 'knowledge_item_chunks.knowledge_item_id')
            ->join('knowledge_item_versions', function (JoinClause $join): void {
                $join->on('knowledge_item_versions.id', '=', 'knowledge_item_chunks.knowledge_item_version_id')
                    ->on('knowledge_item_versions.knowledge_item_id', '=', 'knowledge_items.id')
                    ->where('knowledge_item_versions.is_current', true);
            })
            ->where('knowledge_items.customer_id', $customerId)
            ->where('knowledge_items.ownership_type', KnowledgeItem::OWNERSHIP_TYPE_COMPANY)
            ->where('knowledge_items.is_active', true)
            ->where('knowledge_items.ai_usage_enabled', true)
            ->where('knowledge_items.document_status', KnowledgeItem::DOCUMENT_STATUS_ACTIVE)
            ->whereNotNull('knowledge_items.storage_path')
            ->where('knowledge_items.extraction_status', KnowledgeItem::EXTRACTION_STATUS_COMPLETED)
            ->orderByDesc('knowledge_items.updated_at')
            ->orderByDesc('knowledge_items.id')
            ->orderBy('knowledge_item_chunks.chunk_index')
            ->orderBy('knowledge_item_chunks.id');
    }

    /**
     * Purpose: Return the columns needed by the retrieval pipeline.
     * Inputs: None.
     * Returns: The select list used for chunk retrieval.
     * Side effects: None.
     */
    private function selectColumns(): array
    {
        return [
            'knowledge_item_chunks.*',
            'knowledge_items.original_filename as knowledge_item_title',
            'knowledge_items.document_type as content_type',
            'knowledge_items.summary as knowledge_item_summary',
            'knowledge_items.updated_at as knowledge_item_updated_at',
        ];
    }

    /**
     * Purpose: Convert one chunk model into the deterministic retrieval row shape.
     * Inputs: The retrieved chunk model.
     * Returns: The array row used by the matcher and the controller.
     * Side effects: None.
     */
    private function chunkRowFromModel(KnowledgeItemChunk $chunk): array
    {
        $content = trim((string) $chunk->content);
        $keywords = $this->knowledgeChunkCoverageService->normalizeKeywords($chunk->keywords) ?? [];

        return [
            'chunk_id' => (int) $chunk->id,
            'knowledge_item_id' => (int) $chunk->knowledge_item_id,
            'knowledge_item_title' => (string) $chunk->getAttribute('knowledge_item_title'),
            'content_type' => (string) $chunk->getAttribute('content_type'),
            'knowledge_item_summary' => (string) $chunk->getAttribute('knowledge_item_summary'),
            'chunk_index' => (int) $chunk->chunk_index,
            'chunk_type' => (string) ($chunk->chunk_type ?? 'semantic'),
            'content' => $content,
            'title' => (string) ($chunk->title ?? ''),
            'summary_for_retrieval' => (string) ($chunk->summary_for_retrieval ?? ''),
            'table_text' => (string) ($chunk->table_text ?? ''),
            'table_html' => (string) ($chunk->table_html ?? ''),
            'table_json' => is_array($chunk->table_json) ? $chunk->table_json : null,
            'image_path' => (string) ($chunk->image_path ?? ''),
            'image_disk' => (string) ($chunk->image_disk ?? ''),
            'image_mime_type' => (string) ($chunk->image_mime_type ?? ''),
            'image_original_filename' => (string) ($chunk->image_original_filename ?? ''),
            'image_width' => is_numeric($chunk->image_width ?? null) ? (int) $chunk->image_width : null,
            'image_height' => is_numeric($chunk->image_height ?? null) ? (int) $chunk->image_height : null,
            'image_hash' => (string) ($chunk->image_hash ?? ''),
            'image_metadata' => is_array($chunk->image_metadata) ? $chunk->image_metadata : null,
            'image_alt_text' => (string) ($chunk->image_alt_text ?? ''),
            'image_caption' => (string) ($chunk->image_caption ?? ''),
            'ocr_text' => (string) ($chunk->ocr_text ?? ''),
            'image_description' => (string) ($chunk->image_description ?? ''),
            'topic' => (string) ($chunk->topic ?? ''),
            'sub_topic' => (string) ($chunk->sub_topic ?? ''),
            'service_product_tag' => (string) ($chunk->service_product_tag ?? ''),
            'theme_tag' => (string) ($chunk->theme_tag ?? ''),
            'keywords' => $keywords,
            'section_title' => (string) ($chunk->section_title ?? ''),
            'section_path' => (string) ($chunk->section_path ?? ''),
            'embedding_vector' => is_array($chunk->embedding_vector) ? $chunk->embedding_vector : null,
            'embedding_vector_pgvector' => is_array($chunk->embedding_vector_pgvector) ? $chunk->embedding_vector_pgvector : null,
            'embedding_similarity' => is_numeric($chunk->getAttribute('embedding_similarity'))
                ? (float) $chunk->getAttribute('embedding_similarity')
                : null,
            'embedding_model' => (string) ($chunk->embedding_model ?? ''),
            'embedding_generated_at' => optional($chunk->embedding_generated_at)?->toIso8601String(),
            'embedding_error' => $chunk->embedding_error,
            'knowledge_item_updated_at' => (string) $chunk->getAttribute('knowledge_item_updated_at'),
        ];
    }

    /**
     * Purpose: Resolve the validated selected metadata from the retrieval plan.
     * Inputs: The validated plan payload.
     * Returns: A field-keyed map of selected metadata values.
     * Side effects: None.
     */
    private function selectedMetadataFromPlan(array $validatedPlan): array
    {
        $selectedMetadata = data_get($validatedPlan, 'selected_metadata', []);

        if (! is_array($selectedMetadata)) {
            return [];
        }

        $normalized = [];

        foreach ($selectedMetadata as $field => $values) {
            if (! is_string($field) || ! $this->isSupportedField($field) || ! is_array($values)) {
                continue;
            }

            $cleanValues = [];
            $seen = [];

            foreach ($values as $value) {
                if (! is_string($value)) {
                    continue;
                }

                $normalizedValue = $this->normalizeComparableValue($value);

                if ($normalizedValue === '' || isset($seen[$normalizedValue])) {
                    continue;
                }

                $seen[$normalizedValue] = true;
                $cleanValues[] = trim(Str::squish($value));
            }

            if ($cleanValues !== []) {
                $normalized[$field] = $cleanValues;
            }
        }

        return $normalized;
    }

    /**
     * Purpose: Resolve the free-text retrieval hint from the validated plan.
     * Inputs: The validated plan payload.
     * Returns: A trimmed search string or an empty string.
     * Side effects: None.
     */
    private function searchTextFromPlan(array $validatedPlan): string
    {
        $searchText = data_get($validatedPlan, 'search_text', '');

        if (! is_string($searchText)) {
            return '';
        }

        return trim(Str::squish($searchText));
    }

    /**
     * Purpose: Split a search hint into stable tokens for direct evidence matching.
     * Inputs: A free-text search string.
     * Returns: A deduplicated list of normalized search terms.
     * Side effects: None.
     */
    private function searchTermsFromText(string $searchText): array
    {
        $searchText = mb_strtolower($searchText, 'UTF-8');
        $searchText = preg_replace('/[^\pL\pN\s]+/u', ' ', $searchText) ?? $searchText;
        $searchText = preg_replace('/\s+/u', ' ', $searchText) ?? $searchText;
        $searchText = trim($searchText);

        if ($searchText === '') {
            return [];
        }

        $tokens = preg_split('/\s+/u', $searchText, -1, PREG_SPLIT_NO_EMPTY);

        if (! is_array($tokens)) {
            return [];
        }

        $seen = [];
        $filtered = [];

        foreach ($tokens as $token) {
            $token = trim($token);

            if ($token === '' || mb_strlen($token, 'UTF-8') < 3 || in_array($token, self::SEARCH_STOPWORDS, true)) {
                continue;
            }

            if (isset($seen[$token])) {
                continue;
            }

            $seen[$token] = true;
            $filtered[] = $token;
        }

        return $filtered;
    }

    /**
     * Purpose: Apply direct searchable-evidence filters for one search term.
     * Inputs: The query builder, the normalized LIKE pattern, and the normalized search term.
     * Returns: None.
     * Side effects: Adds an OR group that matches visible chunk text fields or keyword JSON text.
     */
    private function applyDirectEvidenceFilters(Builder $fieldQuery, string $likeTerm): void
    {
        $fieldQuery
            ->whereRaw('LOWER(COALESCE(knowledge_item_chunks.title, \'\')) LIKE ?', [$likeTerm])
            ->orWhereRaw('LOWER(COALESCE(knowledge_item_chunks.content, \'\')) LIKE ?', [$likeTerm])
            ->orWhereRaw('LOWER(COALESCE(knowledge_item_chunks.summary_for_retrieval, \'\')) LIKE ?', [$likeTerm])
            ->orWhereRaw('LOWER(COALESCE(knowledge_item_chunks.table_text, \'\')) LIKE ?', [$likeTerm])
            ->orWhereRaw("LOWER(COALESCE(knowledge_item_chunks.keywords::text, '[]')) LIKE ?", [$likeTerm]);
    }

    /**
     * Purpose: Decide whether a metadata field is supported by the current chunk schema.
     * Inputs: The candidate field name.
     * Returns: True when the field can safely be used for retrieval.
     * Side effects: None.
     */
    private function isSupportedField(string $field): bool
    {
        return in_array($field, array_values(array_filter(array_keys(self::FIELD_WEIGHTS), static fn (string $supportedField): bool => Schema::hasColumn('knowledge_item_chunks', $supportedField))), true);
    }

    /**
     * Purpose: Compute the metadata affinity score for one chunk.
     * Inputs: The candidate row and the validated selected metadata.
     * Returns: The metadata score and the matched metadata values.
     * Side effects: None.
     */
    private function metadataScoreForRow(array $row, array $selectedMetadata): array
    {
        $metadataScore = 0.0;
        $metadataMatches = [];

        foreach ($selectedMetadata as $field => $selectedValues) {
            $selectedLookup = $this->normalizeValueLookup($selectedValues);
            $rowLookup = $this->normalizeValueLookup($this->fieldValuesForRow($row, $field));

            if ($selectedLookup === [] || $rowLookup === []) {
                continue;
            }

            $matchedValues = array_values(array_intersect_key($selectedLookup, $rowLookup));

            if ($matchedValues === []) {
                continue;
            }

            $metadataMatches[$field] = $matchedValues;
            $fieldWeight = self::FIELD_WEIGHTS[$field] ?? 0.5;
            $matchRatio = min(1.0, count($matchedValues) / max(1, count($selectedLookup)));
            $metadataScore += $fieldWeight * $matchRatio;
        }

        $metadataScore = min($metadataScore, self::MAX_METADATA_SCORE);

        return [$metadataScore, $metadataMatches];
    }

    /**
     * Purpose: Compute a small direct-evidence score for searchable chunk text.
     * Inputs: The candidate row and the free-text search terms from the plan.
     * Returns: The direct evidence score and the matched direct-evidence values.
     * Side effects: None.
     */
    private function directEvidenceScoreForRow(array $row, array $searchTerms): array
    {
        if ($searchTerms === []) {
            return [0.0, []];
        }

        $searchLookup = $this->normalizeValueLookup($searchTerms);
        $directEvidenceScore = 0.0;
        $directEvidenceMatches = [];

        foreach (self::DIRECT_EVIDENCE_FIELD_WEIGHTS as $field => $fieldWeight) {
            $fieldTerms = $this->searchableTermsFromValue(data_get($row, $field));
            $fieldLookup = $this->normalizeValueLookup($fieldTerms);

            if ($fieldLookup === []) {
                continue;
            }

            $matchedTerms = array_values(array_intersect_key($searchLookup, $fieldLookup));

            if ($matchedTerms === []) {
                continue;
            }

            $directEvidenceMatches[$field] = $matchedTerms;
            $matchRatio = min(1.0, count($matchedTerms) / max(1, count($searchLookup)));
            $directEvidenceScore += $fieldWeight * $matchRatio;
        }

        return [min($directEvidenceScore, self::MAX_DIRECT_EVIDENCE_SCORE), $directEvidenceMatches];
    }

    /**
     * Purpose: Build a case-insensitive lookup table for one metadata value list.
     * Inputs: A list of values.
     * Returns: A normalized lookup keyed by comparison token.
     * Side effects: None.
     */
    private function normalizeValueLookup(array $values): array
    {
        $lookup = [];

        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }

            $normalizedValue = $this->normalizeComparableValue($value);

            if ($normalizedValue === '' || isset($lookup[$normalizedValue])) {
                continue;
            }

            $lookup[$normalizedValue] = trim(Str::squish($value));
        }

        return $lookup;
    }

    /**
     * Purpose: Resolve the values from one chunk for a metadata field.
     * Inputs: The candidate row and the field name.
     * Returns: A cleaned list of field values from the row.
     * Side effects: None.
     */
    private function fieldValuesForRow(array $row, string $field): array
    {
        if ($field === 'keywords') {
            return $this->knowledgeChunkCoverageService->normalizeKeywords(data_get($row, $field)) ?? [];
        }

        $value = data_get($row, $field);

        if (is_array($value)) {
            $value = implode(' ', array_map(static fn (mixed $item): string => trim((string) $item), $value));
        }

        $normalized = trim(Str::squish((string) $value));

        return $normalized !== '' ? [$normalized] : [];
    }

    /**
     * Purpose: Resolve searchable terms from one chunk field for direct evidence matching.
     * Inputs: The raw row field value.
     * Returns: A list of normalized searchable terms.
     * Side effects: None.
     */
    private function searchableTermsFromValue(mixed $value): array
    {
        if (is_array($value)) {
            $terms = [];

            foreach ($value as $item) {
                $terms = array_merge($terms, $this->searchTermsFromText((string) $item));
            }

            return $this->uniqueTerms($terms);
        }

        return $this->searchTermsFromText((string) $value);
    }

    /**
     * Purpose: Normalize a value for case-insensitive metadata comparison.
     * Inputs: A raw scalar value.
     * Returns: A lowercased comparison token.
     * Side effects: None.
     */
    private function normalizeComparableValue(mixed $value): string
    {
        return mb_strtolower(trim(Str::squish((string) $value)), 'UTF-8');
    }

    /**
     * Purpose: Normalize a token list into unique non-empty terms.
     * Inputs: A list of candidate terms.
     * Returns: A deduplicated list of terms.
     * Side effects: None.
     */
    private function uniqueTerms(array $terms): array
    {
        return array_values(array_unique(array_filter($terms, static fn (string $term): bool => $term !== '')));
    }

    /**
     * Purpose: Count the total number of selected values across all fields.
     * Inputs: The validated selected metadata.
     * Returns: The total selected-value count.
     * Side effects: None.
     */
    private function countSelectedValues(array $selectedMetadata): int
    {
        $count = 0;

        foreach ($selectedMetadata as $values) {
            if (! is_array($values)) {
                continue;
            }

            $count += count(array_filter($values, static fn (mixed $value): bool => is_string($value) && trim($value) !== ''));
        }

        return $count;
    }
}
