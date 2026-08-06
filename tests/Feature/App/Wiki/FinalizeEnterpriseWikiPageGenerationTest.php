<?php

namespace Tests\Feature\App\Wiki;

use App\Jobs\EnterpriseWiki\ContinueEnterpriseWikiDocumentFlowAfterPages;
use App\Jobs\EnterpriseWiki\FinalizeEnterpriseWikiPageGeneration;
use App\Jobs\EnterpriseWiki\GenerateEnterpriseWikiAppliedPage;
use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\Language;
use App\Models\Nationality;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinalizeEnterpriseWikiPageGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_name_is_enterprise_wiki(): void
    {
        $job = new FinalizeEnterpriseWikiPageGeneration(1);

        $this->assertSame('enterprise-wiki', $job->queue);
    }

    public function test_late_finalizer_cannot_reactivate_or_dispatch_for_cancelled_run(): void
    {
        Queue::fake();
        $customer = $this->createCustomer();
        [$run] = $this->createRun($customer, EnterpriseWikiIngestRun::STATUS_CANCELLED, [
            'article' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
            'summary' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
        ]);

        (new FinalizeEnterpriseWikiPageGeneration($run->id))->handle();

        $this->assertSame(EnterpriseWikiIngestRun::STATUS_CANCELLED, $run->fresh()->status);
        Queue::assertNothingPushed();
    }

    // =========================================================================
    // Phase 1: initial page-generation wave
    // =========================================================================

    public function test_phase_1_does_not_advance_while_article_or_summary_is_pending(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        [$run] = $this->createRun($customer, EnterpriseWikiIngestRun::STATUS_GENERATING_PAGES, [
            'article' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
            'summary' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_PENDING,
        ]);

        (new FinalizeEnterpriseWikiPageGeneration($run->id))->handle();

        Queue::assertNotPushed(GenerateEnterpriseWikiAppliedPage::class);
        Queue::assertNotPushed(ContinueEnterpriseWikiDocumentFlowAfterPages::class);
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_GENERATING_PAGES, $run->fresh()->status);
    }

    public function test_phase_1_does_not_advance_while_independent_concept_is_pending(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        [$run] = $this->createRun($customer, EnterpriseWikiIngestRun::STATUS_GENERATING_PAGES, [
            'article' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
            'summary' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
            'concept' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_PENDING,
        ], independentConcept: true);

        (new FinalizeEnterpriseWikiPageGeneration($run->id))->handle();

        Queue::assertNotPushed(GenerateEnterpriseWikiAppliedPage::class);
        Queue::assertNotPushed(ContinueEnterpriseWikiDocumentFlowAfterPages::class);
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_GENERATING_PAGES, $run->fresh()->status);
    }

    public function test_phase_1_completion_dispatches_deferred_concept_and_entity_jobs_not_continuation(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        [$run, $pages] = $this->createRun($customer, EnterpriseWikiIngestRun::STATUS_GENERATING_PAGES, [
            'article' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
            'summary' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
            'concept' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_PENDING,
            'entity' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_PENDING,
        ]);

        (new FinalizeEnterpriseWikiPageGeneration($run->id))->handle();

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_GENERATING_CONCEPT_ENTITY_PAGES, $run->status);

        Queue::assertPushed(
            GenerateEnterpriseWikiAppliedPage::class,
            fn ($job) => $job->runId === $run->id && $job->pageId === $pages['concept']->id,
        );
        Queue::assertPushed(
            GenerateEnterpriseWikiAppliedPage::class,
            fn ($job) => $job->runId === $run->id && $job->pageId === $pages['entity']->id,
        );
        Queue::assertPushed(GenerateEnterpriseWikiAppliedPage::class, 2);

        // The safety net for the "zero/already-done deferred concept/entity pages" case.
        Queue::assertPushed(FinalizeEnterpriseWikiPageGeneration::class, fn ($job) => $job->runId === $run->id);

        // Claim extraction (via the continuation) must NOT start yet — phase 2 hasn't run.
        Queue::assertNotPushed(ContinueEnterpriseWikiDocumentFlowAfterPages::class);
    }

    public function test_phase_1_failure_marks_run_failed_and_does_not_dispatch_concept_or_entity(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        [$run] = $this->createRun($customer, EnterpriseWikiIngestRun::STATUS_GENERATING_PAGES, [
            'article' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
            'summary' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_FAILED,
            'concept' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_PENDING,
            'entity' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_PENDING,
        ]);

        (new FinalizeEnterpriseWikiPageGeneration($run->id))->handle();

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_FAILED, $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertStringContainsString('1 of 2 initial page(s) failed', $run->error_message);

        Queue::assertNotPushed(GenerateEnterpriseWikiAppliedPage::class);
        Queue::assertNotPushed(ContinueEnterpriseWikiDocumentFlowAfterPages::class);
    }

    public function test_phase_1_dispatches_concept_and_entity_jobs_only_once_even_if_finalize_runs_twice(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        [$run] = $this->createRun($customer, EnterpriseWikiIngestRun::STATUS_GENERATING_PAGES, [
            'article' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
            'summary' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
            'concept' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_PENDING,
            'entity' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_PENDING,
        ]);

        // Simulate two article/summary jobs finishing near-simultaneously, both dispatching finalize.
        (new FinalizeEnterpriseWikiPageGeneration($run->id))->handle();
        (new FinalizeEnterpriseWikiPageGeneration($run->id))->handle();

        // Second invocation sees status already advanced to generating_concept_entity_pages
        // and treats phase 1 as re-entered — but concept/entity pivots are no longer pending()
        // for phase 1's own type filter, so it can only dispatch each page once: verify no
        // page id is dispatched twice.
        Queue::assertPushed(GenerateEnterpriseWikiAppliedPage::class, 2);
    }

    public function test_phase_1_completion_continues_directly_when_no_deferred_pages_remain(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        [$run] = $this->createRun($customer, EnterpriseWikiIngestRun::STATUS_GENERATING_PAGES, [
            'article' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
            'summary' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
            'concept' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
        ], independentConcept: true);

        (new FinalizeEnterpriseWikiPageGeneration($run->id))->handle();

        Queue::assertNotPushed(GenerateEnterpriseWikiAppliedPage::class);
        Queue::assertPushed(ContinueEnterpriseWikiDocumentFlowAfterPages::class, fn ($job) => $job->runId === $run->id);
        Queue::assertPushed(ContinueEnterpriseWikiDocumentFlowAfterPages::class, 1);
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING, $run->fresh()->status);
    }

    public function test_phase_1_direct_continuation_dispatched_only_once_even_if_finalize_runs_twice(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        [$run] = $this->createRun($customer, EnterpriseWikiIngestRun::STATUS_GENERATING_PAGES, [
            'article' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
            'summary' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
            'concept' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
        ], independentConcept: true);

        (new FinalizeEnterpriseWikiPageGeneration($run->id))->handle();
        (new FinalizeEnterpriseWikiPageGeneration($run->id))->handle();

        Queue::assertPushed(ContinueEnterpriseWikiDocumentFlowAfterPages::class, 1);
    }

    // =========================================================================
    // Phase 2: concept/entity
    // =========================================================================

    public function test_phase_2_does_not_continue_while_concept_or_entity_is_pending(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        [$run] = $this->createRun($customer, EnterpriseWikiIngestRun::STATUS_GENERATING_CONCEPT_ENTITY_PAGES, [
            'article' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
            'summary' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
            'concept' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
            'entity' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_PENDING,
        ]);

        (new FinalizeEnterpriseWikiPageGeneration($run->id))->handle();

        Queue::assertNotPushed(ContinueEnterpriseWikiDocumentFlowAfterPages::class);
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_GENERATING_CONCEPT_ENTITY_PAGES, $run->fresh()->status);
    }

    public function test_phase_2_completion_dispatches_continuation_exactly_once(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        [$run] = $this->createRun($customer, EnterpriseWikiIngestRun::STATUS_GENERATING_CONCEPT_ENTITY_PAGES, [
            'article' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
            'summary' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
            'concept' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
            'entity' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
        ]);

        (new FinalizeEnterpriseWikiPageGeneration($run->id))->handle();

        Queue::assertPushed(ContinueEnterpriseWikiDocumentFlowAfterPages::class, fn ($job) => $job->runId === $run->id);
        Queue::assertPushed(ContinueEnterpriseWikiDocumentFlowAfterPages::class, 1);
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING, $run->fresh()->status);
    }

    public function test_phase_2_failure_marks_run_failed_and_does_not_dispatch_continuation(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        [$run] = $this->createRun($customer, EnterpriseWikiIngestRun::STATUS_GENERATING_CONCEPT_ENTITY_PAGES, [
            'article' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
            'summary' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
            'concept' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
            'entity' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_FAILED,
        ]);

        (new FinalizeEnterpriseWikiPageGeneration($run->id))->handle();

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_FAILED, $run->status);
        $this->assertStringContainsString('1 of 2 concept/entity page(s) failed', $run->error_message);

        Queue::assertNotPushed(ContinueEnterpriseWikiDocumentFlowAfterPages::class);
    }

    public function test_phase_2_continuation_dispatched_only_once_even_if_finalize_runs_twice(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        [$run] = $this->createRun($customer, EnterpriseWikiIngestRun::STATUS_GENERATING_CONCEPT_ENTITY_PAGES, [
            'article' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
            'summary' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
            'concept' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
            'entity' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
        ]);

        // Simulate two concept/entity jobs finishing near-simultaneously, both dispatching finalize.
        (new FinalizeEnterpriseWikiPageGeneration($run->id))->handle();
        (new FinalizeEnterpriseWikiPageGeneration($run->id))->handle();

        Queue::assertPushed(ContinueEnterpriseWikiDocumentFlowAfterPages::class, 1);
    }

    // =========================================================================
    // End-to-end: claim extraction only starts after BOTH phases finish
    // =========================================================================

    public function test_continuation_only_dispatched_after_both_phases_complete(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        [$run] = $this->createRun($customer, EnterpriseWikiIngestRun::STATUS_GENERATING_PAGES, [
            'article' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
            'summary' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
            'concept' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_PENDING,
            'entity' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_PENDING,
        ]);

        // Phase 1 finishes: dispatches concept/entity, run is NOT yet ready to continue.
        (new FinalizeEnterpriseWikiPageGeneration($run->id))->handle();
        Queue::assertNotPushed(ContinueEnterpriseWikiDocumentFlowAfterPages::class);

        // Simulate the dispatched concept/entity page jobs completing.
        EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->whereHas('page', fn ($q) => $q->whereIn('page_type', ['concept', 'entity']))
            ->update(['generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED]);

        // Phase 2 finishes: only now may the continuation (claim extraction) start.
        (new FinalizeEnterpriseWikiPageGeneration($run->id))->handle();
        Queue::assertPushed(ContinueEnterpriseWikiDocumentFlowAfterPages::class, 1);
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
            'extracted_text' => 'This is the extracted text from the source document.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    /**
     * @param  array<string, string>  $pagesByType  page_type => generation_status
     * @return array{0: EnterpriseWikiIngestRun, 1: array<string, EnterpriseWikiPage>}
     */
    private function createRun(Customer $customer, string $runStatus, array $pagesByType, bool $independentConcept = false): array
    {
        $document = $this->createDocument($customer);

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => $runStatus,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
        ]);

        $pages = [];

        foreach ($pagesByType as $pageType => $status) {
            $page = EnterpriseWikiPage::query()->create([
                'customer_id' => $customer->id,
                'slug' => "{$pageType}-".Str::lower(Str::random(6)),
                'title' => ucfirst($pageType),
                'page_type' => $pageType,
                'status' => EnterpriseWikiPage::STATUS_DRAFT,
                'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
                'last_source_hash' => str_pad('hash', 64, '0'),
            ]);

            EnterpriseWikiIngestRunPage::query()->create([
                'enterprise_wiki_ingest_run_id' => $run->id,
                'enterprise_wiki_page_id' => $page->id,
                'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
                'generation_status' => $status,
                'generation_error' => $status === EnterpriseWikiIngestRunPage::GENERATION_STATUS_FAILED ? 'boom' : null,
            ]);

            $pages[$pageType] = $page;
        }

        if ($independentConcept && isset($pages['concept'])) {
            $run->update(['maintainer_decision_json' => [
                'source_article' => ['action' => 'create', 'title' => $pages['article']->title ?? 'Article', 'reason' => 'r'],
                'source_summary' => ['action' => 'create', 'title' => $pages['summary']->title ?? 'Summary', 'reason' => 'r'],
                'concept_pages' => [[
                    'action' => 'create',
                    'title' => $pages['concept']->title,
                    'proposed_slug' => $pages['concept']->slug,
                    'reason' => 'Scoped concept.',
                    'owned_topics' => ['Forklar concept som selvstendig styringsbegrep.'],
                ]],
                'entity_pages' => [],
                'no_action_reason' => null,
                'warnings' => [],
            ]]);
        }

        return [$run, $pages];
    }
}
