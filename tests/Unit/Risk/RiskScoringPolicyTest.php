<?php

namespace Tests\Unit\Risk;

use App\Services\Risk\RiskScoringPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The 5×5 criteria: score = likelihood × consequence, and four levels by score band.
 */
class RiskScoringPolicyTest extends TestCase
{
    public function test_every_cell_of_the_matrix_gets_the_level_its_score_band_says(): void
    {
        $policy = new RiskScoringPolicy;

        for ($likelihood = 1; $likelihood <= 5; $likelihood++) {
            for ($consequence = 1; $consequence <= 5; $consequence++) {
                $score = $likelihood * $consequence;
                $expected = match (true) {
                    $score <= 4 => 'low',
                    $score <= 9 => 'moderate',
                    $score <= 16 => 'high',
                    default => 'very_high',
                };

                $this->assertSame(
                    ['likelihood' => $likelihood, 'consequence' => $consequence, 'score' => $score, 'level' => $expected],
                    $policy->evaluate($likelihood, $consequence),
                    "{$likelihood}×{$consequence}",
                );
            }
        }
    }

    public function test_band_edges(): void
    {
        $policy = new RiskScoringPolicy;

        $this->assertSame('low', $policy->evaluate(1, 1)['level']);
        $this->assertSame('low', $policy->evaluate(2, 2)['level']);
        $this->assertSame('moderate', $policy->evaluate(1, 5)['level']);
        $this->assertSame('moderate', $policy->evaluate(3, 3)['level']);
        $this->assertSame('high', $policy->evaluate(2, 5)['level']);
        $this->assertSame('high', $policy->evaluate(4, 4)['level']);
        $this->assertSame('very_high', $policy->evaluate(5, 4)['level']);
        $this->assertSame('very_high', $policy->evaluate(5, 5)['level']);
    }

    public function test_the_bands_cover_every_possible_score_exactly_once(): void
    {
        $bands = (new RiskScoringPolicy)->criteria()['bands'];

        for ($score = 1; $score <= 25; $score++) {
            $matches = array_filter($bands, fn (array $band): bool => $score >= $band['min'] && $score <= $band['max']);
            $this->assertCount(1, $matches, "score {$score}");
        }
    }

    public function test_values_outside_the_scale_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new RiskScoringPolicy)->evaluate(0, 3);
    }

    public function test_unknown_criteria_are_refused_rather_than_guessed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new RiskScoringPolicy)->evaluate(3, 3, 'customer_custom_v9');
    }
}
