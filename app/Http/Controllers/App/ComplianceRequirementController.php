<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\ComplianceAssessment;
use App\Models\ComplianceRequirement;
use App\Models\ComplianceRequirementStatusChange;
use App\Models\ComplianceSource;
use App\Models\User;
use App\Services\Compliance\ComplianceAccessService;
use App\Services\Compliance\ComplianceAssessmentService;
use App\Services\Compliance\ComplianceAttentionService;
use App\Services\Compliance\ComplianceQualityContextService;
use App\Services\Compliance\ComplianceRequirementLifecycleService;
use App\Services\Compliance\ComplianceStatusResolver;
use App\Services\Compliance\Library\ComplianceApplicabilityService;
use App\Services\Compliance\Library\ComplianceFrameworkPresenter;
use App\Support\Compliance\ComplianceValidationMessages;
use App\Support\CustomerContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Etterlevelse og revisjon → Krav: the register, one requirement, and its lifecycle.
 *
 * Every read starts from ComplianceAccessService, which narrows to the user's own customer and to
 * nothing at all without compliance.view — before any id is looked at. A requirement of another
 * customer is a 404 like an id that does not exist; a user without compliance.view gets a 403 on
 * everything.
 *
 * compliance.edit registers and changes requirements and retires and reopens them;
 * compliance.delete deletes one registered by mistake. Status changes only through retire() and
 * reopen(), never through store() or update(). compliance.assess — not compliance.edit — registers
 * an etterlevelsesvurdering through assess().
 *
 * Hvordan kravet oppfylles — the Kvalitet processes and controls a requirement is met through — is
 * ComplianceQualityContextService's: it decides what may be shown (Kvalitet read access, never
 * implied by compliance.*) and what may be linked.
 *
 * The reasons a requirement needs attention are ComplianceAttentionService's, computed on the rows
 * reached here and on nothing else. Whether requirements are met, across the register, is
 * Etterlevelsesvurdering's (ComplianceAssessmentController).
 *
 * A requirement from Kravbiblioteket (framework_node_id) is the same requirement with three
 * differences: its reference, title and text belong to the library and are not form fields; its
 * relevance (Gjelder / Ikke relevant / Ikke vurdert) is set through setApplicability() with
 * compliance.edit; and it is assessed only while it applies, never as Ikke relevant. No requirement
 * is registered by hand under an activated framework's source.
 */
