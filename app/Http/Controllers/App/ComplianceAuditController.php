<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\ComplianceAudit;
use App\Models\ComplianceAuditFinding;
use App\Models\ComplianceAuditStatusChange;
use App\Models\User;
use App\Services\Compliance\ComplianceAccessService;
use App\Services\Compliance\ComplianceAuditAttentionService;
use App\Services\Compliance\ComplianceAuditFindingHandoffService;
use App\Services\Compliance\ComplianceAuditFindingService;
use App\Services\Compliance\ComplianceAuditLifecycleService;
use App\Services\Compliance\ComplianceAuditScopeService;
use App\Services\EnterpriseWiki\Knowledge\WikiKnowledgeHandoffService;
use App\Services\Improvements\ImprovementCaseCreator;
use App\Support\Compliance\ComplianceValidationMessages;
use App\Support\CustomerContext;
use App\Support\Improvements\ImprovementValidationMessages;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Etterlevelse og revisjon → Revisjoner: the register, one audit, its scope and its lifecycle.
 *
 * Every read starts from ComplianceAccessService, which narrows to the user's own customer and to
 * nothing at all without compliance.view — before any id is looked at. An audit of another customer
 * is a 404 like an id that does not exist; a user without compliance.view gets a 403 on everything.
 *
 * compliance.audit — not compliance.edit — registers audits, changes them and their scope, and runs
 * their lifecycle (Start, Fullfør, Avbryt, Gjenåpne); compliance.delete deletes one registered by
 * mistake. Status changes only through the lifecycle actions, never through store() or update().
 *
 * The scope's requirements and Kvalitet processes are ComplianceAuditScopeService's: it decides what
 * may be shown about processes (Kvalitet read access, never implied by compliance.*) and what may be
 * linked.
 *
 * Funn are ComplianceAuditFindingService's: recorded, changed and deleted with compliance.audit
 * while the audit is in progress. «Følg opp i Avvik og forbedringer» is
 * ComplianceAuditFindingHandoffService's, and the only way a finding reaches an ImprovementCase —
 * nothing is created when an audit is completed. «Trenger oppmerksomhet» is
 * ComplianceAuditAttentionService's, computed on the audits the user can see and never stored.
 */
