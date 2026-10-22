<?php

namespace App\Services\Compliance;

use App\Models\ComplianceAudit;
use App\Models\ComplianceAuditCheck;
use App\Models\ComplianceAuditCheckRequirement;
use App\Models\ComplianceAuditFinding;
use App\Models\ComplianceAuditRequirement;
use App\Models\ComplianceRequirement;
use App\Models\User;
use App\Services\Security\HumanDecisionGuard;
use App\Support\CustomerPermissionCatalog;
use App\Support\HumanDecision;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Kontrollhandlinger on one audit: what the auditor examined, how, and on what evidence — recorded,
 * changed and deleted, and what the audit page shows about them.
 *
 * WHAT A CHECK IS. A record of an examination, not a verdict. «Kontrollert» never means a
 * requirement is met: the auditor's observation and the audit's conclusion say what was found, and
 * results that need recording on their own are findings. Nothing here touches an assessment.
 *
 * REQUIREMENTS. A check names none, one or several requirements, all in the audit's scope — one
 * examination of a process often covers several clauses, and nobody should write the same
 * observation once per clause. The database holds the scope rule too.
 *
 * FINDINGS. A finding may name the check it came from (ComplianceAuditFindingService); one check can
 * give rise to several. A check with findings cannot be deleted, so a finding never loses where it
 * came from — the database refuses it as well.
 *
 * LIFECYCLE AND ACCESS. The same as findings: compliance.audit, while the audit is in progress. Each
 * write locks the audit row and checks its status again inside the lock. The audit is reached
 * through ComplianceAccessService by the caller.
 */
class ComplianceAuditCheckService
{
    public function __construct(
        private readonly ComplianceAccessService $access,
        private readonly HumanDecisionGuard $guard,
    ) {}

    /** Ny kontrollhandling, Rediger and Slett: compliance.audit on an audit in progress. */
    public function canRecord(User $user, ComplianceAudit $audit): bool
    {
        return $audit->canRecordFindings() && $this->access->canAudit($user);
    }

