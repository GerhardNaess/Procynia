<?php

namespace Tests\Unit;

use App\Support\CpvCodeNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * What counts as a CPV code, now that one class decides it.
 *
 * Six places used to answer this question separately, and the answers disagreed. Most reduced a
 * code to its digits, which is right about punctuation and wrong about the check digit: it turned
 * "90910000-9" into "909100009", a nine-digit string that equals no classification in existence.
 * The failure was silent — a register writing codes in that form would simply have matched nothing.
 *
 * These are the cases that rule has to get right, stated as inputs and outputs so a future change
 * to it has to be deliberate.
 */
class CpvCodeNormalizerTest extends TestCase
{
    /** The overwhelming majority of real codes: already bare, already right. */
    public function test_an_eight_digit_code_is_returned_unchanged(): void
    {
        $this->assertSame('90910000', CpvCodeNormalizer::normalize('90910000'));
        $this->assertSame('72000000', CpvCodeNormalizer::normalize('72000000'));
    }

    /** The whole point of the fix. */
    public function test_a_check_digit_is_not_part_of_the_code(): void
    {
        $this->assertSame('90910000', CpvCodeNormalizer::normalize('90910000-9'));
        $this->assertSame('72222300', CpvCodeNormalizer::normalize('72222300-0'));
    }

    public function test_punctuation_and_whitespace_are_not_part_of_the_code(): void
    {
        $this->assertSame('90910000', CpvCodeNormalizer::normalize('  90910000  '));
        $this->assertSame('90910000', CpvCodeNormalizer::normalize('90.910.000'));
        $this->assertSame('90910000', CpvCodeNormalizer::normalize('90 910 000'));
        $this->assertSame('90910000', CpvCodeNormalizer::normalize("\t90910000\n"));
        $this->assertSame('90910000', CpvCodeNormalizer::normalize('90 910 000 - 9'));
    }

    /**
     * Rejected rather than trimmed to something that would match by accident.
     *
     * "909" is not a short CPV code; it is three digits. Returning it would make it a prefix that
     * could equal another value somewhere, and a caller that wanted prefix matching would rather
     * say so than get it by default.
     */
    public function test_a_value_that_is_not_a_code_is_refused(): void
    {
        $this->assertNull(CpvCodeNormalizer::normalize('909'));
        $this->assertNull(CpvCodeNormalizer::normalize('9091000'));
        $this->assertNull(CpvCodeNormalizer::normalize('9091000099'));
        $this->assertNull(CpvCodeNormalizer::normalize(''));
        $this->assertNull(CpvCodeNormalizer::normalize('   '));
        $this->assertNull(CpvCodeNormalizer::normalize('renhold'));
        $this->assertNull(CpvCodeNormalizer::normalize(null));
        $this->assertNull(CpvCodeNormalizer::normalize(['90910000']));
    }

    /** An integer from a JSON payload is the same code as the string of it. */
    public function test_a_numeric_value_is_read_as_the_code_it_spells(): void
    {
        $this->assertSame('90910000', CpvCodeNormalizer::normalize(90910000));
    }

    /**
     * The check digit is dropped, not verified.
     *
     * Checking it would turn a mistyped catalogue entry into a silent non-match, which is exactly
     * the class of failure this rule exists to remove. "90910000-1" carries the wrong ninth digit
     * and still names the same classification.
     */
    public function test_the_check_digit_is_dropped_rather_than_validated(): void
    {
        $this->assertSame('90910000', CpvCodeNormalizer::normalize('90910000-1'));
    }

    public function test_a_list_is_normalised_deduplicated_and_stripped_of_non_codes(): void
    {
        $this->assertSame(
            ['90910000', '72222300'],
            CpvCodeNormalizer::normalizeMany(['90910000-9', '90.910.000', '72222300', 'renhold', '', '909']),
        );
    }

    public function test_an_empty_list_stays_empty(): void
    {
        $this->assertSame([], CpvCodeNormalizer::normalizeMany([]));
        $this->assertSame([], CpvCodeNormalizer::normalizeMany(['nope', '12']));
    }
}
