<?php

namespace App\Support;

use RuntimeException;

class PgVector
{
    /**
     * Purpose: Encode one numeric embedding vector into the literal format expected by pgvector.
     * Inputs: A normalized numeric embedding vector.
     * Returns: A JSON-array literal that PostgreSQL pgvector accepts as input.
     * Side effects: None.
     */
    public static function literal(array $vector): string
    {
        $literal = json_encode(array_values($vector), JSON_PRESERVE_ZERO_FRACTION);

        if (! is_string($literal) || $literal === '') {
            throw new RuntimeException('Failed to encode pgvector literal.');
        }

        return $literal;
    }
}
