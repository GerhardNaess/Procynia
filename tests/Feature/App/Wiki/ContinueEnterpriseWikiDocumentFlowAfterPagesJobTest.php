<?php

namespace Tests\Feature\App\Wiki;

use App\Jobs\EnterpriseWiki\ContinueEnterpriseWikiDocumentFlowAfterPages;
use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\Language;
use App\Models\Nationality;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class ContinueEnterpriseWikiDocumentFlowAfterPagesJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_name_is_enterprise_wiki(): void
    {
        $job = new ContinueEnterpriseWikiDocumentFlowAfterPages(1);

        $this->assertSame('enterprise-wiki', $job->queue);
    }

    public function test_failed_marks_non_terminal_run_as_failed(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
        ]);

        (new ContinueEnterpriseWikiDocumentFlowAfterPages($run->id))->failed(new RuntimeException('boom'));

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_FAILED, $run->status);
        $this->assertSame('boom', $run->error_message);
        $this->assertNotNull($run->finished_at);
    }

    public function test_failed_does_not_override_an_already_terminal_run(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_COMPLETED,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'finished_at' => now(),
        ]);

        (new ContinueEnterpriseWikiDocumentFlowAfterPages($run->id))->failed(new RuntimeException('boom'));

        $this->assertSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $run->fresh()->status);
        $this->assertNull($run->fresh()->error_message);
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
}
