<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemChunk;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class KnowledgeBaseControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    public function test_knowledge_base_index_lists_uploaded_documents_for_the_current_customer(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer One AS');
        $foreignContext = $this->customerContext('Customer Two AS');

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('company-profile.docx', 'Company profile knowledge text with enough detail to excerpt.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_COMPANY,
            'is_active' => true,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $this->actingAs($foreignContext['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('foreign-profile.docx', 'Foreign customer content should stay hidden.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'is_active' => true,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $response = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.index'));

        $response->assertOk();
        $response->assertViewHas('page', function (array $page): bool {
            $items = collect(data_get($page, 'props.knowledgeItems', []));
            $item = $items->first();

            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Index'
                && data_get($page, 'props.pageTitle') === 'Kunnskapsdokumenter'
                && $items->count() === 1
                && $item !== null
                && $item['original_filename'] === 'company-profile.docx'
                && $item['content_excerpt'] !== ''
                && $item['chunk_count'] > 0
                && $item['extraction_status'] === KnowledgeItem::EXTRACTION_STATUS_COMPLETED
                && ! $items->contains(fn (array $candidate): bool => $candidate['original_filename'] === 'foreign-profile.docx');
        });
    }

    public function test_knowledge_base_create_page_can_be_opened(): void
    {
        $context = $this->customerContext('Customer Three AS');

        $response = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.create'));

        $response->assertOk();
        $response->assertViewHas('page', function (array $page): bool {
            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Create'
                && data_get($page, 'props.pageTitle') === 'Kunnskapsdokumenter · Last opp'
                && count(data_get($page, 'props.documentTypeOptions', [])) === 6
                && data_get($page, 'props.defaultDocumentType') === KnowledgeItem::DOCUMENT_TYPE_OTHER
                && data_get($page, 'props.storeUrl') === route('app.ai.knowledge-base.store')
                && data_get($page, 'props.indexUrl') === route('app.ai.knowledge-base.index');
        });
    }

    public function test_knowledge_document_upload_extracts_text_stores_file_and_chunks_document(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Four AS');
        $content = str_repeat('Reusable method text with enough length to force deterministic chunking. ', 26);

        $response = $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('method-description.docx', $content),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_METHOD,
            'is_active' => true,
        ]);

        $response->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('original_filename', 'method-description.docx')
            ->firstOrFail();

        $chunks = KnowledgeItemChunk::query()
            ->where('knowledge_item_id', $document->id)
            ->orderBy('chunk_index')
            ->get();
        $normalizedContent = $this->normalizeWhitespace($content);

        $this->assertStringStartsWith('customers/'.$context['customer']->id.'/knowledge-documents/', $document->storage_path);
        $this->assertTrue(Storage::disk('local')->exists($document->storage_path));
        $this->assertSame(KnowledgeItem::DOCUMENT_TYPE_METHOD, $document->document_type);
        $this->assertSame(KnowledgeItem::DOCUMENT_TYPE_METHOD, $document->content_type);
        $this->assertSame(KnowledgeItem::EXTRACTION_STATUS_COMPLETED, $document->extraction_status);
        $this->assertSame('', (string) $document->extraction_error);
        $this->assertSame($normalizedContent, $this->normalizeWhitespace((string) $document->extracted_text));
        $this->assertGreaterThan(0, $chunks->count());
        $this->assertSame(range(0, $chunks->count() - 1), $chunks->pluck('chunk_index')->all());
    }

    public function test_knowledge_document_upload_marks_failed_extraction_when_parsing_fails(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Five AS');

        $response = $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => UploadedFile::fake()->create(
                'broken.docx',
                128,
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'is_active' => true,
        ]);

        $response->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('original_filename', 'broken.docx')
            ->firstOrFail();

        $this->assertStringStartsWith('customers/'.$context['customer']->id.'/knowledge-documents/', $document->storage_path);
        $this->assertSame(KnowledgeItem::EXTRACTION_STATUS_FAILED, $document->extraction_status);
        $this->assertSame('', (string) $document->extracted_text);
        $this->assertNotEmpty((string) $document->extraction_error);
        $this->assertSame(0, KnowledgeItemChunk::query()->where('knowledge_item_id', $document->id)->count());
    }

    public function test_knowledge_base_edit_page_can_be_opened(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Six AS');
        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('reference-profile.docx', 'Reference project description used for AI knowledge.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'is_active' => true,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('original_filename', 'reference-profile.docx')
            ->firstOrFail();

        $response = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.edit', ['knowledgeItem' => $document->id]));

        $response->assertOk();
        $response->assertViewHas('page', function (array $page) use ($document): bool {
            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Edit'
                && data_get($page, 'props.pageTitle') === 'Kunnskapsdokumenter · Rediger'
                && data_get($page, 'props.knowledgeItem.id') === $document->id
                && data_get($page, 'props.knowledgeItem.original_filename') === 'reference-profile.docx'
                && data_get($page, 'props.knowledgeItem.extraction_status') === KnowledgeItem::EXTRACTION_STATUS_COMPLETED
                && data_get($page, 'props.indexUrl') === route('app.ai.knowledge-base.index');
        });
    }

    public function test_knowledge_document_update_allows_metadata_only_changes_and_keeps_chunks_intact(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Seven AS');
        $content = str_repeat('Boilerplate document content that will chunk deterministically. ', 20);

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('boilerplate.docx', $content),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_BOILERPLATE,
            'is_active' => true,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('original_filename', 'boilerplate.docx')
            ->firstOrFail();

        $initialChunkCount = KnowledgeItemChunk::query()
            ->where('knowledge_item_id', $document->id)
            ->count();

        $response = $this->actingAs($context['user'])->put(route('app.ai.knowledge-base.update', ['knowledgeItem' => $document->id]), [
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'is_active' => false,
        ]);

        $response->assertRedirect(route('app.ai.knowledge-base.index'));

        $updatedDocument = KnowledgeItem::query()->whereKey($document->id)->firstOrFail();
        $updatedChunkCount = KnowledgeItemChunk::query()
            ->where('knowledge_item_id', $updatedDocument->id)
            ->count();
        $normalizedContent = $this->normalizeWhitespace($content);

        $this->assertSame(KnowledgeItem::DOCUMENT_TYPE_OTHER, $updatedDocument->document_type);
        $this->assertSame(KnowledgeItem::DOCUMENT_TYPE_OTHER, $updatedDocument->content_type);
        $this->assertFalse((bool) $updatedDocument->is_active);
        $this->assertSame($normalizedContent, $this->normalizeWhitespace((string) $updatedDocument->extracted_text));
        $this->assertSame($initialChunkCount, $updatedChunkCount);
    }

    public function test_knowledge_document_destroy_removes_the_database_row_chunks_and_file(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Eight AS');
        $response = $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('delete-me.docx', 'Document that will be deleted.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'is_active' => true,
        ]);

        $response->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('original_filename', 'delete-me.docx')
            ->firstOrFail();

        $storedPath = $document->storage_path;

        $this->actingAs($context['user'])->delete(route('app.ai.knowledge-base.destroy', ['knowledgeItem' => $document->id]))
            ->assertRedirect(route('app.ai.knowledge-base.index'));

        $this->assertDatabaseMissing('knowledge_items', ['id' => $document->id]);
        $this->assertDatabaseMissing('knowledge_item_chunks', ['knowledge_item_id' => $document->id]);
        $this->assertTrue(Storage::disk('local')->missing($storedPath));
    }

    public function test_knowledge_document_routes_are_scoped_to_the_current_customer(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Nine AS');
        $foreignContext = $this->customerContext('Customer Ten AS');

        $this->actingAs($foreignContext['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('foreign.docx', 'Foreign customer knowledge.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'is_active' => true,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $foreignDocument = KnowledgeItem::query()
            ->where('customer_id', $foreignContext['customer']->id)
            ->where('original_filename', 'foreign.docx')
            ->firstOrFail();

        $this->actingAs($context['user'])
            ->get(route('app.ai.knowledge-base.edit', ['knowledgeItem' => $foreignDocument->id]))
            ->assertNotFound();
    }

    public function test_knowledge_document_store_rejects_invalid_file_type(): void
    {
        $context = $this->customerContext('Customer Eleven AS');

        $response = $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => UploadedFile::fake()->create('not-allowed.txt', 8, 'text/plain'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'is_active' => true,
        ]);

        $response->assertSessionHasErrors(['document']);
        $this->assertDatabaseCount('knowledge_items', 0);
    }

    public function test_knowledge_document_store_rejects_invalid_document_type(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Twelve AS');

        $response = $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('invalid-type.docx', 'Valid document text.'),
            'document_type' => 'invalid',
            'is_active' => true,
        ]);

        $response->assertSessionHasErrors(['document_type']);
        $this->assertDatabaseMissing('knowledge_items', [
            'customer_id' => $context['customer']->id,
            'original_filename' => 'invalid-type.docx',
        ]);
    }

    private function customerContext(string $customerName): array
    {
        static $sequence = 0;
        $sequence++;
        $code = str_pad(base_convert((string) $sequence, 10, 36), 2, '0', STR_PAD_LEFT);

        $language = Language::query()->create([
            'code' => $code,
            'name_en' => 'English',
            'name_no' => 'Engelsk',
        ]);
        $nationality = Nationality::query()->create([
            'code' => $code,
            'name_en' => 'Norwegian',
            'name_no' => 'Norsk',
            'flag_emoji' => '🇳🇴',
        ]);
        $customer = Customer::query()->create([
            'name' => $customerName,
            'slug' => Str::slug($customerName).'-'.Str::lower(Str::random(6)),
            'nationality_id' => $nationality->id,
            'language_id' => $language->id,
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'name' => $customerName.' User',
            'email' => Str::slug($customerName).'+user@example.test',
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        return [
            'customer' => $customer,
            'user' => $user,
        ];
    }

    /**
     * Purpose: Create a small DOCX fixture with extractable text for upload tests.
     * Inputs: The client filename and the raw text to embed in the document body.
     * Returns: A test uploaded file backed by a real DOCX archive.
     * Side effects: Writes a temporary ZIP file to the system temp directory.
     */
    private function createDocxUpload(string $filename, string $text): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'procynia-docx-');

        if ($path === false) {
            throw new RuntimeException('Unable to create a temporary DOCX file.');
        }

        $zip = new ZipArchive();
        $opened = $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($opened !== true) {
            throw new RuntimeException('Unable to create a DOCX archive for testing.');
        }

        $escapedText = htmlspecialchars($text, ENT_XML1 | ENT_COMPAT, 'UTF-8');
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:body>'
            .'<w:p><w:r><w:t>'.$escapedText.'</w:t></w:r></w:p>'
            .'</w:body>'
            .'</w:document>';

        $zip->addFromString('word/document.xml', $xml);
        $zip->close();

        return new UploadedFile(
            $path,
            $filename,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            null,
            true,
        );
    }

    /**
     * Purpose: Normalize text so test expectations match the extractor output.
     * Inputs: Raw text with arbitrary whitespace.
     * Returns: A whitespace-collapsed string suitable for exact comparisons.
     * Side effects: None.
     */
    private function normalizeWhitespace(string $text): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($text));

        return is_string($normalized) ? $normalized : trim($text);
    }

    private function useProjectPostgresConnection(): void
    {
        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.database' => 'procynia_test',
        ]);

        DB::purge('pgsql');
        DB::reconnect('pgsql');
    }
}
