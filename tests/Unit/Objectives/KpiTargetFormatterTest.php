<?php

namespace Tests\Unit\Objectives;

use App\Models\Kpi;
use App\Support\Objectives\KpiTargetFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * How a målverdi reads. Presentation only: the strings here are never evaluated.
 * No database — a KPI is built in memory.
 */
class KpiTargetFormatterTest extends TestCase
{
    /** @return array<string, array{array<string, mixed>, string, string}> */
    public static function targets(): array
    {
        return [
            'percent, min' => [['unit' => 'percent', 'target_min' => '99.5'], '≥ 99,5 %', '≥ 99.5%'],
            'count with label, max' => [['unit' => 'count', 'unit_label' => 'hendelser', 'target_max' => '3'], '≤ 3 hendelser', '≤ 3 hendelser'],
            'count without label' => [['unit' => 'count', 'target_max' => '3'], '≤ 3', '≤ 3'],
            'hours, interval' => [['unit' => 'hours', 'target_min' => '2', 'target_max' => '5'], '2–5 timer', '2–5 hours'],
            'hours, a single hour' => [['unit' => 'hours', 'target_max' => '1'], '≤ 1 time', '≤ 1 hour'],
            'days, decimals' => [['unit' => 'days', 'target_max' => '1.5'], '≤ 1,5 dager', '≤ 1.5 days'],
            'currency, million' => [['unit' => 'currency', 'currency_code' => 'NOK', 'target_min' => '1000000'], '≥ NOK 1 000 000', '≥ NOK 1,000,000'],
            'currency, interval' => [['unit' => 'currency', 'currency_code' => 'EUR', 'target_min' => '2000', 'target_max' => '5000.25'], 'EUR 2 000–5 000,25', 'EUR 2,000–5,000.25'],
            'number, trailing zeros dropped' => [['unit' => 'number', 'target_min' => '4.5000'], '≥ 4,5', '≥ 4.5'],
            'number, negative' => [['unit' => 'number', 'target_min' => '-1250.75'], '≥ -1 250,75', '≥ -1,250.75'],
            'percent, exact point' => [['unit' => 'percent', 'target_min' => '100', 'target_max' => '100'], '= 100 %', '= 100%'],
        ];
    }

    /** @param array<string, mixed> $attributes */
    #[DataProvider('targets')]
    public function test_a_target_reads_in_the_readers_language(array $attributes, string $norwegian, string $english): void
    {
        $kpi = new Kpi($attributes);

        $this->assertSame($norwegian, $this->spaces((new KpiTargetFormatter('no'))->target($kpi)));
        $this->assertSame($english, $this->spaces((new KpiTargetFormatter('en'))->target($kpi)));
    }

    public function test_numbers_never_wrap_inside_a_target(): void
    {
        $kpi = new Kpi(['unit' => 'currency', 'currency_code' => 'NOK', 'target_min' => '1000000']);

        $this->assertSame("≥\u{00A0}NOK\u{00A0}1\u{00A0}000\u{00A0}000", (new KpiTargetFormatter('no'))->target($kpi));
    }

    public function test_the_unit_names_its_label_or_currency(): void
    {
        $no = new KpiTargetFormatter('no');

        $this->assertSame('Prosent', $no->unit(new Kpi(['unit' => 'percent'])));
        $this->assertSame('Antall (saker)', $no->unit(new Kpi(['unit' => 'count', 'unit_label' => 'saker'])));
        $this->assertSame('Tall', $no->unit(new Kpi(['unit' => 'number'])));
        $this->assertSame('Valuta (NOK)', $no->unit(new Kpi(['unit' => 'currency', 'currency_code' => 'NOK'])));
        $this->assertSame('Timer', $no->unit(new Kpi(['unit' => 'hours'])));
        $this->assertSame('Days', (new KpiTargetFormatter('en'))->unit(new Kpi(['unit' => 'days'])));
    }

    public function test_a_tolerance_reads_as_an_amount_in_the_unit(): void
    {
        $no = new KpiTargetFormatter('no');

        $this->assertSame('1 %', $this->spaces($no->amount(new Kpi(['unit' => 'percent']), '1.0000')));
        $this->assertSame('0,5 timer', $this->spaces($no->amount(new Kpi(['unit' => 'hours']), '0.5')));
        $this->assertSame('NOK 50 000', $this->spaces($no->amount(new Kpi(['unit' => 'currency', 'currency_code' => 'NOK']), '50000')));
    }

    private function spaces(string $value): string
    {
        return str_replace("\u{00A0}", ' ', $value);
    }
}
