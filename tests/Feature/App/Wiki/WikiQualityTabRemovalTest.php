<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiLintFinding;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Support\EnterpriseWiki\WikiQualityCheckPresentation;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The separate "Kvalitet" tab was retired: a concrete quality problem is now explained on the Wiki
 * page it belongs to, so a second surface listing the same rows as technical data was work the
 * reader had to do twice.
 *
 * What must NOT change is the engine behind it — findings are still generated, stored, severity-
 * graded and page-bound exactly as before, and they are still rendered (with human copy) on their
 * own page. These tests pin the removal on one side and the survival of everything else on the
 * other, so a later cleanup cannot quietly take the engine with it.
 */
class WikiQualityTabRemovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->app->setLocale('no');
    }

    // =========================================================================
    // The tab itself is gone
    // =========================================================================

    public function test_the_wiki_navigation_no_longer_offers_a_quality_tab(): void
    {
        $layout = (string) file_get_contents(base_path('resources/js/Layouts/CustomerAppLayout.jsx'));

        $this->assertStringNotContainsString('wiki-quality', $layout);
        $this->assertStringNotContainsString("tab: 'quality'", $layout);

        foreach (['wiki-pages', 'wiki-sources', 'wiki-runs', 'wiki-graph'] as $keptTab) {
            $this->assertStringContainsString($keptTab, $layout, "the {$keptTab} nav item must be kept");
        }
    }

    public function test_a_stale_quality_link_falls_back_to_the_standard_tab_instead_of_breaking(): void
    {
        $user = $this->createUser($this->createCustomer());

        $response = $this->actingAs($user)->get('/app/wiki?tab=quality');

        $response->assertOk();

        $props = data_get($response->viewData('page'), 'props', []);

        $this->assertSame('pages', $props['active_tab'], 'an unknown tab resolves to the default one');
        $this->assertArrayHasKey('pages', $props);
    }

    public function test_the_quality_only_props_are_no_longer_served(): void
    {
        $user = $this->createUser($this->createCustomer());

        foreach (['?tab=quality', '?tab=pages', ''] as $query) {
            $props = data_get(
                $this->actingAs($user)->get('/app/wiki'.$query)->viewData('page'),
                'props',
                [],
            );

            foreach (['quality_findings', 'quality_filters', 'coverage', 'lint_health'] as $retired) {
                $this->assertArrayNotHasKey($retired, $props, "{$retired} must not be served any more");
            }
        }
    }

    public function test_the_quality_tab_component_and_loader_are_gone_from_the_codebase(): void
    {
        $index = (string) file_get_contents(base_path('resources/js/Pages/App/Wiki/Index.jsx'));
        $controller = (string) file_get_contents(base_path('app/Http/Controllers/App/WikiController.php'));

        $this->assertStringNotContainsString('function QualityTab(', $index);
        $this->assertStringNotContainsString('function CoveragePanel(', $index);
        $this->assertStringNotContainsString('function LintHealthBar(', $index);
        $this->assertStringNotContainsString('loadQualityTab', $controller);
        $this->assertStringNotContainsString('qualityFindingTargetUrl', $controller);
    }

    // =========================================================================
    // The engine and the page-level presentation survive
    // =========================================================================

    public function test_a_wiki_page_still_shows_its_own_findings_with_human_copy(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $run = $this->createAppliedRun($customer, $this->createDocument($customer));
        $page = $this->createVersionedPage($customer, $run, 'Figursiden');

        $this->createLintFinding($customer, $run, $page, EnterpriseWikiLintFinding::CODE_PLANNED_FIGURE_MISSING, EnterpriseWikiLintFinding::SEVERITY_ERROR);

        $props = data_get($this->actingAs($user)->get("/app/wiki/{$page->slug}")->viewData('page'), 'props', []);
        $findings = collect($props['lint_findings'] ?? []);

        $this->assertCount(1, $findings);
        $this->assertSame(EnterpriseWikiLintFinding::CODE_PLANNED_FIGURE_MISSING, $findings->first()['code']);
        $this->assertSame('error', $findings->first()['severity']);

        $copy = WikiQualityCheckPresentation::copy(EnterpriseWikiLintFinding::CODE_PLANNED_FIGURE_MISSING);
        $this->assertSame('Planlagt figur mangler', $copy['label']);
        $this->assertNotSame('', trim($copy['action']));
    }

    public function test_a_finding_for_one_page_is_still_never_shown_on_another(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $run = $this->createAppliedRun($customer, $this->createDocument($customer));
        $pageA = $this->createVersionedPage($customer, $run, 'Side A');
        $pageB = $this->createVersionedPage($customer, $run, 'Side B');

        $this->createLintFinding($customer, $run, $pageA, EnterpriseWikiLintFinding::CODE_PLANNED_FIGURE_MISSING, EnterpriseWikiLintFinding::SEVERITY_ERROR);

        $onA = collect(data_get($this->actingAs($user)->get("/app/wiki/{$pageA->slug}")->viewData('page'), 'props.lint_findings', []));
        $onB = collect(data_get($this->actingAs($user)->get("/app/wiki/{$pageB->slug}")->viewData('page'), 'props.lint_findings', []));

        $this->assertCount(1, $onA);
        $this->assertCount(0, $onB);
    }

    public function test_the_page_scoped_deep_link_still_opens_the_finding_on_its_own_page(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $run = $this->createAppliedRun($customer, $this->createDocument($customer));
        $page = $this->createVersionedPage($customer, $run, 'Panelsiden');

        $finding = $this->createLintFinding($customer, $run, $page, EnterpriseWikiLintFinding::CODE_PLANNED_FIGURE_MISSING, EnterpriseWikiLintFinding::SEVERITY_ERROR);

        $props = data_get(
            $this->actingAs($user)->get("/app/wiki/{$page->slug}?finding_id={$finding->id}")->viewData('page'),
            'props',
            [],
        );

        $this->assertSame($finding->id, $props['structure_finding']['id'] ?? null);
        $this->assertSame('Planlagt figur mangler', $props['structure_finding']['category_label'] ?? null);
        $this->assertNotSame('', trim((string) ($props['structure_finding']['action'] ?? '')));
    }

    /**
     * A run-scoped finding has no page to be explained on. It keeps its existing home in the
     * Kjøringer "Funn" panel, which loads every finding for the run regardless of page binding — so
     * retiring the Kvalitet tab hides nothing that was only visible there.
     */
    public function test_a_finding_without_a_page_is_still_reachable_through_its_run(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $run = $this->createAppliedRun($customer, $this->createDocument($customer));

        $finding = EnterpriseWikiLintFinding::query()->create([
            'customer_id' => $customer->id,
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => null,
            'code' => EnterpriseWikiLintFinding::CODE_APPLIED_RUN_WITHOUT_ARTICLE,
            'severity' => EnterpriseWikiLintFinding::SEVERITY_ERROR,
            'message' => 'Run has no article page.',
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
            'detected_at' => now(),
        ]);

        $findings = collect(
            $this->actingAs($user)->getJson("/app/wiki/runs/{$run->id}/findings")->assertOk()->json('findings'),
        )->keyBy('id');

        $row = $findings['lint-'.$finding->id] ?? null;

        $this->assertNotNull($row, 'a run-scoped finding must still be listed under its run');
        $this->assertSame('run', $row['scope']);
        $this->assertNull($row['page_id']);
        $this->assertNull($row['url'], 'a finding with no page is never forced onto one');
        $this->assertNotSame('', trim((string) $row['title']), 'it still gets human copy');
    }

    // =========================================================================
    // Nothing moved to the overview, nothing changed in the engine
    // =========================================================================

    public function test_the_wiki_page_list_gained_no_quality_information(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $run = $this->createAppliedRun($customer, $this->createDocument($customer));
        $page = $this->createVersionedPage($customer, $run, 'Oversiktssiden');

        $this->createLintFinding($customer, $run, $page, EnterpriseWikiLintFinding::CODE_PLANNED_FIGURE_MISSING, EnterpriseWikiLintFinding::SEVERITY_ERROR);

        $props = data_get($this->actingAs($user)->get('/app/wiki')->viewData('page'), 'props', []);
        $listedPage = collect($props['pages'] ?? [])->firstWhere('id', $page->id);

        $this->assertNotNull($listedPage);

        foreach (['lint_findings', 'quality_findings', 'findings', 'remedy', 'finding_action'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $listedPage, "the page list must not grow a {$forbidden} field");
        }
    }

    public function test_the_lint_row_itself_is_untouched_by_the_removal(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $run = $this->createAppliedRun($customer, $this->createDocument($customer));
        $page = $this->createVersionedPage($customer, $run, 'Uendret');

        $finding = $this->createLintFinding($customer, $run, $page, EnterpriseWikiLintFinding::CODE_PLANNED_FIGURE_MISSING, EnterpriseWikiLintFinding::SEVERITY_ERROR);
        $before = $finding->only(['code', 'severity', 'message', 'status', 'enterprise_wiki_page_id']);

        $this->actingAs($user)->get('/app/wiki?tab=quality')->assertOk();
        $this->actingAs($user)->get("/app/wiki/{$page->slug}")->assertOk();

        $this->assertSame($before, $finding->fresh()->only(['code', 'severity', 'message', 'status', 'enterprise_wiki_page_id']));
        $this->assertSame(44, count(EnterpriseWikiLintFinding::CODES), 'the finding code catalog is unchanged');
    }

    public function test_the_retired_quality_page_translation_keys_are_gone_but_the_page_copy_survives(): void
    {
        foreach (['no', 'en'] as $locale) {
            $wiki = (require base_path("lang/{$locale}/procynia.php"))['wiki'] ?? [];

            foreach (['quality_page_help_title', 'quality_row_open', 'quality_empty', 'coverage_title', 'lint_health_ok', 'tab_quality'] as $retired) {
                $this->assertArrayNotHasKey($retired, $wiki, "{$retired} is dead copy for the removed tab");
            }

            foreach ([
                'quality_checks',
                'quality_check_actions',
                'quality_check_unknown_label',
                'quality_check_action_label',
                'quality_check_technical_reference_label',
                'quality_col_severity',
                'lint_health_title',
                'lint_severity_error',
            ] as $kept) {
                $this->assertArrayHasKey($kept, $wiki, "{$kept} is still used outside the removed tab");
            }
        }
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    private function createCustomer(string $name = 'Kvalitetsopprydding AS'): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    private function createUser(Customer $customer): User
    {
        return User::query()->create([
            'name' => 'System Owner',
            'email' => Str::lower(Str::random(8)).'@kvalitetsopprydding.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function createDocument(Customer $customer): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'kilde.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Kildetekst.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createAppliedRun(Customer $customer, EnterpriseWikiDocument $document): EnterpriseWikiIngestRun
    {
        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_QA,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
            'maintainer_decision_json' => ['pages' => []],
        ]);
    }

    private function createVersionedPage(Customer $customer, EnterpriseWikiIngestRun $run, string $title): EnterpriseWikiPage
    {
        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(4)),
            'title' => $title,
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_APPROVED,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
        ]);

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => "# {$title}\n\nInnhold.",
            'content_blocks_json' => [],
            'generated_by_model' => 'gpt-5',
        ]);

        return $page;
    }

    private function createLintFinding(
        Customer $customer,
        EnterpriseWikiIngestRun $run,
        EnterpriseWikiPage $page,
        string $code,
        string $severity,
    ): EnterpriseWikiLintFinding {
        return EnterpriseWikiLintFinding::query()->create([
            'customer_id' => $customer->id,
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'code' => $code,
            'severity' => $severity,
            'message' => 'Planned figure(s) "fig-1" not found.',
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
            'detected_at' => now(),
        ]);
    }
}
