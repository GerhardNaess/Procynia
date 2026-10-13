<?php

namespace Tests\Unit\Services\Suppliers;

use App\Models\Supplier;
use App\Models\SupplierProfile;
use App\Services\Suppliers\Assurance\SupplierProfilePredicates;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * The decision table (2026-10-13): required on clear answers, must_clarify when an unclear answer
     * could still make it required, not_required when every criterion has a clear no. Columns:
     * high-risk categories, outside the EEA, labour-intensive, subcontractors.
     *
     * @return array<string, array{0: array<string, mixed>|null, 1: string, 2: list<string>}>
     */
    public static function dueDiligenceProfiles(): array
    {
        $row = fn (mixed $highRisk, ?string $outside, ?string $labour, ?string $subcontractors): array => [
            'high_risk_categories' => $highRisk,
            'production_outside_eea' => $outside,
            'labour_intensive' => $labour,
            'uses_subcontractors' => $subcontractors,
        ];

        return [
            'category · no · no · no' => [$row(['textiles'], 'no', 'no', 'no'), 'required', []],
            'none · yes · no · no' => [$row([], 'yes', 'no', 'no'), 'required', []],
            'none · no · yes · yes' => [$row([], 'no', 'yes', 'yes'), 'required', []],
            'none · no · no · yes' => [$row([], 'no', 'no', 'yes'), 'not_required', []],
            'none · no · unclear · no' => [$row([], 'no', 'unknown', 'no'), 'not_required', []],
            'none · no · unclear · yes' => [$row([], 'no', 'unknown', 'yes'), 'must_clarify', ['labour_intensive']],
            'unclear · no · no · no' => [$row(['unknown'], 'no', 'no', 'no'), 'must_clarify', ['high_risk_categories']],
            'none · unclear · no · no' => [$row([], 'unknown', 'no', 'no'), 'must_clarify', ['production_outside_eea']],
            'unclear · yes · unclear · unclear' => [$row(['unknown'], 'yes', 'unknown', 'unknown'), 'required', []],
            // Not answered is as unclear as «Ikke avklart» — for the list too: null is not «Ingen».
            'categories not answered' => [$row(null, 'no', 'no', 'no'), 'must_clarify', ['high_risk_categories']],
            'outside the EEA not answered' => [$row([], null, 'no', 'no'), 'must_clarify', ['production_outside_eea']],
            'both halves of the pair unclear' => [$row([], 'no', 'unknown', null), 'must_clarify', ['labour_intensive', 'uses_subcontractors']],
            // A wholly empty profile has nothing to conclude «not required» from.
            'no profile' => [null, 'must_clarify', ['high_risk_categories', 'production_outside_eea', 'labour_intensive', 'uses_subcontractors']],
            'nothing answered' => [[], 'must_clarify', ['high_risk_categories', 'production_outside_eea', 'labour_intensive', 'uses_subcontractors']],
            // Questions that cannot trigger it need no answer: only the four are read.
            'only the four answered' => [$row([], 'no', 'no', 'no'), 'not_required', []],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $answers
     * @param  list<string>  $unresolved
     */
    #[DataProvider('dueDiligenceProfiles')]
    public function test_the_due_diligence_status_is_one_of_three_by_the_decision_table(?array $answers, string $status, array $unresolved): void
    {
        $result = SupplierProfilePredicates::dueDiligenceStatus($answers === null ? null : $this->answers($answers));

        $this->assertSame($status, $result['status']);
        $this->assertSame($unresolved, $result['unresolved']);
        $this->assertSame($status === 'required', $result['groups'] !== []);
        // Not a predicate a requirement's rule may use.
        $this->assertNotContains('due_diligence_relevant', SupplierProfilePredicates::all());
    }

    public function test_a_confirmed_criterion_is_required_whatever_else_is_unclear_and_a_clear_no_moves_must_clarify_to_not_required(): void
    {
        $status = fn (array $answers): string => SupplierProfilePredicates::dueDiligenceStatus($this->answers($answers))['status'];
        $unclear = ['high_risk_categories' => ['unknown'], 'production_outside_eea' => 'unknown', 'labour_intensive' => 'unknown', 'uses_subcontractors' => 'unknown'];

        $this->assertSame('must_clarify', $status($unclear));
        foreach ([['high_risk_categories' => ['electronics']], ['production_outside_eea' => 'yes'], ['labour_intensive' => 'yes', 'uses_subcontractors' => 'yes']] as $confirmed) {
            $this->assertSame('required', $status($confirmed + $unclear), json_encode($confirmed));
        }

        // Clarified one by one as no: must_clarify until the last criterion is ruled out.
        $answers = $unclear;
        foreach ([['high_risk_categories' => []], ['production_outside_eea' => 'no']] as $step) {
            $answers = $step + $answers;
            $this->assertSame('must_clarify', $status($answers));
        }
        $this->assertSame('not_required', $status(['uses_subcontractors' => 'no'] + $answers), 'labour intensity left unclear does not matter');
    }

    public function test_high_risk_categories_unclear_holds_for_requirements_as_doubt_and_none_does_not(): void
    {
        $supplier = $this->supplier(['criticality' => 'standard']);
        $answers = fn (mixed $categories): array => $this->answers(['high_risk_categories' => $categories, 'labour_intensive' => 'no']);

        $this->assertContains('high_risk_products', SupplierProfilePredicates::evaluate($supplier, $answers(['textiles'])));
        $this->assertNotContains('high_risk_products', SupplierProfilePredicates::uncertain($supplier, $answers(['textiles'])));
        // «Ikke avklart» counts as doubt, as for the yes/no questions — and says so.
        $this->assertContains('high_risk_products', SupplierProfilePredicates::evaluate($supplier, $answers(['unknown'])));
        $this->assertContains('high_risk_products', SupplierProfilePredicates::uncertain($supplier, $answers(['unknown'])));
        // «Ingen av disse» and not answered hold nothing for requirements, as before.
        $this->assertNotContains('high_risk_products', SupplierProfilePredicates::evaluate($supplier, $answers([])));
        $this->assertNotContains('high_risk_products', SupplierProfilePredicates::evaluate($supplier, $answers(null)));
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
