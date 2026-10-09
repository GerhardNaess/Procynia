<?php

namespace Tests\Feature\App;

use App\Models\Supplier;
use App\Models\SupplierControlRequirement;
use App\Models\User;
use App\Support\CustomerPermissionCatalog;
use App\Support\Suppliers\RequirementTemplates\RequirementLibrary;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesImprovementCaseScenarios;
use Tests\Concerns\CreatesSupplierScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Supplier Assurance v2 phase 5: Kravmaler (docs/supplier-assurance-v2-plan.md §16, §13.2). The
 * structure of the templates is SupplierRequirementTemplatesTest's; here, end to end:
 *
 *  - «Ta i bruk kravmal» needs supplier.assure; supplier.view reads the templates; edit, assess and
 *    delete grant nothing; System Owner without a role is refused; nothing crosses customers;
 *  - applying adds the missing items as the customer's own catalogue requirements with provenance,
 *    never twice — not on a second click, not from another template — and never touches a
 *    requirement written by hand, even one with the same title;
 *  - a requirement from a template is an ordinary requirement: edited with its rule kept, the edit
 *    survives applying again, and it applies, counts and blocks like any other (phase 2–4), also for
 *    a customer without Etterlevelse og revisjon;
 *  - phase 8: templates 5–9 through the same engine, table-driven — overlapping items are created
 *    once and never overwritten, a recommended level is shown and not applied.
 */
class SupplierRequirementTemplateTest extends TestCase
{
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

