<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiLintFinding;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Tilbake til funn" on an article draft returned the user to the Kjøringer list filtered to the
 * right document — but only to the document ROW. Continuing work on the same finding meant
 * reopening the Funn panel and locating it by eye, so the context the user came from was lost.
 *
 * The link now carries the finding itself: EnterpriseWikiRunFindingsService::returnUrlForFinding()
 * builds ?tab=runs&run_src=…&focus_run=…&focus_finding=…, WikiController::loadRunsTab() reads the
 * two focus parameters back out into runs_filters, and RunsTab/RunFindingsPanel reopen and focus
 * the row (the resolution/filter rules themselves are unit-tested in runFindingsLogic.test.js).
 *
 * A real URL rather than history state, deliberately: a refresh — or the link pasted to a
 * colleague — has to reproduce the same finding, which browser back() cannot promise.
 */
class WikiFindingDeepLinkTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // The outbound half: every finding's back_url names that finding
    // =========================================================================

    public function test_each_finding_carries_a_back_url_pointing_at_itself(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $document = $this->createDocument($customer);
        $run = $this->createAppliedRun($customer, $document);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $version = $this->currentVersion($article);

        $bestPractice = $this->createClaim($article, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE, 'block-0001');
        $lint = $this->createLintFinding($customer, $run, $article);

        $findings = $this->actingAs($user)
            ->getJson("/app/wiki/runs/{$run->id}/findings")
            ->assertOk()
            ->json('findings');

        $byId = collect($findings)->keyBy('id');

        $this->assertArrayHasKey('best-practice-'.$bestPractice->id, $byId);
        $this->assertArrayHasKey('lint-'.$lint->id, $byId);

        foreach (['best-practice-'.$bestPractice->id, 'lint-'.$lint->id] as $findingId) {
            $backUrl = $this->backUrlFrom($byId[$findingId]['url']);

            $this->assertNotNull($backUrl, "{$findingId} must offer a back_url");

            $query = $this->queryOf($backUrl);

            $this->assertSame('runs', $query['tab'] ?? null);
            $this->assertSame((string) $document->id, (string) ($query['run_src'] ?? null), 'document context is kept');
            $this->assertSame((string) $run->id, (string) ($query['focus_run'] ?? null));
            $this->assertSame($findingId, $query['focus_finding'] ?? null, 'the link names its OWN finding');
        }
    }

    public function test_two_findings_return_to_two_different_findings(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $document = $this->createDocument($customer);
        $run = $this->createAppliedRun($customer, $document);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $version = $this->currentVersion($article);

        $first = $this->createClaim($article, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE, 'block-0001');
        $second = $this->createClaim($article, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE, 'block-0009', 5);

        $findings = collect($this->actingAs($user)
            ->getJson("/app/wiki/runs/{$run->id}/findings")
            ->assertOk()
            ->json('findings'))->keyBy('id');

        $firstFocus = $this->queryOf($this->backUrlFrom($findings['best-practice-'.$first->id]['url']))['focus_finding'];
        $secondFocus = $this->queryOf($this->backUrlFrom($findings['best-practice-'.$second->id]['url']))['focus_finding'];

        $this->assertSame('best-practice-'.$first->id, $firstFocus);
        $this->assertSame('best-practice-'.$second->id, $secondFocus);
        $this->assertNotSame($firstFocus, $secondFocus, 'each finding must return to its own row');
    }

    public function test_the_show_page_hands_the_finding_specific_back_url_to_the_back_link(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $document = $this->createDocument($customer);
        $run = $this->createAppliedRun($customer, $document);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $version = $this->currentVersion($article);
        $claim = $this->createClaim($article, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE, 'block-0001');

        $findingId = 'best-practice-'.$claim->id;
        $backUrl = route('app.wiki.index', [
            'tab' => 'runs',
            'run_src' => $document->id,
            'focus_run' => $run->id,
            'focus_finding' => $findingId,
        ]);

        // Exactly the round trip the article draft makes: the finding's url opens the page with
        // claim_id + back_url, and Show.jsx renders "Tilbake til funn" from review_reference.back_url.
        $response = $this->actingAs($user)->get(
            "/app/wiki/{$article->slug}?claim_id={$claim->id}&back_url=".urlencode($backUrl)
        );

        $response->assertOk();

        $served = data_get($response->viewData('page'), 'props.review_reference.back_url');

        $this->assertNotNull($served, 'the back link must survive normalizeReviewBackUrl()');
        $this->assertSame($findingId, $this->queryOf($served)['focus_finding'] ?? null);
    }

    // =========================================================================
    // The inbound half: Wiki Index reads the focus parameters back out
    // =========================================================================

    public function test_the_runs_tab_exposes_the_focus_parameters_it_was_opened_with(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $document = $this->createDocument($customer);
        $run = $this->createAppliedRun($customer, $document);

        $filters = $this->runsFilters($user, [
            'tab' => 'runs',
            'run_src' => $document->id,
            'focus_run' => $run->id,
            'focus_finding' => 'best-practice-5390',
        ]);

        $this->assertSame($run->id, $filters['focus_run']);
        $this->assertSame('best-practice-5390', $filters['focus_finding']);
        // The pre-existing document filter is untouched by the addition.
        $this->assertSame($document->id, $filters['src_id']);
    }

    public function test_opening_the_wiki_without_focus_parameters_behaves_exactly_as_before(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);

        $filters = $this->runsFilters($user, ['tab' => 'runs']);

        $this->assertNull($filters['focus_run']);
        $this->assertNull($filters['focus_finding']);
        $this->assertNull($filters['src_id']);
        $this->assertNull($filters['status']);
        $this->assertNull($filters['decision']);
    }

    public function test_a_malformed_focus_finding_is_dropped_rather_than_echoed_back(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);

        foreach (['<script>alert(1)</script>', 'best practice 1', str_repeat('a', 65), ''] as $malformed) {
            $filters = $this->runsFilters($user, ['tab' => 'runs', 'focus_finding' => $malformed]);

            $this->assertNull($filters['focus_finding'], "focus_finding [{$malformed}] must be rejected");
        }

        $this->assertNull($this->runsFilters($user, ['tab' => 'runs', 'focus_run' => 'not-a-number'])['focus_run']);
    }

    public function test_a_focus_run_from_another_customer_never_widens_what_this_customer_sees(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);

        $otherCustomer = $this->createCustomer('Annen Kunde AS');
        $otherDocument = $this->createDocument($otherCustomer);
        $otherRun = $this->createAppliedRun($otherCustomer, $otherDocument);

        $response = $this->actingAs($user)->get('/app/wiki?'.http_build_query([
            'tab' => 'runs',
            'focus_run' => $otherRun->id,
            'focus_finding' => 'lint-1',
        ]));

        $response->assertOk();

        $props = data_get($response->viewData('page'), 'props');

        // The parameters are echoed as view hints only — the run list itself stays customer-scoped,
        // so the focused row is simply absent and the frontend has nothing to open.
        $this->assertSame($otherRun->id, $props['runs_filters']['focus_run']);
        $this->assertNotContains(
            $otherRun->id,
            collect($props['runs'] ?? [])->pluck('id')->all(),
            "another customer's run must never appear in this customer's runs list",
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function runsFilters(User $user, array $query): array
    {
        $response = $this->actingAs($user)->get('/app/wiki?'.http_build_query($query));

        $response->assertOk();

        return data_get($response->viewData('page'), 'props.runs_filters');
    }

    private function backUrlFrom(?string $url): ?string
    {
        return $this->queryOf($url)['back_url'] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private function queryOf(?string $url): array
    {
        parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);

        return $query;
    }

    private function createCustomer(string $name = 'Funn Deep Link AS'): Customer
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
            'email' => Str::lower(Str::random(8)).'@funn-deep-link.invalid',
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
            'original_filename' => 'source.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Source document text for deep link tests.',
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

    private function createVersionedPage(
        Customer $customer,
        EnterpriseWikiIngestRun $run,
        string $pageType,
        string $title,
    ): EnterpriseWikiPage {
        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(4)),
            'title' => $title,
            'page_type' => $pageType,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
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
            'content_markdown' => "# {$title}\n\nContent.",
            'content_blocks_json' => [[
                'block_key' => 'block-0001',
                'position' => 0,
                'markdown' => 'Innhold.',
                'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
                'best_practice_reason' => 'Identifisert svakhet.',
                'source_elements' => [],
            ]],
            'generated_by_model' => 'gpt-5',
        ]);

        return $page;
    }

    private function currentVersion(EnterpriseWikiPage $page): EnterpriseWikiPageVersion
    {
        return EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->where('is_current', true)
            ->firstOrFail();
    }

    private function createClaim(
        EnterpriseWikiPage $page,
        EnterpriseWikiPageVersion $version,
        string $contentOrigin,
        ?string $contentBlockKey = null,
        int $positionOrder = 0,
    ): EnterpriseWikiClaim {
        return EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'Test claim.',
            'page_excerpt' => 'Test claim.',
            'content_origin' => $contentOrigin,
            'content_block_key' => $contentBlockKey,
            'review_reason' => 'Identifisert svakhet.',
            'position_order' => $positionOrder,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'verified_at' => now(),
        ]);
    }

    private function createLintFinding(
        Customer $customer,
        EnterpriseWikiIngestRun $run,
        EnterpriseWikiPage $page,
    ): EnterpriseWikiLintFinding {
        return EnterpriseWikiLintFinding::query()->create([
            'customer_id' => $customer->id,
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'code' => EnterpriseWikiLintFinding::CODE_PAGE_WITHOUT_CLAIMS,
            'severity' => EnterpriseWikiLintFinding::SEVERITY_WARNING,
            'message' => 'Page has no claims.',
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
            'detected_at' => now(),
        ]);
    }
}
