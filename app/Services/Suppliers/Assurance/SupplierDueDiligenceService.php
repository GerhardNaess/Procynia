<?php

namespace App\Services\Suppliers\Assurance;

use App\Models\Supplier;
use App\Models\SupplierControlRequirement;
use App\Models\SupplierDueDiligenceAssessment;
use App\Models\SupplierProfile;
use App\Models\User;
use App\Services\Suppliers\SupplierAccessService;
use App\Services\Suppliers\SupplierReviewSchedule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Aktsomhetsvurdering (docs/supplier-assurance-v2-plan.md §11, §13.2, §21) — the only writer of a
 * SupplierDueDiligenceAssessment, and what the supplier page shows of them.
 *
 * Kartlegg → Vurder risiko → Undersøk → Tiltak → Følg opp → Dokumenter, without a workflow of its own:
 * the profile maps (production outside the EEA, high-risk categories, subcontractors, labour
 * intensity), the assessment records the six areas, what was mapped and investigated, and the
 * person's conclusion; control requirements on human rights, working conditions and environment are
 * controlled as any other; «Tiltak kreves» is followed up in Avvik og forbedringer, a significant risk
 * in Risiko (SupplierImprovementHandoffService, SupplierRiskService); the next assessment falls due at
 * assessed_on + review_interval_months, computed on read.
 *
 * NO SCORE. Each area is chosen by the person; the conclusion is chosen by the person and never
 * derived from the areas. Nothing here writes or changes a SupplierAssuranceDecision, the criticality
 * or the lifecycle — «Høy» in every area is still only what the person registered.
 *
 * Inside one transaction, with the supplier row locked:
 *  1. the supplier is looked up again through SupplierAccessService::visibleSuppliers() (another
 *     customer's is a 404) and the actor must hold supplier.assure — never edit, assess or delete;
 *  2. an ended supplier is refused;
 *  3. one new row is written with the snapshot the plan names: the supplier's name, criticality,
 *     high_risk_categories and production_outside_eea as they are now. Never an update, never a delete.
 */
class SupplierDueDiligenceService
{
    public function __construct(
        private readonly SupplierAccessService $access,
        private readonly SupplierReviewSchedule $schedule,
        private readonly SupplierRequirementReasonText $reasons,
    ) {}

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        $areas = [];

        foreach (SupplierDueDiligenceAssessment::AREAS as $area) {
            $areas[$area] = ['required', 'string', Rule::in(SupplierDueDiligenceAssessment::LEVELS)];
        }

        return $areas + [
            'supply_chain_description' => ['nullable', 'string', 'max:10000'],
            'investigation_summary' => ['nullable', 'string', 'max:10000'],
            'conclusion' => ['required', 'string', Rule::in(SupplierDueDiligenceAssessment::CONCLUSIONS)],
            'rationale' => ['required', 'string', 'max:10000'],
            'review_interval_months' => ['required', 'integer', Rule::in(SupplierDueDiligenceAssessment::REVIEW_INTERVALS)],
            'assessed_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ];
    }

    /** @param  array<string, mixed>  $validated  rules() */
    public function record(User $actor, Supplier $supplier, array $validated): SupplierDueDiligenceAssessment
    {
        $areas = [];

        foreach (SupplierDueDiligenceAssessment::AREAS as $area) {
            $level = (string) ($validated[$area] ?? '');

            if (! in_array($level, SupplierDueDiligenceAssessment::LEVELS, true)) {
                throw ValidationException::withMessages([$area => __('procynia.supplier_management.validation.due_diligence_level_required')]);
            }

            $areas[$area] = $level;
        }

        $conclusion = (string) ($validated['conclusion'] ?? '');
        $rationale = trim((string) ($validated['rationale'] ?? ''));
        $interval = (int) ($validated['review_interval_months'] ?? 0);

        if (! in_array($conclusion, SupplierDueDiligenceAssessment::CONCLUSIONS, true)) {
            throw ValidationException::withMessages(['conclusion' => __('procynia.supplier_management.validation.rules.choose')]);
        }

        if ($rationale === '') {
            throw ValidationException::withMessages(['rationale' => __('procynia.supplier_management.validation.rationale_required')]);
        }

        if (! in_array($interval, SupplierDueDiligenceAssessment::REVIEW_INTERVALS, true)) {
            throw ValidationException::withMessages(['review_interval_months' => __('procynia.supplier_management.validation.rules.choose')]);
        }

        return DB::transaction(function () use ($actor, $supplier, $areas, $conclusion, $rationale, $interval, $validated): SupplierDueDiligenceAssessment {
            $locked = $this->access->visibleSuppliers($actor)->whereKey($supplier->id)->lockForUpdate()->firstOrFail();

            if (! $this->access->canAssure($actor)) {
                throw new AuthorizationException;
            }

            if ($locked->isEnded()) {
                throw ValidationException::withMessages(['conclusion' => __('procynia.supplier_management.validation.reopen_before_edit')]);
            }

            $answers = SupplierProfile::query()->where('customer_id', $locked->customer_id)->where('supplier_id', $locked->id)->first()?->answers();

            return SupplierDueDiligenceAssessment::query()->create($areas + [
                'customer_id' => (int) $locked->customer_id,
                'supplier_id' => (int) $locked->id,
                'supply_chain_description' => self::optional($validated['supply_chain_description'] ?? null),
                'investigation_summary' => self::optional($validated['investigation_summary'] ?? null),
                'conclusion' => $conclusion,
                'rationale' => $rationale,
                'review_interval_months' => $interval,
                'assessed_on' => (string) $validated['assessed_on'],
                'assessed_by_user_id' => (int) $actor->id,
                'recorded_at' => now(),
                'supplier_name' => $locked->name,
                'criticality' => $locked->criticality,
                'high_risk_categories' => $answers['high_risk_categories'] ?? null,
                'production_outside_eea' => $answers['production_outside_eea'] ?? null,
            ]);
        });
    }

    /** The next assessment: assessed_on + review_interval_months (plan §14), never stored. */
    public function nextOn(SupplierDueDiligenceAssessment $assessment): ?CarbonImmutable
    {
        return $this->schedule->nextReviewOn((int) $assessment->review_interval_months, $assessment->assessed_on);
    }

    public function isOverdue(SupplierDueDiligenceAssessment $assessment, ?CarbonInterface $today = null): bool
    {
        return $this->schedule->isOverdue($this->nextOn($assessment), $today);
    }

    /**
     * The assessment in force per supplier — latest assessed_on, then highest id — read once for many
     * suppliers of one customer.
     *
     * @param  list<int>  $supplierIds
     * @return array<int, SupplierDueDiligenceAssessment>
     */
    public function inForce(int $customerId, array $supplierIds): array
    {
        if ($supplierIds === []) {
            return [];
        }

        $latest = [];

        SupplierDueDiligenceAssessment::query()
            ->where('customer_id', $customerId)
            ->whereIn('supplier_id', $supplierIds)
            ->orderBy('assessed_on')
            ->orderBy('id')
            ->get()
            ->each(function (SupplierDueDiligenceAssessment $assessment) use (&$latest): void {
                $latest[(int) $assessment->supplier_id] = $assessment;
            });

        return $latest;
    }

    /**
     * «Aktsomhet og bærekraft» on the supplier page. Null for a customer that has not started
     * Leverandørkontroll and a supplier never assessed — such a customer sees the page as before
     * (plan §17, invariant 16). Otherwise always shown, also when the profile does not make it
     * relevant: then it says so and an assessment can still be registered (§11.3).
     *
     * @return array<string, mixed>|null
     */
    public function payload(User $user, Supplier $supplier, ?CarbonInterface $today = null): ?array
    {
        $assessments = $supplier->dueDiligenceAssessments()->with('assessedBy:id,name')->get();
        $started = SupplierControlRequirement::query()->where('customer_id', (int) $supplier->customer_id)->exists();

        if (! $started && $assessments->isEmpty()) {
            return null;
        }

        $today ??= now();
        $answers = $supplier->profile()->first()?->answers();
        $facts = SupplierProfilePredicates::evaluate($supplier, $answers);
        $uncertain = SupplierProfilePredicates::uncertain($supplier, $answers);
        $groups = SupplierRequirementApplicability::holdingGroups(SupplierProfilePredicates::DUE_DILIGENCE_RULE, $facts, $uncertain);
        $because = $groups === [] ? null : $this->reasons->because(['source' => SupplierRequirementApplicability::SOURCE_RULE, 'groups' => $groups]);
        $current = $assessments->first();
        $next = $current !== null ? $this->nextOn($current) : null;
        $open = ! $supplier->isEnded();
        $canAssure = $this->access->canAssure($user);

        return [
            'relevant' => $groups !== [],
            'because' => $because,
            // Kartlegg: the profile's own answers, as they are now. null = not answered — shown as
            // such, never as «no».
            'mapping' => [
                'profile_empty' => SupplierProfilePredicates::isEmpty($answers),
                'production_outside_eea' => $answers['production_outside_eea'] ?? null,
                'high_risk_categories' => $answers['high_risk_categories'] ?? null,
                'uses_subcontractors' => $answers['uses_subcontractors'] ?? null,
                'labour_intensive' => $answers['labour_intensive'] ?? null,
            ],
            'next_on' => $next?->toDateString(),
            'overdue' => $open && $this->schedule->isOverdue($next, $today),
            'history' => $assessments->map(fn (SupplierDueDiligenceAssessment $assessment): array => [
                'id' => (int) $assessment->id,
                'areas' => $assessment->areas(),
                'supply_chain_description' => $assessment->supply_chain_description,
                'investigation_summary' => $assessment->investigation_summary,
                'conclusion' => $assessment->conclusion,
                'rationale' => $assessment->rationale,
                'review_interval_months' => (int) $assessment->review_interval_months,
                'assessed_on' => $assessment->assessed_on?->toDateString(),
                'assessed_by_name' => $assessment->assessedBy?->name,
                'recorded_at' => $assessment->recorded_at?->toIso8601String(),
                'snapshot' => [
                    'supplier_name' => $assessment->supplier_name,
                    'criticality' => $assessment->criticality,
                    'high_risk_categories' => $assessment->high_risk_categories,
                    'production_outside_eea' => $assessment->production_outside_eea,
                ],
            ])->values()->all(),
            'permissions' => [
                'can_assess' => $canAssure && $open,
                'ended' => ! $open,
                'has_assure_right' => $canAssure,
            ],
            'options' => [
                'areas' => SupplierDueDiligenceAssessment::AREAS,
                'levels' => SupplierDueDiligenceAssessment::LEVELS,
                'conclusions' => SupplierDueDiligenceAssessment::CONCLUSIONS,
                'review_intervals' => SupplierDueDiligenceAssessment::REVIEW_INTERVALS,
                'today' => $today->toDateString(),
            ],
        ];
    }

    private static function optional(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
