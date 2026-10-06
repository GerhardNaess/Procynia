<?php

namespace App\Services\Compliance;

use App\Models\ComplianceRequirement;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Revurdering of a requirement: when it is next to be assessed, and whether that is overdue.
 *
 * Computed on read, never stored — the same rules as RiskReviewSchedule. A review is carried out by
 * registering a new ComplianceAssessment, so the next date follows from two things only: the day
 * of the latest assessment and the requirement's review_interval_months.
 *
 *  - No interval: no next date. Never assessed: no next date either — that is Ikke vurdert, which
 *    is not the same as overdue.
 *  - Calendar months with addMonthsNoOverflow: 31 Jan + 1 month = 28/29 Feb.
 *  - Overdue only from the day AFTER the next review date: due today is not overdue.
 *  - A retired requirement has no next date and is never overdue; it is no longer followed up.
 *
 * Pure: it looks nothing up. ComplianceStatusResolver hands it the latest assessment.
 */
class ComplianceReviewSchedule
{
    public function nextReviewOn(string $requirementStatus, ?int $intervalMonths, ?CarbonInterface $lastAssessedAt): ?CarbonImmutable
    {
        if ($requirementStatus !== ComplianceRequirement::STATUS_ACTIVE || $intervalMonths === null || $lastAssessedAt === null) {
            return null;
        }

        return CarbonImmutable::parse($lastAssessedAt->toDateString())->addMonthsNoOverflow($intervalMonths);
    }

    public function isOverdue(?CarbonInterface $nextReviewOn, ?CarbonInterface $today = null): bool
    {
        if ($nextReviewOn === null) {
            return false;
        }

        $today = CarbonImmutable::parse(($today ?? now())->toDateString());

        return $today->greaterThan(CarbonImmutable::parse($nextReviewOn->toDateString()));
    }
}
