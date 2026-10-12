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
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesComplianceScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Kravbiblioteket with three standards — ISO 9001, ISO/IEC 27001 and ISO 14001 — on one engine.
 *
 * What these tests defend:
 *
 *  - The seeder builds all three, idempotently, and activates nothing. ISO/IEC 27001 has chapters
 *    4–10 (30 mandatory requirements) and Vedlegg A with exactly 93 controls (37/8/14/34); ISO
 *    14001:2026 has 35 mandatory requirements in the 2026 structure.
 *  - A customer can activate several standards; each is its own source, the register can be
 *    filtered on it, and nothing reaches another customer.
 *  - Annex A controls are not mandatory: Kontrollvalg is Inkludert or Utelatt, both justified (the
 *    basis for a Statement of Applicability), kept apart from etterlevelse — only an included
 *    control is assessed — and the framework page counts the controls apart from the chapters.
 *  - A rerun or a changed library never touches the customer's owners, choices or assessments.
 *  - Only compliance.edit makes a control choice.
 */
class ComplianceLibraryFrameworksTest extends TestCase
{
    use CreatesComplianceScenarios;
    use UsesProjectPostgresConnection;

    private const FILES = [
        'iso9001:2015' => 'iso9001_2015.php',
        'iso27001:2022' => 'iso27001_2022.php',
        'iso14001:2026' => 'iso14001_2026.php',
    ];

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

    public function test_the_seeder_builds_all_three_standards_and_running_it_again_changes_nothing(): void
    {
        ['customer' => $customer] = $this->complianceContext();

        $this->seed(ComplianceFrameworkLibrarySeeder::class);
        $before = ComplianceFrameworkNode::query()->orderBy('id')->get(['id', 'node_key', 'updated_at'])->toArray();
        $this->seed(ComplianceFrameworkLibrarySeeder::class);

        $this->assertSame($before, ComplianceFrameworkNode::query()->orderBy('id')->get(['id', 'node_key', 'updated_at'])->toArray());
        $this->assertSame(
            ['iso9001:2015', 'iso27001:2022', 'iso14001:2026'],
            ComplianceFramework::query()->whereIn('key', array_keys(self::FILES))->orderBy('position')->pluck('key')->all(),
        );

        foreach (array_keys(self::FILES) as $key) {
            $report = app(ComplianceFrameworkLibraryImporter::class)->import($this->definition($key));
            $this->assertSame(0, $report['created'] + $report['updated'] + $report['withdrawn'] + $report['restored'], $key);
            $this->assertFalse($report['framework']->content_verified, "{$key} is not yet checked against a licensed copy.");
        }

        $this->assertSame('2022 + A1:2024', $this->framework('iso27001:2022')->edition);
        $this->assertSame('2026', $this->framework('iso14001:2026')->edition);
        $this->assertFalse(ComplianceSource::query()->where('customer_id', $customer->id)->exists(), 'The seeder never activates anything.');
    }

    public function test_iso_27001_keeps_chapters_and_the_93_annex_a_controls_apart(): void
    {
        $framework = $this->import('iso27001:2022');
        $nodes = ComplianceFrameworkNode::query()->where('framework_id', $framework->id)->orderBy('position')->get()->keyBy('clause');
        $requirements = $nodes->where('kind', ComplianceFrameworkNode::KIND_REQUIREMENT);

        $this->assertSame(['4', '5', '6', '7', '8', '9', '10', 'A'], $nodes->where('depth', 1)->pluck('clause')->values()->all());
        $this->assertSame(123, $requirements->count());

        $chapters = $requirements->reject(fn (ComplianceFrameworkNode $node): bool => str_starts_with($node->clause, 'A.'));
        $controls = $requirements->filter(fn (ComplianceFrameworkNode $node): bool => str_starts_with($node->clause, 'A.'));
        $this->assertSame(30, $chapters->count());
        $this->assertSame([ComplianceFrameworkNode::RULE_MANDATORY], $chapters->pluck('applicability_rule')->unique()->values()->all());
        $this->assertSame(93, $controls->count());
        $this->assertSame([ComplianceFrameworkNode::RULE_CONTROL], $controls->pluck('applicability_rule')->unique()->values()->all());
        $this->assertSame(['control'], $controls->pluck('requirement_type')->unique()->values()->all());

        // Four themes under Vedlegg A, with the 2022 edition's counts, each control numbered in order.
        $this->assertSame(['A.5', 'A.6', 'A.7', 'A.8'], $nodes->where('parent_id', $nodes['A']->id)->pluck('clause')->values()->all());
        foreach (['A.5' => 37, 'A.6' => 8, 'A.7' => 14, 'A.8' => 34] as $theme => $count) {
            $expected = array_map(fn (int $n): string => "{$theme}.{$n}", range(1, $count));
            $this->assertSame($expected, $nodes->where('parent_id', $nodes[$theme]->id)->pluck('clause')->values()->all(), $theme);
        }

        // 2022 structure: 6.3 Planning of changes exists, and 10.1 is continual improvement before 10.2.
        $this->assertTrue($nodes['6.3']->isRequirement());
        $this->assertSame(['6.1.1', '6.1.2', '6.1.3'], $nodes->where('parent_id', $nodes['6.1']->id)->pluck('clause')->values()->all());
        $this->assertSame(['9.3.1', '9.3.2', '9.3.3'], $nodes->where('parent_id', $nodes['9.3']->id)->pluck('clause')->values()->all());
        $this->assertSame('iso27001:2022/A.8.13', $nodes['A.8.13']->node_key);
        $this->assertStringContainsString('klima', $nodes['4.1']->description);

        $this->assertEveryRequirementIsComplete($requirements);
    }