class ComplianceAuditController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly ComplianceAccessService $access,
        private readonly ComplianceAuditLifecycleService $lifecycle,
        private readonly ComplianceAuditScopeService $scope,
        private readonly ComplianceAuditFindingService $findings,
        private readonly ComplianceAuditFindingHandoffService $handoff,
        private readonly ComplianceAuditAttentionService $attention,
        private readonly WikiKnowledgeHandoffService $knowledgeHandoff,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->authorizedUser();

        $search = trim((string) $request->query('search', ''));
        $status = in_array($request->query('status'), ComplianceAudit::STATUSES, true) ? (string) $request->query('status') : '';
        $type = in_array($request->query('type'), ComplianceAudit::TYPES, true) ? (string) $request->query('type') : '';
        $attentionOnly = $request->boolean('attention');

        $query = $this->access->visibleAudits($user);

        if ($search !== '') {
            $needle = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($search)).'%';
            $query->where(function (Builder $inner) use ($needle): void {
                $inner->whereRaw('lower(compliance_audits.title) like ?', [$needle])
                    ->orWhereRaw('lower(compliance_audits.scope_description) like ?', [$needle])
                    ->orWhereRaw("lower(coalesce(compliance_audits.auditor_name, '')) like ?", [$needle]);
            });
        }

        if ($status !== '') {
            $query->where('compliance_audits.status', $status);
        }

        if ($type !== '') {
            $query->where('compliance_audits.audit_type', $type);
        }

        $audits = $query
            // Under arbeid first, then planned, then what is over.
            ->orderByRaw("CASE compliance_audits.status WHEN 'in_progress' THEN 0 WHEN 'planned' THEN 1 WHEN 'completed' THEN 2 ELSE 3 END")
            ->orderBy('compliance_audits.planned_end_date')
            ->orderBy('compliance_audits.title')
            ->orderBy('compliance_audits.id')
            ->with('responsible:id,name')
            ->get();

        // «Trenger oppmerksomhet» is the worklist of the whole register the user can see — every audit
        // that is not cancelled, whatever the search above — so the panel does not shrink with a search.
        $attentionSet = $this->access->visibleAudits($user)
            ->where('compliance_audits.status', '!=', ComplianceAudit::STATUS_CANCELLED)
            ->orderBy('compliance_audits.planned_end_date')
            ->orderBy('compliance_audits.title')
            ->orderBy('compliance_audits.id')
            ->get();
        $reasons = $this->attention->reasonsForAudits($audits->concat($attentionSet)->unique('id')->values());

        if ($attentionOnly) {
            $audits = $audits->filter(fn (ComplianceAudit $audit): bool => $reasons[(int) $audit->id] !== [])->values();
        }

        $flagged = $attentionSet->filter(fn (ComplianceAudit $audit): bool => $reasons[(int) $audit->id] !== []);

        $canAudit = $this->access->canAudit($user);

        return Inertia::render('App/Compliance/Audits/Index', [
            'audits' => $audits->map(fn (ComplianceAudit $audit): array => $this->row($audit) + [
                'attention' => $reasons[(int) $audit->id],
            ])->all(),
            'attention' => [
                'total' => $flagged->count(),
                'audits' => $flagged->map(fn (ComplianceAudit $audit): array => [
                    'id' => (int) $audit->id,
                    'title' => $audit->title,
                    'url' => route('app.compliance.audits.show', ['auditId' => $audit->id]),
                    'reasons' => $reasons[(int) $audit->id],
                ])->values()->all(),
            ],
            'attention_reasons' => ComplianceAuditAttentionService::REASONS,
            // Only what the user can see — which in v1 is the customer's whole register, or nothing.
            'visible_count' => $this->access->visibleAudits($user)->count(),
            'filters' => [
                'search' => $search,
                'status' => $status,
                'type' => $type,
                'attention' => $attentionOnly,
            ],
            'statuses' => ComplianceAudit::STATUSES,
            'types' => ComplianceAudit::TYPES,
            'permissions' => [
                'can_audit' => $canAudit,
            ],
            'responsible_options' => $canAudit ? $this->access->ownerCandidates($user) : [],
        ]);
    }

    public function show(int $auditId): Response
    {
        $user = $this->authorizedUser();
        $audit = $this->visibleAuditOrFail($user, $auditId);
        $audit->loadMissing('responsible:id,name');

        $canAudit = $this->access->canAudit($user);
        $canReadQuality = $this->scope->canReadQuality($user);
        $canManageRequirements = $this->scope->canManageRequirements($user, $audit);
        $canManageProcesses = $this->scope->canManageProcesses($user, $audit);
        $canRecordFindings = $this->findings->canRecord($user, $audit);
        $canHandOff = $this->handoff->canHandOff($user, $audit);
        $findings = $this->findings->rows($user, $audit, $canHandOff);
        $awaitingHandoff = $canHandOff && collect($findings)->contains(fn (array $finding): bool => ! $finding['handed_off']);

        return Inertia::render('App/Compliance/Audits/Show', [
            // «Lag kunnskapsartikkel»: the shared Wiki handoff (WikiKnowledgeHandoffService).
            'knowledge_handoff' => $this->knowledgeHandoff->panel($user, 'compliance_audit', $audit),
            'audit' => $this->row($audit) + [
                'scope_description' => $audit->scope_description,
                'conclusion' => $audit->conclusion,
                'created_at' => $audit->created_at?->toIso8601String(),
            ],
            'attention' => $this->attention->reasonsForAudit($audit),
            'requirements' => $this->scope->requirements($audit),
            'requirement_options' => $canManageRequirements ? $this->scope->requirementOptions($user, $audit) : null,
            // Prosesser i scope: null — not empty — without Kvalitet read access, so nothing about
            // them reaches the page, not even whether there are any.
            'processes' => $canReadQuality ? $this->scope->processes($audit) : null,
            'process_options' => $canManageProcesses ? $this->scope->processOptions($audit) : null,
            'findings' => $findings,
            'finding_types' => ComplianceAuditFinding::TYPES,
            'finding_options' => $canRecordFindings ? $this->findings->options($user, $audit) : null,
            // What «Følg opp i Avvik og forbedringer» needs from that module — only while there is a
            // finding left to hand off. The areas are those the person may register cases in.
            'handoff' => $awaitingHandoff ? $this->handoff->formOptions($user) + ['today' => now()->toDateString()] : null,
            // Reached through visibleAudits(); the history carries no access of its own.
            'status_history' => $audit->statusChanges()->with('changedBy:id,name')->get()
                ->map(fn (ComplianceAuditStatusChange $change): array => [
                    'id' => (int) $change->id,
                    'from_status' => $change->from_status,
                    'to_status' => $change->to_status,
                    'reason' => $change->reason,
                    'changed_at' => $change->changed_at?->toIso8601String(),
                    'changed_by_name' => $change->changedBy?->name,
                ])
                ->all(),
            'permissions' => [
                'can_edit' => $canAudit && $audit->isEditable(),
                'can_start' => $canAudit && $audit->status === ComplianceAudit::STATUS_PLANNED,
                'can_complete' => $canAudit && $audit->status === ComplianceAudit::STATUS_IN_PROGRESS,
                'can_cancel' => $canAudit && in_array($audit->status, [ComplianceAudit::STATUS_PLANNED, ComplianceAudit::STATUS_IN_PROGRESS], true),
                'can_reopen' => $canAudit && $audit->status === ComplianceAudit::STATUS_COMPLETED,
                'can_delete' => $this->access->canDelete($user) && $audit->isDeletable(),
                'can_manage_requirements' => $canManageRequirements,
                // compliance.audit and Kvalitet read access, on a planned or running audit.
                'can_manage_processes' => $canManageProcesses,
                'can_record_findings' => $canRecordFindings,
            ],
            'editable_fields' => $canAudit ? $audit->editableFields() : [],
            'types' => ComplianceAudit::TYPES,
            'responsible_options' => $canAudit && $audit->isEditable() ? $this->access->ownerCandidates($user) : [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->authorizedUser();
        abort_unless($this->access->canAudit($user), 403);

        $fields = $this->validatedFields($request, $user, (new ComplianceAudit)->editableFields());

        $audit = ComplianceAudit::query()->create($fields + [
            'customer_id' => (int) $user->customer_id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        return redirect()
            ->route('app.compliance.audits.show', ['auditId' => $audit->id])
            ->with('success', __('procynia.compliance.audits.flash.created'));
    }

    /**
     * Rediger: only the fields the audit's status leaves open (ComplianceAudit::editableFields()).
     * Anything else in the request is not read. A completed or cancelled audit refuses it.
     */
    public function update(Request $request, int $auditId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $audit = $this->visibleAuditOrFail($user, $auditId);
        abort_unless($this->access->canAudit($user), 403);

        if (! $audit->isEditable()) {
            return back()->with('error', __($audit->status === ComplianceAudit::STATUS_COMPLETED
                ? 'procynia.compliance.audits.validation.reopen_before_edit'
                : 'procynia.compliance.audits.validation.cancelled_read_only'));
        }

        $fields = $this->validatedFields($request, $user, $audit->editableFields(), $audit);

        $audit->fill($fields + ['updated_by' => $user->id])->save();

        return back()->with('success', __('procynia.compliance.audits.flash.updated'));
    }

    /** Start revisjon: planned → in_progress. */
    public function start(Request $request, int $auditId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $audit = $this->visibleAuditOrFail($user, $auditId);
        abort_unless($this->access->canAudit($user), 403);

        $this->lifecycle->start($audit, $user, $this->optionalReason($request));

        return back()->with('success', __('procynia.compliance.audits.flash.started'));
    }

    /**
     * Fullfør revisjon: in_progress → completed, with the conclusion written in the same step. What
     * the form sends is the conclusion — an emptied field is no conclusion, even if one was saved
     * before. Only a request without the field falls back on what Rediger saved. With neither, the
     * audit is not completed.
     */
    public function complete(Request $request, int $auditId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $audit = $this->visibleAuditOrFail($user, $auditId);
        abort_unless($this->access->canAudit($user), 403);

        $validated = $request->validate([
            'conclusion' => ['nullable', 'string', 'max:20000'],
        ], ComplianceValidationMessages::messages(), ComplianceValidationMessages::attributes());

        $conclusion = $request->exists('conclusion') ? ($validated['conclusion'] ?? null) : $audit->conclusion;

        $this->lifecycle->complete($audit, $user, $conclusion);

        return back()->with('success', __('procynia.compliance.audits.flash.completed'));
    }

    /** Avbryt revisjon: from planned or in_progress. Always says why; the audit stays. */
    public function cancel(Request $request, int $auditId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $audit = $this->visibleAuditOrFail($user, $auditId);
        abort_unless($this->access->canAudit($user), 403);

        $this->lifecycle->cancel($audit, $user, $this->requiredReason($request));

        return back()->with('success', __('procynia.compliance.audits.flash.cancelled'));
    }

    /** Gjenåpne revisjon: completed → in_progress. Always says why; the completion stays in the history. */
    public function reopen(Request $request, int $auditId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $audit = $this->visibleAuditOrFail($user, $auditId);
        abort_unless($this->access->canAudit($user), 403);

        $this->lifecycle->reopen($audit, $user, $this->requiredReason($request));

        return back()->with('success', __('procynia.compliance.audits.flash.reopened'));
    }

    /** Legg til krav: one or more requirements into scope, all or none. */
    public function addRequirements(Request $request, int $auditId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $audit = $this->visibleAuditOrFail($user, $auditId);

        $validated = $request->validate([
            'requirement_ids' => ['required', 'array', 'min:1', 'max:500'],
            'requirement_ids.*' => ['integer'],
        ], ComplianceValidationMessages::messages(), ComplianceValidationMessages::attributes());

        $added = $this->scope->addRequirements($user, $audit, array_map('intval', $validated['requirement_ids']));

        return back()->with('success', trans_choice('procynia.compliance.audits.flash.requirements_added', $added, ['count' => $added]));
    }

    /** Legg til krav fra kravkilde: a shortcut that writes the source's active requirements as fixed rows. */
    public function addRequirementsFromSource(Request $request, int $auditId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $audit = $this->visibleAuditOrFail($user, $auditId);

        $sourceId = (int) $request->validate(['source_id' => ['required', 'integer']], ComplianceValidationMessages::messages(), ComplianceValidationMessages::attributes())['source_id'];

        $added = $this->scope->addRequirementsFromSource($user, $audit, $sourceId);

        return back()->with('success', trans_choice('procynia.compliance.audits.flash.requirements_added', $added, ['count' => $added]));
    }

    /** Fjern fra scope. The requirement stays as it is. */
    public function removeRequirement(int $auditId, int $requirementId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $audit = $this->visibleAuditOrFail($user, $auditId);

        abort_unless($this->scope->removeRequirement($user, $audit, $requirementId), 404);

        return back()->with('success', __('procynia.compliance.audits.flash.requirement_removed'));
    }

    /** Legg til prosess: an existing Kvalitet process into scope. */
    public function addProcess(Request $request, int $auditId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $audit = $this->visibleAuditOrFail($user, $auditId);
        $processId = (int) $request->validate(['quality_process_id' => ['required', 'integer']], ComplianceValidationMessages::messages(), ComplianceValidationMessages::attributes())['quality_process_id'];

        $this->scope->addProcess($user, $audit, $processId);

        return back()->with('success', __('procynia.compliance.audits.flash.process_added'));
    }

    /** Fjern fra scope. The process stays as it is in Kvalitet. */
    public function removeProcess(int $auditId, int $processId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $audit = $this->visibleAuditOrFail($user, $auditId);

        abort_unless($this->scope->removeProcess($user, $audit, $processId), 404);

        return back()->with('success', __('procynia.compliance.audits.flash.process_removed'));
    }

    /** Nytt funn: compliance.audit, audit in progress. */
    public function storeFinding(Request $request, int $auditId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $audit = $this->visibleAuditOrFail($user, $auditId);
        abort_unless($this->access->canAudit($user), 403);

        $this->findings->create($user, $audit, $this->validatedFinding($request));

        return back()->with('success', __('procynia.compliance.audits.findings.flash.created'));
    }

    /** Rediger funn: while the audit is in progress and the finding has not been handed off. */
    public function updateFinding(Request $request, int $auditId, int $findingId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $audit = $this->visibleAuditOrFail($user, $auditId);
        abort_unless($this->access->canAudit($user), 403);
        $finding = $this->findings->find($audit, $findingId) ?? abort(404);

        $this->findings->update($user, $audit, $finding, $this->validatedFinding($request));

        return back()->with('success', __('procynia.compliance.audits.findings.flash.updated'));
    }

    /** Slett funn: the same window as Rediger. A handed-off finding is never deleted. */
    public function destroyFinding(int $auditId, int $findingId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $audit = $this->visibleAuditOrFail($user, $auditId);
        abort_unless($this->access->canAudit($user), 403);
        $finding = $this->findings->find($audit, $findingId) ?? abort(404);

        $this->findings->delete($user, $audit, $finding);

        return back()->with('success', __('procynia.compliance.audits.findings.flash.deleted'));
    }

    /**
     * Følg opp i Avvik og forbedringer. The case's fields follow ImprovementCaseCreator::rules(),
     * except the type — set by the finding — and Hendelsesdato, which a finding does not have.
     */
    public function handOffFinding(Request $request, int $auditId, int $findingId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $audit = $this->visibleAuditOrFail($user, $auditId);
        abort_unless($this->access->canAudit($user), 403);
        $finding = $this->findings->find($audit, $findingId) ?? abort(404);

        $rules = array_intersect_key(ImprovementCaseCreator::rules(), array_flip(['title', 'description', 'business_area_id', 'owner_user_id', 'due_date']));
        $validated = $request->validate($rules + [
            'link_process' => ['boolean'],
        ], ImprovementValidationMessages::messages(), ImprovementValidationMessages::attributes());

        $this->handoff->handOff($user, $audit, $finding, $validated);

        return back()->with('success', __('procynia.compliance.audits.findings.flash.handed_off'));
    }

    public function destroy(int $auditId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $audit = $this->visibleAuditOrFail($user, $auditId);
        abort_unless($this->access->canDelete($user), 403);

        if (! $audit->isDeletable()) {
            return back()->with('error', __('procynia.compliance.audits.validation.not_deletable'));
        }

        $audit->delete();

        return redirect()->route('app.compliance.audits.index')->with('success', __('procynia.compliance.audits.flash.deleted'));
    }

    private function authorizedUser(): User
    {
        $user = $this->customerContext->currentUser();

        abort_unless($this->access->canOpenModule($user), 403);

        return $user;
    }

    private function visibleAuditOrFail(User $user, int $auditId): ComplianceAudit
    {
        return $this->access->findVisibleAudit($user, $auditId) ?? abort(404);
    }

    /**
     * The given fields, checked against what the user can reach. Status is never among them; nor is
     * a field the audit's status does not leave open — it is not read at all.
     *
     * @param  list<string>  $editable
     * @return array<string, mixed>
     */
    private function validatedFields(Request $request, User $user, array $editable, ?ComplianceAudit $current = null): array
    {
        $rules = array_intersect_key([
            'title' => ['required', 'string', 'max:255'],
            'audit_type' => ['required', 'string', Rule::in(ComplianceAudit::TYPES)],
            'responsible_user_id' => ['required', 'integer'],
            'auditor_name' => ['nullable', 'string', 'max:255'],
            'planned_start_date' => ['nullable', 'date_format:Y-m-d'],
            'planned_end_date' => ['required', 'date_format:Y-m-d'],
            'scope_description' => ['required', 'string', 'max:20000'],
            'conclusion' => ['nullable', 'string', 'max:20000'],
        ], array_flip($editable));

        $validated = $request->validate($rules, ComplianceValidationMessages::messages(), ComplianceValidationMessages::attributes());

        $owner = User::query()->where('customer_id', (int) $user->customer_id)->find((int) $validated['responsible_user_id']);

        if (! $this->access->isValidOwner($owner, (int) $user->customer_id)) {
            throw ValidationException::withMessages(['responsible_user_id' => __('procynia.compliance.audits.validation.responsible_not_allowed')]);
        }

        $start = $validated['planned_start_date'] ?? null;
        $end = $validated['planned_end_date'];

        if ($start !== null && Carbon::parse($end)->lt(Carbon::parse($start))) {
            throw ValidationException::withMessages(['planned_end_date' => __('procynia.compliance.audits.validation.end_before_start')]);
        }

        $auditor = trim((string) ($validated['auditor_name'] ?? ''));

        $fields = [
            'title' => trim($validated['title']),
            'responsible_user_id' => (int) $owner->id,
            'auditor_name' => $auditor !== '' ? $auditor : null,
            'planned_start_date' => $start,
            'planned_end_date' => $end,
            'scope_description' => trim($validated['scope_description']),
        ];

        if (array_key_exists('audit_type', $rules)) {
            $fields['audit_type'] = $validated['audit_type'];
        }

        if (array_key_exists('conclusion', $rules)) {
            $conclusion = trim((string) ($validated['conclusion'] ?? ''));
            $fields['conclusion'] = $conclusion !== '' ? $conclusion : null;
        }

        return $fields;
    }

    /** @return array<string, mixed> */
    private function validatedFinding(Request $request): array
    {
        return $request->validate([
            'finding_type' => ['required', 'string', Rule::in(ComplianceAuditFinding::TYPES)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:10000'],
            'requirement_id' => ['nullable', 'integer'],
            'quality_process_id' => ['nullable', 'integer'],
            'control_item_id' => ['nullable', 'integer'],
        ], ComplianceValidationMessages::messages(), ComplianceValidationMessages::attributes());
    }

    private function requiredReason(Request $request): string
    {
        return $request->validate([
            'reason' => ['required', 'string', 'max:5000'],
        ], ComplianceValidationMessages::messages(), ComplianceValidationMessages::attributes())['reason'];
    }

    private function optionalReason(Request $request): ?string
    {
        return $request->validate([
            'reason' => ['nullable', 'string', 'max:5000'],
        ], ComplianceValidationMessages::messages(), ComplianceValidationMessages::attributes())['reason'] ?? null;
    }

    /** @return array<string, mixed> */
    private function row(ComplianceAudit $audit): array
    {
        return [
            'id' => (int) $audit->id,
            'title' => $audit->title,
            'audit_type' => $audit->audit_type,
            'status' => $audit->status,
            'responsible_user_id' => $audit->responsible_user_id !== null ? (int) $audit->responsible_user_id : null,
            'responsible_name' => $audit->responsible?->name,
            'auditor_name' => $audit->auditor_name,
            'planned_start_date' => $audit->planned_start_date?->toDateString(),
            'planned_end_date' => $audit->planned_end_date?->toDateString(),
            'url' => route('app.compliance.audits.show', ['auditId' => $audit->id]),
        ];
    }
}
