<?php

namespace Tests\Concerns;

use App\Models\BusinessArea;
use App\Models\ComplianceAudit;
use App\Models\ComplianceRequirement;
use App\Models\Customer;
use App\Models\ImprovementAction;
use App\Models\ImprovementCase;
use App\Models\Kpi;
use App\Models\Objective;
use App\Models\QualityItem;
use App\Models\Risk;
use App\Models\RiskTreatmentAction;
use App\Models\User;
use App\Services\Modules\ModuleEntitlementService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Support\Facades\DB;

/**
 * One customer holding every styringsmodul (Basis + GRC, Anbud from TestCase), a fagområde, and
 * objects of each module for «Mine oppgaver» and its notifications. Builds on
 * CreatesImprovementCaseScenarios (context(), member(), grant(), grantAll(), area()) and
 * CreatesComplianceScenarios (sources, requirements, audits), which the test class uses alongside.
 */
trait CreatesGovernanceTaskScenarios
{
    /** Every view and act permission of the five modules. */
    private const GOVERNANCE_PERMISSIONS = [
        CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_EDIT, CustomerPermissionCatalog::RISK_ASSESS, CustomerPermissionCatalog::RISK_ACCEPT,
        CustomerPermissionCatalog::IMPROVEMENT_VIEW, CustomerPermissionCatalog::IMPROVEMENT_EDIT, CustomerPermissionCatalog::IMPROVEMENT_CLOSE,
        CustomerPermissionCatalog::COMPLIANCE_VIEW, CustomerPermissionCatalog::COMPLIANCE_EDIT, CustomerPermissionCatalog::COMPLIANCE_ASSESS, CustomerPermissionCatalog::COMPLIANCE_AUDIT,
        CustomerPermissionCatalog::QUALITY_VIEW, CustomerPermissionCatalog::QUALITY_EDIT,
        CustomerPermissionCatalog::OBJECTIVE_VIEW, CustomerPermissionCatalog::OBJECTIVE_EDIT, CustomerPermissionCatalog::OBJECTIVE_MEASURE,
    ];

    /** Read only, everywhere. */
    private const GOVERNANCE_VIEW = [
        CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::IMPROVEMENT_VIEW, CustomerPermissionCatalog::COMPLIANCE_VIEW,
        CustomerPermissionCatalog::QUALITY_VIEW, CustomerPermissionCatalog::OBJECTIVE_VIEW,
    ];

    /** @return array{customer: Customer, area: BusinessArea} */
    private function governanceCustomer(): array
    {
        ['customer' => $customer] = $this->context('basis');
        app(ModuleEntitlementService::class)->activatePackage($customer, 'grc');

        return ['customer' => $customer->fresh(), 'area' => $this->area($customer, 'Drift')];
    }

    /** @param  list<string>|null  $permissions */
    private function governancePerson(Customer $customer, ?array $permissions = null): User
    {
        $user = $this->member($customer);
        $this->grantAll($customer, $user, $permissions ?? self::GOVERNANCE_PERMISSIONS);

        return $user->fresh();
    }

    private function riskOwnedBy(Customer $customer, BusinessArea $area, ?User $owner, string $title = 'Leverandørsvikt', string $status = Risk::STATUS_IDENTIFIED): Risk
    {
        return Risk::query()->create([
            'customer_id' => $customer->id, 'business_area_id' => $area->id, 'title' => $title,
            'cause' => 'manglende rutiner', 'event' => 'en hendelse inntreffer', 'consequence' => 'virksomheten rammes',
            'status' => $status, 'owner_user_id' => $owner?->id,
        ]);
    }

    private function riskAction(Risk $risk, ?User $owner, string $dueAt, string $title = 'Etabler reserveleverandør', string $status = RiskTreatmentAction::STATUS_OPEN): RiskTreatmentAction
    {
        return RiskTreatmentAction::query()->create([
            'customer_id' => $risk->customer_id, 'risk_id' => $risk->id, 'title' => $title, 'due_at' => $dueAt,
            'status' => $status, 'owner_user_id' => $owner?->id,
            'completed_at' => $status === RiskTreatmentAction::STATUS_COMPLETED ? now() : null,
        ]);
    }

    private function caseOwnedBy(Customer $customer, BusinessArea $area, ?User $owner, string $title = 'Avvik i rutine', ?string $dueDate = null): ImprovementCase
    {
        $case = $this->improvementCase($customer, $area, $title, $owner);

        if ($dueDate !== null) {
            $case->forceFill(['due_date' => $dueDate])->saveQuietly();
        }

        return $case->fresh();
    }

    private function qualityItemOwnedBy(Customer $customer, ?User $owner, string $title = 'Tilgangskontroll', string $type = QualityItem::TYPE_CONTROL, string $status = QualityItem::STATUS_ACTIVE): QualityItem
    {
        return QualityItem::query()->create([
            'customer_id' => $customer->id, 'quality_type' => $type, 'title' => $title,
            'status' => $status, 'owner_user_id' => $owner?->id,
        ]);
    }

    private function objectiveOwnedBy(Customer $customer, BusinessArea $area, ?User $owner, string $title = 'Høy leveransepresisjon', ?string $targetDate = null): Objective
    {
        return Objective::query()->create([
            'customer_id' => $customer->id, 'business_area_id' => $area->id, 'title' => $title,
            'owner_user_id' => $owner?->id, 'target_date' => $targetDate,
        ]);
    }

    /** A monthly KPI that has existed since the given day, so its past months are expected. */
    private function kpiSince(Objective $objective, ?User $owner, string $since, string $title = 'Oppetid'): Kpi
    {
        $kpi = Kpi::query()->create([
            'customer_id' => $objective->customer_id, 'objective_id' => $objective->id, 'title' => $title,
            'unit' => Kpi::UNIT_PERCENT, 'target_min' => '98', 'frequency' => Kpi::FREQUENCY_MONTHLY,
            'reporting_grace_days' => 7, 'owner_user_id' => $owner?->id,
        ]);
        DB::table('kpis')->where('id', $kpi->id)->update(['created_at' => $since]);
        DB::table('objectives')->where('id', $objective->id)->update(['created_at' => $since]);

        return $kpi->fresh();
    }

    private function requirementOwnedBy(Customer $customer, ?User $owner, string $title = 'Tilgangsstyring'): ComplianceRequirement
    {
        return $this->complianceRequirement($this->complianceSource($customer), $title, $owner);
    }

    private function auditFor(Customer $customer, ?User $responsible, string $plannedEnd, string $status = ComplianceAudit::STATUS_PLANNED): ComplianceAudit
    {
        $audit = $this->complianceAudit($customer, $responsible, 'Internrevisjon', $status);
        $audit->forceFill(['planned_start_date' => '2026-09-01', 'planned_end_date' => $plannedEnd])->saveQuietly();

        return $audit->fresh();
    }

    private function improvementActionFor(ImprovementCase $case, ?User $owner, string $dueDate, string $title = 'Oppdater rutinen'): ImprovementAction
    {
        return $this->improvementAction($case, $title, $owner, $dueDate);
    }
}