    public function test_iso_14001_follows_the_2026_structure_and_has_no_optional_clause(): void
    {
        $framework = $this->import('iso14001:2026');
        $nodes = ComplianceFrameworkNode::query()->where('framework_id', $framework->id)->orderBy('position')->get()->keyBy('clause');
        $requirements = $nodes->where('kind', ComplianceFrameworkNode::KIND_REQUIREMENT);

        $this->assertSame(['4', '5', '6', '7', '8', '9', '10'], $nodes->where('depth', 1)->pluck('clause')->values()->all());
        $this->assertSame(35, $requirements->count());
        $this->assertSame([ComplianceFrameworkNode::RULE_MANDATORY], $requirements->pluck('applicability_rule')->unique()->values()->all());

        // 6.1.4 is its own clause, planning moved to 6.1.5, 6.3 is new, 9.3 is split, 10.3 is gone.
        $this->assertSame(['6.1.1', '6.1.2', '6.1.3', '6.1.4', '6.1.5'], $nodes->where('parent_id', $nodes['6.1']->id)->pluck('clause')->values()->all());
        $this->assertSame(['6.2.1', '6.2.2'], $nodes->where('parent_id', $nodes['6.2']->id)->pluck('clause')->values()->all());
        $this->assertTrue($nodes['6.3']->isRequirement());
        $this->assertSame(['9.1.1', '9.1.2'], $nodes->where('parent_id', $nodes['9.1']->id)->pluck('clause')->values()->all());
        $this->assertSame(['9.3.1', '9.3.2', '9.3.3'], $nodes->where('parent_id', $nodes['9.3']->id)->pluck('clause')->values()->all());
        $this->assertSame(['10.1', '10.2'], $nodes->where('parent_id', $nodes['10']->id)->pluck('clause')->values()->all());
        $this->assertSame(['8.1', '8.2'], $nodes->where('parent_id', $nodes['8']->id)->pluck('clause')->values()->all());

        $this->assertEveryRequirementIsComplete($requirements);
    }

    // ---------------------------------------------------------------------
    // Activation, several standards, tenant isolation
    // ---------------------------------------------------------------------

    public function test_a_customer_activates_several_standards_and_filters_the_register_on_each(): void
    {
        $frameworks = $this->importAll();
        ['customer' => $customer] = $this->complianceContext();
        $editor = $this->complianceEditor($customer);

        foreach ($frameworks as $framework) {
            $this->actingAs($editor)->post("/app/compliance/library/{$framework->id}/activate")->assertSessionHas('success');
        }

        $sources = ComplianceSource::query()->where('customer_id', $customer->id)->get()->keyBy('name');
        $this->assertSame(['ISO 14001', 'ISO 9001', 'ISO/IEC 27001'], $sources->keys()->sort()->values()->all());
        $this->assertSame(65 + 123 + 35, ComplianceRequirement::query()->where('customer_id', $customer->id)->count());

        // Chapter requirements apply; Annex A controls wait for a Kontrollvalg.
        $iso27001 = $this->requirementsOf($customer, 'ISO/IEC 27001');
        $this->assertSame(ComplianceRequirement::APPLICABILITY_APPLIES, $iso27001['6.1.3']->applicability);
        $this->assertSame(ComplianceRequirement::APPLICABILITY_NOT_ASSESSED, $iso27001['A.5.1']->applicability);
        $this->assertSame(ComplianceRequirement::APPLICABILITY_APPLIES, $this->requirementsOf($customer, 'ISO 14001')['6.1.2']->applicability);

        // The library lists all three as activated; the register filters on one standard's source.
        $index = $this->actingAs($editor)->get('/app/compliance/library')->assertOk()->viewData('page')['props'];
        $this->assertSame([true, true, true], collect($index['frameworks'])->whereIn('key', array_keys(self::FILES))->pluck('activated')->values()->all());

        $register = $this->actingAs($editor)->get('/app/compliance/requirements?view=list&source='.$sources['ISO 14001']->id)->assertOk()->viewData('page')['props'];
        $this->assertCount(35, $register['requirements']);
        foreach ($register['requirements'] as $row) {
            $this->assertStringStartsWith('ISO 14001', $row['source_label']);
        }
    }

