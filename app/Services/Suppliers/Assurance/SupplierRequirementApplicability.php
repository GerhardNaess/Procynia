<?php

namespace App\Services\Suppliers\Assurance;

use App\Models\Supplier;
use App\Models\SupplierControlRequirement;
use App\Models\SupplierProfile;
use App\Models\SupplierRequirementOverride;
use Illuminate\Support\Collection;

/**
 * Kravprofil: which control requirements apply to a supplier, and why
 * (docs/supplier-assurance-v2-plan.md §5.2, §5.5). The one place this is decided; controllers and
 * pages only show what it says.
 *
 * COMPUTED, NEVER STORED. Automatic applicability is worked out on every read from the requirement's
 * rule, the supplier's profile and criticality (SupplierProfilePredicates), and the override in
 * force. No row anywhere says «requirement X applies to supplier Y»; change the profile and the
 * answer changes with it.
 *
 * In this order (§5.2):
 *  1. a retired requirement applies to no one;
 *  2. a requirement for one supplier applies to that supplier, always, and is never overridden;
 *  3. a catalogue requirement applies automatically when its rule holds — [] for every supplier,
 *     otherwise at least one group whose predicates all hold;
 *  4. the override in force (the latest row for the pair) decides over that: include applies,
 *     exclude does not, none or clear leaves it to the rule;
 *  5. except that exclude has no effect on a mandatory requirement that applies automatically —
 *     it applies, and says the exclusion does not count.
 *
 * decide() is pure. for() and forSuppliers() only load what decide() needs, in a fixed number of
 * queries, and do not check access: the caller hands in suppliers it reached through
 * SupplierAccessService.
 */
class SupplierRequirementApplicability
{
    public const SOURCE_SUPPLIER_SPECIFIC = 'supplier_specific';

    public const SOURCE_MANUAL_INCLUDE = 'manual_include';

    public const SOURCE_RULE = 'rule';

    public const SOURCE_ALL_SUPPLIERS = 'all_suppliers';

    public const SOURCE_MANUAL_EXCLUDE = 'manual_exclude';

    public const SOURCE_RULE_NOT_MET = 'rule_not_met';

    public const SOURCE_RETIRED = 'retired';

    /**
     * One requirement for one supplier.
     *
     * @param  list<string>  $facts  SupplierProfilePredicates::evaluate()
     * @param  list<string>  $uncertain  SupplierProfilePredicates::uncertain()
     * @param  SupplierRequirementOverride|null  $override  the latest override for the pair, whatever its action
     * @return array{requirement: SupplierControlRequirement, applies: bool, automatic: bool, source: string, groups: list<list<array{predicate: string, uncertain: bool}>>, override: SupplierRequirementOverride|null, exclusion_ignored: bool}
     */
    public static function decide(SupplierControlRequirement $requirement, Supplier $supplier, array $facts, array $uncertain, ?SupplierRequirementOverride $override): array
    {
        $decision = [
            'requirement' => $requirement,
            'applies' => false,
            'automatic' => false,
            'source' => self::SOURCE_RETIRED,
            'groups' => [],
            'override' => null,
            'exclusion_ignored' => false,
        ];

        if (! $requirement->isActive()) {
            return $decision;
        }

        if ($requirement->isSupplierSpecific()) {
            $mine = (int) $requirement->supplier_id === (int) $supplier->id;

            return ['applies' => $mine, 'source' => $mine ? self::SOURCE_SUPPLIER_SPECIFIC : self::SOURCE_RULE_NOT_MET] + $decision;
        }

        $rule = (array) $requirement->applies_when;
        $groups = self::holdingGroups($rule, $facts, $uncertain);
        $automatic = $rule === [] || $groups !== [];
        $automaticSource = $rule === [] ? self::SOURCE_ALL_SUPPLIERS : self::SOURCE_RULE;
        $inForce = in_array($override?->action, [SupplierRequirementOverride::ACTION_INCLUDE, SupplierRequirementOverride::ACTION_EXCLUDE], true) ? $override : null;

        $decision = [
            'automatic' => $automatic,
            'groups' => $groups,
            'override' => $inForce,
        ] + $decision;

        if ($inForce?->action === SupplierRequirementOverride::ACTION_INCLUDE) {
            return ['applies' => true, 'source' => self::SOURCE_MANUAL_INCLUDE] + $decision;
        }

        if ($inForce?->action === SupplierRequirementOverride::ACTION_EXCLUDE) {
            if ($automatic && $requirement->isMandatory()) {
                return ['applies' => true, 'source' => $automaticSource, 'exclusion_ignored' => true] + $decision;
            }

            return ['applies' => false, 'source' => self::SOURCE_MANUAL_EXCLUDE] + $decision;
        }

        return ['applies' => $automatic, 'source' => $automatic ? $automaticSource : self::SOURCE_RULE_NOT_MET] + $decision;
    }

