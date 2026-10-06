<?php

namespace App\Services\Compliance;

use App\Models\ComplianceAudit;
use App\Models\ComplianceAuditFinding;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * «Trenger oppmerksomhet» for revisjoner — two fixed reasons, each read off data that already
 * decides it, never stored and never folded into a score:
 *
 *  - Revisjon forfalt: planned or in progress, and the planned end date has passed. The end date
 *    itself is not overdue. A completed or cancelled audit is never overdue.
 *  - Avvik uten oppfølging: completed, with at least one avvik that has not been handed off to
 *    Avvik og forbedringer (ComplianceAuditFinding::nonconformitiesAwaitingHandoff()). Observasjoner
 *    and forbedringsmuligheter never raise it, and neither does an audit that is under way again
 *    after a reopening — it is asked again when the audit is completed again.
 *
 * A cancelled audit raises nothing at all.
 *
 * Handing a finding off clears the second reason by itself: it follows from improvement_case_id
 * alone. Nothing here reads the case — its status, area or anything else — so the signal says
 * nothing about Avvik og forbedringer the person might not be allowed to see.
 *
 * Nothing here looks an audit up on its own: every audit handed in must already have been reached
 * through ComplianceAccessService::visibleAudits(), and findings are fetched by exactly those ids.
 */
class ComplianceAuditAttentionService
{
    public const OVERDUE = 'overdue';

    public const NONCONFORMITY_WITHOUT_FOLLOW_UP = 'nonconformity_without_follow_up';

    /** Display order. */
    public const REASONS = [
        self::OVERDUE,
        self::NONCONFORMITY_WITHOUT_FOLLOW_UP,
    ];

    /**
     * The reasons for one audit.
     *
     * @return list<string>
     */
    public function reasonsForAudit(ComplianceAudit $audit, ?CarbonInterface $today = null): array
    {
        return $this->reasonsForAudits(collect([$audit]), $today)[(int) $audit->id];
    }

    /**
     * The reasons for each of the given audits, keyed by audit id, with the findings read in one
     * query whatever the number of audits.
     *
     * @param  Collection<int, ComplianceAudit>  $audits  already access-narrowed
     * @return array<int, list<string>>
     */
    public function reasonsForAudits(Collection $audits, ?CarbonInterface $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();

        $completedIds = $audits
            ->filter(fn (ComplianceAudit $audit): bool => $audit->status === ComplianceAudit::STATUS_COMPLETED)
            ->map(fn (ComplianceAudit $audit): int => (int) $audit->id)
            ->values()
            ->all();

        $unhandled = $completedIds === []
            ? []
            : array_flip(ComplianceAuditFinding::query()
                ->nonconformitiesAwaitingHandoff()
                ->whereIn('compliance_audit_findings.audit_id', $completedIds)
                ->distinct()
                ->pluck('compliance_audit_findings.audit_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all());

        $reasons = [];

        foreach ($audits as $audit) {
            $id = (int) $audit->id;
            $reasons[$id] = [];

            if ($this->isOverdue($audit, $today)) {
                $reasons[$id][] = self::OVERDUE;
            }

            if (isset($unhandled[$id])) {
                $reasons[$id][] = self::NONCONFORMITY_WITHOUT_FOLLOW_UP;
            }
        }

        return $reasons;
    }

    private function isOverdue(ComplianceAudit $audit, CarbonInterface $today): bool
    {
        return in_array($audit->status, [ComplianceAudit::STATUS_PLANNED, ComplianceAudit::STATUS_IN_PROGRESS], true)
            && $audit->planned_end_date !== null
            && $audit->planned_end_date->lt($today);
    }
}
