<?php

namespace Tests\Feature\App;

use App\Models\ComplianceAssessment;
use App\Models\ComplianceFramework;
use App\Models\ComplianceFrameworkNode;
use App\Models\ComplianceRequirement;
use App\Models\ComplianceSource;
use App\Models\Customer;
use App\Models\User;
use App\Services\Compliance\ComplianceAttentionService;
use App\Services\Compliance\Library\ComplianceFrameworkActivationService;
use App\Services\Compliance\Library\ComplianceFrameworkLibraryImporter;
use App\Support\CustomerPermissionCatalog;
use Database\Seeders\ComplianceFrameworkLibrarySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Concerns\CreatesComplianceScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Kravbiblioteket — ISO 9001 as the first standardised requirement package, from seeder to page.
 *
 * What these tests defend:
 *
 *  - The seeder is idempotent, builds the standard's hierarchy (7 chapters, sub-clauses, 65
 *    assessable leaves) and never activates anything for a customer.
 *  - A rerun with changed content updates the library and an activated customer's library-owned
 *    text, adds new requirements, withdraws removed ones — and never touches owners, relevance,
 *    assessments or history.
 *  - Activation is compliance.edit, writes ordinary register rows, and is idempotent in the
 *    controller, the service and the database.
 *  - Tenant isolation: one customer's activation and register never reach another.
 *  - Relevance is kept apart from both the normative character and compliance: a mandatory
 *    requirement is never Ikke relevant, Ikke relevant always has a begrunnelse, and only a
 *    requirement that applies is assessed — never as Ikke relevant, never Oppfylt by itself.
 */
class ComplianceLibraryTest extends TestCase
{
    use CreatesComplianceScenarios;
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

    // ---------------------------------------------------------------------
    // Seeder and hierarchy
    // ---------------------------------------------------------------------

    public function test_the_seeder_builds_iso_9001_and_running_it_again_changes_nothing(): void
    {
        $this->seed(ComplianceFrameworkLibrarySeeder::class);
        $framework = $this->iso9001();
        $before = ComplianceFrameworkNode::query()->where('framework_id', $framework->id)->orderBy('id')->get(['id', 'node_key', 'updated_at'])->toArray();

        $this->seed(ComplianceFrameworkLibrarySeeder::class);
        $report = app(ComplianceFrameworkLibraryImporter::class)->import($this->definition());

        $this->assertSame(1, ComplianceFramework::query()->where('key', 'iso9001:2015')->count());
        $this->assertSame($before, ComplianceFrameworkNode::query()->where('framework_id', $framework->id)->orderBy('id')->get(['id', 'node_key', 'updated_at'])->toArray());
        $this->assertSame(['created' => 0, 'updated' => 0, 'withdrawn' => 0, 'restored' => 0], array_intersect_key($report, array_flip(['created', 'updated', 'withdrawn', 'restored'])));
        $this->assertSame(91, $report['unchanged']);
        $this->assertSame('2015 + A1:2024', $framework->edition);
        $this->assertFalse($framework->content_verified, 'The descriptions are not yet checked against a licensed copy.');
    }

