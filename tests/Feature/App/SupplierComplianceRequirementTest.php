<?php

namespace Tests\Feature\App;

use App\Models\ComplianceAssessment;
use App\Models\ComplianceRequirement;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\SupplierComplianceRequirement;
use App\Models\User;
use App\Services\Suppliers\SupplierLifecycleService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\Concerns\CreatesComplianceScenarios;
use Tests\Concerns\CreatesImprovementCaseScenarios;
use Tests\Concerns\CreatesSupplierScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Tidligere koblinger til etterlevelseskrav (docs/supplier-management-v1-plan.md §7.3, retired from
 * the user flow 2026-10-13). The links made under v1 are kept and shown read-only under Historikk;
 * nothing adds or removes one any more. Rows are inserted directly here, as the data that exists.
 * One test per rule:
 *
 *  - the page reads reference, title and kravkilde now, in register order, never a compliance
 *    status; a requirement retired afterwards is marked as retired; also on an ended supplier;
 *  - no route writes a link — neither adding nor removing — whatever the person's rights;
 *  - someone who cannot read Etterlevelse og revisjon hears nothing about it on the supplier page;
 *  - links alone never block deleting: a supplier that may otherwise be deleted takes its links
 *    along, atomically, leaving the requirements and other suppliers' links; any other history still
 *    blocks, and then the links stay; a requirement deleted there takes the row; no row can cross
 *    customers.
 */
class SupplierComplianceRequirementTest extends TestCase
{
    use CreatesComplianceScenarios;
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
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    public function test_earlier_links_are_listed_read_only_without_any_compliance_status(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $manager = $this->requirementManager($customer);
        $supplier = $this->supplier($customer, $manager, 'Acme AS');
        $iso = $this->complianceSource($customer);
        $contract = $this->complianceSource($customer, 'Driftsavtale Acme', null);
        $access = $this->complianceRequirement($iso, 'Tilgangsstyring', reference: 'A.5.15');
        $backup = $this->complianceRequirement($contract, 'Sikkerhetskopi hver natt');
        $this->link($supplier, $access, $manager);
        $this->link($supplier, $backup, $manager);
        $url = "/app/supplier-management/{$supplier->id}";

        // Even with the requirement assessed and retired in Etterlevelse og revisjon, the supplier page
        // carries reference, title, kravkilde and the requirement's own lifecycle — never its status.
        ComplianceAssessment::query()->create([
            'customer_id' => $customer->id, 'requirement_id' => $access->id, 'result' => ComplianceAssessment::RESULT_COMPLIANT,
            'rationale' => 'Tilgang styres i IAM.', 'assessed_by_user_id' => $manager->id, 'assessed_at' => now(),
            'requirement_reference' => 'A.5.15', 'requirement_title' => 'Tilgangsstyring', 'requirement_text' => 'Kravtekst', 'source_name' => 'ISO 27001', 'source_version' => '2022',
        ]);
        $access->forceFill(['status' => ComplianceRequirement::STATUS_RETIRED])->save();
        $props = $this->actingAs($manager)->get($url)->assertOk()->viewData('page')['props'];
        $this->assertSame(
            [['Sikkerhetskopi hver natt', null, 'Driftsavtale Acme', false], ['Tilgangsstyring', 'A.5.15', 'ISO 27001 (2022)', true]],
            array_map(fn (array $row): array => [$row['title'], $row['reference'], $row['source_label'], $row['retired']], $props['earlier_compliance_links']),
        );
        $this->assertSame(['id', 'reference', 'title', 'source_label', 'retired', 'url'], array_keys($props['earlier_compliance_links'][0]));
        $this->assertSame(route('app.compliance.requirements.show', ['requirementId' => $backup->id]), $props['earlier_compliance_links'][0]['url']);
        // Nothing on the page offers to add or remove one.
        $this->assertArrayNotHasKey('requirement_linking', $props);
        $this->assertArrayNotHasKey('requirements', $props);

        // An ended supplier still shows them.
        $this->actingAs($manager)->post("{$url}/end", ['reason' => 'Avtalen er sagt opp.'])->assertSessionHasNoErrors();
        $props = $this->actingAs($manager)->get($url)->assertOk()->viewData('page')['props'];
        $this->assertSame(['Sikkerhetskopi hver natt', 'Tilgangsstyring'], array_column($props['earlier_compliance_links'], 'title'));
    }

    public function test_no_route_adds_or_removes_a_link_whatever_the_rights(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $manager = $this->requirementManager($customer, [CustomerPermissionCatalog::SUPPLIER_ASSURE, CustomerPermissionCatalog::SUPPLIER_DELETE]);
        $supplier = $this->supplier($customer, $manager, 'Acme AS');
        $kept = $this->complianceRequirement($this->complianceSource($customer), 'Tilgangsstyring', reference: 'A.5.15');
        $other = $this->complianceRequirement($this->complianceSource($customer, 'NSM'), 'Sikkerhetskopi');
        $link = $this->link($supplier, $kept, $manager);
        $url = "/app/supplier-management/{$supplier->id}/requirements";

        $this->assertContains($this->actingAs($manager)->post($url, ['requirement_id' => $other->id])->getStatusCode(), [404, 405]);
        $this->assertContains($this->actingAs($manager)->delete("{$url}/{$link->id}")->getStatusCode(), [404, 405]);
        $this->assertFalse(Route::has('app.supplier-management.requirements.link'));
        $this->assertFalse(Route::has('app.supplier-management.requirements.unlink'));
        $this->assertSame([(int) $kept->id], $supplier->requirementLinks()->pluck('compliance_requirement_id')->map(fn ($id): int => (int) $id)->all());
    }

