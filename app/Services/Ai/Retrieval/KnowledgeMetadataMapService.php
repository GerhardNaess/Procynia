<?php

namespace App\Services\Ai\Retrieval;

use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemChunk;
use App\Services\KnowledgeChunkCoverageService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class KnowledgeMetadataMapService
{
    /**
     * @var list<string>
     */
    private const METADATA_FIELDS = [
        'topic',
        'sub_topic',
        'keywords',
        'service_product_tag',
        'theme_tag',
        'section_title',
        'section_path',
    ];

    public function __construct(
        private readonly KnowledgeChunkCoverageService $knowledgeChunkCoverageService,
    ) {
    }

    /**
     * Purpose: Build a controlled metadata map for one customer's active knowledge chunks.
     * Inputs: The customer id whose active knowledge chunks should be inspected.
     * Returns: A structured map of available metadata fields and values.
     * Side effects: Logs metadata inventory stats only.
     */
    public function buildForCustomer(int $customerId): array
    {
        $chunks = $this->activeChunksForCustomer($customerId);
        $fields = [];
        $fieldCounts = [];

        foreach ($this->metadataFields() as $field) {
            $values = $this->distinctValuesForField($chunks, $field);
            $fields[$field] = $values;
            $fieldCounts[$field] = count($values);
        }

        $map = [
            'customer_id' => $customerId,
            'chunk_count' => $chunks->count(),
            'fields' => $fields,
            'field_counts' => $fieldCounts,
        ];

        Log::info('[PROCYNIA][METADATA_RETRIEVAL] Metadata map built.', [
            'customer_id' => $customerId,
            'chunk_count' => $map['chunk_count'],
            'field_count' => count($fields),
            'field_counts' => $fieldCounts,
        ]);

        return $map;
    }

    /**
     * Purpose: Resolve the active, completed knowledge chunks for one customer.
     * Inputs: The customer id.
     * Returns: The current knowledge chunk rows that can participate in retrieval.
     * Side effects: None.
     */
    private function activeChunksForCustomer(int $customerId): Collection
    {
        return KnowledgeItemChunk::query()
            ->join('knowledge_items', 'knowledge_items.id', '=', 'knowledge_item_chunks.knowledge_item_id')
            ->where('knowledge_items.customer_id', $customerId)
            ->where('knowledge_items.ownership_type', KnowledgeItem::OWNERSHIP_TYPE_COMPANY)
            ->where('knowledge_items.ai_usage_enabled', true)
            ->where('knowledge_items.document_status', KnowledgeItem::DOCUMENT_STATUS_ACTIVE)
            ->whereNotNull('knowledge_items.storage_path')
            ->where('knowledge_items.extraction_status', KnowledgeItem::EXTRACTION_STATUS_COMPLETED)
            ->orderByDesc('knowledge_items.updated_at')
            ->orderByDesc('knowledge_items.id')
            ->orderBy('knowledge_item_chunks.chunk_index')
            ->orderBy('knowledge_item_chunks.id')
            ->limit(1000)
            ->get([
                'knowledge_item_chunks.*',
                'knowledge_items.original_filename as knowledge_item_title',
                'knowledge_items.summary as knowledge_item_summary',
                'knowledge_items.updated_at as knowledge_item_updated_at',
            ])
            ->values();
    }

    /**
     * Purpose: Return the metadata fields that exist on the current schema.
     * Inputs: None.
     * Returns: A deterministic list of metadata field names.
     * Side effects: None.
     */
    private function metadataFields(): array
    {
        return array_values(array_filter(self::METADATA_FIELDS, static fn (string $field): bool => Schema::hasColumn('knowledge_item_chunks', $field)));
    }

    /**
     * Purpose: Extract a stable set of distinct values for one metadata field.
     * Inputs: Retrieved knowledge chunks and one metadata field name.
     * Returns: A sorted list of unique non-empty values.
     * Side effects: None.
     */
    private function distinctValuesForField(Collection $chunks, string $field): array
    {
        $seen = [];

        foreach ($chunks as $chunk) {
            $values = $this->valuesForField($field, $chunk);

            foreach ($values as $value) {
                $key = mb_strtolower($value, 'UTF-8');

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = $value;
            }
        }

        $values = array_values($seen);
        usort($values, static fn (string $left, string $right): int => strcmp(mb_strtolower($left, 'UTF-8'), mb_strtolower($right, 'UTF-8')));

        return $values;
    }

    /**
     * Purpose: Normalize one metadata field from a chunk into comparable values.
     * Inputs: The field name and the raw chunk row.
     * Returns: One or more cleaned strings suitable for a metadata map.
     * Side effects: None.
     */
    private function valuesForField(string $field, mixed $chunk): array
    {
        if ($field === 'keywords') {
            return $this->knowledgeChunkCoverageService->normalizeKeywords(data_get($chunk, $field)) ?? [];
        }

        $value = data_get($chunk, $field);

        if (is_array($value)) {
            $value = implode(' ', array_map(static fn (mixed $item): string => trim((string) $item), $value));
        }

        $normalized = trim(Str::squish((string) $value));

        return $normalized !== '' ? [$normalized] : [];
    }
}
