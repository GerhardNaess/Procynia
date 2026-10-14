<?php

namespace Tests\Unit\Objectives;

use App\Models\Kpi;
use App\Services\Objectives\KpiTargetPolicy;
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

        $this->assertSame('Prosentandel', $no->unit(new Kpi(['unit' => 'percent'])));
        $this->assertSame('Prosentvis endring', $no->unit(new Kpi(['unit' => 'percent_change'])));
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

    public function test_a_percent_tolerance_reads_in_percentage_points(): void
    {
        $this->assertSame('1 prosentpoeng', $this->spaces((new KpiTargetFormatter('no'))->tolerance(new Kpi(['unit' => 'percent']), '1.0000')));
        $this->assertSame('2,5 prosentpoeng', $this->spaces((new KpiTargetFormatter('no'))->tolerance(new Kpi(['unit' => 'percent_change']), '2.5')));
        $this->assertSame('1 percentage point', $this->spaces((new KpiTargetFormatter('en'))->tolerance(new Kpi(['unit' => 'percent']), '1')));
        $this->assertSame('2 dager', $this->spaces((new KpiTargetFormatter('no'))->tolerance(new Kpi(['unit' => 'days']), '2')));
    }

    /** @return array<string, array{array<string, mixed>, string, string}> */
    public static function rules(): array
    {
        return [
            'at least' => [['unit' => 'currency', 'currency_code' => 'NOK', 'target_min' => '1300000'], 'Målet er nådd når verdien er minst NOK 1 300 000.', 'The target is reached when the value is at least NOK 1,300,000.'],
            'at most' => [['unit' => 'days', 'target_max' => '5'], 'Målet er nådd når verdien er høyst 5 dager.', 'The target is reached when the value is at most 5 days.'],
            'range' => [['unit' => 'number', 'target_min' => '18', 'target_max' => '22'], 'Målet er nådd når verdien er mellom 18 og 22.', 'The target is reached when the value is between 18 and 22.'],
            // The stored interval of the real «30 % økning» KPI reads as an interval, never as a minimum.
            'range of millions' => [['unit' => 'currency', 'currency_code' => 'NOK', 'target_min' => '1000000.0000', 'target_max' => '1300000.0000'], 'Målet er nådd når verdien er mellom NOK 1 000 000 og NOK 1 300 000.', 'The target is reached when the value is between NOK 1,000,000 and NOK 1,300,000.'],
            'exact' => [['unit' => 'number', 'target_min' => '100', 'target_max' => '100'], 'Målet er nådd når verdien er nøyaktig 100.', 'The target is reached when the value is exactly 100.'],
        ];
    }

    /** @param array<string, mixed> $attributes */
    #[DataProvider('rules')]
    public function test_the_rule_says_when_the_target_is_reached(array $attributes, string $norwegian, string $english): void
    {
        $kpi = new Kpi($attributes);

        $this->assertSame($norwegian, $this->spaces((new KpiTargetFormatter('no'))->rule($kpi)));
        $this->assertSame($english, $this->spaces((new KpiTargetFormatter('en'))->rule($kpi)));
    }

    public function test_a_growth_target_says_where_it_comes_from(): void
    {
        $kpi = new Kpi(['unit' => 'currency', 'currency_code' => 'NOK', 'target_min' => '1300000', 'baseline_value' => '1000000', 'baseline_date' => '2026-10-01']);

        $this->assertSame(
            'Målet er nådd når verdien er minst NOK 1 300 000. Det er en økning på 30 % fra startverdien NOK 1 000 000 (1. oktober 2026).',
            $this->spaces((new KpiTargetFormatter('no'))->rule($kpi)),
        );
        $this->assertSame('Økning på 30 %', $this->spaces((new KpiTargetFormatter('no'))->changeLabel($kpi)));

        $down = new Kpi(['unit' => 'days', 'target_max' => '8', 'baseline_value' => '10', 'baseline_date' => '2026-01-15']);
        $this->assertSame(
            'The target is reached when the value is at most 8 days. That is a decrease of 20% from the baseline 10 days (15 January 2026).',
            $this->spaces((new KpiTargetFormatter('en'))->rule($down)),
        );
    }

    public function test_the_tolerance_rule_always_points_away_from_the_target(): void
    {
        $no = new KpiTargetFormatter('no');

        $this->assertSame('Inntil 1 prosentpoeng under målet gir «Trenger oppmerksomhet».', $this->spaces($no->toleranceRule(new Kpi(['unit' => 'percent', 'target_min' => '99.5', 'tolerance' => '1']))));
        $this->assertSame('Inntil 2 dager over målet gir «Trenger oppmerksomhet».', $this->spaces($no->toleranceRule(new Kpi(['unit' => 'days', 'target_max' => '5', 'tolerance' => '2']))));
        $this->assertSame('Inntil 1 utenfor målet gir «Trenger oppmerksomhet».', $this->spaces($no->toleranceRule(new Kpi(['unit' => 'number', 'target_min' => '18', 'target_max' => '22', 'tolerance' => '1']))));
        $this->assertNull($no->toleranceRule(new Kpi(['unit' => 'number', 'target_min' => '18', 'tolerance' => '0'])));
    }

    public function test_the_explanation_says_why_the_kpi_has_its_status(): void
    {
        $no = new KpiTargetFormatter('no');
        $minimum = new Kpi(['unit' => 'currency', 'currency_code' => 'NOK', 'target_min' => '1300000', 'tolerance' => '100000']);
        $maximum = new Kpi(['unit' => 'days', 'target_max' => '5']);

        $this->assertSame('Ingen gjeldende måling ennå. KPI-en får status når den første målingen er registrert.', $no->explanation($minimum, null, 'not_measured'));
        $this->assertSame('Siste verdi NOK 1 400 000 oppfyller målet ≥ NOK 1 300 000.', $this->spaces($no->explanation($minimum, '1400000', KpiTargetPolicy::ON_TARGET)));
        $this->assertSame(
            'Siste verdi NOK 1 250 000 er under målet ≥ NOK 1 300 000, men innenfor toleransen på NOK 100 000.',
            $this->spaces($no->explanation($minimum, '1250000', KpiTargetPolicy::ATTENTION)),
        );
        $this->assertSame(
            'Siste verdi NOK 1 000 000 er under målet ≥ NOK 1 300 000 og utenfor toleransen på NOK 100 000.',
            $this->spaces($no->explanation($minimum, '1000000', KpiTargetPolicy::OFF_TARGET)),
        );
        $this->assertSame('Siste verdi 8 dager er over målet ≤ 5 dager.', $this->spaces($no->explanation($maximum, '8', KpiTargetPolicy::OFF_TARGET)));
        $this->assertSame('The latest value 8 days is above the target ≤ 5 days.', $this->spaces((new KpiTargetFormatter('en'))->explanation($maximum, '8', KpiTargetPolicy::OFF_TARGET)));
    }

    private function spaces(string $value): string
    {
        return str_replace("\u{00A0}", ' ', $value);
    }
}
