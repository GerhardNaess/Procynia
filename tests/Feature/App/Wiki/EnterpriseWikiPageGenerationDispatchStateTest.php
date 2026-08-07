<?php

namespace Tests\Feature\App\Wiki;

use App\Jobs\EnterpriseWiki\ContinueEnterpriseWikiDocumentFlowAfterPages;
use App\Jobs\EnterpriseWiki\FinalizeEnterpriseWikiPageGeneration;
use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\Ai\Wiki\WikiPageContentAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiGenerateAppliedPagesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use Tests\TestCase;

/**
 * Durable execution-state for applied-page generation dispatch (Wiki AI capacity work item):
 * generation_status gains a 'dispatched' value and generation_claimed_at/generation_claim_token
 * (execution lease) close the gap where two concurrent GenerateEnterpriseWikiAppliedPage jobs for
 * the same run/page both passed the old row-locked "generated_page_version_id still null" check
 * and both reached the AI client — see the migration
 * 2026_08_07_000003_add_generation_dispatch_lease_to_enterprise_wiki_ingest_run_pages_table and
 * EnterpriseWikiGenerateAppliedPagesService::reservePageForDispatch()/generatePageForRun().
 *
 * Several tests open a second, fully independent raw PDO connection (never a second named
 * Laravel connection) to prove serialization at the real PostgreSQL level, mirroring
 * EnterpriseWikiPageVersionConcurrencyTest's established approach.
 */
