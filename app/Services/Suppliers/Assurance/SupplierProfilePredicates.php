<?php

namespace App\Services\Suppliers\Assurance;

use App\Models\Supplier;
use App\Models\SupplierProfile;

/**
 * The fixed set of named facts about a supplier that control requirements will be applied by
 * (docs/supplier-assurance-v2-plan.md §5.3). Pure: a supplier and its profile in, the facts that
 * hold out, in the fixed order of PREDICATES. Reads nothing from the database and stores nothing.
 *
 * Not an expression language. A requirement's rule (phase 2) is a list of groups over these names;
 * this class only says which names are true.
 *
 * «VET IKKE» MEANS THE REQUIREMENT APPLIES. A yes/no/unknown answer counts when it is yes or unknown,
 * and a question not answered (null) counts as unknown — doubt goes in the direction of control.
 *
 * WITH ONE EXCEPTION: when the whole profile is empty (no profile, or nothing answered — every
 * supplier from before profiles existed), only the facts from the supplier's own criticality
 * answers and level hold. Otherwise a new requirement catalogue would give every old supplier every
 * requirement.
 *
 * A question the profile does not ask for this supplier (SupplierProfile::visibleFields()) is never
 * read as unknown: each conditional question's predicate also requires the fact it is asked on.
 *
 * The two lists have no unknown: a category or sector holds only when it was chosen.
 */
final class SupplierProfilePredicates
{
    /** In this order; sector:<code> follows, one per sector in SupplierProfile::SECTORS. */
    public const PREDICATES = [
        'personal_data',
        'processor',
        'special_category_data',
        'system_access',
        'privileged_access',
        'stores_our_data',
        'confidential_information',
        'data_outside_eea',
        'subcontractors',
        'production_outside_eea',
        'high_risk_products',
        'on_site_work',
        'labour_intensive',
        'public_contract_terms',
        'environmental_impact',
        'critical_delivery',
        'hard_to_replace',
        'criticality_important',
        'criticality_critical',
    ];

    public const SECTOR_PREFIX = 'sector:';

    /**
     * Every predicate name a rule may use.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            ...self::PREDICATES,
            ...array_map(fn (string $sector): string => self::SECTOR_PREFIX.$sector, SupplierProfile::SECTORS),
        ];
    }

    /**
     * The predicates that hold for the supplier.
     *
     * @return list<string>
     */
    public static function for(Supplier $supplier, ?SupplierProfile $profile): array
    {
        return self::evaluate($supplier, $profile?->answers());
    }

    /**
     * @param  array<string, mixed>|null  $answers  the profile's answers; null when there is no profile
     * @return list<string>
     */
    public static function evaluate(Supplier $supplier, ?array $answers): array
    {
        $personalData = $supplier->processes_personal_data === true;
        $systemAccess = $supplier->has_system_access === true;

        $facts = [
            'personal_data' => $personalData,
            'system_access' => $systemAccess,
            'critical_delivery' => $supplier->supports_critical_delivery === true,
            'hard_to_replace' => $supplier->hard_to_replace === true,
            'criticality_important' => in_array($supplier->criticality, [Supplier::CRITICALITY_IMPORTANT, Supplier::CRITICALITY_CRITICAL], true),
            'criticality_critical' => $supplier->criticality === Supplier::CRITICALITY_CRITICAL,
        ];

        if (self::isEmpty($answers)) {
            return self::holding($facts);
        }

        $applies = fn (string $field): bool => in_array($answers[$field] ?? null, [SupplierProfile::ANSWER_YES, SupplierProfile::ANSWER_UNKNOWN, null], true);
        $storesOurData = $applies('stores_our_data');
        $highRisk = $answers['high_risk_categories'] ?? null;
        $sectors = is_array($answers['sectors'] ?? null) ? $answers['sectors'] : [];

        $facts += [
            'processor' => $personalData && in_array($answers['data_role'] ?? null, ['processor', SupplierProfile::ANSWER_UNKNOWN, null], true),
            // Only asked when the supplier processes personal data (§4.2); a question never asked is
            // not «unknown», or a supplier without personal data would get these requirements (§12).
            'special_category_data' => $personalData && $applies('special_category_data'),
            'privileged_access' => $systemAccess && $applies('privileged_access'),
            'stores_our_data' => $storesOurData,
            'confidential_information' => $applies('confidential_information'),
            'data_outside_eea' => $storesOurData && in_array($answers['data_location'] ?? null, ['outside_eea', SupplierProfile::ANSWER_UNKNOWN, null], true),
            'subcontractors' => $applies('uses_subcontractors'),
            'production_outside_eea' => $applies('production_outside_eea'),
            'high_risk_products' => is_array($highRisk) && $highRisk !== [],
            'on_site_work' => $applies('on_site_work'),
            'labour_intensive' => $applies('labour_intensive'),
            'public_contract_terms' => $applies('public_contract_terms'),
            'environmental_impact' => $applies('significant_environmental_impact'),
        ];

        foreach (SupplierProfile::SECTORS as $sector) {
            $facts[self::SECTOR_PREFIX.$sector] = in_array($sector, $sectors, true);
        }

        return self::holding($facts);
    }

    /** No profile, or a profile where nothing is answered. */
    public static function isEmpty(?array $answers): bool
    {
        if ($answers === null) {
            return true;
        }

        foreach (SupplierProfile::fields() as $field) {
            if (($answers[$field] ?? null) !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, bool>  $facts
     * @return list<string>
     */
    private static function holding(array $facts): array
    {
        return array_values(array_filter(self::all(), fn (string $predicate): bool => $facts[$predicate] ?? false));
    }
}
