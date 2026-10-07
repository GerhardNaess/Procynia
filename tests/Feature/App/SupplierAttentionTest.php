<?php

namespace Tests\Feature\App;

use App\Models\Supplier;
use App\Models\SupplierAssessment;
use App\Models\SupplierDocument;
use App\Services\Suppliers\SupplierAttentionService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesImprovementCaseScenarios;
use Tests\Concerns\CreatesSupplierScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Leverandøroppfølging phase 9: Trenger oppmerksomhet (docs/supplier-management-v1-plan.md §8). One
 * test per concern:
 *
 *  - each of the five rules hits and misses — Viktig/Kritisk without assessment, overdue from the
 *    day after, missing owner, expired and within 60 days, replaced rows ignored until their renewal
 *    is gone;
 *  - the register's panel and filter and the supplier page carry the same findings;
 *  - nothing from another customer, nothing without supplier.view, nothing for an ended supplier.
 */
class SupplierAttentionTest extends TestCase
{
    use CreatesImprovementCaseScenarios;
    use CreatesSupplierScenarios;
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->withoutMiddleware([VerifyCsrfToken::class, ValidateCsrfToken::class]);
        Carbon::setTestNow('2026-10-07 12:00:00');
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

    public function test_each_rule_hits_and_misses(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $reader = $this->supplierUser($customer, []);
        $critical = $this->supplier($customer, $reader, 'A Kritisk AS', classification: $this->classification(Supplier::CRITICALITY_CRITICAL, 12));
        $important = $this->supplier($customer, $reader, 'B Viktig AS', classification: $this->classification(Supplier::CRITICALITY_IMPORTANT, 6));
        $this->supplier($customer, $reader, 'C Standard AS', classification: $this->classification());
        $this->supplier($customer, $reader, 'D Under vurdering AS', Supplier::STATUS_ONBOARDING, $this->classification(Supplier::CRITICALITY_CRITICAL, 12));
        $overdue = $this->supplier($customer, $reader, 'E Forfalt AS', classification: $this->classification(Supplier::CRITICALITY_IMPORTANT, 6));
        $dueToday = $this->supplier($customer, $reader, 'F I dag AS', classification: $this->classification(Supplier::CRITICALITY_IMPORTANT, 6));
        $this->assess($overdue, '2026-04-06');
        $this->assess($dueToday, '2026-04-07');
        $ownerless = $this->supplier($customer, $reader, 'G Uten ansvarlig AS');
        $ownerless->forceFill(['owner_user_id' => null])->save();
        $documented = $this->supplier($customer, $reader, 'H Dokumentert AS');
        $this->document($documented, 'insurance_certificate', 'Forsikring 2025', '2026-10-06');
        $this->document($documented, 'certificate', 'ISO 27001', '2026-12-06');
        $this->document($documented, 'agreement', 'Rammeavtale', '2026-12-07');
        $this->document($documented, 'other', 'Uten utløp', null);
        $old = $this->document($documented, 'data_processing_agreement', 'DBA 2024', '2026-01-01');
        $renewal = $this->document($documented, 'data_processing_agreement', 'DBA 2026', '2028-01-01');
        $old->forceFill(['replaced_by_document_id' => $renewal->id])->save();

        $overview = app(SupplierAttentionService::class)->overview($reader);
        $this->assertSame(
            [
                'A Kritisk AS' => [['not_assessed', 'critical']],
                'B Viktig AS' => [['not_assessed', 'important']],
                'E Forfalt AS' => [['review_overdue', '2026-10-06']],
                'G Uten ansvarlig AS' => [['missing_owner', null]],
                // Yesterday is expired; the last day of the 60 is in, the 61st out; no date, never.
                'H Dokumentert AS' => [['document_expired', 'Forsikring 2025'], ['document_expiring', 'ISO 27001']],
            ],
            collect($overview['suppliers'])->mapWithKeys(fn (array $row): array => [$row['name'] => array_map(
                fn (array $finding): array => [$finding['key'], $finding['criticality'] ?? $finding['next_review_on'] ?? $finding['title'] ?? null],
                $row['findings'],
            )])->all(),
        );
        $this->assertSame(60, collect($overview['suppliers'])->firstWhere('name', 'H Dokumentert AS')['findings'][1]['days']);
        $this->assertSame(5, $overview['total']);
        $this->assertSame(
            ['not_assessed' => 2, 'review_overdue' => 1, 'missing_owner' => 1, 'document_expired' => 1, 'document_expiring' => 1],
            collect($overview['categories'])->pluck('count', 'key')->all(),
        );

        // Assessing clears «Ikke vurdert»; deleting the renewal makes the replaced row current again.
        $this->assess($critical, '2026-10-01');
        $renewal->delete();
        $old->refresh();
        $service = app(SupplierAttentionService::class);
        $this->assertSame([], $service->findingsForSupplier($critical->fresh()));
        $this->assertSame(['document_expired', 'document_expired', 'document_expiring'], array_column($service->findingsForSupplier($documented), 'key'));
        $this->assertSame(['not_assessed'], array_column($service->findingsForSupplier($important), 'key'));
    }