    public function test_someone_who_cannot_read_compliance_hears_nothing_about_it_on_the_supplier_page(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $manager = $this->requirementManager($customer);
        $supplier = $this->supplier($customer, $manager, 'Acme AS');
        $requirement = $this->complianceRequirement($this->complianceSource($customer, 'Hemmelig kilde'), 'Skjult krav', reference: 'X.1');
        $this->link($supplier, $requirement, $manager);

        // A supplier reader without compliance.view, and a System Owner whose supplier role carries no
        // compliance key (explicit grant in both modules): no section, no count, no name.
        $reader = $this->supplierUser($customer, []);
        $systemOwner = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $systemOwner->forceFill(['bid_role' => User::BID_ROLE_SYSTEM_OWNER])->save();
        foreach ([$reader, $systemOwner->fresh()] as $person) {
            $response = $this->actingAs($person)->get("/app/supplier-management/{$supplier->id}")->assertOk();
            $this->assertNull($response->viewData('page')['props']['earlier_compliance_links']);
            foreach (['Skjult krav', 'Hemmelig kilde', 'X.1'] as $hidden) {
                $this->assertStringNotContainsString($hidden, $response->getContent());
            }
        }
    }

    public function test_links_alone_never_block_deleting_and_go_with_the_supplier_leaving_the_requirements(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $manager = $this->requirementManager($customer, [CustomerPermissionCatalog::SUPPLIER_DELETE]);
        $requirement = $this->complianceRequirement($this->complianceSource($customer), 'Registrert ved en feil');
        $plain = $this->supplier($customer, $manager, 'Uten koblinger AS');
        $supplier = $this->supplier($customer, $manager, 'Acme AS');
        $neighbour = $this->supplier($customer, $manager, 'Nabo AS');
        $this->link($supplier, $requirement, $manager);
        $neighbourLink = $this->link($neighbour, $requirement, $manager);

        // Without links, as before.
        $this->assertTrue($plain->fresh()->isDeletable());
        $this->actingAs($manager)->delete("/app/supplier-management/{$plain->id}")->assertRedirect(route('app.supplier-management.index'))->assertSessionHas('success');
        $this->assertNull(Supplier::query()->find($plain->id));

        // Links only: Slett is offered, and the link goes with the supplier.
        $this->assertTrue($supplier->fresh()->isDeletable());
        $this->assertTrue($this->actingAs($manager)->get("/app/supplier-management/{$supplier->id}")->viewData('page')['props']['permissions']['can_delete']);
        $this->actingAs($manager)->delete("/app/supplier-management/{$supplier->id}")->assertRedirect(route('app.supplier-management.index'))->assertSessionHas('success');
        $this->assertNull(Supplier::query()->find($supplier->id));
        $this->assertSame(0, SupplierComplianceRequirement::query()->where('supplier_id', $supplier->id)->count());

        // The requirement and another supplier's link to it are untouched.
        $this->assertNotNull(ComplianceRequirement::query()->find($requirement->id));
        $this->assertNotNull(SupplierComplianceRequirement::query()->find($neighbourLink->id));
        $this->assertSame(['Registrert ved en feil'], array_column($this->actingAs($manager)->get("/app/supplier-management/{$neighbour->id}")->viewData('page')['props']['earlier_compliance_links'], 'title'));
    }

