<?php

namespace App\Support\Objectives;

use App\Models\Kpi;
use App\Services\Objectives\KpiPeriod;
use App\Services\Objectives\KpiPeriods;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * How a period reads on the page: «Uke 40, 2026», «September 2026», «3. kvartal 2026», «2026», or a
 * day, «5. oktober 2026». The one place this is written; pages receive the finished strings.
 */
final class KpiPeriodFormatter
{
    public function __construct(
        private readonly KpiPeriods $periods = new KpiPeriods,
        private readonly ?string $locale = null,
    ) {}

    public function label(KpiPeriod $period): string
    {
        $locale = $this->locale();

        return match ($period->frequency) {
            Kpi::FREQUENCY_WEEKLY => (string) __('procynia.objectives.measurement.period_label.weekly', [
                'week' => (int) $period->start->isoWeek(),
                'year' => (int) $period->start->isoWeekYear(),
            ], $locale),
            Kpi::FREQUENCY_MONTHLY => mb_convert_case(
                $period->start->locale($this->carbonLocale())->translatedFormat('F Y'),
                MB_CASE_TITLE,
            ),
            Kpi::FREQUENCY_QUARTERLY => (string) __('procynia.objectives.measurement.period_label.quarterly', [
                'quarter' => (int) $period->start->quarter,
                'year' => (int) $period->start->year,
            ], $locale),
            Kpi::FREQUENCY_YEARLY => $period->start->format('Y'),
            default => $this->date($period->start),
        };
    }

    /**
     * The label as it reads inside a sentence: «Måling mangler for juli 2026», «… for uke 40, 2026».
     * Norwegian writes months and «uke» in lower case; English keeps its capitalised months and
     * lowers only «week». Quarters, years and days start with a digit and are left as they are.
     */
    public function inSentence(KpiPeriod $period): string
    {
        $label = $this->label($period);

        if ($this->isEnglish() && $period->frequency !== Kpi::FREQUENCY_WEEKLY) {
            return $label;
        }

        return mb_strtolower(mb_substr($label, 0, 1)).mb_substr($label, 1);
    }

    /** A stored range: the period it is, or both dates when it is no calendar period. */
    public function range(CarbonInterface $start, CarbonInterface $end): string
    {
        $period = $this->periods->fromRange($start, $end);

        return $period !== null ? $this->label($period) : $this->date($start).'–'.$this->date($end);
    }

    /** A day as people write it: «5. oktober 2026», «5 October 2026». */
    public function date(CarbonInterface $date): string
    {
        $format = $this->isEnglish() ? 'j F Y' : 'j. F Y';

        return CarbonImmutable::instance($date)->locale($this->carbonLocale())->translatedFormat($format);
    }

    private function locale(): string
    {
        return $this->locale ?? app()->getLocale();
    }

    private function carbonLocale(): string
    {
        return $this->isEnglish() ? 'en' : 'nb';
    }

    private function isEnglish(): bool
    {
        return str_starts_with(strtolower($this->locale()), 'en');
    }
}
