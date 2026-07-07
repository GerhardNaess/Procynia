<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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

        $customer = $this->createCustomer('Eier AS');
        $other = $this->createCustomer('Fremmed AS');
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $this->actingAs($user)->post('/app/wiki/sources', [
            'file' => UploadedFile::fake()->create('scoped.pdf', 64, 'application/pdf'),
        ]);

        $this->assertSame(1, EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->count());
        $this->assertSame(0, EnterpriseWikiDocument::query()->where('customer_id', $other->id)->count());
    }

    // ─── Field assertions ─────────────────────────────────────────────────────

    public function test_document_status_is_pending_after_upload(): void
    {
        Storage::fake('local');

        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $this->actingAs($user)->post('/app/wiki/sources', [
            'file' => UploadedFile::fake()->create('status.pdf', 64, 'application/pdf'),
        ]);

        $doc = EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->firstOrFail();

        $this->assertSame(EnterpriseWikiDocument::DOCUMENT_STATUS_PENDING, $doc->document_status);
        $this->assertNull($doc->extracted_text);
    }

    public function test_uploaded_by_user_id_is_set_on_document(): void
    {
        Storage::fake('local');

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

        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        // First upload succeeds
        $file = UploadedFile::fake()->create('duplikat.pdf', 64, 'application/pdf');
        $this->actingAs($user)->post('/app/wiki/sources', ['file' => $file])->assertRedirect();

        // Second upload with same content is rejected
        $duplicate = UploadedFile::fake()->create('duplikat-kopi.pdf', 64, 'application/pdf');

        // Make the duplicate have the same hash by giving it the same real content
        // We need an identical file — create it via the same fake mechanism and copy content
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

    // ─── No knowledge_* models touched ───────────────────────────────────────

    public function test_no_knowledge_item_rows_are_created_during_upload(): void
    {
        Storage::fake('local');

        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $this->actingAs($user)->post('/app/wiki/sources', [
            'file' => UploadedFile::fake()->create('isolation.pdf', 64, 'application/pdf'),
        ]);

        $this->assertSame(0, \App\Models\KnowledgeItem::query()->where('customer_id', $customer->id)->count());
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

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