    public function test_the_hierarchy_follows_the_standard(): void
    {
        $framework = $this->importLibrary();
        $nodes = ComplianceFrameworkNode::query()->where('framework_id', $framework->id)->orderBy('position')->get()->keyBy('clause');

        $this->assertSame(['4', '5', '6', '7', '8', '9', '10'], $nodes->where('depth', 1)->pluck('clause')->values()->all());
        $this->assertSame(65, $nodes->where('kind', ComplianceFrameworkNode::KIND_REQUIREMENT)->count());
        $this->assertSame(26, $nodes->where('kind', ComplianceFrameworkNode::KIND_SECTION)->count());

        // 7.1.5.2 sits four levels down: 7 → 7.1 → 7.1.5 → 7.1.5.2.
        $leaf = $nodes['7.1.5.2'];
        $this->assertSame(4, $leaf->depth);
        $this->assertSame('7.1.5', $nodes->firstWhere('id', $leaf->parent_id)->clause);
        $this->assertSame('7.1', $nodes['7.1.5']->parent->clause);
        $this->assertSame('7', $nodes['7.1']->parent->clause);
        $this->assertSame('iso9001:2015/7.1.5.2', $leaf->node_key);

        // A clause with no sub-clauses is itself the requirement; a section never is.
        $this->assertTrue($nodes['4.1']->isRequirement());
        $this->assertFalse($nodes['4.4']->isRequirement());
        $this->assertNull($nodes['4.4']->description);

        // Every requirement is a leaf, has the fields the page needs, and the standard's order holds.
        foreach ($nodes->where('kind', ComplianceFrameworkNode::KIND_REQUIREMENT) as $requirement) {
            $this->assertFalse($nodes->contains('parent_id', $requirement->id), "{$requirement->clause} must be a leaf");
            $this->assertNotEmpty($requirement->description);
            $this->assertNotEmpty($requirement->guidance);
            $this->assertNotEmpty($requirement->suggested_documentation);
        }

        $this->assertLessThan($nodes['10.1']->position, $nodes['9.3.3']->position);
        $this->assertLessThan($nodes['7.2']->position, $nodes['7.1.6']->position);
    }

    public function test_only_the_clauses_the_standard_conditions_may_be_excluded(): void
    {
        $framework = $this->importLibrary();

        $conditional = ComplianceFrameworkNode::query()->where('framework_id', $framework->id)
            ->where('applicability_rule', ComplianceFrameworkNode::RULE_CONDITIONAL)
            ->orderBy('position')->pluck('clause')->all();

        $this->assertSame(['7.1.5.2', '8.3.1', '8.3.2', '8.3.3', '8.3.4', '8.3.5', '8.3.6', '8.5.3', '8.5.5'], $conditional);
    }

    public function test_an_invalid_definition_writes_nothing(): void
    {
        $definition = $this->definition();
        $definition['chapters'][0]['children'][0]['type'] = 'invented';

        try {
            app(ComplianceFrameworkLibraryImporter::class)->import($definition);
            $this->fail('An unknown type must be refused.');
        } catch (InvalidArgumentException) {
        }

        $this->assertSame(0, ComplianceFramework::query()->where('key', 'iso9001:2015')->count());
    }

    public function test_the_seeder_activates_nothing_for_any_customer(): void
    {
        ['customer' => $customer] = $this->complianceContext();

        $this->seed(ComplianceFrameworkLibrarySeeder::class);

        $this->assertFalse(ComplianceSource::query()->where('customer_id', $customer->id)->exists());
        $this->assertFalse(ComplianceRequirement::query()->where('customer_id', $customer->id)->exists());
    }

    // ---------------------------------------------------------------------
    // Activation
    // ---------------------------------------------------------------------

    public function test_an_editor_activates_iso_9001_into_the_register(): void
    {
        $framework = $this->importLibrary();
        ['customer' => $customer] = $this->complianceContext();
        $editor = $this->complianceEditor($customer);

        $this->actingAs($editor)
            ->post("/app/compliance/library/{$framework->id}/activate")
            ->assertRedirect('/app/compliance/library')
            ->assertSessionHas('success');

        $source = ComplianceSource::query()->where('customer_id', $customer->id)->sole();
        $this->assertSame((int) $framework->id, (int) $source->framework_id);
        $this->assertSame('ISO 9001', $source->name);
        $this->assertSame('2015 + A1:2024', $source->version);

        $requirements = ComplianceRequirement::query()->where('customer_id', $customer->id)->with('frameworkNode')->get()->keyBy('reference');
        $this->assertCount(65, $requirements);
        $this->assertSame('Forstå organisasjonen og dens kontekst', $requirements['4.1']->title);
        $this->assertSame($requirements['4.1']->frameworkNode->description, $requirements['4.1']->requirement_text);

        // Relevance starts where the standard puts it; nothing is assessed or owned yet.
        $this->assertSame(ComplianceRequirement::APPLICABILITY_APPLIES, $requirements['4.1']->applicability);
        $this->assertSame(ComplianceRequirement::APPLICABILITY_NOT_ASSESSED, $requirements['8.3.1']->applicability);
        $this->assertNull($requirements['4.1']->owner_user_id);
        $this->assertSame(0, ComplianceAssessment::query()->where('customer_id', $customer->id)->count());
    }

