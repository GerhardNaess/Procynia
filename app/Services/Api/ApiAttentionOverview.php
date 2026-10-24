<?php

namespace App\Services\Api;

use App\Models\ComplianceAudit;
use App\Models\ComplianceRequirement;
use App\Models\Customer;
use App\Models\ImprovementCase;
use App\Models\Objective;
use App\Models\QualityItem;
use App\Models\Risk;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Api\Presenters\QualityPresenter;
use App\Services\Compliance\ComplianceAccessService;
use App\Services\Compliance\ComplianceAttentionService;
use App\Services\Compliance\ComplianceAuditAttentionService;
use App\Services\Improvements\ImprovementAttentionService;
use App\Services\Improvements\ImprovementCaseAccessService;
use App\Services\Objectives\ObjectiveAccessService;
use App\Services\Objectives\ObjectiveAttentionService;
use App\Services\Risk\RiskAccessService;
use App\Services\Risk\RiskAttentionService;
use App\Services\Suppliers\SupplierAccessService;
use App\Services\Suppliers\SupplierAttentionService;
use Carbon\CarbonImmutable;

/**
 * «Trenger oppmerksomhet» across the modules the API serves, for GET /api/v1/attention: every
 * record the integration identity may read that needs following up, with the reasons as codes.
 *
 * Nothing is judged here. Each module's own attention service decides, over the same set its
 * register's panel uses — risks that are not closed, active avvik and forbedringer, active
 * objectives and their active KPIs — taken from the module's access service for the identity, so a
 * record outside its customer or fagområder never enters. A module is included only when
 * ApiAccessSummary calls it readable (ordered by the customer, and the identity may open it); the
 * others are reported as not readable, never as having nothing.
 *
 * Out, as everywhere in the API: the explanatory sentences of the panels, free text and web routes.
 */
class ApiAttentionOverview
{
    public function __construct(
        private readonly ApiAccessSummary $summary,
        private readonly RiskAccessService $risks,
        private readonly RiskAttentionService $riskAttention,
        private readonly ImprovementCaseAccessService $cases,
        private readonly ImprovementAttentionService $caseAttention,
        private readonly ObjectiveAccessService $objectives,
        private readonly ObjectiveAttentionService $objectiveAttention,
        private readonly ComplianceAccessService $compliance,
        private readonly ComplianceAttentionService $requirementAttention,
        private readonly ComplianceAuditAttentionService $auditAttention,
        private readonly SupplierAccessService $suppliers,
        private readonly SupplierAttentionService $supplierAttention,
        private readonly QualityPresenter $quality,
    ) {}

