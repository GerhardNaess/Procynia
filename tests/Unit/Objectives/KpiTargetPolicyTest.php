<?php

namespace Tests\Unit\Objectives;

use App\Services\Objectives\KpiTarget;
use App\Services\Objectives\KpiTargetPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The målverdi rules and the on target / attention / off target decision, on exact decimals.
 * Every edge is inclusive: on the bound is on target, on the tolerance edge is attention.
 */
class KpiTargetPolicyTest extends TestCase
{
    /** @return array<string, array{string|null, string|null, string|null, string, string}> */
    public static function evaluations(): array
    {
        return [
            // Only a lower bound: «Oppetid ≥ 99,5 %», tolerance 1,0.
            'min: well above' => ['99.5', null, '1.0', '100', KpiTargetPolicy::ON_TARGET],
            'min: exactly on the bound' => ['99.5', null, '1.0', '99.5', KpiTargetPolicy::ON_TARGET],
            'min: a hair below the bound' => ['99.5', null, '1.0', '99.4999', KpiTargetPolicy::ATTENTION],
            'min: inside the tolerance' => ['99.5', null, '1.0', '99.0', KpiTargetPolicy::ATTENTION],
            'min: deeper inside the tolerance' => ['99.5', null, '1.0', '98.7', KpiTargetPolicy::ATTENTION],
            'min: exactly on the tolerance edge' => ['99.5', null, '1.0', '98.5', KpiTargetPolicy::ATTENTION],
            'min: a hair past the tolerance edge' => ['99.5', null, '1.0', '98.4999', KpiTargetPolicy::OFF_TARGET],
            'min: outside the tolerance' => ['99.5', null, '1.0', '98.4', KpiTargetPolicy::OFF_TARGET],
            'min: no tolerance, on the bound' => ['99.5', null, null, '99.5', KpiTargetPolicy::ON_TARGET],
            'min: no tolerance, just below' => ['99.5', null, null, '99.4', KpiTargetPolicy::OFF_TARGET],
            'min: zero tolerance, just below' => ['99.5', null, '0', '99.4999', KpiTargetPolicy::OFF_TARGET],

            // Only an upper bound: «Alvorlige avvik ≤ 3», tolerance 2 — the room lies above.
            'max: well below' => [null, '3', '2', '0', KpiTargetPolicy::ON_TARGET],
            'max: exactly on the bound' => [null, '3', '2', '3', KpiTargetPolicy::ON_TARGET],
            'max: inside the tolerance' => [null, '3', '2', '4', KpiTargetPolicy::ATTENTION],
            'max: exactly on the tolerance edge' => [null, '3', '2', '5', KpiTargetPolicy::ATTENTION],
            'max: past the tolerance edge' => [null, '3', '2', '5.0001', KpiTargetPolicy::OFF_TARGET],
            'max: far outside' => [null, '3', '2', '12', KpiTargetPolicy::OFF_TARGET],
            'max: tolerance never reaches below the bound' => [null, '3', '2', '-100', KpiTargetPolicy::ON_TARGET],
            'max: no tolerance, just above' => [null, '3', null, '3.0001', KpiTargetPolicy::OFF_TARGET],
            'max: zero tolerance, on the bound' => [null, '3', '0.0000', '3', KpiTargetPolicy::ON_TARGET],

            // An interval: «Svartid 2–5 timer», tolerance 0,5 on both sides.
            'interval: inside' => ['2', '5', '0.5', '3.25', KpiTargetPolicy::ON_TARGET],
            'interval: on the lower bound' => ['2', '5', '0.5', '2', KpiTargetPolicy::ON_TARGET],
            'interval: on the upper bound' => ['2', '5', '0.5', '5', KpiTargetPolicy::ON_TARGET],
            'interval: below, inside the tolerance' => ['2', '5', '0.5', '1.75', KpiTargetPolicy::ATTENTION],
            'interval: above, inside the tolerance' => ['2', '5', '0.5', '5.25', KpiTargetPolicy::ATTENTION],
            'interval: on the lower tolerance edge' => ['2', '5', '0.5', '1.5', KpiTargetPolicy::ATTENTION],
            'interval: on the upper tolerance edge' => ['2', '5', '0.5', '5.5', KpiTargetPolicy::ATTENTION],
            'interval: past the lower tolerance edge' => ['2', '5', '0.5', '1.4999', KpiTargetPolicy::OFF_TARGET],
            'interval: past the upper tolerance edge' => ['2', '5', '0.5', '5.5001', KpiTargetPolicy::OFF_TARGET],
            'interval: no tolerance, just outside' => ['2', '5', null, '5.0001', KpiTargetPolicy::OFF_TARGET],
            'interval: a single point' => ['4', '4', null, '4', KpiTargetPolicy::ON_TARGET],
            'interval: a single point, missed' => ['4', '4', '0.1', '4.1', KpiTargetPolicy::ATTENTION],

            // Edges a float gets wrong: in binary 0.7 + 0.1 < 0.8 and 0.8 - 0.1 > 0.7.
            'decimals: on the edge of max 0.7 + tolerance 0.1' => [null, '0.7', '0.1', '0.8', KpiTargetPolicy::ATTENTION],
            'decimals: on the edge of min 0.8 - tolerance 0.1' => ['0.8', null, '0.1', '0.7', KpiTargetPolicy::ATTENTION],
            'decimals: four places on the edge' => ['99.9995', null, '0.0005', '99.9990', KpiTargetPolicy::ATTENTION],
            'decimals: negative bound' => ['-5', null, '1', '-6', KpiTargetPolicy::ATTENTION],
            'decimals: large currency' => [null, '1000000', '50000', '1050000', KpiTargetPolicy::ATTENTION],
        ];
    }

