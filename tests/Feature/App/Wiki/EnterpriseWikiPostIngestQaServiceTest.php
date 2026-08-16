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
use App\Models\EnterpriseWikiSourceReference;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\Ai\Wiki\WikiClaimVerificationAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiAppliedRunLintService;
use App\Services\EnterpriseWiki\EnterpriseWikiPageVersionBlockProvenanceRepairService;
use App\Services\EnterpriseWiki\EnterpriseWikiPostIngestQaService;
use App\Services\EnterpriseWiki\EnterpriseWikiVerifyPageClaimsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Post-ingest QA (redesigned as a minimal, deterministic end check): no OpenAI calls, no
 * content generation, no rewriting, no re-analysis. It only checks facts already recorded by
 * the pipeline — see EnterpriseWikiPostIngestQaService's own class docs for the four checks and
 * the passed/failed/escalated mapping.
 */
class EnterpriseWikiPostIngestQaServiceTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Guard: run must be applied
    // =========================================================================

    public function test_throws_when_run_is_not_applied(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createRun($customer, maintainerStatus: EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_PENDING);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/only 'applied'/");

        $this->service()->runForRun($run);
    }

    // =========================================================================
    // Idempotency: skip when already running or passed
    // =========================================================================

    public function test_returns_null_when_already_passed(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_PASSED);

        $result = $this->service()->runForRun($run);

        $this->assertNull($result);
    }

    public function test_returns_null_when_already_running(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_RUNNING);

        $result = $this->service()->runForRun($run);

        $this->assertNull($result);
    }

    // =========================================================================
    // Happy path: passed
    // =========================================================================

    public function test_sets_passed_when_article_and_summary_have_content_and_steps_are_complete(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);

        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $this->markStepsComplete($run);

        $result = $this->service()->runForRun($run);

        $this->assertNotNull($result);
        $this->assertTrue($result['checks']['article_exists']);
        $this->assertTrue($result['checks']['summary_exists']);
        $this->assertTrue($result['checks']['article_has_content']);
        $this->assertTrue($result['checks']['summary_has_content']);
        $this->assertSame([], $result['critical_defects']);
        $this->assertSame([], $result['incomplete_steps']);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
        $this->assertNotNull($run->qa_completed_at);
        $this->assertSame(1, $run->qa_attempt_count);
        $this->assertNull($run->qa_last_error);
    }

    public function test_stores_qa_result_json(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);

        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $this->markStepsComplete($run);

        $this->service()->runForRun($run);

        $run->refresh();

        $this->assertIsArray($run->qa_result);
        $this->assertArrayHasKey('checks', $run->qa_result);
        $this->assertArrayHasKey('coverage_summary', $run->qa_result);
        $this->assertArrayHasKey('lint_summary', $run->qa_result);
        $this->assertArrayHasKey('critical_defects', $run->qa_result);
        $this->assertArrayHasKey('incomplete_steps', $run->qa_result);
        $this->assertNull($run->qa_result['semantic_qa']);
        $this->assertFalse($run->qa_result['repair_attempted']);
    }

    // =========================================================================
    // Failed: a concrete, understood content defect
    // =========================================================================

    public function test_failed_when_article_page_missing(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);

        // Only summary — no article page in run_pages at all.
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $this->markStepsComplete($run);

        $result = $this->service()->runForRun($run);

        $this->assertNotNull($result);
        $this->assertFalse($result['checks']['article_exists']);
        $this->assertContains('missing_article_or_summary', $result['critical_defects']);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_FAILED, $run->qa_status);
        $this->assertNotEmpty($run->qa_last_error);
    }

    public function test_failed_when_summary_page_missing(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);

        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        // No summary in run_pages.
        $this->markStepsComplete($run);

        $result = $this->service()->runForRun($run);

        $this->assertFalse($result['checks']['summary_exists']);
        $this->assertContains('missing_article_or_summary', $result['critical_defects']);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_FAILED, $run->qa_status);
    }

    public function test_failed_when_article_has_empty_content(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);

        $article = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->addPageToRun($run, $article);
        // Version with empty content.
        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $article->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => '',
            'generated_by_model' => 'gpt-5',
        ]);

        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $this->markStepsComplete($run);

        $result = $this->service()->runForRun($run);

        // Article page exists structurally, but content is empty.
        $this->assertTrue($result['checks']['article_exists']);
        $this->assertFalse($result['checks']['article_has_content']);
        $this->assertNotEmpty($result['critical_defects']);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_FAILED, $run->qa_status);
    }

    public function test_failed_when_a_concept_page_has_no_current_version(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);

        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        // Concept page attached to the run, but no version was ever created for it.
        $concept = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Concept');
        $this->addPageToRun($run, $concept);
        $this->markStepsComplete($run);

        $result = $this->service()->runForRun($run);

        // Article/summary check alone would pass — the broader all-pages check must catch this.
        $this->assertTrue($result['checks']['article_has_content']);
        $this->assertTrue($result['checks']['summary_has_content']);
        $this->assertContains('missing_or_empty_page_version', $result['critical_defects']);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_FAILED, $run->qa_status);
    }

    // =========================================================================
    // Escalated: cannot be safely determined yet — continuation steps not finished
    // =========================================================================

    public function test_escalated_when_run_has_no_applied_pages(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);

        $result = $this->service()->runForRun($run);

        $this->assertNotNull($result);
        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_ESCALATED, $run->qa_status);
    }

    public function test_escalated_when_page_generation_not_completed(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);

        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $this->markStepsComplete($run);

        EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->limit(1)
            ->update(['generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_RUNNING]);

        $result = $this->service()->runForRun($run);

        $this->assertContains('page_generation_incomplete', $result['incomplete_steps']);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_ESCALATED, $run->qa_status);
    }

    public function test_escalated_when_claim_extraction_not_completed(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);

        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $this->markStepsComplete($run);

        EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->limit(1)
            ->update(['claims_extracted_at' => null]);

        $result = $this->service()->runForRun($run);

        $this->assertContains('extraction_incomplete', $result['incomplete_steps']);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_ESCALATED, $run->qa_status);
    }

    public function test_escalated_when_an_active_extraction_lease_is_held(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);

        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $this->markStepsComplete($run);

        EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->limit(1)
            ->update(['claims_claimed_at' => now(), 'claims_claim_token' => 'another-worker']);

        $result = $this->service()->runForRun($run);

        $this->assertContains('extraction_lease_active', $result['incomplete_steps']);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_ESCALATED, $run->qa_status);
    }

    public function test_escalated_when_claim_verification_not_completed(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);

        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $this->markStepsComplete($run);

        $version = $article->versions()->where('is_current', true)->first();
        EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $article->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'Unverified claim.',
            'position_order' => 1,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            // verified_at intentionally left null.
        ]);

        $result = $this->service()->runForRun($run);

        $this->assertContains('verification_incomplete', $result['incomplete_steps']);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_ESCALATED, $run->qa_status);
    }

    // =========================================================================
    // Attempt count increments on re-run
    // =========================================================================

    public function test_increments_attempt_count_on_each_run(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);

        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $this->markStepsComplete($run);

        $this->service()->runForRun($run);

        $run->refresh();
        $this->assertSame(1, $run->qa_attempt_count);
    }

    // =========================================================================
    // Customer scope: other customer's runs not affected
    // =========================================================================

    public function test_does_not_affect_other_customers_runs(): void
    {
        $customerA = $this->createCustomer('A');
        $customerB = $this->createCustomer('B');

        $runA = $this->createAppliedRun($customerA);
        $runB = $this->createAppliedRun($customerB);

        $this->createVersionedPage($customerA, $runA, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'A Article');
        $this->createVersionedPage($customerA, $runA, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'A Summary');
        $this->markStepsComplete($runA);

        $this->service()->runForRun($runA);

        $runB->refresh();
        $this->assertNull($runB->qa_status, 'Run for other customer must not be touched.');
    }

    // =========================================================================
    // findPendingRuns
    // =========================================================================

    public function test_find_pending_runs_returns_null_qa_runs(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer, qaStatus: null);

        $pending = $this->service()->findPendingRuns();

        $this->assertTrue($pending->contains('id', $run->id));
    }

    public function test_find_pending_runs_excludes_passed(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_PASSED);

        $pending = $this->service()->findPendingRuns();

        $this->assertFalse($pending->contains('id', $run->id));
    }

    public function test_find_pending_runs_excludes_running(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_RUNNING);

        $pending = $this->service()->findPendingRuns();

        $this->assertFalse($pending->contains('id', $run->id));
    }

    public function test_find_pending_runs_excludes_failed(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_FAILED);

        $pending = $this->service()->findPendingRuns();

        $this->assertFalse($pending->contains('id', $run->id));
    }

    public function test_find_pending_runs_excludes_escalated(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_ESCALATED);

        $pending = $this->service()->findPendingRuns();

        $this->assertFalse($pending->contains('id', $run->id));
    }

    // =========================================================================
    // Retry mode: failed and escalated require explicit $retry = true
    // =========================================================================

    public function test_default_mode_skips_failed_run(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_FAILED);

        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $this->markStepsComplete($run);

        $result = $this->service()->runForRun($run);

        $this->assertNull($result, 'Failed run must not be claimed without --retry.');
        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_FAILED, $run->qa_status);
    }

    public function test_default_mode_skips_escalated_run(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_ESCALATED);

        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $this->markStepsComplete($run);

        $result = $this->service()->runForRun($run);

        $this->assertNull($result, 'Escalated run must not be claimed without --retry.');
        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_ESCALATED, $run->qa_status);
    }

    public function test_retry_mode_can_run_failed_run(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_FAILED);

        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $this->markStepsComplete($run);

        $result = $this->service()->runForRun($run, retry: true);

        $this->assertNotNull($result);
        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
    }

    public function test_retry_mode_can_run_escalated_run(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_ESCALATED);

        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $this->markStepsComplete($run);

        $result = $this->service()->runForRun($run, retry: true);

        $this->assertNotNull($result);
        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
    }

    public function test_find_retryable_runs_includes_failed_and_escalated(): void
    {
        $customer = $this->createCustomer();
        $runF = $this->createAppliedRun($customer, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_FAILED);
        $runE = $this->createAppliedRun($customer, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_ESCALATED);

        $retryable = $this->service()->findRetryableRuns();

        $this->assertTrue($retryable->contains('id', $runF->id));
        $this->assertTrue($retryable->contains('id', $runE->id));
    }

    // =========================================================================
    // Run-34 fix: a stale qa_status=passed run can be explicitly re-evaluated once a claim-
    // integrity defect is discovered — but never picked up by scheduled/bulk sweeps.
    // =========================================================================

    public function test_default_mode_skips_passed_run(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_PASSED);

        $result = $this->service()->runForRun($run);

        $this->assertNull($result, 'Passed run must not be re-evaluated without --retry.');
        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
    }

    public function test_retry_mode_can_re_evaluate_a_passed_run_and_reports_the_new_claim_qa_signal(): void
    {
        // v0.10 (docs/enterprise-llm-wiki-plan.md, "Arkitekturnotat — v0.10"): claim QA signals
        // never downgrade qa_status away from passed — a re-evaluation still reports the newly
        // discovered signal informationally, but the run stays passed.
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_PASSED);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $this->markStepsComplete($run);

        // A claim QA signal discovered after the run was originally marked passed.
        $version = $article->versions()->where('is_current', true)->first();
        EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $article->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'Bad claim.',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            'content_block_key' => 'block-0001',
            'review_metadata' => [
                'classification_basis' => 'semantic_verification',
                'verdict' => 'not_supported',
            ],
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'verified_at' => now(),
        ]);

        $result = $this->service()->runForRun($run, retry: true);

        $this->assertNotNull($result);
        $this->assertContains('open_unsupported_generated_content_claims', $result['claim_qa_signals']);
        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
    }

    // =========================================================================
    // Run-482 fix: a document like "Incident Management Illustration.docx" whose only real
    // content is one short paragraph plus one figure generates additional best-practice
    // guidance beyond the source — this must not, on its own, escalate the run.
    // =========================================================================

    public function test_run_with_only_correctly_marked_best_practice_content_does_not_escalate(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Incident Management Illustration');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Sammendrag: Incident Management Illustration');
        $version = $article->versions()->where('is_current', true)->first();

        // The model generated this best-practice guidance but — exactly the run-482 bug — mis-
        // tagged it source_based instead of best_practice.
        $bestPracticeText = 'Det anbefales å definere tydelige roller, eskaleringspunkter og responstider.';

        $version->update([
            'content_markdown' => "# Incident Management Illustration\n\nFiguren under illustrerer samhandlingsprosessen mellom Kunden og Leverandøren.\n\n{$bestPracticeText}",
            'content_blocks_json' => [
                [
                    'block_key' => 'block-0001',
                    'position' => 0,
                    'markdown' => 'Figuren under illustrerer samhandlingsprosessen mellom Kunden og Leverandøren.',
                    'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                    'source_elements' => [[
                        'source_element_key' => 'paragraph-0',
                        'source_element_type' => 'paragraph',
                        'source_excerpt' => 'Figuren under illustrerer samhandlingsprosessen mellom Kunden og Leverandøren.',
                        'page_reference' => 'Løpende tekst',
                    ]],
                ],
                [
                    'block_key' => 'block-0002',
                    'position' => 1,
                    'markdown' => $bestPracticeText,
                    'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                    'source_elements' => [[
                        'source_element_key' => 'paragraph-0',
                        'source_element_type' => 'paragraph',
                        'source_excerpt' => 'Figuren under illustrerer samhandlingsprosessen mellom Kunden og Leverandøren.',
                        'page_reference' => 'Løpende tekst',
                    ]],
                ],
            ],
        ]);

        EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $article->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'Figuren under illustrerer samhandlingsprosessen mellom Kunden og Leverandøren.',
            'page_excerpt' => 'Figuren under illustrerer samhandlingsprosessen mellom Kunden og Leverandøren.',
            'content_block_key' => 'block-0001',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ]);
        $bestPracticeClaim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $article->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => $bestPracticeText,
            'page_excerpt' => $bestPracticeText,
            'content_block_key' => 'block-0002',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            'position_order' => 1,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ]);

        $this->mock(WikiClaimVerificationAiClient::class)
            ->shouldReceive('verifyClaim')
            ->andReturn([
                'verdict' => 'not_supported',
                'reason' => 'The source only describes the figure, not this guidance.',
                'checks' => [],
            ]);

        // The real verification pipeline — proves the rescue fix end to end, not just in
        // isolation.
        app(EnterpriseWikiVerifyPageClaimsService::class)->verify($run->fresh());

        $bestPracticeClaim->refresh();
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE, $bestPracticeClaim->content_origin);

        $this->markStepsComplete($run);

        $result = $this->service()->runForRun($run->fresh());

        $this->assertNotNull($result);
        $this->assertNotContains('open_unsupported_generated_content_claims', $result['claim_qa_signals']);
        $run->refresh();
        $this->assertNotSame(EnterpriseWikiIngestRun::QA_STATUS_ESCALATED, $run->qa_status);
        $this->assertNotSame(EnterpriseWikiIngestRun::QA_STATUS_REPAIR_REQUIRED, $run->qa_status);
    }

    /**
     * Regression for ingest run 486 (real production document "Incident Management Illustration
     * (E2E).docx"): the block was correctly tagged best_practice at generation time (not
     * mis-tagged source_based, unlike the run-482 case above) — the bug here was that the claim
     * extracted from it, a supporting sentence with no marker word of its own, was independently
     * downgraded to unsupported_generated_content anyway, escalating the run for content that was
     * never presented as a customer-specific fact.
     */
    public function test_run_with_best_practice_block_supporting_sentence_lacking_its_own_marker_does_not_escalate(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Incident Management Illustration');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Sammendrag: Incident Management Illustration');
        $version = $article->versions()->where('is_current', true)->first();

        // Real run-486 wording: a supporting/context sentence within a best_practice-tagged
        // recommendation paragraph, extracted as its own claim. It carries no "bør"/"anbefales"
        // marker of its own (that lives in a sibling sentence of the same block) and does not
        // assert anything about the customer's current state.
        $supportingText = 'Typiske grenseflater omfatter problemhåndtering, endringsstyring, kunnskapsforvaltning og forespørselshåndtering i ITIL.';

        $version->update([
            'content_markdown' => "# Incident Management Illustration\n\n{$supportingText}",
            'content_blocks_json' => [[
                'block_key' => 'block-0001',
                'position' => 0,
                'markdown' => 'Når illustrasjonen brukes i operativ styring, bør team vurdere hvordan hendelser henger sammen med beslektede prosesser. '.$supportingText,
                'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
                'best_practice_reason' => 'Generell ITSM-kontekst utover kildedokumentet.',
            ]],
        ]);

        $claim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $article->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => $supportingText,
            'page_excerpt' => $supportingText,
            'content_block_key' => 'block-0001',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'review_metadata' => [
                'statement_kind' => 'recommendation',
                'classification_basis' => 'ai_block_content_origin',
                'suggested_placement' => 'block-0001',
                'visible_wiki_link_recommendation' => 'not_needed',
            ],
        ]);

        $this->mock(WikiClaimVerificationAiClient::class)
            ->shouldReceive('verifyClaim')
            ->never();

        app(EnterpriseWikiVerifyPageClaimsService::class)->verify($run->fresh());

        $claim->refresh();
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE, $claim->content_origin);

        $this->markStepsComplete($run);

        $result = $this->service()->runForRun($run->fresh());

        $this->assertNotNull($result);
        $this->assertNotContains('open_unsupported_generated_content_claims', $result['claim_qa_signals']);
        $run->refresh();
        $this->assertNotSame(EnterpriseWikiIngestRun::QA_STATUS_ESCALATED, $run->qa_status);
        $this->assertNotSame(EnterpriseWikiIngestRun::QA_STATUS_REPAIR_REQUIRED, $run->qa_status);
    }

    // =========================================================================
    // Block-anchor safety net (Preserve Wiki block provenance after semantic repair): confirms
    // isPositiveBestPracticeSuggestion() still refuses to rescue a claim with no resolvable
    // content_block_key — this task's block-provenance fix restores the anchor earlier in the
    // pipeline, it never loosens this structural requirement — and that restoring the anchor via
    // EnterpriseWikiPageVersionBlockProvenanceRepairService is what lets a legitimate best-practice
    // claim be rescued afterwards.
    // =========================================================================

    public function test_claim_without_a_resolvable_block_key_is_never_rescued_to_best_practice(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'ITIL');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Sammendrag: ITIL');
        $version = $article->versions()->where('is_current', true)->first();

        // Exactly the confirmed production drift (claims 5382/5384 on page 290 "ITIL"): real,
        // eligible best-practice wording, but content_blocks_json is empty on this version — no
        // prior block-bearing version exists in this test, so there is nothing to reconstruct.
        $bestPracticeText = 'Bruk av prosessbilder sammen med nøkkelindikatorer gjør årsak-virkning-forhold mer synlige.';

        $version->update([
            'content_markdown' => "# ITIL\n\n{$bestPracticeText}",
            'content_blocks_json' => [],
        ]);

        $claim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $article->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => $bestPracticeText,
            'page_excerpt' => $bestPracticeText,
            'content_block_key' => '',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ]);

        $this->mock(WikiClaimVerificationAiClient::class)
            ->shouldReceive('verifyClaim')
            ->andReturn([
                'verdict' => 'not_supported',
                'reason' => 'The source does not describe this guidance.',
                'checks' => [],
            ]);

        app(EnterpriseWikiVerifyPageClaimsService::class)->verify($run->fresh());

        $claim->refresh();
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT, $claim->content_origin);
    }

    public function test_restoring_the_block_anchor_lets_the_same_claim_be_rescued_to_best_practice(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'ITIL');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Sammendrag: ITIL');

        $bestPracticeText = 'Bruk av prosessbilder sammen med nøkkelindikatorer gjør årsak-virkning-forhold mer synlige.';

        // A prior version carries the real block (as if generated normally) — mirroring the real
        // production page 290 "ITIL" shape, the block's own markdown includes the page heading
        // (single newline, not a blank line) so it forms exactly one "\n\n"-delimited segment.
        $blockMarkdown = "# ITIL\n{$bestPracticeText}";
        $priorVersion = $article->versions()->where('is_current', true)->first();
        $priorVersion->update([
            'is_current' => false,
            'content_markdown' => $blockMarkdown,
            'content_blocks_json' => [[
                'block_key' => 'block-0001',
                'position' => 0,
                'markdown' => $blockMarkdown,
                'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
                'best_practice_reason' => 'Generell ITSM-anbefaling.',
            ]],
        ]);

        // …but the CURRENT version is exactly the confirmed drift left by a semantic repair that
        // predates this task's fix: content_markdown carried over unchanged, content_blocks_json empty.
        $current = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $article->id,
            'version_number' => 2,
            'is_current' => true,
            'content_markdown' => $blockMarkdown,
            'content_blocks_json' => [],
            'generated_by_model' => 'gpt-5/semantic-repair',
        ]);

        $claim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $article->id,
            'enterprise_wiki_page_version_id' => $current->id,
            'claim_text' => $bestPracticeText,
            'page_excerpt' => $bestPracticeText,
            'content_block_key' => '',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ]);

        // This is exactly what EnterpriseWikiLinkSemanticRepairService/EnterpriseWikiSemanticRepairService
        // now call automatically right after creating a new current version (see restoreBlockProvenance()
        // in both services) — invoked directly here to isolate the effect on classification.
        app(EnterpriseWikiPageVersionBlockProvenanceRepairService::class)
            ->repairPageVersion($article->id, $current);

        $this->assertSame('block-0001', $claim->fresh()->content_block_key);

        $this->mock(WikiClaimVerificationAiClient::class)
            ->shouldReceive('verifyClaim')
            ->andReturn([
                'verdict' => 'not_supported',
                'reason' => 'The source does not describe this guidance.',
                'checks' => [],
            ]);

        app(EnterpriseWikiVerifyPageClaimsService::class)->verify($run->fresh());

        $claim->refresh();
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE, $claim->content_origin);
    }

    public function test_run_with_an_unsupported_customer_fact_still_reports_an_open_qa_signal_but_never_blocks(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Incident Management Illustration');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Sammendrag: Incident Management Illustration');
        $version = $article->versions()->where('is_current', true)->first();

        $version->update([
            'content_markdown' => "# Incident Management Illustration\n\nKunden har fem godkjente eskaleringsnivåer definert i sin styringsmodell.",
            'content_blocks_json' => [[
                'block_key' => 'block-0001',
                'position' => 0,
                'markdown' => 'Kunden har fem godkjente eskaleringsnivåer definert i sin styringsmodell.',
                'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                'source_elements' => [[
                    'source_element_key' => 'paragraph-0',
                    'source_element_type' => 'paragraph',
                    'source_excerpt' => 'Figuren under illustrerer samhandlingsprosessen mellom Kunden og Leverandøren.',
                    'page_reference' => 'Løpende tekst',
                ]],
            ]],
        ]);

        // No best-practice marker at all — a plain, specific, unsupported customer fact. This
        // must still surface as an open claim QA signal — the fix above is scoped to genuinely
        // best-practice content only — but per v0.10 it never blocks the run either way.
        $unsupportedText = 'Kunden har fem godkjente eskaleringsnivåer definert i sin styringsmodell.';

        $claim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $article->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => $unsupportedText,
            'page_excerpt' => $unsupportedText,
            'content_block_key' => 'block-0001',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ]);

        $this->mock(WikiClaimVerificationAiClient::class)
            ->shouldReceive('verifyClaim')
            ->andReturn([
                'verdict' => 'not_supported',
                'reason' => 'The source does not mention escalation levels.',
                'checks' => [],
            ]);

        app(EnterpriseWikiVerifyPageClaimsService::class)->verify($run->fresh());

        $claim->refresh();
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT, $claim->content_origin);

        $this->markStepsComplete($run);

        $result = $this->service()->runForRun($run->fresh());

        $this->assertNotNull($result);
        $this->assertContains('open_unsupported_generated_content_claims', $result['claim_qa_signals']);
        $run->refresh();
        // v0.10: the open claim QA signal is reported for the voluntary QA screen, but never
        // gates qa_status — the run is technically sound and passes.
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
    }

    public function test_find_pending_runs_and_find_retryable_runs_never_include_passed(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_PASSED);

        $this->assertFalse($this->service()->findPendingRuns()->contains('id', $run->id));
        $this->assertFalse($this->service()->findRetryableRuns()->contains('id', $run->id));
    }

    // =========================================================================
    // Lint errors are a critical (failed) defect; warnings do not block passed
    // =========================================================================

    public function test_failed_when_lint_error_blocks_passed(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);

        // Article with content + a claim whose source reference points to a non-existent document.
        // This produces a source_reference_without_document ERROR during lint.
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $this->markStepsComplete($run);

        $version = $article->versions()->where('is_current', true)->first();
        $claim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $article->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'Test claim.',
            'position_order' => 1,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'verified_at' => now(),
        ]);

        EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $claim->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => 999999, // Non-existent document.
            'source_label' => 'Missing doc',
            'excerpt' => 'some excerpt',
        ]);

        // Lint findings must already exist for QA to read — the continuation pipeline's own
        // lint stage would normally have written these before QA runs.
        app(EnterpriseWikiAppliedRunLintService::class)->lint($run->fresh());

        $result = $this->service()->runForRun($run);

        $this->assertNotNull($result);
        $this->assertTrue($result['checks']['article_has_content']);
        $this->assertTrue($result['checks']['summary_has_content']);
        $this->assertContains('critical_lint_findings_or_broken_links', $result['critical_defects']);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_FAILED, $run->qa_status);
    }

    public function test_passed_when_only_lint_warnings_exist(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);

        // Article with content + a claim with no source reference → warning, not error.
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $this->markStepsComplete($run);

        $version = $article->versions()->where('is_current', true)->first();
        EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $article->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'Test claim with no source.',
            'position_order' => 1,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'verified_at' => now(),
        ]);
        // No source references on the claim → claim_missing_source WARNING is created.

        app(EnterpriseWikiAppliedRunLintService::class)->lint($run->fresh());

        $result = $this->service()->runForRun($run);

        $this->assertNotNull($result);
        $this->assertSame([], $result['critical_defects'], 'Warnings must not block passed.');

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
    }

    public function test_current_wikilink_integrity_blocks_qa_but_a_superseded_version_finding_does_not(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $this->markStepsComplete($run);

        $currentVersion = $article->currentVersion()->firstOrFail();
        EnterpriseWikiLintFinding::query()->create([
            'customer_id' => $customer->id,
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $article->id,
            'enterprise_wiki_page_version_id' => $currentVersion->id,
            'code' => EnterpriseWikiLintFinding::CODE_BROKEN_WIKILINK,
            'severity' => EnterpriseWikiLintFinding::SEVERITY_ERROR,
            'message' => 'Current integrity defect.',
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
            'detected_at' => now(),
        ]);

        $this->service()->runForRun($run);
        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_FAILED, $run->qa_status);

        $run->update(['qa_status' => null, 'qa_started_at' => null, 'qa_completed_at' => null, 'qa_last_error' => null]);
        $currentVersion->update(['is_current' => false]);
        $replacement = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $article->id,
            'version_number' => 2,
            'is_current' => true,
            'content_markdown' => '# Article\n\nCurrent, healthy content.',
            'generated_by_model' => 'gpt-5',
        ]);
        $this->assertNotNull($replacement);

        $result = $this->service()->runForRun($run->fresh());

        $this->assertSame([], $result['critical_defects']);
        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
    }

    // =========================================================================
    // ProcessEnterpriseWikiIngest is untouched
    // =========================================================================

    public function test_process_enterprise_wiki_ingest_is_not_modified(): void
    {
        $path = app_path('Jobs/Ai/Wiki/ProcessEnterpriseWikiIngest.php');
        $hash = md5_file($path);

        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'A');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'S');
        $this->markStepsComplete($run);

        $this->service()->runForRun($run);

        $this->assertSame($hash, md5_file($path), 'ProcessEnterpriseWikiIngest must not be modified.');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function service(): EnterpriseWikiPostIngestQaService
    {
        return app(EnterpriseWikiPostIngestQaService::class);
    }

    /**
     * Marks every continuation step this run's pages depend on as complete — page generation,
     * claim extraction, and claim verification — so QA can reach a real passed/failed verdict
     * instead of escalating on "not finished yet". Call after all pages/claims for a test are
     * set up.
     */
    private function markStepsComplete(EnterpriseWikiIngestRun $run): void
    {
        EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->update([
                'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
                'claims_extracted_at' => now(),
                'claims_claimed_at' => null,
                'claims_claim_token' => null,
            ]);

        $pageIds = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->pluck('enterprise_wiki_page_id');

        EnterpriseWikiClaim::query()
            ->whereIn('enterprise_wiki_page_id', $pageIds)
            ->whereNull('verified_at')
            ->update(['verified_at' => now(), 'verification_claimed_at' => null, 'verification_claim_token' => null]);
    }

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
            'extracted_text' => 'Source document text for QA tests.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createRun(Customer $customer, string $maintainerStatus): EnterpriseWikiIngestRun
    {
        $document = $this->createDocument($customer);

        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_status' => $maintainerStatus,
            'maintainer_decision_generated_at' => now(),
        ]);
    }

    private function createAppliedRun(Customer $customer, ?string $qaStatus = null): EnterpriseWikiIngestRun
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
            'maintainer_decision_json' => ['pages' => []],
            'qa_status' => $qaStatus,
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
}