    public function test_one_customers_iso_27001_never_reaches_another(): void
    {
        $framework = $this->import('iso27001:2022');
        ['customer' => $first] = $this->complianceContext();
        ['customer' => $second] = $this->complianceContext();
        $firstEditor = $this->complianceEditor($first);
        $secondEditor = $this->complianceEditor($second);

        app(ComplianceFrameworkActivationService::class)->activate($framework, $firstEditor);
        $control = $this->requirementsOf($first, 'ISO/IEC 27001')['A.8.13'];
        $this->actingAs($firstEditor)->post($this->applicabilityUrl($control), ['applicability' => 'applies', 'rationale' => 'Tap av data er høy risiko.'])->assertSessionHasNoErrors();

        $props = $this->actingAs($secondEditor)->get("/app/compliance/library/{$framework->id}")->assertOk()->viewData('page')['props'];
        $this->assertNull($props['activation']);
        $this->assertNull(collect($props['nodes'])->firstWhere('clause', 'A.8.13')['requirement']);
        $this->assertSame([], $this->actingAs($secondEditor)->get('/app/compliance/assessments')->viewData('page')['props']['per_source']);

        $this->actingAs($secondEditor)->get($this->showUrl($control))->assertNotFound();
        $this->actingAs($secondEditor)->post($this->applicabilityUrl($control), ['applicability' => 'not_applicable', 'rationale' => 'Nei.'])->assertNotFound();
        $this->assertSame(ComplianceRequirement::APPLICABILITY_APPLIES, $control->fresh()->applicability);
    }

    public function test_each_card_activates_its_standard_and_then_opens_the_register_filtered_on_it(): void
    {
        $this->importAll();
        ['customer' => $customer] = $this->complianceContext();
        $editor = $this->complianceEditor($customer);
        $expected = ['iso9001:2015' => [65, false], 'iso27001:2022' => [123, true], 'iso14001:2026' => [35, false]];

        $cards = $this->cards($editor);
        $this->assertTrue($this->actingAs($editor)->get('/app/compliance/library')->viewData('page')['props']['permissions']['can_activate']);

        foreach ($expected as $key => [$count, $hasControls]) {
            $card = $cards[$key];
            $this->assertFalse($card['activated'], $key);
            $this->assertNull($card['register_url'], $key);
            $this->assertSame($count, $card['requirement_count'], $key);
            $this->assertSame($hasControls, $card['has_controls'], $key);
            $this->assertSame(url("/app/compliance/library/{$card['id']}/activate"), $card['activate_url'], $key);

            // From the card: back to the library, with the success message; a repeat creates nothing.
            $this->actingAs($editor)->post($card['activate_url'])->assertRedirect('/app/compliance/library')->assertSessionHas('success');
            $this->actingAs($editor)->post($card['activate_url'])->assertRedirect('/app/compliance/library');
        }

        $this->assertSame(3, ComplianceSource::query()->where('customer_id', $customer->id)->count());
        $this->assertSame(65 + 123 + 35, ComplianceRequirement::query()->where('customer_id', $customer->id)->count());

        // Activated: the card links to the register filtered on that standard, and only that one.
        foreach ($this->cards($editor) as $key => $card) {
            if (! isset($expected[$key])) {
                continue;
            }

            $this->assertTrue($card['activated'], $key);
            $source = ComplianceSource::query()->where('customer_id', $customer->id)->where('framework_id', $card['id'])->sole();
            $this->assertSame(route('app.compliance.requirements.index', ['source' => $source->id]), $card['register_url'], $key);

            $register = $this->actingAs($editor)->get($card['register_url'])->assertOk()->viewData('page')['props'];
            // It opens in Gruppert, narrowed to the one standard with all its requirements counted.
            $this->assertSame([(int) $source->id], array_column($register['register']['sources'], 'id'), $key);
            $this->assertSame($expected[$key][0], $register['matching_count'], $key);
        }
    }

