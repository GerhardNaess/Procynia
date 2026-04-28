<?php

namespace App\Services\Ai\Retrieval;

use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemChunk;
use App\Services\KnowledgeChunkCoverageService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MetadataCandidateRetrievalService
{
    private const MAX_QUERY_ROWS = 1000;

    private const MAX_RESULTS = 200;

    private const MAX_METADATA_SCORE = 5.0;

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
    public function retrieveForCustomer(int $customerId, array $validatedPlan): Collection
    {
        $selectedMetadata = $this->selectedMetadataFromPlan($validatedPlan);

        if ($selectedMetadata === []) {
            return collect();
        }

        $query = $this->baseQuery($customerId)
            ->where(function (Builder $metadataQuery) use ($selectedMetadata): void {
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

        $rawRows = $query
            ->limit(self::MAX_QUERY_ROWS)
            ->get($this->selectColumns())
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
            ->map(function (array $row) use ($selectedMetadata): array {
                [$metadataScore, $metadataMatches] = $this->metadataScoreForRow($row, $selectedMetadata);

                $row['metadata_score'] = $metadataScore;
                $row['metadata_matches'] = $metadataMatches;

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
            ->where('knowledge_items.customer_id', $customerId)
            ->where('knowledge_items.is_active', true)
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
            'content' => $content,
            'title' => (string) ($chunk->title ?? ''),
            'topic' => (string) ($chunk->topic ?? ''),
            'sub_topic' => (string) ($chunk->sub_topic ?? ''),
            'service_product_tag' => (string) ($chunk->service_product_tag ?? ''),
            'theme_tag' => (string) ($chunk->theme_tag ?? ''),
            'keywords' => $keywords,
            'section_title' => (string) ($chunk->section_title ?? ''),
            'section_path' => (string) ($chunk->section_path ?? ''),
            'embedding_vector' => is_array($chunk->embedding_vector) ? $chunk->embedding_vector : null,
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
