<?php

namespace Tests\Unit\Services\Suppliers;

use App\Models\SupplierDocument;
use App\Models\SupplierRequirementEvaluation;
use App\Services\Suppliers\Assurance\SupplierFollowUpPlan as Plan;
use App\Services\Suppliers\Assurance\SupplierRequirementStatus as Status;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Oppfølgingsplan (docs/supplier-assurance-v2-plan.md §14): when a control requirement falls due and
 * why — SupplierRequirementStatus::followUp(), the dates the visningsstatus is decided on — and the
 * plan built from them. Pure, one row per rule; the edges are the day before, the day itself and the
 * day after.
 */
class SupplierFollowUpPlanTest extends TestCase
{
    /**
     * @return array<string, array{0: array<string, mixed>|null, 1: int|null, 2: list<array<string, mixed>>, 3: string, 4: array<string, mixed>}>
     */
    public static function followUps(): array
    {
        $documented = ['status' => 'documented', 'evaluated_on' => '2026-04-08'];

        return [
            'never controlled: nothing scheduled' => [null, 12, [], '2026-10-08', ['next_control_on' => null, 'control_overdue' => false, 'accepted_until' => null]],
            'no interval: no control date, never overdue by time' => [['status' => 'documented', 'evaluated_on' => '2016-01-01'], null, [], '2026-10-08', ['next_control_on' => null, 'control_overdue' => false]],
            'interval: control date + months' => [$documented, 6, [], '2026-10-07', ['next_control_on' => '2026-10-08', 'control_overdue' => false]],
            'control due today: not overdue' => [$documented, 6, [], '2026-10-08', ['next_control_on' => '2026-10-08', 'control_overdue' => false]],
            'control overdue the day after' => [$documented, 6, [], '2026-10-09', ['next_control_on' => '2026-10-08', 'control_overdue' => true]],
            'no overflow: 31 Aug + 6 months is 28 Feb' => [['status' => 'partially_documented', 'evaluated_on' => '2025-08-31'], 6, [], '2026-01-01', ['next_control_on' => '2026-02-28']],
            'no overflow: 31 Jan + 3 months is 30 Apr' => [['status' => 'documented', 'evaluated_on' => '2026-01-31'], 3, [], '2026-01-31', ['next_control_on' => '2026-04-30']],
            'missing: open already, no date' => [['status' => 'missing', 'evaluated_on' => '2020-01-01'], 3, [], '2026-10-08', ['next_control_on' => null, 'control_overdue' => false]],
            'acceptance the day before' => [['status' => 'temporarily_accepted', 'evaluated_on' => '2026-09-01', 'accepted_until' => '2026-10-08'], 12, [], '2026-10-07', ['accepted_until' => '2026-10-08', 'acceptance_expired' => false, 'next_control_on' => null]],
            'acceptance on its last day' => [['status' => 'temporarily_accepted', 'evaluated_on' => '2026-09-01', 'accepted_until' => '2026-10-08'], 12, [], '2026-10-08', ['acceptance_expired' => false]],
            'acceptance expired the day after' => [['status' => 'temporarily_accepted', 'evaluated_on' => '2026-09-01', 'accepted_until' => '2026-10-08'], 12, [], '2026-10-09', ['acceptance_expired' => true]],
            'document the day before its expiry' => [$documented, null, [['id' => 1, 'valid_until' => '2026-10-08']], '2026-10-07', ['document_renewal_due' => false, 'document_valid_until' => '2026-10-08']],
            'document on its last valid day' => [$documented, null, [['id' => 1, 'valid_until' => '2026-10-08']], '2026-10-08', ['document_renewal_due' => false]],
            'document expired the day after' => [$documented, null, [['id' => 1, 'valid_until' => '2026-10-08']], '2026-10-09', ['document_renewal_due' => true]],
            'earliest of several documents' => [$documented, 24, [['id' => 1, 'valid_until' => '2027-05-01'], ['id' => 2, 'valid_until' => '2026-12-01'], ['id' => 3]], '2026-10-08', ['document_id' => 2, 'document_ids' => [1, 2, 3], 'next_control_on' => '2028-04-08']],
            'a replaced document comes first and is due now' => [$documented, null, [['id' => 1, 'valid_until' => '2026-11-01'], ['id' => 2, 'valid_until' => '2030-01-01', 'replaced_by_document_id' => 9]], '2026-10-08', ['document_id' => 2, 'document_renewal_due' => true]],
            'a document without date never needs renewing' => [$documented, null, [['id' => 1]], '2026-10-08', ['document_id' => null, 'document_renewal_due' => false]],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $control
     * @param  list<array<string, mixed>>  $documents
     * @param  array<string, mixed>  $expected
     */
    #[DataProvider('followUps')]
    public function test_follow_up_dates(?array $control, ?int $interval, array $documents, string $today, array $expected): void
    {
        $result = Status::followUp($control === null ? null : $this->control($control), $interval, array_map($this->document(...), $documents), CarbonImmutable::parse($today));

        foreach ($expected as $key => $value) {
            $actual = match ($key) {
                'document_id' => $result['document']['id'] ?? null,
                'document_valid_until' => $result['document']['valid_until'] ?? null,
                default => $result[$key],
            };
            $this->assertSame($value, $actual, $key);
        }
    }

    public function test_the_visningsstatus_follows_the_same_dates(): void
    {
        $control = $this->control(['status' => 'documented', 'evaluated_on' => '2026-04-08']);

        $this->assertSame('documented', Status::display($control, 6, [], CarbonImmutable::parse('2026-10-08')));
        $this->assertSame(Status::RENEWAL_DUE, Status::display($control, 6, [], CarbonImmutable::parse('2026-10-09')));
        $this->assertSame('documented', Status::display($control, null, [], CarbonImmutable::parse('2036-10-09')));
    }

    public function test_the_plan_lists_each_date_once_overdue_first_and_change_requirements_apart(): void
    {
        $today = CarbonImmutable::parse('2026-10-08');
        $basis = $this->document(['id' => 5, 'title' => 'ISO 27001', 'document_type' => 'certificate', 'valid_until' => '2026-12-01']);
        $requirements = [
            ['id' => 1, 'title' => 'Sikkerhetsrapport', 'level' => 'mandatory', 'control_point' => 'ongoing',
                'follow_up' => Status::followUp($this->control(['status' => 'documented', 'evaluated_on' => '2025-10-01']), 12, [$basis], $today)],
            ['id' => 2, 'title' => 'DBA', 'level' => 'mandatory', 'control_point' => 'before_contract',
                'follow_up' => Status::followUp($this->control(['status' => 'temporarily_accepted', 'evaluated_on' => '2026-09-01', 'accepted_until' => '2026-11-15']), null, [], $today)],
            ['id' => 3, 'title' => 'Underleverandører', 'level' => 'important', 'control_point' => 'on_change',
                'follow_up' => Status::followUp(null, null, [], $today)],
        ];
        $documents = [
            $basis,
            $this->document(['id' => 6, 'title' => 'Forsikring', 'document_type' => 'insurance_certificate', 'valid_until' => '2027-01-01']),
            $this->document(['id' => 7, 'title' => 'Gammel', 'document_type' => 'other', 'valid_until' => '2026-01-01', 'replaced_by_document_id' => 6]),
            $this->document(['id' => 8, 'title' => 'Uten utløp', 'document_type' => 'other']),
        ];

        $plan = Plan::build($requirements, $documents, CarbonImmutable::parse('2027-03-01'), $today);

        $this->assertSame([
            ['control', '2026-10-01', true, 1, null],
            ['acceptance', '2026-11-15', false, 2, null],
            ['document_renewal', '2026-12-01', false, 1, 5],
            ['document', '2027-01-01', false, null, 6],
            ['assessment', '2027-03-01', false, null, null],
        ], array_map(fn (array $entry): array => [$entry['kind'], $entry['date'], $entry['overdue'], $entry['requirement']['id'] ?? null, $entry['document']['id'] ?? null], $plan['entries']));
        $this->assertSame([['id' => 3, 'title' => 'Underleverandører']], $plan['on_change']);

        // Nothing applies, nothing documented, no assessment: an empty plan.
        $this->assertSame(['entries' => [], 'on_change' => []], Plan::build([], [], null, $today));
    }

    /** @param  array<string, mixed>  $attributes */
    private function control(array $attributes): SupplierRequirementEvaluation
    {
        return (new SupplierRequirementEvaluation)->setRawAttributes($attributes + ['id' => 1, 'accepted_until' => null]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function document(array $attributes): SupplierDocument
    {
        return (new SupplierDocument)->setRawAttributes($attributes + ['title' => 'Dokument', 'document_type' => 'other', 'valid_until' => null, 'replaced_by_document_id' => null]);
    }
}