    public function test_activating_again_creates_no_duplicates(): void
    {
        $framework = $this->importLibrary();
        ['customer' => $customer] = $this->complianceContext();
        $editor = $this->complianceEditor($customer);

        $this->actingAs($editor)->post("/app/compliance/library/{$framework->id}/activate");
        $this->actingAs($editor)->post("/app/compliance/library/{$framework->id}/activate")->assertSessionHas('success', __('procynia.compliance.library.flash.already_activated', ['name' => 'ISO 9001']));
        app(ComplianceFrameworkActivationService::class)->activate($framework, $editor);

        $this->assertSame(1, ComplianceSource::query()->where('customer_id', $customer->id)->count());
        $this->assertSame(65, ComplianceRequirement::query()->where('customer_id', $customer->id)->count());
    }

    public function test_the_database_refuses_a_second_activation_or_a_duplicate_requirement(): void
    {
        $framework = $this->importLibrary();
        ['customer' => $customer] = $this->complianceContext();
        $source = app(ComplianceFrameworkActivationService::class)->activate($framework, $this->complianceEditor($customer));
        $node = $framework->requirementNodes()->first();

        $this->assertDatabaseRefuses(fn () => ComplianceSource::query()->create([
            'customer_id' => $customer->id, 'framework_id' => $framework->id, 'name' => 'ISO 9001', 'kind' => ComplianceSource::KIND_STANDARD,
        ]));

        $this->assertDatabaseRefuses(fn () => (new ComplianceRequirement([
            'customer_id' => $customer->id, 'source_id' => $source->id, 'title' => 'Kopi', 'requirement_text' => 'Kopi',
        ]))->forceFill(['framework_node_id' => $node->id])->save());
    }

    public function test_reading_needs_compliance_view_and_activating_needs_compliance_edit(): void
    {
        $framework = $this->importLibrary();
        ['customer' => $customer] = $this->complianceContext();
        $reader = $this->complianceReader($customer);
        $outsider = $this->complianceMember($customer);

        $this->actingAs($reader)->get('/app/compliance/library')->assertOk();
        $this->assertFalse($this->actingAs($reader)->get('/app/compliance/library')->viewData('page')['props']['permissions']['can_activate']);
        $props = $this->actingAs($reader)->get("/app/compliance/library/{$framework->id}")->assertOk()->viewData('page')['props'];
        $this->assertNull($props['activation']);

        $this->actingAs($reader)->post("/app/compliance/library/{$framework->id}/activate")->assertForbidden();
        $this->actingAs($outsider)->get('/app/compliance/library')->assertForbidden();
        $this->actingAs($outsider)->get("/app/compliance/library/{$framework->id}")->assertForbidden();
        $this->actingAs($outsider)->post("/app/compliance/library/{$framework->id}/activate")->assertForbidden();

        $this->assertFalse(ComplianceSource::query()->where('customer_id', $customer->id)->exists());
    }

