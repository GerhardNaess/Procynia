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
use App\Models\EnterpriseWikiSourceReference;
use App\Models\Language;
use App\Models\Nationality;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnterpriseWikiLintAppliedRunCommandTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Argument validation
    // =========================================================================

    public function test_command_fails_when_run_id_is_missing(): void
    {
        $this->artisan('wiki:lint-applied-run')
            ->expectsOutputToContain('--run-id is required')
            ->assertExitCode(1);
    }

    public function test_command_fails_when_run_not_found(): void
    {
        $this->artisan('wiki:lint-applied-run', ['--run-id' => 99999])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    }

    public function test_command_fails_when_run_not_applied(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createRunPending($customer);

        $this->artisan('wiki:lint-applied-run', ['--run-id' => $run->id])
            ->expectsOutputToContain("only 'applied'")
            ->assertExitCode(1);
    }

    // =========================================================================
    // Happy path: exits zero
    // =========================================================================

    public function test_command_exits_zero_on_success(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);

        $this->artisan('wiki:lint-applied-run', ['--run-id' => $run->id])
            ->assertExitCode(0);
    }

    // =========================================================================
    // Run-level completeness findings
    // =========================================================================

    public function test_creates_finding_when_run_has_no_pages(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);

        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run->id]);

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'customer_id' => $customer->id,
            'enterprise_wiki_ingest_run_id' => $run->id,
            'code' => EnterpriseWikiLintFinding::CODE_APPLIED_RUN_WITHOUT_PAGES,
            'severity' => EnterpriseWikiLintFinding::SEVERITY_ERROR,
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
        ]);
    }

    public function test_creates_finding_when_run_has_no_article(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Only Summary');

        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run->id]);

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'customer_id' => $customer->id,
            'enterprise_wiki_ingest_run_id' => $run->id,
            'code' => EnterpriseWikiLintFinding::CODE_APPLIED_RUN_WITHOUT_ARTICLE,
            'severity' => EnterpriseWikiLintFinding::SEVERITY_WARNING,
        ]);
    }

    public function test_creates_finding_when_run_has_no_summary(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Only Article');

        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run->id]);

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'customer_id' => $customer->id,
            'enterprise_wiki_ingest_run_id' => $run->id,
            'code' => EnterpriseWikiLintFinding::CODE_APPLIED_RUN_WITHOUT_SUMMARY,
            'severity' => EnterpriseWikiLintFinding::SEVERITY_WARNING,
        ]);
    }

    // =========================================================================
    // Page / version findings
    // =========================================================================

    public function test_creates_finding_when_page_has_no_current_version(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $page = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'No Version');
        $this->addPageToRun($run, $page);

        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run->id]);

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'customer_id' => $customer->id,
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'code' => EnterpriseWikiLintFinding::CODE_MISSING_CURRENT_VERSION,
            'severity' => EnterpriseWikiLintFinding::SEVERITY_ERROR,
        ]);
    }

    public function test_creates_finding_when_page_has_empty_content(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $page = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Empty Content');
        $this->addPageToRun($run, $page);

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => '   ',
            'generated_by_model' => 'gpt-5',
        ]);

        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run->id]);

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $page->id,
            'code' => EnterpriseWikiLintFinding::CODE_EMPTY_PAGE_CONTENT,
            'severity' => EnterpriseWikiLintFinding::SEVERITY_ERROR,
        ]);
    }

    // =========================================================================
    // Claims findings
    // =========================================================================

    // Wiki run-5: a page whose only best_practice block has no matching claim — the scenario
    // "Side mangler påstander" was originally meant to catch. Uses an explicit best_practice
    // block (rather than an empty content_blocks_json) so the finding still has a genuine
    // Procynia assertion to be missing a claim for — see test_no_finding_when_page_has_only_
    // source_based_and_structural_blocks_and_zero_claims() for the case that must NOT fire this.
    public function test_creates_finding_when_page_has_no_claims(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $page = $this->createVersionedPageWithBlocks($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'No Claims', [
            $this->bestPracticeBlock('block-0001', 'Procynia anbefaler regelmessig gjennomgang av rolletildelinger.', 'Identifisert svakhet: uklart ansvar.'),
        ]);

        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run->id]);

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $page->id,
            'code' => EnterpriseWikiLintFinding::CODE_PAGE_WITHOUT_CLAIMS,
            'severity' => EnterpriseWikiLintFinding::SEVERITY_WARNING,
        ]);
    }

    // Wiki run-5 (test O): a page built purely from source_based + structural content correctly
    // has zero claims — that must never be reported as "Side mangler påstander", since neither
    // origin is ever expected to produce a reviewable claim.
    public function test_no_finding_when_page_has_only_source_based_and_structural_blocks_and_zero_claims(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $page = $this->createVersionedPageWithBlocks($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Source And Structural Only', [
            $this->structuralBlock('block-0001', '# Source And Structural Only'),
            $this->sourceBasedBlockFixture('block-0002', 'Direkte kildeinnhold.'),
        ]);

        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run->id]);

        $this->assertDatabaseMissing('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $page->id,
            'code' => EnterpriseWikiLintFinding::CODE_PAGE_WITHOUT_CLAIMS,
        ]);
        $this->assertDatabaseMissing('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $page->id,
            'code' => EnterpriseWikiLintFinding::CODE_BEST_PRACTICE_BLOCK_WITHOUT_CLAIM,
        ]);
    }

    // Wiki run-5 (test P): a best_practice content block with no reviewable claim at all is a
    // real integrity error, distinct from (and stronger than) the page-level warning above.
    public function test_creates_integrity_error_when_best_practice_block_has_no_claim(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $page = $this->createVersionedPageWithBlocks($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Missing Claim', [
            $this->bestPracticeBlock('block-0001', 'Procynia anbefaler dokumentert risikoklassifisering av endringer.', 'Identifisert svakhet: manglende risikoklassifisering.'),
        ]);

        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run->id]);

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $page->id,
            'code' => EnterpriseWikiLintFinding::CODE_BEST_PRACTICE_BLOCK_WITHOUT_CLAIM,
            'severity' => EnterpriseWikiLintFinding::SEVERITY_ERROR,
        ]);
    }

    // Wiki run-5 (test Q): once the best_practice block has a matching claim, the integrity
    // error must not fire.
    public function test_no_integrity_error_when_best_practice_block_has_a_claim(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $page = $this->createVersionedPageWithBlocks($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Has Claim', [
            $this->bestPracticeBlock('block-0001', 'Procynia anbefaler dokumentert risikoklassifisering av endringer.', 'Identifisert svakhet: manglende risikoklassifisering.'),
        ]);
        $version = $this->currentVersion($page);

        EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'Procynia anbefaler dokumentert risikoklassifisering av endringer.',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'content_block_key' => 'block-0001',
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ]);

        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run->id]);

        $this->assertDatabaseMissing('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $page->id,
            'code' => EnterpriseWikiLintFinding::CODE_BEST_PRACTICE_BLOCK_WITHOUT_CLAIM,
        ]);
    }

    /**
     * Wiki run-7 (test C): a claim tied to an OLDER page version must never satisfy the
     * best_practice_block_without_claim check for a NEWER current version — checkPageClaims()
     * scopes its claims query to the CURRENT version's own id, so a claim left behind on a
     * superseded version is correctly invisible to it. This is the scenario item 5 asked to rule
     * out for run 7 (a repair/re-versioning leaving a claim "stuck" on the wrong version) — proven
     * here as already-correct existing behavior, not a gap this task introduces a fix for.
     */
    public function test_claim_on_old_page_version_does_not_satisfy_the_check_for_a_new_current_version(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $page = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Re-versioned Page');
        $this->addPageToRun($run, $page);

        $oldVersion = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => false,
            'content_markdown' => "# Re-versioned Page\n\nContent.",
            'content_blocks_json' => [
                $this->bestPracticeBlock('block-0001', 'Procynia anbefaler dokumentert risikoklassifisering av endringer.', 'Identifisert svakhet: manglende risikoklassifisering.'),
            ],
            'generated_by_model' => 'gpt-5',
        ]);

        EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $oldVersion->id,
            'claim_text' => 'Procynia anbefaler dokumentert risikoklassifisering av endringer.',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'content_block_key' => 'block-0001',
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ]);

        // A repair/re-generation supersedes the old version with a new current one — same
        // block_key reused, but this new version has no claim of its own.
        $newVersion = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 2,
            'is_current' => true,
            'content_markdown' => "# Re-versioned Page\n\nRepaired content.",
            'content_blocks_json' => [
                $this->bestPracticeBlock('block-0001', 'Procynia anbefaler dokumentert risikoklassifisering av endringer.', 'Identifisert svakhet: manglende risikoklassifisering.'),
            ],
            'generated_by_model' => 'gpt-5',
        ]);
        $oldVersion->update(['is_current' => false]);

        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run->id]);

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $newVersion->id,
            'code' => EnterpriseWikiLintFinding::CODE_BEST_PRACTICE_BLOCK_WITHOUT_CLAIM,
            'severity' => EnterpriseWikiLintFinding::SEVERITY_ERROR,
        ]);
    }

    public function test_creates_finding_when_claim_has_no_source_reference(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $page = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $version = $this->currentVersion($page);
        $claim = $this->createClaim($version, 'Claim with no source ref.');

        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run->id]);

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'enterprise_wiki_claim_id' => $claim->id,
            'code' => EnterpriseWikiLintFinding::CODE_CLAIM_MISSING_SOURCE,
        ]);
    }

    public function test_creates_finding_when_source_reference_has_no_excerpt(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $page = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $version = $this->currentVersion($page);
        $claim = $this->createClaim($version, 'A claim text.');
        $document = $this->createDocument($customer);
        $this->createSourceRef($claim, $document, '');

        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run->id]);

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'enterprise_wiki_claim_id' => $claim->id,
            'code' => EnterpriseWikiLintFinding::CODE_SOURCE_REFERENCE_MISSING_EXCERPT,
        ]);
    }

    // =========================================================================
    // Source reference integrity findings
    // =========================================================================

    public function test_creates_finding_when_source_reference_points_to_missing_document(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $page = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $version = $this->currentVersion($page);
        $claim = $this->createClaim($version, 'Claim.');

        // Create a source reference with a source_id that points to a non-existent document
        EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $claim->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => 99999,
            'source_label' => 'ghost.pdf',
            'excerpt' => 'Some text.',
        ]);

        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run->id]);

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'enterprise_wiki_claim_id' => $claim->id,
            'code' => EnterpriseWikiLintFinding::CODE_SOURCE_REFERENCE_WITHOUT_DOCUMENT,
            'severity' => EnterpriseWikiLintFinding::SEVERITY_ERROR,
        ]);
    }

    public function test_creates_finding_when_source_reference_document_belongs_to_different_customer(): void
    {
        $customer1 = $this->createCustomer('Customer One');
        $customer2 = $this->createCustomer('Customer Two');
        $run = $this->createAppliedRun($customer1);
        $page = $this->createVersionedPage($customer1, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $version = $this->currentVersion($page);
        $claim = $this->createClaim($version, 'Claim.');
        $docOther = $this->createDocument($customer2);

        $this->createSourceRef($claim, $docOther, 'Some excerpt.');

        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run->id]);

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'enterprise_wiki_claim_id' => $claim->id,
            'code' => EnterpriseWikiLintFinding::CODE_SOURCE_REFERENCE_CUSTOMER_MISMATCH,
            'severity' => EnterpriseWikiLintFinding::SEVERITY_ERROR,
        ]);
    }

    // =========================================================================
    // Link findings
    // =========================================================================

    public function test_creates_finding_when_page_has_no_outgoing_links(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $page = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Isolated Article');
        $this->createClaim($this->currentVersion($page), 'A claim.');

        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run->id]);

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $page->id,
            'code' => EnterpriseWikiLintFinding::CODE_PAGE_WITHOUT_OUTGOING_LINKS,
        ]);
    }

    public function test_creates_finding_when_page_has_no_incoming_links(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $page = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'No Backlinks');
        $this->createClaim($this->currentVersion($page), 'A claim.');

        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run->id]);

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $page->id,
            'code' => EnterpriseWikiLintFinding::CODE_PAGE_WITHOUT_INCOMING_LINKS,
        ]);
    }

    public function test_creates_finding_when_article_has_no_summary_link(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $concept = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Concept');
        $this->createClaim($this->currentVersion($article), 'Claim.');
        $this->createLink($customer, $article, $concept, EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_CONCEPT);

        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run->id]);

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $article->id,
            'code' => EnterpriseWikiLintFinding::CODE_ARTICLE_WITHOUT_SUMMARY_LINK,
        ]);
    }

    public function test_creates_finding_when_summary_has_no_article_link(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $summary = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Orphan Summary');
        $concept = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Concept');
        $this->createClaim($this->currentVersion($summary), 'Claim.');
        $this->createLink($customer, $summary, $concept, EnterpriseWikiPageLink::LINK_TYPE_SUMMARY_TO_CONCEPT);

        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run->id]);

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $summary->id,
            'code' => EnterpriseWikiLintFinding::CODE_SUMMARY_WITHOUT_ARTICLE_LINK,
        ]);
    }

    public function test_creates_finding_when_article_has_no_concept_or_entity_links(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $summary = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $this->createClaim($this->currentVersion($article), 'Claim.');
        $this->createLink($customer, $article, $summary, EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_SUMMARY);
        $this->createLink($customer, $summary, $article, EnterpriseWikiPageLink::LINK_TYPE_SUMMARY_TO_ARTICLE);

        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run->id]);

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $article->id,
            'code' => EnterpriseWikiLintFinding::CODE_ARTICLE_WITHOUT_CONCEPT_OR_ENTITY_LINKS,
            'severity' => EnterpriseWikiLintFinding::SEVERITY_INFO,
        ]);
    }

    public function test_creates_finding_when_concept_has_no_article_or_summary_link(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $concept = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Orphan Concept');
        $this->createClaim($this->currentVersion($concept), 'Claim.');

        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run->id]);

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $concept->id,
            'code' => EnterpriseWikiLintFinding::CODE_ORPHAN_CONCEPT_PAGE,
        ]);
    }

    public function test_no_finding_when_concept_has_outgoing_wikilink_to_article(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $concept = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Concept');
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createClaim($this->currentVersion($concept), 'Claim.');
        $this->createLink($customer, $concept, $article, EnterpriseWikiPageLink::LINK_TYPE_WIKILINK);

        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run->id]);

        $this->assertDatabaseMissing('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $concept->id,
            'code' => EnterpriseWikiLintFinding::CODE_ORPHAN_CONCEPT_PAGE,
        ]);
    }

    public function test_no_finding_when_concept_has_outgoing_wikilink_to_summary(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $concept = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Concept');
        $summary = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $this->createClaim($this->currentVersion($concept), 'Claim.');
        $this->createLink($customer, $concept, $summary, EnterpriseWikiPageLink::LINK_TYPE_WIKILINK);

        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run->id]);

        $this->assertDatabaseMissing('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $concept->id,
            'code' => EnterpriseWikiLintFinding::CODE_ORPHAN_CONCEPT_PAGE,
        ]);
    }

    public function test_creates_finding_when_concept_only_has_wikilinks_to_other_concepts(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $concept = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Concept');
        $otherConcept = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Other Concept');
        $this->createClaim($this->currentVersion($concept), 'Claim.');
        $this->createLink($customer, $concept, $otherConcept, EnterpriseWikiPageLink::LINK_TYPE_WIKILINK);

        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run->id]);

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $concept->id,
            'code' => EnterpriseWikiLintFinding::CODE_ORPHAN_CONCEPT_PAGE,
        ]);
    }

    public function test_creates_finding_when_concept_only_has_incoming_links(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $concept = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Concept');
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createClaim($this->currentVersion($concept), 'Claim.');
        // Only incoming (article -> concept), no outgoing link from the concept page itself.
        $this->createLink($customer, $article, $concept, EnterpriseWikiPageLink::LINK_TYPE_WIKILINK);

        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run->id]);

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $concept->id,
            'code' => EnterpriseWikiLintFinding::CODE_ORPHAN_CONCEPT_PAGE,
        ]);
    }

    public function test_no_finding_when_concept_has_legacy_concept_to_article_link(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $concept = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Concept');
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createClaim($this->currentVersion($concept), 'Claim.');
        $this->createLink($customer, $concept, $article, EnterpriseWikiPageLink::LINK_TYPE_CONCEPT_TO_ARTICLE);

        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run->id]);

        $this->assertDatabaseMissing('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $concept->id,
            'code' => EnterpriseWikiLintFinding::CODE_ORPHAN_CONCEPT_PAGE,
        ]);
    }

    public function test_no_finding_when_concept_has_legacy_concept_to_summary_link(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $concept = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Concept');
        $summary = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $this->createClaim($this->currentVersion($concept), 'Claim.');
        $this->createLink($customer, $concept, $summary, EnterpriseWikiPageLink::LINK_TYPE_CONCEPT_TO_SUMMARY);

        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run->id]);

        $this->assertDatabaseMissing('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $concept->id,
            'code' => EnterpriseWikiLintFinding::CODE_ORPHAN_CONCEPT_PAGE,
        ]);
    }

    public function test_creates_finding_when_wikilink_target_is_neither_article_nor_summary(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $concept = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Concept');
        $entity = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ENTITY, 'Entity');
        $this->createClaim($this->currentVersion($concept), 'Claim.');

        // A wikilink to a valid page whose page_type is neither article nor summary must not
        // count as a valid backlink.
        $this->createLink($customer, $concept, $entity, EnterpriseWikiPageLink::LINK_TYPE_WIKILINK);

        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run->id]);

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $concept->id,
            'code' => EnterpriseWikiLintFinding::CODE_ORPHAN_CONCEPT_PAGE,
        ]);
    }

    public function test_regression_page_230_concept_with_wikilink_to_article_is_not_orphan(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $concept = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Roller i styringsmodellen');
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Masterdata Samhandling');
        $otherConcept1 = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Rolle A');
        $otherConcept2 = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Rolle B');
        $this->createClaim($this->currentVersion($concept), 'Claim.');

        // Mirrors the reported page 230 shape: several outgoing wikilinks to other concept
        // pages plus one outgoing wikilink to an article.
        $this->createLink($customer, $concept, $otherConcept1, EnterpriseWikiPageLink::LINK_TYPE_WIKILINK);
        $this->createLink($customer, $concept, $otherConcept2, EnterpriseWikiPageLink::LINK_TYPE_WIKILINK);
        $this->createLink($customer, $concept, $article, EnterpriseWikiPageLink::LINK_TYPE_WIKILINK);

        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run->id]);

        $this->assertDatabaseMissing('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $concept->id,
            'code' => EnterpriseWikiLintFinding::CODE_ORPHAN_CONCEPT_PAGE,
        ]);
    }

    public function test_reopened_run_closes_stale_orphan_concept_finding_once_wikilink_is_added(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $concept = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Concept');
        $this->createClaim($this->currentVersion($concept), 'Claim.');

        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run->id]);

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $concept->id,
            'code' => EnterpriseWikiLintFinding::CODE_ORPHAN_CONCEPT_PAGE,
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
        ]);

        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createLink($customer, $concept, $article, EnterpriseWikiPageLink::LINK_TYPE_WIKILINK);

        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run->id]);

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $concept->id,
            'code' => EnterpriseWikiLintFinding::CODE_ORPHAN_CONCEPT_PAGE,
            'status' => EnterpriseWikiLintFinding::STATUS_RESOLVED,
        ]);
    }

    public function test_creates_finding_when_entity_has_no_article_or_summary_link(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $entity = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ENTITY, 'Orphan Entity');
        $this->createClaim($this->currentVersion($entity), 'Claim.');

        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run->id]);

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $entity->id,
            'code' => EnterpriseWikiLintFinding::CODE_ORPHAN_ENTITY_PAGE,
        ]);
    }

    public function test_creates_finding_when_reverse_link_is_missing(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $summary = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $this->createClaim($this->currentVersion($article), 'Claim.');

        // Only forward link, no reverse
        $this->createLink($customer, $article, $summary, EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_SUMMARY);

        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run->id]);

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $article->id,
            'code' => EnterpriseWikiLintFinding::CODE_MISSING_REVERSE_LINK,
        ]);
    }

    // =========================================================================
    // Idempotency
    // =========================================================================

    public function test_rerun_skips_already_open_findings(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);

        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run->id]);
        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run->id]);

        // Findings should not be duplicated
        $runFindings = EnterpriseWikiLintFinding::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->where('code', EnterpriseWikiLintFinding::CODE_APPLIED_RUN_WITHOUT_PAGES)
            ->count();

        $this->assertSame(1, $runFindings);
    }

    public function test_resolved_finding_is_reopened_on_rerun_when_condition_persists(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);

        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run->id]);

        // Manually resolve the finding
        EnterpriseWikiLintFinding::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->where('code', EnterpriseWikiLintFinding::CODE_APPLIED_RUN_WITHOUT_PAGES)
            ->update(['status' => EnterpriseWikiLintFinding::STATUS_RESOLVED]);

        // Re-run — condition still applies, so it should be re-opened
        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run->id]);

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'enterprise_wiki_ingest_run_id' => $run->id,
            'code' => EnterpriseWikiLintFinding::CODE_APPLIED_RUN_WITHOUT_PAGES,
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
        ]);
    }

    // =========================================================================
    // Stale resolution
    // =========================================================================

    public function test_resolves_stale_findings_when_condition_no_longer_holds(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);

        // First run: no pages — creates applied_run_without_pages finding
        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run->id]);

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'enterprise_wiki_ingest_run_id' => $run->id,
            'code' => EnterpriseWikiLintFinding::CODE_APPLIED_RUN_WITHOUT_PAGES,
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
        ]);

        // Fix: add pages to the run
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Added Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Added Summary');

        // Second run: condition resolved — finding should be closed
        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run->id]);

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'enterprise_wiki_ingest_run_id' => $run->id,
            'code' => EnterpriseWikiLintFinding::CODE_APPLIED_RUN_WITHOUT_PAGES,
            'status' => EnterpriseWikiLintFinding::STATUS_RESOLVED,
        ]);
    }

    // =========================================================================
    // No side effects
    // =========================================================================

    public function test_command_does_not_create_claims(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $claimsBefore = EnterpriseWikiClaim::query()->count();

        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run->id]);

        $this->assertSame($claimsBefore, EnterpriseWikiClaim::query()->count());
    }

    public function test_command_does_not_create_source_references(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $refsBefore = EnterpriseWikiSourceReference::query()->count();

        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run->id]);

        $this->assertSame($refsBefore, EnterpriseWikiSourceReference::query()->count());
    }

    public function test_command_does_not_create_page_links(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $linksBefore = EnterpriseWikiPageLink::query()->count();

        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run->id]);

        $this->assertSame($linksBefore, EnterpriseWikiPageLink::query()->count());
    }

    public function test_command_does_not_modify_run_status(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);

        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run->id]);

        $this->assertSame(
            EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            $run->fresh()->maintainer_decision_status,
        );
    }

    // =========================================================================
    // CLI output
    // =========================================================================

    public function test_command_outputs_run_id(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);

        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run->id]);

        $this->assertStringContainsString("Run ID: {$run->id}", Artisan::output());
    }

    public function test_command_outputs_findings_created_count(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);

        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run->id]);

        $output = Artisan::output();
        $this->assertStringContainsString('Findings created:', $output);
    }

    public function test_command_outputs_zero_findings_skipped_on_first_run(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);

        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run->id]);

        $this->assertStringContainsString('Findings skipped: 0', Artisan::output());
    }

    // =========================================================================
    // Customer scoping
    // =========================================================================

    public function test_findings_are_scoped_to_run_customer(): void
    {
        $customer1 = $this->createCustomer('Customer One');
        $customer2 = $this->createCustomer('Customer Two');
        $run1 = $this->createAppliedRun($customer1);
        $run2 = $this->createAppliedRun($customer2);

        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run1->id]);
        Artisan::call('wiki:lint-applied-run', ['--run-id' => $run2->id]);

        $this->assertTrue(
            EnterpriseWikiLintFinding::query()
                ->where('customer_id', $customer1->id)
                ->where('enterprise_wiki_ingest_run_id', $run2->id)
                ->doesntExist()
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

    private function createDocument(Customer $customer): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'source.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Source text for lint tests.',
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

    private function createRunPending(Customer $customer): EnterpriseWikiIngestRun
    {
        $document = $this->createDocument($customer);

        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_PENDING,
            'maintainer_decision_generated_at' => now(),
        ]);
    }

    private function createPage(Customer $customer, string $pageType, string $title): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(4)),
            'title' => $title,
            'page_type' => $pageType,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
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

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => "# {$title}\n\nContent.",
            'generated_by_model' => 'gpt-5',
        ]);

        return $page;
    }

    /**
     * Same as createVersionedPage() but with an explicit content_blocks_json — needed for the
     * new best_practice/structural/source_based lint checks, which read block-level content_origin
     * directly rather than only the claims table.
     */
    private function createVersionedPageWithBlocks(
        Customer $customer,
        EnterpriseWikiIngestRun $run,
        string $pageType,
        string $title,
        array $blocks,
    ): EnterpriseWikiPage {
        $page = $this->createPage($customer, $pageType, $title);
        $this->addPageToRun($run, $page);

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => "# {$title}\n\nContent.",
            'content_blocks_json' => $blocks,
            'generated_by_model' => 'gpt-5',
        ]);

        return $page;
    }

    private function bestPracticeBlock(string $blockKey, string $markdown, string $reason): array
    {
        return [
            'block_key' => $blockKey,
            'position' => 0,
            'markdown' => $markdown,
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'best_practice_reason' => $reason,
            'source_elements' => [],
        ];
    }

    private function structuralBlock(string $blockKey, string $markdown): array
    {
        return [
            'block_key' => $blockKey,
            'position' => 0,
            'markdown' => $markdown,
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_STRUCTURAL,
            'best_practice_reason' => null,
            'source_elements' => [],
        ];
    }

    private function sourceBasedBlockFixture(string $blockKey, string $markdown): array
    {
        return [
            'block_key' => $blockKey,
            'position' => 1,
            'markdown' => $markdown,
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            'best_practice_reason' => null,
            'source_elements' => [],
        ];
    }

    private function currentVersion(EnterpriseWikiPage $page): EnterpriseWikiPageVersion
    {
        return EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->where('is_current', true)
            ->firstOrFail();
    }

    private function createClaim(EnterpriseWikiPageVersion $version, string $text): EnterpriseWikiClaim
    {
        return EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $version->enterprise_wiki_page_id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => $text,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ]);
    }

    private function createSourceRef(
        EnterpriseWikiClaim $claim,
        EnterpriseWikiDocument $document,
        string $excerpt,
    ): EnterpriseWikiSourceReference {
        return EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $claim->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_label' => $document->original_filename,
            'excerpt' => $excerpt,
        ]);
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
