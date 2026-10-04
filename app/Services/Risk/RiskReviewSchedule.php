<?php

namespace App\Services\Risk;

use App\Models\Risk;
use App\Models\RiskAssessment;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Periodisk vurdering: when a risk is next to be assessed, and whether that is overdue.
 *
 * Computed on read, never stored. A review is carried out by registering a new RiskAssessment, so
 * the next review date follows from two things only — the day of the latest assessment and the
 * risk's review_interval_months — and moves by itself when either changes.
 *
 *  - No interval: no next date. No assessment yet: no next date either, until the first one.
 *  - Calendar months, never an approximate number of days. A day that does not exist in the
 *    target month clamps to that month's last day (31 Jan + 1 month = 28/29 Feb), so a monthly
 *    review never skips a month.
 *  - Overdue only from the day AFTER the next review date: due today is not overdue.
 *  - A closed risk is never overdue. Risiko has no draft status; a closed risk is the one that is
 *    no longer followed up.
 *
 * Acceptance of residual risk does not enter into this: an accepted risk is still reviewed.
 *
 * The caller has already reached the risk through RiskAccessService::visibleRisks(); nothing here
 * looks a risk up on its own.
 */
class RiskReviewSchedule
{
    /**
     * @return array{
     *     interval_months: int|null,
     *     last_assessed_on: string|null,
     *     next_review_on: string|null,
     *     is_overdue: bool
     * }
     */
    public function scheduleFor(Risk $risk, ?CarbonInterface $today = null): array
    {
        $latest = $risk->assessments()->where('customer_id', $risk->customer_id)->first(['id', 'assessed_at']);
        $interval = $risk->review_interval_months;
        $next = $this->nextReviewOn($interval, $latest instanceof RiskAssessment ? $latest->assessed_at : null);

        return [
            'interval_months' => $interval,
            'last_assessed_on' => $latest?->assessed_at?->toDateString(),
            'next_review_on' => $next?->toDateString(),
            'is_overdue' => $this->isOverdue($risk->status, $next, $today),
        ];
    }

    public function nextReviewOn(?int $intervalMonths, ?CarbonInterface $lastAssessedAt): ?CarbonImmutable
    {
        if ($intervalMonths === null || $lastAssessedAt === null) {
            return null;
        }

        return CarbonImmutable::parse($lastAssessedAt->toDateString())->addMonthsNoOverflow($intervalMonths);
    }

    public function isOverdue(string $status, ?CarbonInterface $nextReviewOn, ?CarbonInterface $today = null): bool
    {
        if ($nextReviewOn === null || $status === Risk::STATUS_CLOSED) {
            return false;
        }

        $today = CarbonImmutable::parse(($today ?? now())->toDateString());

        return $today->greaterThan(CarbonImmutable::parse($nextReviewOn->toDateString()));
    }
}
