<?php

namespace Tests\Feature\App;

use App\Models\ComplianceRequirement;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\SupplierControlRequirement;
use App\Models\SupplierRequirementOverride;
use App\Models\User;
use App\Services\Modules\ModuleEntitlementService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Concerns\CreatesComplianceScenarios;
use Tests\Concerns\CreatesImprovementCaseScenarios;
use Tests\Concerns\CreatesSupplierScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Supplier Assurance v2 phase 2: kontrollkrav, applicability and manual overrides
 * (docs/supplier-assurance-v2-plan.md §5, §6.6, §13.2, §17, §20). The pure decision table is
 * SupplierRequirementApplicabilityTest's; here, one test per rule end to end:
 *
 *  - the catalogue and requirements for one supplier are written with supplier.assure only;
 *    supplier.view reads; edit, assess and delete grant nothing; System Owner without a role is
 *    refused; rows never cross customers; a used requirement is retired, not deleted;
 *  - Krav og kvalifikasjoner follows the profile live, with «Gjelder fordi …» in words, and no row is
 *    written for an applying requirement;
 *  - include, exclude and clear are immutable rows with a begrunnelse; clear is back to the rule;
 *    a mandatory requirement cannot be excluded, and an exclusion stops counting once it is;
 *  - the anchor in Etterlevelse og revisjon is optional, gated on module and compliance.view, and set
 *    to null when the compliance requirement is deleted.
 */
class SupplierAssuranceRequirementTest extends TestCase
{
    use CreatesComplianceScenarios;
    use CreatesImprovementCaseScenarios;
    use CreatesSupplierScenarios;
    use UsesProjectPostgresConnection;

    private const CATALOGUE = '/app/supplier-management/control-requirements';

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