    public function test_the_framework_page_shows_the_tree_and_after_activation_the_register_and_counts(): void
    {
        $framework = $this->importLibrary();
        ['customer' => $customer] = $this->complianceContext();
        $editor = $this->complianceEditor($customer);

        $before = $this->actingAs($editor)->get("/app/compliance/library/{$framework->id}")->assertOk()->viewData('page');
        $this->assertSame('App/Compliance/Library/Show', $before['component']);
        $this->assertArrayNotHasKey('permissions', $before['props'], 'The framework page explores; activation is on the library card.');
        $this->assertSame(65, $before['props']['framework']['requirement_count']);
        $this->assertSame(7, $before['props']['framework']['chapter_count']);
        $this->assertCount(91, $before['props']['nodes']);
        $this->assertNull(collect($before['props']['nodes'])->firstWhere('clause', '4.1')['requirement']);

        app(ComplianceFrameworkActivationService::class)->activate($framework, $editor);

        $after = $this->actingAs($editor)->get("/app/compliance/library/{$framework->id}")->assertOk()->viewData('page')['props'];
        $this->assertNotNull($after['activation']);
        // The library explores and chooses; it is not a second dashboard. Each requirement shows its
        // relevance in the register, and where the work stands is Etterlevelsesvurdering's.
        $this->assertArrayNotHasKey('stats', $after);
        $this->assertArrayNotHasKey('stat_groups', $after);
        $row = collect($after['nodes'])->firstWhere('clause', '6.1.1')['requirement'];
        $this->assertSame('applies', $row['applicability']);
        $this->assertArrayNotHasKey('compliance', $row);
        $source = ComplianceSource::query()->where('customer_id', $customer->id)->where('framework_id', $framework->id)->sole();
        $this->assertSame(route('app.compliance.assessments.index', ['source' => $source->id]), $after['activation']['assessments_url']);

        $index = $this->actingAs($editor)->get('/app/compliance/library')->assertOk()->viewData('page')['props'];
        $this->assertTrue($index['frameworks'][0]['activated']);
        $this->assertSame(65, $index['frameworks'][0]['register_count']);
        $this->assertArrayNotHasKey('stats', $index['frameworks'][0]);

        // The figures, in Etterlevelsesvurdering: the nine conditional requirements wait for a
        // relevance judgement and are kept outside the 56 that can be assessed.
        $worklist = $this->actingAs($editor)->get('/app/compliance/assessments?source='.$source->id)->assertOk()->viewData('page')['props'];
        $this->assertSame(['assessable' => 56, 'assessed' => 0, 'not_assessed' => 56, 'not_applicable' => 0, 'relevance_open' => 9], array_intersect_key($worklist['stats'], array_flip(['assessable', 'assessed', 'not_assessed', 'relevance_open', 'not_applicable'])));
        $this->assertSame('ISO 9001:2015', $worklist['requirements'][0]['origin']['source']);
    }

    public function test_module_hints_are_shown_only_for_modules_the_person_can_open(): void
    {
        $framework = $this->importLibrary();
        ['customer' => $customer] = $this->complianceContext();
        $reader = $this->complianceReader($customer);

        $nodes = collect($this->actingAs($reader)->get("/app/compliance/library/{$framework->id}")->viewData('page')['props']['nodes']);

        // 9.2 → Revisjoner, which compliance.view opens; 6.1 → Risiko, which this reader cannot open.
        $this->assertSame(['compliance_audits'], array_column($nodes->firstWhere('clause', '9.2.1')['modules'], 'key'));
        $this->assertSame([], $nodes->firstWhere('clause', '6.1.1')['modules']);

        $riskReader = $this->complianceMember($customer);
        $this->complianceGrant($customer, $riskReader, [CustomerPermissionCatalog::COMPLIANCE_VIEW, CustomerPermissionCatalog::RISK_VIEW]);
        $nodes = collect($this->actingAs($riskReader)->get("/app/compliance/library/{$framework->id}")->viewData('page')['props']['nodes']);
        $this->assertSame([['key' => 'risk', 'href' => '/app/risk']], $nodes->firstWhere('clause', '6.1.1')['modules']);
    }

    // ---------------------------------------------------------------------
    // Tenant isolation
    // ---------------------------------------------------------------------

