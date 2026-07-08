<?php

namespace Tests\Feature\App\Wiki;

use App\Jobs\Ai\Wiki\ProcessEnterpriseWikiIngest;
use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\DocumentTextExtractor;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class WikiSourceControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    // ─── Successful upload ────────────────────────────────────────────────────

    public function test_system_owner_can_upload_pdf_as_enterprise_wiki_document(): void
    {
        Storage::fake('local');
        $this->mockExtractorReturning('Tjenestebeskrivelse innhold.');

        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $response = $this->actingAs($user)->post('/app/wiki/sources', [
            'file' => UploadedFile::fake()->create('tjeneste.pdf', 256, 'application/pdf'),
        ]);

        $response->assertRedirect(route('app.wiki.index'));

        $this->assertSame(1, EnterpriseWikiDocument::query()
            ->where('customer_id', $customer->id)
            ->count());
    }

    public function test_system_owner_can_upload_docx_as_enterprise_wiki_document(): void
    {
        Storage::fake('local');
        $this->mockExtractorReturning('Kompetansebeskrivelse innhold.');

        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $response = $this->actingAs($user)->post('/app/wiki/sources', [
            'file' => UploadedFile::fake()->create(
                'kompetanse.docx',
                128,
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ),
        ]);

        $response->assertRedirect(route('app.wiki.index'));

        $this->assertSame(1, EnterpriseWikiDocument::query()
            ->where('customer_id', $customer->id)
            ->count());
    }

    // ─── Customer scoping ─────────────────────────────────────────────────────

    public function test_uploaded_document_is_scoped_to_uploading_customer(): void
    {
        Storage::fake('local');
        $this->mockExtractorReturning('Innhold.');

        $customer = $this->createCustomer('Eier AS');
        $other = $this->createCustomer('Fremmed AS');
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $this->actingAs($user)->post('/app/wiki/sources', [
            'file' => UploadedFile::fake()->create('scoped.pdf', 64, 'application/pdf'),
        ]);

        $this->assertSame(1, EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->count());
        $this->assertSame(0, EnterpriseWikiDocument::query()->where('customer_id', $other->id)->count());
    }

    // ─── Extraction: success ──────────────────────────────────────────────────

    public function test_document_status_is_extracted_when_extraction_succeeds(): void
    {
        Storage::fake('local');
        $this->mockExtractorReturning('Kapittel 1. Kompetanse og erfaring fra norske virksomheter.');

        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $this->actingAs($user)->post('/app/wiki/sources', [
            'file' => UploadedFile::fake()->create('status.pdf', 64, 'application/pdf'),
        ]);

        $doc = EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->firstOrFail();

        $this->assertSame(EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED, $doc->document_status);
    }

    public function test_extracted_text_is_saved_when_extraction_succeeds(): void
    {
        Storage::fake('local');
        $expectedText = 'Kapittel 1. Tjenestebeskrivelse for norske virksomheter.';
        $this->mockExtractorReturning($expectedText);

        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $this->actingAs($user)->post('/app/wiki/sources', [
            'file' => UploadedFile::fake()->create('tekst.pdf', 64, 'application/pdf'),
        ]);

        $doc = EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->firstOrFail();

        $this->assertSame($expectedText, $doc->extracted_text);
    }

    // ─── Extraction: failure ──────────────────────────────────────────────────

    public function test_document_status_is_failed_when_extraction_returns_empty(): void
    {
        Storage::fake('local');
        $this->mockExtractorReturning('');

        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $this->actingAs($user)->post('/app/wiki/sources', [
            'file' => UploadedFile::fake()->create('tom.pdf', 64, 'application/pdf'),
        ]);

        $doc = EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->firstOrFail();

        $this->assertSame(EnterpriseWikiDocument::DOCUMENT_STATUS_FAILED, $doc->document_status);
    }

    public function test_extracted_text_is_null_when_extraction_returns_empty(): void
    {
        Storage::fake('local');
        $this->mockExtractorReturning('');

        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $this->actingAs($user)->post('/app/wiki/sources', [
            'file' => UploadedFile::fake()->create('tom.pdf', 64, 'application/pdf'),
        ]);

        $doc = EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->firstOrFail();

        $this->assertNull($doc->extracted_text);
    }

    public function test_no_document_is_left_as_pending_after_store(): void
    {
        Storage::fake('local');
        $this->mockExtractorReturning('Noe tekst.');

        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $this->actingAs($user)->post('/app/wiki/sources', [
            'file' => UploadedFile::fake()->create('check.pdf', 64, 'application/pdf'),
        ]);

        $pendingCount = EnterpriseWikiDocument::query()
            ->where('customer_id', $customer->id)
            ->where('document_status', EnterpriseWikiDocument::DOCUMENT_STATUS_PENDING)
            ->count();

        $this->assertSame(0, $pendingCount);
    }

    // ─── Field assertions ─────────────────────────────────────────────────────

    public function test_uploaded_by_user_id_is_set_on_document(): void
    {
        Storage::fake('local');
        $this->mockExtractorReturning('Innhold.');

        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $this->actingAs($user)->post('/app/wiki/sources', [
            'file' => UploadedFile::fake()->create('uploader.pdf', 64, 'application/pdf'),
        ]);

        $doc = EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->firstOrFail();

        $this->assertSame($user->id, $doc->uploaded_by_user_id);
    }

    public function test_file_hash_sha256_is_set_on_document(): void
    {
        Storage::fake('local');
        $this->mockExtractorReturning('Innhold.');

        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $file = UploadedFile::fake()->create('hash-check.pdf', 64, 'application/pdf');
        $expectedHash = hash_file('sha256', $file->getRealPath());

        $this->actingAs($user)->post('/app/wiki/sources', ['file' => $file]);

        $doc = EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->firstOrFail();

        $this->assertSame($expectedHash, $doc->file_hash_sha256);
        $this->assertSame(64, strlen($doc->file_hash_sha256));
    }

    public function test_original_filename_is_preserved(): void
    {
        Storage::fake('local');
        $this->mockExtractorReturning('Innhold.');

        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $this->actingAs($user)->post('/app/wiki/sources', [
            'file' => UploadedFile::fake()->create('min-fil-navn.pdf', 64, 'application/pdf'),
        ]);

        $doc = EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->firstOrFail();

        $this->assertSame('min-fil-navn.pdf', $doc->original_filename);
    }

    public function test_file_is_stored_under_wiki_documents_path(): void
    {
        Storage::fake('local');
        $this->mockExtractorReturning('Innhold.');

        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $this->actingAs($user)->post('/app/wiki/sources', [
            'file' => UploadedFile::fake()->create('path-check.pdf', 64, 'application/pdf'),
        ]);

        $doc = EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->firstOrFail();

        $this->assertStringStartsWith(
            sprintf('customers/%d/wiki-documents/', $customer->id),
            $doc->file_path,
        );

        Storage::disk('local')->assertExists($doc->file_path);
    }

    // ─── Deduplication ────────────────────────────────────────────────────────

    public function test_same_file_for_same_customer_is_rejected_as_duplicate(): void
    {
        Storage::fake('local');
        $this->mockExtractorReturning('Innhold.');

        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $file = UploadedFile::fake()->create('duplikat.pdf', 64, 'application/pdf');
        $this->actingAs($user)->post('/app/wiki/sources', ['file' => $file])->assertRedirect();

        $firstDoc = EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->firstOrFail();

        $response = $this->actingAs($user)->post('/app/wiki/sources', [
            'file' => UploadedFile::fake()->createWithContent(
                'duplikat-same-hash.pdf',
                (string) file_get_contents(Storage::disk('local')->path($firstDoc->file_path)),
            ),
        ]);

        $response->assertSessionHasErrors('file');
        $this->assertSame(1, EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->count());
    }

    public function test_same_file_for_different_customer_is_allowed(): void
    {
        Storage::fake('local');
        $this->mockExtractorReturning('Innhold.');

        $customerA = $this->createCustomer('Kunde A AS');
        $customerB = $this->createCustomer('Kunde B AS');
        $userA = $this->createUser($customerA, User::BID_ROLE_SYSTEM_OWNER);
        $userB = $this->createUser($customerB, User::BID_ROLE_SYSTEM_OWNER);

        $content = Str::random(256);

        $this->actingAs($userA)->post('/app/wiki/sources', [
            'file' => UploadedFile::fake()->createWithContent('same.pdf', $content),
        ])->assertRedirect();

        $this->actingAs($userB)->post('/app/wiki/sources', [
            'file' => UploadedFile::fake()->createWithContent('same.pdf', $content),
        ])->assertRedirect();

        $this->assertSame(1, EnterpriseWikiDocument::query()->where('customer_id', $customerA->id)->count());
        $this->assertSame(1, EnterpriseWikiDocument::query()->where('customer_id', $customerB->id)->count());
    }

    // ─── Validation ───────────────────────────────────────────────────────────

    public function test_invalid_mime_type_is_rejected(): void
    {
        Storage::fake('local');

        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $response = $this->actingAs($user)->post('/app/wiki/sources', [
            'file' => UploadedFile::fake()->create('script.txt', 8, 'text/plain'),
        ]);

        $response->assertSessionHasErrors('file');
        $this->assertSame(0, EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->count());
    }

    public function test_missing_file_is_rejected(): void
    {
        Storage::fake('local');

        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $response = $this->actingAs($user)->post('/app/wiki/sources', []);

        $response->assertSessionHasErrors('file');
    }

    // ─── Ingest action ────────────────────────────────────────────────────────

    public function test_system_owner_can_start_ingest_for_extracted_document(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $document = $this->createExtractedDocument($customer);

        $response = $this->actingAs($user)->post("/app/wiki/sources/{$document->id}/ingest");

        $response->assertRedirect(route('app.wiki.index'));
        $response->assertSessionHas('success');
        Queue::assertPushed(ProcessEnterpriseWikiIngest::class);
    }

    public function test_ingest_rejects_other_customer_document(): void
    {
        Queue::fake();

        $customer = $this->createCustomer('Eigen kunde');
        $other = $this->createCustomer('Annen kunde');
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $foreignDoc = $this->createExtractedDocument($other);

        $response = $this->actingAs($user)->post("/app/wiki/sources/{$foreignDoc->id}/ingest");

        $response->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_ingest_rejects_pending_document(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $document = $this->createDocument($customer, EnterpriseWikiDocument::DOCUMENT_STATUS_PENDING);

        $response = $this->actingAs($user)->post("/app/wiki/sources/{$document->id}/ingest");

        $response->assertRedirect(route('app.wiki.index'));
        $response->assertSessionHas('error');
        Queue::assertNothingPushed();
    }

    public function test_ingest_rejects_failed_document(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $document = $this->createDocument($customer, EnterpriseWikiDocument::DOCUMENT_STATUS_FAILED);

        $response = $this->actingAs($user)->post("/app/wiki/sources/{$document->id}/ingest");

        $response->assertRedirect(route('app.wiki.index'));
        $response->assertSessionHas('error');
        Queue::assertNothingPushed();
    }

    // ─── No knowledge_* models touched ───────────────────────────────────────

    public function test_no_knowledge_item_rows_are_created_during_upload(): void
    {
        Storage::fake('local');
        $this->mockExtractorReturning('Innhold.');

        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $this->actingAs($user)->post('/app/wiki/sources', [
            'file' => UploadedFile::fake()->create('isolation.pdf', 64, 'application/pdf'),
        ]);

        $this->assertSame(0, \App\Models\KnowledgeItem::query()->where('customer_id', $customer->id)->count());
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function createDocument(Customer $customer, string $status): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'test.pdf',
            'file_path' => sprintf('customers/%d/wiki-documents/%s.pdf', $customer->id, Str::random(8)),
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'document_status' => $status,
            'extracted_text' => $status === EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED
                ? 'Testtekst for ingest.'
                : null,
        ]);
    }

    private function createExtractedDocument(Customer $customer): EnterpriseWikiDocument
    {
        return $this->createDocument($customer, EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED);
    }

    private function mockExtractorReturning(string $text): void
    {
        $this->mock(DocumentTextExtractor::class, function ($mock) use ($text): void {
            $mock->shouldReceive('extractText')->andReturn($text);
        });
    }

    private function createCustomer(string $name = 'Wiki Source Test AS'): Customer
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

    private function createUser(Customer $customer, string $bidRole): User
    {
        return User::query()->create([
            'name' => 'Wiki Uploader',
            'email' => Str::lower(Str::random(8)).'@wiki-test.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }
}