class ComplianceRequirementController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly ComplianceAccessService $access,
        private readonly ComplianceRequirementLifecycleService $lifecycle,
        private readonly ComplianceStatusResolver $status,
        private readonly ComplianceAssessmentService $assessments,
        private readonly ComplianceQualityContextService $qualityContext,
        private readonly ComplianceAttentionService $attention,
        private readonly ComplianceApplicabilityService $applicability,
        private readonly ComplianceFrameworkPresenter $library,
    ) {}

    /**
     * /app/compliance, the rail's one entry: Etterlevelsesvurdering, where the ongoing work is — or,
     * while the register is still empty, Kravbibliotek, where it starts.
     */
    public function home(): RedirectResponse
    {
        $user = $this->authorizedUser();

        return $this->access->visibleRequirements($user)->exists()
            ? redirect()->route('app.compliance.assessments.index')
            : redirect()->route('app.compliance.library.index');
    }

    /**
     * Kravregister: which requirements apply to the virksomhet — origin, relevance or Kontrollvalg,
     * and status. Whether they are met is Etterlevelsesvurdering's (ComplianceAssessmentController),
     * so the register carries no results and no worklist of its own.
     */
    public function index(Request $request): Response
    {
        $user = $this->authorizedUser();

        $sources = $this->access->visibleSources($user)
            ->withCount('requirements')
            ->orderBy('name')
            ->orderBy('version')
            ->orderBy('id')
            ->get();
        $sourceIds = $sources->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        $search = trim((string) $request->query('search', ''));
        $status = in_array($request->query('status'), ComplianceRequirement::STATUSES, true) ? (string) $request->query('status') : '';
        $applicability = in_array($request->query('applicability'), ComplianceRequirement::APPLICABILITIES, true) ? (string) $request->query('applicability') : '';
        // Only a source the user can read is a filter; anything else is ignored rather than answered.
        $sourceId = (int) $request->query('source', 0);
        $sourceId = in_array($sourceId, $sourceIds, true) ? $sourceId : 0;

        $query = $this->access->visibleRequirements($user);

        if ($search !== '') {
            $needle = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($search)).'%';
            $query->where(function (Builder $inner) use ($needle): void {
                $inner->whereRaw('lower(compliance_requirements.title) like ?', [$needle])
                    ->orWhereRaw('lower(compliance_requirements.requirement_text) like ?', [$needle])
                    ->orWhereRaw("lower(coalesce(compliance_requirements.reference, '')) like ?", [$needle]);
            });
        }

        if ($status !== '') {
            $query->where('compliance_requirements.status', $status);
        }

        if ($applicability !== '') {
            $query->where('compliance_requirements.applicability', $applicability);
        }

        if ($sourceId !== 0) {
            $query->where('compliance_requirements.source_id', $sourceId);
        }

        $requirements = $query->inRegisterOrder()
            ->with(['source:id,name,version,framework_id', 'owner:id,name', 'frameworkNode:id,applicability_rule'])
            ->get();

        $canEdit = $this->access->canEdit($user);
        $canDelete = $this->access->canDelete($user);

        return Inertia::render('App/Compliance/Requirements/Index', [
            'requirements' => $requirements->map(fn (ComplianceRequirement $requirement): array => $this->row($requirement) + [
                'applicability_rule' => $requirement->frameworkNode?->applicability_rule,
            ])->all(),
            // Only what the user can see — which in v1 is the customer's whole register, or nothing.
            'visible_count' => $this->access->visibleRequirements($user)->count(),
            'filters' => [
                'search' => $search,
                'source' => $sourceId !== 0 ? $sourceId : null,
                'status' => $status,
                'applicability' => $applicability,
            ],
            'statuses' => ComplianceRequirement::STATUSES,
            'applicabilities' => ComplianceRequirement::APPLICABILITIES,
            'sources' => $sources->map(fn (ComplianceSource $source): array => [
                'id' => (int) $source->id,
                'name' => $source->name,
                'version' => $source->version,
                'label' => $source->originLabel(),
                'kind' => $source->kind,
                'description' => $source->description,
                'requirement_count' => (int) $source->requirements_count,
                // An activated framework's source follows the library: not edited, and nothing is
                // registered under it by hand.
                'from_library' => $source->isFromLibrary(),
                'can_delete' => $canDelete && (int) $source->requirements_count === 0,
            ])->all(),
            'kinds' => ComplianceSource::KINDS,
            'review_intervals' => ComplianceRequirement::REVIEW_INTERVALS,
            'permissions' => [
                'can_edit' => $canEdit,
                'can_delete' => $canDelete,
            ],
            'owner_options' => $canEdit ? $this->access->ownerCandidates($user) : [],
        ]);
    }

    public function show(int $requirementId): Response
    {
        $user = $this->authorizedUser();
        $requirement = $this->visibleRequirementOrFail($user, $requirementId);
        $requirement->loadMissing(['source:id,name,version,kind,framework_id', 'owner:id,name']);

        $canEdit = $this->access->canEdit($user);
        $active = $requirement->isActive();
        $assessments = $requirement->assessments()->with('assessedBy:id,name')->get();
        $canManageQualityLinks = $this->qualityContext->canManageLinks($user, $requirement);
        $fromLibrary = $requirement->isFromLibrary();
        // A library requirement is assessed only while it applies, and Ikke relevant is relevance,
        // not a result.
        $assessable = $active && (! $fromLibrary || $requirement->applicability === ComplianceRequirement::APPLICABILITY_APPLIES);
        $requirement->loadMissing('applicabilitySetBy:id,name');

        return Inertia::render('App/Compliance/Requirements/Show', [
            'requirement' => $this->row($requirement) + [
                'requirement_text' => $requirement->requirement_text,
                'source_kind' => $requirement->source?->kind,
                'origin' => $requirement->origin(),
                'created_at' => $requirement->created_at?->toIso8601String(),
            ],
            'compliance' => $this->status->summarize($requirement, $assessments->first()),
            'attention' => $this->attention->reasonsFor($requirement, $assessments->first()),
            // Reached through visibleRequirements(), like the status history.
            'assessments' => $assessments->map(fn (ComplianceAssessment $assessment): array => [
                'id' => (int) $assessment->id,
                'result' => $assessment->result,
                'rationale' => $assessment->rationale,
                'documentation' => $assessment->documentation,
                'assessed_at' => $assessment->assessed_at?->toIso8601String(),
                'assessed_by_name' => $assessment->assessedBy?->name,
                'snapshot' => [
                    'reference' => $assessment->requirement_reference,
                    'title' => $assessment->requirement_title,
                    'requirement_text' => $assessment->requirement_text,
                    'source_name' => $assessment->source_name,
                    'source_version' => $assessment->source_version,
                ],
            ])->all(),
            'assessment_results' => $fromLibrary
                ? array_values(array_diff(ComplianceAssessment::RESULTS, [ComplianceAssessment::RESULT_NOT_APPLICABLE]))
                : ComplianceAssessment::RESULTS,
            // Fra kravbiblioteket: guidance, documentation, normative character and where in
            // Procynia the work is done. null for a requirement registered by hand.
            'library' => $this->library->libraryContext($requirement, $user),
            'applicability' => $fromLibrary ? [
                'value' => $requirement->applicability,
                'rationale' => $requirement->applicability_rationale,
                'set_at' => $requirement->applicability_set_at?->toIso8601String(),
                'set_by_name' => $requirement->applicabilitySetBy?->name,
                'options' => $this->applicabilityOptions($requirement),
            ] : null,
            // Hvordan kravet oppfylles: null — not empty — without Kvalitet read access, so nothing
            // about the links reaches the page, not even whether there are any.
            'quality_context' => $this->qualityContext->canReadQuality($user) ? $this->qualityContext->linkedContext($requirement) : null,
            'quality_options' => $canManageQualityLinks ? $this->qualityContext->options($requirement) : null,
            // Reached through visibleRequirements(); the history carries no access of its own.
            'status_history' => $requirement->statusChanges()->with('changedBy:id,name')->get()
                ->map(fn (ComplianceRequirementStatusChange $change): array => [
                    'id' => (int) $change->id,
                    'from_status' => $change->from_status,
                    'to_status' => $change->to_status,
                    'note' => $change->note,
                    'changed_at' => $change->changed_at?->toIso8601String(),
                    'changed_by_name' => $change->changedBy?->name,
                ])
                ->all(),
            'permissions' => [
                // A retired requirement is reopened before it is changed.
                'can_edit' => $canEdit && $active,
                'can_retire' => $canEdit && $active,
                'can_reopen' => $canEdit && ! $active,
                'can_delete' => $this->access->canDelete($user) && $requirement->isDeletable(),
                // Only an active requirement is assessed; the server refuses it otherwise too.
                'can_assess' => $this->access->canAssess($user) && $assessable,
                // Relevans: compliance.edit, on an active library requirement.
                'can_set_applicability' => $fromLibrary && $canEdit && $active,
                // compliance.edit and Kvalitet read access, on an active requirement.
                'can_manage_quality_links' => $canManageQualityLinks,
            ],
            'source_options' => $canEdit && $active && ! $fromLibrary ? $this->sourceOptions($user) : [],
            'owner_options' => $canEdit && $active ? $this->access->ownerCandidates($user) : [],
            'review_intervals' => ComplianceRequirement::REVIEW_INTERVALS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->authorizedUser();
        abort_unless($this->access->canEdit($user), 403);

        $fields = $this->validatedFields($request, $user, null);

        $requirement = $this->guardReferenceRace(fn (): ComplianceRequirement => ComplianceRequirement::query()->create($fields + [
            'customer_id' => (int) $user->customer_id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]));

        return redirect()
            ->route('app.compliance.requirements.show', ['requirementId' => $requirement->id])
            ->with('success', __('procynia.compliance.flash.created'));
    }

    public function update(Request $request, int $requirementId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $requirement = $this->visibleRequirementOrFail($user, $requirementId);
        abort_unless($this->access->canEdit($user), 403);

        if (! $requirement->isActive()) {
            return back()->with('error', __('procynia.compliance.validation.reopen_before_edit'));
        }

        $fields = $this->validatedFields($request, $user, $requirement);

        $this->guardReferenceRace(fn () => $requirement->fill($fields + ['updated_by' => $user->id])->save());

        return back()->with('success', __('procynia.compliance.flash.updated'));
    }

    /** Sett som utgått: the requirement no longer applies. Always says why. */
    public function retire(Request $request, int $requirementId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $requirement = $this->visibleRequirementOrFail($user, $requirementId);
        abort_unless($this->access->canEdit($user), 403);

        $this->lifecycle->retire($requirement, $user, $this->validatedReason($request));

        return back()->with('success', __('procynia.compliance.flash.retired'));
    }

    /** Gjenåpne: the requirement applies again. Always says why; the retirement stays in the history. */
    public function reopen(Request $request, int $requirementId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $requirement = $this->visibleRequirementOrFail($user, $requirementId);
        abort_unless($this->access->canEdit($user), 403);

        $this->lifecycle->reopen($requirement, $user, $this->validatedReason($request));

        return back()->with('success', __('procynia.compliance.flash.reopened'));
    }

    /**
     * Vurder etterlevelse: a new, immutable assessment. Access first — the requirement must be
     * visible (404 otherwise), then compliance.assess (403). Only an active requirement is
     * assessed. The date is the system's; nothing in the request can set it.
     */
    public function assess(Request $request, int $requirementId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $requirement = $this->visibleRequirementOrFail($user, $requirementId);
        abort_unless($this->access->canAssess($user), 403);

        if (! $requirement->isActive()) {
            return back()->with('error', __('procynia.compliance.validation.assess_retired'));
        }

        $validated = $request->validate([
            'result' => ['required', 'string', Rule::in(ComplianceAssessment::RESULTS)],
            'rationale' => ['required', 'string', 'max:10000'],
            // Optional: what the assessment points to — a Wiki article, a document, a link.
            'documentation' => ['nullable', 'string', 'max:5000'],
        ], ComplianceValidationMessages::messages(), ComplianceValidationMessages::attributes());

        $this->assessments->assess($requirement, $user, $validated['result'], $validated['rationale'], $validated['documentation'] ?? null);

        return back()->with('success', __('procynia.compliance.flash.assessed'));
    }

    /**
     * Relevans for a library requirement. Access first (404), then compliance.edit (403). Ikke
     * relevant only where the standard allows it, and always with a begrunnelse — the service
     * refuses it otherwise.
     */
    public function setApplicability(Request $request, int $requirementId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $requirement = $this->visibleRequirementOrFail($user, $requirementId);
        abort_unless($this->access->canEdit($user), 403);

        $validated = $request->validate([
            'applicability' => ['required', 'string', Rule::in(ComplianceRequirement::APPLICABILITIES)],
            'rationale' => ['nullable', 'string', 'max:10000'],
        ], ComplianceValidationMessages::messages(), ComplianceValidationMessages::attributes());

        $saved = $this->applicability->set($requirement, $user, $validated['applicability'], $validated['rationale'] ?? null);

        return back()->with('success', __($saved->frameworkNode?->isControl()
            ? 'procynia.compliance.library.flash.control_choice_saved'
            : 'procynia.compliance.library.flash.applicability_saved'));
    }

    /** Koble prosess: the requirement is met (in part) through an existing Kvalitet process. */
    public function linkProcess(Request $request, int $requirementId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $requirement = $this->visibleRequirementOrFail($user, $requirementId);
        $processId = (int) $request->validate(['quality_process_id' => ['required', 'integer']], ComplianceValidationMessages::messages(), ComplianceValidationMessages::attributes())['quality_process_id'];

        $this->qualityContext->linkProcess($user, $requirement, $processId);

        return back()->with('success', __('procynia.compliance.flash.process_linked'));
    }

    /** Fjern kobling. The process stays as it is in Kvalitet. */
    public function unlinkProcess(int $requirementId, int $processId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $requirement = $this->visibleRequirementOrFail($user, $requirementId);

        abort_unless($this->qualityContext->unlinkProcess($user, $requirement, $processId), 404);

        return back()->with('success', __('procynia.compliance.flash.process_unlinked'));
    }

    /** Koble kontroll: the requirement is met (in part) through an existing Kvalitet control. */
    public function linkControl(Request $request, int $requirementId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $requirement = $this->visibleRequirementOrFail($user, $requirementId);
        $controlId = (int) $request->validate(['control_item_id' => ['required', 'integer']], ComplianceValidationMessages::messages(), ComplianceValidationMessages::attributes())['control_item_id'];

        $this->qualityContext->linkControl($user, $requirement, $controlId);

        return back()->with('success', __('procynia.compliance.flash.control_linked'));
    }

    /** Fjern kobling. The control and its evidence stay as they are in Kvalitet. */
    public function unlinkControl(int $requirementId, int $controlId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $requirement = $this->visibleRequirementOrFail($user, $requirementId);

        abort_unless($this->qualityContext->unlinkControl($user, $requirement, $controlId), 404);

        return back()->with('success', __('procynia.compliance.flash.control_unlinked'));
    }

    public function destroy(int $requirementId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $requirement = $this->visibleRequirementOrFail($user, $requirementId);
        abort_unless($this->access->canDelete($user), 403);

        if (! $requirement->isDeletable()) {
            return back()->with('error', __('procynia.compliance.validation.not_deletable'));
        }

        $requirement->delete();

        return redirect()->route('app.compliance.requirements.index')->with('success', __('procynia.compliance.flash.deleted'));
    }

    private function authorizedUser(): User
    {
        $user = $this->customerContext->currentUser();

        abort_unless($this->access->canOpenModule($user), 403);

        return $user;
    }

    private function visibleRequirementOrFail(User $user, int $requirementId): ComplianceRequirement
    {
        return $this->access->findVisibleRequirement($user, $requirementId) ?? abort(404);
    }

    /**
     * The same fields for registering and editing, checked against what the user can reach. Status
     * is not among them: a requirement is created active and moves only through the lifecycle.
     *
     * @return array<string, mixed>
     */
    private function validatedFields(Request $request, User $user, ?ComplianceRequirement $current): array
    {
        if ($current !== null && $current->isFromLibrary()) {
            return $this->validatedLibraryFields($request, $user);
        }

        $validated = $request->validate([
            'source_id' => ['required', 'integer'],
            'reference' => ['nullable', 'string', 'max:100'],
            'title' => ['required', 'string', 'max:255'],
            'requirement_text' => ['required', 'string', 'max:20000'],
            // Optional: responsibility follows the work (assessment, audit, Avvik), not each requirement.
            'owner_user_id' => ['nullable', 'integer'],
            'review_interval_months' => ['nullable', 'integer', Rule::in(ComplianceRequirement::REVIEW_INTERVALS)],
        ], ComplianceValidationMessages::messages(), ComplianceValidationMessages::attributes());

        // A 422 rather than a 404: the id came from a form, and whether it names another customer's
        // source or nothing at all, the answer is the same.
        $source = $this->access->findVisibleSource($user, (int) $validated['source_id']);

        if ($source === null) {
            throw ValidationException::withMessages(['source_id' => __('procynia.compliance.validation.source_not_allowed')]);
        }

        if ($source->isFromLibrary()) {
            throw ValidationException::withMessages(['source_id' => __('procynia.compliance.library.validation.source_is_library')]);
        }

        $owner = $this->validatedOwner($validated['owner_user_id'] ?? null, $user);

        $reference = trim((string) ($validated['reference'] ?? ''));
        $reference = $reference !== '' ? $reference : null;

        if ($reference !== null && $this->referenceTaken((int) $source->id, $reference, $current?->id)) {
            throw ValidationException::withMessages(['reference' => __('procynia.compliance.validation.reference_taken')]);
        }

        $interval = $validated['review_interval_months'] ?? null;

        return [
            'source_id' => (int) $source->id,
            'reference' => $reference,
            'title' => trim($validated['title']),
            'requirement_text' => trim($validated['requirement_text']),
            'owner_user_id' => $owner?->id,
            'review_interval_months' => $interval !== null ? (int) $interval : null,
        ];
    }

    /**
     * Rediger on a library requirement: only what the customer owns — the person responsible and the
     * review interval. Reference, title and text follow the library.
     *
     * @return array<string, mixed>
     */
    private function validatedLibraryFields(Request $request, User $user): array
    {
        $validated = $request->validate([
            'owner_user_id' => ['nullable', 'integer'],
            'review_interval_months' => ['nullable', 'integer', Rule::in(ComplianceRequirement::REVIEW_INTERVALS)],
        ], ComplianceValidationMessages::messages(), ComplianceValidationMessages::attributes());

        $owner = $this->validatedOwner($validated['owner_user_id'] ?? null, $user);
        $interval = $validated['review_interval_months'] ?? null;

        return [
            'owner_user_id' => $owner?->id,
            'review_interval_months' => $interval !== null ? (int) $interval : null,
        ];
    }

    /**
     * Ansvarlig is optional. Left empty it is null; chosen, it must be someone of the customer who
     * can work in the module — a 422 otherwise, whoever the id names.
     */
    private function validatedOwner(mixed $ownerId, User $user): ?User
    {
        if ($ownerId === null || $ownerId === '') {
            return null;
        }

        $owner = User::query()->where('customer_id', (int) $user->customer_id)->find((int) $ownerId);

        if (! $this->access->isValidOwner($owner, (int) $user->customer_id)) {
            throw ValidationException::withMessages(['owner_user_id' => __('procynia.compliance.validation.owner_not_allowed')]);
        }

        return $owner;
    }

    /**
     * The relevance choices a library requirement offers: Ikke relevant only where the standard
     * allows an organisation to exclude it.
     *
     * @return list<string>
     */
    private function applicabilityOptions(ComplianceRequirement $requirement): array
    {
        $node = $requirement->frameworkNode;

        return $node !== null && $node->mayBeNotApplicable()
            ? ComplianceRequirement::APPLICABILITIES
            : [ComplianceRequirement::APPLICABILITY_APPLIES, ComplianceRequirement::APPLICABILITY_NOT_ASSESSED];
    }

    /** Within one source, ignoring case — the same rule as the database's unique index. */
    private function referenceTaken(int $sourceId, string $reference, ?int $exceptId): bool
    {
        return ComplianceRequirement::query()
            ->where('source_id', $sourceId)
            ->whereRaw('lower(reference) = ?', [mb_strtolower($reference)])
            ->when($exceptId !== null, fn (Builder $query) => $query->whereKeyNot($exceptId))
            ->exists();
    }

    /**
     * Two saves of the same reference at once both pass the check above; the unique index refuses
     * the second, and it gets the same answer the check would have given.
     *
     * @template T
     *
     * @param  callable(): T  $write
     * @return T
     */
    private function guardReferenceRace(callable $write): mixed
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['reference' => __('procynia.compliance.validation.reference_taken')]);
        }
    }

    private function validatedReason(Request $request): string
    {
        return $request->validate([
            'reason' => ['required', 'string', 'max:5000'],
        ], ComplianceValidationMessages::messages(), ComplianceValidationMessages::attributes())['reason'];
    }

    /** @return list<array{id: int, label: string}> */
    private function sourceOptions(User $user): array
    {
        return $this->access->visibleSources($user)
            ->whereNull('compliance_sources.framework_id')
            ->orderBy('name')
            ->orderBy('version')
            ->get()
            ->map(fn (ComplianceSource $source): array => ['id' => (int) $source->id, 'label' => $source->originLabel()])
            ->all();
    }

    /** @return array<string, mixed> */
    private function row(ComplianceRequirement $requirement): array
    {
        return [
            'id' => (int) $requirement->id,
            'reference' => $requirement->reference,
            'title' => $requirement->title,
            'status' => $requirement->status,
            'source_id' => (int) $requirement->source_id,
            'source_label' => $requirement->source?->originLabel(),
            'origin' => $requirement->origin(),
            'owner_user_id' => $requirement->owner_user_id !== null ? (int) $requirement->owner_user_id : null,
            'owner_name' => $requirement->owner?->name,
            'review_interval_months' => $requirement->review_interval_months,
            'from_library' => $requirement->isFromLibrary(),
            'applicability' => $requirement->applicability,
            'url' => route('app.compliance.requirements.show', ['requirementId' => $requirement->id]),
        ];
    }
}
