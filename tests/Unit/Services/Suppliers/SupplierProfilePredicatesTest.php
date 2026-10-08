<?php

namespace Tests\Unit\Services\Suppliers;

use App\Models\Supplier;
use App\Models\SupplierProfile;
use App\Services\Suppliers\Assurance\SupplierProfilePredicates;
use PHPUnit\Framework\TestCase;

/**
 * The fixed predicates control requirements will be applied by (docs/supplier-assurance-v2-plan.md
 * §5.3): «vet ikke» and an unanswered question count as the fact holding, «nei» does not; a wholly
 * empty profile gives only the criticality facts; a question not asked for the supplier never holds.
 */
class SupplierProfilePredicatesTest extends TestCase
{
    public function test_unknown_and_unanswered_hold_and_no_does_not(): void
    {
        $supplier = $this->supplier(['processes_personal_data' => true, 'has_system_access' => true]);

        foreach (['yes' => true, 'unknown' => true, null => true, 'no' => false] as $answer => $holds) {
            $answer = $answer === '' ? null : $answer;
            $facts = SupplierProfilePredicates::evaluate($supplier, $this->answers([
                'data_role' => 'controller',
                'stores_our_data' => $answer,
                'uses_subcontractors' => $answer,
                'privileged_access' => $answer,
                // Something else answered, so the profile is not empty.
                'labour_intensive' => 'no',
            ]));

            foreach (['stores_our_data', 'subcontractors', 'privileged_access'] as $predicate) {
                $this->assertSame($holds, in_array($predicate, $facts, true), "{$predicate} with ".var_export($answer, true));
            }
        }
    }

    public function test_the_processor_and_data_location_facts_follow_the_role_and_place(): void
    {
        $supplier = $this->supplier(['processes_personal_data' => true]);
        $facts = fn (array $answers): array => SupplierProfilePredicates::evaluate($supplier, $this->answers($answers + ['labour_intensive' => 'no']));

        $this->assertContains('processor', $facts(['data_role' => 'processor']));
        $this->assertContains('processor', $facts(['data_role' => 'unknown']));
        $this->assertContains('processor', $facts(['data_role' => null]));
        $this->assertNotContains('processor', $facts(['data_role' => 'controller']));

        $this->assertContains('data_outside_eea', $facts(['stores_our_data' => 'yes', 'data_location' => 'outside_eea']));
        $this->assertContains('data_outside_eea', $facts(['stores_our_data' => 'yes', 'data_location' => 'unknown']));
        $this->assertNotContains('data_outside_eea', $facts(['stores_our_data' => 'yes', 'data_location' => 'eea']));
        $this->assertNotContains('data_outside_eea', $facts(['stores_our_data' => 'no', 'data_location' => null]));

        $this->assertSame(['high_risk_products', 'sector:construction', 'sector:cleaning'], array_values(array_intersect(
            $facts(['high_risk_categories' => ['textiles'], 'sectors' => ['cleaning', 'construction']]),
            ['high_risk_products', ...array_map(fn (string $s): string => 'sector:'.$s, SupplierProfile::SECTORS)],
        )));
        // A list has no unknown: nothing chosen, or not answered, is not the fact.
        $this->assertNotContains('high_risk_products', $facts(['high_risk_categories' => []]));
        $this->assertNotContains('high_risk_products', $facts(['high_risk_categories' => null]));
    }

    public function test_a_question_not_asked_for_the_supplier_never_holds(): void
    {
        // No personal data and no system access: the questions that depend on them are not asked.
        $supplier = $this->supplier(['processes_personal_data' => false, 'has_system_access' => false]);
        $facts = SupplierProfilePredicates::evaluate($supplier, $this->answers(['labour_intensive' => 'no']));

        foreach (['personal_data', 'processor', 'special_category_data', 'system_access', 'privileged_access'] as $predicate) {
            $this->assertNotContains($predicate, $facts, $predicate);
        }
    }

    public function test_a_wholly_empty_profile_gives_only_the_criticality_facts(): void
    {
        $supplier = $this->supplier([
            'criticality' => Supplier::CRITICALITY_CRITICAL,
            'processes_personal_data' => true,
            'has_system_access' => true,
            'supports_critical_delivery' => true,
            'hard_to_replace' => false,
        ]);
        $expected = ['personal_data', 'system_access', 'critical_delivery', 'criticality_important', 'criticality_critical'];

        $this->assertSame($expected, SupplierProfilePredicates::evaluate($supplier, null));
        $this->assertSame($expected, SupplierProfilePredicates::evaluate($supplier, SupplierProfile::emptyAnswers()));
        // One answer is enough for the unanswered questions to count as unknown.
        $this->assertContains('processor', SupplierProfilePredicates::evaluate($supplier, $this->answers(['on_site_work' => 'no'])));

        // A supplier not classified at all, without a profile, holds nothing.
        $this->assertSame([], SupplierProfilePredicates::evaluate($this->supplier([]), null));
    }

    public function test_every_predicate_name_is_in_the_fixed_list(): void
    {
        $all = SupplierProfilePredicates::all();

        $this->assertCount(count(SupplierProfilePredicates::PREDICATES) + count(SupplierProfile::SECTORS), $all);
        $this->assertSame([], array_diff(
            SupplierProfilePredicates::evaluate(
                $this->supplier(['criticality' => 'critical', 'processes_personal_data' => true, 'has_system_access' => true, 'supports_critical_delivery' => true, 'hard_to_replace' => true]),
                $this->answers(['high_risk_categories' => ['other'], 'sectors' => SupplierProfile::SECTORS]),
            ),
            $all,
        ));
    }

    /** @param  array<string, mixed>  $attributes */
    private function supplier(array $attributes): Supplier
    {
        return (new Supplier)->forceFill($attributes);
    }

    /**
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    private function answers(array $answers): array
    {
        return array_merge(SupplierProfile::emptyAnswers(), $answers);
    }
}
