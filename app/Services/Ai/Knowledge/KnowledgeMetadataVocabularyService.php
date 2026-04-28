<?php

namespace App\Services\Ai\Knowledge;

use App\Models\KnowledgeMetadataTerm;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class KnowledgeMetadataVocabularyService
{
    private const SUPPORTED_FIELDS = [
        'service_product_tag',
        'theme_tag',
        'topic',
        'sub_topic',
        'keywords',
    ];

    private const FIELD_ALIASES = [
        'keyword' => 'keywords',
    ];

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $customerCache = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $catalogCache = [];

    /**
     * Purpose: Build an approved metadata vocabulary map for one customer.
     * Inputs: The customer id.
     * Returns: A structured vocabulary map used by the metadata generator.
     * Side effects: Logs vocabulary inventory statistics only.
     */
    public function buildForCustomer(int $customerId): array
    {
        if (isset($this->customerCache[$customerId])) {
            return $this->customerCache[$customerId];
        }

        $fields = $this->emptyFieldMap();
        $terms = $this->emptyFieldMap();
        $lookup = $this->emptyLookupMap();

        $rows = KnowledgeMetadataTerm::query()
            ->where('customer_id', $customerId)
            ->where('approved', true)
            ->orderBy('type')
            ->orderBy('canonical_name')
            ->get();

        foreach ($rows as $row) {
            $field = $this->normalizeField((string) $row->type);

            if ($field === '' || ! array_key_exists($field, $fields)) {
                continue;
            }

            $canonicalName = trim((string) $row->canonical_name);

            if ($canonicalName === '') {
                continue;
            }

            $synonyms = $this->normalizeStringList($row->synonyms);

            $fields[$field][] = $canonicalName;
            $terms[$field][] = [
                'canonical_name' => $canonicalName,
                'synonyms' => $synonyms,
                'description' => $this->normalizeNullableString($row->description),
                'approved' => (bool) $row->approved,
            ];

            foreach (array_merge([$canonicalName], $synonyms) as $term) {
                $lookup[$field][$this->comparisonKey($term)] = $canonicalName;
            }
        }

        foreach ($fields as $field => $values) {
            $fields[$field] = $this->uniqueSortedValues($values);
        }

        $fieldCounts = [];

        foreach ($fields as $field => $values) {
            $fieldCounts[$field] = count($values);
        }

        $map = [
            'customer_id' => $customerId,
            'available_fields' => $this->availableFields(),
            'fields' => $fields,
            'terms' => $terms,
            'lookup' => $lookup,
            'field_counts' => $fieldCounts,
        ];

        Log::info('[PROCYNIA][KNOWLEDGE_METADATA] Approved vocabulary map built.', [
            'customer_id' => $customerId,
            'available_field_count' => count($map['available_fields']),
            'term_count' => array_sum($fieldCounts),
            'field_counts' => $fieldCounts,
        ]);

        return $this->customerCache[$customerId] = $map;
    }

    /**
     * Purpose: Build a full approved vocabulary catalog for one customer.
     * Inputs: The customer id.
     * Returns: All approved terms grouped by authoritative metadata type.
     * Side effects: Logs vocabulary inventory statistics only.
     */
    public function buildCatalogForCustomer(int $customerId): array
    {
        if (isset($this->catalogCache[$customerId])) {
            return $this->catalogCache[$customerId];
        }

        $types = $this->catalogTypes();
        $groups = array_fill_keys($types, []);
        $lookup = array_fill_keys($types, []);

        $rows = KnowledgeMetadataTerm::query()
            ->where('customer_id', $customerId)
            ->where('approved', true)
            ->orderBy('type')
            ->orderBy('canonical_name')
            ->get();

        foreach ($rows as $row) {
            $type = $this->normalizeCatalogType((string) $row->type);

            if ($type === '' || ! array_key_exists($type, $groups)) {
                continue;
            }

            $canonicalName = trim((string) $row->canonical_name);

            if ($canonicalName === '') {
                continue;
            }

            $synonyms = $this->normalizeStringList($row->synonyms);
            $term = [
                'id' => (int) $row->id,
                'customer_id' => $customerId,
                'type' => $type,
                'canonical_name' => $canonicalName,
                'synonyms' => $synonyms,
                'description' => $this->normalizeNullableString($row->description),
                'approved' => (bool) $row->approved,
            ];

            $groups[$type][] = $term;

            foreach (array_merge([$canonicalName], $synonyms) as $candidate) {
                $lookup[$type][$this->comparisonKey($candidate)] = $term;
            }
        }

        foreach ($groups as $type => $terms) {
            usort(
                $groups[$type],
                static function (array $left, array $right): int {
                    return strcmp(
                        mb_strtolower((string) data_get($left, 'canonical_name', ''), 'UTF-8'),
                        mb_strtolower((string) data_get($right, 'canonical_name', ''), 'UTF-8'),
                    );
                },
            );
        }

        $typeCounts = [];

        foreach ($groups as $type => $terms) {
            $typeCounts[$type] = count($terms);
        }

        $catalog = [
            'customer_id' => $customerId,
            'types' => $types,
            'groups' => $groups,
            'lookup' => $lookup,
            'type_counts' => $typeCounts,
        ];

        Log::info('[PROCYNIA][KNOWLEDGE_METADATA] Approved vocabulary catalog built.', [
            'customer_id' => $customerId,
            'type_count' => count($types),
            'term_count' => array_sum($typeCounts),
            'type_counts' => $typeCounts,
        ]);

        return $this->catalogCache[$customerId] = $catalog;
    }

    /**
     * Purpose: Return the metadata fields that exist on the current chunk schema.
     * Inputs: None.
     * Returns: A deterministic list of supported metadata field names.
     * Side effects: None.
     */
    public function availableFields(): array
    {
        return array_values(array_filter(
            self::SUPPORTED_FIELDS,
            static fn (string $field): bool => Schema::hasColumn('knowledge_item_chunks', $field),
        ));
    }

    /**
     * Purpose: Resolve one raw value against the approved vocabulary map for a field.
     * Inputs: The vocabulary map, a field name, and the candidate value.
     * Returns: The approved canonical name when a match exists, otherwise null.
     * Side effects: None.
     */
    public function resolveCanonicalValue(array $vocabularyMap, string $field, string $value): ?string
    {
        $field = $this->normalizeField($field);
        $normalizedValue = $this->comparisonKey($value);

        if ($field === '' || $normalizedValue === '') {
            return null;
        }

        $lookup = data_get($vocabularyMap, 'lookup.'.$field, []);

        if (! is_array($lookup)) {
            return null;
        }

        $canonical = $lookup[$normalizedValue] ?? null;

        return is_string($canonical) && trim($canonical) !== '' ? trim($canonical) : null;
    }

    /**
     * Purpose: Resolve a raw value against the approved vocabulary catalog for a type.
     * Inputs: The catalog, a type name, and the candidate value.
     * Returns: The matching approved term row or null.
     * Side effects: None.
     */
    public function resolveCatalogTerm(array $catalog, string $type, string $value): ?array
    {
        $type = $this->normalizeCatalogType($type);
        $normalizedValue = $this->comparisonKey($value);

        if ($type === '' || $normalizedValue === '') {
            return null;
        }

        $lookup = data_get($catalog, 'lookup.'.$type, []);

        if (! is_array($lookup)) {
            return null;
        }

        $term = $lookup[$normalizedValue] ?? null;

        return is_array($term) ? $term : null;
    }

    /**
     * Purpose: Return an empty field map for all supported metadata fields.
     * Inputs: None.
     * Returns: A field-keyed map with empty arrays.
     * Side effects: None.
     */
    private function emptyFieldMap(): array
    {
        return array_fill_keys($this->availableFields(), []);
    }

    /**
     * Purpose: Return an empty lookup map for all supported metadata fields.
     * Inputs: None.
     * Returns: A field-keyed map with empty arrays.
     * Side effects: None.
     */
    private function emptyLookupMap(): array
    {
        return array_fill_keys($this->availableFields(), []);
    }

    /**
     * Purpose: Return all authoritative metadata types for the catalog builder.
     * Inputs: None.
     * Returns: A deterministic list of supported vocabulary types.
     * Side effects: None.
     */
    private function catalogTypes(): array
    {
        return KnowledgeMetadataTerm::TYPES;
    }

    /**
     * Purpose: Normalize a metadata field name to a supported field key.
     * Inputs: A raw field name.
     * Returns: A normalized field key or an empty string.
     * Side effects: None.
     */
    private function normalizeField(string $field): string
    {
        $field = trim(mb_strtolower(Str::squish($field), 'UTF-8'));
        $field = self::FIELD_ALIASES[$field] ?? $field;

        return in_array($field, $this->availableFields(), true) ? $field : '';
    }

    /**
     * Purpose: Normalize a vocabulary type to a supported authoritative type key.
     * Inputs: A raw type name.
     * Returns: A normalized type key or an empty string.
     * Side effects: None.
     */
    private function normalizeCatalogType(string $type): string
    {
        $type = trim(mb_strtolower(Str::squish($type), 'UTF-8'));

        return in_array($type, $this->catalogTypes(), true) ? $type : '';
    }

    /**
     * Purpose: Normalize a persisted synonym list into trimmed string values.
     * Inputs: Raw synonym data from the database.
     * Returns: A deterministic list of non-empty synonyms.
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
     * Purpose: Normalize a nullable string into a trimmed nullable value.
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
     * Purpose: Produce a stable comparison key for canonical and synonym matching.
     * Inputs: Raw text.
     * Returns: A lowercased comparison key stripped of excess whitespace and accents.
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

    /**
     * Purpose: Return a sorted unique list of values.
     * Inputs: An array of candidate strings.
     * Returns: A stable de-duplicated list.
     * Side effects: None.
     */
    private function uniqueSortedValues(array $values): array
    {
        $seen = [];

        foreach ($values as $value) {
            $cleanValue = trim((string) $value);

            if ($cleanValue === '') {
                continue;
            }

            $seen[$this->comparisonKey($cleanValue)] = $cleanValue;
        }

        $normalized = array_values($seen);
        usort($normalized, static fn (string $left, string $right): int => strcmp(mb_strtolower($left, 'UTF-8'), mb_strtolower($right, 'UTF-8')));

        return $normalized;
    }
}
