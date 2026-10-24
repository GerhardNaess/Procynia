<?php

namespace App\Services\Api\Presenters;

use App\Models\Supplier;
use App\Models\SupplierAssessment;
use App\Models\User;
use App\Services\Suppliers\Assurance\SupplierAssuranceResolver;
use App\Services\Suppliers\Assurance\SupplierDueDiligenceService;
use App\Services\Suppliers\Assurance\SupplierFollowUpPlan;
use App\Services\Suppliers\Assurance\SupplierRequirementPayload;
use App\Services\Suppliers\SupplierAttentionService;
use App\Services\Suppliers\SupplierComplianceRequirementService;
use App\Services\Suppliers\SupplierImprovementHandoffService;
use App\Services\Suppliers\SupplierReviewSchedule;
use App\Services\Suppliers\SupplierRiskService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Leverandøroppfølging as the external API presents it: structured fields only (D8).
 *
 * In: identifiers, name, organisasjonsnummer, category, status, criticality, review interval and
 * dates, ansvarlig, kravstatus and the attention codes. The detail adds the control requirements that
 * apply with their status and follow-up, the aktsomhetsvurdering (status, next date, latest
 * conclusion and areas), the follow-up plan and the links to Risiko, Avvik og forbedringer and
 * Etterlevelse. The catalogue is the customer's kontrollkrav. Out: the leveranse description,
 * contact details and notes, every rationale, comment, description, guidance and generated
 * explanation, documents and files, the profile answers, assessments' ratings, every person who
 * assessed, controlled or changed something, the histories, the Wiki guidance and web routes.
 *
 * Nothing is judged here. The kravstatus is SupplierAssuranceResolver's, the attention codes are
 * SupplierAttentionService's, the requirements, their status and follow-up are
 * SupplierRequirementPayload's, the plan is SupplierFollowUpPlan's, the aktsomhet is
 * SupplierDueDiligenceService's and the links are each module's own service, read for the identity —
 * the same services as the register and the supplier page.
 */
class SupplierPresenter
{
    public function __construct(
        private readonly SupplierReviewSchedule $schedule,
        private readonly SupplierAssuranceResolver $assurance,
        private readonly SupplierAttentionService $attention,
        private readonly SupplierRequirementPayload $requirements,
        private readonly SupplierDueDiligenceService $dueDiligence,
        private readonly SupplierRiskService $risks,
        private readonly SupplierImprovementHandoffService $cases,
        private readonly SupplierComplianceRequirementService $compliance,
    ) {}

    /**
     * @param  Collection<int, Supplier>  $suppliers  already reached through SupplierAccessService::visibleSuppliers(),
     *                                                with owner and latest_assessed_on loaded
     * @return list<array<string, mixed>>
     */
    public function rows(Collection $suppliers): array
    {
        $findings = $this->attention->findingsForSuppliers($suppliers);
        $states = $this->assurance->forSuppliers($suppliers);

        return $suppliers
            ->map(fn (Supplier $supplier): array => $this->row($supplier, $states[(int) $supplier->id]['state'] ?? null, $findings[(int) $supplier->id] ?? []))
            ->values()
            ->all();
    }