    public function test_a_supplier_with_another_blocker_is_still_not_deleted_and_keeps_its_links(): void
    {
        ['customer' => $customer] = $this->context('grc');
        ['customer' => $foreignCustomer] = $this->context('grc');
        $manager = $this->requirementManager($customer, [CustomerPermissionCatalog::SUPPLIER_DELETE]);
        $requirement = $this->complianceRequirement($this->complianceSource($customer), 'Tilgangsstyring');
        $supplier = $this->supplier($customer, $manager, 'Acme AS');
        $this->link($supplier, $requirement, $manager);

        // No supplier.delete, or another customer: refused, nothing removed.
        $this->actingAs($this->requirementManager($customer))->delete("/app/supplier-management/{$supplier->id}")->assertForbidden();
        $foreign = $this->supplierUser($foreignCustomer, [CustomerPermissionCatalog::SUPPLIER_EDIT, CustomerPermissionCatalog::SUPPLIER_DELETE]);
        $this->actingAs($foreign)->delete("/app/supplier-management/{$supplier->id}")->assertNotFound();
        $this->assertSame(1, $supplier->requirementLinks()->count());

        // Ended: it has a history and is not deleted; the link stays, still read under Historikk.
        $this->actingAs($manager)->post("/app/supplier-management/{$supplier->id}/end", ['reason' => 'Avtalen er sagt opp.'])->assertSessionHas('success');
        $this->assertFalse($supplier->fresh()->isDeletable());
        $props = $this->actingAs($manager)->get("/app/supplier-management/{$supplier->id}")->viewData('page')['props'];
        $this->assertFalse($props['permissions']['can_delete']);
        $this->assertSame(['Tilgangsstyring'], array_column($props['earlier_compliance_links'], 'title'));
        $this->actingAs($manager)->delete("/app/supplier-management/{$supplier->id}")->assertSessionHas('error');
        $this->assertNotNull(Supplier::query()->find($supplier->id));
        $this->assertSame(1, $supplier->requirementLinks()->count());
        $this->assertDatabaseRefuses(fn () => DB::table('suppliers')->where('id', $supplier->id)->delete(), 'deleting a supplier with a history');

        // Reopened, unchanged: active again, link and history kept.
        $this->actingAs($manager)->post("/app/supplier-management/{$supplier->id}/reopen", ['reason' => 'Ny avtale.'])->assertSessionHas('success');
        $this->assertSame([Supplier::STATUS_ACTIVE, 1], [$supplier->fresh()->status, $supplier->requirementLinks()->count()]);
    }

    public function test_deleting_is_checked_again_inside_the_lock_and_rolls_back_as_a_whole(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $manager = $this->requirementManager($customer, [CustomerPermissionCatalog::SUPPLIER_DELETE]);
        $requirement = $this->complianceRequirement($this->complianceSource($customer), 'Tilgangsstyring');
        $lifecycle = app(SupplierLifecycleService::class);

        // A history written after the page was read: the locked row says no, nothing is removed.
        $stale = $this->supplier($customer, $manager, 'Gammel visning AS');
        $this->link($stale, $requirement, $manager);
        $this->assertTrue($stale->isDeletable());
        app(SupplierLifecycleService::class)->end(Supplier::query()->findOrFail($stale->id), $manager, 'Avsluttet i mellomtiden.');
        $this->assertFalse($lifecycle->delete($stale, $manager));
        $this->assertSame(1, $stale->requirementLinks()->count());

        // The supplier delete fails after its links are removed: both are rolled back.
        $supplier = $this->supplier($customer, $manager, 'Acme AS');
        $this->link($supplier, $requirement, $manager);
        Supplier::deleting(function (Supplier $deleting) use ($supplier): void {
            if ((int) $deleting->id === (int) $supplier->id) {
                throw new RuntimeException('Simulated failure.');
            }
        });
        try {
            $lifecycle->delete($supplier, $manager);
            $this->fail('The delete should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated failure.', $exception->getMessage());
        }
        $this->assertNotNull(Supplier::query()->find($supplier->id));
        $this->assertSame(1, $supplier->requirementLinks()->count());
    }

    public function test_no_link_can_cross_customers_and_a_deleted_requirement_takes_its_row_along(): void
    {
        ['customer' => $customer] = $this->context('grc');
        ['customer' => $foreignCustomer] = $this->context('grc');
        $manager = $this->requirementManager($customer, [CustomerPermissionCatalog::COMPLIANCE_DELETE]);
        $supplier = $this->supplier($customer, $manager, 'Acme AS');
        $requirement = $this->complianceRequirement($this->complianceSource($customer), 'Registrert ved en feil');
        $this->link($supplier, $requirement, $manager);

        $foreignRequirement = $this->complianceRequirement($this->complianceSource($foreignCustomer), 'Fremmed krav');
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_compliance_requirements')->insert([
            'customer_id' => $customer->id, 'supplier_id' => $supplier->id, 'compliance_requirement_id' => $foreignRequirement->id, 'created_at' => now(),
        ]), 'a link to another customer\'s requirement');

        // Etterlevelse og revisjon's own delete rule is unchanged: the requirement is deleted there
        // and takes the row with it.
        $this->actingAs($manager)->delete("/app/compliance/requirements/{$requirement->id}")->assertRedirect(route('app.compliance.requirements.index'));
        $this->assertFalse($supplier->requirementLinks()->exists());
    }

    /** A link as v1 wrote it; nothing in the application writes one any more. */
    private function link(Supplier $supplier, ComplianceRequirement $requirement, User $by): SupplierComplianceRequirement
    {
        return SupplierComplianceRequirement::query()->create([
            'customer_id' => (int) $supplier->customer_id,
            'supplier_id' => (int) $supplier->id,
            'compliance_requirement_id' => (int) $requirement->id,
            'created_by' => (int) $by->id,
        ]);
    }

    /**
     * supplier.view + supplier.edit and compliance.view (+ extra keys of either module).
     *
     * @param  list<string>  $extra
     */
    private function requirementManager(Customer $customer, array $extra = []): User
    {
        return $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT, CustomerPermissionCatalog::COMPLIANCE_VIEW, ...$extra]);
    }
}