    public function test_the_figures_across_standards_count_each_requirement_once_and_keep_annex_a_choices_apart(): void
    {
        $this->importAll();
        ['customer' => $customer] = $this->complianceContext();
        $editor = $this->complianceEditor($customer);
        $assessor = $this->complianceMember($customer);
        $this->complianceGrant($customer, $assessor, [CustomerPermissionCatalog::COMPLIANCE_VIEW, CustomerPermissionCatalog::COMPLIANCE_ASSESS]);

        // ISO 14001 stays not activated: it is in no figure.
        foreach (['iso9001:2015', 'iso27001:2022'] as $key) {
            app(ComplianceFrameworkActivationService::class)->activate($this->framework($key), $editor);
        }

        $iso9001 = $this->requirementsOf($customer, 'ISO 9001');
        $this->actingAs($editor)->post($this->applicabilityUrl($iso9001['8.3.1']), ['applicability' => 'not_applicable', 'rationale' => 'Ingen utvikling.']);
        $this->actingAs($assessor)->post($this->assessUrl($iso9001['9.2.1']), ['result' => 'partially_compliant', 'rationale' => 'Programmet mangler.']);
        $this->actingAs($assessor)->post($this->assessUrl($iso9001['9.3.1']), ['result' => 'compliant', 'rationale' => 'Gjennomført.']);

        $iso27001 = $this->requirementsOf($customer, 'ISO/IEC 27001');
        $this->actingAs($editor)->post($this->applicabilityUrl($iso27001['A.8.13']), ['applicability' => 'applies', 'rationale' => 'Høy risiko.']);
        $this->actingAs($editor)->post($this->applicabilityUrl($iso27001['A.5.1']), ['applicability' => 'applies', 'rationale' => 'Alltid med.']);
        $this->actingAs($editor)->post($this->applicabilityUrl($iso27001['A.8.28']), ['applicability' => 'not_applicable', 'rationale' => 'Ingen utvikling.']);
        $this->actingAs($assessor)->post($this->assessUrl($iso27001['A.8.13']), ['result' => 'partially_compliant', 'rationale' => 'Ikke testet.']);
        $this->actingAs($assessor)->post($this->assessUrl($iso27001['9.2.1']), ['result' => 'non_compliant', 'rationale' => 'Ingen revisjon.']);

        $page = $this->actingAs($editor)->get('/app/compliance/assessments')->assertOk()->viewData('page')['props'];
        $perSource = collect($page['per_source'])->keyBy('label');
        $this->assertSame(['ISO 9001:2015', 'ISO/IEC 27001:2022'], $perSource->keys()->all());

        // ISO 9001: 8.3.1 is outside the work, the other eight conditional clauses wait for relevance.
        $this->assertSame(
            ['assessable' => 56, 'assessed' => 2, 'compliant' => 1, 'partially_compliant' => 1, 'non_compliant' => 0, 'not_assessed' => 54, 'overdue' => 0, 'not_applicable' => 1, 'relevance_open' => 8],
            $perSource['ISO 9001:2015']['stats'],
        );
        // ISO/IEC 27001: the 30 chapter requirements and the two included controls are assessable; the
        // excluded control is neither a gap nor work left, and the 90 not selected are not either.
        $this->assertSame(
            ['assessable' => 32, 'assessed' => 2, 'compliant' => 0, 'partially_compliant' => 1, 'non_compliant' => 1, 'not_assessed' => 30, 'overdue' => 0, 'not_applicable' => 1, 'relevance_open' => 90],
            $perSource['ISO/IEC 27001:2022']['stats'],
        );
        // Across standards: each requirement once — the total is the sum of the rows.
        foreach ($page['stats'] as $key => $value) {
            $this->assertSame($perSource->sum(fn (array $row): int => $row['stats'][$key]), $value, $key);
        }
        $this->assertSame(88, $page['stats']['assessable']);

        // The same 9.2.1 in two standards are two requirements, each with its own origin and result.
        $gaps = collect($this->actingAs($editor)->get('/app/compliance/assessments?status=gaps')->viewData('page')['props']['requirements']);
        $this->assertSame(
            [['ISO 9001:2015', '9.2.1', 'partially_compliant'], ['ISO/IEC 27001:2022', '9.2.1', 'non_compliant'], ['ISO/IEC 27001:2022', 'A.8.13', 'partially_compliant']],
            $gaps->map(fn (array $row): array => [$row['origin']['source'], $row['origin']['reference'], $row['group']])->sortBy(fn (array $row): string => implode('|', $row))->values()->all(),
        );

        // Annex A in the worklist: an excluded control reads Utelatt, an undecided one waits for its choice.
        $outside = collect($this->actingAs($editor)->get('/app/compliance/assessments?status=not_applicable')->viewData('page')['props']['requirements'])->keyBy(fn (array $row): string => $row['origin']['reference']);
        $this->assertSame(['8.3.1', 'A.8.28'], $outside->keys()->sort()->values()->all());
        $this->assertTrue($outside['A.8.28']['is_control']);
        $this->assertFalse($outside['8.3.1']['is_control']);
        $open = collect($this->actingAs($editor)->get('/app/compliance/assessments?status=relevance_open')->viewData('page')['props']['requirements']);
        $this->assertSame(98, $open->count());
        $this->assertNull($open->firstWhere('origin.reference', 'A.5.2')['assess_url'], 'An undecided control cannot be assessed yet.');
        $this->assertNotNull($open->firstWhere('origin.reference', 'A.5.2')['relevance_url']);

        // The library cards only say what is activated and how much is in the register.
        $cards = $this->cards($editor);
        $this->assertSame(65, $cards['iso9001:2015']['register_count']);
        $this->assertSame(123, $cards['iso27001:2022']['register_count']);
        $this->assertNull($cards['iso14001:2026']['register_count']);
        $this->assertArrayNotHasKey('stats', $cards['iso9001:2015']);

        // Someone who may only read sees the same figures; no one outside the module sees any.
        $reader = $this->complianceReader($customer);
        $this->assertSame($page['stats'], $this->actingAs($reader)->get('/app/compliance/assessments')->viewData('page')['props']['stats']);
        $this->actingAs($this->complianceMember($customer))->get('/app/compliance/assessments')->assertForbidden();
    }

