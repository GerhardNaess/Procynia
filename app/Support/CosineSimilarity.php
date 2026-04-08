<?php

namespace App\Support;

class CosineSimilarity
{
    /**
     * Purpose: Calculate the cosine similarity between two numeric vectors.
     * Inputs: Two equally sized numeric vectors.
     * Returns: A similarity score or null when the vectors are invalid.
     * Side effects: None.
     */
    public function calculate(array $left, array $right): ?float
    {
        $left = array_values($left);
        $right = array_values($right);
        $count = count($left);

        if ($count === 0 || $count !== count($right)) {
            return null;
        }

        $dotProduct = 0.0;
        $leftMagnitude = 0.0;
        $rightMagnitude = 0.0;

        for ($index = 0; $index < $count; $index++) {
            $leftValue = $left[$index] ?? null;
            $rightValue = $right[$index] ?? null;

            if (! $this->isNumericValue($leftValue) || ! $this->isNumericValue($rightValue)) {
                return null;
            }

            $leftFloat = (float) $leftValue;
            $rightFloat = (float) $rightValue;

            if (! is_finite($leftFloat) || ! is_finite($rightFloat)) {
                return null;
            }

            $dotProduct += $leftFloat * $rightFloat;
            $leftMagnitude += $leftFloat * $leftFloat;
            $rightMagnitude += $rightFloat * $rightFloat;
        }

        if ($leftMagnitude <= 0.0 || $rightMagnitude <= 0.0) {
            return null;
        }

        return $dotProduct / (sqrt($leftMagnitude) * sqrt($rightMagnitude));
    }

    /**
     * Purpose: Determine whether a vector entry can be safely used in cosine similarity.
     * Inputs: One candidate value from a vector.
     * Returns: True when the value is numeric and finite.
     * Side effects: None.
     */
    private function isNumericValue(mixed $value): bool
    {
        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            return false;
        }

        return is_numeric($value);
    }
}
