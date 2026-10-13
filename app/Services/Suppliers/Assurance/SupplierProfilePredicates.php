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
 * The two lists have no unknown by omission: a category or sector holds only when it was chosen —
 * or, for high_risk_categories, when the answer is «Ikke avklart» (['unknown']).
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
     * When an aktsomhetsvurdering is expected (docs/supplier-assurance-v2-plan.md §11.3):
     * high_risk_products ∨ production_outside_eea ∨ (labour_intensive ∧ subcontractors), as groups
     * over the predicates above. Not a predicate a requirement's rule may use: it decides signal 10,
     * «Aktsomhetsbehov må avklares» and what the Aktsomhet card says, nothing else —
     * dueDiligenceStatus() reads it.
     */
    public const DUE_DILIGENCE_RULE = [['high_risk_products'], ['production_outside_eea'], ['labour_intensive', 'subcontractors']];

    /** The profile question each predicate in DUE_DILIGENCE_RULE is read from. */
    public const DUE_DILIGENCE_FIELDS = [
        'high_risk_products' => 'high_risk_categories',
        'production_outside_eea' => 'production_outside_eea',
        'labour_intensive' => 'labour_intensive',
        'subcontractors' => 'uses_subcontractors',
    ];

    public const DUE_DILIGENCE_REQUIRED = 'required';

    public const DUE_DILIGENCE_MUST_CLARIFY = 'must_clarify';

    public const DUE_DILIGENCE_NOT_REQUIRED = 'not_required';

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
        return self::compute($supplier, $answers, false);
    }

    /**
     * The predicates that hold only because an answer is «Ikke avklart» or not answered — they would
     * not hold had the answer been a clear yes or no. «Gjelder fordi …» says so rather than stating
     * the fact as known (plan §2 pkt. 2–3).
     *
     * @param  array<string, mixed>|null  $answers
     * @return list<string>
     */
    public static function uncertain(Supplier $supplier, ?array $answers): array
    {
        return array_values(array_diff(self::compute($supplier, $answers, false), self::compute($supplier, $answers, true)));
    }

    /**
     * @param  array<string, mixed>|null  $answers
     * @param  bool  $certainOnly  only a clear answer counts, «Ikke avklart» and not answered do not
     * @return list<string>
     */
    private static function compute(Supplier $supplier, ?array $answers, bool $certainOnly): array
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

        $doubt = $certainOnly ? [] : [SupplierProfile::ANSWER_UNKNOWN, null];
        $applies = fn (string $field): bool => in_array($answers[$field] ?? null, [SupplierProfile::ANSWER_YES, ...$doubt], true);
        $storesOurData = $applies('stores_our_data');
        $highRisk = $answers['high_risk_categories'] ?? null;
        $sectors = is_array($answers['sectors'] ?? null) ? $answers['sectors'] : [];

        $facts += [
            'processor' => $personalData && in_array($answers['data_role'] ?? null, ['processor', ...$doubt], true),
            // Only asked when the supplier processes personal data (§4.2); a question never asked is
            // not «unknown», or a supplier without personal data would get these requirements (§12).
            'special_category_data' => $personalData && $applies('special_category_data'),
            'privileged_access' => $systemAccess && $applies('privileged_access'),
            'stores_our_data' => $storesOurData,
            'confidential_information' => $applies('confidential_information'),
            'data_outside_eea' => $storesOurData && in_array($answers['data_location'] ?? null, ['outside_eea', ...$doubt], true),
            'subcontractors' => $applies('uses_subcontractors'),
            'production_outside_eea' => $applies('production_outside_eea'),
            // A category chosen, or «Ikke avklart» (['unknown']) — doubt counts as for the yes/no
            // questions. Not answered (null) still holds nothing here: the lists have no unknown
            // by omission, only by answer.
            'high_risk_products' => is_array($highRisk) && $highRisk !== [] && ($certainOnly ? ! SupplierProfile::isListUnknown('high_risk_categories', $highRisk) : true),
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

    /**
     * Whether the profile makes an aktsomhetsvurdering required — three answers, never two, so
     * «not known» is never read as «not required» and never as «required» either:
     *
     *  - required: one group of DUE_DILIGENCE_RULE holds on clear answers only. It wins, whatever
     *    else is unclear.
     *  - must_clarify: no group holds for certain, but one could: each of its facts is either a
     *    clear yes or unclear (Ikke avklart, or not answered). Only those unclear facts are named —
     *    labour intensity left unclear next to a clear «no» to subcontractors changes nothing.
     *  - not_required: every group has a clear no. Questions that cannot change that need no answer.
     *
     * Each fact is read from the profile alone: yes is yes, no is no, anything else is unclear; for
     * the high-risk list a chosen category is yes, [] («Ingen av disse») no, and ['unknown'] or not
     * answered unclear. A wholly empty profile is therefore must_clarify — there is nothing to
     * conclude «not required» from. Nothing here reads the criticality answers, an assessment or its
     * conclusion: whether one is required is not whether one is done.
     *
     * Returns the status, the groups that hold for certain (required), and the profile questions to
     * clarify (must_clarify), in DUE_DILIGENCE_FIELDS order.
     *
     * @param  array<string, mixed>|null  $answers
     * @return array{status: string, groups: list<list<string>>, unresolved: list<string>}
     */
    public static function dueDiligenceStatus(?array $answers): array
    {
        $fact = function (string $predicate) use ($answers): ?bool {
            $field = self::DUE_DILIGENCE_FIELDS[$predicate];
            $value = $answers[$field] ?? null;

            if ($field === 'high_risk_categories') {
                if (! is_array($value) || SupplierProfile::isListUnknown($field, $value)) {
                    return null;
                }

                return $value !== [];
            }

            return match ($value) {
                SupplierProfile::ANSWER_YES => true,
                SupplierProfile::ANSWER_NO => false,
                default => null,
            };
        };

        $holding = [];
        $open = [];

        foreach (self::DUE_DILIGENCE_RULE as $group) {
            $values = array_map($fact, $group);

            if (in_array(false, $values, true)) {
                continue;
            }

            if (! in_array(null, $values, true)) {
                $holding[] = $group;

                continue;
            }

            foreach ($group as $index => $predicate) {
                if ($values[$index] === null) {
                    $open[] = $predicate;
                }
            }
        }

        if ($holding !== []) {
            return ['status' => self::DUE_DILIGENCE_REQUIRED, 'groups' => $holding, 'unresolved' => []];
        }

        if ($open !== []) {
            $fields = array_values(array_filter(
                self::DUE_DILIGENCE_FIELDS,
                fn (string $field, string $predicate): bool => in_array($predicate, $open, true),
                ARRAY_FILTER_USE_BOTH,
            ));

            return ['status' => self::DUE_DILIGENCE_MUST_CLARIFY, 'groups' => [], 'unresolved' => $fields];
        }

        return ['status' => self::DUE_DILIGENCE_NOT_REQUIRED, 'groups' => [], 'unresolved' => []];
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
