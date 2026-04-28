<?php

namespace App\Services\Ai\Retrieval;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MetadataRetrievalPlanValidator
{
    private const MAX_VALUES_PER_FIELD = 5;

    private const MAX_TOTAL_VALUES = 12;

    private const MAX_SEARCH_TEXT_LENGTH = 500;

    private const MAX_INTENT_SUMMARY_LENGTH = 500;

    /**
     * Purpose: Validate and normalize one AI-generated metadata retrieval plan against the known metadata inventory.
     * Inputs: The raw retrieval plan and the customer-specific metadata map.
     * Returns: A safe, stable retrieval plan that can be used for database queries.
     * Side effects: Logs the kept and discarded metadata values.
     */
    public function validate(array $retrievalPlan, array $metadataMap): array
    {
        $allowedValuesByField = $this->allowedValuesByField($metadataMap);
        $rawSelectedMetadata = data_get($retrievalPlan, 'selected_metadata', []);

        if (! is_array($rawSelectedMetadata)) {
            $rawSelectedMetadata = [];
        }

        $validatedSelectedMetadata = [];
        $discardedFields = [];
        $discardedValues = [];
        $totalSelectedValues = 0;

        foreach ($this->fieldOrder($metadataMap) as $field) {
            $allowedValues = $allowedValuesByField[$field] ?? [];
            $normalizedValues = $this->normalizeSelectedValues(
                data_get($rawSelectedMetadata, $field),
                $allowedValues,
                $field,
                $discardedValues,
            );

            if ($normalizedValues === []) {
                continue;
            }

            $trimmedValues = [];

            foreach ($normalizedValues as $value) {
                if ($totalSelectedValues >= self::MAX_TOTAL_VALUES || count($trimmedValues) >= self::MAX_VALUES_PER_FIELD) {
                    $discardedValues[] = [
                        'field' => $field,
                        'value' => $value,
                        'reason' => 'value_limit_reached',
                    ];

                    continue;
                }

                $trimmedValues[] = $value;
                $totalSelectedValues++;
            }

            if ($trimmedValues !== []) {
                $validatedSelectedMetadata[$field] = $trimmedValues;
            }
        }

        foreach (array_keys($rawSelectedMetadata) as $field) {
            if (! is_string($field) || isset($allowedValuesByField[$field])) {
                continue;
            }

            $discardedFields[] = $field;
        }

        $searchText = $this->normalizeString(data_get($retrievalPlan, 'search_text'), self::MAX_SEARCH_TEXT_LENGTH);
        $intentSummary = $this->normalizeString(data_get($retrievalPlan, 'intent_summary'), self::MAX_INTENT_SUMMARY_LENGTH);
        $confidence = $this->normalizeConfidence(data_get($retrievalPlan, 'confidence'));

        $validated = [
            'selected_metadata' => $validatedSelectedMetadata,
            'search_text' => $searchText,
            'intent_summary' => $intentSummary,
            'confidence' => $confidence,
        ];

        Log::info('[PROCYNIA][METADATA_RETRIEVAL] Retrieval plan validated.', [
            'metadata_field_count' => count($allowedValuesByField),
            'selected_field_count' => count($validatedSelectedMetadata),
            'selected_value_count' => $totalSelectedValues,
            'discarded_fields' => array_values(array_unique($discardedFields)),
            'discarded_values' => $discardedValues,
            'validated_selected_metadata' => $validatedSelectedMetadata,
            'search_text' => $searchText,
            'intent_summary' => $intentSummary,
            'confidence' => $confidence,
        ]);

        return $validated;
    }

