<?php

namespace App\Services\Risk;

use InvalidArgumentException;

/**
 * How likelihood and consequence become a score and a level. The only place that knows.
 *
 * Today there is one set of criteria: a 5×5 scale, score = likelihood × consequence, and four
 * levels by score band. An assessment records the criteria_key it was given under, so when
 * customer-specific criteria arrive they are added here as another key, and an old assessment is
 * still read through the criteria it was made with.
 *
 * Levels are returned as keys (low, moderate, high, very_high); the words a person reads are
 * translations. Nothing stores the level — it is always computed from the two values.
 *
 * This policy never looks at controls, evidence or measures. Residual risk is a person's explicit
 * judgement, scored with the same rules as inherent risk, never derived.
 */
final class RiskScoringPolicy
{
    public const CRITERIA_STANDARD_5X5 = 'standard_5x5_v1';

    public const LEVEL_LOW = 'low';

    public const LEVEL_MODERATE = 'moderate';

    public const LEVEL_HIGH = 'high';

    public const LEVEL_VERY_HIGH = 'very_high';

    /** The criteria new assessments are given under. */
    public function currentCriteriaKey(): string
    {
        return self::CRITERIA_STANDARD_5X5;
    }

    /**
     * @return array{likelihood: list<int>, consequence: list<int>, bands: list<array{level: string, min: int, max: int}>}
     */
    public function criteria(?string $criteriaKey = null): array
    {
        return match ($criteriaKey ?? $this->currentCriteriaKey()) {
            self::CRITERIA_STANDARD_5X5 => [
                'likelihood' => [1, 2, 3, 4, 5],
                'consequence' => [1, 2, 3, 4, 5],
                'bands' => [
                    ['level' => self::LEVEL_LOW, 'min' => 1, 'max' => 4],
                    ['level' => self::LEVEL_MODERATE, 'min' => 5, 'max' => 9],
                    ['level' => self::LEVEL_HIGH, 'min' => 10, 'max' => 16],
                    ['level' => self::LEVEL_VERY_HIGH, 'min' => 17, 'max' => 25],
                ],
            ],
            default => throw new InvalidArgumentException("Unknown risk criteria [{$criteriaKey}]."),
        };
    }

    /**
     * @return array{likelihood: int, consequence: int, score: int, level: string}
     */
    public function evaluate(int $likelihood, int $consequence, ?string $criteriaKey = null): array
    {
        $criteria = $this->criteria($criteriaKey);

        if (! in_array($likelihood, $criteria['likelihood'], true) || ! in_array($consequence, $criteria['consequence'], true)) {
            throw new InvalidArgumentException("Likelihood {$likelihood} / consequence {$consequence} is outside the scale.");
        }

        $score = $likelihood * $consequence;

        foreach ($criteria['bands'] as $band) {
            if ($score >= $band['min'] && $score <= $band['max']) {
                return ['likelihood' => $likelihood, 'consequence' => $consequence, 'score' => $score, 'level' => $band['level']];
            }
        }

        throw new InvalidArgumentException("Score {$score} falls in no band.");
    }
}
