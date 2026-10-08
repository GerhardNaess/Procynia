<?php

namespace App\Services\Suppliers\Assurance;

use App\Models\ComplianceRequirement;
use App\Models\Supplier;
use App\Models\SupplierControlRequirement;
use App\Models\SupplierDocument;
use App\Models\SupplierRequirementOverride;
use App\Models\User;
use App\Services\Compliance\ComplianceAccessService;
use App\Services\Suppliers\SupplierAccessService;
use Illuminate\Support\Collection;

/**
 * What the pages are given about control requirements: the Kontrollkrav catalogue and a supplier's
 * Krav og kvalifikasjoner (docs/supplier-assurance-v2-plan.md §5.2, §6.6, §22). Reads only; every
 * decision about what applies is SupplierRequirementApplicability's, every text
 * SupplierRequirementReasonText's.
 *
 * Only supplier data. The anchor in Etterlevelse og revisjon is present only when the customer holds
 * the module and the person can read that requirement — otherwise it is absent: no title, no id, no
 * «hidden anchor». Its compliance status is never read.
 */
class SupplierRequirementPayload
{
    /** How many requirements the anchor choice offers, in register order. */
    private const ANCHOR_OPTIONS_LIMIT = 500;

    public function __construct(
        private readonly SupplierAccessService $access,
        private readonly ComplianceAccessService $compliance,
        private readonly SupplierRequirementApplicability $applicability,
        private readonly SupplierRequirementReasonText $text,
    ) {}

    /**
     * Krav og kvalifikasjoner on the supplier page. Null for a customer with no control requirements
     * at all, so nothing changes for a customer that has not started (§17) — except for someone with
     * supplier.assure, who may start with a requirement for this supplier.
     *
     * @return array<string, mixed>|null
     */
    public function forSupplier(User $user, Supplier $supplier): ?array
    {
        $canOverride = $this->access->canAssure($user) && ! $supplier->isEnded();

        if (! $canOverride && ! SupplierControlRequirement::query()->where('customer_id', (int) $supplier->customer_id)->exists()) {
            return null;
        }

        $decisions = collect($this->applicability->for($supplier));
        $anchors = $this->anchors($user, $decisions->map(fn (array $decision) => $decision['requirement']));

        $applicable = $decisions->filter(fn (array $decision): bool => $decision['applies'])
            ->map(fn (array $decision): array => $this->requirement($decision['requirement'], $anchors) + [
                'reason' => $this->text->because($decision),
                'exclusion_ignored' => $decision['exclusion_ignored'],
                // Nothing is controlled before phase 3: every applying requirement is Ikke vurdert.
                'display_status' => 'not_evaluated',
                'can_exclude' => $canOverride && $decision['automatic'] && ! $decision['requirement']->isMandatory()
                    && $decision['requirement']->supplier_id === null && $decision['override']?->action !== SupplierRequirementOverride::ACTION_EXCLUDE,
                'can_clear' => $canOverride && $decision['override'] !== null,
            ]);

        $excluded = $decisions->filter(fn (array $decision): bool => $decision['source'] === SupplierRequirementApplicability::SOURCE_MANUAL_EXCLUDE)
            ->map(fn (array $decision): array => $this->requirement($decision['requirement'], $anchors) + [
                'reason' => $this->text->notApplying($decision),
                'rule_text' => $this->text->rule((array) $decision['requirement']->applies_when),
                'can_clear' => $canOverride,
            ]);

        // Only for someone who can include: the catalogue requirements whose rule does not hold, each
        // with when it would apply.
        $includable = $canOverride ? $decisions->filter(fn (array $decision): bool => $decision['source'] === SupplierRequirementApplicability::SOURCE_RULE_NOT_MET
            && $decision['requirement']->supplier_id === null)
            ->map(fn (array $decision): array => [
                'id' => (int) $decision['requirement']->id,
                'title' => $decision['requirement']->title,
                'level' => $decision['requirement']->level,
                'theme' => $decision['requirement']->theme,
                'rule_text' => $this->text->rule((array) $decision['requirement']->applies_when),
            ]) : collect();

        return [
            'applicable' => $this->sorted($applicable),
            'excluded' => $this->sorted($excluded),
            'includable' => $this->sorted($includable),
            'history' => $supplier->requirementOverrides()->with('createdBy:id,name')->get()
                ->map(fn (SupplierRequirementOverride $override): array => [
                    'id' => (int) $override->id,
                    'action' => $override->action,
                    'action_text' => $this->text->action($override->action),
                    'title' => $override->requirement_title,
                    'reason' => $override->reason,
                    'created_at' => $override->created_at?->toIso8601String(),
                    'created_by_name' => $override->createdBy?->name,
                ])->all(),
            'permissions' => [
                'can_override' => $canOverride,
                'can_add_for_supplier' => $canOverride,
                // Says why nothing can be changed, for someone who otherwise could.
                'ended' => $supplier->isEnded() && $this->access->canAssure($user),
            ],
            'form' => $canOverride ? $this->formOptions($user) : null,
        ];
    }

