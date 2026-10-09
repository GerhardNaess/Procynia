<?php

namespace App\Services\MyTasks\Sources;

use App\Models\ComplianceAudit;
use App\Models\ComplianceRequirement;
use App\Models\User;
use App\Services\Compliance\ComplianceAccessService;
use App\Services\Compliance\ComplianceAttentionService as RequirementAttention;
use App\Services\Compliance\ComplianceAuditAttentionService as AuditAttention;
use App\Services\Compliance\ComplianceReviewSchedule;
use App\Services\Compliance\ComplianceStatusResolver;
use App\Services\MyTasks\MyTask;
use App\Services\MyTasks\MyTaskSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Etterlevelse og revisjon: requirements and audits the person is ansvarlig for that ask something
 * of them.
 *
 *  - An active requirement whose owner is the person and that ComplianceAttentionService flags — not
 *    assessed, not or partially compliant, revurdering forfalt — one task, its reasons the service's.
 *    Due on the next review date ComplianceReviewSchedule gives. Oppfylt and not due is no work.
 *  - An audit the person is ansvarlig for that is planned or in progress — one task, due on its
 *    planned end date — or completed with an avvik not yet followed up in Avvik og forbedringer.
 *    Its reasons are ComplianceAuditAttentionService's.
 *
 * Revisjonsfunn have no owner of their own and are not tasks: the audit's ansvarlig hands them off.
 *
 * ACCESS. ComplianceAccessService::canReadFromAnotherModule() (module + compliance.view), and the
 * customer-wide visibleRequirements()/visibleAudits().
 *
 * READ, NOT ACT. compliance.assess to assess or review a requirement, compliance.audit to run an
 * audit and hand off its findings.
 */
class ComplianceTaskSource implements MyTaskSource
{
    public function __construct(
        private readonly ComplianceAccessService $access,
        private readonly RequirementAttention $requirementAttention,
        private readonly AuditAttention $auditAttention,
        private readonly ComplianceReviewSchedule $schedule,
        private readonly ComplianceStatusResolver $status,
    ) {}

    public function module(): string
    {
        return 'compliance';
    }

    public function isAvailableFor(User $user): bool
    {
        return $this->access->canReadFromAnotherModule($user);
    }

    public function openTasksFor(User $user, int $customerId, CarbonImmutable $today): Collection
    {
        if (! $this->isAvailableFor($user)) {
            return collect();
        }

        return $this->requirementTasks($user, $customerId, $today)->merge($this->auditTasks($user, $today))->values();
    }

    /** @return Collection<int, MyTask> */
    private function requirementTasks(User $user, int $customerId, CarbonImmutable $today): Collection
    {
        $requirements = $this->access->visibleRequirements($user)
            ->where('compliance_requirements.owner_user_id', $user->id)
            ->where('compliance_requirements.status', ComplianceRequirement::STATUS_ACTIVE)
            ->orderBy('compliance_requirements.id')
            ->get(['compliance_requirements.*']);

        if ($requirements->isEmpty()) {
            return collect();
        }

        $latest = $this->status->latestForRequirements($customerId, $requirements->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
        $reasons = $this->requirementAttention->reasonsForRequirements($requirements, $latest, $today);
        $canAssess = $this->access->canAssess($user);

        return $requirements->toBase()
            ->filter(fn (ComplianceRequirement $requirement): bool => array_diff($reasons[(int) $requirement->id], [RequirementAttention::MISSING_OWNER]) !== [])
            ->map(function (ComplianceRequirement $requirement) use ($reasons, $latest, $canAssess, $user): MyTask {
                $next = $this->schedule->nextReviewOn((string) $requirement->status, $requirement->review_interval_months, $latest->get((int) $requirement->id)?->assessed_at);
                $rows = array_map(fn (string $key): array => [
                    'key' => $key,
                    'due_on' => $key === RequirementAttention::REVIEW_OVERDUE ? $next?->toDateString() : null,
                    'overdue' => $key === RequirementAttention::REVIEW_OVERDUE,
                    'can_act' => $canAssess,
                ], $reasons[(int) $requirement->id]);

                return new MyTask(
                    id: 'compliance-requirement-'.$requirement->id,
                    module: $this->module(),
                    type: 'compliance_requirement',
                    title: $requirement->title,
                    subjectTitle: $requirement->title,
                    assigneeUserId: (int) $user->id,
                    actionUrl: route('app.compliance.requirements.show', ['requirementId' => $requirement->id], false),
                    dueOn: $next,
                    overdue: in_array(true, array_column($rows, 'overdue'), true),
                    reasons: $rows,
                    canAct: $canAssess,
                    details: ['requirement' => ['id' => (int) $requirement->id, 'title' => $requirement->title]],
                    subject: ['prefix' => 'compliance', 'metadata' => ['compliance_requirement_id' => (int) $requirement->id]],
                );
            })
            ->values();
    }

    /** @return Collection<int, MyTask> */
    private function auditTasks(User $user, CarbonImmutable $today): Collection
    {
        $audits = $this->access->visibleAudits($user)
            ->where('compliance_audits.responsible_user_id', $user->id)
            ->whereIn('compliance_audits.status', [ComplianceAudit::STATUS_PLANNED, ComplianceAudit::STATUS_IN_PROGRESS, ComplianceAudit::STATUS_COMPLETED])
            ->orderBy('compliance_audits.id')
            ->get(['compliance_audits.*']);

        if ($audits->isEmpty()) {
            return collect();
        }

        $reasons = $this->auditAttention->reasonsForAudits($audits, $today);
        $canAudit = $this->access->canAudit($user);

        return $audits->toBase()
            ->filter(fn (ComplianceAudit $audit): bool => $audit->status !== ComplianceAudit::STATUS_COMPLETED || $reasons[(int) $audit->id] !== [])
            ->map(function (ComplianceAudit $audit) use ($reasons, $canAudit, $user): MyTask {
                $open = $audit->status !== ComplianceAudit::STATUS_COMPLETED;
                $dueOn = $open ? $audit->planned_end_date?->toDateString() : null;
                $keys = $reasons[(int) $audit->id] ?: ['audit_open'];
                $rows = array_map(fn (string $key): array => [
                    'key' => $key,
                    'due_on' => $key === AuditAttention::OVERDUE || $key === 'audit_open' ? $dueOn : null,
                    'overdue' => $key === AuditAttention::OVERDUE,
                    'can_act' => $canAudit,
                ], $keys);

                return new MyTask(
                    id: 'compliance-audit-'.$audit->id,
                    module: $this->module(),
                    type: 'compliance_audit',
                    title: $audit->title,
                    subjectTitle: $audit->title,
                    assigneeUserId: (int) $user->id,
                    actionUrl: route('app.compliance.audits.show', ['auditId' => $audit->id], false),
                    dueOn: $dueOn !== null ? CarbonImmutable::parse($dueOn) : null,
                    overdue: in_array(true, array_column($rows, 'overdue'), true),
                    reasons: $rows,
                    canAct: $canAudit,
                    details: ['audit' => ['id' => (int) $audit->id, 'title' => $audit->title, 'status' => $audit->status]],
                    subject: ['prefix' => 'compliance', 'metadata' => ['compliance_audit_id' => (int) $audit->id]],
                );
            })
            ->values();
    }
}
