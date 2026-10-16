<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * «I dag» for a calendar date a person picks — the day a deviation happened, the day a supplier was
 * assessed, how long a risk acceptance holds.
 *
 * The app runs in UTC, so Laravel's `before_or_equal:today` meant today in UTC: from midnight until
 * 01:00 (02:00 in summer) a Norwegian user's today was refused as a future day, and yesterday was
 * accepted as today. A calendar date belongs to the user's calendar, so «today» is read in
 * config('app.calendar_timezone') — Europe/Oslo — and handed back as that date at midnight in the
 * app's own zone, the same shape a Y-m-d value from a date field is parsed into. Timestamps
 * (recorded_at, created_at, …) are moments, not calendar dates, and stay in UTC.
 *
 * The date-field limits a page sends (`today`, a latest date) and the rules that check the same
 * field must both come from here, or the picker and the server disagree for those hours.
 */
final class CalendarDate
{
    public static function timezone(): string
    {
        return (string) config('app.calendar_timezone', 'Europe/Oslo');
    }

    /** Today in Norway, at midnight in the app's time zone. Follows Carbon::setTestNow(). */
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::todayString());
    }

    /** Today in Norway as Y-m-d — for a date field's limit and for a validation rule's parameter. */
    public static function todayString(): string
    {
        return CarbonImmutable::now(self::timezone())->toDateString();
    }
}
