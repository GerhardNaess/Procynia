<?php

namespace App\Support;

use RuntimeException;

class PgVector
{
    /**
     * Purpose: Encode one numeric embedding vector into the literal format expected by pgvector.
     * Inputs: A numeric embedding vector.
     * Returns: A JSON-array literal accepted by PostgreSQL pgvector.
     * Side effects: None.
     */
    public static function literal(array $vector): string
    {
        $normalized = [];

        foreach (array_values($vector) as $index => $value) {
            if (is_int($value) || is_float($value)) {
                self::assertFinite($value, $index);
                $normalized[] = $value;

                continue;
            }

            if (is_string($value) && is_numeric($value)) {
                $numeric = str_contains(strtolower($value), 'e') || str_contains($value, '.')
                    ? (float) $value
                    : (int) $value;

                self::assertFinite($numeric, $index);
                $normalized[] = $numeric;

                continue;
            }

            throw new RuntimeException(sprintf('Invalid pgvector value at index %d.', $index));
        }

        $literal = json_encode($normalized, JSON_PRESERVE_ZERO_FRACTION);

        if (! is_string($literal) || $literal === '') {
            throw new RuntimeException('Failed to encode pgvector literal.');
        }

        return $literal;
    }

    /**
     * Purpose: Reject non-finite vector values before encoding them.
     * Inputs: One numeric vector element and its index.
     * Returns: None.
     * Side effects: Throws when the value cannot be represented safely.
     */
    private static function assertFinite(int|float $value, int $index): void
    {
        if (is_float($value) && (is_nan($value) || is_infinite($value))) {
            throw new RuntimeException(sprintf('Invalid pgvector value at index %d.', $index));
        }
    }
}
