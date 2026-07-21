<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiPageVersionDocumentOwnerApproval;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentFlowService;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentOwnerApprovalService;
use App\Services\EnterpriseWiki\EnterpriseWikiPostIngestQaService;
use App\Services\EnterpriseWiki\EnterpriseWikiRunFindingsService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Run 34 fix: a run whose current page-version claims include an active
 * unsupported_generated_content / internal_error claim, or a source_based claim missing its
 * source reference, must never reach qa_status=passed / awaiting_document_owner_approval — see
 * EnterpriseWikiPostIngestQaService::findClaimIntegrityDefects(),
 * EnterpriseWikiDocumentFlowService::escalateRunForClaimIntegrityRepair(),
 * EnterpriseWikiDocumentOwnerApprovalService::hasActiveClaimIntegrityDefects().
 */
class EnterpriseWikiClaimIntegrityGatingTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    // =========================================================================
    // QA gating (Del 10, items 5-9)
    // =========================================================================

    public function test_active_internal_error_claim_does_not_block_qa_passed_by_default(): void
    {
        // Product rule: internal_error is a technical uncertainty (missing/ambiguous block-source
        // link), not a confirmed content error — it no longer suggests blocking by default (see
        // EnterpriseWikiClaimFindingExplainer::suggestedBlocking()). An authorized user can still
        // opt back in via blocking_override — see the next test.
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $version = $this->currentVersion($article);
        $this->createClaim($article, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR);
        $this->markStepsComplete($run);

        $result = $this->qaService()->runForRun($run);

        $this->assertNotContains('active_internal_error_claims', $result['claim_integrity_defects']);
        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
    }

    public function test_active_internal_error_claim_blocks_qa_passed_when_override_kept(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $version = $this->currentVersion($article);
        $this->createClaim($article, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR, blockingOverride: true);
        $this->markStepsComplete($run);

        $result = $this->qaService()->runForRun($run);

        $this->assertContains('active_internal_error_claims', $result['claim_integrity_defects']);
        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_REPAIR_REQUIRED, $run->qa_status);
    }

    public function test_unverified_unsupported_generated_content_claim_does_not_block_qa_passed_by_default(): void
    {
        // Product rule (run-38 follow-up fix): a claim that never actually reached a semantic
        // verdict (no content_block_key, no source reference, no review_metadata) is a technical
        // linking uncertainty, not a confirmed content error — it must not suggest blocking by
        // default even though its content_origin is unsupported_generated_content. See
        // EnterpriseWikiClaimFindingExplainer.
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $version = $this->currentVersion($article);
        $this->createClaim($article, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT);
        $this->markStepsComplete($run);

        $result = $this->qaService()->runForRun($run);

        $this->assertNotContains('active_unsupported_generated_content_claims', $result['claim_integrity_defects']);
        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
    }

    public function test_unverified_unsupported_generated_content_claim_blocks_qa_passed_when_override_kept(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $version = $this->currentVersion($article);
        $this->createClaim($article, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT, blockingOverride: true);
        $this->markStepsComplete($run);

        $result = $this->qaService()->runForRun($run);

        $this->assertContains('active_unsupported_generated_content_claims', $result['claim_integrity_defects']);
        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_REPAIR_REQUIRED, $run->qa_status);
    }

    public function test_verified_unsupported_generated_content_claim_blocks_qa_passed(): void
    {
        // A claim that DID reach a real semantic verdict (block key + source reference +
        // review_metadata with a verdict) is a genuine, confirmed content error and still
        // suggests blocking by default — the fix above only changes claims that were never
        // actually checked.
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $document = EnterpriseWikiDocument::query()->find($run->source_id);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $version = $this->currentVersion($article);
        $this->createVerifiedUnsupportedClaim($article, $version, $document);
        $this->markStepsComplete($run);

        $result = $this->qaService()->runForRun($run);

        $this->assertContains('active_unsupported_generated_content_claims', $result['claim_integrity_defects']);
        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_REPAIR_REQUIRED, $run->qa_status);
    }

    // =========================================================================
    // v0.8 fix: an internal comparison-mechanism signal alone must never set
    // repair_required (docs/enterprise-llm-wiki-plan.md, "Arkitekturnotat — v0.8").
    // =========================================================================

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function dimensionMismatchReasonProvider(): iterable
    {
        yield 'negation_mismatch' => ['negation_mismatch'];
        yield 'modality_mismatch' => ['modality_mismatch'];
        yield 'actor_mismatch' => ['actor_mismatch'];
        yield 'scope_mismatch' => ['scope_mismatch'];
        yield 'subject_mismatch' => ['subject_mismatch'];
    }

    #[DataProvider('dimensionMismatchReasonProvider')]
    public function test_dimension_mismatch_claim_does_not_set_repair_required_by_default(string $deterministicReason): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $document = EnterpriseWikiDocument::query()->find($run->source_id);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $version = $this->currentVersion($article);
        $claim = $this->createClaim($article, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT, contentBlockKey: 'block-0001', reviewMetadata: [
            'classification_basis' => 'semantic_verification',
            'verdict' => 'not_supported',
            'deterministic_reason' => $deterministicReason,
        ]);
        $this->createSourceReference($claim, $document);
        $this->markStepsComplete($run);

        $result = $this->qaService()->runForRun($run);

        $this->assertNotContains('active_unsupported_generated_content_claims', $result['claim_integrity_defects']);
        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
    }

    public function test_self_reported_action_mismatch_does_not_set_repair_required_by_default(): void
    {
        // Distinct code path from the deterministic dimension mismatches above — a self-reported
        // AI check mismatch (no deterministic_reason, but checks.action = 'mismatch') is equally
        // an internal comparison-mechanism signal, not a confirmed content error.
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $document = EnterpriseWikiDocument::query()->find($run->source_id);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $version = $this->currentVersion($article);
        $claim = $this->createClaim($article, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT, contentBlockKey: 'block-0001', reviewMetadata: [
            'classification_basis' => 'semantic_verification',
            'verdict' => 'not_supported',
            'checks' => ['action' => 'mismatch'],
        ]);
        $this->createSourceReference($claim, $document);
        $this->markStepsComplete($run);

        $result = $this->qaService()->runForRun($run);

        $this->assertNotContains('active_unsupported_generated_content_claims', $result['claim_integrity_defects']);
        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
    }

    public function test_dimension_mismatch_claim_still_blocks_when_a_human_explicitly_overrides_it(): void
    {
        // An authorized user's explicit blocking_override = true is a real human decision, not a
        // hidden classification — it must still count regardless of the underlying category.
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $document = EnterpriseWikiDocument::query()->find($run->source_id);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $version = $this->currentVersion($article);
        $claim = $this->createClaim($article, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT, blockingOverride: true, contentBlockKey: 'block-0001', reviewMetadata: [
            'classification_basis' => 'semantic_verification',
            'verdict' => 'not_supported',
            'deterministic_reason' => 'negation_mismatch',
        ]);
        $this->createSourceReference($claim, $document);
        $this->markStepsComplete($run);

        $result = $this->qaService()->runForRun($run);

        $this->assertContains('active_unsupported_generated_content_claims', $result['claim_integrity_defects']);
        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_REPAIR_REQUIRED, $run->qa_status);
    }

    public function test_document_owner_approval_gate_agrees_with_qa_gate_for_dimension_mismatch_claim(): void
    {
        // EnterpriseWikiDocumentOwnerApprovalService::hasActiveClaimIntegrityDefectsForVersion()
        // must never disagree with the QA gate above about whether a dimension-mismatch claim is
        // effectively blocking.
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $document = EnterpriseWikiDocument::query()->find($run->source_id);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $version = $this->currentVersion($article);
        $claim = $this->createClaim($article, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT, contentBlockKey: 'block-0001', reviewMetadata: [
            'classification_basis' => 'semantic_verification',
            'verdict' => 'not_supported',
            'deterministic_reason' => 'scope_mismatch',
        ]);
        $this->createSourceReference($claim, $document);
        $this->markStepsComplete($run);

        $qaResult = $this->qaService()->runForRun($run);
        $docOwnerBlocks = app(EnterpriseWikiDocumentOwnerApprovalService::class)->hasActiveClaimIntegrityDefectsForVersion($version->fresh());

        $this->assertNotContains('active_unsupported_generated_content_claims', $qaResult['claim_integrity_defects']);
        $this->assertFalse($docOwnerBlocks);
    }

    public function test_genuine_technical_flow_failure_still_stops_the_run(): void
    {
        // v0.8 explicitly preserves this: a real technical flow failure (here, a missing current
        // page version for one of the run's pages) must still fail QA — unrelated to and unaffected
        // by the claim-classification gate fixed above.
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $conceptPage = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Concept Without Version');
        $this->addPageToRun($run, $conceptPage);
        // Deliberately no current version created for $conceptPage.
        $this->markStepsComplete($run);

        $result = $this->qaService()->runForRun($run);

        $this->assertContains('missing_or_empty_page_version', $result['critical_defects']);
        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_FAILED, $run->qa_status);
    }

    public function test_source_based_claim_without_source_reference_blocks_qa_passed(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $version = $this->currentVersion($article);
        // source_based but no EnterpriseWikiSourceReference row created.
        $this->createClaim($article, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED);
        $this->markStepsComplete($run);

        $result = $this->qaService()->runForRun($run);

        $this->assertContains('source_based_claims_missing_provenance', $result['claim_integrity_defects']);
        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_REPAIR_REQUIRED, $run->qa_status);
    }

    public function test_source_based_claim_with_source_reference_passes(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $document = EnterpriseWikiDocument::query()->find($run->source_id);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $version = $this->currentVersion($article);
        $claim = $this->createClaim($article, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED);
        $this->createSourceReference($claim, $document);
        $this->markStepsComplete($run);

        $result = $this->qaService()->runForRun($run);

        $this->assertSame([], $result['claim_integrity_defects']);
        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
    }

    public function test_pending_best_practice_claim_does_not_block_qa_passed(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $version = $this->currentVersion($article);
        $this->createClaim($article, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE);
        $this->markStepsComplete($run);

        $result = $this->qaService()->runForRun($run);

        $this->assertSame([], $result['claim_integrity_defects']);
        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
    }

    public function test_claim_on_superseded_version_does_not_block_qa_passed(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->createAppliedRun($customer, $document);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        $oldVersion = $this->currentVersion($article);
        // A defect on a superseded (non-current) version must not count as active.
        $this->createClaim($article, $oldVersion, EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR);

        $newVersion = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $article->id,
            'version_number' => 2,
            'is_current' => true,
            'content_markdown' => "# Article\n\nRevised.",
            'generated_by_model' => 'gpt-5',
        ]);
        $oldVersion->update(['is_current' => false]);

        $claim = $this->createClaim($article, $newVersion, EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED);
        $this->createSourceReference($claim, $document);

        EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->where('enterprise_wiki_page_id', $article->id)
            ->update(['generated_page_version_id' => $newVersion->id]);

        $this->markStepsComplete($run);

        $result = $this->qaService()->runForRun($run);

        $this->assertSame([], $result['claim_integrity_defects']);
        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
    }

    // =========================================================================
    // Document Owner transition (Del 10, items 10-12)
    // =========================================================================

    public function test_repair_required_run_does_not_reach_awaiting_document_owner_approval(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $page = $this->createPendingPage($customer, 'blocked-page');
        $version = $this->createCurrentVersion($page);
        $this->createClaim($page, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR);
        $run = $this->createRunAtQaStatus($customer, $page, $version, $document->id, EnterpriseWikiIngestRun::QA_STATUS_REPAIR_REQUIRED);

        app(EnterpriseWikiDocumentFlowService::class)->finalizeFromExistingQaResult($run);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_ESCALATED, $run->status);
        $this->assertNotSame(EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL, $run->status);
    }

    public function test_repair_required_run_creates_no_owner_approvals(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $page = $this->createPendingPage($customer, 'blocked-page-no-approvals');
        $version = $this->createCurrentVersion($page);
        // unsupported claim, no source reference at all.
        $this->createClaim($page, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT);
        $run = $this->createRunAtQaStatus($customer, $page, $version, $document->id, EnterpriseWikiIngestRun::QA_STATUS_REPAIR_REQUIRED);

        app(EnterpriseWikiDocumentFlowService::class)->finalizeFromExistingQaResult($run);

        $this->assertSame(
            0,
            EnterpriseWikiPageVersionDocumentOwnerApproval::query()
                ->where('enterprise_wiki_page_version_id', $version->id)
                ->count(),
        );
    }

    public function test_valid_run_still_reaches_awaiting_document_owner_approval(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer);
        $document = $this->createDocument($customer, $owner);
        $page = $this->createPendingPage($customer, 'valid-page');
        $version = $this->createCurrentVersion($page);
        $claim = $this->createClaim($page, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED);
        $this->createSourceReference($claim, $document);
        $run = $this->createRunAtQaStatus($customer, $page, $version, $document->id, EnterpriseWikiIngestRun::QA_STATUS_PASSED);

        app(EnterpriseWikiDocumentFlowService::class)->finalizeFromExistingQaResult($run);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL, $run->status);
        $this->assertSame(
            1,
            EnterpriseWikiPageVersionDocumentOwnerApproval::query()
                ->where('enterprise_wiki_page_version_id', $version->id)
                ->count(),
        );
    }

    public function test_owner_reassignment_sync_does_not_vacuously_complete_a_non_passed_run(): void
    {
        // Guards EnterpriseWikiDocumentFlowService::reconcileRunDocumentOwnerApprovalState():
        // a page version with only an unsupported claim produces zero pending approval groups
        // (EnterpriseWikiDocumentOwnerApprovalService never builds a requirement for it), so the
        // gate looks vacuously "ready" — the qa_status guard must stop this from completing the
        // run when technical QA never actually passed.
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $page = $this->createPendingPage($customer, 'reassignment-page');
        $version = $this->createCurrentVersion($page);
        $this->createClaim($page, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT);
        $run = $this->createRunAtQaStatus($customer, $page, $version, $document->id, EnterpriseWikiIngestRun::QA_STATUS_REPAIR_REQUIRED);
        $run->update(['status' => EnterpriseWikiIngestRun::STATUS_ESCALATED, 'finished_at' => now()]);

        app(EnterpriseWikiDocumentFlowService::class)->syncDocumentOwnerApprovals($document);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_ESCALATED, $run->status);
    }

    // =========================================================================
    // Owner-approval requirement suppression (Del 10, item 10 + Del 9 UI gating)
    // =========================================================================

    public function test_preview_requirements_empty_for_page_with_active_defect(): void
    {
        $customer = $this->createCustomer();
        $page = $this->createPendingPage($customer, 'preview-blocked-page');
        $version = $this->createCurrentVersion($page);
        $this->createClaim($page, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR, blockingOverride: true);

        $requirements = app(EnterpriseWikiDocumentOwnerApprovalService::class)->previewRequirementsForPageVersion($version);

        $this->assertTrue($requirements->isEmpty());
    }

    public function test_has_active_claim_integrity_defects_for_version_true_for_verified_unsupported_claim(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $page = $this->createPendingPage($customer, 'flagged-page');
        $version = $this->createCurrentVersion($page);
        $this->createVerifiedUnsupportedClaim($page, $version, $document);

        $hasDefects = app(EnterpriseWikiDocumentOwnerApprovalService::class)
            ->hasActiveClaimIntegrityDefectsForVersion($version);

        $this->assertTrue($hasDefects);
    }

    public function test_has_active_claim_integrity_defects_for_version_false_for_unverified_unsupported_claim(): void
    {
        // Same rule as the QA gate (findClaimIntegrityDefects()) — an unsupported_generated_content
        // claim that never actually reached a verdict is technical uncertainty, not a confirmed
        // defect, so it must not suppress the Document Owner approval requirement either.
        $customer = $this->createCustomer();
        $page = $this->createPendingPage($customer, 'unverified-page');
        $version = $this->createCurrentVersion($page);
        $this->createClaim($page, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT);

        $hasDefects = app(EnterpriseWikiDocumentOwnerApprovalService::class)
            ->hasActiveClaimIntegrityDefectsForVersion($version);

        $this->assertFalse($hasDefects);
    }

    public function test_qa_gate_and_document_owner_approval_agree_on_effective_blocking(): void
    {
        // The QA gate (EnterpriseWikiPostIngestQaService::findClaimIntegrityDefects()) and
        // Document Owner approval suppression (EnterpriseWikiDocumentOwnerApprovalService::
        // hasActiveClaimIntegrityDefects()) must never disagree about whether the same claim is
        // effectively blocking — both consult EnterpriseWikiClaimFindingExplainer the same way.
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $document = EnterpriseWikiDocument::query()->find($run->source_id);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $unverifiedVersion = $this->currentVersion($article);
        $this->createClaim($article, $unverifiedVersion, EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT);
        $this->markStepsComplete($run);

        $qaResult = $this->qaService()->runForRun($run);
        $docOwnerService = app(EnterpriseWikiDocumentOwnerApprovalService::class);

        $this->assertNotContains('active_unsupported_generated_content_claims', $qaResult['claim_integrity_defects']);
        $this->assertFalse($docOwnerService->hasActiveClaimIntegrityDefectsForVersion($unverifiedVersion));

        $verifiedRun = $this->createAppliedRun($customer, $document);
        $verifiedArticle = $this->createVersionedPage($customer, $verifiedRun, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Verified Article');
        $this->createVersionedPage($customer, $verifiedRun, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Verified Summary');
        $verifiedVersion = $this->currentVersion($verifiedArticle);
        $this->createVerifiedUnsupportedClaim($verifiedArticle, $verifiedVersion, $document);
        $this->markStepsComplete($verifiedRun);

        $verifiedQaResult = $this->qaService()->runForRun($verifiedRun);

        $this->assertContains('active_unsupported_generated_content_claims', $verifiedQaResult['claim_integrity_defects']);
        $this->assertTrue($docOwnerService->hasActiveClaimIntegrityDefectsForVersion($verifiedVersion));
    }

    public function test_qa_document_owner_and_ui_findings_panel_agree_on_effective_blocking(): void
    {
        // Extends the QA/Document-Owner consistency check above to the Funn panel
        // (EnterpriseWikiRunFindingsService) — the UI-facing 'blocks_run' must use the exact same
        // gate value as the QA/document-owner services, even though the UI must present it as
        // "requires decision", never as an already-decided block (CLAUDE.md).
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->createAppliedRun($customer, $document);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'UI Consistency Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'UI Consistency Summary');
        $version = $this->currentVersion($article);
        $this->createVerifiedUnsupportedClaim($article, $version, $document);
        $this->markStepsComplete($run);

        $qaResult = $this->qaService()->runForRun($run);
        $docOwnerBlocks = app(EnterpriseWikiDocumentOwnerApprovalService::class)->hasActiveClaimIntegrityDefectsForVersion($version);
        $findings = app(EnterpriseWikiRunFindingsService::class)->buildForRun($run, null, false);
        $claimFinding = collect($findings['findings'])->firstWhere('claim_id', '!=', null);

        $this->assertContains('active_unsupported_generated_content_claims', $qaResult['claim_integrity_defects']);
        $this->assertTrue($docOwnerBlocks);
        $this->assertNotNull($claimFinding);
        $this->assertTrue($claimFinding['blocks_run']);
        $this->assertSame('requires_decision', $claimFinding['status']);
        $this->assertSame('pending', $claimFinding['user_decision']);
    }

    public function test_has_active_claim_integrity_defects_for_version_false_for_clean_source_based_claim(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $page = $this->createPendingPage($customer, 'clean-page');
        $version = $this->createCurrentVersion($page);
        $claim = $this->createClaim($page, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED);
        $this->createSourceReference($claim, $document);

        $hasDefects = app(EnterpriseWikiDocumentOwnerApprovalService::class)
            ->hasActiveClaimIntegrityDefectsForVersion($version);

        $this->assertFalse($hasDefects);
    }

    // =========================================================================
    // Document Owner UI (Del 9 / Del 10 items 23, 32-33)
    // =========================================================================

    public function test_document_owner_sees_blocked_by_quality_message_not_approve_reject(): void
    {
        // A page can be a mix of good and bad claims (exactly the run-34 shape) — the Document
        // Owner legitimately tied to the page's good claim must still be able to reach the page
        // (to see the blocked message, not a 404) even though the page as a whole is blocked.
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer);
        $document = $this->createDocument($customer, $owner);
        $page = $this->createPendingPage($customer, 'blocked-ui-page');
        $version = $this->createCurrentVersion($page);
        $goodClaim = $this->createClaim($page, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED);
        $this->createSourceReference($goodClaim, $document);
        $this->createVerifiedUnsupportedClaim($page, $version, $document);

        $response = $this->actingAs($owner)->get('/app/wiki/'.$page->slug);
        $response->assertOk();

        $response->assertViewHas('page', function (array $inertia): bool {
            $props = data_get($inertia, 'props');

            return data_get($props, 'document_owner_approvals', []) === []
                && data_get($props, 'document_owner_summary.state') === 'blocked_by_quality';
        });
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function qaService(): EnterpriseWikiPostIngestQaService
    {
        return app(EnterpriseWikiPostIngestQaService::class);
    }

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

    private function createCustomer(string $name = 'Claim Integrity Test AS'): Customer
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

    private function createUser(Customer $customer): User
    {
        return User::query()->create([
            'name' => 'Owner '.Str::random(5),
            'email' => Str::lower(Str::random(8)).'@example.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function createDocument(Customer $customer, ?User $owner = null): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'owner_user_id' => $owner?->id,
            'original_filename' => 'source.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Source document text for claim integrity gating tests.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createAppliedRun(Customer $customer, ?EnterpriseWikiDocument $document = null): EnterpriseWikiIngestRun
    {
        $document ??= $this->createDocument($customer);

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

    private function createPendingPage(Customer $customer, string $slug): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => $slug.'-'.Str::lower(Str::random(6)),
            'title' => Str::headline($slug),
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_PENDING_REVIEW,
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

    private function createCurrentVersion(EnterpriseWikiPage $page): EnterpriseWikiPageVersion
    {
        return EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => '# '.e($page->title),
            'generated_by_model' => 'gpt-5',
        ]);
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
        ?bool $blockingOverride = null,
        ?string $contentBlockKey = null,
        ?array $reviewMetadata = null,
    ): EnterpriseWikiClaim {
        return EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'Test claim.',
            'content_origin' => $contentOrigin,
            'blocking_override' => $blockingOverride,
            'content_block_key' => $contentBlockKey,
            'review_metadata' => $reviewMetadata,
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'verified_at' => now(),
        ]);
    }

    /**
     * A source_based-anchored unsupported_generated_content claim: content_block_key set, a real
     * source reference linked, and review_metadata carrying an actual 'verdict' — i.e. one that
     * genuinely reached a semantic verdict, as opposed to createClaim()'s plain
     * unsupported_generated_content fixture (no block key, no metadata), which now represents a
     * claim that never reached a verdict at all (technical uncertainty, see
     * EnterpriseWikiClaimFindingExplainer).
     */
    private function createVerifiedUnsupportedClaim(
        EnterpriseWikiPage $page,
        EnterpriseWikiPageVersion $version,
        EnterpriseWikiDocument $document,
    ): EnterpriseWikiClaim {
        $claim = $this->createClaim($page, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT, contentBlockKey: 'block-0001', reviewMetadata: [
            'classification_basis' => 'semantic_verification',
            'verdict' => 'not_supported',
            'reason' => 'The source describes a different process than the one claimed.',
        ]);
        $this->createSourceReference($claim, $document);

        return $claim;
    }

    private function createSourceReference(EnterpriseWikiClaim $claim, EnterpriseWikiDocument $document): EnterpriseWikiSourceReference
    {
        return EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $claim->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_label' => $document->original_filename,
            'excerpt' => 'Utdrag for '.$document->original_filename,
        ]);
    }

    private function createRunAtQaStatus(
        Customer $customer,
        EnterpriseWikiPage $page,
        EnterpriseWikiPageVersion $version,
        int $sourceId,
        string $qaStatus,
    ): EnterpriseWikiIngestRun {
        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'enterprise_wiki_page_id' => $page->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $sourceId,
            'status' => EnterpriseWikiIngestRun::STATUS_QA,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
            'qa_status' => $qaStatus,
            'qa_started_at' => now()->subMinute(),
            'qa_completed_at' => now(),
            'qa_attempt_count' => 1,
            'qa_result' => ['claim_integrity_defects' => ['active_internal_error_claims']],
        ]);

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'generated_page_version_id' => $version->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
            'generation_started_at' => now()->subMinute(),
            'generation_completed_at' => now(),
        ]);

        return $run;
    }
}
