<?php

namespace Tests\Feature\App\Wiki;

use App\Data\Ai\AiCallContext;
use App\Jobs\EnterpriseWiki\FinalizeEnterpriseWikiPageGeneration;
use App\Jobs\EnterpriseWiki\GenerateEnterpriseWikiAppliedPage;
use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\EnterpriseWiki\EnterpriseWikiAppliedRunLintService;
use App\Services\EnterpriseWiki\EnterpriseWikiBuildPageLinksService;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentFlowService;
use App\Services\EnterpriseWiki\EnterpriseWikiExtractPageClaimsService;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionApplyService;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionService;
use App\Services\EnterpriseWiki\EnterpriseWikiPostIngestQaService;
use App\Services\EnterpriseWiki\EnterpriseWikiVerifyPageClaimsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class EnterpriseWikiDocumentFlowServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.enterprise_wiki.ai_enabled' => true]);
    }

    // =========================================================================
    // run(): maintainer decision -> apply -> dispatch phase 1 (article/summary) jobs only
    // =========================================================================

    public function test_run_executes_maintainer_decision_and_apply_then_dispatches_only_article_and_summary_jobs(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->flowService()->prepareRunForDocument($customer->id, $document->id)['run'];

        $pages = $this->attachAllPageTypes($run);

        $callOrder = [];
        $this->configureStage1Mocks($customer, $document, $callOrder);

        $this->flowService()->run($run->id);

        $this->assertSame(['maintainer_decision', 'apply'], $callOrder);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_GENERATING_PAGES, $run->status);
        $this->assertSame(EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED, $run->maintainer_decision_status);
        $this->assertNotNull($run->started_at);
        $this->assertNull($run->error_message);

        foreach (['article', 'summary'] as $type) {
            Queue::assertPushed(
                GenerateEnterpriseWikiAppliedPage::class,
                fn (GenerateEnterpriseWikiAppliedPage $job) => $job->runId === $run->id && $job->pageId === $pages[$type]->id,
            );
        }

        // Concept/entity must NOT be dispatched yet — only after phase 1 completes
        // (see FinalizeEnterpriseWikiPageGenerationTest for that transition).
        foreach (['concept', 'entity'] as $type) {
            Queue::assertNotPushed(
                GenerateEnterpriseWikiAppliedPage::class,
                fn (GenerateEnterpriseWikiAppliedPage $job) => $job->pageId === $pages[$type]->id,
            );
        }

        Queue::assertPushed(GenerateEnterpriseWikiAppliedPage::class, 2);
        Queue::assertPushed(FinalizeEnterpriseWikiPageGeneration::class, fn ($job) => $job->runId === $run->id);
    }

    /**
     * Best-effort ordering (see beginGeneratingPages()'s docblock): article dispatched before
     * summary so EnterpriseWikiGenerateAppliedPagesService::buildArticleSummaryContextForRun()
     * has the best chance of finding the article's finished content already written when the
     * summary job runs, letting the summary condense the actual article instead of independently
     * re-deriving from the raw source document.
     */
    public function test_article_page_job_is_dispatched_before_summary_page_job(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->flowService()->prepareRunForDocument($customer->id, $document->id)['run'];

        $pages = $this->attachAllPageTypes($run);

        $callOrder = [];
        $this->configureStage1Mocks($customer, $document, $callOrder);

        $this->flowService()->run($run->id);

        $pushedPageIds = Queue::pushed(GenerateEnterpriseWikiAppliedPage::class)
            ->map(fn (GenerateEnterpriseWikiAppliedPage $job) => $job->pageId)
            ->values()
            ->all();

        $this->assertSame(
            [$pages['article']->id, $pages['summary']->id],
            $pushedPageIds,
        );
    }

    public function test_run_dispatches_article_page_job(): void
    {
        $this->assertPageTypeDispatched(EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
    }

    public function test_run_dispatches_summary_page_job(): void
    {
        $this->assertPageTypeDispatched(EnterpriseWikiPage::PAGE_TYPE_SUMMARY);
    }

    public function test_run_does_not_dispatch_concept_or_entity_jobs_before_phase_1_completes(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->flowService()->prepareRunForDocument($customer->id, $document->id)['run'];

        $pages = $this->attachAllPageTypes($run);

        $callOrder = [];
        $this->configureStage1Mocks($customer, $document, $callOrder);

        $this->flowService()->run($run->id);

        foreach (['concept', 'entity'] as $type) {
            Queue::assertNotPushed(
                GenerateEnterpriseWikiAppliedPage::class,
                fn (GenerateEnterpriseWikiAppliedPage $job) => $job->pageId === $pages[$type]->id,
            );
        }
    }

    public function test_run_does_not_dispatch_a_job_for_an_article_summary_page_that_already_has_a_version(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->flowService()->prepareRunForDocument($customer->id, $document->id)['run'];

        $pages = $this->attachAllPageTypes($run);
        $existingVersion = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $pages['article']->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => '# Existing',
            'generated_by_model' => 'gpt-5',
        ]);
        $articlePivot = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->where('enterprise_wiki_page_id', $pages['article']->id)
            ->first();
        $articlePivot->update(['generated_page_version_id' => $existingVersion->id]);

        $callOrder = [];
        $this->configureStage1Mocks($customer, $document, $callOrder);

        $this->flowService()->run($run->id);

        Queue::assertNotPushed(
            GenerateEnterpriseWikiAppliedPage::class,
            fn (GenerateEnterpriseWikiAppliedPage $job) => $job->pageId === $pages['article']->id,
        );
        // Only the summary job remains for phase 1 (concept/entity aren't dispatched yet).
        Queue::assertPushed(GenerateEnterpriseWikiAppliedPage::class, 1);
    }

    #[DataProvider('stage1FailingStepProvider')]
    public function test_run_marks_run_failed_when_a_stage1_step_throws(string $failingStep, array $expectedCallOrder, string $expectedFailedPhase): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->flowService()->prepareRunForDocument($customer->id, $document->id)['run'];

        $callOrder = [];
        $this->configureStage1Mocks($customer, $document, $callOrder, $failingStep);

        try {
            $this->flowService()->run($run->id);
            $this->fail('Expected the flow to throw.');
        } catch (RuntimeException $e) {
            $this->assertSame(str_replace('_', ' ', $failingStep).' failed', $e->getMessage());
        }

        $this->assertSame($expectedCallOrder, $callOrder);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_FAILED, $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(str_replace('_', ' ', $failingStep).' failed', $run->error_message);
        $this->assertNotSame(EnterpriseWikiIngestRun::QA_STATUS_FAILED, $run->qa_status);
        // Wiki run-588: the exact phase the run was in when it failed, persisted separately
        // from the generic terminal 'status' above.
        $this->assertSame($expectedFailedPhase, $run->failed_phase);
    }

    public static function stage1FailingStepProvider(): array
    {
        return [
            'maintainer decision' => ['maintainer_decision', ['maintainer_decision'], EnterpriseWikiIngestRun::STATUS_MAINTAINER_DECISION],
            'apply' => ['apply', ['maintainer_decision', 'apply'], EnterpriseWikiIngestRun::STATUS_APPLYING],
        ];
    }

    public function test_run_skips_duplicate_dispatch_when_run_already_claimed(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->flowService()->prepareRunForDocument($customer->id, $document->id)['run'];
        $run->update(['status' => EnterpriseWikiIngestRun::STATUS_MAINTAINER_DECISION]);

        $this->mock(EnterpriseWikiMaintainerDecisionService::class)->shouldNotReceive('runForDocument');

        $this->flowService()->run($run->id);

        Queue::assertNothingPushed();
    }

    public function test_continue_after_maintainer_batches_is_a_no_op_when_run_is_waiting_on_document_owner_approval(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->createIngestRunWithStatus($customer, $document, EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL);
        $run->update([
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
        ]);

        $this->mock(EnterpriseWikiMaintainerDecisionApplyService::class)->shouldNotReceive('apply');

        $this->flowService()->continueAfterMaintainerDecisionBatches($run->id);

        $this->assertSame(EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL, $run->fresh()->status);
        Queue::assertNothingPushed();
    }

    // =========================================================================
    // continueAfterPagesGenerated(): materialize -> extract -> verify -> lint -> qa
    //
    // The old combinatorial EnterpriseWikiBuildPageLinksService::build() step is
    // deliberately gone from this flow — canonical relations now come exclusively
    // from performMaterializeWikilinks(). See configureStage2Mocks(), which asserts
    // build() is never called regardless of which step fails.
    // =========================================================================

    public function test_continue_after_pages_generated_executes_steps_in_order_and_completes(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->createRunAwaitingContinuation($customer, $document);

        $callOrder = [];
        $this->configureStage2Mocks($callOrder);

        $this->flowService()->continueAfterPagesGenerated($run->id);

        $this->assertSame(['materialize', 'extract', 'verify', 'lint', 'qa'], $callOrder);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
        $this->assertNotNull($run->finished_at);
        $this->assertNull($run->error_message);
    }

    public function test_continue_after_pages_generated_never_calls_combinatorial_build(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->createRunAwaitingContinuation($customer, $document);

        $callOrder = [];
        // configureStage2Mocks() always asserts shouldNotReceive('build') on the
        // EnterpriseWikiBuildPageLinksService mock — a passing run here proves it.
        $this->configureStage2Mocks($callOrder);

        $this->flowService()->continueAfterPagesGenerated($run->id);

        $this->assertNotContains('build', $callOrder);
    }

    #[DataProvider('stage2FailingStepProvider')]
    public function test_continue_after_pages_generated_marks_run_failed_when_a_step_throws(string $failingStep, array $expectedCallOrder, bool $qaContext, string $expectedFailedPhase): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->createRunAwaitingContinuation($customer, $document);

        $callOrder = [];
        $this->configureStage2Mocks($callOrder, $failingStep);

        try {
            $this->flowService()->continueAfterPagesGenerated($run->id);
            $this->fail('Expected the flow to throw.');
        } catch (RuntimeException $e) {
            $this->assertSame(str_replace('_', ' ', $failingStep).' failed', $e->getMessage());
        }

        $this->assertSame($expectedCallOrder, $callOrder);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_FAILED, $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(str_replace('_', ' ', $failingStep).' failed', $run->error_message);
        // Wiki run-588: materialize/extract/verify/lint all fail while still nominally in the
        // verification_linking phase (currentStage only advances to STATUS_QA right before the
        // performPostIngestQa() call) — only the 'qa' failure is attributed to the qa phase.
        $this->assertSame($expectedFailedPhase, $run->failed_phase);

        if ($qaContext) {
            $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_FAILED, $run->qa_status);
            $this->assertSame("{$failingStep} failed", $run->qa_last_error);
            $this->assertNotNull($run->qa_completed_at);
        } else {
            $this->assertNotSame(EnterpriseWikiIngestRun::QA_STATUS_FAILED, $run->qa_status);
        }
    }

    public static function stage2FailingStepProvider(): array
    {
        return [
            'materialize wikilinks' => ['materialize', ['materialize'], false, EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING],
            'extract claims' => ['extract', ['materialize', 'extract'], false, EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING],
            'verify claims' => ['verify', ['materialize', 'extract', 'verify'], false, EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING],
            'lint' => ['lint', ['materialize', 'extract', 'verify', 'lint'], false, EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING],
            'qa' => ['qa', ['materialize', 'extract', 'verify', 'lint', 'qa'], true, EnterpriseWikiIngestRun::STATUS_QA],
        ];
    }

    public function test_continue_after_pages_generated_is_a_no_op_when_run_is_already_terminal(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->createRunAwaitingContinuation($customer, $document);
        $run->update(['status' => EnterpriseWikiIngestRun::STATUS_COMPLETED, 'finished_at' => now()]);

        $this->mock(EnterpriseWikiExtractPageClaimsService::class)->shouldNotReceive('extract');

        $this->flowService()->continueAfterPagesGenerated($run->id);

        $this->assertSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $run->fresh()->status);
    }

    // =========================================================================
    // cancelRun()
    // =========================================================================

    public function test_cancel_run_sets_cancelled_status_and_releases_leases_without_touching_content(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->createIngestRunWithStatus($customer, $document, EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL);
        $actor = $this->createActor($customer);

        $page = $this->createPage($customer->id, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Artikkel');
        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => '# Artikkel',
        ]);
        $runPage = EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            'generated_page_version_id' => $version->id,
            'claims_claimed_at' => now(),
            'claims_claim_token' => 'lease-token',
        ]);
        $claim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'Vi leverer strukturert innhold.',
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'position_order' => 0,
            'verification_claimed_at' => now(),
            'verification_claim_token' => 'claim-lease-token',
        ]);

        $cancelled = $this->flowService()->cancelRun($run, $actor, 'Testavbrudd');

        $this->assertSame(EnterpriseWikiIngestRun::STATUS_CANCELLED, $cancelled->status);
        $this->assertTrue($cancelled->isTerminal());
        $this->assertNotNull($cancelled->finished_at);
        $this->assertStringContainsString('Testavbrudd', (string) $cancelled->error_message);

        $runPage->refresh();
        $this->assertNull($runPage->claims_claimed_at);
        $this->assertNull($runPage->claims_claim_token);

        $claim->refresh();
        $this->assertNull($claim->verification_claimed_at);
        $this->assertNull($claim->verification_claim_token);
        $this->assertSame('Vi leverer strukturert innhold.', $claim->claim_text);
        $this->assertSame(EnterpriseWikiClaim::APPROVAL_STATUS_PENDING, $claim->approval_status);
    }

    public function test_cancel_run_is_a_no_op_when_run_is_already_terminal(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->createIngestRunWithStatus($customer, $document, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        $run->update(['finished_at' => now(), 'error_message' => null]);
        $actor = $this->createActor($customer);

        $result = $this->flowService()->cancelRun($run, $actor);

        $this->assertSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $result->status);
    }

    public function test_run_is_terminal_and_excluded_from_non_terminal_statuses_once_cancelled(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->createIngestRunWithStatus($customer, $document, EnterpriseWikiIngestRun::STATUS_CANCELLED);

        $this->assertTrue($run->isTerminal());
        $this->assertNotContains(EnterpriseWikiIngestRun::STATUS_CANCELLED, EnterpriseWikiIngestRun::NON_TERMINAL_STATUSES);
    }

    // =========================================================================
    // isCancellable() — the single central rule for whether the ordinary "Avbryt kjøring"
    // action applies (deliberately narrower than !isTerminal()).
    // =========================================================================

    public function test_is_cancellable_true_for_every_genuinely_active_status(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);

        foreach (EnterpriseWikiIngestRun::CANCELLABLE_STATUSES as $status) {
            $run = $this->createIngestRunWithStatus($customer, $document, $status);
            $this->assertTrue($run->isCancellable(), "Expected status [{$status}] to be cancellable.");
        }
    }

    public function test_is_cancellable_false_for_awaiting_document_owner_approval(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->createIngestRunWithStatus($customer, $document, EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL);

        $this->assertFalse($run->isCancellable());
    }

    public function test_is_cancellable_false_for_decision_only(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->createIngestRunWithStatus($customer, $document, EnterpriseWikiIngestRun::STATUS_DECISION_ONLY);

        $this->assertFalse($run->isCancellable());
    }

    public function test_is_cancellable_false_for_every_terminal_status(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);

        foreach (EnterpriseWikiIngestRun::TERMINAL_STATUSES as $status) {
            $run = $this->createIngestRunWithStatus($customer, $document, $status);
            $this->assertFalse($run->isCancellable(), "Expected terminal status [{$status}] to not be cancellable.");
        }
    }

    // =========================================================================
    // expectsAutomaticProgress() — the central rule for the "Ser ut til å stå stille" warning.
    // A separate concept from isCancellable() even though membership currently matches — see
    // EXPECTS_AUTOMATIC_PROGRESS_STATUSES's own docblock.
    // =========================================================================

    public function test_expects_automatic_progress_true_for_every_genuinely_active_status(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);

        foreach (EnterpriseWikiIngestRun::EXPECTS_AUTOMATIC_PROGRESS_STATUSES as $status) {
            $run = $this->createIngestRunWithStatus($customer, $document, $status);
            $this->assertTrue($run->expectsAutomaticProgress(), "Expected status [{$status}] to expect automatic progress.");
        }
    }

    public function test_expects_automatic_progress_false_for_awaiting_document_owner_approval(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->createIngestRunWithStatus($customer, $document, EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL);

        $this->assertFalse($run->expectsAutomaticProgress());
    }

    public function test_expects_automatic_progress_false_for_decision_only(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->createIngestRunWithStatus($customer, $document, EnterpriseWikiIngestRun::STATUS_DECISION_ONLY);

        $this->assertFalse($run->expectsAutomaticProgress());
    }

    public function test_expects_automatic_progress_false_for_every_terminal_status(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);

        foreach (EnterpriseWikiIngestRun::TERMINAL_STATUSES as $status) {
            $run = $this->createIngestRunWithStatus($customer, $document, $status);
            $this->assertFalse($run->expectsAutomaticProgress(), "Expected terminal status [{$status}] to not expect automatic progress.");
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function assertPageTypeDispatched(string $pageType): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->flowService()->prepareRunForDocument($customer->id, $document->id)['run'];

        $pages = $this->attachAllPageTypes($run);

        $callOrder = [];
        $this->configureStage1Mocks($customer, $document, $callOrder);

        $this->flowService()->run($run->id);

        $page = $pages[$pageType];

        Queue::assertPushed(
            GenerateEnterpriseWikiAppliedPage::class,
            fn (GenerateEnterpriseWikiAppliedPage $job) => $job->runId === $run->id && $job->pageId === $page->id,
        );
    }

    /**
     * @return array<string, EnterpriseWikiPage> keyed by page_type
     */
    private function attachAllPageTypes(EnterpriseWikiIngestRun $run): array
    {
        $pages = [
            EnterpriseWikiPage::PAGE_TYPE_ARTICLE => $this->createPage($run->customer_id, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Artikkel'),
            EnterpriseWikiPage::PAGE_TYPE_SUMMARY => $this->createPage($run->customer_id, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Sammendrag'),
            EnterpriseWikiPage::PAGE_TYPE_CONCEPT => $this->createPage($run->customer_id, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Konsept'),
            EnterpriseWikiPage::PAGE_TYPE_ENTITY => $this->createPage($run->customer_id, EnterpriseWikiPage::PAGE_TYPE_ENTITY, 'Entitet'),
        ];

        foreach ($pages as $page) {
            EnterpriseWikiIngestRunPage::query()->create([
                'enterprise_wiki_ingest_run_id' => $run->id,
                'enterprise_wiki_page_id' => $page->id,
                'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            ]);
        }

        return $pages;
    }

    private function createPage(int $customerId, string $pageType, string $title): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customerId,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'title' => $title,
            'page_type' => $pageType,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }

    private function createIngestRunWithStatus(Customer $customer, EnterpriseWikiDocument $document, string $status): EnterpriseWikiIngestRun
    {
        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => $status,
            'started_at' => now(),
        ]);
    }

    private function createActor(Customer $customer): User
    {
        return User::query()->create([
            'name' => 'Test Actor',
            'email' => Str::lower(Str::random(8)).'@test.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function createRunAwaitingContinuation(Customer $customer, EnterpriseWikiDocument $document): EnterpriseWikiIngestRun
    {
        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_GENERATING_PAGES,
            'maintainer_decision_json' => $this->baseDecision(),
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
            'started_at' => now(),
        ]);
    }

    private function configureStage1Mocks(
        Customer $customer,
        EnterpriseWikiDocument $document,
        array &$callOrder,
        ?string $failingStep = null,
    ): void {
        $decision = $this->baseDecision();
        $stages = ['maintainer_decision', 'apply'];
        $failingStageIndex = $failingStep !== null ? array_search($failingStep, $stages, true) : false;
        $shouldExpect = function (string $stage) use ($failingStageIndex, $stages): bool {
            if ($failingStageIndex === false) {
                return true;
            }

            return array_search($stage, $stages, true) <= $failingStageIndex;
        };
        $shouldFail = fn (string $stage): bool => $failingStep === $stage;

        if ($shouldExpect('maintainer_decision')) {
            $this->mock(EnterpriseWikiMaintainerDecisionService::class)
                ->shouldReceive('runForDocument')
                ->once()
                ->ordered('enterprise-wiki-document-flow')
                ->with($customer->id, $document->id, 'no', Mockery::type(AiCallContext::class))
                ->andReturnUsing(function () use (&$callOrder, $decision, $shouldFail) {
                    $callOrder[] = 'maintainer_decision';

                    if ($shouldFail('maintainer_decision')) {
                        throw new RuntimeException('maintainer decision failed');
                    }

                    return $decision;
                });
        } else {
            $this->mock(EnterpriseWikiMaintainerDecisionService::class)->shouldNotReceive('runForDocument');
        }

        if ($shouldExpect('apply')) {
            $this->mock(EnterpriseWikiMaintainerDecisionApplyService::class)
                ->shouldReceive('apply')
                ->once()
                ->ordered('enterprise-wiki-document-flow')
                ->andReturnUsing(function (EnterpriseWikiIngestRun $run) use (&$callOrder, $shouldFail) {
                    $callOrder[] = 'apply';
                    $this->assertSame(EnterpriseWikiIngestRun::STATUS_APPLYING, $run->status);
                    $this->assertSame(EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_PENDING, $run->maintainer_decision_status);

                    if ($shouldFail('apply')) {
                        throw new RuntimeException('apply failed');
                    }

                    $run->update(['maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED]);

                    return ['created' => 4, 'updated' => 0];
                });
        } else {
            $this->mock(EnterpriseWikiMaintainerDecisionApplyService::class)->shouldNotReceive('apply');
        }
    }

    private function configureStage2Mocks(array &$callOrder, ?string $failingStep = null): void
    {
        $stages = ['materialize', 'extract', 'verify', 'lint', 'qa'];
        $failingStageIndex = $failingStep !== null ? array_search($failingStep, $stages, true) : false;
        $shouldExpect = function (string $stage) use ($failingStageIndex, $stages): bool {
            if ($failingStageIndex === false) {
                return true;
            }

            return array_search($stage, $stages, true) <= $failingStageIndex;
        };
        $shouldFail = fn (string $stage): bool => $failingStep === $stage;

        $buildPageLinksMock = $this->mock(EnterpriseWikiBuildPageLinksService::class);
        // The old combinatorial build() step is gone from this flow entirely — assert
        // it regardless of which stage fails (see the correction note in the class
        // header). materializeWikilinksForRun() is the only method this flow calls.
        $buildPageLinksMock->shouldNotReceive('build');

        if ($shouldExpect('materialize')) {
            $buildPageLinksMock
                ->shouldReceive('materializeWikilinksForRun')
                ->once()
                ->ordered('enterprise-wiki-continue-flow')
                ->andReturnUsing(function (EnterpriseWikiIngestRun $run) use (&$callOrder, $shouldFail) {
                    $callOrder[] = 'materialize';
                    $this->assertSame(EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING, $run->status);

                    if ($shouldFail('materialize')) {
                        throw new RuntimeException('materialize failed');
                    }

                    return [
                        'pages_processed' => 4, 'occurrences_found' => 2, 'valid_links' => 2,
                        'broken_slugs' => 0, 'self_links' => 0, 'created' => 2, 'updated' => 0,
                        'stale_links_removed' => 0,
                    ];
                });
        } else {
            $buildPageLinksMock->shouldNotReceive('materializeWikilinksForRun');
        }

        if ($shouldExpect('extract')) {
            $this->mock(EnterpriseWikiExtractPageClaimsService::class)
                ->shouldReceive('extract')
                ->once()
                ->ordered('enterprise-wiki-continue-flow')
                ->andReturnUsing(function (EnterpriseWikiIngestRun $run) use (&$callOrder, $shouldFail) {
                    $callOrder[] = 'extract';
                    $this->assertSame(EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING, $run->status);

                    if ($shouldFail('extract')) {
                        throw new RuntimeException('extract failed');
                    }

                    return ['pages' => 4, 'claims' => 4, 'skipped' => 0];
                });
        } else {
            $this->mock(EnterpriseWikiExtractPageClaimsService::class)->shouldNotReceive('extract');
        }

        if ($shouldExpect('verify')) {
            $this->mock(EnterpriseWikiVerifyPageClaimsService::class)
                ->shouldReceive('verify')
                ->once()
                ->ordered('enterprise-wiki-continue-flow')
                ->andReturnUsing(function (EnterpriseWikiIngestRun $run) use (&$callOrder, $shouldFail) {
                    $callOrder[] = 'verify';

                    if ($shouldFail('verify')) {
                        throw new RuntimeException('verify failed');
                    }

                    return ['pages' => 4, 'claims' => 4, 'references' => 4, 'skipped' => 0, 'no_support' => 0];
                });
        } else {
            $this->mock(EnterpriseWikiVerifyPageClaimsService::class)->shouldNotReceive('verify');
        }

        if ($shouldExpect('lint')) {
            $this->mock(EnterpriseWikiAppliedRunLintService::class)
                ->shouldReceive('lint')
                ->once()
                ->ordered('enterprise-wiki-continue-flow')
                ->andReturnUsing(function (EnterpriseWikiIngestRun $run) use (&$callOrder, $shouldFail) {
                    $callOrder[] = 'lint';

                    if ($shouldFail('lint')) {
                        throw new RuntimeException('lint failed');
                    }

                    return [
                        'pages_checked' => 4, 'claims_checked' => 4, 'source_refs_checked' => 4,
                        'links_checked' => 4, 'findings_created' => 0, 'findings_skipped' => 0,
                        'findings_resolved' => 0, 'errors' => 0, 'warnings' => 0, 'info' => 0,
                    ];
                });
        } else {
            $this->mock(EnterpriseWikiAppliedRunLintService::class)->shouldNotReceive('lint');
        }

        if ($shouldExpect('qa')) {
            $this->mock(EnterpriseWikiPostIngestQaService::class)
                ->shouldReceive('runForRun')
                ->once()
                ->ordered('enterprise-wiki-continue-flow')
                ->andReturnUsing(function (EnterpriseWikiIngestRun $run) use (&$callOrder, $shouldFail) {
                    $callOrder[] = 'qa';
                    $this->assertSame(EnterpriseWikiIngestRun::STATUS_QA, $run->status);

                    if ($shouldFail('qa')) {
                        throw new RuntimeException('qa failed');
                    }

                    $run->update([
                        'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_PASSED,
                        'qa_completed_at' => now(),
                        'qa_last_error' => null,
                        'qa_result' => ['pass' => true, 'quality_score' => 1.0],
                    ]);

                    return ['pass' => true, 'quality_score' => 1.0];
                });
        } else {
            $this->mock(EnterpriseWikiPostIngestQaService::class)->shouldNotReceive('runForRun');
        }
    }

    private function flowService(): EnterpriseWikiDocumentFlowService
    {
        return app(EnterpriseWikiDocumentFlowService::class);
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
            'original_filename' => 'enterprise-wiki-source.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => "## Kompetanse\nVi leverer kontrollert Enterprise Wiki-innhold.\n\n## Kvalitet\nVi bevarer sporbarhet og struktur.",
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function baseDecision(): array
    {
        return [
            'source_article' => [
                'action' => 'create',
                'title' => 'Enterprise Wiki-artikkel',
                'proposed_slug' => 'enterprise-wiki-artikkel-ab1c2d',
                'reason' => 'Source article.',
            ],
            'source_summary' => [
                'action' => 'create',
                'title' => 'Sammendrag: Enterprise Wiki',
                'proposed_slug' => 'sammendrag-enterprise-wiki-ab1c2d',
                'reason' => 'Summary page.',
            ],
            'concept_pages' => [
                ['action' => 'create', 'title' => 'Konsept', 'proposed_slug' => 'konsept-ab1c2d', 'reason' => 'Key concept.'],
            ],
            'entity_pages' => [
                ['action' => 'create', 'title' => 'Entitet', 'proposed_slug' => 'entitet-ab1c2d', 'reason' => 'Key entity.'],
            ],
            'no_action_reason' => null,
            'warnings' => [],
        ];
    }
}
