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
 *    a customer without Etterlevelse og revisjon.
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
        $this->assertSame(['public_sector_general', 'it_saas', 'data_processor', 'human_rights_risk'], array_keys($templates($reader)));
        $this->assertSame([11, 5, 11], [count($templates($reader)['it_saas']['items']), $templates($reader)['it_saas']['mandatory_count'], $templates($reader)['it_saas']['to_create_count']]);
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
}
