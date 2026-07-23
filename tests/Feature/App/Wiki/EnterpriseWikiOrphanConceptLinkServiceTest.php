<?php

namespace Tests\Feature\App\Wiki;

use App\Exceptions\EnterpriseWikiOrphanConceptLinkException;
use App\Models\Customer;
use App\Models\EnterpriseWikiCanonicalFact;
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
use App\Services\EnterpriseWiki\EnterpriseWikiBuildPageLinksService;
use App\Services\EnterpriseWiki\EnterpriseWikiOrphanConceptLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Resolving an orphan_concept_page finding by linking a concept page to a candidate
 * article/summary page (EnterpriseWikiOrphanConceptLinkService). All AI calls are mocked at the
 * client boundary (WikiPageClaimExtractionAiClient/WikiClaimVerificationAiClient) exactly like
 * EnterpriseWikiDeepRepairServiceTest — the real extract/verify service logic still runs.
 */
class EnterpriseWikiOrphanConceptLinkServiceTest extends TestCase
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
                    'actor' => 'match',
                    'action' => 'match',
                    'object' => 'match',
                    'modality' => 'match',
                    'negation' => 'match',
                    'numbers_and_units' => 'match',
                    'time_and_date' => 'match',
                    'scope' => 'match',
                    'conditions_and_exceptions' => 'not_applicable',
                    'subject_entity' => 'match',
                ],
            ])
            ->byDefault();
    }

    // =========================================================================
    // Candidate discovery
    // =========================================================================

    public function test_candidate_includes_article_with_incoming_wikilink(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $concept = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Konsept');
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Artikkel');
        $this->createLink($customer, $article, $concept, EnterpriseWikiPageLink::LINK_TYPE_WIKILINK);

        $candidates = app(EnterpriseWikiOrphanConceptLinkService::class)->findCandidates($concept);

        $this->assertCount(1, $candidates);
        $this->assertSame($article->id, $candidates[0]['page_id']);
        $this->assertContains(EnterpriseWikiOrphanConceptLinkService::REASON_INCOMING_WIKILINK, $candidates[0]['reasons']);
    }

    public function test_candidate_includes_summary_with_incoming_wikilink(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $concept = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Konsept');
        $summary = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Sammendrag');
        $this->createLink($customer, $summary, $concept, EnterpriseWikiPageLink::LINK_TYPE_WIKILINK);

        $candidates = app(EnterpriseWikiOrphanConceptLinkService::class)->findCandidates($concept);

        $this->assertCount(1, $candidates);
        $this->assertSame($summary->id, $candidates[0]['page_id']);
        $this->assertContains(EnterpriseWikiOrphanConceptLinkService::REASON_INCOMING_WIKILINK, $candidates[0]['reasons']);
    }

    public function test_candidate_includes_page_with_structural_pairing(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $concept = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Konsept');
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Artikkel');
        $this->createLink($customer, $article, $concept, EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_CONCEPT);

        $candidates = app(EnterpriseWikiOrphanConceptLinkService::class)->findCandidates($concept);

        $this->assertCount(1, $candidates);
        $this->assertSame($article->id, $candidates[0]['page_id']);
        $this->assertContains(EnterpriseWikiOrphanConceptLinkService::REASON_STRUCTURAL_PAIRING, $candidates[0]['reasons']);
    }

    public function test_candidate_includes_page_that_mentions_concept_title(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $concept = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Rollemodell');
        $article = $this->createVersionedPage(
            $customer,
            $run,
            EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'Artikkel',
            "# Artikkel\n\nDenne teksten nevner Rollemodell i en setning.",
        );

        $candidates = app(EnterpriseWikiOrphanConceptLinkService::class)->findCandidates($concept);

        $this->assertCount(1, $candidates);
        $this->assertSame($article->id, $candidates[0]['page_id']);
        $this->assertContains(EnterpriseWikiOrphanConceptLinkService::REASON_MENTIONS_TITLE, $candidates[0]['reasons']);
    }

    public function test_candidate_includes_page_sharing_canonical_fact(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $concept = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Konsept');
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Artikkel');

        $fact = EnterpriseWikiCanonicalFact::query()->create([
            'customer_id' => $customer->id,
            'content_origin' => 'source_based',
            'source_element_keys' => ['document-1-full-text'],
            'source_element_keys_hash' => hash('sha256', 'document-1-full-text'),
            'normalized_fingerprint' => hash('sha256', 'delt faktagrunnlag'),
            'canonical_text' => 'Delt faktagrunnlag.',
            'verification_status' => 'verified',
        ]);

        $this->createClaim($this->currentVersion($concept), 'Delt faktagrunnlag.', ['canonical_fact_id' => $fact->id]);
        $this->createClaim($this->currentVersion($article), 'Delt faktagrunnlag.', ['canonical_fact_id' => $fact->id]);

        $candidates = app(EnterpriseWikiOrphanConceptLinkService::class)->findCandidates($concept);

        $this->assertCount(1, $candidates);
        $this->assertSame($article->id, $candidates[0]['page_id']);
        $this->assertContains(EnterpriseWikiOrphanConceptLinkService::REASON_SHARED_CANONICAL_FACT, $candidates[0]['reasons']);
    }

    public function test_concept_pages_are_never_candidates(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $concept = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Konsept');
        $otherConcept = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Annet konsept');
        $this->createLink($customer, $otherConcept, $concept, EnterpriseWikiPageLink::LINK_TYPE_WIKILINK);

        $candidates = app(EnterpriseWikiOrphanConceptLinkService::class)->findCandidates($concept);

        $this->assertSame([], $candidates);
    }

    public function test_unrelated_article_is_not_a_candidate(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $concept = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Konsept');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Helt urelatert artikkel', '# Helt urelatert artikkel\n\nIngen forbindelse.');

        $candidates = app(EnterpriseWikiOrphanConceptLinkService::class)->findCandidates($concept);

        $this->assertSame([], $candidates);
    }

    // =========================================================================
    // Creating the link
    // =========================================================================

    public function test_authorized_user_can_link_concept_to_article_via_auto_embed(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $run = $this->createAppliedRun($customer);
        $concept = $this->createVersionedPage(
            $customer,
            $run,
            EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
            'Rollemodell',
            "# Rollemodell\n\nDenne siden omtaler Masterdata Samhandling som en viktig artikkel.",
        );
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Masterdata Samhandling');
        $version = $this->currentVersion($concept);

        $result = app(EnterpriseWikiOrphanConceptLinkService::class)->linkConceptToTarget(
            $concept,
            $article->id,
            $version->id,
            $user,
        );

        $this->assertFalse($result['already_linked']);
        $this->assertSame('auto_embedded', $result['placement']);
        $this->assertTrue($result['resolved_finding']);

        $newVersion = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $concept->id)
            ->where('is_current', true)
            ->first();

        $this->assertNotSame($version->id, $newVersion->id);
        $this->assertStringContainsString('[['.$article->slug.'|Masterdata Samhandling]]', $newVersion->content_markdown);
    }

    public function test_authorized_user_can_link_concept_to_summary_via_appended_block(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $run = $this->createAppliedRun($customer);
        $concept = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Konsept');
        $summary = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Sammendrag');
        $version = $this->currentVersion($concept);

        $result = app(EnterpriseWikiOrphanConceptLinkService::class)->linkConceptToTarget(
            $concept,
            $summary->id,
            $version->id,
            $user,
        );

        $this->assertFalse($result['already_linked']);
        $this->assertSame('appended_block', $result['placement']);

        $newVersion = EnterpriseWikiPageVersion::query()->find($result['new_page_version_id']);
        $this->assertStringContainsString('[['.$summary->slug.'|Sammendrag]]', $newVersion->content_markdown);
    }

    public function test_link_result_creates_outgoing_wikilink_row(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $run = $this->createAppliedRun($customer);
        $concept = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Konsept');
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Artikkel');
        $version = $this->currentVersion($concept);

        app(EnterpriseWikiOrphanConceptLinkService::class)->linkConceptToTarget($concept, $article->id, $version->id, $user);

        $this->assertDatabaseHas('enterprise_wiki_page_links', [
            'customer_id' => $customer->id,
            'from_page_id' => $concept->id,
            'to_page_id' => $article->id,
            'link_type' => EnterpriseWikiPageLink::LINK_TYPE_WIKILINK,
        ]);
    }

    public function test_invalid_target_type_is_rejected(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $run = $this->createAppliedRun($customer);
        $concept = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Konsept');
        $otherConcept = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Annet konsept');
        $version = $this->currentVersion($concept);

        $this->expectException(EnterpriseWikiOrphanConceptLinkException::class);

        try {
            app(EnterpriseWikiOrphanConceptLinkService::class)->linkConceptToTarget($concept, $otherConcept->id, $version->id, $user);
        } catch (EnterpriseWikiOrphanConceptLinkException $e) {
            $this->assertSame(EnterpriseWikiOrphanConceptLinkException::REASON_INVALID_TARGET_TYPE, $e->reason);

            throw $e;
        }
    }

    public function test_existing_links_are_preserved_after_linking(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $run = $this->createAppliedRun($customer);
        $concept = $this->createVersionedPage(
            $customer,
            $run,
            EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
            'Konsept',
            "# Konsept\n\nDenne siden lenker til [[eksisterende-side|Eksisterende side]].",
        );
        $otherConcept = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Eksisterende side', 'eksisterende-side');
        $this->addPageToRun($run, $otherConcept);
        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $otherConcept->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => "# Eksisterende side\n\nInnhold.",
        ]);
        $this->createClaim($this->currentVersion($otherConcept), 'Claim.');
        app(EnterpriseWikiBuildPageLinksService::class)->materializeWikilinksForPage($concept->fresh());

        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Artikkel');
        $version = $this->currentVersion($concept);

        app(EnterpriseWikiOrphanConceptLinkService::class)->linkConceptToTarget($concept, $article->id, $version->id, $user);

        $newVersion = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $concept->id)
            ->where('is_current', true)
            ->first();

        $this->assertStringContainsString('[[eksisterende-side|Eksisterende side]]', $newVersion->content_markdown);
        $this->assertDatabaseHas('enterprise_wiki_page_links', [
            'from_page_id' => $concept->id,
            'to_page_id' => $otherConcept->id,
            'link_type' => EnterpriseWikiPageLink::LINK_TYPE_WIKILINK,
        ]);
    }

    public function test_duplicate_link_is_not_created_when_already_linked(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $run = $this->createAppliedRun($customer);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Artikkel');
        $concept = $this->createVersionedPage(
            $customer,
            $run,
            EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
            'Konsept',
            "# Konsept\n\nAllerede lenket til [[{$article->slug}|Artikkel]].",
        );
        $version = $this->currentVersion($concept);
        $versionCountBefore = EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $concept->id)->count();

        $result = app(EnterpriseWikiOrphanConceptLinkService::class)->linkConceptToTarget($concept, $article->id, $version->id, $user);

        $this->assertTrue($result['already_linked']);
        $this->assertSame(
            $versionCountBefore,
            EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $concept->id)->count(),
        );
    }

    public function test_stale_version_is_rejected_and_leaves_no_partial_state(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $run = $this->createAppliedRun($customer);
        $concept = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Konsept');
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Artikkel');
        $staleVersionId = $this->currentVersion($concept)->id - 1;
        $versionCountBefore = EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $concept->id)->count();
        $linkCountBefore = EnterpriseWikiPageLink::query()->count();

        $this->expectException(EnterpriseWikiOrphanConceptLinkException::class);

        try {
            app(EnterpriseWikiOrphanConceptLinkService::class)->linkConceptToTarget($concept, $article->id, $staleVersionId, $user);
        } catch (EnterpriseWikiOrphanConceptLinkException $e) {
            $this->assertSame(EnterpriseWikiOrphanConceptLinkException::REASON_STALE_VERSION, $e->reason);
            $this->assertSame(
                $versionCountBefore,
                EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $concept->id)->count(),
            );
            $this->assertSame($linkCountBefore, EnterpriseWikiPageLink::query()->count());

            throw $e;
        }
    }

    public function test_unauthorized_user_is_rejected(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $run = $this->createAppliedRun($customer);
        $concept = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Konsept');
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Artikkel');
        $version = $this->currentVersion($concept);

        $this->expectException(EnterpriseWikiOrphanConceptLinkException::class);

        try {
            app(EnterpriseWikiOrphanConceptLinkService::class)->linkConceptToTarget($concept, $article->id, $version->id, $user);
        } catch (EnterpriseWikiOrphanConceptLinkException $e) {
            $this->assertSame(EnterpriseWikiOrphanConceptLinkException::REASON_UNAUTHORIZED, $e->reason);

            throw $e;
        }
    }

    public function test_cross_customer_target_is_rejected(): void
    {
        $customer = $this->createCustomer();
        $otherCustomer = $this->createCustomer('Annen kunde AS');
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $run = $this->createAppliedRun($customer);
        $otherRun = $this->createAppliedRun($otherCustomer);
        $concept = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Konsept');
        $foreignArticle = $this->createVersionedPage($otherCustomer, $otherRun, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Fremmed artikkel');
        $version = $this->currentVersion($concept);

        $this->expectException(EnterpriseWikiOrphanConceptLinkException::class);

        try {
            app(EnterpriseWikiOrphanConceptLinkService::class)->linkConceptToTarget($concept, $foreignArticle->id, $version->id, $user);
        } catch (EnterpriseWikiOrphanConceptLinkException $e) {
            $this->assertSame(EnterpriseWikiOrphanConceptLinkException::REASON_TARGET_NOT_FOUND, $e->reason);

            throw $e;
        }
    }

    // =========================================================================
    // Lint resolution
    // =========================================================================

    public function test_valid_link_resolves_orphan_concept_page_finding(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $run = $this->createAppliedRun($customer);
        $concept = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Konsept');
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Artikkel');
        $version = $this->currentVersion($concept);

        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run->id]);
        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $concept->id,
            'code' => EnterpriseWikiLintFinding::CODE_ORPHAN_CONCEPT_PAGE,
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
        ]);

        app(EnterpriseWikiOrphanConceptLinkService::class)->linkConceptToTarget($concept, $article->id, $version->id, $user);

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $concept->id,
            'code' => EnterpriseWikiLintFinding::CODE_ORPHAN_CONCEPT_PAGE,
            'status' => EnterpriseWikiLintFinding::STATUS_RESOLVED,
        ]);
    }

    public function test_lint_only_reruns_the_affected_page_run(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $run = $this->createAppliedRun($customer);
        $otherRun = $this->createAppliedRun($customer);
        $concept = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Konsept');
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Artikkel');
        $version = $this->currentVersion($concept);

        // Unrelated run gets its own finding open before we touch the concept page's run.
        Artisan::call('wiki:lint-applied-run', ['--run-id' => $otherRun->id]);
        $otherFindingId = EnterpriseWikiLintFinding::query()
            ->where('enterprise_wiki_ingest_run_id', $otherRun->id)
            ->where('code', EnterpriseWikiLintFinding::CODE_APPLIED_RUN_WITHOUT_PAGES)
            ->value('id');
        $this->assertNotNull($otherFindingId);

        app(EnterpriseWikiOrphanConceptLinkService::class)->linkConceptToTarget($concept, $article->id, $version->id, $user);

        // The unrelated run's finding is untouched by linking a page in a different run.
        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'id' => $otherFindingId,
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
        ]);
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

    private function createUser(Customer $customer, string $bidRole): User
    {
        return User::query()->create([
            'name' => 'Wiki Link Tester',
            'email' => Str::lower(Str::random(8)).'@orphan-concept-link-test.invalid',
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

    private function createPage(Customer $customer, string $pageType, string $title, ?string $slug = null): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => $slug ?? Str::slug($title).'-'.Str::lower(Str::random(6)),
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
        ?string $markdown = null,
    ): EnterpriseWikiPage {
        $page = $this->createPage($customer, $pageType, $title);
        $this->addPageToRun($run, $page);

        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => $markdown ?? "# {$title}\n\nContent.",
            'generated_by_model' => 'gpt-5',
        ]);

        $this->createClaim($version, "Claim about {$title}.");

        return $page;
    }

    private function currentVersion(EnterpriseWikiPage $page): EnterpriseWikiPageVersion
    {
        return EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->where('is_current', true)
            ->firstOrFail();
    }

    private function createClaim(EnterpriseWikiPageVersion $version, string $text, array $overrides = []): EnterpriseWikiClaim
    {
        return EnterpriseWikiClaim::query()->create(array_merge([
            'enterprise_wiki_page_id' => $version->enterprise_wiki_page_id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => $text,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ], $overrides));
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
