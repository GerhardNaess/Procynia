<?php

namespace Tests\Feature\App;

use App\Models\Supplier;
use App\Models\SupplierAssessment;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Concerns\CreatesImprovementCaseScenarios;
use Tests\Concerns\CreatesSupplierScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Leverandøroppfølging phase 4: supplier assessments (docs/supplier-management-v1-plan.md §4.3,
 * §6.3, §9.2, §10). One test per rule:
 *
 *  - an assessment stores the four criteria, the result the assessor chose, the begrunnelse, the
 *    actor and a snapshot of the criticality; a new one never changes an old one or the criticality;
 *  - Neste vurdering is the current assessment's day plus the supplier's CURRENT interval;
 *  - supplier.assess registers, supplier.edit alone does not and assess grants no editing; only an
 *    active supplier is assessed; another customer's supplier is 404 and cannot be named by a row;
 *  - assessments are immutable in the model and the database, and an assessed supplier is ended,
 *    never deleted.
 */
class SupplierAssessmentTest extends TestCase
{
    use CreatesImprovementCaseScenarios;
    use CreatesSupplierScenarios;
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->withoutMiddleware([VerifyCsrfToken::class, ValidateCsrfToken::class]);
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    public function test_an_assessment_keeps_its_criteria_result_reason_actor_and_criticality_snapshot_and_a_new_one_changes_nothing_old(): void
    {
        Carbon::setTestNow('2026-10-07 10:00:00');
        ['customer' => $customer] = $this->context('grc');
        $assessor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSESS]);
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $supplier = $this->supplier($customer, $editor, 'Drift AS', Supplier::STATUS_ACTIVE, $this->classification(Supplier::CRITICALITY_IMPORTANT, 24));
        $url = "/app/supplier-management/{$supplier->id}/assessments";

        // Each rule refused before anything is written: a criterion, the result, the reason, a future date.
        foreach ([
            'quality_rating' => ['quality_rating' => null],
            'overall_result' => ['overall_result' => 'great'],
            'rationale' => ['rationale' => '  '],
            'assessed_on' => ['assessed_on' => '2026-10-08'],
        ] as $field => $override) {
            $this->actingAs($assessor)->post($url, array_merge($this->assessmentPayload(), $override))->assertSessionHasErrors($field);
        }
        $this->assertFalse($supplier->assessments()->exists());

        // First assessment: everything kept as given; the result is the assessor's choice, not the criteria's.
        $this->actingAs($assessor)->post($url, $this->assessmentPayload([
            'quality_rating' => 'good', 'delivery_rating' => 'poor', 'security_rating' => 'acceptable', 'compliance_rating' => 'not_relevant',
            'overall_result' => 'satisfactory', 'assessed_on' => '2026-09-30',
        ]))->assertSessionHasNoErrors();
        $first = $supplier->assessments()->sole();
        $this->assertSame(
            ['good', 'poor', 'acceptable', 'not_relevant', 'satisfactory', '2026-09-30', 'Drift AS', 'important', 24, $assessor->id, '2026-10-07 10:00:00'],
            [$first->quality_rating, $first->delivery_rating, $first->security_rating, $first->compliance_rating, $first->overall_result,
                $first->assessed_on->toDateString(), $first->supplier_name, $first->criticality, $first->review_interval_months,
                (int) $first->assessed_by_user_id, $first->recorded_at->toDateTimeString()],
        );

        // The criticality changes; the next assessment snapshots the new one, and neither touches the other.
        $this->actingAs($editor)->post("/app/supplier-management/{$supplier->id}/criticality", $this->classification(Supplier::CRITICALITY_CRITICAL, 12) + ['reason' => 'Kjernesystem.'])
            ->assertSessionHasNoErrors();
        $this->actingAs($assessor)->post($url, $this->assessmentPayload(['overall_result' => 'partially_satisfactory', 'assessed_on' => '2026-10-07']))
            ->assertSessionHasNoErrors();
        $this->assertSame(2, $supplier->assessments()->count());
        $this->assertEquals($first->getAttributes(), SupplierAssessment::query()->find($first->id)->getAttributes());
        $this->assertSame(['critical', 12], [$supplier->assessments()->first()->criticality, $supplier->assessments()->first()->review_interval_months]);
        $this->assertSame('critical', $supplier->fresh()->criticality);

        $page = $this->actingAs($assessor)->get("/app/supplier-management/{$supplier->id}")->assertOk()->viewData('page')['props'];
        $this->assertSame(['partially_satisfactory', 'satisfactory'], array_column($page['assessments'], 'overall_result'));
        $this->assertSame([12, 24], array_column($page['assessments'], 'review_interval_months'));
        // Neste vurdering: the current assessment's day + the CURRENT interval (plan §4.3).
        $this->assertSame('2027-10-07', $page['supplier']['next_review_on']);

        // Changing the interval later moves it, counted from the assessment already made.
        $this->actingAs($editor)->post("/app/supplier-management/{$supplier->id}/criticality", ['review_interval_months' => 6] + $this->classification(Supplier::CRITICALITY_CRITICAL, 12) + ['reason' => 'Tettere oppfølging.'])
            ->assertSessionHasNoErrors();
        $register = $this->actingAs($assessor)->get('/app/supplier-management')->viewData('page')['props']['suppliers'];
        $this->assertSame([['2026-10-07', '2027-04-07']], array_map(fn (array $row): array => [$row['last_assessed_on'], $row['next_review_on']], $register));
    }

    public function test_assess_registers_edit_alone_does_not_only_an_active_supplier_is_assessed_and_other_customers_are_404(): void
    {
        ['customer' => $customer] = $this->context('grc');
        ['customer' => $other] = $this->context('grc');
        $owner = $this->supplierUser($customer, []);
        $supplier = $this->supplier($customer, $owner, 'Aktiv AS');
        $url = "/app/supplier-management/{$supplier->id}/assessments";

        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT, CustomerPermissionCatalog::SUPPLIER_DELETE]);
        $this->actingAs($editor)->post($url, $this->assessmentPayload())->assertForbidden();
        $this->assertFalse($this->actingAs($editor)->get("/app/supplier-management/{$supplier->id}")->viewData('page')['props']['permissions']['can_assess']);
        $withoutView = $this->member($customer);
        $this->grantAll($customer, $withoutView, [CustomerPermissionCatalog::SUPPLIER_ASSESS]);
        $this->actingAs($withoutView)->post($url, $this->assessmentPayload())->assertForbidden();

        // supplier.assess assesses, and does nothing else.
        $assessor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSESS]);
        $this->actingAs($assessor)->patch("/app/supplier-management/{$supplier->id}", $this->supplierPayload($owner))->assertForbidden();
        $this->actingAs($assessor)->post($url, $this->assessmentPayload())->assertSessionHasNoErrors();

        // Under vurdering is not in use yet; Avsluttet is read-only. The history stays readable.
        $onboarding = $this->supplier($customer, $owner, 'Kandidat AS', Supplier::STATUS_ONBOARDING);
        $this->actingAs($assessor)->post("/app/supplier-management/{$onboarding->id}/assessments", $this->assessmentPayload())->assertSessionHasErrors('overall_result');
        $this->actingAs($editor)->post("/app/supplier-management/{$supplier->id}/end", ['reason' => 'Avtalen er sagt opp.'])->assertSessionHasNoErrors();
        $this->actingAs($assessor)->post($url, $this->assessmentPayload())->assertSessionHasErrors('overall_result');
        $page = $this->actingAs($assessor)->get("/app/supplier-management/{$supplier->id}")->viewData('page')['props'];
        $this->assertSame([false, true], [$page['permissions']['can_assess'], $page['permissions']['has_assess_right']]);
        $this->assertCount(1, $page['assessments']);
        $this->assertFalse($onboarding->assessments()->exists());

        // Another customer's supplier: 404, and no row can name it.
        $foreign = $this->supplier($other, $this->supplierUser($other, []), 'Fremmed AS');
        $this->actingAs($assessor)->post("/app/supplier-management/{$foreign->id}/assessments", $this->assessmentPayload())->assertNotFound();
        $this->assertFalse($foreign->assessments()->exists());
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_assessments')->insert([
            'customer_id' => $customer->id, 'supplier_id' => $foreign->id, 'assessed_on' => '2026-10-01',
            'quality_rating' => 'good', 'delivery_rating' => 'good', 'security_rating' => 'good', 'compliance_rating' => 'good',
            'overall_result' => 'satisfactory', 'rationale' => 'x', 'supplier_name' => 'Fremmed AS', 'recorded_at' => now(),
        ]), 'an assessment across customers');
    }

    public function test_assessments_are_immutable_and_an_assessed_supplier_is_ended_never_deleted(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $assessor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSESS, CustomerPermissionCatalog::SUPPLIER_DELETE]);
        $supplier = $this->supplier($customer, $assessor, 'Vurdert AS');
        $this->assertTrue($supplier->isDeletable());
        $this->actingAs($assessor)->post("/app/supplier-management/{$supplier->id}/assessments", $this->assessmentPayload())->assertSessionHasNoErrors();
        $assessment = $supplier->assessments()->sole();

        foreach (['update' => fn () => $assessment->update(['rationale' => 'Endret']), 'delete' => fn () => $assessment->delete()] as $what => $write) {
            try {
                $write();
                $this->fail("The model must refuse to {$what} an assessment.");
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        $row = DB::table('supplier_assessments')->where('id', $assessment->id);
        $this->assertDatabaseRefuses(fn () => (clone $row)->update(['overall_result' => 'unsatisfactory']), 'changing the result');
        $this->assertDatabaseRefuses(fn () => (clone $row)->update(['quality_rating' => 'poor']), 'changing a criterion');
        $this->assertDatabaseRefuses(fn () => (clone $row)->update(['criticality' => 'critical']), 'changing the snapshot');
        $this->assertDatabaseRefuses(fn () => (clone $row)->delete(), 'deleting an assessment');

        $this->assertFalse($supplier->fresh()->isDeletable());
        $this->actingAs($assessor)->delete("/app/supplier-management/{$supplier->id}")->assertSessionHas('error');
        $this->assertDatabaseRefuses(fn () => DB::table('suppliers')->where('id', $supplier->id)->delete(), 'deleting an assessed supplier');

        // The one update the database allows: the assessor's account going. The row stays.
        $assessor->delete();
        $this->assertNull(DB::table('supplier_assessments')->where('id', $assessment->id)->value('assessed_by_user_id'));
    }

    /** @return array<string, mixed> */
    private function assessmentPayload(array $overrides = []): array
    {
        return array_merge([
            'quality_rating' => 'good',
            'delivery_rating' => 'acceptable',
            'security_rating' => 'good',
            'compliance_rating' => 'good',
            'overall_result' => 'satisfactory',
            'rationale' => 'Leveransene har vært stabile, men flere supporthenvendelser har overskredet avtalt responstid.',
            'assessed_on' => now()->toDateString(),
        ], $overrides);
    }
}