    /**
     * The Kontrollkrav catalogue: catalogue requirements only (one supplier's are on its page), each
     * with when it applies and to how many suppliers it applies now — counted among the suppliers the
     * person can see that are not ended.
     *
     * @return list<array<string, mixed>>
     */
    public function catalogue(User $user): array
    {
        $requirements = SupplierControlRequirement::query()
            ->where('customer_id', (int) $user->customer_id)
            ->whereNull('supplier_id')
            ->withExists('overrides')
            ->orderByRaw('lower(title)')
            ->orderBy('id')
            ->get();

        $suppliers = $this->access->visibleSuppliers($user)->where('status', '!=', Supplier::STATUS_ENDED)->get();
        $counts = [];

        foreach ($this->applicability->forSuppliers($suppliers) as $decisions) {
            foreach ($decisions as $decision) {
                if ($decision['applies']) {
                    $id = (int) $decision['requirement']->id;
                    $counts[$id] = ($counts[$id] ?? 0) + 1;
                }
            }
        }

        $anchors = $this->anchors($user, $requirements);

        return $this->sorted($requirements->map(fn (SupplierControlRequirement $requirement): array => $this->requirement($requirement, $anchors) + [
            'rule_text' => $this->text->rule((array) $requirement->applies_when),
            'rule' => SupplierRequirementRule::toForm((array) $requirement->applies_when),
            'applies_to_count' => $counts[(int) $requirement->id] ?? 0,
            'deletable' => ! $requirement->overrides_exists,
        ]));
    }

    /**
     * What the requirement form offers: the codes, the conditions in words, and — only with access to
     * Etterlevelse og revisjon — the active requirements that can be chosen as anchor.
     *
     * @return array<string, mixed>
     */
    public function formOptions(User $user): array
    {
        return [
            'themes' => SupplierControlRequirement::THEMES,
            'levels' => SupplierControlRequirement::LEVELS,
            'control_points' => SupplierControlRequirement::CONTROL_POINTS,
            'control_intervals' => SupplierControlRequirement::CONTROL_INTERVALS,
            'document_types' => SupplierDocument::TYPES,
            'max_conditions' => SupplierRequirementRule::MAX_GROUPS,
            'conditions' => array_map(fn (string $predicate): array => [
                'value' => $predicate,
                'label' => $this->text->predicate($predicate),
            ], SupplierRequirementRule::conditions()),
            'anchor_options' => $this->compliance->canReadFromAnotherModule($user)
                ? $this->compliance->visibleRequirements($user)
                    ->where('compliance_requirements.status', ComplianceRequirement::STATUS_ACTIVE)
                    ->orderByRaw('lower(compliance_requirements.reference) ASC NULLS LAST')
                    ->orderBy('compliance_requirements.title')
                    ->orderBy('compliance_requirements.id')
                    ->limit(self::ANCHOR_OPTIONS_LIMIT)
                    ->get(['compliance_requirements.id', 'compliance_requirements.reference', 'compliance_requirements.title'])
                    ->map(fn (ComplianceRequirement $requirement): array => [
                        'id' => (int) $requirement->id,
                        'reference' => $requirement->reference,
                        'title' => $requirement->title,
                    ])->all()
                : null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $anchors
     * @return array<string, mixed>
     */
    private function requirement(SupplierControlRequirement $requirement, array $anchors): array
    {
        return [
            'id' => (int) $requirement->id,
            'title' => $requirement->title,
            'description' => $requirement->description,
            'guidance' => $requirement->guidance,
            'theme' => $requirement->theme,
            'level' => $requirement->level,
            'control_point' => $requirement->control_point,
            'control_interval_months' => $requirement->control_interval_months,
            'accepted_document_types' => (array) $requirement->accepted_document_types,
            'basis_text' => $requirement->basis_text,
            'status' => $requirement->status,
            'supplier_specific' => $requirement->supplier_id !== null,
            // Absent, not null-with-a-reason, when the person may not see it.
            'anchor' => $requirement->compliance_requirement_id !== null ? ($anchors[(int) $requirement->compliance_requirement_id] ?? null) : null,
        ];
    }

    /**
     * The anchors the person may see, by compliance requirement id: reference, title, whether retired
     * there, and the link. Never the compliance status.
     *
     * @param  Collection<int, SupplierControlRequirement>  $requirements
     * @return array<int, array<string, mixed>>
     */
    private function anchors(User $user, Collection $requirements): array
    {
        $ids = $requirements->pluck('compliance_requirement_id')->filter()->unique()->values()->all();

        if ($ids === [] || ! $this->compliance->canReadFromAnotherModule($user)) {
            return [];
        }

        return $this->compliance->visibleRequirements($user)
            ->whereIn('compliance_requirements.id', $ids)
            ->get(['compliance_requirements.id', 'compliance_requirements.reference', 'compliance_requirements.title', 'compliance_requirements.status'])
            ->mapWithKeys(fn (ComplianceRequirement $requirement): array => [(int) $requirement->id => [
                'id' => (int) $requirement->id,
                'reference' => $requirement->reference,
                'title' => $requirement->title,
                'retired' => ! $requirement->isActive(),
                'url' => route('app.compliance.requirements.show', ['requirementId' => $requirement->id]),
            ]])
            ->all();
    }

    /**
     * By theme in the catalogue's order, then Obligatorisk → Viktig → Oppfølging, then title.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function sorted(Collection $rows): array
    {
        return $rows->sortBy([
            fn (array $a, array $b): int => array_search($a['theme'], SupplierControlRequirement::THEMES, true) <=> array_search($b['theme'], SupplierControlRequirement::THEMES, true),
            fn (array $a, array $b): int => array_search($a['level'], SupplierControlRequirement::LEVELS, true) <=> array_search($b['level'], SupplierControlRequirement::LEVELS, true),
            fn (array $a, array $b): int => strcasecmp($a['title'], $b['title']),
        ])->values()->all();
    }
}