    public function test_the_register_panel_and_filter_and_the_supplier_page_carry_the_findings(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $reader = $this->supplierUser($customer, []);
        $flagged = $this->supplier($customer, $reader, 'Kritisk AS', classification: $this->classification(Supplier::CRITICALITY_CRITICAL, 12));
        $this->supplier($customer, $reader, 'Rolig AS');

        $page = $this->actingAs($reader)->get('/app/supplier-management')->assertOk()->viewData('page')['props'];
        $this->assertSame([1, ['Kritisk AS']], [$page['attention']['total'], array_column($page['attention']['suppliers'], 'name')]);
        $this->assertSame(['Kritisk AS', 'Rolig AS'], array_column($page['suppliers'], 'name'));

        $filtered = $this->actingAs($reader)->get('/app/supplier-management?attention=1')->viewData('page')['props'];
        $this->assertSame([['Kritisk AS'], true], [array_column($filtered['suppliers'], 'name'), $filtered['filters']['attention']]);

        $show = $this->actingAs($reader)->get("/app/supplier-management/{$flagged->id}")->viewData('page')['props'];
        $this->assertSame([['key' => 'not_assessed', 'criticality' => 'critical']], $show['attention']);
    }

    public function test_nothing_from_another_customer_without_supplier_view_or_for_an_ended_supplier(): void
    {
        ['customer' => $customer, 'owner' => $systemOwner] = $this->context('grc');
        ['customer' => $other] = $this->context('grc');
        $reader = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $this->supplier($other, $this->supplierUser($other, []), 'Fremmed Kritisk AS', classification: $this->classification(Supplier::CRITICALITY_CRITICAL, 12));
        $ended = $this->supplier($customer, $reader, 'Avsluttet AS', classification: $this->classification(Supplier::CRITICALITY_CRITICAL, 12));
        $this->document($ended, 'certificate', 'Utløpt sertifikat', '2026-01-01');
        $this->actingAs($reader)->post("/app/supplier-management/{$ended->id}/end", ['reason' => 'Avtalen er sagt opp.']);
        $ended->forceFill(['owner_user_id' => null])->save();

        $service = app(SupplierAttentionService::class);
        $this->assertSame(['total' => 0, 'categories' => [], 'suppliers' => []], $service->overview($reader));
        $this->assertSame([], $this->actingAs($reader)->get("/app/supplier-management/{$ended->id}")->viewData('page')['props']['attention']);

        // No supplier.view — a colleague, or a System Owner without a supplier role: nothing, and the
        // register itself is refused.
        $colleague = $this->member($customer);
        foreach ([$colleague, $systemOwner] as $person) {
            $this->assertSame(0, $service->overview($person)['total']);
            $this->actingAs($person)->get('/app/supplier-management')->assertForbidden();
        }
    }

    private function assess(Supplier $supplier, string $on): void
    {
        SupplierAssessment::query()->create([
            'customer_id' => $supplier->customer_id, 'supplier_id' => $supplier->id, 'assessed_on' => $on,
            'quality_rating' => 'good', 'delivery_rating' => 'good', 'security_rating' => 'good', 'compliance_rating' => 'good',
            'overall_result' => 'satisfactory', 'rationale' => 'Stabile leveranser.', 'supplier_name' => $supplier->name,
            'criticality' => $supplier->criticality, 'review_interval_months' => $supplier->review_interval_months, 'recorded_at' => now(),
        ]);
    }

    private function document(Supplier $supplier, string $type, string $title, ?string $validUntil): SupplierDocument
    {
        return SupplierDocument::query()->create([
            'customer_id' => $supplier->customer_id, 'supplier_id' => $supplier->id,
            'document_type' => $type, 'title' => $title, 'valid_until' => $validUntil,
        ]);
    }
}