    public function test_control_requirements_are_written_with_supplier_assure_only_and_never_cross_customers(): void
    {
        ['customer' => $customer] = $this->context('grc');
        ['customer' => $foreignCustomer] = $this->context('grc');
        $assurer = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSURE]);
        $supplier = $this->supplier($customer, $assurer, 'Drift AS', classification: $this->classification());

        // supplier.edit, .assess and .delete grant nothing here; supplier.view reads.
        foreach ([CustomerPermissionCatalog::SUPPLIER_EDIT, CustomerPermissionCatalog::SUPPLIER_ASSESS, CustomerPermissionCatalog::SUPPLIER_DELETE] as $key) {
            $other = $this->supplierUser($customer, [$key]);
            $this->actingAs($other)->post(self::CATALOGUE, $this->requirementPayload())->assertForbidden();
            $this->actingAs($other)->post("/app/supplier-management/{$supplier->id}/control-requirements", $this->requirementPayload())->assertForbidden();
            $this->assertFalse($this->actingAs($other)->get(self::CATALOGUE)->assertOk()->viewData('page')['props']['permissions']['can_manage']);
        }
        $systemOwner = $this->member($customer);
        $systemOwner->forceFill(['role' => User::ROLE_CUSTOMER_ADMIN, 'bid_role' => User::BID_ROLE_SYSTEM_OWNER])->save();
        $this->actingAs($systemOwner)->get(self::CATALOGUE)->assertForbidden();
        $this->actingAs($systemOwner)->post(self::CATALOGUE, $this->requirementPayload())->assertForbidden();
        $this->assertSame(0, SupplierControlRequirement::query()->count());

        // A rule is the fixed predicates, written the simple way.
        $this->actingAs($assurer)->post(self::CATALOGUE, $this->requirementPayload(['rule_mode' => 'conditions', 'conditions' => []]))->assertSessionHasErrors('conditions');
        $this->actingAs($assurer)->post(self::CATALOGUE, $this->requirementPayload(['conditions' => ['criticality_critical']]))->assertSessionHasErrors('conditions.0');
        $this->actingAs($assurer)->post(self::CATALOGUE, $this->requirementPayload(['theme' => 'cooking']))->assertSessionHasErrors('theme');
        $this->actingAs($assurer)->post(self::CATALOGUE, $this->requirementPayload(['criticality_scope' => 'important']))->assertSessionHas('success');
        $requirement = SupplierControlRequirement::query()->sole();
        $this->assertSame([['processor', 'criticality_important']], $requirement->applies_when);
        $this->assertSame([null, $assurer->id], [$requirement->supplier_id, $requirement->created_by]);

        $this->actingAs($assurer)->patch(self::CATALOGUE."/{$requirement->id}", $this->requirementPayload(['title' => 'DBA', 'rule_mode' => 'all', 'level' => 'important']))->assertSessionHas('success');
        $this->assertSame(['DBA', []], [$requirement->fresh()->title, $requirement->fresh()->applies_when]);

        // A requirement for one supplier: no rule, on its page, only while the supplier is open.
        $this->actingAs($assurer)->post("/app/supplier-management/{$supplier->id}/control-requirements", $this->requirementPayload(['title' => 'Utslippsfrie kjøretøy']))->assertSessionHas('success');
        $own = SupplierControlRequirement::query()->where('supplier_id', $supplier->id)->sole();
        $this->assertSame([], $own->applies_when);
        $this->assertFalse($supplier->fresh()->isDeletable());
        $ended = $this->supplier($customer, $assurer, 'Avsluttet AS', Supplier::STATUS_ENDED);
        $this->actingAs($assurer)->post("/app/supplier-management/{$ended->id}/control-requirements", $this->requirementPayload())->assertSessionHasErrors('requirement');
        // The catalogue lists catalogue requirements only.
        $this->assertSame(['DBA'], array_column($this->actingAs($assurer)->get(self::CATALOGUE)->viewData('page')['props']['requirements'], 'title'));

        // Retire and use again; delete only while unused.
        $this->actingAs($assurer)->post(self::CATALOGUE."/{$requirement->id}/retire")->assertSessionHas('success');
        $this->actingAs($assurer)->post(self::CATALOGUE."/{$requirement->id}/retire")->assertSessionHasErrors('requirement');
        $this->actingAs($assurer)->post(self::CATALOGUE."/{$requirement->id}/reactivate")->assertSessionHas('success');
        $this->actingAs($assurer)->post("/app/supplier-management/{$supplier->id}/requirement-overrides", ['requirement_id' => $requirement->id, 'action' => 'exclude', 'reason' => 'Ikke relevant.'])->assertSessionHas('success');
        $this->actingAs($assurer)->delete(self::CATALOGUE."/{$requirement->id}")->assertSessionHasErrors('requirement');
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_control_requirements')->where('id', $requirement->id)->delete(), 'deleting a requirement with overrides');
        $this->actingAs($assurer)->delete(self::CATALOGUE."/{$own->id}")->assertSessionHas('success');

        // Another customer's requirement and supplier are 404; no row can cross customers.
        $foreignAssurer = $this->supplierUser($foreignCustomer, [CustomerPermissionCatalog::SUPPLIER_ASSURE]);
        $foreignSupplier = $this->supplier($foreignCustomer, $foreignAssurer, 'Fremmed AS');
        $this->actingAs($foreignAssurer)->patch(self::CATALOGUE."/{$requirement->id}", $this->requirementPayload())->assertNotFound();
        $this->actingAs($foreignAssurer)->post(self::CATALOGUE."/{$requirement->id}/retire")->assertNotFound();
        $this->actingAs($assurer)->post("/app/supplier-management/{$foreignSupplier->id}/control-requirements", $this->requirementPayload())->assertNotFound();
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_control_requirements')->insert($this->requirementRow($customer) + ['supplier_id' => $foreignSupplier->id]), 'a requirement for another customer\'s supplier');
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_requirement_overrides')->insert([
            'customer_id' => $foreignCustomer->id, 'supplier_id' => $foreignSupplier->id, 'requirement_id' => $requirement->id, 'action' => 'include',
            'reason' => 'x', 'requirement_title' => 'x', 'requirement_level' => 'standard', 'created_at' => now(),
        ]), 'an override of another customer\'s requirement');
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_control_requirements')->insert($this->requirementRow($customer) + ['supplier_id' => $supplier->id, 'applies_when' => '[["processor"]]']), 'a rule on a requirement for one supplier');
    }

    public function test_the_requirement_profile_follows_the_profile_live_and_stores_no_applicability(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $assurer = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSURE]);
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $reader = $this->supplierUser($customer, []);
        $supplier = $this->supplier($customer, $editor, 'Lønn AS', classification: $this->classification(Supplier::CRITICALITY_IMPORTANT, 12, ['processes_personal_data' => true]));
        $dpa = $this->requirement($customer, 'Databehandleravtale', [['processor']], 'mandatory');
        $this->requirement($customer, 'Etiske retningslinjer', []);
        $subcontractors = $this->requirement($customer, 'Oversikt over underleverandører', [['subcontractors']]);
        $show = "/app/supplier-management/{$supplier->id}";
        $profile = fn (array $answers, ?string $reason = 'Endret.') => $this->actingAs($editor)->post("{$show}/profile", $answers + ['reason' => $reason])->assertSessionHasNoErrors();
        $applicable = fn (): array => collect($this->actingAs($reader)->get($show)->assertOk()->viewData('page')['props']['control_requirements']['applicable'])
            ->mapWithKeys(fn (array $row): array => [$row['title'] => $row['reason']['text']])->all();

        // No profile yet: only what the criticality answers say. «Databehandler» is a profile question
        // nobody has answered, and a wholly empty profile does not trigger every requirement.
        $this->assertSame(['Etiske retningslinjer' => 'Gjelder alle leverandører'], $applicable());
        $personal = $this->requirement($customer, 'Behandlingsoversikt', [['personal_data']]);
        $this->assertSame('Gjelder fordi leverandøren behandler personopplysninger på våre vegne', $applicable()['Behandlingsoversikt']);
        $personal->forceFill(['status' => 'retired'])->save();

        $profile(['data_role' => 'controller', 'uses_subcontractors' => 'no'], null);
        $this->assertSame(['Etiske retningslinjer'], array_keys($applicable()));

        // «Ikke avklart» applies, and says so.
        $profile(['data_role' => 'unknown', 'uses_subcontractors' => 'unknown']);
        $this->assertSame([
            'Databehandleravtale' => 'Gjelder fordi det er ikke avklart om leverandøren er databehandler',
            'Etiske retningslinjer' => 'Gjelder alle leverandører',
            'Oversikt over underleverandører' => 'Gjelder fordi det er ikke avklart om leverandøren bruker underleverandører',
        ], $applicable());

        $profile(['data_role' => 'processor', 'uses_subcontractors' => 'no']);
        $page = $this->actingAs($reader)->get($show)->viewData('page')['props']['control_requirements'];
        $this->assertSame(['Databehandleravtale', 'Etiske retningslinjer'], array_column($page['applicable'], 'title'));
        $this->assertSame(['not_evaluated'], array_values(array_unique(array_column($page['applicable'], 'display_status'))));
        // A reader is offered nothing to change, and not the requirements that do not apply.
        $this->assertSame([false, [], null], [$page['permissions']['can_override'], $page['includable'], $page['form']]);
        $this->assertNotContains(true, [...array_column($page['applicable'], 'can_exclude'), ...array_column($page['applicable'], 'can_clear')]);

        // Someone who can include sees why the rest does not apply.
        $includable = $this->actingAs($assurer)->get($show)->viewData('page')['props']['control_requirements']['includable'];
        $this->assertSame([[$subcontractors->id, 'Gjelder når leverandøren bruker underleverandører']], array_map(fn (array $row): array => [$row['id'], $row['rule_text']], $includable));

        // The catalogue counts the suppliers it applies to now — computed, like everything above.
        $counts = collect($this->actingAs($reader)->get(self::CATALOGUE)->viewData('page')['props']['requirements'])->pluck('applies_to_count', 'title')->all();
        $this->assertSame(['Databehandleravtale' => 1, 'Behandlingsoversikt' => 0, 'Etiske retningslinjer' => 1, 'Oversikt over underleverandører' => 0], $counts);

        // Nothing was stored about which requirements apply.
        $this->assertSame(0, SupplierRequirementOverride::query()->count());
        $this->assertSame(4, SupplierControlRequirement::query()->where('customer_id', $customer->id)->count());
        $this->assertSame($dpa->updated_at->toIso8601String(), $dpa->fresh()->updated_at->toIso8601String());

        // A customer without control requirements sees no difference.
        ['customer' => $plain] = $this->context('grc');
        $plainReader = $this->supplierUser($plain, []);
        $plainSupplier = $this->supplier($plain, $plainReader, 'Vanlig AS');
        $this->assertNull($this->actingAs($plainReader)->get("/app/supplier-management/{$plainSupplier->id}")->viewData('page')['props']['control_requirements']);
    }

    public function test_include_exclude_and_clear_are_immutable_rows_with_a_reason_and_mandatory_requirements_cannot_be_excluded(): void
    {
        ['customer' => $customer] = $this->context('grc');
        ['customer' => $foreignCustomer] = $this->context('grc');
        $assurer = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSURE]);
        $supplier = $this->supplier($customer, $assurer, 'Lønn AS', classification: $this->classification(Supplier::CRITICALITY_STANDARD, null, ['processes_personal_data' => true]));
        $dpa = $this->requirement($customer, 'Databehandleravtale', [['personal_data']], 'mandatory');
        $code = $this->requirement($customer, 'Etiske retningslinjer', [], 'important');
        $onSite = $this->requirement($customer, 'HMS-kort', [['on_site_work']]);
        $url = "/app/supplier-management/{$supplier->id}/requirement-overrides";
        $override = fn (SupplierControlRequirement $requirement, string $action, ?string $reason = 'Begrunnet.', ?User $as = null) => $this->actingAs($as ?? $assurer)
            ->post($url, ['requirement_id' => $requirement->id, 'action' => $action, 'reason' => $reason]);
        $rows = fn (): array => collect($this->actingAs($assurer)->get("/app/supplier-management/{$supplier->id}")->viewData('page')['props']['control_requirements']['applicable'])->keyBy('title')->all();

        // supplier.assure only.
        foreach ([CustomerPermissionCatalog::SUPPLIER_EDIT, CustomerPermissionCatalog::SUPPLIER_ASSESS] as $key) {
            $override($onSite, 'include', as: $this->supplierUser($customer, [$key]))->assertForbidden();
        }
        $systemOwner = $this->member($customer);
        $systemOwner->forceFill(['role' => User::ROLE_CUSTOMER_ADMIN, 'bid_role' => User::BID_ROLE_SYSTEM_OWNER])->save();
        $override($onSite, 'include', as: $systemOwner)->assertForbidden();

        // Include: a begrunnelse is required; the reason names who and when; a double submit writes one row.
        $override($onSite, 'include', '  ')->assertSessionHasErrors('reason');
        $override($onSite, 'include', 'Leverandøren skal montere utstyr hos oss.')->assertSessionHas('success');
        $override($onSite, 'include', 'Igjen.')->assertSessionHasErrors('requirement_id');
        $reason = $rows()['HMS-kort']['reason'];
        $this->assertSame("Gjelder fordi {$assurer->name} inkluderte kravet manuelt ".now()->format('d.m.Y'), $reason['text']);
        $this->assertSame('Leverandøren skal montere utstyr hos oss.', $reason['note']);
        $this->assertTrue($rows()['HMS-kort']['can_clear']);

        // Clear: a new row, and the rule decides again.
        $override($onSite, 'clear', 'Monteringen er avlyst.')->assertSessionHas('success');
        $this->assertArrayNotHasKey('HMS-kort', $rows());
        $override($onSite, 'clear', 'Igjen.')->assertSessionHasErrors('requirement_id');

        // Exclude an important requirement that applies automatically; not one that does not.
        $override($onSite, 'exclude')->assertSessionHasErrors('requirement_id');
        $override($code, 'exclude', 'Dekkes av rammeavtalen.')->assertSessionHas('success');
        $page = $this->actingAs($assurer)->get("/app/supplier-management/{$supplier->id}")->viewData('page')['props']['control_requirements'];
        $this->assertNotContains('Etiske retningslinjer', array_column($page['applicable'], 'title'));
        $this->assertSame("{$assurer->name} utelukket kravet ".now()->format('d.m.Y'), $page['excluded'][0]['reason']['text']);

        // A mandatory requirement cannot be excluded — not offered, and refused by the server.
        $this->assertFalse($rows()['Databehandleravtale']['can_exclude']);
        $override($dpa, 'exclude')->assertSessionHasErrors(['requirement_id' => __('procynia.supplier_management.validation.mandatory_not_excludable')]);
        // Made mandatory after it was excluded, the exclusion stops counting.
        $code->forceFill(['level' => 'mandatory'])->save();
        $this->assertTrue($rows()['Etiske retningslinjer']['exclusion_ignored']);
        $this->assertSame('Gjelder alle leverandører', $rows()['Etiske retningslinjer']['reason']['text']);

        // Three rows, newest first in the history, each with its snapshot.
        $history = $this->actingAs($assurer)->get("/app/supplier-management/{$supplier->id}")->viewData('page')['props']['control_requirements']['history'];
        $this->assertSame(['exclude', 'clear', 'include'], array_column($history, 'action'));
        $this->assertSame(['Etiske retningslinjer', 'HMS-kort', 'HMS-kort'], array_column($history, 'title'));
        $this->assertSame('Kravet ble inkludert manuelt', $history[2]['action_text']);

        // Immutable in the model and the database.
        $row = SupplierRequirementOverride::query()->where('supplier_id', $supplier->id)->firstOrFail();
        foreach ([fn () => $row->forceFill(['reason' => 'Endret'])->save(), fn () => $row->delete()] as $write) {
            try {
                $write();
                $this->fail('An override must be immutable in the model.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_requirement_overrides')->where('id', $row->id)->update(['reason' => 'Endret i ettertid.']), 'changing an override');
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_requirement_overrides')->where('id', $row->id)->delete(), 'deleting an override');
        // A deleted user is nulled out of the history, which the trigger allows.
        DB::table('supplier_requirement_overrides')->where('id', $row->id)->update(['created_by_user_id' => null]);
        $this->assertSame(3, SupplierRequirementOverride::query()->where('supplier_id', $supplier->id)->count());

        // Only an active catalogue requirement of the same customer; never an ended or foreign supplier.
        $retired = $this->requirement($customer, 'Utgått krav', [['on_site_work']]);
        $retired->forceFill(['status' => 'retired'])->save();
        $own = $this->requirement($customer, 'Eget krav', [], supplier: $supplier);
        $foreign = $this->requirement($foreignCustomer, 'Fremmed krav', [['on_site_work']]);
        foreach ([$retired, $own, $foreign] as $refused) {
            $override($refused, $refused === $own ? 'exclude' : 'include')->assertSessionHasErrors('requirement_id');
        }
        $foreignSupplier = $this->supplier($foreignCustomer, $this->supplierUser($foreignCustomer, []), 'Fremmed AS');
        $this->actingAs($assurer)->post("/app/supplier-management/{$foreignSupplier->id}/requirement-overrides", ['requirement_id' => $onSite->id, 'action' => 'include', 'reason' => 'x'])->assertNotFound();
        $supplier->status = Supplier::STATUS_ENDED;
        $supplier->save();
        $override($onSite, 'include')->assertSessionHasErrors('requirement_id');
        $this->assertSame(3, SupplierRequirementOverride::query()->count());
        $this->assertFalse($supplier->fresh()->isDeletable());
    }

    public function test_the_compliance_anchor_is_optional_shown_only_with_access_and_set_to_null_when_deleted(): void
    {
        ['customer' => $customer] = $this->context('grc');
        ['customer' => $foreignCustomer] = $this->context('grc');
        $anchorer = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSURE, CustomerPermissionCatalog::COMPLIANCE_VIEW, CustomerPermissionCatalog::COMPLIANCE_DELETE]);
        $assurerOnly = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSURE]);
        $reader = $this->supplierUser($customer, []);
        $gdpr = $this->complianceRequirement($this->complianceSource($customer, 'Personvernforordningen'), 'Krav til databehandlere', reference: 'Art. 28');
        $retiredGdpr = $this->complianceRequirement($this->complianceSource($customer, 'Gammel kilde'), 'Gammelt krav');
        $retiredGdpr->forceFill(['status' => ComplianceRequirement::STATUS_RETIRED])->save();
        $foreignGdpr = $this->complianceRequirement($this->complianceSource($foreignCustomer), 'Fremmed krav');
        $supplier = $this->supplier($customer, $reader, 'Lønn AS');
        $anchorOf = fn (User $as): mixed => $this->actingAs($as)->get(self::CATALOGUE)->assertOk()->viewData('page')['props']['requirements'][0]['anchor'];

        // Only an active requirement the person can read.
        foreach ([$retiredGdpr, $foreignGdpr] as $refused) {
            $this->actingAs($anchorer)->post(self::CATALOGUE, $this->requirementPayload(['compliance_requirement_id' => $refused->id]))->assertSessionHasErrors('compliance_requirement_id');
        }
        $this->actingAs($anchorer)->post(self::CATALOGUE, $this->requirementPayload(['rule_mode' => 'all', 'compliance_requirement_id' => $gdpr->id, 'basis_text' => 'Personvernforordningen art. 28']))->assertSessionHas('success');
        $requirement = SupplierControlRequirement::query()->sole();
        $this->assertSame([$gdpr->id, 'Krav til databehandlere', false], [$anchorOf($anchorer)['id'], $anchorOf($anchorer)['title'], $anchorOf($anchorer)['retired']]);
        $this->assertNotNull($this->actingAs($anchorer)->get(self::CATALOGUE)->viewData('page')['props']['form']['anchor_options']);

        // Without compliance.view: absent — no title, no id — and a save does not clear it.
        foreach ([$reader, $assurerOnly] as $person) {
            $this->assertNull($anchorOf($person));
            foreach ([self::CATALOGUE, "/app/supplier-management/{$supplier->id}"] as $page) {
                $this->assertStringNotContainsString('Krav til databehandlere', $this->actingAs($person)->get($page)->getContent());
            }
        }
        $this->assertNull($this->actingAs($assurerOnly)->get(self::CATALOGUE)->viewData('page')['props']['form']['anchor_options']);
        $this->actingAs($assurerOnly)->patch(self::CATALOGUE."/{$requirement->id}", $this->requirementPayload(['rule_mode' => 'all', 'compliance_requirement_id' => null]))->assertSessionHas('success');
        $this->assertSame($gdpr->id, $requirement->fresh()->compliance_requirement_id);

        // Retired in Etterlevelse: still shown, marked; no effect on the control requirement.
        $gdpr->forceFill(['status' => ComplianceRequirement::STATUS_RETIRED])->save();
        $this->assertTrue($anchorOf($anchorer)['retired']);
        $this->assertSame('Gjelder alle leverandører', $this->actingAs($reader)->get("/app/supplier-management/{$supplier->id}")->viewData('page')['props']['control_requirements']['applicable'][0]['reason']['text']);

        // Etterlevelse cancelled: the anchor is not read, the requirement works on; ordered again, it is back.
        $entitlements = app(ModuleEntitlementService::class);
        $entitlements->cancelOption($customer->fresh(), 'compliance');
        $this->assertNull($anchorOf($anchorer->fresh()));
        $this->assertSame(['Databehandleravtale'], array_column($this->actingAs($reader)->get("/app/supplier-management/{$supplier->id}")->viewData('page')['props']['control_requirements']['applicable'], 'title'));
        $this->assertSame($gdpr->id, $requirement->fresh()->compliance_requirement_id);
        $entitlements->activatePackage($customer->fresh(), 'compliance');
        $this->assertSame($gdpr->id, $anchorOf($anchorer->fresh())['id']);

        // Deleted in Etterlevelse (its own rule: active and unused): never blocked from here, and the
        // anchor becomes null.
        $gdpr->forceFill(['status' => ComplianceRequirement::STATUS_ACTIVE])->save();
        $this->actingAs($anchorer)->delete("/app/compliance/requirements/{$gdpr->id}")->assertRedirect(route('app.compliance.requirements.index'));
        $this->assertNull($requirement->fresh()->compliance_requirement_id);
        $this->assertSame('Personvernforordningen art. 28', $requirement->fresh()->basis_text);
    }

    /**
     * A catalogue requirement written directly, or one for $supplier.
     *
     * @param  list<list<string>>  $rule
     */
    private function requirement(Customer $customer, string $title, array $rule, string $level = 'standard', ?Supplier $supplier = null): SupplierControlRequirement
    {
        return SupplierControlRequirement::query()->create([
            'customer_id' => $customer->id,
            'supplier_id' => $supplier?->id,
            'title' => $title,
            'theme' => 'privacy',
            'level' => $level,
            'control_point' => 'before_contract',
            'applies_when' => $rule,
        ]);
    }

    /** @return array<string, mixed> */
    private function requirementPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Databehandleravtale',
            'description' => 'Avtale etter personvernforordningen art. 28.',
            'guidance' => 'Signert avtale.',
            'theme' => 'privacy',
            'level' => 'mandatory',
            'control_point' => 'before_contract',
            'control_interval_months' => 24,
            'accepted_document_types' => ['data_processing_agreement'],
            'basis_text' => 'Personvernforordningen art. 28',
            'rule_mode' => 'conditions',
            'conditions' => ['processor'],
            'criticality_scope' => '',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function requirementRow(Customer $customer): array
    {
        return [
            'customer_id' => $customer->id, 'title' => 'Rått krav', 'theme' => 'privacy', 'level' => 'standard',
            'control_point' => 'ongoing', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ];
    }
}
