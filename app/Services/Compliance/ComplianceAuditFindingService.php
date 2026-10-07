<?php

namespace App\Services\Compliance;

use App\Models\ComplianceAudit;
use App\Models\ComplianceAuditFinding;
use App\Models\ComplianceAuditProcess;
use App\Models\ComplianceAuditRequirement;
use App\Models\ComplianceRequirement;
use App\Models\ComplianceSource;
use App\Models\ImprovementCase;
use App\Models\QualityItem;
use App\Models\User;
use App\Services\Improvements\ImprovementCaseAccessService;
use App\Services\Quality\QualityProcessContextReader;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Revisjonsfunn on one audit: recording, changing and deleting them, and what the audit page may
 * show about them.
 *
 * LIFECYCLE. A finding is recorded, changed and deleted while the audit is in progress, and only
 * then: a planned audit has not found anything yet, a completed one is locked until it is reopened,
 * a cancelled one is read-only for good. A finding handed off to Avvik og forbedringer is frozen for
 * good, even after the audit is reopened (the database refuses it too). Each write locks the audit
 * row and checks its status again inside the lock, so it cannot slip past a lifecycle step running
 * at the same moment.
 *
 * ACCESS. The audit is reached through ComplianceAccessService; writing findings takes
 * compliance.audit. Context:
 *
 *  - Requirement: any requirement the user can read, a retired one too; those in the audit's scope
 *    are offered first. Optional.
 *  - Kvalitet process and control: only with Kvalitet read access (QualityProcessContextReader),
 *    which compliance.* never implies. Without it the page carries nothing about a finding's
 *    Kvalitet context — no name, no id, not whether there is any — and a change to the finding
 *    leaves that context exactly as it was. Only a non-retired process or control can be chosen
 *    anew; one already on the finding may stay after it is retired. A control must be a `control`.
 *
 * Hand-off and everything about the ImprovementCase is ComplianceAuditFindingHandoffService's; the
 * page learns only whether a finding was handed off, and a link only when the case is readable.
 */
class ComplianceAuditFindingService
{
    public function __construct(
        private readonly ComplianceAccessService $access,
        private readonly QualityProcessContextReader $quality,
        private readonly ImprovementCaseAccessService $improvements,
    ) {}

    public function canReadQuality(User $user): bool
    {
        return $this->quality->canRead($user);
    }

    /** Nytt funn, Rediger and Slett: compliance.audit on an audit in progress. */
    public function canRecord(User $user, ComplianceAudit $audit): bool
    {
        return $audit->canRecordFindings() && $this->access->canAudit($user);
    }

    /**
     * The audit's findings as the page shows them, in the order they were recorded.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(User $user, ComplianceAudit $audit, bool $canHandOff): array
    {
        $findings = ComplianceAuditFinding::query()
            ->where('audit_id', $audit->id)
            ->where('customer_id', $audit->customer_id)
            ->with(['requirement.source:id,name,version', 'handedOffBy:id,name'])
            ->orderBy('id')
            ->get();

        $canRecord = $this->canRecord($user, $audit);
        $canReadQuality = $this->canReadQuality($user);
        $customerId = (int) $audit->customer_id;
        $quality = $canReadQuality ? $this->qualityItems($customerId, $findings) : collect();
        $cases = $this->readableCases($user, $findings);

        return $findings->map(function (ComplianceAuditFinding $finding) use ($canRecord, $canReadQuality, $canHandOff, $quality, $cases): array {
            $handedOff = $finding->isHandedOff();
            $case = $handedOff ? $cases->get((int) $finding->improvement_case_id) : null;

            $row = [
                'id' => (int) $finding->id,
                'finding_type' => $finding->finding_type,
                'title' => $finding->title,
                'description' => $finding->description,
                'requirement' => $finding->requirement !== null ? $this->requirementRow($finding->requirement) : null,
                'improvement_type' => $finding->improvementCaseType(),
                'handed_off' => $handedOff,
                'handed_off_at' => $finding->handed_off_at?->toIso8601String(),
                'handed_off_by_name' => $finding->handedOffBy?->name,
                // Only a case the person can read today gets a link and a title — never its status.
                'case_link' => $case !== null ? ['url' => route('app.improvements.show', ['caseId' => $case->id]), 'title' => $case->title] : null,
                'permissions' => [
                    'can_edit' => $canRecord && ! $handedOff,
                    'can_delete' => $canRecord && ! $handedOff,
                    'can_hand_off' => $canHandOff && ! $handedOff,
                ],
            ];

            // Kvalitet context only for someone who can read Kvalitet: without it the keys are absent.
            if ($canReadQuality) {
                $row['quality_process'] = $finding->quality_process_id !== null ? $quality->get((int) $finding->quality_process_id) : null;
                $row['control'] = $finding->control_item_id !== null ? $quality->get((int) $finding->control_item_id) : null;
            }

            return $row;
        })->all();
    }

    /**
     * What the finding form offers: every requirement the user can read — those in the audit's scope
     * first — and, with Kvalitet read access, the non-retired processes (scope first) and controls.
     * Call only for a user who passes canRecord().
     *
     * @return array{requirements: list<array<string, mixed>>, processes: ?list<array<string, mixed>>, controls: ?list<array<string, mixed>>}
     */
    public function options(User $user, ComplianceAudit $audit): array
    {
        $customerId = (int) $audit->customer_id;
        $scopeRequirementIds = ComplianceAuditRequirement::query()->where('audit_id', $audit->id)->pluck('requirement_id')->map(fn ($id): int => (int) $id)->all();

        $requirements = $this->access->visibleRequirements($user)
            ->with('source:id,name,version')
            ->orderBy(ComplianceSource::query()->select('name')->whereColumn('compliance_sources.id', 'compliance_requirements.source_id'))
            ->orderBy('compliance_requirements.source_id')
            ->orderByRaw('lower(compliance_requirements.reference) ASC NULLS LAST')
            ->orderBy('compliance_requirements.title')
            ->orderBy('compliance_requirements.id')
            ->get()
            ->map(fn (ComplianceRequirement $requirement): array => $this->requirementRow($requirement) + [
                'in_scope' => in_array((int) $requirement->id, $scopeRequirementIds, true),
            ])
            ->sortBy(fn (array $row): int => $row['in_scope'] ? 0 : 1)
            ->values()
            ->all();

        if (! $this->canReadQuality($user)) {
            return ['requirements' => $requirements, 'processes' => null, 'controls' => null];
        }

        $scopeProcessIds = ComplianceAuditProcess::query()->where('audit_id', $audit->id)->pluck('quality_process_id')->map(fn ($id): int => (int) $id)->all();

        return [
            'requirements' => $requirements,
            'processes' => $this->linkable($this->quality->processesQuery($customerId))->get()
                ->map(fn (QualityItem $process): array => $this->qualityRow($process) + ['in_scope' => in_array((int) $process->id, $scopeProcessIds, true)])
                ->sortBy(fn (array $row): int => $row['in_scope'] ? 0 : 1)
                ->values()
                ->all(),
            'controls' => $this->linkable($this->quality->controlsQuery($customerId))->get()
                ->map(fn (QualityItem $control): array => $this->qualityRow($control))
                ->all(),
        ];
    }

