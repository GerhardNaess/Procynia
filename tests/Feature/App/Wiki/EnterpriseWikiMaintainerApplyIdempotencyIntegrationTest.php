<?php

namespace Tests\Feature\App\Wiki;

use App\Jobs\Ai\Wiki\ProcessEnterpriseWikiIngest;
use App\Jobs\EnterpriseWiki\GenerateEnterpriseWikiAppliedPage;
use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiPage;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentFlowService;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionApplyService;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Runtime fix (run 23): reproduces the exact reported failure end-to-end — a run whose
 * maintainer decision apply already created page skeletons, followed by a new run for the
 * same document whose maintainer decision (still proposing "create"/"update" for the same
 * canonical slugs) must be applied idempotently rather than throwing
 * SQLSTATE[23505] duplicate key value violates unique constraint enterprise_wiki_pages_customer_id_slug_unique.
 *
 * Only EnterpriseWikiMaintainerDecisionService (the AI decision generator) is mocked — the
 * real EnterpriseWikiMaintainerDecisionApplyService runs, proving the fix through the actual
 * staged document flow, not just the service in isolation.
 */
class EnterpriseWikiMaintainerApplyIdempotencyIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_previously_failed_downstream_run_can_be_followed_by_a_new_run_without_unique_violation(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $decision = $this->baseDecision();

        // Run A: maintainer decision applied successfully (page skeletons created), then
        // failed later downstream — e.g. during page generation or materialization.
        $runA = $this->createDecisionOnlyRun($customer, $document, $decision);
        app(EnterpriseWikiMaintainerDecisionApplyService::class)->apply($runA);
        $runA->update([
            'status' => EnterpriseWikiIngestRun::STATUS_FAILED,
            'error_message' => 'Simulated downstream failure after apply.',
            'finished_at' => now(),
        ]);

        $this->assertSame(2, EnterpriseWikiPage::query()->where('customer_id', $customer->id)->count());

        // Run B: a fresh run for the same document. The maintainer decision AI is mocked to
        // return the exact same decision — still proposing "create" for both source pages,
        // exactly as it would if regenerated without knowledge of run A's partial success.
        $this->mock(EnterpriseWikiMaintainerDecisionService::class)
            ->shouldReceive('runForDocument')
            ->once()
            ->andReturn($decision);

        $flowService = app(EnterpriseWikiDocumentFlowService::class);
        $runB = $flowService->prepareRunForDocument($customer->id, $document->id)['run'];

        $flowService->run($runB->id);

        $runB->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_GENERATING_PAGES, $runB->status);
        $this->assertNull($runB->error_message);
        $this->assertSame(EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED, $runB->maintainer_decision_status);

        // Idempotent: page count must not have increased.
        $this->assertSame(2, EnterpriseWikiPage::query()->where('customer_id', $customer->id)->count());
    }

    public function test_staged_page_generation_dispatches_normally_after_reusing_existing_pages(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $decision = $this->baseDecision();

        $runA = $this->createDecisionOnlyRun($customer, $document, $decision);
        app(EnterpriseWikiMaintainerDecisionApplyService::class)->apply($runA);
        $existingArticle = EnterpriseWikiPage::query()->where('customer_id', $customer->id)->where('page_type', EnterpriseWikiPage::PAGE_TYPE_ARTICLE)->firstOrFail();
        $existingSummary = EnterpriseWikiPage::query()->where('customer_id', $customer->id)->where('page_type', EnterpriseWikiPage::PAGE_TYPE_SUMMARY)->firstOrFail();
        $runA->update(['status' => EnterpriseWikiIngestRun::STATUS_FAILED, 'finished_at' => now()]);

        $this->mock(EnterpriseWikiMaintainerDecisionService::class)
            ->shouldReceive('runForDocument')
            ->once()
            ->andReturn($decision);

        $flowService = app(EnterpriseWikiDocumentFlowService::class);
        $runB = $flowService->prepareRunForDocument($customer->id, $document->id)['run'];

        $flowService->run($runB->id);

        Queue::assertPushed(
            GenerateEnterpriseWikiAppliedPage::class,
            fn (GenerateEnterpriseWikiAppliedPage $job) => $job->runId === $runB->id && $job->pageId === $existingArticle->id,
        );
        Queue::assertPushed(
            GenerateEnterpriseWikiAppliedPage::class,
            fn (GenerateEnterpriseWikiAppliedPage $job) => $job->runId === $runB->id && $job->pageId === $existingSummary->id,
        );
        Queue::assertPushed(GenerateEnterpriseWikiAppliedPage::class, 2);
    }

    public function test_process_enterprise_wiki_ingest_not_modified(): void
    {
        $reflection = new \ReflectionClass(ProcessEnterpriseWikiIngest::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringNotContainsString('EnterpriseWikiMaintainerDecisionApplyService', $source);
        $this->assertStringNotContainsString('resolvePage', $source);
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
            'extracted_text' => 'Source text for maintainer-apply idempotency tests.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createDecisionOnlyRun(Customer $customer, EnterpriseWikiDocument $document, array $decision): EnterpriseWikiIngestRun
    {
        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_json' => $decision,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_PENDING,
            'maintainer_decision_generated_at' => now(),
        ]);
    }

    private function baseDecision(): array
    {
        return [
            'source_article' => [
                'action' => 'create',
                'title' => 'Advania Prosjektmetode',
                'proposed_slug' => 'advania-prosjektmetode-risikologg-risikomoter-abc-b41c2e',
                'reason' => 'Source article.',
            ],
            'source_summary' => [
                'action' => 'create',
                'title' => 'Sammendrag: Advania Prosjektmetode',
                'proposed_slug' => 'sammendrag-advania-prosjektmetode-abc-b41c2e',
                'reason' => 'Summary page.',
            ],
            'concept_pages' => [],
            'entity_pages' => [],
            'no_action_reason' => null,
            'warnings' => [],
        ];
    }
}
