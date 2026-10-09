<?php

namespace App\Support\Statistics;

/**
 * Descriptive statistics over a list of numbers: count, mean, median, p75, p95, min, max.
 *
 * Percentiles interpolate linearly between the closest ranks — the same definition as PostgreSQL's
 * PERCENTILE_CONT — so a figure computed here and one computed in SQL agree.
 */
final class Distribution
{
    /**
     * @param  iterable<int|float>  $values
     * @return array{n: int, mean: ?float, median: ?float, p75: ?float, p95: ?float, min: ?float, max: ?float}
     */
    public static function describe(iterable $values, int $precision = 4): array
    {
        $sorted = [];

        foreach ($values as $value) {
            $sorted[] = (float) $value;
        }

        sort($sorted);
        $n = count($sorted);

        if ($n === 0) {
            return ['n' => 0, 'mean' => null, 'median' => null, 'p75' => null, 'p95' => null, 'min' => null, 'max' => null];
        }

        return [
            'n' => $n,
            'mean' => round(array_sum($sorted) / $n, $precision),
            'median' => round(self::percentile($sorted, 0.5), $precision),
            'p75' => round(self::percentile($sorted, 0.75), $precision),
            'p95' => round(self::percentile($sorted, 0.95), $precision),
            'min' => round($sorted[0], $precision),
            'max' => round($sorted[$n - 1], $precision),
        ];
    }

    /** @param list<float> $sorted ascending, non-empty */
    public static function percentile(array $sorted, float $fraction): float
    {
        $index = $fraction * (count($sorted) - 1);
        $lower = (int) floor($index);
        $upper = (int) ceil($index);

        return $sorted[$lower] + ($sorted[$upper] - $sorted[$lower]) * ($index - $lower);
    }
}
