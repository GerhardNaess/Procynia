<?php

namespace App\Services\Compliance;

use App\Models\ComplianceAudit;
use App\Models\ComplianceAuditProcess;
use App\Models\ComplianceAuditRequirement;
use App\Models\ComplianceRequirement;
use App\Models\ComplianceSource;
use App\Models\QualityItem;
use App\Models\User;
use App\Services\Quality\QualityProcessContextReader;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A revisjon's scope as structure: the requirements and the Kvalitet processes it looks at.
 *
 * The authoritative scope is the audit's scope_description; these links support it. They are fixed
 * rows. «Legg til krav fra kravkilde» is a shortcut that writes one row per active requirement of
 * the source at that moment — a requirement added to the source afterwards never enters the audit
 * by itself.
 *
 * REQUIREMENTS. Compliance-global within the customer: any requirement the user can read may be
 * taken into scope, a retired one too (an audit may look at what used to apply). A retired
 * requirement in scope stays there and is shown as utgått.
 *
 * PROCESSES. Two access questions, neither implying the other — the same as Krav → Kvalitet. The
 * audit through ComplianceAccessService; anything about a process only with Kvalitet read access
 * (QualityProcessContextReader::canRead), which compliance.* never implies. Without it the page says
 * nothing about the processes in scope — not their names, not how many there are — and nothing can
 * be linked or unlinked. Only a non-retired process can be added; Kvalitet never reads these links.
 *
 * Changing the scope takes compliance.audit (403 otherwise) and a planned or running audit (422
 * otherwise): a completed audit is reopened first, a cancelled one is read-only.
 */
class ComplianceAuditScopeService
{
    public function __construct(
        private readonly ComplianceAccessService $access,
        private readonly QualityProcessContextReader $quality,
    ) {}

    public function canReadQuality(User $user): bool
    {
        return $this->quality->canRead($user);
    }

    public function canManageRequirements(User $user, ComplianceAudit $audit): bool
    {
        return $audit->canChangeScope() && $this->access->canAudit($user);
    }

    public function canManageProcesses(User $user, ComplianceAudit $audit): bool
    {
        return $this->canManageRequirements($user, $audit) && $this->canReadQuality($user);
    }

    /**
     * The requirements in scope, read live, in the register's order: by source, then reference.
     *
     * @return list<array<string, mixed>>
     */
    public function requirements(ComplianceAudit $audit): array
    {
        return ComplianceRequirement::query()
            ->where('compliance_requirements.customer_id', (int) $audit->customer_id)
            ->whereIn('compliance_requirements.id', ComplianceAuditRequirement::query()
                ->where('audit_id', $audit->id)
                ->where('customer_id', $audit->customer_id)
                ->select('requirement_id'))
            ->with('source:id,name,version')
            ->orderBy(ComplianceSource::query()->select('name')->whereColumn('compliance_sources.id', 'compliance_requirements.source_id'))
            ->orderBy('compliance_requirements.source_id')
            ->orderByRaw('lower(compliance_requirements.reference) ASC NULLS LAST')
            ->orderBy('compliance_requirements.title')
            ->orderBy('compliance_requirements.id')
            ->get()
            ->map(fn (ComplianceRequirement $requirement): array => $this->requirementRow($requirement))
            ->all();
    }

    /**
     * What can still be taken into scope: every requirement the user can read that is not in it yet
     * (retired ones marked), and the sources that still have active requirements to add. Call only
     * for a user who passes canManageRequirements().
     *
     * @return array{requirements: list<array<string, mixed>>, sources: list<array{id: int, label: string, count: int}>}
     */
    public function requirementOptions(User $user, ComplianceAudit $audit): array
    {
        $linked = ComplianceAuditRequirement::query()->where('audit_id', $audit->id)->select('requirement_id');

        $requirements = $this->access->visibleRequirements($user)
            ->whereNotIn('compliance_requirements.id', $linked)
            ->with('source:id,name,version')
            ->orderByRaw('CASE compliance_requirements.status WHEN ? THEN 0 ELSE 1 END', [ComplianceRequirement::STATUS_ACTIVE])
            ->orderBy(ComplianceSource::query()->select('name')->whereColumn('compliance_sources.id', 'compliance_requirements.source_id'))
            ->orderBy('compliance_requirements.source_id')
            ->orderByRaw('lower(compliance_requirements.reference) ASC NULLS LAST')
            ->orderBy('compliance_requirements.title')
            ->orderBy('compliance_requirements.id')
            ->get();

        $perSource = $requirements
            ->filter(fn (ComplianceRequirement $requirement): bool => $requirement->isActive())
            ->countBy(fn (ComplianceRequirement $requirement): int => (int) $requirement->source_id);

        $sources = $this->access->visibleSources($user)
            ->whereIn('id', $perSource->keys()->all())
            ->orderBy('name')
            ->orderBy('version')
            ->get()
            ->map(fn (ComplianceSource $source): array => [
                'id' => (int) $source->id,
                'label' => $this->sourceLabel($source),
                'count' => (int) $perSource->get((int) $source->id, 0),
            ])
            ->all();

        return [
            'requirements' => $requirements->map(fn (ComplianceRequirement $requirement): array => $this->requirementRow($requirement))->all(),
            'sources' => $sources,
        ];
    }

