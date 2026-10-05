<?php

namespace Tests\Unit\Objectives;

use App\Models\Kpi;
use App\Services\Objectives\KpiPeriods;
use App\Support\Objectives\KpiPeriodFormatter;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A period on its own starts with a capital — it heads a table cell or an option. Inside a sentence
 * («Måling mangler for juli 2026») Norwegian writes months and weeks in lower case, while English
 * keeps its months capitalised.
 */
class KpiPeriodFormatterTest extends TestCase
{
    /** @return array<string, array{string, string, string, string}> */
    public static function periods(): array
    {
        return [
            'month, Norwegian' => [Kpi::FREQUENCY_MONTHLY, 'no', 'Juli 2026', 'juli 2026'],
            'week, Norwegian' => [Kpi::FREQUENCY_WEEKLY, 'no', 'Uke 28, 2026', 'uke 28, 2026'],
            'quarter, Norwegian' => [Kpi::FREQUENCY_QUARTERLY, 'no', '3. kvartal 2026', '3. kvartal 2026'],
            'year, Norwegian' => [Kpi::FREQUENCY_YEARLY, 'no', '2026', '2026'],
            'month, English' => [Kpi::FREQUENCY_MONTHLY, 'en', 'July 2026', 'July 2026'],
            'week, English' => [Kpi::FREQUENCY_WEEKLY, 'en', 'Week 28, 2026', 'week 28, 2026'],
        ];
    }

    #[DataProvider('periods')]
    public function test_a_period_reads_capitalised_alone_and_in_lower_case_inside_a_sentence(string $frequency, string $locale, string $alone, string $inSentence): void
    {
        $period = (new KpiPeriods)->containing($frequency, CarbonImmutable::parse('2026-07-08'));
        $formatter = new KpiPeriodFormatter(locale: $locale);

        $this->assertSame($alone, $formatter->label($period));
        $this->assertSame($inSentence, $formatter->inSentence($period));
    }
}