    public function test_the_figures_never_include_another_customers(): void
    {
        $this->importAll();
        ['customer' => $first] = $this->complianceContext();
        ['customer' => $second] = $this->complianceContext();
        $firstEditor = $this->complianceEditor($first);
        $secondEditor = $this->complianceEditor($second);
        $assessor = $this->complianceMember($first);
        $this->complianceGrant($first, $assessor, [CustomerPermissionCatalog::COMPLIANCE_VIEW, CustomerPermissionCatalog::COMPLIANCE_ASSESS]);

        app(ComplianceFrameworkActivationService::class)->activate($this->framework('iso14001:2026'), $firstEditor);
        $this->actingAs($assessor)->post($this->assessUrl($this->requirementsOf($first, 'ISO 14001')['6.1.2']), ['result' => 'compliant', 'rationale' => 'Registeret er komplett.']);

        $figures = fn (User $user): array => $this->actingAs($user)->get('/app/compliance/assessments')->viewData('page')['props']['stats'];
        $this->assertSame(0, $figures($secondEditor)['assessable']);

        app(ComplianceFrameworkActivationService::class)->activate($this->framework('iso14001:2026'), $secondEditor);
        $this->assertSame(['assessable' => 35, 'assessed' => 0, 'compliant' => 0], array_intersect_key($figures($secondEditor), array_flip(['assessable', 'assessed', 'compliant'])));
        $this->assertSame(1, $figures($firstEditor)['compliant']);
    }

    public function test_the_worklist_and_the_library_read_each_standard_once_however_many_requirements_are_assessed(): void
    {
        $this->importAll();
        ['customer' => $customer] = $this->complianceContext();
        $editor = $this->complianceEditor($customer);
        $assessor = $this->complianceMember($customer);
        $this->complianceGrant($customer, $assessor, [CustomerPermissionCatalog::COMPLIANCE_VIEW, CustomerPermissionCatalog::COMPLIANCE_ASSESS]);

        foreach (array_keys(self::FILES) as $key) {
            app(ComplianceFrameworkActivationService::class)->activate($this->framework($key), $editor);
        }

        $countQueries = function (string $url) use ($editor): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($editor)->get($url)->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $this->actingAs($assessor)->post($this->assessUrl($this->requirementsOf($customer, 'ISO 14001')['4.1']), ['result' => 'compliant', 'rationale' => 'Ok.']);
        // Warm up: per-process lookups (the customer) are cached after the first request.
        $countQueries('/app/compliance/assessments');
        $countQueries('/app/compliance/library');
        $before = [$countQueries('/app/compliance/assessments'), $countQueries('/app/compliance/library')];

        foreach (['4.2', '4.3', '5.1', '5.2', '6.1.2', '6.1.3'] as $clause) {
            $this->actingAs($assessor)->post($this->assessUrl($this->requirementsOf($customer, 'ISO 14001')[$clause]), ['result' => 'non_compliant', 'rationale' => 'Mangler.']);
        }

        $this->assertSame($before, [$countQueries('/app/compliance/assessments'), $countQueries('/app/compliance/library')], 'Neither page may query per requirement or per assessment.');
        $perSource = collect($this->actingAs($editor)->get('/app/compliance/assessments')->viewData('page')['props']['per_source'])->keyBy('label');
        $this->assertSame(6, $perSource['ISO 14001:2026']['stats']['non_compliant']);
    }

    public function test_activating_keeps_what_the_customer_already_has(): void
    {
        ['customer' => $customer, 'editor' => $editor, 'requirements' => $requirements, 'framework' => $framework] = $this->activated('iso27001:2022');
        $control = $requirements['A.5.1'];
        $control->forceFill(['owner_user_id' => $editor->id])->save();
        $this->actingAs($editor)->post($this->applicabilityUrl($control), ['applicability' => 'applies', 'rationale' => 'Alltid med.']);

        $this->actingAs($editor)->post("/app/compliance/library/{$framework->id}/activate")->assertSessionHas('success', __('procynia.compliance.library.flash.already_activated', ['name' => 'ISO/IEC 27001']));

        $fresh = $control->fresh();
        $this->assertSame((int) $editor->id, (int) $fresh->owner_user_id);
        $this->assertSame(ComplianceRequirement::APPLICABILITY_APPLIES, $fresh->applicability);
        $this->assertSame(123, ComplianceRequirement::query()->where('customer_id', $customer->id)->count());
    }

    // ---------------------------------------------------------------------
    // Annex A: Kontrollvalg, kept apart from etterlevelse
    // ---------------------------------------------------------------------