    /**
     * The audit's checks as the page shows them, in the order they were recorded: what was examined,
     * the requirements with their origin, and the findings that came from each.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(User $user, ComplianceAudit $audit): array
    {
        $checks = ComplianceAuditCheck::query()
            ->where('audit_id', $audit->id)
            ->where('customer_id', $audit->customer_id)
            ->with([
                'requirementLinks.requirement.source:id,name,version,framework_id',
                'findings:id,check_id,finding_type,title',
                'createdBy:id,name',
            ])
            ->orderBy('id')
            ->get();

        $canRecord = $this->canRecord($user, $audit);

        return $checks->map(function (ComplianceAuditCheck $check) use ($canRecord): array {
            $hasFindings = $check->findings->isNotEmpty();

            return [
                'id' => (int) $check->id,
                'title' => $check->title,
                'observation' => $check->observation,
                'evidence' => $check->evidence,
                'requirements' => $check->requirementLinks
                    ->map(fn (ComplianceAuditCheckRequirement $link): ?ComplianceRequirement => $link->requirement)
                    ->filter()
                    ->sortBy(fn (ComplianceRequirement $requirement): string => mb_strtolower(($requirement->source?->originLabel() ?? '').' '.($requirement->reference ?? '').' '.$requirement->title))
                    ->map(fn (ComplianceRequirement $requirement): array => $this->requirementRow($requirement))
                    ->values()
                    ->all(),
                'findings' => $check->findings
                    ->map(fn (ComplianceAuditFinding $finding): array => [
                        'id' => (int) $finding->id,
                        'finding_type' => $finding->finding_type,
                        'title' => $finding->title,
                    ])
                    ->values()
                    ->all(),
                'created_by_name' => $check->createdBy?->name,
                'created_at' => $check->created_at?->toIso8601String(),
                'permissions' => [
                    'can_edit' => $canRecord,
                    'can_delete' => $canRecord && ! $hasFindings,
                ],
            ];
        })->all();
    }

    /** @param  array<string, mixed>  $input  validated by the controller */
    public function create(User $user, ComplianceAudit $audit, array $input): ComplianceAuditCheck
    {
        $user = $this->authorizeAudit($user, $audit);

        $fields = $this->fields($input);
        $requirementIds = $this->requirementIds($audit, $input['requirement_ids'] ?? []);

        return DB::transaction(function () use ($user, $audit, $fields, $requirementIds): ComplianceAuditCheck {
            $this->lockOpenAudit($audit);

            $check = ComplianceAuditCheck::query()->create($fields + [
                'customer_id' => (int) $audit->customer_id,
                'audit_id' => (int) $audit->id,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            $this->syncRequirements($audit, $check, $requirementIds);

            return $check;
        });
    }

    /** @param  array<string, mixed>  $input  validated by the controller */
    public function update(User $user, ComplianceAudit $audit, ComplianceAuditCheck $check, array $input): void
    {
        $user = $this->authorizeAudit($user, $audit);

        $fields = $this->fields($input);
        $requirementIds = $this->requirementIds($audit, $input['requirement_ids'] ?? []);

        DB::transaction(function () use ($user, $audit, $check, $fields, $requirementIds): void {
            $this->lockOpenAudit($audit);
            $locked = $this->lockedCheck($audit, $check);

            $locked->fill($fields + ['updated_by' => $user->id])->save();
            $this->syncRequirements($audit, $locked, $requirementIds);
        });
    }

    /** Slett: only a check no finding came from. */
    public function delete(User $user, ComplianceAudit $audit, ComplianceAuditCheck $check): void
    {
        $this->authorizeAudit($user, $audit);

        DB::transaction(function () use ($audit, $check): void {
            $this->lockOpenAudit($audit);
            $locked = $this->lockedCheck($audit, $check);

            if (ComplianceAuditFinding::query()->where('check_id', $locked->id)->exists()) {
                throw ValidationException::withMessages(['check' => __('procynia.compliance.audits.checks.validation.has_findings')]);
            }

            $locked->delete();
        });
    }

    /** A check of this audit, or null — the same answer for another audit's or customer's id. */
    public function find(ComplianceAudit $audit, int $checkId): ?ComplianceAuditCheck
    {
        return ComplianceAuditCheck::query()
            ->where('audit_id', $audit->id)
            ->where('customer_id', $audit->customer_id)
            ->whereKey($checkId)
            ->first();
    }

    /** @return array{title: string, observation: string, evidence: ?string} */
    private function fields(array $input): array
    {
        $evidence = trim((string) ($input['evidence'] ?? ''));

        return [
            'title' => trim((string) $input['title']),
            'observation' => trim((string) $input['observation']),
            'evidence' => $evidence !== '' ? $evidence : null,
        ];
    }

    /**
     * The requirements named, each in the audit's scope. Anything else — out of scope, another
     * customer's, missing — gets the same answer.
     *
     * @param  array<int, mixed>  $ids
     * @return list<int>
     */
    private function requirementIds(ComplianceAudit $audit, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            return [];
        }

        $inScope = ComplianceAuditRequirement::query()
            ->where('audit_id', $audit->id)
            ->where('customer_id', $audit->customer_id)
            ->whereIn('requirement_id', $ids)
            ->count();

        if ($inScope !== count($ids)) {
            throw ValidationException::withMessages(['requirement_ids' => __('procynia.compliance.audits.checks.validation.requirement_not_in_scope')]);
        }

        return $ids;
    }

    /** @param  list<int>  $requirementIds */
    private function syncRequirements(ComplianceAudit $audit, ComplianceAuditCheck $check, array $requirementIds): void
    {
        ComplianceAuditCheckRequirement::query()
            ->where('check_id', $check->id)
            ->whereNotIn('requirement_id', $requirementIds)
            ->delete();

        $existing = ComplianceAuditCheckRequirement::query()->where('check_id', $check->id)->pluck('requirement_id')->map(fn ($id): int => (int) $id)->all();

        foreach (array_diff($requirementIds, $existing) as $requirementId) {
            ComplianceAuditCheckRequirement::query()->create([
                'customer_id' => (int) $audit->customer_id,
                'audit_id' => (int) $audit->id,
                'check_id' => (int) $check->id,
                'requirement_id' => $requirementId,
            ]);
        }
    }

    /** The check row, locked — and only as one of this audit's own: another audit's check is not found. */
    private function lockedCheck(ComplianceAudit $audit, ComplianceAuditCheck $check): ComplianceAuditCheck
    {
        return ComplianceAuditCheck::query()
            ->where('audit_id', $audit->id)
            ->where('customer_id', $audit->customer_id)
            ->whereKey($check->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Revisjonsarbeid is a person's (HumanDecision::COMPLIANCE_AUDIT, phase 0 step 13): the guard, then
     * compliance.audit in the audit's own customer with the module — before anything is read.
     */
    private function authorizeAudit(User $user, ComplianceAudit $audit): User
    {
        $user = $this->guard->assert($user, HumanDecision::COMPLIANCE_AUDIT);
        $this->access->authorize($user, CustomerPermissionCatalog::COMPLIANCE_AUDIT, (int) $audit->customer_id);

        return $user;
    }

    /** The audit row, locked against a lifecycle step, still in progress. */
    private function lockOpenAudit(ComplianceAudit $audit): void
    {
        $locked = ComplianceAudit::query()->whereKey($audit->id)->lockForUpdate()->firstOrFail();

        if (! $locked->canRecordFindings()) {
            throw ValidationException::withMessages(['audit' => __('procynia.compliance.audits.checks.validation.audit_not_in_progress')]);
        }
    }

    /** @return array<string, mixed> */
    private function requirementRow(ComplianceRequirement $requirement): array
    {
        return [
            'id' => (int) $requirement->id,
            'reference' => $requirement->reference,
            'title' => $requirement->title,
            'status' => $requirement->status,
            'source_label' => $requirement->source?->originLabel(),
            'origin' => $requirement->origin(),
            'url' => route('app.compliance.requirements.show', ['requirementId' => $requirement->id]),
        ];
    }
}
