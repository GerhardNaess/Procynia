<?php

namespace App\Services\Suppliers\Assurance;

use App\Models\Supplier;
use App\Models\SupplierControlRequirement;
use App\Models\SupplierDocument;
use App\Models\SupplierProfile;
use App\Models\SupplierRequirementEvaluation;
use App\Models\SupplierRequirementEvaluationDocument;
use App\Models\User;
use App\Services\Suppliers\SupplierAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Kontroller krav · Bekreft kravene på nytt — the only writer of a SupplierRequirementEvaluation and
 * its documentation (docs/supplier-assurance-v2-plan.md §8, §10.2–10.3, §13.2).
 *
 * Documentation is evidence; the control is a person's judgement of it. The status is what the
 * person chose — nothing here derives it from a document type, a certificate or a date.
 *
 * Inside one transaction, with the supplier row locked:
 *  1. the supplier is looked up again through SupplierAccessService::visibleSuppliers() (another
 *     customer's is a 404) and the actor must hold supplier.assure — never edit, assess or delete;
 *  2. an ended supplier is refused;
 *  3. the requirement must apply to the supplier now, decided by SupplierRequirementApplicability
 *     and nothing else — so a retired requirement, an excluded one, one whose rule does not hold
 *     and another supplier's own are all refused, and an included one is allowed;
 *  4. the documents must be rows of this supplier; Dokumentert needs at least one;
 *  5. the control and one snapshot row per document are written, or nothing is.
 *
 * A double submit — the same person registering exactly the same control again — is refused, not
 * written twice: the lock makes the second request see the first.
 */
class SupplierRequirementEvaluationService
{
    public function __construct(
        private readonly SupplierAccessService $access,
        private readonly SupplierRequirementApplicability $applicability,
        private readonly SupplierRequirementReasonText $text,
    ) {}

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'requirement_id' => ['required', 'integer'],
            'status' => ['required', 'string', Rule::in(SupplierRequirementEvaluation::STATUSES)],
            'rationale' => ['required', 'string', 'max:10000'],
            'evaluated_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'accepted_until' => [
                'exclude_unless:status,'.SupplierRequirementEvaluation::STATUS_TEMPORARILY_ACCEPTED,
                'required',
                'date_format:Y-m-d',
                'after_or_equal:today',
                'before_or_equal:'.now()->addMonthsNoOverflow(SupplierRequirementEvaluation::MAX_ACCEPTANCE_MONTHS)->toDateString(),
            ],
            'document_ids' => ['nullable', 'array', 'max:50'],
            'document_ids.*' => ['integer', 'distinct'],
        ];
    }

    /** @param  array<string, mixed>  $validated  rules() */
    public function record(User $actor, Supplier $supplier, array $validated): SupplierRequirementEvaluation
    {
        $rationale = trim((string) ($validated['rationale'] ?? ''));

        if ($rationale === '') {
            throw ValidationException::withMessages(['rationale' => __('procynia.supplier_management.validation.rationale_required')]);
        }

        return DB::transaction(function () use ($actor, $supplier, $validated, $rationale): SupplierRequirementEvaluation {
            $locked = $this->lock($actor, $supplier);
            [$requirement, $decision] = $this->applying($locked, (int) $validated['requirement_id']);
            $status = (string) $validated['status'];
            $documents = $this->documentsOf($locked, array_map('intval', (array) ($validated['document_ids'] ?? [])));

            if ($status === SupplierRequirementEvaluation::STATUS_DOCUMENTED && $documents->isEmpty()) {
                throw ValidationException::withMessages(['document_ids' => __('procynia.supplier_management.validation.documented_requires_document')]);
            }

            $row = [
                'status' => $status,
                'rationale' => $rationale,
                'evaluated_on' => (string) $validated['evaluated_on'],
                'accepted_until' => $status === SupplierRequirementEvaluation::STATUS_TEMPORARILY_ACCEPTED ? (string) $validated['accepted_until'] : null,
            ];

            if ($this->sameAsLastRegistered($actor, $locked, $requirement, $row, $documents)) {
                throw ValidationException::withMessages(['status' => __('procynia.supplier_management.validation.evaluation_already_registered')]);
            }

            return $this->write($actor, $locked, $requirement, $decision, $row, $documents);
        });
    }

    /**
     * Bekreft kravene på nytt (§10.3): after «Registrer fornyet», the requirements whose control in
     * force rests on an earlier edition of $documentId get a new control each — same status, the new
     * edition in place of the earlier one, today's date, one shared begrunnelse. Only requirements
     * offered by reconfirmable() for this document, checked again inside the lock; nothing happens
     * without the person choosing them.
     *
     * @param  list<int>  $requirementIds
     * @return list<SupplierRequirementEvaluation>
     */
    public function reconfirm(User $actor, Supplier $supplier, int $documentId, array $requirementIds, ?string $rationale): array
    {
        $rationale = trim((string) $rationale);

        if ($rationale === '') {
            throw ValidationException::withMessages(['rationale' => __('procynia.supplier_management.validation.rationale_required')]);
        }

        if ($requirementIds === []) {
            throw ValidationException::withMessages(['requirement_ids' => __('procynia.supplier_management.validation.reconfirm_choose')]);
        }

        return DB::transaction(function () use ($actor, $supplier, $documentId, $requirementIds, $rationale): array {
            $locked = $this->lock($actor, $supplier);
            $documents = SupplierDocument::query()->where('supplier_id', $locked->id)->get()->keyBy('id');
            $applying = collect($this->applicability->for($locked))->filter(fn (array $decision): bool => $decision['applies'])
                ->keyBy(fn (array $decision): int => (int) $decision['requirement']->id);
            $current = $this->currentControls($locked, $applying->keys()->all());
            $offered = self::reconfirmable($current, $documents)[$documentId] ?? [];

            if (array_diff($requirementIds, $offered) !== []) {
                throw ValidationException::withMessages(['requirement_ids' => __('procynia.supplier_management.validation.reconfirm_not_available')]);
            }

            $written = [];

            foreach (array_values(array_unique($requirementIds)) as $requirementId) {
                $previous = $current[$requirementId];
                $ids = $previous->documents->map(fn (SupplierRequirementEvaluationDocument $used): int => self::latestEdition((int) $used->supplier_document_id, $documents) === $documentId
                    ? $documentId
                    : (int) $used->supplier_document_id)->unique()->values()->all();
                $decision = $applying[$requirementId];

                $written[] = $this->write($actor, $locked, $decision['requirement'], $decision, [
                    'status' => $previous->status,
                    'rationale' => $rationale,
                    'evaluated_on' => now()->toDateString(),
                    'accepted_until' => null,
                ], $documents->only($ids)->values());
            }

            return $written;
        });
    }

    /**
     * Which requirements can be confirmed again on which current document: for each control in force
     * that is Dokumentert or Delvis dokumentert and names a row that has since been renewed, the
     * latest edition of that row — when the control does not name it already. Computed, never stored.
     *
     * @param  array<int, SupplierRequirementEvaluation>  $currentByRequirement  the control in force per applying requirement, documents loaded
     * @param  Collection<int, SupplierDocument>  $documentsById  all of the supplier's documentation rows
     * @return array<int, list<int>> document id => requirement ids
     */
    public static function reconfirmable(array $currentByRequirement, Collection $documentsById): array
    {
        $offers = [];

        foreach ($currentByRequirement as $requirementId => $evaluation) {
            if (! in_array($evaluation->status, [SupplierRequirementEvaluation::STATUS_DOCUMENTED, SupplierRequirementEvaluation::STATUS_PARTIALLY_DOCUMENTED], true)) {
                continue;
            }

            $named = $evaluation->documents->map(fn (SupplierRequirementEvaluationDocument $used): int => (int) $used->supplier_document_id)->all();

            foreach ($named as $id) {
                $latest = self::latestEdition($id, $documentsById);

                if ($latest !== $id && ! in_array($latest, $named, true) && ! in_array((int) $requirementId, $offers[$latest] ?? [], true)) {
                    $offers[$latest][] = (int) $requirementId;
                }
            }
        }

        return $offers;
    }

    /**
     * The control in force for each of the given requirements, with its documentation loaded.
     *
     * @param  list<int>  $requirementIds
     * @return array<int, SupplierRequirementEvaluation>
     */
    public function currentControls(Supplier $supplier, array $requirementIds): array
    {
        return SupplierRequirementEvaluation::query()
            ->with('documents')
            ->where('supplier_id', $supplier->id)
            ->whereIn('requirement_id', $requirementIds)
            ->get()
            ->groupBy('requirement_id')
            ->map(fn (Collection $evaluations): ?SupplierRequirementEvaluation => SupplierRequirementStatus::current($evaluations))
            ->all();
    }

    /** Following «Registrer fornyet» from a row to the edition that has not been replaced. */
    private static function latestEdition(int $documentId, Collection $documentsById): int
    {
        $seen = [];

        while (($next = $documentsById->get($documentId)?->replaced_by_document_id) !== null && ! isset($seen[$documentId])) {
            $seen[$documentId] = true;
            $documentId = (int) $next;
        }

        return $documentId;
    }

    private function lock(User $actor, Supplier $supplier): Supplier
    {
        $locked = $this->access->visibleSuppliers($actor)->whereKey($supplier->id)->lockForUpdate()->firstOrFail();

        if (! $this->access->canAssure($actor)) {
            throw new AuthorizationException;
        }

        if ($locked->isEnded()) {
            throw ValidationException::withMessages(['requirement_id' => __('procynia.supplier_management.validation.reopen_before_edit')]);
        }

        return $locked;
    }

    /**
     * The requirement, held against retiring while the control is written, and the applicability
     * decision for it — refused unless it applies to the supplier now.
     *
     * @return array{0: SupplierControlRequirement, 1: array<string, mixed>}
     */
    private function applying(Supplier $locked, int $requirementId): array
    {
        $requirement = SupplierControlRequirement::query()
            ->where('customer_id', (int) $locked->customer_id)
            ->whereKey($requirementId)
            ->sharedLock()
            ->first();

        // A 422 rather than a 404: the id came from a form, and whether it names another customer's
        // requirement or nothing at all, the answer is the same.
        if ($requirement === null) {
            throw ValidationException::withMessages(['requirement_id' => __('procynia.supplier_management.validation.control_requirement_not_available')]);
        }

        $answers = SupplierProfile::query()->whereKey($locked->id)->first()?->answers();
        $decision = SupplierRequirementApplicability::decide(
            $requirement,
            $locked,
            SupplierProfilePredicates::evaluate($locked, $answers),
            SupplierProfilePredicates::uncertain($locked, $answers),
            $this->applicability->latestOverrides([(int) $locked->id])[$locked->id.':'.$requirement->id] ?? null,
        );

        if (! $decision['applies']) {
            throw ValidationException::withMessages(['requirement_id' => __('procynia.supplier_management.validation.requirement_not_applicable')]);
        }

        return [$requirement, $decision];
    }

    /**
     * The supplier's own documentation rows with these ids — all of them, or a refusal.
     *
     * @param  list<int>  $ids
     * @return Collection<int, SupplierDocument>
     */
    private function documentsOf(Supplier $locked, array $ids): Collection
    {
        $ids = array_values(array_unique($ids));
        $documents = $ids === [] ? collect() : SupplierDocument::query()
            ->where('supplier_id', $locked->id)
            ->where('customer_id', $locked->customer_id)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get();

        if ($documents->count() !== count($ids)) {
            throw ValidationException::withMessages(['document_ids' => __('procynia.supplier_management.validation.document_not_available')]);
        }

        return $documents;
    }

    /**
     * @param  array{status: string, rationale: string, evaluated_on: string, accepted_until: string|null}  $row
     * @param  Collection<int, SupplierDocument>  $documents
     */
    private function sameAsLastRegistered(User $actor, Supplier $locked, SupplierControlRequirement $requirement, array $row, Collection $documents): bool
    {
        $last = SupplierRequirementEvaluation::query()
            ->with('documents')
            ->where('supplier_id', $locked->id)
            ->where('requirement_id', $requirement->id)
            ->orderByDesc('id')
            ->first();

        if ($last === null || (int) $last->evaluated_by_user_id !== (int) $actor->id) {
            return false;
        }

        $named = $last->documents->pluck('supplier_document_id')->map(fn ($id): int => (int) $id)->sort()->values()->all();

        return [$last->status, $last->rationale, $last->evaluated_on?->toDateString(), $last->accepted_until?->toDateString(), $named]
            === [$row['status'], $row['rationale'], $row['evaluated_on'], $row['accepted_until'], $documents->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all()];
    }

    /**
     * @param  array<string, mixed>  $decision
     * @param  array{status: string, rationale: string, evaluated_on: string, accepted_until: string|null}  $row
     * @param  Collection<int, SupplierDocument>  $documents
     */
    private function write(User $actor, Supplier $locked, SupplierControlRequirement $requirement, array $decision, array $row, Collection $documents): SupplierRequirementEvaluation
    {
        $evaluation = SupplierRequirementEvaluation::query()->create($row + [
            'customer_id' => (int) $locked->customer_id,
            'supplier_id' => (int) $locked->id,
            'requirement_id' => (int) $requirement->id,
            'evaluated_by_user_id' => (int) $actor->id,
            'recorded_at' => now(),
            'requirement_title' => $requirement->title,
            'requirement_level' => $requirement->level,
            'requirement_theme' => $requirement->theme,
            'applicability_reason' => $this->text->because($decision)['text'],
            'supplier_name' => $locked->name,
            'criticality' => $locked->criticality,
        ]);

        foreach ($documents as $document) {
            SupplierRequirementEvaluationDocument::query()->create(SupplierRequirementEvaluationDocument::snapshotOf($document) + [
                'customer_id' => (int) $locked->customer_id,
                'evaluation_id' => (int) $evaluation->id,
                'supplier_document_id' => (int) $document->id,
            ]);
        }

        return $evaluation;
    }
}
