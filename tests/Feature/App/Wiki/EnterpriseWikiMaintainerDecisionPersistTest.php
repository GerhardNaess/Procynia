<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

class EnterpriseWikiMaintainerDecisionPersistTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.enterprise_wiki.ai_enabled' => true]);
    }

    // =========================================================================
    // Without --persist (dry-run — no writes)
    // =========================================================================

    public function test_without_persist_no_ingest_run_is_created(): void
    {
        $customer = $this->createCustomer();
        $this->mockService();

        $runsBefore = EnterpriseWikiIngestRun::query()->count();

        Artisan::call('wiki:maintainer-decision', [
            '--customer'    => $customer->id,
            '--document-id' => 1,
        ]);

        $this->assertSame($runsBefore, EnterpriseWikiIngestRun::query()->count());
    }

    public function test_without_persist_no_pages_created(): void
    {
        $customer = $this->createCustomer();
        $this->mockService();

        $pagesBefore = EnterpriseWikiPage::query()->count();

        Artisan::call('wiki:maintainer-decision', [
            '--customer'    => $customer->id,
            '--document-id' => 1,
        ]);

        $this->assertSame($pagesBefore, EnterpriseWikiPage::query()->count());
    }

    public function test_without_persist_outputs_dry_run_marker(): void
    {
        $customer = $this->createCustomer();
        $this->mockService();

        Artisan::call('wiki:maintainer-decision', [
            '--customer'    => $customer->id,
            '--document-id' => 1,
        ]);

        $this->assertStringContainsString('[DRY-RUN]', Artisan::output());
    }

    // =========================================================================
    // With --persist
    // =========================================================================

    public function test_persist_creates_ingest_run_with_decision_json(): void
    {
        $customer = $this->createCustomer();
        $decision = $this->validDecision();
        $this->mockService($decision);

        Artisan::call('wiki:maintainer-decision', [
            '--customer'    => $customer->id,
            '--document-id' => 42,
            '--persist'     => true,
        ]);

        $run = EnterpriseWikiIngestRun::query()
            ->where('customer_id', $customer->id)
            ->where('status', EnterpriseWikiIngestRun::STATUS_DECISION_ONLY)
            ->latest()
            ->first();

        $this->assertNotNull($run, 'An ingest run should have been created.');
        $this->assertIsArray($run->maintainer_decision_json);
        $this->assertArrayHasKey('source_article', $run->maintainer_decision_json);
    }

    public function test_persist_stores_correct_source_type_and_id(): void
    {
        $customer = $this->createCustomer();
        $this->mockService();

        Artisan::call('wiki:maintainer-decision', [
            '--customer'    => $customer->id,
            '--document-id' => 77,
            '--persist'     => true,
        ]);

        $run = EnterpriseWikiIngestRun::query()
            ->where('customer_id', $customer->id)
            ->where('status', EnterpriseWikiIngestRun::STATUS_DECISION_ONLY)
            ->latest()
            ->first();

        $this->assertSame(EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT, $run->source_type);
        $this->assertSame(77, $run->source_id);
    }

    public function test_persist_stores_maintainer_decision_status_pending(): void
    {
        $customer = $this->createCustomer();
        $this->mockService();

        Artisan::call('wiki:maintainer-decision', [
            '--customer'    => $customer->id,
            '--document-id' => 1,
            '--persist'     => true,
        ]);

        $run = EnterpriseWikiIngestRun::query()
            ->where('customer_id', $customer->id)
            ->latest()
            ->first();

        $this->assertSame(EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_PENDING, $run->maintainer_decision_status);
        $this->assertNotNull($run->maintainer_decision_generated_at);
    }

    public function test_persist_does_not_create_pages(): void
    {
        $customer = $this->createCustomer();
        $this->mockService();

        $pagesBefore = EnterpriseWikiPage::query()->count();

        Artisan::call('wiki:maintainer-decision', [
            '--customer'    => $customer->id,
            '--document-id' => 1,
            '--persist'     => true,
        ]);

        $this->assertSame($pagesBefore, EnterpriseWikiPage::query()->count());
    }

    public function test_persist_does_not_create_page_versions(): void
    {
        $customer = $this->createCustomer();
        $this->mockService();

        $versionsBefore = EnterpriseWikiPageVersion::query()->count();

        Artisan::call('wiki:maintainer-decision', [
            '--customer'    => $customer->id,
            '--document-id' => 1,
            '--persist'     => true,
        ]);

        $this->assertSame($versionsBefore, EnterpriseWikiPageVersion::query()->count());
    }

    public function test_persist_does_not_create_ingest_run_pages_pivot_rows(): void
    {
        $customer = $this->createCustomer();
        $this->mockService();

        $pivotBefore = EnterpriseWikiIngestRunPage::query()->count();

        Artisan::call('wiki:maintainer-decision', [
            '--customer'    => $customer->id,
            '--document-id' => 1,
            '--persist'     => true,
        ]);

        $this->assertSame($pivotBefore, EnterpriseWikiIngestRunPage::query()->count());
    }

    public function test_persist_outputs_persisted_marker(): void
    {
        $customer = $this->createCustomer();
        $this->mockService();

        Artisan::call('wiki:maintainer-decision', [
            '--customer'    => $customer->id,
            '--document-id' => 1,
            '--persist'     => true,
        ]);

        $output = Artisan::output();
        $this->assertStringContainsString('[PERSISTED]', $output);
        $this->assertStringContainsString('source_article', $output);
    }

    public function test_persist_exit_code_is_zero(): void
    {
        $customer = $this->createCustomer();
        $this->mockService();

        $exitCode = Artisan::call('wiki:maintainer-decision', [
            '--customer'    => $customer->id,
            '--document-id' => 1,
            '--persist'     => true,
        ]);

        $this->assertSame(0, $exitCode);
    }

    // =========================================================================
    // JSON cast
    // =========================================================================

    public function test_maintainer_decision_json_cast_returns_array(): void
    {
        $customer = $this->createCustomer();
        $decision = $this->validDecision();

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid'                              => Str::uuid()->toString(),
            'customer_id'                       => $customer->id,
            'trigger_type'                      => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type'                       => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id'                         => 1,
            'status'                            => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_json'          => $decision,
            'maintainer_decision_status'        => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_PENDING,
            'maintainer_decision_generated_at'  => now(),
        ]);

        $fresh = $run->fresh();
        $this->assertIsArray($fresh->maintainer_decision_json);
        $this->assertSame('create', $fresh->maintainer_decision_json['source_article']['action']);
    }

    public function test_maintainer_decision_json_is_null_when_not_set(): void
    {
        $customer = $this->createCustomer();

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid'         => Str::uuid()->toString(),
            'customer_id'  => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type'  => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id'    => 1,
            'status'       => EnterpriseWikiIngestRun::STATUS_QUEUED,
        ]);

        $this->assertNull($run->fresh()->maintainer_decision_json);
    }

    // =========================================================================
    // Guard conditions (customer scope, AI flag)
    // =========================================================================

    public function test_ai_disabled_blocks_persist(): void
    {
        config(['services.enterprise_wiki.ai_enabled' => false]);
        $customer = $this->createCustomer();

        $runsBefore = EnterpriseWikiIngestRun::query()->count();

        Artisan::call('wiki:maintainer-decision', [
            '--customer'    => $customer->id,
            '--document-id' => 1,
            '--persist'     => true,
        ]);

        $this->assertStringContainsString('not enabled', Artisan::output());
        $this->assertSame($runsBefore, EnterpriseWikiIngestRun::query()->count());
    }

    public function test_customer_not_found_does_not_create_run(): void
    {
        $runsBefore = EnterpriseWikiIngestRun::query()->count();

        Artisan::call('wiki:maintainer-decision', [
            '--customer'    => 99999,
            '--document-id' => 1,
            '--persist'     => true,
        ]);

        $this->assertSame($runsBefore, EnterpriseWikiIngestRun::query()->count());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function mockService(array $decision = []): void
    {
        if ($decision === []) {
            $decision = $this->validDecision();
        }

        /** @var EnterpriseWikiMaintainerDecisionService&MockInterface $mock */
        $mock = $this->mock(EnterpriseWikiMaintainerDecisionService::class);
        $mock->shouldReceive('runForDocument')->once()->andReturn($decision);
    }

    private function validDecision(): array
    {
        return [
            'source_article' => [
                'action'        => 'create',
                'title'         => 'Test Artikkel',
                'proposed_slug' => 'test-artikkel-ab1c2d',
                'reason'        => 'New.',
            ],
            'source_summary' => [
                'action'        => 'create',
                'title'         => 'Sammendrag: Test Artikkel',
                'proposed_slug' => 'sammendrag-test-artikkel-ab1c2d',
                'reason'        => 'Companion.',
            ],
            'concept_pages'    => [],
            'entity_pages'     => [],
            'no_action_reason' => null,
            'warnings'         => [],
        ];
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
            'name'             => $name,
            'slug'             => Str::slug($name) . '-' . Str::lower(Str::random(6)),
            'language_id'      => $language->id,
            'nationality_id'   => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active'        => true,
        ]);
    }
}
