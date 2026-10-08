<?php

namespace Tests\Unit\Services\Suppliers;

use App\Models\SupplierDocument;
use App\Models\SupplierRequirementEvaluation;
use App\Models\SupplierRequirementEvaluationDocument;
use App\Services\Suppliers\Assurance\SupplierRequirementEvaluationService;
use App\Services\Suppliers\Assurance\SupplierRequirementStatus as Status;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Visningsstatus per requirement (docs/supplier-assurance-v2-plan.md §8.2), the control in force,
 * and which requirements a renewed document can confirm again (§10.3) — pure, one row per rule.
 * The current document row decides «Må fornyes»; the control's own snapshot never does.
 */
class SupplierRequirementStatusTest extends TestCase
{
    private const TODAY = '2026-10-08';

    /** @return array<string, array{0: array<string, mixed>|null, 1: int|null, 2: list<array<string, mixed>>, 3: string}> */
    public static function statuses(): array
    {
        $documented = ['status' => 'documented', 'evaluated_on' => '2026-01-10'];

        return [
            'no control' => [null, null, [], Status::NOT_EVALUATED],
            'documented, valid document' => [$documented, null, [['valid_until' => '2027-01-01']], 'documented'],
            'partially documented without documents' => [['status' => 'partially_documented', 'evaluated_on' => '2026-01-10'], null, [], 'partially_documented'],
            'missing stays missing' => [['status' => 'missing', 'evaluated_on' => '2020-01-10'], 3, [], 'missing'],
            'document expired now' => [$documented, null, [['valid_until' => '2026-10-07']], Status::RENEWAL_DUE],
            'document expires today: still valid' => [$documented, null, [['valid_until' => self::TODAY]], 'documented'],
            'document replaced now' => [$documented, null, [['replaced_by_document_id' => 99]], Status::RENEWAL_DUE],
            'interval run out yesterday' => [['status' => 'documented', 'evaluated_on' => '2026-04-07'], 6, [], Status::RENEWAL_DUE],
            'interval runs out today: not yet' => [['status' => 'documented', 'evaluated_on' => '2026-04-08'], 6, [], 'documented'],
            'accepted until tomorrow' => [['status' => 'temporarily_accepted', 'evaluated_on' => '2026-09-01', 'accepted_until' => '2026-10-09'], null, [], 'temporarily_accepted'],
            'accepted until today' => [['status' => 'temporarily_accepted', 'evaluated_on' => '2026-09-01', 'accepted_until' => self::TODAY], null, [], 'temporarily_accepted'],
            'acceptance expired' => [['status' => 'temporarily_accepted', 'evaluated_on' => '2026-09-01', 'accepted_until' => '2026-10-07'], 12, [], Status::ACCEPTANCE_EXPIRED],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $control
     * @param  list<array<string, mixed>>  $documents
     */
    #[DataProvider('statuses')]
    public function test_the_display_status(?array $control, ?int $interval, array $documents, string $expected): void
    {
        $now = array_map(fn (array $attributes): SupplierDocument => (new SupplierDocument)->setRawAttributes($attributes + ['valid_until' => null, 'replaced_by_document_id' => null]), $documents);

        $this->assertSame($expected, Status::display($control === null ? null : $this->control($control), $interval, $now, CarbonImmutable::parse(self::TODAY)));
    }

    public function test_the_control_in_force_is_the_latest_control_date_then_the_highest_id(): void
    {
        $older = $this->control(['id' => 9, 'status' => 'missing', 'evaluated_on' => '2026-05-01']);
        $backdated = $this->control(['id' => 7, 'status' => 'documented', 'evaluated_on' => '2026-06-01']);
        $sameDay = $this->control(['id' => 8, 'status' => 'partially_documented', 'evaluated_on' => '2026-06-01']);

        $this->assertSame(8, Status::current([$older, $backdated, $sameDay])->id);
        $this->assertNull(Status::current([]));
    }

    public function test_a_renewed_document_offers_the_requirements_resting_on_an_earlier_edition(): void
    {
        $documents = collect([
            1 => (new SupplierDocument)->forceFill(['id' => 1, 'replaced_by_document_id' => 2]),
            2 => (new SupplierDocument)->forceFill(['id' => 2, 'replaced_by_document_id' => 3]),
            3 => (new SupplierDocument)->forceFill(['id' => 3, 'replaced_by_document_id' => null]),
            4 => (new SupplierDocument)->forceFill(['id' => 4, 'replaced_by_document_id' => null]),
        ]);
        $with = fn (array $control, array $ids): SupplierRequirementEvaluation => $this->control($control)->setRelation('documents', collect(array_map(
            fn (int $id): SupplierRequirementEvaluationDocument => (new SupplierRequirementEvaluationDocument)->forceFill(['supplier_document_id' => $id]),
            $ids,
        )));

        $offers = SupplierRequirementEvaluationService::reconfirmable([
            // Two editions back: offered on the latest one.
            10 => $with(['status' => 'documented', 'evaluated_on' => '2026-01-01'], [1, 4]),
            11 => $with(['status' => 'partially_documented', 'evaluated_on' => '2026-01-01'], [2]),
            // Already names the latest edition, or names nothing renewed.
            12 => $with(['status' => 'documented', 'evaluated_on' => '2026-01-01'], [2, 3]),
            13 => $with(['status' => 'documented', 'evaluated_on' => '2026-01-01'], [4]),
            // Missing and accepted are not confirmed again with a document.
            14 => $with(['status' => 'missing', 'evaluated_on' => '2026-01-01'], [1]),
            15 => $with(['status' => 'temporarily_accepted', 'evaluated_on' => '2026-01-01', 'accepted_until' => '2026-12-01'], [1]),
        ], $documents);

        $this->assertSame([3 => [10, 11]], $offers);
    }

    /** @param  array<string, mixed>  $attributes */
    private function control(array $attributes): SupplierRequirementEvaluation
    {
        $control = new SupplierRequirementEvaluation;
        $control->setRawAttributes($attributes + ['id' => 1, 'accepted_until' => null]);

        return $control;
    }
}
