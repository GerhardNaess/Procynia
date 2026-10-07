<?php

namespace App\Services\Compliance;

use App\Models\ComplianceAssessment;
use App\Models\ComplianceRequirement;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * «Trenger oppmerksomhet» — which requirements have something concrete to follow up.
 *
 * Five fixed reasons, each read off data that already decides it elsewhere, never stored and never
 * folded into a score:
 *
 *  - Ikke vurdert, Ikke oppfylt, Delvis oppfylt: the current status from ComplianceStatusResolver.
 *    Oppfylt and Ikke relevant raise nothing.
 *  - Revurdering forfalt: ComplianceReviewSchedule — due today is not overdue.
 *  - Mangler ansvarlig: owner_user_id is null.
 *
 * Only an active requirement can need attention. A retired one raises nothing at all — no status,
 * no review, no owner — though its history stays where it is.
 *
 * Nothing here looks a requirement up on its own: every requirement handed in must already have been
 * reached through ComplianceAccessService::visibleRequirements(), and the assessments are fetched by
 * exactly those ids. A hidden requirement can therefore move neither a count nor a reason. Nothing
 * here writes either; fixing the requirement is the only way to clear a reason.
 */
class ComplianceAttentionService
{
    public const NOT_ASSESSED = 'not_assessed';

    public const NON_COMPLIANT = 'non_compliant';

    public const PARTIALLY_COMPLIANT = 'partially_compliant';

    public const REVIEW_OVERDUE = 'review_overdue';

    public const MISSING_OWNER = 'missing_owner';

    /** Display order. */
    public const REASONS = [
        self::NOT_ASSESSED,
        self::NON_COMPLIANT,
        self::PARTIALLY_COMPLIANT,
        self::REVIEW_OVERDUE,
        self::MISSING_OWNER,
    ];

    /** The current statuses that are a reason of their own; the reason carries the status's name. */
    private const STATUS_REASONS = [
        ComplianceStatusResolver::STATUS_NOT_ASSESSED => self::NOT_ASSESSED,
        ComplianceAssessment::RESULT_NON_COMPLIANT => self::NON_COMPLIANT,
        ComplianceAssessment::RESULT_PARTIALLY_COMPLIANT => self::PARTIALLY_COMPLIANT,
    ];

    public function __construct(
        private readonly ComplianceStatusResolver $status,
        private readonly ComplianceReviewSchedule $schedule,
    ) {}

    /**
     * The reasons for one requirement, looking up its latest assessment.
     *
     * @return list<string>
     */
    public function reasonsForRequirement(ComplianceRequirement $requirement, ?CarbonInterface $today = null): array
    {
        if (! $requirement->isActive()) {
            return [];
        }

        return $this->reasonsFor($requirement, $this->status->latestFor($requirement), $today);
    }

    /**
     * The reasons for each of the given requirements, keyed by requirement id, with their latest
     * assessments read in one query — or none, when the caller already holds them.
     *
     * @param  Collection<int, ComplianceRequirement>  $requirements  all of one customer, already access-narrowed
     * @param  Collection<int, ComplianceAssessment>|null  $latest  keyed by requirement id
     * @return array<int, list<string>>
     */
    public function reasonsForRequirements(Collection $requirements, ?Collection $latest = null, ?CarbonInterface $today = null): array
    {
        $active = $requirements->filter(fn (ComplianceRequirement $requirement): bool => $requirement->isActive());

        if ($latest === null && $active->isNotEmpty()) {
            $latest = $this->status->latestForRequirements(
                (int) $active->first()->customer_id,
                $active->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all(),
            );
        }

        $reasons = [];

        foreach ($requirements as $requirement) {
            $id = (int) $requirement->id;
            $reasons[$id] = $requirement->isActive() ? $this->reasonsFor($requirement, $latest?->get($id), $today) : [];
        }

        return $reasons;
    }

    /**
     * The reasons for one requirement given its latest assessment, in display order.
     *
     * @return list<string>
     */
    public function reasonsFor(ComplianceRequirement $requirement, ?ComplianceAssessment $latest, ?CarbonInterface $today = null): array
    {
        if (! $requirement->isActive()) {
            return [];
        }

        $reasons = [];
        $statusReason = self::STATUS_REASONS[$this->status->statusOf($latest)] ?? null;

        if ($statusReason !== null) {
            $reasons[] = $statusReason;
        }

        $next = $this->schedule->nextReviewOn((string) $requirement->status, $requirement->review_interval_months, $latest?->assessed_at);

        if ($this->schedule->isOverdue($next, $today)) {
            $reasons[] = self::REVIEW_OVERDUE;
        }

        if ($requirement->owner_user_id === null) {
            $reasons[] = self::MISSING_OWNER;
        }

        return $reasons;
    }
}