    /** @param  array<string, mixed>  $input  validated by the controller */
    public function create(User $user, ComplianceAudit $audit, array $input): ComplianceAuditFinding
    {
        abort_unless($this->access->canAudit($user), 403);

        $fields = $this->fields($user, $audit, $input, null);

        return DB::transaction(function () use ($user, $audit, $fields): ComplianceAuditFinding {
            $this->lockOpenAudit($audit);

            return ComplianceAuditFinding::query()->create($fields + [
                'customer_id' => (int) $audit->customer_id,
                'audit_id' => (int) $audit->id,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);
        });
    }

    /** @param  array<string, mixed>  $input  validated by the controller */
    public function update(User $user, ComplianceAudit $audit, ComplianceAuditFinding $finding, array $input): void
    {
        abort_unless($this->access->canAudit($user), 403);

        $fields = $this->fields($user, $audit, $input, $finding);

        DB::transaction(function () use ($user, $audit, $finding, $fields): void {
            $this->lockOpenAudit($audit);
            $locked = $this->lockOpenFinding($finding);

            $locked->fill($fields + ['updated_by' => $user->id])->save();
        });
    }

    public function delete(User $user, ComplianceAudit $audit, ComplianceAuditFinding $finding): void
    {
        abort_unless($this->access->canAudit($user), 403);

        DB::transaction(function () use ($audit, $finding): void {
            $this->lockOpenAudit($audit);
            $this->lockOpenFinding($finding)->delete();
        });
    }

    /** A finding of this audit, or null — the same answer for another audit's or customer's id. */
    public function find(ComplianceAudit $audit, int $findingId): ?ComplianceAuditFinding
    {
        return ComplianceAuditFinding::query()
            ->where('audit_id', $audit->id)
            ->where('customer_id', $audit->customer_id)
            ->whereKey($findingId)
            ->first();
    }

    /**
     * The validated input as columns, each reference checked against what the user may reach. A
     * Kvalitet reference is read only from someone who can read Kvalitet; for anyone else the
     * finding keeps the context it has, untouched and unseen.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function fields(User $user, ComplianceAudit $audit, array $input, ?ComplianceAuditFinding $current): array
    {
        $fields = [
            'finding_type' => $input['finding_type'],
            'title' => trim($input['title']),
            'description' => trim($input['description']),
            'requirement_id' => $this->requirementId($user, $input['requirement_id'] ?? null),
        ];

        if ($this->canReadQuality($user)) {
            $customerId = (int) $audit->customer_id;
            $fields['quality_process_id'] = $this->qualityId($this->quality->processesQuery($customerId), $input['quality_process_id'] ?? null, $current?->quality_process_id, 'quality_process_id', 'procynia.compliance.audits.findings.validation.process_not_allowed');
            $fields['control_item_id'] = $this->qualityId($this->quality->controlsQuery($customerId), $input['control_item_id'] ?? null, $current?->control_item_id, 'control_item_id', 'procynia.compliance.audits.findings.validation.control_not_allowed');
        }

        return $fields;
    }

    private function requirementId(User $user, mixed $id): ?int
    {
        if ($id === null || $id === '') {
            return null;
        }

        $requirement = $this->access->findVisibleRequirement($user, (int) $id);

        if ($requirement === null) {
            throw ValidationException::withMessages(['requirement_id' => __('procynia.compliance.audits.findings.validation.requirement_not_allowed')]);
        }

        return (int) $requirement->id;
    }

    /**
     * A process or control of the audit's customer: unchanged, it may stay even if retired since;
     * chosen anew, it must not be retired. Anything else — another customer's id, the wrong kind,
     * a missing id — gets the same answer.
     *
     * @param  Builder<QualityItem>  $query
     */
    private function qualityId(Builder $query, mixed $id, mixed $currentId, string $field, string $message): ?int
    {
        if ($id === null || $id === '') {
            return null;
        }

        $id = (int) $id;

        if ($currentId === null || (int) $currentId !== $id) {
            $query = $this->linkable($query);
        }

        if (! $query->whereKey($id)->exists()) {
            throw ValidationException::withMessages([$field => __($message)]);
        }

        return $id;
    }

    /** The audit row, locked against a lifecycle step, still in progress. */
    private function lockOpenAudit(ComplianceAudit $audit): void
    {
        $locked = ComplianceAudit::query()->whereKey($audit->id)->lockForUpdate()->firstOrFail();

        if (! $locked->canRecordFindings()) {
            throw ValidationException::withMessages(['audit' => __('procynia.compliance.audits.findings.validation.audit_not_in_progress')]);
        }
    }

    /** The finding row, locked, still not handed off. */
    private function lockOpenFinding(ComplianceAuditFinding $finding): ComplianceAuditFinding
    {
        $locked = ComplianceAuditFinding::query()->whereKey($finding->id)->lockForUpdate()->firstOrFail();

        if ($locked->isHandedOff()) {
            throw ValidationException::withMessages(['finding' => __('procynia.compliance.audits.findings.validation.handed_off_locked')]);
        }

        return $locked;
    }

    /**
     * The processes and controls the findings name, read live from Kvalitet, by id.
     *
     * @param  Collection<int, ComplianceAuditFinding>  $findings
     * @return Collection<int, array<string, mixed>>
     */
    private function qualityItems(int $customerId, Collection $findings): Collection
    {
        $ids = $findings->flatMap(fn (ComplianceAuditFinding $finding): array => [$finding->quality_process_id, $finding->control_item_id])
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return collect();
        }

        return QualityItem::query()
            ->where('customer_id', $customerId)
            ->whereIn('quality_type', [QualityItem::TYPE_PROCESS, QualityItem::TYPE_CONTROL])
            ->whereKey($ids)
            ->get()
            ->mapWithKeys(fn (QualityItem $item): array => [(int) $item->id => $this->qualityRow($item) + [
                'url' => route('app.quality.items.show', ['item' => $item->id]),
            ]]);
    }

