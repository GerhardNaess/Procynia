<?php

namespace App\Services\ManagementReview\Sections;

use App\Models\ComplianceAssessment;
use App\Models\ComplianceAudit;
use App\Models\ComplianceAuditFinding;
use App\Models\ComplianceAuditStatusChange;
use App\Models\ComplianceRequirement;
use App\Models\User;
use App\Services\Compliance\ComplianceAccessService;
use App\Services\Compliance\ComplianceAttentionService;
use App\Services\Compliance\ComplianceAuditAttentionService;
use App\Services\Compliance\ComplianceStatusResolver;
use App\Services\ManagementReview\ReviewScope;
use App\Services\ManagementReview\SectionBuilder;
use App\Services\ManagementReview\SectionPayload;

/**
 * Etterlevelse og revisjon. Customer-wide, read through ComplianceAccessService (an explicit-grant
 * domain: System Owner sees none of it without a role of their own).
 *
 * In the period: compliance assessments registered, audits completed (from the audit status history
 * — an audit has no actual dates of its own), and audit findings by type and how many were handed
 * to Avvik og forbedringer. Now: each active requirement's status from its latest assessment, reviews
 * overdue, and audits overdue or with a nonconformity not yet followed up.
 */
final class ComplianceSection implements SectionBuilder
{
    public function __construct(
        private readonly ComplianceAccessService $access,
        private readonly ComplianceStatusResolver $resolver,
        private readonly ComplianceAttentionService $attention,
        private readonly ComplianceAuditAttentionService $auditAttention,
    ) {}

    public function build(User $user, ReviewScope $scope, ?array $areaIds): array
    {
        $payload = (new SectionPayload(false, (int) config('management_review.list_limit', 50)))
            ->metrics('period', ['assessments', 'audits_completed', 'findings_nonconformity', 'findings_observation', 'findings_opportunity', 'findings_handed_off'])
            ->metrics('status', ['requirements_active', 'compliant', 'partially_compliant', 'non_compliant', 'not_applicable', 'not_assessed', 'review_overdue', 'audits_open', 'audits_overdue', 'nonconformity_without_follow_up'])
            ->list('requirements_attention')
            ->list('audits_completed');

        $requirements = $this->access->visibleRequirements($user)
            ->where('compliance_requirements.status', ComplianceRequirement::STATUS_ACTIVE)
            ->with('source:id,name,version')
            ->orderBy('compliance_requirements.title')
            ->orderBy('compliance_requirements.id')
            ->get(['compliance_requirements.*']);

        $allRequirementIds = $this->access->visibleRequirements($user)->pluck('compliance_requirements.id')->all();

        $payload->count('period', 'assessments', ComplianceAssessment::query()
            ->where('customer_id', $scope->customerId)
            ->whereIn('requirement_id', $allRequirementIds ?: [0])
            ->where('assessed_at', '>=', $scope->from())
            ->where('assessed_at', '<', $scope->until())
            ->count());

        $latest = $this->resolver->latestForRequirements($scope->customerId, $requirements->modelKeys());
        $reasons = $this->attention->reasonsForRequirements($requirements, $latest, $scope->today);
        $rank = [ComplianceAssessment::RESULT_NON_COMPLIANT => 0, ComplianceAssessment::RESULT_PARTIALLY_COMPLIANT => 1, ComplianceStatusResolver::STATUS_NOT_ASSESSED => 2];
        $attentionRows = [];

        foreach ($requirements as $requirement) {
            $status = $this->resolver->statusOf($latest->get((int) $requirement->id));
            $payload->count('status', 'requirements_active');
            $payload->count('status', $status);
            $flags = $reasons[(int) $requirement->id] ?? [];

            if (in_array(ComplianceAttentionService::REVIEW_OVERDUE, $flags, true)) {
                $payload->count('status', 'review_overdue');
            }

            if (isset($rank[$status]) || in_array(ComplianceAttentionService::REVIEW_OVERDUE, $flags, true)) {
                $attentionRows[] = ['requirement' => $requirement, 'status' => $status, 'overdue' => in_array(ComplianceAttentionService::REVIEW_OVERDUE, $flags, true)];
            }
        }

        usort($attentionRows, fn (array $a, array $b): int => [$rank[$a['status']] ?? 3, $a['requirement']->title] <=> [$rank[$b['status']] ?? 3, $b['requirement']->title]);

        foreach ($attentionRows as $row) {
            $requirement = $row['requirement'];
            $payload->item('requirements_attention', [
                'id' => (int) $requirement->id,
                'title' => trim(($requirement->reference ? $requirement->reference.' ' : '').$requirement->title),
                'url' => route('app.compliance.requirements.show', ['requirementId' => $requirement->id], false),
                'fields' => SectionPayload::fields([
                    'source' => ['text', trim($requirement->source?->name.' '.$requirement->source?->version)],
                    'compliance_status' => ['enum', $row['status']],
                    'review_overdue' => ['enum', $row['overdue'] ? 'yes' : null],
                ]),
            ]);
        }

        $audits = $this->access->visibleAudits($user)->orderBy('compliance_audits.title')->get(['compliance_audits.*']);
        $auditIds = $audits->modelKeys() ?: [0];
        $completions = ComplianceAuditStatusChange::query()
            ->whereIn('audit_id', $auditIds)
            ->where('to_status', ComplianceAudit::STATUS_COMPLETED)
            ->where('changed_at', '>=', $scope->from())
            ->where('changed_at', '<', $scope->until())
            ->orderBy('changed_at')
            ->get(['audit_id', 'changed_at']);

        $byId = $audits->keyBy('id');

        foreach ($completions as $completion) {
            $audit = $byId->get($completion->audit_id);
            $payload->count('period', 'audits_completed');
            $payload->item('audits_completed', [
                'id' => (int) $audit->id,
                'title' => $audit->title,
                'url' => route('app.compliance.audits.show', ['auditId' => $audit->id], false),
                'fields' => SectionPayload::fields([
                    'audit_type' => ['enum', 'audit_'.$audit->audit_type],
                    'completed_on' => ['date', $completion->changed_at?->toDateString()],
                    'conclusion' => ['text', $audit->conclusion !== null ? mb_strimwidth($audit->conclusion, 0, 400, '…') : null],
                ]),
            ]);
        }

        $findings = ComplianceAuditFinding::query()
            ->whereIn('audit_id', $auditIds)
            ->where('created_at', '>=', $scope->from())
            ->where('created_at', '<', $scope->until())
            ->get(['finding_type', 'improvement_case_id']);

        foreach ($findings as $finding) {
            $payload->count('period', 'findings_'.$finding->finding_type);

            if ($finding->improvement_case_id !== null) {
                $payload->count('period', 'findings_handed_off');
            }
        }

        $open = $audits->filter(fn (ComplianceAudit $audit): bool => in_array($audit->status, [ComplianceAudit::STATUS_PLANNED, ComplianceAudit::STATUS_IN_PROGRESS], true));
        $payload->count('status', 'audits_open', $open->count());

        foreach ($this->auditAttention->reasonsForAudits($audits, $scope->today) as $flags) {
            foreach ([ComplianceAuditAttentionService::OVERDUE => 'audits_overdue', ComplianceAuditAttentionService::NONCONFORMITY_WITHOUT_FOLLOW_UP => 'nonconformity_without_follow_up'] as $key => $metric) {
                if (in_array($key, $flags, true)) {
                    $payload->count('status', $metric);
                }
            }
        }

        return $payload->toArray();
    }
}
