<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiLintFinding;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\Ai\Wiki\WikiClaimVerificationAiClient;
use App\Services\Ai\Wiki\WikiPageClaimExtractionAiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * HTTP-level coverage for resolving an orphan_concept_page finding through the Wiki UI:
 * WikiController::show() surfacing candidate_targets/can_link_related_page on the
 * structure_finding prop, and WikiController::linkOrphanConceptTarget() creating the link.
 */
class WikiOrphanConceptLinkControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(WikiPageClaimExtractionAiClient::class)
            ->shouldReceive('extractClaims')
            ->andReturn(['claims' => [
                ['text' => 'Deterministic claim.', 'confidence' => 'high', 'excerpt' => 'Deterministic claim.', 'conflict_note' => null],
            ]])
            ->byDefault();

        $this->mock(WikiClaimVerificationAiClient::class)
            ->shouldReceive('verifyClaim')
            ->andReturn([
                'verdict' => 'supported',
                'same_meaning_across_languages' => true,
                'claim_language' => 'no',
                'source_language' => 'no',
                'supporting_source_element_keys' => [],
                'reason' => 'Claim matches the cited source excerpt.',
                'unsupported_parts' => '',
                'checks' => [
                    'actor' => 'match', 'action' => 'match', 'object' => 'match', 'modality' => 'match',
                    'negation' => 'match', 'numbers_and_units' => 'match', 'time_and_date' => 'match',
                    'scope' => 'match', 'conditions_and_exceptions' => 'not_applicable', 'subject_entity' => 'match',
                ],
            ])
            ->byDefault();
    }

    public function test_authorized_user_sees_candidate_targets_and_can_link_flag(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $run = $this->createAppliedRun($customer);
        $concept = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Konsept');
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Artikkel');
        $this->createLink($customer, $article, $concept, EnterpriseWikiPageLink::LINK_TYPE_WIKILINK);

        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run->id]);
        $finding = EnterpriseWikiLintFinding::query()
            ->where('enterprise_wiki_page_id', $concept->id)
            ->where('code', EnterpriseWikiLintFinding::CODE_ORPHAN_CONCEPT_PAGE)
            ->firstOrFail();

        $response = $this->actingAs($user)->get("/app/wiki/{$concept->slug}?finding_id={$finding->id}");

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia) use ($article): bool {
            $structureFinding = data_get($inertia, 'props.structure_finding', []);
            $candidates = $structureFinding['candidate_targets'] ?? [];

            return ($structureFinding['can_link_related_page'] ?? null) === true
                && count($candidates) === 1
                && ($candidates[0]['page_id'] ?? null) === $article->id
                && in_array('incoming_wikilink', $candidates[0]['reasons'] ?? [], true);
        });
    }

    public function test_read_only_user_sees_candidates_but_cannot_link(): void
    {
        $customer = $this->createCustomer();
        $this->configureContributorCannotApproveWikiClaims($customer);
        $user = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $run = $this->createAppliedRun($customer);
        $concept = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Konsept');
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Artikkel');
        $this->createLink($customer, $article, $concept, EnterpriseWikiPageLink::LINK_TYPE_WIKILINK);

        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run->id]);
        $finding = EnterpriseWikiLintFinding::query()
            ->where('enterprise_wiki_page_id', $concept->id)
            ->where('code', EnterpriseWikiLintFinding::CODE_ORPHAN_CONCEPT_PAGE)
            ->firstOrFail();

        $response = $this->actingAs($user)->get("/app/wiki/{$concept->slug}?finding_id={$finding->id}");

        $response->assertOk();
        $response->assertViewHas('page', function (array $inertia): bool {
            $structureFinding = data_get($inertia, 'props.structure_finding', []);

            return ($structureFinding['can_link_related_page'] ?? null) === false
                && count($structureFinding['candidate_targets'] ?? []) === 1;
        });

        $link = $this->actingAs($user)->patch(
            "/app/wiki/{$concept->slug}/structure-findings/{$finding->id}/link-target",
            [
                'target_page_id' => $article->id,
                'expected_page_version_id' => $this->currentVersion($concept)->id,
            ],
        );

        $link->assertForbidden();
    }

    public function test_authorized_user_can_link_via_http_and_finding_resolves(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $run = $this->createAppliedRun($customer);
        $concept = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Konsept');
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Artikkel');
        $this->createLink($customer, $article, $concept, EnterpriseWikiPageLink::LINK_TYPE_WIKILINK);

        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run->id]);
        $finding = EnterpriseWikiLintFinding::query()
            ->where('enterprise_wiki_page_id', $concept->id)
            ->where('code', EnterpriseWikiLintFinding::CODE_ORPHAN_CONCEPT_PAGE)
            ->firstOrFail();
        $expectedVersionId = $this->currentVersion($concept)->id;

        $response = $this->actingAs($user)->patch(
            "/app/wiki/{$concept->slug}/structure-findings/{$finding->id}/link-target",
            [
                'target_page_id' => $article->id,
                'expected_page_version_id' => $expectedVersionId,
            ],
        );

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('enterprise_wiki_page_links', [
            'from_page_id' => $concept->id,
            'to_page_id' => $article->id,
            'link_type' => EnterpriseWikiPageLink::LINK_TYPE_WIKILINK,
        ]);

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'id' => $finding->id,
            'status' => EnterpriseWikiLintFinding::STATUS_RESOLVED,
        ]);
    }

    public function test_finding_from_another_customer_is_not_found(): void
    {
        $customer = $this->createCustomer();
        $otherCustomer = $this->createCustomer('Annen kunde AS');
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $otherRun = $this->createAppliedRun($otherCustomer);
        $otherConcept = $this->createVersionedPage($otherCustomer, $otherRun, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Fremmed konsept');
        $otherArticle = $this->createVersionedPage($otherCustomer, $otherRun, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Fremmed artikkel');

        Artisan::call('wiki:lint-applied-run', ['--run-id' => $otherRun->id]);
        $foreignFinding = EnterpriseWikiLintFinding::query()
            ->where('enterprise_wiki_page_id', $otherConcept->id)
            ->where('code', EnterpriseWikiLintFinding::CODE_ORPHAN_CONCEPT_PAGE)
            ->firstOrFail();

        $run = $this->createAppliedRun($customer);
        $concept = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Konsept');

        $response = $this->actingAs($user)->patch(
            "/app/wiki/{$concept->slug}/structure-findings/{$foreignFinding->id}/link-target",
            [
                'target_page_id' => $otherArticle->id,
                'expected_page_version_id' => $this->currentVersion($concept)->id,
            ],
        );

        $response->assertNotFound();
    }

    public function test_stale_version_redirects_with_error_and_creates_no_new_version(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $run = $this->createAppliedRun($customer);
        $concept = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Konsept');
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Artikkel');

        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run->id]);
        $finding = EnterpriseWikiLintFinding::query()
            ->where('enterprise_wiki_page_id', $concept->id)
            ->where('code', EnterpriseWikiLintFinding::CODE_ORPHAN_CONCEPT_PAGE)
            ->firstOrFail();
        $versionCountBefore = EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $concept->id)->count();

        $response = $this->actingAs($user)->patch(
            "/app/wiki/{$concept->slug}/structure-findings/{$finding->id}/link-target",
            [
                'target_page_id' => $article->id,
                'expected_page_version_id' => $this->currentVersion($concept)->id - 1,
            ],
        );

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame(
            $versionCountBefore,
            EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $concept->id)->count(),
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createCustomer(string $name = 'Test AS'): Customer
    {
        $language = Language::query()->firstOrCreate(
            ['code' => 'no'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk'],
        );

        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO'],
        );

        return Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    private function configureContributorCannotApproveWikiClaims(Customer $customer): void
    {
        $customer->update([
            'permission_settings' => [
                Customer::PERMISSION_APPROVE_WIKI_CLAIMS => ['system_owner'],
            ],
        ]);
    }

    private function createUser(Customer $customer, string $bidRole): User
    {
        return User::query()->create([
            'name' => 'Wiki Link Tester',
            'email' => Str::lower(Str::random(8)).'@orphan-concept-link-controller-test.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function createDocument(Customer $customer): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'test-document.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki-documents/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createAppliedRun(Customer $customer): EnterpriseWikiIngestRun
    {
        $document = $this->createDocument($customer);

        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
        ]);
    }

    private function createPage(Customer $customer, string $pageType, string $title): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'title' => $title,
            'page_type' => $pageType,
            'status' => EnterpriseWikiPage::STATUS_APPROVED,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }

    private function addPageToRun(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $page): void
    {
        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
        ]);
    }

    private function createVersionedPage(
        Customer $customer,
        EnterpriseWikiIngestRun $run,
        string $pageType,
        string $title,
    ): EnterpriseWikiPage {
        $page = $this->createPage($customer, $pageType, $title);
        $this->addPageToRun($run, $page);

        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => "# {$title}\n\nContent.",
            'generated_by_model' => 'gpt-5',
        ]);

        EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => "Claim about {$title}.",
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
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

    private function createLink(
        Customer $customer,
        EnterpriseWikiPage $from,
        EnterpriseWikiPage $to,
        string $linkType,
    ): EnterpriseWikiPageLink {
        return EnterpriseWikiPageLink::query()->create([
            'customer_id' => $customer->id,
            'from_page_id' => $from->id,
            'to_page_id' => $to->id,
            'link_type' => $linkType,
            'source' => EnterpriseWikiPageLink::SOURCE_DETERMINISTIC,
            'confidence' => EnterpriseWikiPageLink::CONFIDENCE_CERTAIN,
        ]);
    }
}
