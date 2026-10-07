<?php

namespace Tests\Feature\App;

use App\Models\ComplianceAssessment;
use App\Models\ComplianceRequirement;
use App\Models\Customer;
use App\Models\SupplierComplianceRequirement;
use App\Models\User;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesComplianceScenarios;
use Tests\Concerns\CreatesImprovementCaseScenarios;
use Tests\Concerns\CreatesSupplierScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Leverandøroppfølging phase 8: Krav som gjelder leverandøren
 * (docs/supplier-management-v1-plan.md §7.3, §6.3, §6.4, §9.2). Only the integration — the
 * requirement's own rules are Etterlevelse og revisjon's tests. One test per rule:
 *
 *  - «Legg til krav» adds an active requirement the person can read; a supplier may have many and a
 *    requirement may apply to many suppliers; the page reads reference, title and kravkilde now and
 *    never a compliance status; a requirement retired afterwards stays listed, marked as retired;
 *  - adding and removing take supplier.edit and compliance.view; an ended supplier is read-only;
 *    another customer's supplier is 404 and its requirement is neither offered nor accepted;
 *  - someone who cannot read Etterlevelse og revisjon hears nothing about it on the supplier page;
 *  - a supplier with requirements is ended, never deleted; a requirement deleted there takes the row.
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

    public function test_requirements_are_added_and_listed_without_any_compliance_status(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $manager = $this->requirementManager($customer);
        $supplier = $this->supplier($customer, $manager, 'Acme AS');
        $other = $this->supplier($customer, $manager, 'Beta AS');
        $iso = $this->complianceSource($customer);
        $contract = $this->complianceSource($customer, 'Driftsavtale Acme', null);
        $access = $this->complianceRequirement($iso, 'Tilgangsstyring', reference: 'A.5.15');
        $backup = $this->complianceRequirement($contract, 'Sikkerhetskopi hver natt');
        $retired = $this->complianceRequirement($iso, 'Gammelt krav', reference: 'A.9.9');
        $retired->forceFill(['status' => ComplianceRequirement::STATUS_RETIRED])->save();
        $url = "/app/supplier-management/{$supplier->id}";

        // Offered: active requirements the person can read, in register order — not the retired one.
        $options = $this->actingAs($manager)->get($url)->assertOk()->viewData('page')['props']['requirement_linking']['link_options'];
        $this->assertSame(['Sikkerhetskopi hver natt', 'Tilgangsstyring'], array_column($options, 'title'));

        foreach ([$access, $backup] as $requirement) {
            $this->actingAs($manager)->post("{$url}/requirements", ['requirement_id' => $requirement->id])->assertSessionHas('success');
        }
        $this->actingAs($manager)->post("/app/supplier-management/{$other->id}/requirements", ['requirement_id' => $access->id])->assertSessionHasNoErrors();
        $this->assertSame(3, SupplierComplianceRequirement::query()->where('customer_id', $customer->id)->count());

        // Even with the requirement assessed in Etterlevelse og revisjon, the supplier page carries
        // reference, title, kravkilde and the requirement's own lifecycle — never its status.
        ComplianceAssessment::query()->create([
            'customer_id' => $customer->id, 'requirement_id' => $access->id, 'result' => ComplianceAssessment::RESULT_COMPLIANT,
            'rationale' => 'Tilgang styres i IAM.', 'assessed_by_user_id' => $manager->id, 'assessed_at' => now(),
            'requirement_reference' => 'A.5.15', 'requirement_title' => 'Tilgangsstyring', 'requirement_text' => 'Kravtekst', 'source_name' => 'ISO 27001', 'source_version' => '2022',
        ]);
        $access->forceFill(['status' => ComplianceRequirement::STATUS_RETIRED])->save();
        $page = $this->actingAs($manager)->get($url)->assertOk()->viewData('page')['props'];
        $this->assertSame(
            [['Sikkerhetskopi hver natt', null, 'Driftsavtale Acme', false], ['Tilgangsstyring', 'A.5.15', 'ISO 27001 (2022)', true]],
            array_map(fn (array $row): array => [$row['title'], $row['reference'], $row['source_label'], $row['retired']], $page['requirements']),
        );
        $this->assertSame(['link_id', 'id', 'reference', 'title', 'source_label', 'retired', 'url'], array_keys($page['requirements'][0]));
        $this->assertSame(route('app.compliance.requirements.show', ['requirementId' => $backup->id]), $page['requirements'][0]['url']);
        $this->assertSame([], $page['requirement_linking']['link_options']);
    }

    public function test_it_takes_supplier_edit_and_compliance_view_ended_is_read_only_and_other_customers_are_refused(): void
    {
        ['customer' => $customer] = $this->context('grc');
        ['customer' => $foreignCustomer] = $this->context('grc');
        $manager = $this->requirementManager($customer);
        $supplier = $this->supplier($customer, $manager, 'Acme AS');
        $requirement = $this->complianceRequirement($this->complianceSource($customer), 'Tilgangsstyring', reference: 'A.5.15');
        $retired = $this->complianceRequirement($this->complianceSource($customer, 'NSM'), 'Utgått krav');
        $retired->forceFill(['status' => ComplianceRequirement::STATUS_RETIRED])->save();
        $foreignRequirement = $this->complianceRequirement($this->complianceSource($foreignCustomer), 'Fremmed krav');
        $url = "/app/supplier-management/{$supplier->id}/requirements";

        // supplier.assess is not enough; supplier.edit without compliance.view is told nothing and refused.
        $assessor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSESS, CustomerPermissionCatalog::COMPLIANCE_VIEW]);
        $this->actingAs($assessor)->post($url, ['requirement_id' => $requirement->id])->assertForbidden();
        $editorOnly = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $page = $this->actingAs($editorOnly)->get("/app/supplier-management/{$supplier->id}")->viewData('page')['props'];
        $this->assertSame([null, null], [$page['requirements'], $page['requirement_linking']]);
        $this->actingAs($editorOnly)->post($url, ['requirement_id' => $requirement->id])->assertSessionHasErrors('requirement_id');

        // Only an active requirement of the person's own customer, once.
        foreach ([$retired, $foreignRequirement] as $refused) {
            $this->actingAs($manager)->post($url, ['requirement_id' => $refused->id])->assertSessionHasErrors('requirement_id');
        }
        $this->actingAs($manager)->post($url, ['requirement_id' => $requirement->id])->assertSessionHasNoErrors();
        $this->actingAs($manager)->post($url, ['requirement_id' => $requirement->id])->assertSessionHasErrors('requirement_id');
        $this->assertSame(1, $supplier->requirementLinks()->count());

        // Fjern krav: also supplier.edit + compliance.view; the requirement itself is untouched.
        $link = $supplier->requirementLinks()->sole();
        $this->actingAs($editorOnly)->delete("{$url}/{$link->id}")->assertNotFound();
        $this->actingAs($manager)->delete("{$url}/{$link->id}")->assertSessionHas('success');
        $this->assertFalse($supplier->requirementLinks()->exists());
        $this->assertSame([ComplianceRequirement::STATUS_ACTIVE, 'Tilgangsstyring'], [$requirement->fresh()->status, $requirement->fresh()->title]);

        // Ended: nothing added and nothing removed until reopened; what is there is still shown.
        $this->actingAs($manager)->post($url, ['requirement_id' => $requirement->id])->assertSessionHasNoErrors();
        $this->actingAs($manager)->post("/app/supplier-management/{$supplier->id}/end", ['reason' => 'Avtalen er sagt opp.'])->assertSessionHasNoErrors();
        $this->actingAs($manager)->delete("{$url}/".$supplier->requirementLinks()->value('id'))->assertSessionHasErrors('requirement_id');
        $late = $this->complianceRequirement($this->complianceSource($customer, 'Ny kilde'), 'Registrert etter avslutning');
        $this->actingAs($manager)->post($url, ['requirement_id' => $late->id])->assertSessionHasErrors('requirement_id');
        $page = $this->actingAs($manager)->get("/app/supplier-management/{$supplier->id}")->viewData('page')['props'];
        $this->assertSame([null, ['Tilgangsstyring']], [$page['requirement_linking'], array_column($page['requirements'], 'title')]);

        // Another customer's supplier: 404; no row can cross customers.
        $foreign = $this->supplier($foreignCustomer, $this->supplierUser($foreignCustomer, []), 'Fremmed AS');
        $this->actingAs($manager)->post("/app/supplier-management/{$foreign->id}/requirements", ['requirement_id' => $requirement->id])->assertNotFound();
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_compliance_requirements')->insert([
            'customer_id' => $customer->id, 'supplier_id' => $supplier->id, 'compliance_requirement_id' => $foreignRequirement->id, 'created_at' => now(),
        ]), 'a link to another customer\'s requirement');
    }

    public function test_someone_who_cannot_read_compliance_hears_nothing_about_it_on_the_supplier_page(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $manager = $this->requirementManager($customer);
        $supplier = $this->supplier($customer, $manager, 'Acme AS');
        $requirement = $this->complianceRequirement($this->complianceSource($customer, 'Hemmelig kilde'), 'Skjult krav', reference: 'X.1');
        $this->actingAs($manager)->post("/app/supplier-management/{$supplier->id}/requirements", ['requirement_id' => $requirement->id])->assertSessionHasNoErrors();

        // A supplier reader without compliance.view, and a System Owner whose supplier role carries no
        // compliance key (explicit grant in both modules): no section, no count, no name.
        $reader = $this->supplierUser($customer, []);
        $systemOwner = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $systemOwner->forceFill(['bid_role' => User::BID_ROLE_SYSTEM_OWNER])->save();
        foreach ([$reader, $systemOwner->fresh()] as $person) {
            $response = $this->actingAs($person)->get("/app/supplier-management/{$supplier->id}")->assertOk();
            $this->assertSame([null, null], [$response->viewData('page')['props']['requirements'], $response->viewData('page')['props']['requirement_linking']]);
            foreach (['Skjult krav', 'Hemmelig kilde', 'X.1'] as $hidden) {
                $this->assertStringNotContainsString($hidden, $response->getContent());
            }
        }
    }

    public function test_a_supplier_with_requirements_is_ended_never_deleted_and_a_deleted_requirement_takes_its_row_along(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $manager = $this->requirementManager($customer, [CustomerPermissionCatalog::SUPPLIER_DELETE, CustomerPermissionCatalog::COMPLIANCE_DELETE]);
        $supplier = $this->supplier($customer, $manager, 'Acme AS');
        $requirement = $this->complianceRequirement($this->complianceSource($customer), 'Registrert ved en feil');
        $this->actingAs($manager)->post("/app/supplier-management/{$supplier->id}/requirements", ['requirement_id' => $requirement->id])->assertSessionHasNoErrors();

        $this->assertFalse($supplier->fresh()->isDeletable());
        $this->actingAs($manager)->delete("/app/supplier-management/{$supplier->id}")->assertSessionHas('error');
        $this->assertDatabaseRefuses(fn () => DB::table('suppliers')->where('id', $supplier->id)->delete(), 'deleting a supplier with requirements');

        // Etterlevelse og revisjon's own delete rule is unchanged: the requirement is deleted there
        // and takes the row with it.
        $this->actingAs($manager)->delete("/app/compliance/requirements/{$requirement->id}")->assertRedirect(route('app.compliance.requirements.index'));
        $this->assertFalse($supplier->requirementLinks()->exists());
        $this->assertTrue($supplier->fresh()->isDeletable());
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
