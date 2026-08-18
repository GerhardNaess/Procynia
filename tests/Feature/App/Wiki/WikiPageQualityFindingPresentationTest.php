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
 * A quality finding is only useful once the reader can answer three questions without knowing what
 * a lint code is: what is wrong, what does it mean, and what should I do. The page showed neither
 * the second nor the third, and for the newer checks it showed "Ukjent sjekktype:
 * planned_figure_missing" as the entire message.
 *
 * These tests lock the presentation contract, not the lint engine: every code the engine can anchor
 * to a page must resolve to a human title, an explanation and ONE recommended action that the
 * product can actually perform. The findings themselves stay page-scoped — a finding for page A is
 * never rendered on page B — and claim findings stay separate from page-quality findings.
 */
class WikiPageQualityFindingPresentationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        // The copy assertions below quote the Norwegian strings verbatim, which is the point: they
        // must fail if the wording regresses into technical language again.
        $this->app->setLocale('no');
    }

    // =========================================================================
    // Human presentation per finding type
    // =========================================================================

    public function test_planned_figure_missing_has_a_human_title_explanation_and_action(): void
    {
        $copy = WikiQualityCheckPresentation::copy(EnterpriseWikiLintFinding::CODE_PLANNED_FIGURE_MISSING);

        $this->assertFalse($copy['unknown']);
        $this->assertSame('Planlagt figur mangler', $copy['label']);
        $this->assertStringNotContainsString('planned_figure_missing', $copy['label']);
        $this->assertStringContainsString('figur', mb_strtolower($copy['description']));
        $this->assertSame(WikiQualityCheckPresentation::REMEDY_SOURCE_DOCUMENT, $copy['remedy']);
        $this->assertStringContainsString('Kilder', $copy['action']);
    }

    public function test_planned_figure_wrong_section_has_its_own_human_presentation(): void
    {
        $copy = WikiQualityCheckPresentation::copy(EnterpriseWikiLintFinding::CODE_PLANNED_FIGURE_WRONG_SECTION);

        $this->assertFalse($copy['unknown']);
        $this->assertSame('Planlagt figur er feil plassert', $copy['label']);
        $this->assertNotSame(
            WikiQualityCheckPresentation::copy(EnterpriseWikiLintFinding::CODE_PLANNED_FIGURE_MISSING)['description'],
            $copy['description'],
            'the two figure findings must not share one generic explanation',
        );
        $this->assertNotSame('', trim($copy['action']));
    }

    public function test_every_lint_code_resolves_to_a_title_an_explanation_and_an_action(): void
    {
        foreach (EnterpriseWikiLintFinding::CODES as $code) {
            $copy = WikiQualityCheckPresentation::copy($code);

            $this->assertFalse($copy['unknown'], "{$code} has no human presentation");
            $this->assertNotSame('', trim($copy['label']), "{$code} has no title");
            $this->assertNotSame('', trim($copy['description']), "{$code} has no explanation");
            $this->assertNotSame('', trim($copy['action']), "{$code} has no recommended action");
            $this->assertStringNotContainsString($code, $copy['label'], "{$code} leaks its technical code into the title");
        }
    }

    public function test_a_finding_the_user_cannot_fix_says_so_instead_of_inventing_an_action(): void
    {
        $copy = WikiQualityCheckPresentation::copy(EnterpriseWikiLintFinding::CODE_WIKILINK_PROJECTION_MISMATCH);

        $this->assertSame(WikiQualityCheckPresentation::REMEDY_SYSTEM, $copy['remedy']);
        $this->assertStringContainsString('systemansvarlig', mb_strtolower($copy['action']));
    }

    public function test_an_unknown_finding_type_gets_a_safe_fallback_and_never_a_raw_code(): void
    {
        $copy = WikiQualityCheckPresentation::copy('not_a_real_check_yet');

        $this->assertTrue($copy['unknown']);
        $this->assertSame(WikiQualityCheckPresentation::REMEDY_UNKNOWN, $copy['remedy']);
        $this->assertSame('Kvalitetsproblem på Wiki-siden', $copy['label']);
        $this->assertStringNotContainsString('not_a_real_check_yet', $copy['label']);
        $this->assertStringNotContainsString('not_a_real_check_yet', $copy['description']);
        $this->assertStringNotContainsString('Ukjent sjekktype', $copy['label']);
        $this->assertNotSame('', trim($copy['action']));
    }

    public function test_both_languages_carry_the_full_presentation(): void
    {
        foreach (['no', 'en'] as $locale) {
            $this->app->setLocale($locale);

            foreach ([
                EnterpriseWikiLintFinding::CODE_PLANNED_FIGURE_MISSING,
                EnterpriseWikiLintFinding::CODE_PLANNED_FIGURE_WRONG_SECTION,
                EnterpriseWikiLintFinding::CODE_STALE_CURRENT_ASSERTION,
            ] as $code) {
                $copy = WikiQualityCheckPresentation::copy($code);

                $this->assertFalse($copy['unknown'], "{$code} is untranslated in {$locale}");
                $this->assertNotSame('', trim($copy['action']), "{$code} has no action in {$locale}");
            }

            $unknown = WikiQualityCheckPresentation::copy('still_not_a_real_check');
            $this->assertNotSame('', trim($unknown['label']), "the fallback title is missing in {$locale}");
            $this->assertNotSame('', trim($unknown['action']), "the fallback action is missing in {$locale}");
            $this->assertStringNotContainsString('still_not_a_real_check', $unknown['label']);
        }

        $this->app->setLocale('no');
    }

    /**
     * The frontend renders page findings client-side, so it carries its own copy of the remedy map.
     * Drift between the two would silently hand users a different (and possibly untrue) action
     * depending on which surface they were looking at.
     */
    public function test_the_frontend_remedy_map_matches_the_backend_one(): void
    {
        $js = (string) file_get_contents(base_path('resources/js/Pages/App/Wiki/wikiQualityChecks.js'));
        $block = Str::between($js, 'export const WIKI_QUALITY_CHECK_REMEDIES = {', '};');

        preg_match_all("/^\s*([a-z0-9_]+):\s*'([a-z_]+)',/m", $block, $matches, PREG_SET_ORDER);

        $frontend = [];

        foreach ($matches as $match) {
            $frontend[$match[1]] = $match[2];
        }

        ksort($frontend);
        $backend = WikiQualityCheckPresentation::REMEDIES;
        ksort($backend);

        $this->assertSame($backend, $frontend, 'wikiQualityChecks.js has drifted from WikiQualityCheckPresentation::REMEDIES');
    }

    // =========================================================================
    // Severity, page scoping and the claim/page-quality split
    // =========================================================================

    public function test_error_and_warning_findings_reach_the_page_with_their_severity_intact(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $run = $this->createAppliedRun($customer, $this->createDocument($customer));
        $page = $this->createVersionedPage($customer, $run, 'Figursiden');

        $this->createLintFinding($customer, $run, $page, EnterpriseWikiLintFinding::CODE_PLANNED_FIGURE_MISSING, EnterpriseWikiLintFinding::SEVERITY_ERROR);
        $this->createLintFinding($customer, $run, $page, EnterpriseWikiLintFinding::CODE_PLANNED_FIGURE_WRONG_SECTION, EnterpriseWikiLintFinding::SEVERITY_WARNING);

        $findings = collect($this->pageProps($user, $page)['lint_findings'] ?? [])->keyBy('code');

        $this->assertSame('error', $findings[EnterpriseWikiLintFinding::CODE_PLANNED_FIGURE_MISSING]['severity']);
        $this->assertSame('warning', $findings[EnterpriseWikiLintFinding::CODE_PLANNED_FIGURE_WRONG_SECTION]['severity']);
    }

    public function test_a_finding_for_one_page_is_never_shown_on_another_page(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $run = $this->createAppliedRun($customer, $this->createDocument($customer));
        $pageA = $this->createVersionedPage($customer, $run, 'Side A');
        $pageB = $this->createVersionedPage($customer, $run, 'Side B');

        $this->createLintFinding($customer, $run, $pageA, EnterpriseWikiLintFinding::CODE_PLANNED_FIGURE_MISSING, EnterpriseWikiLintFinding::SEVERITY_ERROR);

        $onA = collect($this->pageProps($user, $pageA)['lint_findings'] ?? [])->pluck('code')->all();
        $onB = collect($this->pageProps($user, $pageB)['lint_findings'] ?? [])->pluck('code')->all();

        $this->assertSame([EnterpriseWikiLintFinding::CODE_PLANNED_FIGURE_MISSING], $onA);
        $this->assertSame([], $onB, 'page B must not inherit page A findings');
    }

    public function test_the_wiki_overview_gains_no_quality_fields_from_this_change(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $run = $this->createAppliedRun($customer, $this->createDocument($customer));
        $page = $this->createVersionedPage($customer, $run, 'Oversiktssiden');

        $this->createLintFinding($customer, $run, $page, EnterpriseWikiLintFinding::CODE_PLANNED_FIGURE_MISSING, EnterpriseWikiLintFinding::SEVERITY_ERROR);

        $props = $this->inertiaProps($this->actingAs($user)->get('/app/wiki'));
        $listedPage = collect($props['pages'] ?? [])->firstWhere('id', $page->id);

        $this->assertNotNull($listedPage);

        foreach (['lint_findings', 'quality_findings', 'finding_action', 'quality_action', 'remedy'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $listedPage, "the page list must not grow a {$forbidden} field");
        }
    }

    public function test_the_structure_finding_panel_carries_the_action_and_the_technical_code_separately(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $run = $this->createAppliedRun($customer, $this->createDocument($customer));
        $page = $this->createVersionedPage($customer, $run, 'Panelsiden');

        $finding = $this->createLintFinding($customer, $run, $page, EnterpriseWikiLintFinding::CODE_PLANNED_FIGURE_MISSING, EnterpriseWikiLintFinding::SEVERITY_ERROR);

        $props = $this->inertiaProps(
            $this->actingAs($user)->get("/app/wiki/{$page->slug}?finding_id={$finding->id}"),
        );
        $panel = $props['structure_finding'] ?? null;

        $this->assertNotNull($panel);
        $this->assertSame('Planlagt figur mangler', $panel['category_label']);
        $this->assertSame(EnterpriseWikiLintFinding::CODE_PLANNED_FIGURE_MISSING, $panel['code']);
        $this->assertNotSame('', trim((string) $panel['action']));
        $this->assertSame(WikiQualityCheckPresentation::REMEDY_SOURCE_DOCUMENT, $panel['remedy']);
        $this->assertStringNotContainsString('planned_figure_missing', $panel['category_label']);
    }

    /**
     * The lint engine anchors these codes to a claim, so the page UI must route them to the claim
     * cards rather than to the page-quality list. Guarded here because the split lives in the
     * frontend helper, and the two halves must agree on the same set of codes.
     */
    public function test_claim_scoped_codes_are_classified_as_claim_findings_not_page_quality(): void
    {
        $claimCodes = array_keys(array_filter(
            WikiQualityCheckPresentation::REMEDIES,
            static fn (string $remedy): bool => $remedy === WikiQualityCheckPresentation::REMEDY_CLAIM_REVIEW,
        ));

        sort($claimCodes);

        $this->assertSame([
            EnterpriseWikiLintFinding::CODE_CLAIM_MISSING_SOURCE,
            EnterpriseWikiLintFinding::CODE_SOURCE_REFERENCE_CUSTOMER_MISMATCH,
            EnterpriseWikiLintFinding::CODE_SOURCE_REFERENCE_MISSING_EXCERPT,
            EnterpriseWikiLintFinding::CODE_SOURCE_REFERENCE_WITHOUT_DOCUMENT,
        ], $claimCodes);

        foreach ($claimCodes as $code) {
            $this->assertStringContainsString(
                'påstanden',
                mb_strtolower(WikiQualityCheckPresentation::copy($code)['action']),
                "{$code} must point the user at the claim, not at the page",
            );
        }
    }

    public function test_the_lint_rows_themselves_are_untouched_by_the_presentation_layer(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $run = $this->createAppliedRun($customer, $this->createDocument($customer));
        $page = $this->createVersionedPage($customer, $run, 'Uendret');

        $finding = $this->createLintFinding($customer, $run, $page, EnterpriseWikiLintFinding::CODE_PLANNED_FIGURE_MISSING, EnterpriseWikiLintFinding::SEVERITY_ERROR);
        $before = $finding->only(['code', 'severity', 'message', 'status', 'metadata']);

        $this->pageProps($user, $page);

        $this->assertSame($before, $finding->fresh()->only(['code', 'severity', 'message', 'status', 'metadata']));
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    private function pageProps(User $user, EnterpriseWikiPage $page): array
    {
        return $this->inertiaProps($this->actingAs($user)->get("/app/wiki/{$page->slug}"));
    }

    private function inertiaProps($response): array
    {
        $response->assertOk();

        return data_get($response->viewData('page'), 'props', []);
    }

    private function createCustomer(string $name = 'Sidekvalitet AS'): Customer
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
            'email' => Str::lower(Str::random(8)).'@sidekvalitet.invalid',
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
