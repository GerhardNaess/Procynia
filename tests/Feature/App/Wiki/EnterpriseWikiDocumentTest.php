<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnterpriseWikiDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_can_be_created_for_customer(): void
    {
        $customer = $this->createCustomer();

        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'tjenestebeskrivelse.docx',
            'file_path' => 'wiki-documents/1/abc123.docx',
            'file_hash_sha256' => str_repeat('a', 64),
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_PENDING,
        ]);

        $this->assertSame($customer->id, $document->customer_id);
        $this->assertSame('tjenestebeskrivelse.docx', $document->original_filename);
        $this->assertSame(EnterpriseWikiDocument::DOCUMENT_STATUS_PENDING, $document->document_status);
        $this->assertNull($document->extracted_text);
        $this->assertNull($document->uploaded_by_user_id);
    }

    public function test_document_customer_relation_resolves(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);

        $this->assertTrue($document->customer->is($customer));
    }

    public function test_document_uploaded_by_relation_is_nullable(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer, uploadedBy: null);

        $this->assertNull($document->uploadedBy);
    }

    public function test_document_uploaded_by_relation_resolves_when_set(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $document = $this->createDocument($customer, uploadedBy: $user);

        $this->assertTrue($document->uploadedBy->is($user));
    }

    public function test_document_owner_relation_is_nullable(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer, owner: null);

        $this->assertNull($document->owner);
    }

    public function test_document_owner_relation_resolves_when_set(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer);
        $document = $this->createDocument($customer, owner: $owner);

        $this->assertTrue($document->owner->is($owner));
    }

    public function test_document_has_no_column_referencing_knowledge_items(): void
    {
        $document = new EnterpriseWikiDocument;
        $fillable = $document->getFillable();

        $forbidden = ['knowledge_item_id', 'knowledge_item_version_id', 'knowledge_item_chunk_id'];

        foreach ($forbidden as $column) {
            $this->assertNotContains(
                $column,
                $fillable,
                "EnterpriseWikiDocument must not reference {$column}",
            );
        }
    }

    public function test_ingest_run_has_enterprise_wiki_document_source_type_constant(): void
    {
        $this->assertSame(
            'enterprise_wiki_document',
            EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
        );

        $this->assertContains(
            EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            EnterpriseWikiIngestRun::SOURCE_TYPES,
        );
    }

    public function test_source_reference_has_enterprise_wiki_document_source_type_constant(): void
    {
        $this->assertSame(
            'enterprise_wiki_document',
            EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
        );

        $this->assertContains(
            EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            EnterpriseWikiSourceReference::SOURCE_TYPES,
        );
    }

    public function test_document_status_constants_cover_expected_lifecycle(): void
    {
        $this->assertSame('pending', EnterpriseWikiDocument::DOCUMENT_STATUS_PENDING);
        $this->assertSame('extracted', EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED);
        $this->assertSame('failed', EnterpriseWikiDocument::DOCUMENT_STATUS_FAILED);

        $this->assertCount(3, EnterpriseWikiDocument::DOCUMENT_STATUSES);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function createCustomer(): Customer
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
            'name' => 'Wiki Document Test '.Str::random(6),
            'slug' => 'wiki-doc-test-'.Str::lower(Str::random(8)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);
    }

    private function createUser(Customer $customer): User
    {
        return User::query()->create([
            'name' => 'Test Uploader',
            'email' => 'uploader-'.Str::random(6).'@example.com',
            'password' => bcrypt('secret'),
            'customer_id' => $customer->id,
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
        ]);
    }

    private function createDocument(Customer $customer, ?User $uploadedBy = null, ?User $owner = null): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'uploaded_by_user_id' => $uploadedBy?->id,
            'owner_user_id' => $owner?->id,
            'original_filename' => 'test.docx',
            'file_path' => 'wiki-documents/'.$customer->id.'/'.Str::random(16).'.docx',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_PENDING,
        ]);
    }
}
