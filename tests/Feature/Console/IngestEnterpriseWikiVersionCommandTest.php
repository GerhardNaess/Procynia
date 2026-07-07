<?php

namespace Tests\Feature\Console;

use App\Jobs\Ai\Wiki\ProcessEnterpriseWikiIngest;
use App\Models\Customer;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemVersion;
use App\Models\Language;
use App\Models\Nationality;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class IngestEnterpriseWikiVersionCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_command_fails_when_customer_option_is_missing(): void
    {
        $this->artisan('wiki:ingest', ['--version-id' => '1'])
            ->expectsOutputToContain('--customer')
            ->assertFailed();

        Queue::assertNothingPushed();
    }

    public function test_command_fails_when_version_option_is_missing(): void
    {
        $customer = $this->createCustomer();

        $this->artisan('wiki:ingest', ['--customer' => (string) $customer->id])
            ->expectsOutputToContain('--version')
            ->assertFailed();

        Queue::assertNothingPushed();
    }

    public function test_command_fails_when_customer_not_found(): void
    {
        $this->artisan('wiki:ingest', ['--customer' => '99999', '--version-id' => '1'])
            ->expectsOutputToContain('not found')
            ->assertFailed();

        Queue::assertNothingPushed();
    }

    public function test_command_fails_when_version_not_found_for_customer(): void
    {
        $customer = $this->createCustomer('Customer A AS');
        $otherCustomer = $this->createCustomer('Customer B AS');
        $item = $this->createKnowledgeItem($otherCustomer);
        $version = $this->createVersion($item, $otherCustomer);

        $this->artisan('wiki:ingest', [
            '--customer' => (string) $customer->id,
            '--version-id' => (string) $version->id,
        ])
            ->expectsOutputToContain('not found')
            ->assertFailed();

        Queue::assertNothingPushed();
    }

    public function test_command_fails_when_version_is_not_approved(): void
    {
        $customer = $this->createCustomer();
        $item = $this->createKnowledgeItem($customer);
        $version = $this->createVersion($item, $customer, [
            'approval_status' => KnowledgeItemVersion::APPROVAL_STATUS_PENDING_REVIEW,
        ]);

        $this->artisan('wiki:ingest', [
            '--customer' => (string) $customer->id,
            '--version-id' => (string) $version->id,
        ])
            ->expectsOutputToContain('approval_status')
            ->assertFailed();

        Queue::assertNothingPushed();
    }

    public function test_command_fails_when_version_is_not_current(): void
    {
        $customer = $this->createCustomer();
        $item = $this->createKnowledgeItem($customer);
        $version = $this->createVersion($item, $customer, ['is_current' => false]);

        $this->artisan('wiki:ingest', [
            '--customer' => (string) $customer->id,
            '--version-id' => (string) $version->id,
        ])
            ->expectsOutputToContain('is_current')
            ->assertFailed();

        Queue::assertNothingPushed();
    }

    public function test_command_fails_when_version_has_no_extracted_text(): void
    {
        $customer = $this->createCustomer();
        $item = $this->createKnowledgeItem($customer);
        $version = $this->createVersion($item, $customer, ['extracted_text' => null]);

        $this->artisan('wiki:ingest', [
            '--customer' => (string) $customer->id,
            '--version-id' => (string) $version->id,
        ])
            ->expectsOutputToContain('extracted text')
            ->assertFailed();

        Queue::assertNothingPushed();
    }

    public function test_command_fails_when_ai_usage_disabled_on_knowledge_item(): void
    {
        $customer = $this->createCustomer();
        $item = $this->createKnowledgeItem($customer, aiUsageEnabled: false);
        $version = $this->createVersion($item, $customer);

        $this->artisan('wiki:ingest', [
            '--customer' => (string) $customer->id,
            '--version-id' => (string) $version->id,
        ])
            ->expectsOutputToContain('ai_usage_enabled')
            ->assertFailed();

        Queue::assertNothingPushed();
    }

    public function test_command_creates_queued_run_and_dispatches_job(): void
    {
        $customer = $this->createCustomer();
        $item = $this->createKnowledgeItem($customer);
        $version = $this->createVersion($item, $customer);

        $this->artisan('wiki:ingest', [
            '--customer' => (string) $customer->id,
            '--version-id' => (string) $version->id,
        ])
            ->expectsOutputToContain('[WIKI_INGEST][START]')
            ->assertSuccessful();

        $this->assertDatabaseCount('enterprise_wiki_ingest_runs', 1);
        $this->assertDatabaseHas('enterprise_wiki_ingest_runs', [
            'customer_id' => $customer->id,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_KNOWLEDGE_ITEM_VERSION,
            'source_id' => $version->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'status' => EnterpriseWikiIngestRun::STATUS_QUEUED,
        ]);

        Queue::assertPushed(ProcessEnterpriseWikiIngest::class, 1);
    }

    public function test_command_skips_dispatch_when_completed_run_exists_and_no_force(): void
    {
        $customer = $this->createCustomer();
        $item = $this->createKnowledgeItem($customer);
        $version = $this->createVersion($item, $customer);

        EnterpriseWikiIngestRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_KNOWLEDGE_ITEM_VERSION,
            'source_id' => $version->id,
            'source_hash' => 'existing-completed-hash',
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'status' => EnterpriseWikiIngestRun::STATUS_COMPLETED,
        ]);

        $this->artisan('wiki:ingest', [
            '--customer' => (string) $customer->id,
            '--version-id' => (string) $version->id,
        ])
            ->expectsOutputToContain('--force')
            ->assertSuccessful();

        $this->assertDatabaseCount('enterprise_wiki_ingest_runs', 1);
        Queue::assertNothingPushed();
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

    private function createKnowledgeItem(Customer $customer, bool $aiUsageEnabled = true): KnowledgeItem
    {
        return KnowledgeItem::query()->create([
            'customer_id' => $customer->id,
            'title' => 'Test Document',
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_COMPANY,
            'ai_usage_enabled' => $aiUsageEnabled,
        ]);
    }

    private function createVersion(KnowledgeItem $item, Customer $customer, array $overrides = []): KnowledgeItemVersion
    {
        return KnowledgeItemVersion::query()->create(array_merge([
            'knowledge_item_id' => $item->id,
            'customer_id' => $customer->id,
            'version_no' => 1,
            'is_current' => true,
            'extracted_text' => 'Sample extracted text for ingest testing.',
            'approval_status' => KnowledgeItemVersion::APPROVAL_STATUS_APPROVED,
            'file_hash_sha256' => str_pad('abc123', 64, '0'),
        ], $overrides));
    }
}
