<?php

namespace App\Services\Suppliers;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Neste vurdering: when a supplier is next to be assessed (docs/supplier-management-v1-plan.md §4.3).
 *
 * Computed on read, never stored — there is no next_review_at. It follows from two things only:
 * the day of the current assessment, and the supplier's CURRENT review interval (not the interval
 * in that assessment's snapshot). Changing the criticality or the interval therefore moves the next
 * review by itself, counted from the assessment already made.
 *
 *  - No interval, or no assessment yet: no next date.
 *  - Calendar months, never an approximate number of days. A day that does not exist in the target
 *    month clamps to that month's last day (31 Aug + 6 months = 28/29 Feb), the same convention as
 *    RiskReviewSchedule.
 *
 * Overdue from the day after the next review date: due today is not overdue, as in RiskReviewSchedule.
 */
class SupplierReviewSchedule
{
    public function nextReviewOn(?int $intervalMonths, ?CarbonInterface $lastAssessedOn): ?CarbonImmutable
    {
        if ($intervalMonths === null || $lastAssessedOn === null) {
            return null;
        }

        return CarbonImmutable::parse($lastAssessedOn->toDateString())->addMonthsNoOverflow($intervalMonths);
    }

    /** Whether the next review date has passed. No date — no interval or no assessment — is never overdue. */
    public function isOverdue(?CarbonInterface $nextReviewOn, ?CarbonInterface $today = null): bool
    {
        return $nextReviewOn !== null && $nextReviewOn->toDateString() < ($today ?? now())->toDateString();
    }
}