    /**
     * Take one or more requirements into scope, all or none. Every id must be a requirement the user
     * can read — another customer's id or a missing one gets the same answer — and none may be in
     * scope already: a duplicate is refused, not silently skipped.
     *
     * @param  list<int>  $requirementIds
     */
    public function addRequirements(User $user, ComplianceAudit $audit, array $requirementIds): int
    {
        $this->authorizeRequirements($user, $audit);

        $ids = array_values(array_unique(array_map('intval', $requirementIds)));
        $found = $this->access->visibleRequirements($user)->whereKey($ids)->pluck('compliance_requirements.id')->map(fn ($id): int => (int) $id)->all();

        if ($ids === [] || count($found) !== count($ids)) {
            throw ValidationException::withMessages(['requirement_ids' => __('procynia.compliance.audits.validation.requirement_not_allowed')]);
        }

        if (ComplianceAuditRequirement::query()->where('audit_id', $audit->id)->whereIn('requirement_id', $ids)->exists()) {
            throw ValidationException::withMessages(['requirement_ids' => __('procynia.compliance.audits.validation.requirement_already_in_scope')]);
        }

        return $this->insertRequirements($user, $audit, $ids, 'requirement_ids', __('procynia.compliance.audits.validation.requirement_already_in_scope'));
    }

    /**
     * «Legg til krav fra kravkilde»: every active requirement of the source that is not in scope yet,
     * written as fixed rows now. The source itself is not stored.
     */
    public function addRequirementsFromSource(User $user, ComplianceAudit $audit, int $sourceId): int
    {
        $this->authorizeRequirements($user, $audit);

        $source = $this->access->findVisibleSource($user, $sourceId);

        if ($source === null) {
            throw ValidationException::withMessages(['source_id' => __('procynia.compliance.validation.source_not_allowed')]);
        }

        $ids = $this->access->visibleRequirements($user)
            ->where('compliance_requirements.source_id', $source->id)
            ->where('compliance_requirements.status', ComplianceRequirement::STATUS_ACTIVE)
            ->whereNotIn('compliance_requirements.id', ComplianceAuditRequirement::query()->where('audit_id', $audit->id)->select('requirement_id'))
            ->orderBy('compliance_requirements.id')
            ->pluck('compliance_requirements.id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($ids === []) {
            throw ValidationException::withMessages(['source_id' => __('procynia.compliance.audits.validation.source_nothing_to_add')]);
        }

        return $this->insertRequirements($user, $audit, $ids, 'source_id', __('procynia.compliance.audits.validation.source_nothing_to_add'));
    }

    /** Take a requirement out of scope. The requirement is left exactly as it was. False when it was not in scope. */
    public function removeRequirement(User $user, ComplianceAudit $audit, int $requirementId): bool
    {
        $this->authorizeRequirements($user, $audit);

        return ComplianceAuditRequirement::query()
            ->where('audit_id', $audit->id)
            ->where('customer_id', $audit->customer_id)
            ->where('requirement_id', $requirementId)
            ->delete() > 0;
    }

    /**
     * The processes in scope, read live from Kvalitet. Call only for a user who passes
     * canReadQuality().
     *
     * @return list<array{id: int, title: string, code: ?string, status: string, url: string}>
     */
    public function processes(ComplianceAudit $audit): array
    {
        return $this->quality->processesQuery((int) $audit->customer_id)
            ->whereIn('quality_items.id', ComplianceAuditProcess::query()
                ->where('audit_id', $audit->id)
                ->where('customer_id', $audit->customer_id)
                ->select('quality_process_id'))
            ->get()
            ->map(fn (QualityItem $process): array => [
                'id' => (int) $process->id,
                'title' => (string) $process->title,
                'code' => $process->code,
                'status' => $process->status,
                'url' => $this->quality->processUrl((int) $process->id),
            ])
            ->all();
    }