    /**
     * Every active requirement that could concern the supplier — the catalogue and its own — decided.
     * Retired ones are left out: they apply to no one, and their overrides stay readable in the
     * history.
     *
     * @return list<array<string, mixed>>
     */
    public function for(Supplier $supplier): array
    {
        return $this->forSuppliers(collect([$supplier]))[(int) $supplier->id];
    }

    /**
     * The same for many suppliers of one customer at once, keyed by supplier id.
     *
     * @param  Collection<int, Supplier>  $suppliers
     * @return array<int, list<array<string, mixed>>>
     */
    public function forSuppliers(Collection $suppliers): array
    {
        if ($suppliers->isEmpty()) {
            return [];
        }

        $ids = $suppliers->map(fn (Supplier $supplier): int => (int) $supplier->id)->all();
        $customerIds = $suppliers->map(fn (Supplier $supplier): int => (int) $supplier->customer_id)->unique()->all();

        $requirements = SupplierControlRequirement::query()
            ->whereIn('customer_id', $customerIds)
            ->where('status', SupplierControlRequirement::STATUS_ACTIVE)
            ->where(fn ($query) => $query->whereNull('supplier_id')->orWhereIn('supplier_id', $ids))
            ->orderBy('id')
            ->get();

        $profiles = SupplierProfile::query()->whereIn('supplier_id', $ids)->get()->keyBy('supplier_id');
        $overrides = $this->latestOverrides($ids);

        $result = [];

        foreach ($suppliers as $supplier) {
            $answers = $profiles->get($supplier->id)?->answers();
            $facts = SupplierProfilePredicates::evaluate($supplier, $answers);
            $uncertain = SupplierProfilePredicates::uncertain($supplier, $answers);

            $result[(int) $supplier->id] = $requirements
                ->filter(fn (SupplierControlRequirement $requirement): bool => (int) $requirement->customer_id === (int) $supplier->customer_id
                    && ($requirement->supplier_id === null || (int) $requirement->supplier_id === (int) $supplier->id))
                ->map(fn (SupplierControlRequirement $requirement): array => self::decide(
                    $requirement,
                    $supplier,
                    $facts,
                    $uncertain,
                    $overrides[$supplier->id.':'.$requirement->id] ?? null,
                ))
                ->values()
                ->all();
        }

        return $result;
    }

    /**
     * The override in force for each (supplier, requirement): the latest row by created_at, then id.
     *
     * @param  list<int>  $supplierIds
     * @return array<string, SupplierRequirementOverride>
     */
    public function latestOverrides(array $supplierIds): array
    {
        $latest = [];

        SupplierRequirementOverride::query()
            ->with('createdBy:id,name')
            ->whereIn('supplier_id', $supplierIds)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->each(function (SupplierRequirementOverride $override) use (&$latest): void {
                $latest[$override->supplier_id.':'.$override->requirement_id] = $override;
            });

        return $latest;
    }

    /**
     * The rule's groups whose predicates all hold, in stored order, each predicate marked when it
     * holds only because an answer is «Ikke avklart» or not answered.
     *
     * @param  list<list<string>>  $rule
     * @param  list<string>  $facts
     * @param  list<string>  $uncertain
     * @return list<list<array{predicate: string, uncertain: bool}>>
     */
    public static function holdingGroups(array $rule, array $facts, array $uncertain): array
    {
        $holding = [];

        foreach ($rule as $group) {
            $group = array_values((array) $group);

            if ($group !== [] && array_diff($group, $facts) === []) {
                $holding[] = array_map(fn (string $predicate): array => [
                    'predicate' => $predicate,
                    'uncertain' => in_array($predicate, $uncertain, true),
                ], $group);
            }
        }

        return $holding;
    }
}