    /**
     * The cases behind handed-off findings that the user can read today, by id. A case in a
     * fagområde the user cannot reach is simply not here.
     *
     * @param  Collection<int, ComplianceAuditFinding>  $findings
     * @return Collection<int, ImprovementCase>
     */
    private function readableCases(User $user, Collection $findings): Collection
    {
        $ids = $findings->pluck('improvement_case_id')->filter()->map(fn ($id): int => (int) $id)->values()->all();

        if ($ids === [] || ! $this->improvements->canOpenModule($user)) {
            return collect();
        }

        return $this->improvements->visibleCases($user)
            ->whereIn('improvement_cases.id', $ids)
            ->get(['improvement_cases.id', 'improvement_cases.title'])
            ->keyBy(fn (ImprovementCase $case): int => (int) $case->id);
    }

    /**
     * @param  Builder<QualityItem>  $query
     * @return Builder<QualityItem>
     */
    private function linkable(Builder $query): Builder
    {
        return $query->where('quality_items.status', '!=', QualityItem::STATUS_RETIRED);
    }

    /** @return array{id: int, title: string, code: ?string, status: string} */
    private function qualityRow(QualityItem $item): array
    {
        return [
            'id' => (int) $item->id,
            'title' => (string) $item->title,
            'code' => $item->code,
            'status' => $item->status,
        ];
    }

    /** @return array<string, mixed> */
    private function requirementRow(ComplianceRequirement $requirement): array
    {
        $source = $requirement->source;

        return [
            'id' => (int) $requirement->id,
            'reference' => $requirement->reference,
            'title' => $requirement->title,
            'status' => $requirement->status,
            'source_label' => $source !== null ? ($source->version !== null && $source->version !== '' ? "{$source->name} ({$source->version})" : $source->name) : null,
            'url' => route('app.compliance.requirements.show', ['requirementId' => $requirement->id]),
        ];
    }
}