    /**
     * The customer's non-retired processes that are not in scope yet. Call only for a user who
     * passes canManageProcesses().
     *
     * @return list<array{id: int, title: string, code: ?string, status: string}>
     */
    public function processOptions(ComplianceAudit $audit): array
    {
        return $this->linkableProcesses((int) $audit->customer_id)
            ->whereNotIn('quality_items.id', ComplianceAuditProcess::query()->where('audit_id', $audit->id)->select('quality_process_id'))
            ->get()
            ->map(fn (QualityItem $process): array => [
                'id' => (int) $process->id,
                'title' => (string) $process->title,
                'code' => $process->code,
                'status' => $process->status,
            ])
            ->all();
    }

    /**
     * Take an existing process into scope. Anything that is not a non-retired process of the audit's
     * own customer — another customer's id, a control, a missing id — gets the same answer.
     */
    public function addProcess(User $user, ComplianceAudit $audit, int $processId): void
    {
        $this->authorizeProcesses($user, $audit);

        $process = $this->linkableProcesses((int) $audit->customer_id)->whereKey($processId)->first();

        if ($process === null) {
            throw ValidationException::withMessages(['quality_process_id' => __('procynia.compliance.audits.validation.process_not_allowed')]);
        }

        $message = __('procynia.compliance.audits.validation.process_already_in_scope');

        if (ComplianceAuditProcess::query()->where('audit_id', $audit->id)->where('quality_process_id', $process->id)->exists()) {
            throw ValidationException::withMessages(['quality_process_id' => $message]);
        }

        try {
            ComplianceAuditProcess::query()->create([
                'customer_id' => $audit->customer_id,
                'audit_id' => $audit->id,
                'quality_process_id' => $process->id,
                'created_by' => $user->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['quality_process_id' => $message]);
        }
    }

    /** Take a process out of scope. The process is left exactly as it was in Kvalitet. */
    public function removeProcess(User $user, ComplianceAudit $audit, int $processId): bool
    {
        $this->authorizeProcesses($user, $audit);

        return ComplianceAuditProcess::query()
            ->where('audit_id', $audit->id)
            ->where('customer_id', $audit->customer_id)
            ->where('quality_process_id', $processId)
            ->delete() > 0;
    }

    /**
     * Permission first, the same 403 whatever id was sent; then the audit's status.
     */
    private function authorizeRequirements(User $user, ComplianceAudit $audit): void
    {
        abort_unless($this->access->canAudit($user), 403);
        $this->assertScopeOpen($audit);
    }

    private function authorizeProcesses(User $user, ComplianceAudit $audit): void
    {
        abort_unless($this->access->canAudit($user) && $this->canReadQuality($user), 403);
        $this->assertScopeOpen($audit);
    }

    private function assertScopeOpen(ComplianceAudit $audit): void
    {
        if (! $audit->canChangeScope()) {
            throw ValidationException::withMessages(['audit' => __('procynia.compliance.audits.validation.scope_locked')]);
        }
    }

    /**
     * All rows in one transaction. Two requests at once both pass the check; the unique index refuses
     * the second, and it gets the same answer.
     *
     * @param  list<int>  $ids
     */
    private function insertRequirements(User $user, ComplianceAudit $audit, array $ids, string $field, string $message): int
    {
        try {
            DB::transaction(function () use ($user, $audit, $ids): void {
                foreach ($ids as $id) {
                    ComplianceAuditRequirement::query()->create([
                        'customer_id' => $audit->customer_id,
                        'audit_id' => $audit->id,
                        'requirement_id' => $id,
                        'created_by' => $user->id,
                    ]);
                }
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([$field => $message]);
        }

        return count($ids);
    }

    /** @return Builder<QualityItem> */
    private function linkableProcesses(int $customerId): Builder
    {
        return $this->quality->processesQuery($customerId)->where('quality_items.status', '!=', QualityItem::STATUS_RETIRED);
    }

    /** @return array<string, mixed> */
    private function requirementRow(ComplianceRequirement $requirement): array
    {
        return [
            'id' => (int) $requirement->id,
            'reference' => $requirement->reference,
            'title' => $requirement->title,
            'status' => $requirement->status,
            'source_id' => (int) $requirement->source_id,
            'source_label' => $requirement->source ? $this->sourceLabel($requirement->source) : null,
            'url' => route('app.compliance.requirements.show', ['requirementId' => $requirement->id]),
        ];
    }

    private function sourceLabel(ComplianceSource $source): string
    {
        return $source->version !== null && $source->version !== '' ? "{$source->name} ({$source->version})" : $source->name;
    }
}