    /**
     * One supplier with its requirements, aktsomhet, follow-up plan and links.
     *
     * @return array<string, mixed>
     */
    public function detail(User $identity, Supplier $supplier): array
    {
        $today = now();
        $row = $this->rows(new Collection([$supplier]))[0];
        $payload = $this->requirements->forSupplier($identity, $supplier);
        $dueDiligence = $this->dueDiligence->payload($identity, $supplier, $today);
        $latestDueDiligence = $dueDiligence['history'][0] ?? null;
        // The supplier page's own condition: only once the customer has control requirements, and never for an ended supplier.
        $plan = ! $supplier->isEnded() && $payload !== null
            ? SupplierFollowUpPlan::build(
                $payload['applicable'],
                $supplier->documents()->get(),
                $row['next_review_on'] !== null ? Carbon::parse($row['next_review_on']) : null,
                $today,
                ($dueDiligence['next_on'] ?? null) !== null ? Carbon::parse($dueDiligence['next_on']) : null,
            )
            : null;
        $risks = $this->risks->risksFor($identity, $supplier);
        $cases = $this->cases->casesFor($identity, $supplier);
        $compliance = $this->compliance->requirementsFor($identity, $supplier);

        return $row + [
            // null for a customer without control requirements: nothing is said about them.
            'requirements' => $payload === null ? null : array_map(fn (array $requirement): array => $this->requirement($requirement) + [
                'display_status' => $requirement['display_status'],
                'evaluated' => $requirement['evaluated'],
                'current' => $requirement['current'] === null ? null : [
                    'status' => $requirement['current']['status'],
                    'evaluated_on' => $requirement['current']['evaluated_on'],
                    'accepted_until' => $requirement['current']['accepted_until'],
                ],
                'follow_up' => [
                    'next_control_on' => $requirement['follow_up']['next_control_on'] ?? null,
                    'control_overdue' => (bool) ($requirement['follow_up']['control_overdue'] ?? false),
                    'document_renewal_due' => (bool) ($requirement['follow_up']['document_renewal_due'] ?? false),
                    'accepted_until' => $requirement['follow_up']['accepted_until'] ?? null,
                    'acceptance_expired' => (bool) ($requirement['follow_up']['acceptance_expired'] ?? false),
                ],
            ], array_values($payload['applicable'])),
            'due_diligence' => $dueDiligence === null ? null : [
                'status' => $dueDiligence['status'],
                'next_on' => $dueDiligence['next_on'],
                'is_overdue' => (bool) $dueDiligence['overdue'],
                'latest' => $latestDueDiligence === null ? null : [
                    'conclusion' => $latestDueDiligence['conclusion'],
                    'areas' => $latestDueDiligence['areas'],
                    'assessed_on' => $latestDueDiligence['assessed_on'],
                    'review_interval_months' => $latestDueDiligence['review_interval_months'],
                ],
            ],
            'follow_up_plan' => $plan === null ? null : array_map(fn (array $entry): array => [
                'kind' => $entry['kind'],
                'date' => $entry['date'],
                'is_overdue' => (bool) $entry['overdue'],
                'requirement_id' => $entry['requirement']['id'] ?? null,
                'document_type' => $entry['document']['document_type'] ?? null,
            ], $plan['entries']),
            // Each null when the identity cannot read that module: nothing is said about it.
            'links' => [
                'risk_ids' => $risks === null ? null : array_values(array_map(fn (array $risk): int => (int) $risk['id'], $risks)),
                'improvement_case_ids' => $cases === null ? null : array_values(array_map(fn (array $case): int => (int) $case['id'], $cases)),
                'compliance_requirement_ids' => $compliance === null ? null : array_values(array_map(fn (array $requirement): int => (int) $requirement['id'], $compliance)),
            ],
        ];
    }

    /**
     * Kontrollkrav: the customer's catalogue, as Leverandørkontroll lists it.
     *
     * @return list<array<string, mixed>>
     */
    public function catalogue(User $identity): array
    {
        return array_map(fn (array $requirement): array => $this->requirement($requirement) + [
            'applies_to_count' => $requirement['applies_to_count'],
            'template' => $requirement['template'],
        ], $this->requirements->catalogue($identity));
    }

    /**
     * @param  list<array<string, mixed>>  $findings
     * @return array<string, mixed>
     */
    private function row(Supplier $supplier, ?string $state, array $findings): array
    {
        $latestAssessedOn = $supplier->getAttribute('latest_assessed_on');
        $next = $this->schedule->nextReviewOn($supplier->review_interval_months, $latestAssessedOn !== null ? Carbon::parse($latestAssessedOn) : null);

        return [
            'id' => (int) $supplier->id,
            'name' => $supplier->name,
            'organization_number' => $supplier->organization_number,
            'category' => $supplier->category,
            'status' => $supplier->status,
            'criticality' => $supplier->criticality,
            'review_interval_months' => $supplier->review_interval_months,
            'last_assessed_on' => $latestAssessedOn !== null ? CarbonImmutable::parse($latestAssessedOn)->toDateString() : null,
            'next_review_on' => $next?->toDateString(),
            'owner' => $supplier->owner_user_id !== null
                ? ['id' => (int) $supplier->owner_user_id, 'name' => $supplier->owner?->name]
                : null,
            // Kravstatus: never on an ended supplier, which is no longer followed up.
            'requirement_status' => $supplier->isEnded() ? null : $state,
            'attention' => array_values(array_unique(array_column($findings, 'key'))),
            'created_at' => $supplier->created_at?->utc()->toIso8601ZuluString(),
            'updated_at' => $supplier->updated_at?->utc()->toIso8601ZuluString(),
        ];
    }

    /**
     * A kontrollkrav's structured fields, from SupplierRequirementPayload's own row.
     *
     * @param  array<string, mixed>  $requirement
     * @return array<string, mixed>
     */
    private function requirement(array $requirement): array
    {
        return [
            'id' => $requirement['id'],
            'title' => $requirement['title'],
            'theme' => $requirement['theme'],
            'level' => $requirement['level'],
            'control_point' => $requirement['control_point'],
            'control_interval_months' => $requirement['control_interval_months'],
            'accepted_document_types' => $requirement['accepted_document_types'],
            'status' => $requirement['status'],
            'supplier_specific' => $requirement['supplier_specific'],
            // The requirement in Etterlevelse it is anchored in, when the identity may read it there.
            'compliance_requirement_id' => $requirement['anchor']['id'] ?? null,
        ];
    }

    /** The latest assessment's day, as the register and the supplier page read it. */
    public static function latestAssessedOn(): Builder
    {
        return SupplierAssessment::query()
            ->selectRaw('max(assessed_on)')
            ->whereColumn('supplier_assessments.supplier_id', 'suppliers.id')
            ->whereColumn('supplier_assessments.customer_id', 'suppliers.customer_id');
    }
}
