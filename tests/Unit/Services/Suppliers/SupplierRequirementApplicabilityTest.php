<?php

namespace Tests\Unit\Services\Suppliers;

use App\Models\Supplier;
use App\Models\SupplierControlRequirement;
use App\Models\SupplierProfile;
use App\Models\SupplierRequirementOverride;
use App\Services\Suppliers\Assurance\SupplierProfilePredicates;
use App\Services\Suppliers\Assurance\SupplierRequirementApplicability as Applicability;
use App\Services\Suppliers\Assurance\SupplierRequirementRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which control requirements apply to a supplier, and why (docs/supplier-assurance-v2-plan.md §5.2,
 * §5.3, §5.5) — the pure decision, one row per rule: the rule over the predicates, «vet ikke»
 * applying, the wholly empty profile, criticality scope, retired and supplier-specific
 * requirements, and the override in force over the rule, with mandatory requirements immune to
 * exclusion.
 */
class SupplierRequirementApplicabilityTest extends TestCase
{
    private const SUPPLIER_ID = 10;

    /** @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>|null, 2: array<string, mixed>, 3: string|null, 4: bool, 5: string}> */
    public static function decisions(): array
    {
        $personal = ['processes_personal_data' => true];
        $processor = ['data_role' => 'processor', 'labour_intensive' => 'no'];

        return [
            'no rule applies to everyone' => [[], null, ['applies_when' => []], null, true, Applicability::SOURCE_ALL_SUPPLIERS],
            'the rule holds' => [$personal, $processor, ['applies_when' => [['processor']]], null, true, Applicability::SOURCE_RULE],
            'the rule does not hold' => [$personal, ['data_role' => 'controller'], ['applies_when' => [['processor']]], null, false, Applicability::SOURCE_RULE_NOT_MET],
            'unknown applies' => [$personal, ['data_role' => 'unknown'], ['applies_when' => [['processor']]], null, true, Applicability::SOURCE_RULE],
            'no does not apply' => [[], ['uses_subcontractors' => 'no'], ['applies_when' => [['subcontractors']]], null, false, Applicability::SOURCE_RULE_NOT_MET],
            'a wholly empty profile only gives criticality facts' => [[], null, ['applies_when' => [['subcontractors']]], null, false, Applicability::SOURCE_RULE_NOT_MET],
            'a wholly empty profile still gives v1 facts' => [$personal, null, ['applies_when' => [['personal_data']]], null, true, Applicability::SOURCE_RULE],
            'every predicate of a group must hold' => [$personal, ['stores_our_data' => 'yes', 'data_location' => 'eea'], ['applies_when' => [['personal_data', 'data_outside_eea']]], null, false, Applicability::SOURCE_RULE_NOT_MET],
            'criticality scope keeps a standard supplier out' => [['criticality' => 'standard'], ['uses_subcontractors' => 'yes'], ['applies_when' => [['subcontractors', 'criticality_important']]], null, false, Applicability::SOURCE_RULE_NOT_MET],
            'criticality scope lets an important supplier in' => [['criticality' => 'important'], ['uses_subcontractors' => 'yes'], ['applies_when' => [['subcontractors', 'criticality_important']]], null, true, Applicability::SOURCE_RULE],
            'a retired requirement applies to no one' => [[], null, ['applies_when' => [], 'status' => 'retired'], null, false, Applicability::SOURCE_RETIRED],
            'a requirement for this supplier always applies' => [[], null, ['supplier_id' => self::SUPPLIER_ID], null, true, Applicability::SOURCE_SUPPLIER_SPECIFIC],
            'a requirement for another supplier never does' => [[], null, ['supplier_id' => self::SUPPLIER_ID + 1], null, false, Applicability::SOURCE_RULE_NOT_MET],
            'an override never touches a requirement for this supplier' => [[], null, ['supplier_id' => self::SUPPLIER_ID], 'exclude', true, Applicability::SOURCE_SUPPLIER_SPECIFIC],
            'include where the rule does not hold' => [[], ['uses_subcontractors' => 'no'], ['applies_when' => [['subcontractors']]], 'include', true, Applicability::SOURCE_MANUAL_INCLUDE],
            'include stays the reason when the rule holds too' => [[], ['uses_subcontractors' => 'yes'], ['applies_when' => [['subcontractors']]], 'include', true, Applicability::SOURCE_MANUAL_INCLUDE],
            'exclude where the rule holds' => [[], ['uses_subcontractors' => 'yes'], ['applies_when' => [['subcontractors']]], 'exclude', false, Applicability::SOURCE_MANUAL_EXCLUDE],
            'exclude has no effect on a mandatory requirement that applies' => [[], ['uses_subcontractors' => 'yes'], ['applies_when' => [['subcontractors']], 'level' => 'mandatory'], 'exclude', true, Applicability::SOURCE_RULE],
            'clear is back to the rule' => [[], ['uses_subcontractors' => 'no'], ['applies_when' => [['subcontractors']]], 'clear', false, Applicability::SOURCE_RULE_NOT_MET],
        ];
    }