    /**
     * Purpose: Resolve allowed metadata values from the inventory map into case-insensitive lookup tables.
     * Inputs: The metadata map from the metadata map service.
     * Returns: A field-keyed set of canonical value lookups.
     * Side effects: None.
     */
    private function allowedValuesByField(array $metadataMap): array
    {
        $fields = data_get($metadataMap, 'fields', []);

        if (! is_array($fields)) {
            return [];
        }

        $allowedValuesByField = [];

        foreach ($fields as $field => $values) {
            if (! is_string($field) || trim($field) === '' || ! is_array($values)) {
                continue;
            }

            $lookup = [];

            foreach ($values as $value) {
                if (! is_string($value)) {
                    continue;
                }

                $normalizedValue = $this->normalizeComparableValue($value);

                if ($normalizedValue === '') {
                    continue;
                }

                $lookup[$normalizedValue] = $value;
            }

            if ($lookup !== []) {
                $allowedValuesByField[$field] = $lookup;
            }
        }

        return $allowedValuesByField;
    }

    /**
     * Purpose: Preserve the original field order from the metadata map.
     * Inputs: The metadata map.
     * Returns: The deterministic ordered list of field names.
     * Side effects: None.
     */
    private function fieldOrder(array $metadataMap): array
    {
        $fields = data_get($metadataMap, 'fields', []);

        if (! is_array($fields)) {
            return [];
        }

        return array_values(array_filter(array_keys($fields), static fn (string|int $field): bool => is_string($field) && trim($field) !== ''));
    }

    /**
     * Purpose: Normalize AI-selected metadata values against the allowed values for one field.
     * Inputs: The raw selected values, the canonical lookup table, the field name, and the discard log buffer.
     * Returns: A de-duplicated list of canonical metadata values.
     * Side effects: Appends discard entries for invalid values.
     */
    private function normalizeSelectedValues(mixed $values, array $allowedValues, string $field, array &$discardedValues): array
    {
        if ($values === null) {
            return [];
        }

        if (! is_array($values)) {
            $values = [$values];
        }

        $normalizedValues = [];
        $seen = [];

        foreach ($values as $value) {
            if (! is_string($value)) {
                $discardedValues[] = [
                    'field' => $field,
                    'value' => $this->stringifyDiscardValue($value),
                    'reason' => 'invalid_type',
                ];

                continue;
            }

            $normalizedValue = $this->normalizeComparableValue($value);

            if ($normalizedValue === '' || ! isset($allowedValues[$normalizedValue])) {
                $discardedValues[] = [
                    'field' => $field,
                    'value' => trim(Str::squish($value)),
                    'reason' => 'unknown_value',
                ];

                continue;
            }

            $canonicalValue = $allowedValues[$normalizedValue];

            if (isset($seen[$normalizedValue])) {
                continue;
            }

            $seen[$normalizedValue] = true;
            $normalizedValues[] = $canonicalValue;
        }

        return $normalizedValues;
    }

    /**
     * Purpose: Normalize one comparison value into a stable lookup key.
     * Inputs: A raw scalar or string.
     * Returns: A lowercased comparison token.
     * Side effects: None.
     */
    private function normalizeComparableValue(mixed $value): string
    {
        return mb_strtolower(trim(Str::squish((string) $value)), 'UTF-8');
    }

    /**
     * Purpose: Normalize a user-facing string field to a stable bounded string.
     * Inputs: A mixed scalar value and a maximum length.
     * Returns: A trimmed string.
     * Side effects: None.
     */
    private function normalizeString(mixed $value, int $maxLength): string
    {
        if (! is_scalar($value) && $value !== null) {
            return '';
        }

        $normalized = trim(Str::squish((string) ($value ?? '')));

        if ($normalized === '') {
            return '';
        }

        if (mb_strlen($normalized, 'UTF-8') > $maxLength) {
            return mb_substr($normalized, 0, $maxLength, 'UTF-8');
        }

        return $normalized;
    }

    /**
     * Purpose: Normalize a confidence value into the 0-1 range.
     * Inputs: A raw confidence value.
     * Returns: A bounded floating-point confidence score.
     * Side effects: None.
     */
    private function normalizeConfidence(mixed $value): float
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
     * Purpose: Convert a discarded non-string value into a stable log string.
     * Inputs: A raw mixed value.
     * Returns: A short descriptive string.
     * Side effects: None.
     */
    private function stringifyDiscardValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value) || $value === null) {
            return trim(Str::squish((string) ($value ?? '')));
        }

        return get_debug_type($value);
    }
}