    #[DataProvider('evaluations')]
    public function test_a_value_is_placed_against_the_target(?string $min, ?string $max, ?string $tolerance, string $value, string $expected): void
    {
        $this->assertSame($expected, (new KpiTargetPolicy)->evaluate(KpiTarget::of($min, $max, $tolerance), $value));
    }

    public function test_without_a_tolerance_there_is_no_attention_anywhere(): void
    {
        $policy = new KpiTargetPolicy;

        foreach ([null, '0', '0.0000'] as $tolerance) {
            $target = KpiTarget::of('10', '20', $tolerance);

            foreach (['-1000', '9.9999', '10', '15', '20', '20.0001', '1000'] as $value) {
                $this->assertNotSame(KpiTargetPolicy::ATTENTION, $policy->evaluate($target, $value), "tolerance {$tolerance}, value {$value}");
            }
        }
    }

    public function test_a_target_needs_at_least_one_bound(): void
    {
        $this->assertSame(['target_min' => KpiTarget::ERROR_NO_BOUND], KpiTarget::errorsFor(null, null, null));
        $this->assertSame(['target_min' => KpiTarget::ERROR_NO_BOUND], KpiTarget::errorsFor('', '', '1'));

        $this->expectException(InvalidArgumentException::class);
        KpiTarget::of(null, null);
    }

    public function test_min_may_equal_max_but_never_exceed_it(): void
    {
        $this->assertSame([], KpiTarget::errorsFor('5', '5', null));
        $this->assertSame([], KpiTarget::errorsFor('4.9999', '5', null));
        $this->assertSame(['target_max' => KpiTarget::ERROR_MIN_ABOVE_MAX], KpiTarget::errorsFor('5.0001', '5', null));

        $this->expectException(InvalidArgumentException::class);
        KpiTarget::of('6', '5');
    }

    public function test_tolerance_is_zero_or_more(): void
    {
        $this->assertSame([], KpiTarget::errorsFor('1', null, '0'));
        $this->assertSame(['tolerance' => KpiTarget::ERROR_NEGATIVE_TOLERANCE], KpiTarget::errorsFor('1', null, '-0.0001'));

        $this->assertFalse(KpiTarget::of('1', null, '0')->hasTolerance());
        $this->assertFalse(KpiTarget::of('1', null, null)->hasTolerance());
        $this->assertTrue(KpiTarget::of('1', null, '0.0001')->hasTolerance());

        $this->expectException(InvalidArgumentException::class);
        KpiTarget::of('1', null, '-1');
    }

    public function test_every_broken_rule_is_reported_at_once(): void
    {
        $this->assertSame([
            'target_max' => KpiTarget::ERROR_MIN_ABOVE_MAX,
            'tolerance' => KpiTarget::ERROR_NEGATIVE_TOLERANCE,
        ], KpiTarget::errorsFor('9', '1', '-1'));
    }

    public function test_something_that_is_not_a_number_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        KpiTarget::of('99,5', null);
    }
}