    public function test_a_control_is_included_or_excluded_and_both_need_a_justification(): void
    {
        ['editor' => $editor, 'requirements' => $requirements] = $this->activated('iso27001:2022');
        $backup = $requirements['A.8.13'];
        $coding = $requirements['A.8.28'];

        $props = $this->actingAs($editor)->get($this->showUrl($backup))->viewData('page')['props'];
        $this->assertSame(['applies', 'not_applicable', 'not_assessed'], $props['applicability']['options']);
        $this->assertSame('control', $props['library']['applicability_rule']);

        foreach (['applies', 'not_applicable'] as $choice) {
            $this->actingAs($editor)->from($this->showUrl($backup))
                ->post($this->applicabilityUrl($backup), ['applicability' => $choice, 'rationale' => ' '])
                ->assertSessionHasErrors('rationale');
        }
        $this->assertSame(ComplianceRequirement::APPLICABILITY_NOT_ASSESSED, $backup->fresh()->applicability);

        $this->actingAs($editor)->from($this->showUrl($backup))
            ->post($this->applicabilityUrl($backup), ['applicability' => 'applies', 'rationale' => 'Inkludert fordi tap av kundedata er høy risiko.'])
            ->assertSessionHasNoErrors();
        $this->actingAs($editor)->from($this->showUrl($coding))
            ->post($this->applicabilityUrl($coding), ['applicability' => 'not_applicable', 'rationale' => 'Utelatt fordi vi ikke utvikler programvare selv.'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Inkludert fordi tap av kundedata er høy risiko.', $backup->fresh()->applicability_rationale);
        $this->assertSame((int) $editor->id, (int) $backup->fresh()->applicability_set_by);
        $this->assertSame(ComplianceRequirement::APPLICABILITY_NOT_APPLICABLE, $coding->fresh()->applicability);

        // A chapter requirement of the management system can never be left out.
        $this->actingAs($editor)->from($this->showUrl($requirements['6.1.3']))
            ->post($this->applicabilityUrl($requirements['6.1.3']), ['applicability' => 'not_applicable', 'rationale' => 'Vi har ingen SoA.'])
            ->assertSessionHasErrors('applicability');
        $this->assertSame(ComplianceRequirement::APPLICABILITY_APPLIES, $requirements['6.1.3']->fresh()->applicability);
    }

    public function test_only_an_included_control_is_assessed_and_an_excluded_one_needs_no_attention(): void
    {
        ['customer' => $customer, 'editor' => $editor, 'requirements' => $requirements] = $this->activated('iso27001:2022');
        $assessor = $this->complianceMember($customer);
        $this->complianceGrant($customer, $assessor, [CustomerPermissionCatalog::COMPLIANCE_VIEW, CustomerPermissionCatalog::COMPLIANCE_ASSESS]);
        $backup = $requirements['A.8.13'];
        $coding = $requirements['A.8.28'];

        // Not yet chosen: no assessment.
        $this->actingAs($assessor)->from($this->showUrl($backup))
            ->post($this->assessUrl($backup), ['result' => 'compliant', 'rationale' => 'Kopier tas hver natt.'])
            ->assertSessionHasErrors('result');

        $this->actingAs($editor)->post($this->applicabilityUrl($backup), ['applicability' => 'applies', 'rationale' => 'Høy risiko for tap av data.']);
        $this->actingAs($editor)->post($this->applicabilityUrl($coding), ['applicability' => 'not_applicable', 'rationale' => 'Vi utvikler ikke programvare.']);

        // Included: assessed like any requirement, never as «Ikke relevant», never compliant by itself.
        $this->assertSame('not_assessed', $this->actingAs($assessor)->get($this->showUrl($backup))->viewData('page')['props']['compliance']['status']);
        $this->actingAs($assessor)->from($this->showUrl($backup))
            ->post($this->assessUrl($backup), ['result' => 'not_applicable', 'rationale' => 'Ikke relevant.'])
            ->assertSessionHasErrors('result');
        $this->actingAs($assessor)->from($this->showUrl($backup))
            ->post($this->assessUrl($backup), ['result' => 'partially_compliant', 'rationale' => 'Gjenoppretting er ikke testet.'])
            ->assertSessionHasNoErrors();
        $this->assertSame('partially_compliant', $this->actingAs($assessor)->get($this->showUrl($backup))->viewData('page')['props']['compliance']['status']);

        // Excluded: not assessable, reads as not applicable, raises no attention.
        $this->actingAs($assessor)->from($this->showUrl($coding))
            ->post($this->assessUrl($coding), ['result' => 'compliant', 'rationale' => 'Ok.'])
            ->assertSessionHasErrors('result');
        $this->assertSame([], app(ComplianceAttentionService::class)->reasonsForRequirement($coding->fresh()));
        $this->assertSame(1, ComplianceAssessment::query()->where('customer_id', $customer->id)->count());
    }

    public function test_the_framework_page_keeps_the_chapters_and_the_annex_a_controls_apart(): void
    {
        ['customer' => $customer, 'editor' => $editor, 'requirements' => $requirements, 'framework' => $framework] = $this->activated('iso27001:2022');
        $assessor = $this->complianceMember($customer);
        $this->complianceGrant($customer, $assessor, [CustomerPermissionCatalog::COMPLIANCE_VIEW, CustomerPermissionCatalog::COMPLIANCE_ASSESS]);

        $this->actingAs($editor)->post($this->applicabilityUrl($requirements['A.8.13']), ['applicability' => 'applies', 'rationale' => 'Høy risiko.']);
        $this->actingAs($editor)->post($this->applicabilityUrl($requirements['A.8.28']), ['applicability' => 'not_applicable', 'rationale' => 'Ingen utvikling.']);
        $this->actingAs($assessor)->post($this->assessUrl($requirements['A.8.13']), ['result' => 'compliant', 'rationale' => 'Testet gjenoppretting i september.']);
        $this->actingAs($assessor)->post($this->assessUrl($requirements['9.2.1']), ['result' => 'non_compliant', 'rationale' => 'Ingen internrevisjon gjennomført.']);

        $props = $this->actingAs($editor)->get("/app/compliance/library/{$framework->id}")->assertOk()->viewData('page')['props'];
        $this->assertTrue($props['framework']['has_controls']);
        $this->assertSame(30, collect($props['nodes'])->where('kind', 'requirement')->filter(fn (array $node): bool => $node['applicability_rule'] !== 'control')->count());
        $this->assertSame(93, collect($props['nodes'])->where('applicability_rule', 'control')->count());
        $this->assertArrayNotHasKey('stat_groups', $props);

        $node = collect($props['nodes'])->firstWhere('clause', 'A.8.28');
        $this->assertSame('control', $node['applicability_rule']);
        $this->assertSame('not_applicable', $node['requirement']['applicability']);

        // The compliance figures live in Etterlevelsesvurdering, the Annex A choices kept apart there.
        $source = ComplianceSource::query()->where('customer_id', $customer->id)->where('framework_id', $framework->id)->sole();
        $stats = $this->actingAs($editor)->get('/app/compliance/assessments?source='.$source->id)->viewData('page')['props']['stats'];
        $this->assertSame(['assessable' => 31, 'assessed' => 2, 'compliant' => 1, 'non_compliant' => 1, 'not_applicable' => 1, 'relevance_open' => 91], array_intersect_key($stats, array_flip(['assessable', 'assessed', 'compliant', 'non_compliant', 'not_applicable', 'relevance_open'])));

        // Without controls (ISO 14001) the page is the same tree, no controls.
        $iso14001 = $this->import('iso14001:2026');
        app(ComplianceFrameworkActivationService::class)->activate($iso14001, $editor);
        $props = $this->actingAs($editor)->get("/app/compliance/library/{$iso14001->id}")->viewData('page')['props'];
        $this->assertFalse($props['framework']['has_controls']);
        $this->assertSame(35, $props['framework']['requirement_count']);
    }

    public function test_a_control_choice_needs_compliance_edit(): void
    {
        ['customer' => $customer, 'requirements' => $requirements, 'framework' => $framework] = $this->activated('iso27001:2022');
        $reader = $this->complianceReader($customer);

        $this->actingAs($reader)->post($this->applicabilityUrl($requirements['A.5.1']), ['applicability' => 'applies', 'rationale' => 'Alltid med.'])->assertForbidden();
        $this->assertSame(ComplianceRequirement::APPLICABILITY_NOT_ASSESSED, $requirements['A.5.1']->fresh()->applicability);

        $iso14001 = $this->import('iso14001:2026');
        $this->actingAs($reader)->post("/app/compliance/library/{$iso14001->id}/activate")->assertForbidden();
        $this->assertSame(1, ComplianceSource::query()->where('customer_id', $customer->id)->count());
        $this->assertFalse($this->actingAs($reader)->get('/app/compliance/library')->viewData('page')['props']['permissions']['can_activate']);
        $this->actingAs($reader)->get("/app/compliance/library/{$framework->id}")->assertOk();
    }

    // ---------------------------------------------------------------------
    // The customer's work survives the library changing
    // ---------------------------------------------------------------------

    public function test_a_changed_library_keeps_the_customers_choices_owners_and_assessments(): void
    {
        ['customer' => $customer, 'editor' => $editor, 'requirements' => $requirements, 'framework' => $framework] = $this->activated('iso27001:2022');
        $assessor = $this->complianceMember($customer);
        $this->complianceGrant($customer, $assessor, [CustomerPermissionCatalog::COMPLIANCE_VIEW, CustomerPermissionCatalog::COMPLIANCE_ASSESS]);
        $backup = $requirements['A.8.13'];
        $backup->forceFill(['owner_user_id' => $editor->id, 'review_interval_months' => 12])->save();
        $this->actingAs($editor)->post($this->applicabilityUrl($backup), ['applicability' => 'applies', 'rationale' => 'Høy risiko.']);
        $this->actingAs($assessor)->post($this->assessUrl($backup), ['result' => 'compliant', 'rationale' => 'Testet.']);
        $coding = $requirements['A.8.28'];
        $this->actingAs($editor)->post($this->applicabilityUrl($coding), ['applicability' => 'not_applicable', 'rationale' => 'Ingen utvikling.']);

        // Same file again: nothing changes.
        $this->assertSame(0, app(ComplianceFrameworkLibraryImporter::class)->import($this->definition('iso27001:2022'))['customer_requirements_updated']);

        // A changed file: a retitled control, a removed one, a new one.
        $definition = $this->definition('iso27001:2022');
        $annex = &$definition['chapters'][7]['children'][3]['children'];
        foreach ($annex as $index => &$control) {
            if ($control['clause'] === 'A.8.13') {
                $control['title'] = 'Sikkerhetskopiering av informasjon';
            }
            if ($control['clause'] === 'A.8.28') {
                unset($annex[$index]);
            }
        }
        unset($control);
        $annex[] = ['clause' => 'A.8.35', 'title' => 'Testkontroll', 'description' => 'Kontrollen går ut på at noe testes.', 'guidance' => 'Test.', 'documentation' => ['Test'], 'type' => 'control', 'info' => null, 'rule' => 'control', 'modules' => []];
        $annex = array_values($annex);
        unset($annex);

        $report = app(ComplianceFrameworkLibraryImporter::class)->import($definition);
        $this->assertSame(1, $report['withdrawn']);
        $this->assertSame(1, $report['customer_requirements_created']);

        $fresh = $backup->fresh();
        $this->assertSame('Sikkerhetskopiering av informasjon', $fresh->title);
        $this->assertSame((int) $editor->id, (int) $fresh->owner_user_id);
        $this->assertSame(12, (int) $fresh->review_interval_months);
        $this->assertSame(ComplianceRequirement::APPLICABILITY_APPLIES, $fresh->applicability);
        $this->assertSame('Høy risiko.', $fresh->applicability_rationale);
        $this->assertSame(1, ComplianceAssessment::query()->where('requirement_id', $backup->id)->count());

        // The withdrawn control and its justification stay in the register; the new one waits for a choice.
        $this->assertSame('Ingen utvikling.', $coding->fresh()->applicability_rationale);
        $this->assertTrue($coding->fresh()->frameworkNode->isWithdrawn());
        $new = ComplianceRequirement::query()->where('customer_id', $customer->id)->where('reference', 'A.8.35')->sole();
        $this->assertSame(ComplianceRequirement::APPLICABILITY_NOT_ASSESSED, $new->applicability);
        $this->assertSame(1, ComplianceSource::query()->where('customer_id', $customer->id)->where('framework_id', $framework->id)->count());
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function definition(string $key): array
    {
        return ComplianceFrameworkLibraryImporter::definitionFromFile(database_path('data/compliance_frameworks/'.self::FILES[$key]));
    }

    private function import(string $key): ComplianceFramework
    {
        return app(ComplianceFrameworkLibraryImporter::class)->import($this->definition($key))['framework'];
    }

    /** @return list<ComplianceFramework> */
    private function importAll(): array
    {
        return array_map(fn (string $key): ComplianceFramework => $this->import($key), array_keys(self::FILES));
    }

    private function framework(string $key): ComplianceFramework
    {
        return ComplianceFramework::query()->where('key', $key)->sole();
    }

    /** @return array<string, array<string, mixed>> the library's cards, keyed by framework key */
    private function cards(User $user): array
    {
        return collect($this->actingAs($user)->get('/app/compliance/library')->assertOk()->viewData('page')['props']['frameworks'])->keyBy('key')->all();
    }

    /** @return Collection<string, ComplianceRequirement> keyed by reference */
    private function requirementsOf(Customer $customer, string $sourceName): Collection
    {
        return ComplianceRequirement::query()
            ->where('customer_id', $customer->id)
            ->whereHas('source', fn ($query) => $query->where('name', $sourceName))
            ->get()
            ->keyBy('reference');
    }

    /**
     * A customer with one standard activated by an editor.
     *
     * @return array{customer: Customer, editor: User, framework: ComplianceFramework, requirements: Collection<string, ComplianceRequirement>}
     */
    private function activated(string $key): array
    {
        $framework = $this->import($key);
        ['customer' => $customer] = $this->complianceContext();
        $editor = $this->complianceEditor($customer);
        app(ComplianceFrameworkActivationService::class)->activate($framework, $editor);

        return [
            'customer' => $customer,
            'editor' => $editor,
            'framework' => $framework,
            'requirements' => ComplianceRequirement::query()->where('customer_id', $customer->id)->get()->keyBy('reference'),
        ];
    }

    /** @param  Collection<string, ComplianceFrameworkNode>  $requirements */
    private function assertEveryRequirementIsComplete(Collection $requirements): void
    {
        foreach ($requirements as $requirement) {
            $this->assertNotEmpty($requirement->title, $requirement->clause);
            $this->assertNotEmpty($requirement->description, $requirement->clause);
            $this->assertNotEmpty($requirement->guidance, $requirement->clause);
            $this->assertNotEmpty($requirement->suggested_documentation, $requirement->clause);
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
