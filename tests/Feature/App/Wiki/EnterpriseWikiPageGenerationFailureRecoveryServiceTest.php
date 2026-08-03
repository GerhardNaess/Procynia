<?php

namespace Tests\Feature\App\Wiki;

use App\Jobs\EnterpriseWiki\GenerateEnterpriseWikiAppliedPage;
use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\EnterpriseWiki\EnterpriseWikiPageGenerationFailureRecoveryService;
use App\Services\EnterpriseWiki\EnterpriseWikiRunRecoveryResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Wiki run-593: a run whose maintainer_decision was already applied can fail because ONE OR MORE
 * individual applied-page generation jobs failed while every other page for the same run
 * generated successfully (see FinalizeEnterpriseWikiPageGeneration::markRunFailed()). This service
 * lets that be retried by re-dispatching only the failed page job(s) — completed pages are never
 * regenerated, and the same run_id/document_id/page_id are always reused.
 *
 * The run-593 fixture used throughout (8 pivots: article, summary, 6 concept pages, 1 of which —
 * "Styringsnivåer (strategisk, taktisk, operativt)" — failed planned-section-coverage) mirrors the
 * real incident shape.
 */
class EnterpriseWikiPageGenerationFailureRecoveryServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): EnterpriseWikiPageGenerationFailureRecoveryService
    {
        return app(EnterpriseWikiPageGenerationFailureRecoveryService::class);
    }

    public function test_only_failed_pages_are_redispatched(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        [$run, $pivots] = $this->createRun593LikeFixture($customer);

        $result = $this->service()->attempt($run->id, caller: 'test');

        $this->assertSame(EnterpriseWikiRunRecoveryResult::OUTCOME_RESUMED, $result->outcome);

        $failedPageId = $pivots['failed']->enterprise_wiki_page_id;

        Queue::assertPushed(GenerateEnterpriseWikiAppliedPage::class, 1);
        Queue::assertPushed(
            GenerateEnterpriseWikiAppliedPage::class,
            fn ($job) => $job->runId === $run->id && $job->pageId === $failedPageId
        );
    }

    public function test_completed_pages_are_not_touched(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        [$run, $pivots] = $this->createRun593LikeFixture($customer);

        $completedSnapshots = collect($pivots['completed'])->map(fn (EnterpriseWikiIngestRunPage $p) => $p->only([
            'id', 'generation_status', 'generated_page_version_id', 'generation_started_at', 'generation_completed_at', 'generation_error',
        ]));

        $this->service()->attempt($run->id, caller: 'test');

        foreach ($completedSnapshots as $snapshot) {
            $fresh = EnterpriseWikiIngestRunPage::query()->find($snapshot['id']);
            $this->assertSame(EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED, $fresh->generation_status);
            $this->assertSame($snapshot['generated_page_version_id'], $fresh->generated_page_version_id);
        }

        // Never dispatched for any completed page.
        Queue::assertPushed(GenerateEnterpriseWikiAppliedPage::class, function ($job) use ($pivots) {
            return ! collect($pivots['completed'])->pluck('enterprise_wiki_page_id')->contains($job->pageId);
        });
    }

    public function test_same_run_document_and_page_ids_are_preserved(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        [$run, $pivots] = $this->createRun593LikeFixture($customer);
        $originalRunId = $run->id;
        $originalDocumentId = $run->source_id;
        $originalFailedPageId = $pivots['failed']->enterprise_wiki_page_id;

        $this->service()->attempt($run->id, caller: 'test');

        $run->refresh();
        $this->assertSame($originalRunId, $run->id);
        $this->assertSame($originalDocumentId, $run->source_id);

        Queue::assertPushed(GenerateEnterpriseWikiAppliedPage::class, fn ($job) => $job->runId === $originalRunId && $job->pageId === $originalFailedPageId
        );

        $failedPivot = EnterpriseWikiIngestRunPage::query()->find($pivots['failed']->id);
        $this->assertSame($originalFailedPageId, $failedPivot->enterprise_wiki_page_id);
        $this->assertSame($originalRunId, $failedPivot->enterprise_wiki_ingest_run_id);
    }

    public function test_failed_pivot_is_reset_to_pending_with_a_clean_slate(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        [$run, $pivots] = $this->createRun593LikeFixture($customer);

        $this->service()->attempt($run->id, caller: 'test');

        $failedPivot = EnterpriseWikiIngestRunPage::query()->find($pivots['failed']->id);
        $this->assertSame(EnterpriseWikiIngestRunPage::GENERATION_STATUS_PENDING, $failedPivot->generation_status);
        $this->assertNull($failedPivot->generation_started_at);
        $this->assertNull($failedPivot->generation_completed_at);
        $this->assertNull($failedPivot->generation_error);
        $this->assertNull($failedPivot->generated_page_version_id);
    }

    public function test_run_status_is_set_back_to_generating_concept_entity_pages(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        [$run] = $this->createRun593LikeFixture($customer);

        $this->service()->attempt($run->id, caller: 'test');

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_GENERATING_CONCEPT_ENTITY_PAGES, $run->status);
        $this->assertNull($run->finished_at);
        $this->assertNull($run->error_message);
    }

    public function test_run_without_any_failed_page_is_rejected(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        [$run] = $this->createRun593LikeFixture($customer, allCompleted: true);

        $result = $this->service()->attempt($run->id, caller: 'test');

        $this->assertSame(EnterpriseWikiRunRecoveryResult::OUTCOME_NOT_RECOVERABLE, $result->outcome);
        Queue::assertNothingPushed();
    }

    public function test_run_with_a_pending_page_is_rejected(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        [$run] = $this->createRun593LikeFixture($customer, extraPendingPage: true);

        $result = $this->service()->attempt($run->id, caller: 'test');

        $this->assertSame(EnterpriseWikiRunRecoveryResult::OUTCOME_NOT_RECOVERABLE, $result->outcome);
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_FAILED, $run->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_run_with_a_running_page_is_rejected(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        [$run] = $this->createRun593LikeFixture($customer, extraRunningPage: true);

        $result = $this->service()->attempt($run->id, caller: 'test');

        $this->assertSame(EnterpriseWikiRunRecoveryResult::OUTCOME_NOT_RECOVERABLE, $result->outcome);
        Queue::assertNothingPushed();
    }

    public function test_double_call_dispatches_only_one_job_per_failed_page(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        [$run] = $this->createRun593LikeFixture($customer);

        $first = $this->service()->attempt($run->id, caller: 'test-first');
        $second = $this->service()->attempt($run->id, caller: 'test-second');

        $this->assertTrue($first->isResumed());
        $this->assertSame(EnterpriseWikiRunRecoveryResult::OUTCOME_ALREADY_RUNNING, $second->outcome);

        Queue::assertPushed(GenerateEnterpriseWikiAppliedPage::class, 1);
    }

    public function test_multiple_failed_pages_are_all_redispatched_and_none_others(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        [$run, $pivots] = $this->createRun593LikeFixture($customer, secondFailedPage: true);

        $result = $this->service()->attempt($run->id, caller: 'test');

        $this->assertTrue($result->isResumed());
        Queue::assertPushed(GenerateEnterpriseWikiAppliedPage::class, 2);

        foreach ($pivots['failed_multi'] as $failedPivot) {
            Queue::assertPushed(GenerateEnterpriseWikiAppliedPage::class, fn ($job) => $job->runId === $run->id && $job->pageId === $failedPivot->enterprise_wiki_page_id
            );
        }
    }

    public function test_run_not_found_is_not_recoverable(): void
    {
        $result = $this->service()->evaluate(999999);

        $this->assertSame(EnterpriseWikiRunRecoveryResult::OUTCOME_NOT_RECOVERABLE, $result->outcome);
    }

    public function test_run_with_maintainer_decision_not_applied_is_rejected(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        [$run] = $this->createRun593LikeFixture($customer);
        $run->update(['maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_PENDING]);

        $result = $this->service()->attempt($run->id, caller: 'test');

        $this->assertSame(EnterpriseWikiRunRecoveryResult::OUTCOME_NOT_RECOVERABLE, $result->outcome);
        Queue::assertNothingPushed();
    }

    public function test_run_already_completed_is_already_complete(): void
    {
        $customer = $this->createCustomer();
        [$run] = $this->createRun593LikeFixture($customer);
        $run->update(['status' => EnterpriseWikiIngestRun::STATUS_COMPLETED]);

        $result = $this->service()->evaluate($run->id);

        $this->assertSame(EnterpriseWikiRunRecoveryResult::OUTCOME_ALREADY_COMPLETE, $result->outcome);
    }

    public function test_evaluate_is_read_only_and_never_dispatches(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        [$run] = $this->createRun593LikeFixture($customer);

        $result = $this->service()->evaluate($run->id);

        $this->assertTrue($result->isResumed());
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_FAILED, $run->fresh()->status);
        Queue::assertNothingPushed();
    }

    // =========================================================================
    // Fixtures
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
            'original_filename' => 'run-593-source.docx',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.docx',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Extracted source text for the run-593 page-generation recovery fixture.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createPage(Customer $customer, string $pageType, string $title): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'title' => $title,
            'page_type' => $pageType,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }

    private function attachPivot(
        EnterpriseWikiIngestRun $run,
        EnterpriseWikiPage $page,
        string $generationStatus,
        ?string $error = null,
    ): EnterpriseWikiIngestRunPage {
        return EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            'generation_status' => $generationStatus,
            'generation_started_at' => $generationStatus === EnterpriseWikiIngestRunPage::GENERATION_STATUS_PENDING ? null : now()->subMinutes(5),
            'generation_completed_at' => $generationStatus === EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED ? now() : null,
            'generation_error' => $error,
        ]);
    }

    /**
     * Builds the exact run-593 shape: status=failed, maintainer_decision_status=applied, 8
     * pivots (article, summary, 6 concept pages) — 7 generation_status=completed, 1
     * generation_status=failed with no generated_page_version_id.
     *
     * @return array{0: EnterpriseWikiIngestRun, 1: array{completed: list<EnterpriseWikiIngestRunPage>, failed: EnterpriseWikiIngestRunPage, failed_multi?: list<EnterpriseWikiIngestRunPage>}}
     */
    private function createRun593LikeFixture(
        Customer $customer,
        bool $allCompleted = false,
        bool $extraPendingPage = false,
        bool $extraRunningPage = false,
        bool $secondFailedPage = false,
    ): array {
        $document = $this->createDocument($customer);

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_FAILED,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'error_message' => '1 of 6 concept/entity page(s) failed to generate: Styringsnivåer (strategisk, taktisk, operativt).',
            'finished_at' => now(),
        ]);

        $completed = [];

        $completed[] = $this->attachPivot(
            $run,
            $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Masterdata Samhandling'),
            EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
        );
        $completed[] = $this->attachPivot(
            $run,
            $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Masterdata Samhandling — kort oppsummering'),
            EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
        );

        $conceptTitles = ['Samhandlingsmodell', 'Kundeteam', 'Styrings- og samhandlingsmodell', 'Applikasjonsdrift', 'ITIL (rammeverk)'];
        foreach ($conceptTitles as $title) {
            $completed[] = $this->attachPivot(
                $run,
                $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, $title),
                EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
            );
        }

        $failedStatus = $allCompleted
            ? EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED
            : EnterpriseWikiIngestRunPage::GENERATION_STATUS_FAILED;

        $failed = $this->attachPivot(
            $run,
            $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Styringsnivåer (strategisk, taktisk, operativt)'),
            $failedStatus,
            $allCompleted ? null : '[EnterpriseWikiPageGenerationIncompleteException] planned section(s) still missing or empty after one repair: Rolle- og møtefora-oversikt.',
        );

        if ($allCompleted) {
            $completed[] = $failed;
        }

        $pivots = ['completed' => $completed, 'failed' => $failed];

        if ($extraPendingPage) {
            $this->attachPivot(
                $run,
                $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Endnu ikke startet'),
                EnterpriseWikiIngestRunPage::GENERATION_STATUS_PENDING,
            );
        }

        if ($extraRunningPage) {
            $this->attachPivot(
                $run,
                $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Under generering'),
                EnterpriseWikiIngestRunPage::GENERATION_STATUS_RUNNING,
            );
        }

        if ($secondFailedPage) {
            $secondFailed = $this->attachPivot(
                $run,
                $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ENTITY, 'Acme AS'),
                EnterpriseWikiIngestRunPage::GENERATION_STATUS_FAILED,
                '[EnterpriseWikiPageGenerationIncompleteException] second failed page for multi-failure coverage.',
            );
            $pivots['failed_multi'] = [$failed, $secondFailed];
        }

        return [$run, $pivots];
    }
}
