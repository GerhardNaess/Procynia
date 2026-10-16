<?php

namespace Tests\Unit\Validation;

use App\Support\LocalizedNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A number typed the reader's way, read back as the plain value the server stores — and refused,
 * not guessed, when it does not read. The same rules as the KPI fields (kpiNumber.js).
 */
class LocalizedNumberTest extends TestCase
{
    /** @return array<string, array{mixed, string, mixed}> */
    public static function readings(): array
    {
        return [
            'plain integer' => ['1250', 'no', '1250'],
            'plain decimal point keeps its meaning' => ['12.5', 'no', '12.5'],
            'Norwegian decimal comma' => ['12,5', 'no', '12.5'],
            'Norwegian thousands space' => ['1 250', 'no', '1250'],
            'Norwegian both' => ['1 250 000,75', 'no', '1250000.75'],
            'non-breaking space from formatting' => ["1\u{00A0}250,5", 'no', '1250.5'],
            'narrow no-break space' => ["1\u{202F}250", 'no', '1250'],
            'surrounding spaces' => ['  12,5 ', 'no', '12.5'],
            'negative is read, the rule refuses it' => ['-3,5', 'no', '-3.5'],
            'English thousands comma' => ['1,250.75', 'en', '1250.75'],
            'English thousands space' => ['1 250', 'en', '1250'],
            'not a string is left alone' => [12.5, 'no', 12.5],
            'null is left alone' => [null, 'no', null],
        ];
    }

    #[DataProvider('readings')]
    public function test_a_number_written_the_readers_way_is_read_as_the_plain_value(mixed $typed, string $locale, mixed $plain): void
    {
        $this->assertSame($plain, LocalizedNumber::normalize($typed, $locale));
    }

    /** @return array<string, array{string, string}> */
    public static function refusals(): array
    {
        return [
            'German notation' => ['1.250,5', 'no'],
            'two decimal commas' => ['12,5,0', 'no'],
            'a group that is not three digits' => ['1 25', 'no'],
            'mixed separators' => ['1,250 000', 'en'],
            'English with a decimal comma' => ['12,5', 'en'],
            'English with Norwegian notation' => ['1 250,5', 'en'],
            'Norwegian with English notation' => ['1,250.5', 'no'],
            'words' => ['tolv', 'no'],
            'empty after trimming spaces' => ['   ', 'no'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_what_does_not_read_is_left_for_the_numeric_rule_to_refuse(string $typed, string $locale): void
    {
        $this->assertSame($typed, LocalizedNumber::normalize($typed, $locale));
        $this->assertFalse(is_numeric(LocalizedNumber::normalize($typed, $locale)));
    }
}