class EnterpriseWikiPageGenerationDispatchStateTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_MARKDOWN = "# Test Page\n\nGenerated content for testing.";

    // === Test 10: first page dispatch — pending -> dispatched ===

    public function test_first_page_dispatch_transitions_pending_to_dispatched(): void
    {
        $customer = $this->createCustomer();
        [$run, $article] = $this->createAppliedRunWithTwoPages($customer);

        $service = app(EnterpriseWikiGenerateAppliedPagesService::class);
        $this->assertTrue($service->reservePageForDispatch($run->id, $article->id));

        $pivot = $this->pivot($run->id, $article->id);
        $this->assertSame(EnterpriseWikiIngestRunPage::GENERATION_STATUS_DISPATCHED, $pivot->generation_status);
        $this->assertNotNull($pivot->generation_dispatched_at);

        // Still within its dispatch-wait window — a second attempt must not win again.
        $this->assertFalse($service->reservePageForDispatch($run->id, $article->id));
    }

    // === Test 11 (& part of 14): concurrent dispatch — only one wins ===

    public function test_concurrent_dispatch_of_a_pending_page_only_one_wins(): void
    {
        $customer = $this->createCustomer();
        [$run, $article] = $this->createAppliedRunWithTwoPages($customer);

        $this->assertConcurrentDispatchOnlyOneWins($run->id, $article->id);
    }

    public function test_concurrent_recovery_dispatch_of_a_stale_dispatched_page_only_one_wins(): void
    {
        $customer = $this->createCustomer();
        [$run, $article] = $this->createAppliedRunWithTwoPages($customer);
        $this->pivot($run->id, $article->id)->update([
            'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_DISPATCHED,
            'generation_dispatched_at' => now()->subDays(1),
        ]);

        $this->assertConcurrentDispatchOnlyOneWins($run->id, $article->id);
    }

    private function assertConcurrentDispatchOnlyOneWins(int $runId, int $pageId): void
    {
        DB::commit();

        $pdoA = DB::connection()->getPdo();
        $pdoB = $this->openIndependentConnection();

        $sql = "UPDATE enterprise_wiki_ingest_run_pages SET generation_status = 'dispatched', generation_dispatched_at = now() ".
            'WHERE enterprise_wiki_ingest_run_id = ? AND enterprise_wiki_page_id = ? AND generated_page_version_id IS NULL '.
            "AND (generation_status = 'pending' ".
            "OR (generation_status = 'dispatched' AND generation_dispatched_at < now() - interval '90 seconds') ".
            "OR (generation_status = 'running' AND (generation_claimed_at IS NULL OR generation_claimed_at < now() - interval '720 seconds')))";

        $stmtA = $pdoA->prepare($sql);
        $stmtA->execute([$runId, $pageId]);
        $stmtB = $pdoB->prepare($sql);
        $stmtB->execute([$runId, $pageId]);

        $this->assertSame(1, $stmtA->rowCount() + $stmtB->rowCount(), 'Exactly one of the two racing dispatchers must win the compare-and-swap.');
    }

    // === Test 12: two duplicate page jobs — only one gets the execution token ===

    public function test_two_concurrent_workers_on_a_dispatched_page_only_one_claims_the_execution_lease(): void
    {
        $customer = $this->createCustomer();
        [$run, $article] = $this->createAppliedRunWithTwoPages($customer);
        $this->pivot($run->id, $article->id)->update(['generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_DISPATCHED]);

        $this->assertOnlyOneWorkerClaimsExecutionLease($this->pivot($run->id, $article->id)->id);
    }

    // === Test 14: stale running page can be reclaimed, exactly once, in a controlled way ===

    public function test_two_concurrent_workers_on_a_stale_running_page_only_one_reclaims_it(): void
    {
        $customer = $this->createCustomer();
        [$run, $article] = $this->createAppliedRunWithTwoPages($customer);
        $this->pivot($run->id, $article->id)->update([
            'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_RUNNING,
            'generation_claimed_at' => now()->subDays(1),
            'generation_claim_token' => 'dead-worker-token',
        ]);

        $this->assertOnlyOneWorkerClaimsExecutionLease($this->pivot($run->id, $article->id)->id);
    }

    private function assertOnlyOneWorkerClaimsExecutionLease(int $pivotId): void
    {
        DB::commit();

        $pdoA = DB::connection()->getPdo();
        $pdoB = $this->openIndependentConnection();

        $pdoA->beginTransaction();
        $rowA = $this->lockPivotRow($pdoA, $pivotId);

        $pdoB->beginTransaction();
        $pdoB->exec("SET LOCAL lock_timeout = '200ms'");
        $blocked = false;

        try {
            $this->lockPivotRow($pdoB, $pivotId);
        } catch (PDOException) {
            $blocked = true;
        }

        $this->assertTrue($blocked, 'Worker B must block while worker A still holds the pivot row lock.');
        $pdoB->rollBack();

        $claimedA = $this->claimPivotRowIfEligible($pdoA, $pivotId, $rowA, 'worker-a');
        $pdoA->commit();
        $this->assertTrue($claimedA, 'Worker A must be able to claim an eligible pivot.');

        // Worker B retries now that A has released the row lock — it must see A's fresh lease
        // and correctly refuse, never reaching AI.
        $pdoB->beginTransaction();
        $rowB = $this->lockPivotRow($pdoB, $pivotId);
        $claimedB = $this->claimPivotRowIfEligible($pdoB, $pivotId, $rowB, 'worker-b');
        $pdoB->commit();

        $this->assertFalse($claimedB, 'Worker B must not claim a pivot worker A just legitimately claimed.');

        $final = DB::table('enterprise_wiki_ingest_run_pages')->where('id', $pivotId)->first();
        $this->assertSame('worker-a', $final->generation_claim_token);
    }

    private function lockPivotRow(PDO $pdo, int $pivotId): array
    {
        $stmt = $pdo->prepare('SELECT generation_status, generation_claimed_at, generated_page_version_id FROM enterprise_wiki_ingest_run_pages WHERE id = ? FOR UPDATE');
        $stmt->execute([$pivotId]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Mirrors EnterpriseWikiGenerateAppliedPagesService::generatePageForRun()'s claim-phase
     * eligibility check exactly (pending/dispatched, or running-but-stale, and never already
     * completed) — proving the same invariant the real service enforces inside its own
     * lockForUpdate() transaction.
     */
    private function claimPivotRowIfEligible(PDO $pdo, int $pivotId, array $row, string $token): bool
    {
        if ($row['generated_page_version_id'] !== null) {
            return false;
        }

        $status = $row['generation_status'];
        $claimedAt = $row['generation_claimed_at'];
        $eligible = in_array($status, ['pending', 'dispatched'], true)
            || ($status === 'running' && ($claimedAt === null || strtotime($claimedAt) < time() - 720));

        if (! $eligible) {
            return false;
        }

        $stmt = $pdo->prepare("UPDATE enterprise_wiki_ingest_run_pages SET generation_status = 'running', generation_claimed_at = now(), generation_claim_token = ? WHERE id = ?");
        $stmt->execute([$token, $pivotId]);

        return true;
    }

    private function openIndependentConnection(): PDO
    {
        $config = config('database.connections.pgsql');
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']);

        return new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    // === Test 13: completed page — cannot be redispatched or regenerated ===

    public function test_completed_page_cannot_be_dispatched_or_regenerated(): void
    {
        $customer = $this->createCustomer();
        [$run, $article] = $this->createAppliedRunWithTwoPages($customer);

        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $article->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => self::FAKE_MARKDOWN,
        ]);
        $this->pivot($run->id, $article->id)->update([
            'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
            'generated_page_version_id' => $version->id,
        ]);

        $service = app(EnterpriseWikiGenerateAppliedPagesService::class);
        $this->assertFalse($service->reservePageForDispatch($run->id, $article->id));

        $this->mock(WikiPageContentAiClient::class)->shouldNotReceive('generatePageFromSource');
        $service->generatePageForRun($run->fresh(), $article);

        $this->assertSame(1, EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $article->id)->count());
    }

    // === Test 16: duplicate fan-in invocations — one continuation ===

    public function test_duplicate_finalize_invocations_produce_exactly_one_continuation(): void
    {
        Queue::fake();
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $concept = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Konsept');

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_GENERATING_CONCEPT_ENTITY_PAGES,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
        ]);
        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $concept->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
        ]);

        // Two "duplicate" completion callbacks for the same finished phase — e.g. one from the
        // last page job's own dispatch and one from a delayed recovery-sentinel pass that fires
        // just after everything already finished.
        (new FinalizeEnterpriseWikiPageGeneration($run->id))->handle();
        (new FinalizeEnterpriseWikiPageGeneration($run->id))->handle();

        Queue::assertPushed(ContinueEnterpriseWikiDocumentFlowAfterPages::class, 1);
    }

    private function pivot(int $runId, int $pageId): EnterpriseWikiIngestRunPage
    {
        return EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $runId)
            ->where('enterprise_wiki_page_id', $pageId)
            ->firstOrFail();
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
            'extracted_text' => 'This is the extracted text from the source document.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
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

    /**
     * @return array{0: EnterpriseWikiIngestRun, 1: EnterpriseWikiPage, 2: EnterpriseWikiPage}
     */
    private function createAppliedRunWithTwoPages(Customer $customer): array
    {
        $document = $this->createDocument($customer);
        $article = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Test Artikkel');
        $summary = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Sammendrag: Test Artikkel');

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_GENERATING_PAGES,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
        ]);

        foreach ([$article, $summary] as $page) {
            EnterpriseWikiIngestRunPage::query()->create([
                'enterprise_wiki_ingest_run_id' => $run->id,
                'enterprise_wiki_page_id' => $page->id,
                'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            ]);
        }

        $this->mock(WikiPageContentAiClient::class)
            ->shouldReceive('generatePageFromSource')
            ->andReturnUsing(fn (
                string $pageTitle,
                string $pageType,
                string $sourceText,
                string $languageCode,
                string $additionalContext = '',
                array $linkCatalog = [],
                array $sourceElements = [],
            ): array => $this->structuredPageResult(self::FAKE_MARKDOWN." See [[{$summary->slug}]] for details.", $sourceElements))
            ->byDefault();

        return [$run, $article, $summary];
    }

    /**
     * @param  list<array<string, mixed>>  $sourceElements
     * @return array{markdown: string, blocks: list<array<string, mixed>>}
     */
    private function structuredPageResult(string $markdown, array $sourceElements): array
    {
        $sourceElement = $sourceElements[0] ?? [
            'source_element_key' => 'document-1-full-text',
            'source_element_type' => 'manual',
        ];

        return [
            'markdown' => $markdown,
            'blocks' => [
                [
                    'markdown' => $markdown,
                    'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                    'source_element_keys' => [(string) $sourceElement['source_element_key']],
                    'source_element_types' => [(string) $sourceElement['source_element_type']],
                    'best_practice_reason' => null,
                    'link_intents' => [],
                ],
            ],
        ];
    }
}
