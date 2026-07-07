<?php

namespace Tests\Feature\App\Wiki;

use App\Jobs\Ai\Wiki\ProcessEnterpriseWikiIngest;
use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\Language;
use App\Models\Nationality;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class IngestEnterpriseWikiDocumentCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    // ─── Missing options ──────────────────────────────────────────────────────

    public function test_fails_when_customer_option_is_missing(): void
    {
        $this->artisan('wiki:ingest-document', [
            '--document-id' => '1',
        ])->assertFailed();

        Queue::assertNothingPushed();
    }

    public function test_fails_when_document_id_option_is_missing(): void
    {
        $customer = $this->createCustomer();

        $this->artisan('wiki:ingest-document', [
            '--customer' => (string) $customer->id,
        ])->assertFailed();

        Queue::assertNothingPushed();
    }

    // ─── Customer validation ──────────────────────────────────────────────────

    public function test_fails_when_customer_not_found(): void
    {
        $this->artisan('wiki:ingest-document', [
            '--customer'    => '99999',
            '--document-id' => '1',
        ])->assertFailed();

        Queue::assertNothingPushed();
    }

    // ─── Document validation ──────────────────────────────────────────────────

    public function test_fails_when_document_not_found(): void
    {
        $customer = $this->createCustomer();

        $this->artisan('wiki:ingest-document', [
            '--customer'    => (string) $customer->id,
            '--document-id' => '99999',
        ])->assertFailed();

        Queue::assertNothingPushed();
    }

    public function test_fails_when_document_belongs_to_other_customer(): void
    {
        $ownerCustomer = $this->createCustomer('Eier AS');
        $callerCustomer = $this->createCustomer('Annen AS');

        $document = $this->createDocument($ownerCustomer, EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED, 'Noe tekst.');

        $this->artisan('wiki:ingest-document', [
            '--customer'    => (string) $callerCustomer->id,
            '--document-id' => (string) $document->id,
        ])->assertFailed();

        Queue::assertNothingPushed();
    }

    public function test_fails_when_document_status_is_pending(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer, EnterpriseWikiDocument::DOCUMENT_STATUS_PENDING, null);

        $this->artisan('wiki:ingest-document', [
            '--customer'    => (string) $customer->id,
            '--document-id' => (string) $document->id,
        ])->assertFailed();

        Queue::assertNothingPushed();
    }

    public function test_fails_when_document_status_is_failed(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer, EnterpriseWikiDocument::DOCUMENT_STATUS_FAILED, null);

        $this->artisan('wiki:ingest-document', [
            '--customer'    => (string) $customer->id,
            '--document-id' => (string) $document->id,
        ])->assertFailed();

        Queue::assertNothingPushed();
    }

    public function test_fails_when_document_extracted_text_is_empty(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer, EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED, null);

        $this->artisan('wiki:ingest-document', [
            '--customer'    => (string) $customer->id,
            '--document-id' => (string) $document->id,
        ])->assertFailed();

        Queue::assertNothingPushed();
    }

    // ─── Dry-run ──────────────────────────────────────────────────────────────

    public function test_dry_run_does_not_create_ingest_run(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createExtractedDocument($customer);

        $this->artisan('wiki:ingest-document', [
            '--customer'    => (string) $customer->id,
            '--document-id' => (string) $document->id,
            '--dry-run'     => true,
        ])->assertSuccessful();

        $this->assertSame(0, EnterpriseWikiIngestRun::query()->count());
        Queue::assertNothingPushed();
    }

    // ─── Successful run creation ──────────────────────────────────────────────

    public function test_valid_document_creates_queued_run_with_correct_source_type(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createExtractedDocument($customer);

        $this->artisan('wiki:ingest-document', [
            '--customer'    => (string) $customer->id,
            '--document-id' => (string) $document->id,
        ])->assertSuccessful();

        $run = EnterpriseWikiIngestRun::query()
            ->where('customer_id', $customer->id)
            ->where('source_id', $document->id)
            ->first();

        $this->assertNotNull($run);
        $this->assertSame(EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT, $run->source_type);
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_QUEUED, $run->status);

        Queue::assertPushed(ProcessEnterpriseWikiIngest::class, 1);
    }

    public function test_run_source_hash_encodes_document_id_and_file_hash(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createExtractedDocument($customer);

        $this->artisan('wiki:ingest-document', [
            '--customer'    => (string) $customer->id,
            '--document-id' => (string) $document->id,
        ])->assertSuccessful();

        $run = EnterpriseWikiIngestRun::query()
            ->where('customer_id', $customer->id)
            ->where('source_id', $document->id)
            ->firstOrFail();

        $expectedHash = hash('sha256', "enterprise_wiki_document:{$document->id}:{$document->file_hash_sha256}");
        $this->assertSame($expectedHash, $run->source_hash);
    }

    // ─── --force / completed-run guard ───────────────────────────────────────

    public function test_existing_completed_run_blocks_without_force(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createExtractedDocument($customer);

        EnterpriseWikiIngestRun::query()->create([
            'uuid'         => (string) Str::uuid(),
            'customer_id'  => $customer->id,
            'source_type'  => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id'    => $document->id,
            'source_hash'  => str_pad('existing', 64, '0'),
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'status'       => EnterpriseWikiIngestRun::STATUS_COMPLETED,
        ]);

        $this->artisan('wiki:ingest-document', [
            '--customer'    => (string) $customer->id,
            '--document-id' => (string) $document->id,
        ])->assertSuccessful();

        // No new run created, no new job dispatched.
        $this->assertSame(1, EnterpriseWikiIngestRun::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_force_allows_new_run_when_completed_run_exists(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createExtractedDocument($customer);

        EnterpriseWikiIngestRun::query()->create([
            'uuid'         => (string) Str::uuid(),
            'customer_id'  => $customer->id,
            'source_type'  => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id'    => $document->id,
            'source_hash'  => str_pad('existing', 64, '0'),
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'status'       => EnterpriseWikiIngestRun::STATUS_COMPLETED,
        ]);

        $this->artisan('wiki:ingest-document', [
            '--customer'    => (string) $customer->id,
            '--document-id' => (string) $document->id,
            '--force'       => true,
        ])->assertSuccessful();

        $this->assertSame(2, EnterpriseWikiIngestRun::query()->count());
        Queue::assertPushed(ProcessEnterpriseWikiIngest::class, 1);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function createCustomer(string $name = 'Wiki Ingest Doc Test AS'): Customer
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
            'slug'             => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'language_id'      => $language->id,
            'nationality_id'   => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active'        => true,
        ]);
    }

    private function createDocument(
        Customer $customer,
        string $status,
        ?string $extractedText,
    ): EnterpriseWikiDocument {
        return EnterpriseWikiDocument::query()->create([
            'customer_id'       => $customer->id,
            'original_filename' => 'test.pdf',
            'file_path'         => sprintf('customers/%d/wiki-documents/%s.pdf', $customer->id, Str::random(8)),
            'file_hash_sha256'  => hash('sha256', Str::random(32)),
            'document_status'   => $status,
            'extracted_text'    => $extractedText,
        ]);
    }

    private function createExtractedDocument(Customer $customer): EnterpriseWikiDocument
    {
        return $this->createDocument(
            $customer,
            EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
            "## Kompetanse\nVi leverer ISO 9001-sertifisert tjeneste.",
        );
    }
}
