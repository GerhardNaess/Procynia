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
 * v0.10 (docs/enterprise-llm-wiki-plan.md, "Arkitekturnotat — v0.10"): claims and their QA review
 * are a voluntary, non-blocking quality loop, never a completion gate. This test was previously
 * "EnterpriseWikiClaimIntegrityGatingTest" and asserted the OPPOSITE — that specific claim states
 * set qa_status=repair_required and stopped a run from reaching Document Owner approval. It now
 * asserts the corrected behavior: a technically sound run always reaches qa_status=passed and the
 * ordinary Document Owner approval flow, regardless of open claim QA signals
 * (EnterpriseWikiPostIngestQaService::findOpenClaimQaSignals(),
 * EnterpriseWikiDocumentOwnerApprovalService::hasOpenClaimQaSignalsForVersion()) — only a genuine
 * technical defect (missing page version, critical lint) still fails/escalates a run.
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
    // QA never gates on claim QA signals (v0.10)
    // =========================================================================

    public function test_internal_error_claim_never_blocks_qa_passed(): void
    {
        // internal_error alone (no human override) is pure technical noise — it stays hidden even
        // from the informational claim_qa_signals, matching EnterpriseWikiClaimFindingExplainer::
        // isUserFacingAddition(). See the next test for the explicit-override case.
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $version = $this->currentVersion($article);
        $this->createClaim($article, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR);
        $this->markStepsComplete($run);

        $result = $this->qaService()->runForRun($run);

        $this->assertNotContains('open_internal_error_claims', $result['claim_qa_signals']);
        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
    }

    public function test_internal_error_claim_with_blocking_override_still_never_blocks_qa_passed(): void
    {
        // v0.10: a human's blocking_override no longer feeds the QA gate at all — claims never
        // block, regardless of any decision recorded on them. The claim's own decision remains
        // visible in the voluntary QA screen (see EnterpriseWikiRunFindingsService), it just never
        // stops the run from completing.
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $version = $this->currentVersion($article);
        $this->createClaim($article, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR, blockingOverride: true);
        $this->markStepsComplete($run);

        $result = $this->qaService()->runForRun($run);

        $this->assertContains('open_internal_error_claims', $result['claim_qa_signals']);
        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
    }

    public function test_unverified_unsupported_generated_content_claim_never_blocks_qa_passed(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $version = $this->currentVersion($article);
        $this->createClaim($article, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT);
        $this->markStepsComplete($run);

        $result = $this->qaService()->runForRun($run);

        $this->assertNotContains('open_unsupported_generated_content_claims', $result['claim_qa_signals']);
        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
    }

    public function test_verified_unsupported_generated_content_claim_never_blocks_qa_passed(): void
    {
        // A claim that DID reach a real semantic verdict (block key + source reference +
        // review_metadata with a verdict) is a genuine, confirmed content deviation and still
        // surfaces as an open claim QA signal — but per v0.10 that is a voluntary QA opportunity,
        // never a reason to keep the run from completing.
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $document = EnterpriseWikiDocument::query()->find($run->source_id);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $version = $this->currentVersion($article);
        $this->createVerifiedUnsupportedClaim($article, $version, $document);
        $this->markStepsComplete($run);

        $result = $this->qaService()->runForRun($run);

        $this->assertContains('open_unsupported_generated_content_claims', $result['claim_qa_signals']);
        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
    }

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
    public function test_dimension_mismatch_claim_never_blocks_qa_passed(string $deterministicReason): void
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

        $this->assertNotContains('open_unsupported_generated_content_claims', $result['claim_qa_signals']);
        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
    }

    public function test_source_based_claim_without_source_reference_never_blocks_qa_passed(): void
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

        $this->assertContains('source_based_claims_missing_provenance', $result['claim_qa_signals']);
        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
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

        $this->assertSame([], $result['claim_qa_signals']);
        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
    }

    public function test_pending_best_practice_claim_never_blocks_qa_passed(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $version = $this->currentVersion($article);
        $this->createClaim($article, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE);
        $this->markStepsComplete($run);

        $result = $this->qaService()->runForRun($run);

        $this->assertSame([], $result['claim_qa_signals']);
        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
    }

    public function test_claim_on_superseded_version_does_not_surface_as_open_signal(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->createAppliedRun($customer, $document);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        $oldVersion = $this->currentVersion($article);
        // A signal on a superseded (non-current) version must not count as active.
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

        $this->assertSame([], $result['claim_qa_signals']);
        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
    }

    public function test_genuine_technical_flow_failure_still_stops_the_run(): void
    {
        // v0.10 explicitly preserves this: a real technical flow failure (here, a missing current
        // page version for one of the run's pages) must still fail QA — unrelated to and unaffected
        // by claim QA signals, which never gate anything.
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

    // =========================================================================
    // Document Owner approval is never suppressed by claim QA signals (v0.10)
    // =========================================================================

    public function test_valid_run_reaches_awaiting_document_owner_approval(): void
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

    public function test_run_with_open_claim_qa_signal_still_reaches_document_owner_approval(): void
    {
        // v0.10: a source-linked claim's Document Owner approval requirement is built regardless
        // of an unrelated open claim QA signal on the same page — QA review and Document Owner
        // approval are orthogonal, and neither suppresses the other.
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer);
        $document = $this->createDocument($customer, $owner);
        $page = $this->createPendingPage($customer, 'mixed-page');
        $version = $this->createCurrentVersion($page);
        $goodClaim = $this->createClaim($page, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED);
        $this->createSourceReference($goodClaim, $document);
        $this->createVerifiedUnsupportedClaim($page, $version, $document);
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

    public function test_legacy_repair_required_run_now_proceeds_to_document_owner_approval(): void
    {
        // Backward compatibility (Del 7, v0.10): a historical run already recorded with the
        // now-retired qa_status=repair_required value is treated exactly like passed — it is not
        // rewritten, but finalizing it proceeds through the ordinary owner-approval gate instead
        // of the removed claim-content-repair escalation path.
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $owner = $this->createUser($customer);
        $document->update(['owner_user_id' => $owner->id]);
        $page = $this->createPendingPage($customer, 'legacy-repair-required-page');
        $version = $this->createCurrentVersion($page);
        $claim = $this->createClaim($page, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED);
        $this->createSourceReference($claim, $document);
        $run = $this->createRunAtQaStatus($customer, $page, $version, $document->id, EnterpriseWikiIngestRun::QA_STATUS_REPAIR_REQUIRED);

        app(EnterpriseWikiDocumentFlowService::class)->finalizeFromExistingQaResult($run);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL, $run->status);
        $this->assertNotSame(EnterpriseWikiIngestRun::STATUS_ESCALATED, $run->status);
    }

    public function test_owner_reassignment_sync_completes_a_legacy_repair_required_run_when_ready(): void
    {
        // Companion to the above via the syncDocumentOwnerApprovals() entrypoint: since v0.10 no
        // longer treats repair_required as a reason to withhold completion, a document-owner
        // reassignment sync on such a run can now legitimately complete it once the (real,
        // source-linked) approval requirement is satisfied. A genuine source-based claim is
        // required here so EnterpriseWikiDocumentOwnerApprovalService::syncForDocument() actually
        // discovers this page version for the document in the first place (it only looks at
        // versions with at least one real source reference to that document) — the additional
        // open claim QA signal on the same page must not stop that discovery or the completion.
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer);
        $document = $this->createDocument($customer, $owner);
        $page = $this->createPendingPage($customer, 'reassignment-page');
        $version = $this->createCurrentVersion($page);
        $goodClaim = $this->createClaim($page, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED);
        $this->createSourceReference($goodClaim, $document);
        $this->createClaim($page, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT);
        $run = $this->createRunAtQaStatus($customer, $page, $version, $document->id, EnterpriseWikiIngestRun::QA_STATUS_REPAIR_REQUIRED);
        $run->update(['status' => EnterpriseWikiIngestRun::STATUS_ESCALATED, 'finished_at' => now()]);

        app(EnterpriseWikiDocumentFlowService::class)->syncDocumentOwnerApprovals($document);

        // The good claim's approval requirement is auto-created but still pending an explicit
        // owner decision, so the run reaches "awaiting approval" rather than completing outright
        // — the point of this test is that it is no longer stuck at "escalated".
        $run->refresh();
        $this->assertNotSame(EnterpriseWikiIngestRun::STATUS_ESCALATED, $run->status);
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL, $run->status);
    }

    public function test_owner_reassignment_sync_does_not_complete_a_technically_failed_run(): void
    {
        // The guard in reconcileRunDocumentOwnerApprovalState() must still refuse a run whose own
        // technical QA genuinely failed or is still escalated for a non-claim reason.
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $page = $this->createPendingPage($customer, 'technical-failure-page');
        $version = $this->createCurrentVersion($page);
        $run = $this->createRunAtQaStatus($customer, $page, $version, $document->id, EnterpriseWikiIngestRun::QA_STATUS_FAILED);
        $run->update(['status' => EnterpriseWikiIngestRun::STATUS_FAILED, 'finished_at' => now()]);

        app(EnterpriseWikiDocumentFlowService::class)->syncDocumentOwnerApprovals($document);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_FAILED, $run->status);
    }

    // =========================================================================
    // hasOpenClaimQaSignalsForVersion() — informational only, never a gate (v0.10)
    // =========================================================================

    public function test_requirements_are_built_from_real_source_references_even_with_an_active_signal(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $page = $this->createPendingPage($customer, 'preview-page-with-signal');
        $version = $this->createCurrentVersion($page);
        $goodClaim = $this->createClaim($page, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED);
        $this->createSourceReference($goodClaim, $document);
        $this->createClaim($page, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR, blockingOverride: true);

        $requirements = app(EnterpriseWikiDocumentOwnerApprovalService::class)->previewRequirementsForPageVersion($version);

        $this->assertTrue($requirements->isNotEmpty());
    }

    public function test_has_open_claim_qa_signals_for_version_true_for_verified_unsupported_claim(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $page = $this->createPendingPage($customer, 'flagged-page');
        $version = $this->createCurrentVersion($page);
        $this->createVerifiedUnsupportedClaim($page, $version, $document);

        $hasSignals = app(EnterpriseWikiDocumentOwnerApprovalService::class)
            ->hasOpenClaimQaSignalsForVersion($version);

        $this->assertTrue($hasSignals);
    }

    public function test_has_open_claim_qa_signals_for_version_false_for_unverified_unsupported_claim(): void
    {
        $customer = $this->createCustomer();
        $page = $this->createPendingPage($customer, 'unverified-page');
        $version = $this->createCurrentVersion($page);
        $this->createClaim($page, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT);

        $hasSignals = app(EnterpriseWikiDocumentOwnerApprovalService::class)
            ->hasOpenClaimQaSignalsForVersion($version);

        $this->assertFalse($hasSignals);
    }

    public function test_has_open_claim_qa_signals_for_version_false_for_clean_source_based_claim(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $page = $this->createPendingPage($customer, 'clean-page');
        $version = $this->createCurrentVersion($page);
        $claim = $this->createClaim($page, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED);
        $this->createSourceReference($claim, $document);

        $hasSignals = app(EnterpriseWikiDocumentOwnerApprovalService::class)
            ->hasOpenClaimQaSignalsForVersion($version);

        $this->assertFalse($hasSignals);
    }

    // =========================================================================
    // Funn panel: claim findings never block, but stay informative (v0.10)
    // =========================================================================

    public function test_findings_panel_never_marks_a_claim_finding_as_blocking(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->createAppliedRun($customer, $document);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'UI Consistency Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'UI Consistency Summary');
        $version = $this->currentVersion($article);
        $this->createVerifiedUnsupportedClaim($article, $version, $document);
        $this->markStepsComplete($run);

        $qaResult = $this->qaService()->runForRun($run);
        $findings = app(EnterpriseWikiRunFindingsService::class)->buildForRun($run, null, false);
        $claimFinding = collect($findings['findings'])->firstWhere('claim_id', '!=', null);

        $this->assertContains('open_unsupported_generated_content_claims', $qaResult['claim_qa_signals']);
        $this->assertNotNull($claimFinding);
        $this->assertFalse($claimFinding['blocks_run']);
        $this->assertFalse($claimFinding['blocks_page']);
        $this->assertSame('open_for_qa_review', $claimFinding['status']);
        $this->assertSame('pending', $claimFinding['user_decision']);
        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
    }

    // =========================================================================
    // Document Owner UI (v0.10 non-blocking language)
    // =========================================================================

    public function test_document_owner_sees_qa_review_open_state_when_page_has_no_source_linked_claims(): void
    {
        // A page whose only claim carries an open claim QA signal but no real source reference at
        // all (an internal_error a human has explicitly flagged — never source-anchored by
        // construction) has nothing to attribute to a document owner, so no approval requirement
        // is built. The informational "open QA points" state is shown instead — never a message
        // implying the page is blocked or unavailable.
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer);
        $this->createDocument($customer, $owner);
        $page = $this->createPendingPage($customer, 'qa-review-open-ui-page');
        $version = $this->createCurrentVersion($page);
        $this->createClaim($page, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR, blockingOverride: true);

        $response = $this->actingAs($owner)->get('/app/wiki/'.$page->slug);
        $response->assertOk();

        $response->assertViewHas('page', function (array $inertia): bool {
            $props = data_get($inertia, 'props');

            return data_get($props, 'document_owner_summary.state') === 'qa_review_open';
        });
    }

    public function test_document_owner_sees_normal_approval_flow_despite_an_open_claim_qa_signal(): void
    {
        // v0.10: a real source-linked claim on the same page as an open claim QA signal still
        // produces the ordinary approve/reject flow — the signal never withholds it.
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer);
        $document = $this->createDocument($customer, $owner);
        $page = $this->createPendingPage($customer, 'mixed-ui-page');
        $version = $this->createCurrentVersion($page);
        $goodClaim = $this->createClaim($page, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED);
        $this->createSourceReference($goodClaim, $document);
        $this->createVerifiedUnsupportedClaim($page, $version, $document);

        $response = $this->actingAs($owner)->get('/app/wiki/'.$page->slug);
        $response->assertOk();

        $response->assertViewHas('page', function (array $inertia): bool {
            $props = data_get($inertia, 'props');

            return data_get($props, 'document_owner_summary.state') !== 'qa_review_open'
                && count(data_get($props, 'document_owner_approvals', [])) > 0;
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
     * unsupported_generated_content fixture (no block key, no metadata), which represents a claim
     * that never reached a verdict at all (technical uncertainty, see
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
            'qa_result' => ['claim_qa_signals' => ['open_internal_error_claims']],
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