    public function test_one_customers_activation_and_register_never_reach_another(): void
    {
        $framework = $this->importLibrary();
        ['customer' => $first] = $this->complianceContext();
        ['customer' => $second] = $this->complianceContext();
        $firstEditor = $this->complianceEditor($first);
        $secondEditor = $this->complianceEditor($second);

        app(ComplianceFrameworkActivationService::class)->activate($framework, $firstEditor);
        $foreign = ComplianceRequirement::query()->where('customer_id', $first->id)->where('reference', '9.2.1')->sole();

        $props = $this->actingAs($secondEditor)->get("/app/compliance/library/{$framework->id}")->assertOk()->viewData('page')['props'];
        $this->assertNull($props['activation']);
        $card = collect($this->actingAs($secondEditor)->get('/app/compliance/library')->viewData('page')['props']['frameworks'])->firstWhere('key', 'iso9001:2015');
        $this->assertFalse($card['activated']);
        $this->assertNull($card['register_url']);
        $this->assertNull($card['register_count']);
        $this->assertNull(collect($props['nodes'])->firstWhere('clause', '9.2.1')['requirement']);
        // Nor does the first customer's register reach the second's Etterlevelsesvurdering.
        $this->assertSame([], $this->actingAs($secondEditor)->get('/app/compliance/assessments')->viewData('page')['props']['requirements']);

        $this->actingAs($secondEditor)->get("/app/compliance/requirements/{$foreign->id}")->assertNotFound();
        $this->actingAs($secondEditor)->post("/app/compliance/requirements/{$foreign->id}/applicability", ['applicability' => 'applies'])->assertNotFound();

        // The second customer's own activation is its own rows; the first's are untouched.
        $this->actingAs($secondEditor)->post("/app/compliance/library/{$framework->id}/activate");
        $this->assertSame(65, ComplianceRequirement::query()->where('customer_id', $second->id)->count());
        $this->assertSame(65, ComplianceRequirement::query()->where('customer_id', $first->id)->count());
        $this->assertSame(2, ComplianceSource::query()->where('framework_id', $framework->id)->count());
    }

    // ---------------------------------------------------------------------
    // Relevance
    // ---------------------------------------------------------------------

    public function test_a_mandatory_requirement_can_never_be_not_applicable(): void
    {
        ['editor' => $editor, 'requirements' => $requirements] = $this->activated();

        $this->actingAs($editor)
            ->from($this->showUrl($requirements['9.2.1']))
            ->post($this->applicabilityUrl($requirements['9.2.1']), ['applicability' => 'not_applicable', 'rationale' => 'Vi gjennomfører ikke internrevisjon.'])
            ->assertSessionHasErrors('applicability');

        $this->assertSame(ComplianceRequirement::APPLICABILITY_APPLIES, $requirements['9.2.1']->fresh()->applicability);

        $props = $this->actingAs($editor)->get($this->showUrl($requirements['9.2.1']))->viewData('page')['props'];
        $this->assertSame(['applies', 'not_assessed'], $props['applicability']['options']);
    }

    public function test_not_applicable_needs_a_rationale_and_is_recorded_with_who_and_when(): void
    {
        ['editor' => $editor, 'requirements' => $requirements] = $this->activated();
        $design = $requirements['8.3.1'];

        $this->actingAs($editor)->from($this->showUrl($design))
            ->post($this->applicabilityUrl($design), ['applicability' => 'not_applicable', 'rationale' => '   '])
            ->assertSessionHasErrors('rationale');
        $this->assertSame(ComplianceRequirement::APPLICABILITY_NOT_ASSESSED, $design->fresh()->applicability);

        $this->actingAs($editor)->from($this->showUrl($design))
            ->post($this->applicabilityUrl($design), ['applicability' => 'not_applicable', 'rationale' => 'Vi leverer etter kundens spesifikasjon og utvikler ikke egne tjenester.'])
            ->assertSessionHasNoErrors();

        $fresh = $design->fresh();
        $this->assertSame(ComplianceRequirement::APPLICABILITY_NOT_APPLICABLE, $fresh->applicability);
        $this->assertSame('Vi leverer etter kundens spesifikasjon og utvikler ikke egne tjenester.', $fresh->applicability_rationale);
        $this->assertSame((int) $editor->id, (int) $fresh->applicability_set_by);
        $this->assertNotNull($fresh->applicability_set_at);

        // Ikke relevant reads as such, raises no attention and leaves no review due.
        $this->assertSame([], app(ComplianceAttentionService::class)->reasonsForRequirement($fresh));
        $show = $this->actingAs($editor)->get($this->showUrl($design))->viewData('page')['props'];
        $this->assertSame('not_applicable', $show['compliance']['status']);
        $this->assertFalse($show['permissions']['can_assess']);

        // The database holds the rule below the service.
        $this->assertDatabaseRefuses(fn () => DB::table('compliance_requirements')->where('id', $design->id)->update(['applicability_rationale' => null]));
    }