    #[DataProvider('decisions')]
    public function test_the_decision(array $supplier, ?array $answers, array $requirement, ?string $override, bool $applies, string $source): void
    {
        $decision = $this->decide($supplier, $answers, $requirement, $override);

        $this->assertSame($applies, $decision['applies']);
        $this->assertSame($source, $decision['source']);
        $this->assertSame($override === 'exclude' && $applies && $source === Applicability::SOURCE_RULE, $decision['exclusion_ignored']);
        // clear is never «in force»; it only returns the decision to the rule.
        $this->assertSame(in_array($override, ['include', 'exclude'], true) && ($requirement['supplier_id'] ?? null) === null ? $override : null, $decision['override']?->action);
    }

    public function test_the_first_holding_group_is_the_reason_and_unclear_facts_are_marked(): void
    {
        $decision = $this->decide(
            ['processes_personal_data' => true],
            ['data_role' => 'processor', 'uses_subcontractors' => 'unknown', 'stores_our_data' => 'no'],
            ['applies_when' => [['stores_our_data'], ['subcontractors'], ['processor']]],
        );

        $this->assertSame([
            [['predicate' => 'subcontractors', 'uncertain' => true]],
            [['predicate' => 'processor', 'uncertain' => false]],
        ], $decision['groups']);
    }

    public function test_the_form_writes_and_reads_back_the_same_rule(): void
    {
        $rule = SupplierRequirementRule::fromForm('conditions', ['subcontractors', 'processor', 'nonsense'], 'important');

        $this->assertSame([['processor', 'criticality_important'], ['subcontractors', 'criticality_important']], $rule);
        $this->assertSame(['rule_mode' => 'conditions', 'conditions' => ['processor', 'subcontractors'], 'criticality_scope' => 'important'], SupplierRequirementRule::toForm($rule));
        $this->assertSame([['criticality_critical']], SupplierRequirementRule::fromForm('all', ['processor'], 'critical'));
        $this->assertSame([], SupplierRequirementRule::fromForm('all', [], ''));
        $this->assertNull(SupplierRequirementRule::toForm([['processor', 'subcontractors']]));

        $this->assertTrue(SupplierRequirementRule::isValid([['sector:ict', 'criticality_critical']]));
        $this->assertFalse(SupplierRequirementRule::isValid([['unknown_predicate']]));
        $this->assertFalse(SupplierRequirementRule::isValid([[]]));
        $this->assertFalse(SupplierRequirementRule::isValid(array_fill(0, 7, ['processor'])));
        $this->assertFalse(SupplierRequirementRule::isValid([['processor', 'personal_data', 'subcontractors', 'on_site_work', 'labour_intensive']]));
    }

    /** @return array<string, mixed> */
    private function decide(array $supplierAttributes, ?array $answers, array $requirementAttributes, ?string $overrideAction = null): array
    {
        $supplier = (new Supplier)->forceFill($supplierAttributes + [
            'id' => self::SUPPLIER_ID,
            'customer_id' => 1,
            'criticality' => 'standard',
            'processes_personal_data' => false,
            'has_system_access' => false,
            'supports_critical_delivery' => false,
            'hard_to_replace' => false,
        ]);

        $answers = $answers !== null ? $answers + SupplierProfile::emptyAnswers() : null;

        $requirement = (new SupplierControlRequirement)->forceFill($requirementAttributes + [
            'id' => 1,
            'customer_id' => 1,
            'title' => 'Krav',
            'level' => 'standard',
            'status' => 'active',
            'applies_when' => [],
            'supplier_id' => null,
        ]);

        $override = $overrideAction !== null ? (new SupplierRequirementOverride)->forceFill(['action' => $overrideAction, 'reason' => 'Fordi']) : null;

        return Applicability::decide(
            $requirement,
            $supplier,
            SupplierProfilePredicates::evaluate($supplier, $answers),
            SupplierProfilePredicates::uncertain($supplier, $answers),
            $override,
        );
    }
}
