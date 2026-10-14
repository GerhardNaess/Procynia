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

    /**
     * The four måltyper as a person sets them, with the result the brief requires.
     *
     * @return array<string, array{string|null, string|null, string, string, string}>
     */
    public static function targetTypes(): array
    {
        return [
            'minst 100, 120' => ['100', null, KpiTarget::TYPE_AT_LEAST, '120', KpiTargetPolicy::ON_TARGET],
            'minst 100, 80' => ['100', null, KpiTarget::TYPE_AT_LEAST, '80', KpiTargetPolicy::OFF_TARGET],
            'høyst 5, 3' => [null, '5', KpiTarget::TYPE_AT_MOST, '3', KpiTargetPolicy::ON_TARGET],
            'høyst 5, 8' => [null, '5', KpiTarget::TYPE_AT_MOST, '8', KpiTargetPolicy::OFF_TARGET],
            'intervall 18–22, 20' => ['18', '22', KpiTarget::TYPE_RANGE, '20', KpiTargetPolicy::ON_TARGET],
            'intervall 18–22, 25' => ['18', '22', KpiTarget::TYPE_RANGE, '25', KpiTargetPolicy::OFF_TARGET],
            'nøyaktig 100, 100' => ['100', '100', KpiTarget::TYPE_EXACT, '100', KpiTargetPolicy::ON_TARGET],
            'nøyaktig 100, 110' => ['100', '100', KpiTarget::TYPE_EXACT, '110', KpiTargetPolicy::OFF_TARGET],
            'nøyaktig 100, 99.9999' => ['100', '100', KpiTarget::TYPE_EXACT, '99.9999', KpiTargetPolicy::OFF_TARGET],
            // An interval of 1 000 000–1 300 000 is an interval: 1 400 000 is above it, not «more growth».
            'intervall 1 000 000–1 300 000, 1 400 000' => ['1000000', '1300000', KpiTarget::TYPE_RANGE, '1400000', KpiTargetPolicy::OFF_TARGET],
            'intervall 1 000 000–1 300 000, 1 000 000' => ['1000000', '1300000', KpiTarget::TYPE_RANGE, '1000000', KpiTargetPolicy::ON_TARGET],
            'negativ intervall -10–-2, -5' => ['-10', '-2', KpiTarget::TYPE_RANGE, '-5', KpiTargetPolicy::ON_TARGET],
            'negativ minst -2, -3' => ['-2', null, KpiTarget::TYPE_AT_LEAST, '-3', KpiTargetPolicy::OFF_TARGET],
        ];
    }

    #[DataProvider('targetTypes')]
    public function test_each_target_type_is_derived_from_the_bounds_and_judged_as_the_brief_says(?string $min, ?string $max, string $type, string $value, string $expected): void
    {
        $target = KpiTarget::of($min, $max);

        $this->assertSame($type, $target->type());
        $this->assertSame($expected, (new KpiTargetPolicy)->evaluate($target, $value));
    }

    public function test_an_exact_target_with_tolerance_gives_attention_on_both_sides(): void
    {
        $target = KpiTarget::of('100', '100', '5');
        $policy = new KpiTargetPolicy;

        $this->assertSame(KpiTargetPolicy::ON_TARGET, $policy->evaluate($target, '100'));
        $this->assertSame(KpiTargetPolicy::ATTENTION, $policy->evaluate($target, '95'));
        $this->assertSame(KpiTargetPolicy::ATTENTION, $policy->evaluate($target, '105'));
        $this->assertSame(KpiTargetPolicy::OFF_TARGET, $policy->evaluate($target, '105.0001'));
    }

    /** However large, a tolerance only widens outwards: a better value is never made worse by it. */
    public function test_a_tolerance_never_turns_the_direction_around(): void
    {
        $policy = new KpiTargetPolicy;

        foreach (['0', '1', '1000', '1000000000'] as $tolerance) {
            foreach (['100', '100.0001', '5000', '999999999'] as $better) {
                $this->assertSame(KpiTargetPolicy::ON_TARGET, $policy->evaluate(KpiTarget::of('100', null, $tolerance), $better));
            }

            foreach (['5', '4.9999', '0', '-999999'] as $better) {
                $this->assertSame(KpiTargetPolicy::ON_TARGET, $policy->evaluate(KpiTarget::of(null, '5', $tolerance), $better));
            }

            $this->assertNotSame(KpiTargetPolicy::ON_TARGET, $policy->evaluate(KpiTarget::of('100', null, $tolerance), '99.9999'));
            $this->assertNotSame(KpiTargetPolicy::ON_TARGET, $policy->evaluate(KpiTarget::of(null, '5', $tolerance), '5.0001'));
        }
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