    public function test_relevance_needs_compliance_edit_and_a_library_requirement(): void
    {
        ['customer' => $customer, 'requirements' => $requirements] = $this->activated();
        $reader = $this->complianceReader($customer);
        $manual = $this->complianceRequirement($this->complianceSource($customer, 'Kontrakt', null, ComplianceSource::KIND_CONTRACT), 'Rapportering');
        $editor = $this->complianceEditor($customer);

        $this->actingAs($reader)->post($this->applicabilityUrl($requirements['8.3.1']), ['applicability' => 'applies'])->assertForbidden();
        $this->actingAs($editor)->from($this->showUrl($manual))
            ->post($this->applicabilityUrl($manual), ['applicability' => 'not_applicable', 'rationale' => 'Gjelder ikke.'])
            ->assertSessionHasErrors('applicability');

        $this->assertSame(ComplianceRequirement::APPLICABILITY_APPLIES, $manual->fresh()->applicability);
    }

    // ---------------------------------------------------------------------
    // Compliance assessments on library requirements
    // ---------------------------------------------------------------------

    public function test_a_library_requirement_is_assessed_only_while_it_applies_and_never_as_not_applicable(): void
    {
        ['customer' => $customer, 'requirements' => $requirements] = $this->activated();
        $assessor = $this->complianceMember($customer);
        $this->complianceGrant($customer, $assessor, [CustomerPermissionCatalog::COMPLIANCE_VIEW, CustomerPermissionCatalog::COMPLIANCE_ASSESS]);

        $this->actingAs($assessor)->from($this->showUrl($requirements['9.2.1']))
            ->post($this->assessUrl($requirements['9.2.1']), ['result' => 'not_applicable', 'rationale' => 'Ikke relevant.'])
            ->assertSessionHasErrors('result');

        // 8.3.1 is still Ikke vurdert for relevance: it is not assessed yet.
        $this->actingAs($assessor)->from($this->showUrl($requirements['8.3.1']))
            ->post($this->assessUrl($requirements['8.3.1']), ['result' => 'compliant', 'rationale' => 'Utviklingsprosessen er beskrevet.'])
            ->assertSessionHasErrors('result');

        $this->actingAs($assessor)->from($this->showUrl($requirements['9.2.1']))
            ->post($this->assessUrl($requirements['9.2.1']), ['result' => 'partially_compliant', 'rationale' => 'Revisjonsprogram mangler for 2027.'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, ComplianceAssessment::query()->where('customer_id', $customer->id)->count());
        $props = $this->actingAs($assessor)->get($this->showUrl($requirements['9.2.1']))->viewData('page')['props'];
        $this->assertSame('partially_compliant', $props['compliance']['status']);
        $this->assertNotContains('not_applicable', $props['assessment_results']);
        $this->assertSame('9.2.1', $props['library']['clause']);
    }

    public function test_linking_documentation_never_makes_a_requirement_compliant(): void
    {
        ['customer' => $customer, 'editor' => $editor, 'requirements' => $requirements] = $this->activated();
        $requirement = $requirements['7.5.1'];

        // Owner and review interval are the customer's; library text cannot be changed through Rediger.
        $this->actingAs($editor)->from($this->showUrl($requirement))->patch($this->showUrl($requirement), [
            'owner_user_id' => $editor->id,
            'review_interval_months' => 12,
            'title' => 'Omdøpt',
            'requirement_text' => 'Endret tekst',
        ])->assertSessionHasNoErrors();

        $fresh = $requirement->fresh();
        $this->assertSame((int) $editor->id, (int) $fresh->owner_user_id);
        $this->assertSame(12, $fresh->review_interval_months);
        $this->assertSame($requirement->title, $fresh->title);
        $this->assertSame($requirement->requirement_text, $fresh->requirement_text);

        $show = $this->actingAs($editor)->get($this->showUrl($requirement))->viewData('page')['props'];
        $this->assertSame('not_assessed', $show['compliance']['status']);
        $this->assertNotEmpty($show['library']['documentation']);
        $this->assertSame(0, ComplianceAssessment::query()->where('customer_id', $customer->id)->count());
    }

    public function test_nothing_is_registered_by_hand_under_an_activated_framework(): void
    {
        ['editor' => $editor, 'source' => $source] = $this->activated();

        $this->actingAs($editor)->from('/app/compliance/requirements')->post('/app/compliance/requirements', $this->requirementPayload($source, $editor, 'Eget krav', 'X.1'))
            ->assertSessionHasErrors('source_id');
        $this->actingAs($editor)->from('/app/compliance/requirements')->patch("/app/compliance/sources/{$source->id}", ['name' => 'Annet navn', 'kind' => 'standard'])
            ->assertSessionHas('error');

        $this->assertSame('ISO 9001', $source->fresh()->name);
    }

    // ---------------------------------------------------------------------
    // Rerunning the seeder with changed content
    // ---------------------------------------------------------------------

    public function test_a_changed_library_updates_the_register_and_keeps_the_customers_work(): void
    {
        ['customer' => $customer, 'editor' => $editor, 'requirements' => $requirements] = $this->activated();
        $assessor = $this->complianceMember($customer);
        $this->complianceGrant($customer, $assessor, [CustomerPermissionCatalog::COMPLIANCE_VIEW, CustomerPermissionCatalog::COMPLIANCE_ASSESS]);

        $requirements['4.1']->forceFill(['owner_user_id' => $editor->id, 'review_interval_months' => 12])->save();
        $this->actingAs($assessor)->post($this->assessUrl($requirements['4.1']), ['result' => 'compliant', 'rationale' => 'Kontekstanalysen er oppdatert.']);
        $this->actingAs($editor)->post($this->applicabilityUrl($requirements['8.5.5']), ['applicability' => 'not_applicable', 'rationale' => 'Ingen forpliktelser etter levering.']);

        $definition = $this->definition();
        // 4.1 gets new wording; 10.3 leaves the library; 10.4 is new.
        $definition['chapters'][0]['children'][0]['description'] = 'Ny formulering av 4.1.';
        array_pop($definition['chapters'][6]['children']);
        $definition['chapters'][6]['children'][] = [
            'clause' => '10.4', 'title' => 'Nytt punkt', 'description' => 'Et nytt krav.', 'guidance' => 'Veiledning.',
            'documentation' => ['Bevis'], 'type' => 'activity', 'info' => null, 'rule' => 'mandatory', 'modules' => [],
        ];

        $report = app(ComplianceFrameworkLibraryImporter::class)->import($definition);

        $this->assertSame(1, $report['created']);
        $this->assertSame(1, $report['updated']);
        $this->assertSame(1, $report['withdrawn']);
        $this->assertSame(1, $report['customers_synced']);
        $this->assertSame(1, $report['customer_requirements_created']);
        $this->assertSame(1, $report['customer_requirements_updated']);

        $fourOne = $requirements['4.1']->fresh();
        $this->assertSame('Ny formulering av 4.1.', $fourOne->requirement_text);
        $this->assertSame((int) $editor->id, (int) $fourOne->owner_user_id);
        $this->assertSame(12, $fourOne->review_interval_months);
        $this->assertSame(1, $fourOne->assessments()->count());
        $this->assertSame('compliant', $fourOne->assessments()->first()->result);
        $this->assertNotSame('Ny formulering av 4.1.', $fourOne->assessments()->first()->requirement_text, 'The assessment keeps the text it judged.');
        $this->assertTrue($this->actingAs($editor)->get($this->showUrl($fourOne))->viewData('page')['props']['compliance']['changed_since_assessment']);

        $this->assertSame(ComplianceRequirement::APPLICABILITY_NOT_APPLICABLE, $requirements['8.5.5']->fresh()->applicability);
        $this->assertSame('Ingen forpliktelser etter levering.', $requirements['8.5.5']->fresh()->applicability_rationale);

        // A withdrawn clause stays in the customer's register, flagged; the new one arrives.
        $this->assertNotNull($requirements['10.3']->fresh());
        $this->assertTrue($this->actingAs($editor)->get($this->showUrl($requirements['10.3']))->viewData('page')['props']['library']['withdrawn']);
        $this->assertTrue(ComplianceRequirement::query()->where('customer_id', $customer->id)->where('reference', '10.4')->exists());

        // The seeder's own file restores the library; the restored node is the same row.
        $restored = app(ComplianceFrameworkLibraryImporter::class)->import($this->definition());
        $this->assertSame(1, $restored['restored']);
        $this->assertSame(1, $restored['withdrawn']);
        $this->assertSame((int) $requirements['10.3']->framework_node_id, (int) ComplianceFrameworkNode::query()->where('node_key', 'iso9001:2015/10.3')->value('id'));
    }

    public function test_the_register_lists_iso_9001_in_the_standards_order(): void
    {
        ['editor' => $editor, 'source' => $source] = $this->activated();

        $rows = $this->actingAs($editor)->get("/app/compliance/requirements?view=list&source={$source->id}")->viewData('page')['props']['requirements'];

        $this->assertCount(65, $rows);
        $this->assertSame(['4.1', '4.2', '4.3'], array_slice(array_column($rows, 'reference'), 0, 3));
        $this->assertSame('10.3', end($rows)['reference']);
        $this->assertTrue($rows[0]['from_library']);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function definition(): array
    {
        return ComplianceFrameworkLibraryImporter::definitionFromFile(database_path('data/compliance_frameworks/iso9001_2015.php'));
    }

    private function importLibrary(): ComplianceFramework
    {
        return app(ComplianceFrameworkLibraryImporter::class)->import($this->definition())['framework'];
    }

    private function iso9001(): ComplianceFramework
    {
        return ComplianceFramework::query()->where('key', 'iso9001:2015')->sole();
    }

    /**
     * A customer with ISO 9001 activated by an editor.
     *
     * @return array{customer: Customer, editor: User, source: ComplianceSource, requirements: Collection<string, ComplianceRequirement>}
     */
    private function activated(): array
    {
        $framework = $this->importLibrary();
        ['customer' => $customer] = $this->complianceContext();
        $editor = $this->complianceEditor($customer);
        $source = app(ComplianceFrameworkActivationService::class)->activate($framework, $editor);

        return [
            'customer' => $customer,
            'editor' => $editor,
            'source' => $source,
            'requirements' => ComplianceRequirement::query()->where('customer_id', $customer->id)->get()->keyBy('reference'),
        ];
    }

    private function assertDatabaseRefuses(callable $write): void
    {
        DB::statement('SAVEPOINT library_refusal');

        try {
            $write();
            $this->fail('The database must refuse this write.');
        } catch (QueryException) {
            DB::statement('ROLLBACK TO SAVEPOINT library_refusal');
            $this->addToAssertionCount(1);
        }
    }

    private function showUrl(ComplianceRequirement $requirement): string
    {
        return "/app/compliance/requirements/{$requirement->id}";
    }

    private function applicabilityUrl(ComplianceRequirement $requirement): string
    {
        return "/app/compliance/requirements/{$requirement->id}/applicability";
    }

    private function assessUrl(ComplianceRequirement $requirement): string
    {
        return "/app/compliance/requirements/{$requirement->id}/assessments";
    }
}
