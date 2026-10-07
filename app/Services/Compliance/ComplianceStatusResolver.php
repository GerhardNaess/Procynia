<?php

namespace App\Services\Compliance;

use App\Models\ComplianceAssessment;
use App\Models\ComplianceRequirement;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * The one answer to «does the virksomhet meet this requirement now, and since when?».
 *
 * The current assessment is the latest by assessed_at, the highest id breaking a tie. The current
 * compliance status is its result — or not_assessed when there is none, which is never stored
 * anywhere. Nothing of this is written to compliance_requirements; it is computed here on read,
 * together with the review schedule and whether the requirement has changed since it was assessed.
 *
 * Every requirement passed in must already have been reached through
 * ComplianceAccessService::visibleRequirements(); nothing here looks a requirement up on its own,
 * so a hidden requirement's status cannot leak through it.
 */
class ComplianceStatusResolver
{
    /** Derived only: the requirement has no assessment. Never a stored result. */
    public const STATUS_NOT_ASSESSED = 'not_assessed';

    public function __construct(
        private readonly ComplianceReviewSchedule $schedule,
    ) {}

    public function latestFor(ComplianceRequirement $requirement): ?ComplianceAssessment
    {
        return $this->latestForRequirements((int) $requirement->customer_id, [(int) $requirement->id])->get((int) $requirement->id);
    }

    /**
     * The latest assessment of each of the given requirements, keyed by requirement id, in one
     * query that returns one row per requirement.
     *
     * @param  list<int>  $requirementIds
     * @return Collection<int, ComplianceAssessment>
     */
    public function latestForRequirements(int $customerId, array $requirementIds): Collection
    {
        if ($requirementIds === []) {
            return collect();
        }

        return ComplianceAssessment::query()
            ->with('assessedBy:id,name')
            ->where('compliance_assessments.customer_id', $customerId)
            ->whereIn('compliance_assessments.requirement_id', $requirementIds)
            ->whereNotExists(fn (QueryBuilder $newer) => $newer
                ->selectRaw('1')
                ->from('compliance_assessments as newer')
                ->whereColumn('newer.requirement_id', 'compliance_assessments.requirement_id')
                ->where(fn (QueryBuilder $later) => $later
                    ->whereColumn('newer.assessed_at', '>', 'compliance_assessments.assessed_at')
                    ->orWhere(fn (QueryBuilder $tie) => $tie
                        ->whereColumn('newer.assessed_at', 'compliance_assessments.assessed_at')
                        ->whereColumn('newer.id', '>', 'compliance_assessments.id'))))
            ->get()
            ->keyBy(fn (ComplianceAssessment $assessment): int => (int) $assessment->requirement_id);
    }

    /** The result of the current assessment, or not_assessed. */
    public function statusOf(?ComplianceAssessment $latest): string
    {
        return $latest?->result ?? self::STATUS_NOT_ASSESSED;
    }

    /**
     * Whether what was assessed is no longer what the requirement says: its reference, title, text,
     * or its source's name or version differ from the latest assessment's snapshot. A fact for the
     * page, never a status — the assessment and its result stand.
     */
    public function changedSince(ComplianceRequirement $requirement, ?ComplianceAssessment $latest): bool
    {
        if ($latest === null) {
            return false;
        }

        $requirement->loadMissing('source:id,name,version');

        return $latest->requirement_reference !== $requirement->reference
            || $latest->requirement_title !== $requirement->title
            || $latest->requirement_text !== $requirement->requirement_text
            || $latest->source_name !== $requirement->source?->name
            || $latest->source_version !== $requirement->source?->version;
    }

    /**
     * Everything the pages, and later Attention, need about one requirement's compliance.
     *
     * @return array{
     *     status: string,
     *     assessment_id: int|null,
     *     assessed_at: string|null,
     *     assessed_by_name: string|null,
     *     rationale: string|null,
     *     next_review_on: string|null,
     *     is_overdue: bool,
     *     changed_since_assessment: bool
     * }
     */
    public function summarize(ComplianceRequirement $requirement, ?ComplianceAssessment $latest, ?CarbonInterface $today = null): array
    {
        $next = $this->schedule->nextReviewOn((string) $requirement->status, $requirement->review_interval_months, $latest?->assessed_at);

        return [
            'status' => $this->statusOf($latest),
            'assessment_id' => $latest !== null ? (int) $latest->id : null,
            'assessed_at' => $latest?->assessed_at?->toIso8601String(),
            'assessed_by_name' => $latest?->assessedBy?->name,
            'rationale' => $latest?->rationale,
            'next_review_on' => $next?->toDateString(),
            'is_overdue' => $this->schedule->isOverdue($next, $today),
            'changed_since_assessment' => $this->changedSince($requirement, $latest),
        ];
    }
}