    /**
     * @return array{
     *     modules: array{risk: bool, deviations: bool, objectives: bool, compliance: bool, suppliers: bool, quality: bool},
     *     items: list<array<string, mixed>>
     * }
     */
    public function for(User $identity, Customer $customer, CarbonImmutable $today): array
    {
        $summary = $this->summary->for($identity, $customer);
        $readable = [
            'risk' => $summary['risk']['readable'],
            'deviations' => $summary['deviations']['readable'],
            'objectives' => $summary['objectives']['readable'],
            'compliance' => $summary['compliance']['readable'],
            'suppliers' => $summary['suppliers']['readable'],
            'quality' => $summary['quality']['readable'],
        ];

        return [
            'modules' => $readable,
            'items' => [
                ...($readable['risk'] ? $this->risks($identity, $today) : []),
                ...($readable['deviations'] ? $this->cases($identity, $today) : []),
                ...($readable['objectives'] ? $this->objectives($identity, $today) : []),
                ...($readable['compliance'] ? $this->compliance($identity, $today) : []),
                ...($readable['suppliers'] ? $this->suppliers($identity, $today) : []),
                ...($readable['quality'] ? $this->quality($identity) : []),
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function risks(User $identity, CarbonImmutable $today): array
    {
        $risks = $this->risks->visibleRisks($identity)
            ->where('risks.status', '!=', Risk::STATUS_CLOSED)
            ->with('businessArea:id,name')
            ->orderBy('risks.id')
            ->get(['risks.*']);
        $findings = $this->riskAttention->findingsForRisks($risks, $today);
        $items = [];

        foreach ($risks as $risk) {
            $codes = array_keys($findings[(int) $risk->id] ?? []);

            if ($codes !== []) {
                $items[] = $this->item('risk', (int) $risk->id, $risk->title, (int) $risk->business_area_id, $risk->businessArea?->name, $codes);
            }
        }

        return $items;
    }

    /**
     * Avvik and forbedringer, each case once with its own reasons and those of its tiltak, in the
     * service's display order — the codes of the case rows' follow_up.attention.
     *
     * @return list<array<string, mixed>>
     */
    private function cases(User $identity, CarbonImmutable $today): array
    {
        $cases = $this->cases->visibleCases($identity)
            ->whereIn('improvement_cases.status', ImprovementCase::ACTIVE_STATUSES)
            ->with('businessArea:id,name')
            ->orderBy('improvement_cases.id')
            ->get(['improvement_cases.*']);
        $findings = $this->caseAttention->findingsForCases($cases, $today);
        $keys = [];

        foreach ($findings['cases'] as $finding) {
            foreach (array_keys($finding['reasons']) as $key) {
                $keys[(int) $finding['case']->id][$key] = true;
            }
        }

        foreach ($findings['actions'] as $finding) {
            foreach (array_keys($finding['reasons']) as $key) {
                $keys[(int) $finding['action']->improvement_case_id][$key] = true;
            }
        }

        $order = array_keys(ImprovementAttentionService::CATEGORIES);
        $items = ['deviation' => [], 'improvement' => []];

        foreach ($cases as $case) {
            if (! isset($keys[(int) $case->id])) {
                continue;
            }

            $codes = array_values(array_filter($order, fn (string $key): bool => isset($keys[(int) $case->id][$key])));
            $type = $case->type === ImprovementCase::TYPE_DEVIATION ? 'deviation' : 'improvement';
            $items[$type][] = $this->item($type, (int) $case->id, $case->title, (int) $case->business_area_id, $case->businessArea?->name, $codes);
        }

        return [...$items['deviation'], ...$items['improvement']];
    }

    /**
     * Objectives with their own reasons, then KPIs with theirs. A KPI finding never becomes an
     * objective code, as on the register's panel.
     *
     * @return list<array<string, mixed>>
     */
    private function objectives(User $identity, CarbonImmutable $today): array
    {
        $objectives = $this->objectives->visibleObjectives($identity)
            ->where('objectives.status', Objective::STATUS_ACTIVE)
            ->with('businessArea:id,name')
            ->orderBy('objectives.id')
            ->get(['objectives.*']);
        $findings = $this->objectiveAttention->findingsForObjectives($objectives, $today);
        $areas = $objectives->keyBy('id');

        $objectiveItems = array_map(fn (array $finding): array => $this->item(
            'objective',
            (int) $finding['objective']->id,
            $finding['objective']->title,
            (int) $finding['objective']->business_area_id,
            $finding['objective']->businessArea?->name,
            array_keys($finding['reasons']),
        ), $findings['objectives']);

        $kpiItems = array_map(function (array $finding) use ($areas): array {
            $objective = $areas->get((int) $finding['kpi']->objective_id);

            return $this->item(
                'kpi',
                (int) $finding['kpi']->id,
                $finding['kpi']->title,
                (int) $objective?->business_area_id,
                $objective?->businessArea?->name,
                array_keys($finding['reasons']),
                (int) $finding['kpi']->objective_id,
            );
        }, $findings['kpis']);

        usort($objectiveItems, fn (array $a, array $b): int => $a['id'] <=> $b['id']);
        usort($kpiItems, fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return [...$objectiveItems, ...$kpiItems];
    }

    /**
     * Requirements, then audits — ComplianceAttentionService and ComplianceAuditAttentionService
     * over the active requirements and the audits that are not cancelled, the sets the register and
     * Ledelsens gjennomgang use. Neither has a fagområde.
     *
     * @return list<array<string, mixed>>
     */
    private function compliance(User $identity, CarbonImmutable $today): array
    {
        $requirements = $this->compliance->visibleRequirements($identity)
            ->where('compliance_requirements.status', ComplianceRequirement::STATUS_ACTIVE)
            ->orderBy('compliance_requirements.id')
            ->get(['compliance_requirements.*']);
        $audits = $this->compliance->visibleAudits($identity)
            ->where('compliance_audits.status', '!=', ComplianceAudit::STATUS_CANCELLED)
            ->orderBy('compliance_audits.id')
            ->get(['compliance_audits.*']);
        $requirementReasons = $this->requirementAttention->reasonsForRequirements($requirements, null, $today);
        $auditReasons = $this->auditAttention->reasonsForAudits($audits, $today);
        $items = [];

        foreach ($requirements as $requirement) {
            if (($requirementReasons[(int) $requirement->id] ?? []) !== []) {
                $items[] = $this->item('compliance_requirement', (int) $requirement->id, $requirement->title, null, null, $requirementReasons[(int) $requirement->id]);
            }
        }

        foreach ($audits as $audit) {
            if (($auditReasons[(int) $audit->id] ?? []) !== []) {
                $items[] = $this->item('compliance_audit', (int) $audit->id, $audit->title, null, null, $auditReasons[(int) $audit->id]);
            }
        }

        return $items;
    }

    /**
     * Suppliers that are not ended — the register's panel — each with SupplierAttentionService's
     * finding keys once, in its order. Suppliers have no fagområde.
     *
     * @return list<array<string, mixed>>
     */
    private function suppliers(User $identity, CarbonImmutable $today): array
    {
        $suppliers = $this->suppliers->visibleSuppliers($identity)
            ->where('suppliers.status', '!=', Supplier::STATUS_ENDED)
            ->orderBy('suppliers.id')
            ->get(['suppliers.*']);
        $findings = $this->supplierAttention->findingsForSuppliers($suppliers, $today);
        $items = [];

        foreach ($suppliers as $supplier) {
            $codes = array_values(array_unique(array_column($findings[(int) $supplier->id] ?? [], 'key')));

            if ($codes !== []) {
                $items[] = $this->item('supplier', (int) $supplier->id, $supplier->name, null, null, $codes);
            }
        }

        return $items;
    }

    /**
     * Kvalitet's items with QualityAttentionService's codes — the customer's whole register, which is
     * what quality.view reads — without the code that is judged against a process flow's working
     * version (QualityPresenter::FLOW_DEPENDENT_FINDINGS). Kvalitet has no fagområder.
     *
     * @return list<array<string, mixed>>
     */
    private function quality(User $identity): array
    {
        $codes = $this->quality->attentionByItem((int) $identity->customer_id);

        if ($codes === []) {
            return [];
        }

        return QualityItem::query()
            ->where('customer_id', (int) $identity->customer_id)
            ->whereIn('id', array_keys($codes))
            ->orderBy('id')
            ->get(['id', 'title'])
            ->map(fn (QualityItem $item): array => $this->item('quality_item', (int) $item->id, $item->title, null, null, $codes[(int) $item->id]))
            ->all();
    }

    /**
     * One record needing attention. business_area is null for a module without fagområder. objective_id is a KPI's objective, null for anything else.
     *
     * @param  list<string>  $codes
     * @return array{type: string, id: int, title: string, objective_id: ?int, business_area: array{id: int, name: ?string}|null, attention: list<string>}
     */
    private function item(string $type, int $id, string $title, ?int $areaId, ?string $areaName, array $codes, ?int $objectiveId = null): array
    {
        return [
            'type' => $type,
            'id' => $id,
            'title' => $title,
            'objective_id' => $objectiveId,
            'business_area' => $areaId !== null ? ['id' => $areaId, 'name' => $areaName] : null,
            'attention' => $codes,
        ];
    }
}