    public function test_a_template_is_applied_with_supplier_assure_only_never_twice_and_never_across_customers(): void
    {
        ['customer' => $customer] = $this->context('grc');
        ['customer' => $foreignCustomer] = $this->context('grc');
        $assurer = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSURE]);
        $reader = $this->supplierUser($customer, []);
        $apply = fn (User $user, string $template) => $this->actingAs($user)->post(self::CATALOGUE."/templates/{$template}");
        $templates = fn (User $user): array => collect($this->actingAs($user)->get(self::CATALOGUE)->assertOk()->viewData('page')['props']['templates'])->keyBy('key')->all();

        // A requirement written by hand, with the same title as a template item.
        $manual = SupplierControlRequirement::query()->create([
            'customer_id' => $customer->id, 'title' => 'Databehandleravtale', 'theme' => 'privacy', 'level' => 'important',
            'control_point' => 'ongoing', 'applies_when' => [['personal_data']],
        ]);

        // supplier.view reads the templates; edit, assess, delete and System Owner apply nothing.
        $this->assertSame(['public_sector_general', 'it_saas', 'data_processor', 'human_rights_risk', 'critical_ict', 'construction', 'cleaning', 'staffing', 'health_care'], array_keys($templates($reader)));
        $this->assertSame([11, 5, 11], [count($templates($reader)['it_saas']['items']), $templates($reader)['it_saas']['mandatory_count'], $templates($reader)['it_saas']['to_create_count']]);
        // Until the content review is signed (plan §19.2), the page is told the templates are not reviewed.
        $this->assertFalse($this->actingAs($reader)->get(self::CATALOGUE)->viewData('page')['props']['templates_reviewed']);
        foreach ([CustomerPermissionCatalog::SUPPLIER_EDIT, CustomerPermissionCatalog::SUPPLIER_ASSESS, CustomerPermissionCatalog::SUPPLIER_DELETE] as $key) {
            $apply($this->supplierUser($customer, [$key]), 'it_saas')->assertForbidden();
        }
        $apply($reader, 'it_saas')->assertForbidden();
        $systemOwner = $this->member($customer);
        $systemOwner->forceFill(['role' => User::ROLE_CUSTOMER_ADMIN, 'bid_role' => User::BID_ROLE_SYSTEM_OWNER])->save();
        $apply($systemOwner, 'it_saas')->assertForbidden();
        $apply($assurer, 'unknown_template')->assertNotFound();
        $this->assertSame([$manual->id], SupplierControlRequirement::query()->pluck('id')->all());

        // Applied: each item as the customer's own catalogue requirement, with provenance.
        $apply($assurer, 'it_saas')->assertSessionHas('success', '11 kontrollkrav ble lagt til.');
        $created = SupplierControlRequirement::query()->where('customer_id', $customer->id)->whereNotNull('template_item_key')->get()->keyBy('template_item_key');
        $this->assertEqualsCanonicalizing(['S1', 'S2', 'S4', 'S5', 'S6', 'S8', 'P1', 'P2', 'P3', 'P4', 'C1'], $created->keys()->all());
        $p4 = $created['P4'];
        $this->assertSame(
            ['Overføringsgrunnlag utenfor EØS og vurdering av overføringen', 'privacy', 'mandatory', 'before_contract', 12, [['personal_data', 'data_outside_eea']], ['data_processing_agreement', 'other'], null, null, null, 'active', 'it_saas', '1', $assurer->id],
            [$p4->title, $p4->theme, $p4->level, $p4->control_point, $p4->control_interval_months, $p4->applies_when, $p4->accepted_document_types, $p4->basis_text, $p4->compliance_requirement_id, $p4->supplier_id, $p4->status, $p4->template_key, $p4->template_version, $p4->created_by],
        );
        foreach ($created as $key => $requirement) {
            $this->assertSame([RequirementLibrary::ITEMS[$key]['level'], RequirementLibrary::ITEMS[$key]['applies_when']], [$requirement->level, $requirement->applies_when], $key);
        }
        // The hand-written requirement with the same title is untouched, and not counted as the item.
        $this->assertSame(['Databehandleravtale', 'important', null], [$manual->fresh()->title, $manual->fresh()->level, $manual->fresh()->template_item_key]);
        $this->assertSame(2, SupplierControlRequirement::query()->where('title', 'Databehandleravtale')->count());
        $this->assertSame(['name' => 'IT/SaaS-leverandør', 'version' => '1'], collect($this->actingAs($reader)->get(self::CATALOGUE)->viewData('page')['props']['requirements'])->firstWhere('id', $p4->id)['template']);

        // Again, and from another template: only what is missing — matched on the item, not the title.
        $apply($assurer, 'it_saas')->assertSessionHas('success', 'Alle kravene i malen fantes allerede. Ingen kontrollkrav ble lagt til.');
        $this->assertSame(1, $templates($assurer)['data_processor']['to_create_count']);
        $apply($assurer, 'data_processor')->assertSessionHas('success', '1 kontrollkrav ble lagt til. 7 krav fantes allerede og ble ikke lagt til på nytt.');
        $this->assertSame(['data_processor'], SupplierControlRequirement::query()->where('template_item_key', 'P5')->pluck('template_key')->all());
        $this->assertSame('it_saas', $p4->fresh()->template_key);
        $this->assertSame(13, SupplierControlRequirement::query()->where('customer_id', $customer->id)->count());
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_control_requirements')->insert([
            'customer_id' => $customer->id, 'title' => 'Kopi', 'theme' => 'privacy', 'level' => 'standard', 'control_point' => 'ongoing',
            'status' => 'active', 'template_item_key' => 'P1', 'created_at' => now(), 'updated_at' => now(),
        ]), 'the same template item twice for one customer');

        // The other customer has none of it, sees every item as missing, and cannot reach these rows.
        $foreignAssurer = $this->supplierUser($foreignCustomer, [CustomerPermissionCatalog::SUPPLIER_ASSURE]);
        $this->assertSame(11, $templates($foreignAssurer)['it_saas']['to_create_count']);
        $this->assertSame([], $this->actingAs($foreignAssurer)->get(self::CATALOGUE)->viewData('page')['props']['requirements']);
        $this->actingAs($foreignAssurer)->post(self::CATALOGUE."/{$p4->id}/retire")->assertNotFound();
        $apply($foreignAssurer, 'it_saas')->assertSessionHas('success', '11 kontrollkrav ble lagt til.');
        $this->assertSame(13, SupplierControlRequirement::query()->where('customer_id', $customer->id)->count());
        $this->assertSame(11, SupplierControlRequirement::query()->where('customer_id', $foreignCustomer->id)->count());

        // Template 4 (phase 7) through the same engine: its five items, once, with provenance; E1 and M1
        // are the library's items, not copies.
        $this->assertSame([5, 1, 5], [count($templates($assurer)['human_rights_risk']['items']), $templates($assurer)['human_rights_risk']['mandatory_count'], $templates($assurer)['human_rights_risk']['to_create_count']]);
        $apply($assurer, 'human_rights_risk')->assertSessionHas('success', '5 kontrollkrav ble lagt til.');
        $h1 = SupplierControlRequirement::query()->where('customer_id', $customer->id)->where('template_item_key', 'H1')->sole();
        $this->assertSame(
            ['Egenerklæring om menneskerettigheter og arbeidsforhold i leverandørkjeden', 'human_rights', 'mandatory', [['high_risk_products'], ['production_outside_eea']], null, 'human_rights_risk', '1'],
            [$h1->title, $h1->theme, $h1->level, $h1->applies_when, $h1->basis_text, $h1->template_key, $h1->template_version],
        );
        $apply($assurer, 'human_rights_risk')->assertSessionHas('success', 'Alle kravene i malen fantes allerede. Ingen kontrollkrav ble lagt til.');
        $this->assertSame(18, SupplierControlRequirement::query()->where('customer_id', $customer->id)->count());
    }

    public function test_a_requirement_from_a_template_is_the_customers_own_and_runs_through_the_control_chain_without_compliance(): void
    {
        // Leverandøroppfølging alone: no Etterlevelse og revisjon.
        ['customer' => $customer] = $this->context('supplier');
        $assurer = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSURE]);
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $this->actingAs($assurer)->post(self::CATALOGUE.'/templates/data_processor')->assertSessionHas('success');
        $p4 = SupplierControlRequirement::query()->where('template_item_key', 'P4')->sole();
        $p1 = SupplierControlRequirement::query()->where('template_item_key', 'P1')->sole();

        // P4's rule is not one the form writes: it is offered as kept, and kept on save.
        $row = collect($this->actingAs($assurer)->get(self::CATALOGUE)->viewData('page')['props']['requirements'])->firstWhere('id', $p4->id);
        $this->assertNull($row['rule']);
        $this->actingAs($assurer)->patch(self::CATALOGUE."/{$p4->id}", [
            'title' => 'Overføring utenfor EØS', 'theme' => 'privacy', 'level' => 'important', 'control_point' => 'before_contract',
            'control_interval_months' => 24, 'accepted_document_types' => ['data_processing_agreement'], 'basis_text' => 'Avtale pkt. 4',
            'rule_mode' => 'keep',
        ])->assertSessionHasNoErrors();
        $edited = $p4->fresh();
        $this->assertSame(
            ['Overføring utenfor EØS', 'important', 24, 'Avtale pkt. 4', [['personal_data', 'data_outside_eea']], 'data_processor', 'P4', '1'],
            [$edited->title, $edited->level, $edited->control_interval_months, $edited->basis_text, $edited->applies_when, $edited->template_key, $edited->template_item_key, $edited->template_version],
        );
        // «Keep» is for an existing rule only.
        $this->actingAs($assurer)->post(self::CATALOGUE, ['title' => 'Nytt', 'theme' => 'privacy', 'level' => 'standard', 'control_point' => 'ongoing', 'rule_mode' => 'keep'])->assertSessionHasErrors('rule_mode');

        // The customer's edits and retirements survive applying the template again.
        $p1->forceFill(['status' => SupplierControlRequirement::STATUS_RETIRED])->save();
        $this->actingAs($assurer)->post(self::CATALOGUE.'/templates/data_processor')->assertSessionHas('success', 'Alle kravene i malen fantes allerede. Ingen kontrollkrav ble lagt til.');
        $this->assertSame(['Overføring utenfor EØS', 'important'], [$p4->fresh()->title, $p4->fresh()->level]);
        $this->assertSame('retired', $p1->fresh()->status);
        $p1->forceFill(['status' => SupplierControlRequirement::STATUS_ACTIVE])->save();

        // On the supplier: the profile decides, as for any requirement; a mandatory one asks for a decision.
        $supplier = $this->supplier($customer, $editor, 'Lønn AS', classification: $this->classification(Supplier::CRITICALITY_IMPORTANT, 12, ['processes_personal_data' => true]));
        $show = "/app/supplier-management/{$supplier->id}";
        $this->actingAs($editor)->post("{$show}/profile", [
            'data_role' => 'processor', 'special_category_data' => 'no', 'stores_our_data' => 'yes', 'data_location' => 'eea', 'confidential_information' => 'no',
        ])->assertSessionHasNoErrors();
        $page = $this->actingAs($assurer)->get($show)->assertOk()->viewData('page')['props'];
        $this->assertEqualsCanonicalizing(['P1', 'P2', 'P3', 'S4', 'S6', 'S8'], SupplierControlRequirement::query()->whereIn('id', array_column($page['control_requirements']['applicable'], 'id'))->pluck('template_item_key')->all());
        $this->assertSame(['mandatory_open', true], [$page['assurance']['state']['state'], $page['assurance']['state']['decision_required']]);
        $this->assertContains($p1->title, array_column($page['assurance']['state']['open_mandatory'], 'title'));
        // A mandatory requirement from a template cannot be excluded, like any mandatory requirement.
        $this->actingAs($assurer)->post("{$show}/requirement-overrides", ['requirement_id' => $p1->id, 'action' => 'exclude', 'reason' => 'Ikke aktuelt.'])->assertSessionHasErrors();
        // No anchor field without Etterlevelse og revisjon.
        $this->assertNull($this->actingAs($assurer)->get(self::CATALOGUE)->viewData('page')['props']['form']['anchor_options']);
    }

    public function test_templates_five_to_nine_overlap_without_duplicating_or_overwriting_and_run_through_the_same_chain(): void
    {
        // Leverandøroppfølging alone: no Etterlevelse og revisjon.
        ['customer' => $customer] = $this->context('supplier');
        ['customer' => $foreignCustomer] = $this->context('supplier');
        $assurer = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSURE]);
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $reader = $this->supplierUser($customer, []);
        $apply = fn (User $user, string $template) => $this->actingAs($user)->post(self::CATALOGUE."/templates/{$template}");
        $templates = fn (User $user): array => collect($this->actingAs($user)->get(self::CATALOGUE)->assertOk()->viewData('page')['props']['templates'])->keyBy('key')->all();
        $count = fn (): int => SupplierControlRequirement::query()->where('customer_id', $customer->id)->count();

        // What each new template brings, read by supplier.view: [items, mandatory, to create].
        $overview = $templates($reader);
        $this->assertSame(
            ['critical_ict' => [17, 6, 17], 'construction' => [10, 2, 10], 'cleaning' => [7, 3, 7], 'staffing' => [5, 2, 5], 'health_care' => [9, 7, 9]],
            collect($overview)->only(['critical_ict', 'construction', 'cleaning', 'staffing', 'health_care'])
                ->map(fn (array $template): array => [count($template['items']), $template['mandatory_count'], $template['to_create_count']])->all(),
        );
        // E2 is recommended mandatory in Bygg og anlegg only (plan §16.3) — shown, not a level of its own.
        $this->assertSame(['important', 'mandatory'], [collect($overview['construction']['items'])->firstWhere('key', 'E2')['level'], collect($overview['construction']['items'])->firstWhere('key', 'E2')['recommended_level']]);
        $this->assertNull(collect($overview['cleaning']['items'])->firstWhere('key', 'E2')['recommended_level']);

        // Access as for templates 1–4: only supplier.assure; System Owner without a role is refused.
        foreach ([[], [CustomerPermissionCatalog::SUPPLIER_EDIT], [CustomerPermissionCatalog::SUPPLIER_ASSESS], [CustomerPermissionCatalog::SUPPLIER_DELETE]] as $permissions) {
            $apply($this->supplierUser($customer, $permissions), 'critical_ict')->assertForbidden();
        }
        $systemOwner = $this->member($customer);
        $systemOwner->forceFill(['role' => User::ROLE_CUSTOMER_ADMIN, 'bid_role' => User::BID_ROLE_SYSTEM_OWNER])->save();
        $apply($systemOwner, 'health_care')->assertForbidden();
        $this->assertSame(0, $count());

        // The customer already uses IT/SaaS, has edited one of its requirements and retired another.
        $apply($assurer, 'it_saas')->assertSessionHas('success', '11 kontrollkrav ble lagt til.');
        $s2 = SupplierControlRequirement::query()->where('customer_id', $customer->id)->where('template_item_key', 'S2')->sole();
        $s2->forceFill(['title' => 'MFA hos driftsleverandør', 'level' => 'important', 'control_interval_months' => 6, 'applies_when' => [['privileged_access']], 'accepted_document_types' => ['policy'], 'basis_text' => 'Avtale pkt. 7'])->save();
        SupplierControlRequirement::query()->where('customer_id', $customer->id)->where('template_item_key', 'P1')->update(['status' => SupplierControlRequirement::STATUS_RETIRED]);
        $before = SupplierControlRequirement::query()->where('customer_id', $customer->id)->orderBy('id')->get()->map->only(['id', 'title', 'level', 'control_interval_months', 'applies_when', 'accepted_document_types', 'basis_text', 'status', 'template_key', 'template_version'])->all();

        // Then each new template in turn: only what is missing, matched on the item; table-driven.
        foreach ([
            'critical_ict' => [['S3', 'S7', 'C2', 'C3', 'F2', 'Q2'], '6 kontrollkrav ble lagt til. 11 krav fantes allerede og ble ikke lagt til på nytt.'],
            'construction' => [['E2', 'L1', 'L2', 'L3', 'L4', 'L5', 'B1', 'F1', 'M1', 'M2'], '10 kontrollkrav ble lagt til.'],
            'cleaning' => [['R1'], '1 kontrollkrav ble lagt til. 6 krav fantes allerede og ble ikke lagt til på nytt.'],
            'staffing' => [['ST1', 'ST2'], '2 kontrollkrav ble lagt til. 3 krav fantes allerede og ble ikke lagt til på nytt.'],
            'health_care' => [['HE1', 'HE2', 'HE3', 'P5'], '4 kontrollkrav ble lagt til. 5 krav fantes allerede og ble ikke lagt til på nytt.'],
        ] as $template => [$new, $message]) {
            $this->assertSame(count($new), $templates($assurer)[$template]['to_create_count'], $template);
            $apply($assurer, $template)->assertSessionHas('success', $message);
            $apply($assurer, $template)->assertSessionHas('success', 'Alle kravene i malen fantes allerede. Ingen kontrollkrav ble lagt til.');

            $created = SupplierControlRequirement::query()->where('customer_id', $customer->id)->where('template_key', $template)->get()->keyBy('template_item_key');
            $this->assertEqualsCanonicalizing($new, $created->keys()->all(), $template);
            foreach ($created as $key => $requirement) {
                $item = RequirementLibrary::ITEMS[$key];
                $this->assertSame(
                    [RequirementLibrary::title($key), $item['theme'], $item['level'], $item['control_point'], $item['control_interval_months'], $item['applies_when'], $item['accepted_document_types'], null, null, null, 'active', '1'],
                    [$requirement->title, $requirement->theme, $requirement->level, $requirement->control_point, $requirement->control_interval_months, $requirement->applies_when, $requirement->accepted_document_types, $requirement->basis_text, $requirement->compliance_requirement_id, $requirement->supplier_id, $requirement->status, $requirement->template_version],
                    "{$template}.{$key}",
                );
            }
        }

        // 34 items, each once: every library item but the five only templates 1 and 4 bring.
        $this->assertSame(34, $count());
        $this->assertSame(34, SupplierControlRequirement::query()->where('customer_id', $customer->id)->distinct()->count('template_item_key'));
        $this->assertSame([], array_values(array_diff(array_keys(RequirementLibrary::ITEMS), ['E1', 'Q1', 'H1', 'H2', 'H3'], SupplierControlRequirement::query()->where('customer_id', $customer->id)->pluck('template_item_key')->all())));
        // The IT/SaaS rows — edited, retired or not — are exactly as they were; E2 stays Viktig.
        $this->assertSame($before, SupplierControlRequirement::query()->where('customer_id', $customer->id)->where('template_key', 'it_saas')->orderBy('id')->get()->map->only(['id', 'title', 'level', 'control_interval_months', 'applies_when', 'accepted_document_types', 'basis_text', 'status', 'template_key', 'template_version'])->all());
        $this->assertSame('important', SupplierControlRequirement::query()->where('customer_id', $customer->id)->where('template_item_key', 'E2')->value('level'));

        // HE1's rule is not one the form writes: kept on save.
        $he1 = SupplierControlRequirement::query()->where('customer_id', $customer->id)->where('template_item_key', 'HE1')->sole();
        $this->assertNull(collect($this->actingAs($assurer)->get(self::CATALOGUE)->viewData('page')['props']['requirements'])->firstWhere('id', $he1->id)['rule']);
        $this->actingAs($assurer)->patch(self::CATALOGUE."/{$he1->id}", [
            'title' => 'Normen for informasjonssikkerhet', 'theme' => 'information_security', 'level' => 'mandatory', 'control_point' => 'before_contract',
            'control_interval_months' => 12, 'accepted_document_types' => ['self_declaration'], 'rule_mode' => 'keep',
        ])->assertSessionHasNoErrors();
        $this->assertSame(['Normen for informasjonssikkerhet', [['sector:health_care', 'personal_data']], 'health_care', 'HE1'], [$he1->fresh()->title, $he1->fresh()->applies_when, $he1->fresh()->template_key, $he1->fresh()->template_item_key]);

        // On a cleaning supplier: the profile decides, and the mandatory items ask for a decision (phase 4).
        $supplier = $this->supplier($customer, $editor, 'Renhold AS', classification: $this->classification(Supplier::CRITICALITY_STANDARD, 12));
        $show = "/app/supplier-management/{$supplier->id}";
        $this->actingAs($editor)->post("{$show}/profile", [
            'stores_our_data' => 'no', 'confidential_information' => 'no', 'uses_subcontractors' => 'no', 'production_outside_eea' => 'no', 'high_risk_categories' => [],
            'on_site_work' => 'yes', 'labour_intensive' => 'yes', 'public_contract_terms' => 'no', 'significant_environmental_impact' => 'no', 'sectors' => ['cleaning'],
        ])->assertSessionHasNoErrors();
        $page = $this->actingAs($assurer)->get($show)->assertOk()->viewData('page')['props'];
        $this->assertEqualsCanonicalizing(['R1', 'E2', 'L3', 'L4', 'F1'], SupplierControlRequirement::query()->whereIn('id', array_column($page['control_requirements']['applicable'], 'id'))->pluck('template_item_key')->all());
        $this->assertSame(['mandatory_open', true], [$page['assurance']['state']['state'], $page['assurance']['state']['decision_required']]);
        $this->assertEqualsCanonicalizing([RequirementLibrary::title('R1'), RequirementLibrary::title('L3')], array_column($page['assurance']['state']['open_mandatory'], 'title'));

        // The other customer has none of it, and sees every item as missing.
        $foreignAssurer = $this->supplierUser($foreignCustomer, [CustomerPermissionCatalog::SUPPLIER_ASSURE]);
        $this->assertSame(17, $templates($foreignAssurer)['critical_ict']['to_create_count']);
        $this->assertSame([], $this->actingAs($foreignAssurer)->get(self::CATALOGUE)->viewData('page')['props']['requirements']);
        $this->actingAs($foreignAssurer)->post(self::CATALOGUE."/{$he1->id}/retire")->assertNotFound();
        $this->assertSame(34, $count());
    }
}
