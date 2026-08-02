<?php

namespace Tests\Unit\Services\EnterpriseWiki;

use App\Data\Ai\Requirements\DocxImageData;
use App\Data\Ai\Requirements\DocxTableCellData;
use App\Data\Ai\Requirements\DocxTableData;
use App\Data\Ai\Requirements\DocxTableRowData;
use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\DocumentTextExtractor;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentSourceElementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnterpriseWikiDocumentSourceElementServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_inspect_exposes_structured_text_elements_and_table_rows(): void
    {
        Storage::fake('local');

        $customer = $this->createCustomer();
        $document = $this->createDocument($customer, 'example.docx');
        Storage::disk('local')->put($document->file_path, 'docx-bytes');

        $expectedPath = Storage::disk('local')->path($document->file_path);

        $this->mock(DocumentTextExtractor::class, function ($mock) use ($expectedPath): void {
            $mock->shouldReceive('extractDocxTextAndTables')
                ->once()
                ->with($expectedPath)
                ->andReturn([
                    'text' => 'Paragraph text',
                    'tables' => [
                        new DocxTableData(
                            tableIndex: 0,
                            headerLabels: ['Header'],
                            rows: [
                                new DocxTableRowData(
                                    sourceRowKey: 'doc1-tbl0-row0',
                                    tableIndex: 0,
                                    rowIndex: 0,
                                    charStart: 12,
                                    charEnd: 24,
                                    cells: [
                                        new DocxTableCellData(
                                            columnIndex: 0,
                                            originalHeader: 'Header',
                                            normalizedColumnKey: 'header',
                                            value: 'Row value',
                                        ),
                                    ],
                                    sectionNumber: '1.1',
                                    sectionTitle: 'Section title',
                                ),
                            ],
                            sectionNumber: '1.1',
                            sectionTitle: 'Section title',
                        ),
                    ],
                    'headings' => [],
                    'list_items' => [],
                    'text_elements' => [
                        [
                            'element_key' => 'paragraph-0',
                            'element_type' => 'paragraph',
                            'text' => 'Paragraph text',
                            'number' => null,
                            'section_number' => '1.1',
                            'section_title' => 'Section title',
                            'char_start' => 0,
                            'char_end' => 14,
                        ],
                    ],
                ]);
            $mock->shouldReceive('extractDocxImages')
                ->once()
                ->with($expectedPath)
                ->andReturn([]);
        });

        $service = app(EnterpriseWikiDocumentSourceElementService::class);
        $result = $service->inspect($document);

        $this->assertTrue($result['supports_structured_elements']);
        $this->assertFalse($result['manual_source_allowed']);
        $this->assertCount(2, $result['elements']);
        $this->assertSame('paragraph-0', $result['elements'][0]['source_element_key']);
        $this->assertSame('paragraph', $result['elements'][0]['source_element_type']);
        $this->assertSame('doc1-tbl0-row0', $result['elements'][1]['source_element_key']);
        $this->assertSame('table_row', $result['elements'][1]['source_element_type']);
        $this->assertSame('doc1-tbl0-row0', $result['elements'][1]['source_row_key']);
    }

    public function test_tables_for_document_returns_the_raw_docx_table_data(): void
    {
        Storage::fake('local');

        $customer = $this->createCustomer();
        $document = $this->createDocument($customer, 'example.docx');
        Storage::disk('local')->put($document->file_path, 'docx-bytes');

        $expectedPath = Storage::disk('local')->path($document->file_path);
        $table = new DocxTableData(
            tableIndex: 0,
            headerLabels: ['Header'],
            rows: [
                new DocxTableRowData(
                    sourceRowKey: 'tbl0-row0',
                    tableIndex: 0,
                    rowIndex: 0,
                    charStart: 0,
                    charEnd: 10,
                    cells: [new DocxTableCellData(0, 'Header', 'header', 'Value')],
                ),
            ],
        );

        $this->mock(DocumentTextExtractor::class, function ($mock) use ($expectedPath, $table): void {
            $mock->shouldReceive('extractDocxTextAndTables')
                ->once()
                ->with($expectedPath)
                ->andReturn(['text' => '', 'tables' => [$table], 'headings' => [], 'list_items' => [], 'text_elements' => []]);
        });

        $service = app(EnterpriseWikiDocumentSourceElementService::class);
        $tables = $service->tablesForDocument($document);

        $this->assertCount(1, $tables);
        $this->assertSame('tbl0-row0', $tables[0]->rows[0]->sourceRowKey);
    }

    public function test_tables_for_document_returns_empty_for_non_docx_documents(): void
    {
        Storage::fake('local');

        $customer = $this->createCustomer();
        $document = $this->createDocument($customer, 'example.pdf');
        Storage::disk('local')->put($document->file_path, 'pdf-bytes');

        $service = app(EnterpriseWikiDocumentSourceElementService::class);

        $this->assertSame([], $service->tablesForDocument($document));
    }

    public function test_inspect_exposes_a_showable_image_as_a_citable_source_element(): void
    {
        Storage::fake('local');

        $customer = $this->createCustomer();
        $document = $this->createDocument($customer, 'example.docx');
        Storage::disk('local')->put($document->file_path, 'docx-bytes');

        $expectedPath = Storage::disk('local')->path($document->file_path);
        $image = $this->image(['caption' => 'Figur 1: Overordnet arkitektur', 'altText' => 'Arkitekturdiagram']);

        $this->mock(DocumentTextExtractor::class, function ($mock) use ($expectedPath, $image): void {
            $mock->shouldReceive('extractDocxTextAndTables')
                ->once()
                ->with($expectedPath)
                ->andReturn(['text' => '', 'tables' => [], 'headings' => [], 'list_items' => [], 'text_elements' => []]);
            $mock->shouldReceive('extractDocxImages')
                ->with($expectedPath)
                ->andReturn([$image]);
        });

        $service = app(EnterpriseWikiDocumentSourceElementService::class);
        $result = $service->inspect($document);

        $this->assertCount(1, $result['elements']);
        $this->assertSame('img0', $result['elements'][0]['source_element_key']);
        $this->assertSame('image', $result['elements'][0]['source_element_type']);
        $this->assertStringContainsString('Figur 1: Overordnet arkitektur', $result['elements'][0]['reference_text']);
        $this->assertSame($document->original_filename.' → Figur 1', $result['elements'][0]['page_reference']);
    }

    public function test_inspect_excludes_decorative_and_logo_images_from_citable_elements(): void
    {
        Storage::fake('local');

        $customer = $this->createCustomer();
        $document = $this->createDocument($customer, 'example.docx');
        Storage::disk('local')->put($document->file_path, 'docx-bytes');

        $expectedPath = Storage::disk('local')->path($document->file_path);
        $logo = $this->image(['sourceImageKey' => 'img0', 'imageIndex' => 0, 'width' => 20, 'height' => 20, 'caption' => null, 'altText' => null]);

        $this->mock(DocumentTextExtractor::class, function ($mock) use ($expectedPath, $logo): void {
            $mock->shouldReceive('extractDocxTextAndTables')
                ->once()
                ->with($expectedPath)
                ->andReturn(['text' => '', 'tables' => [], 'headings' => [], 'list_items' => [], 'text_elements' => []]);
            $mock->shouldReceive('extractDocxImages')
                ->with($expectedPath)
                ->andReturn([$logo]);
        });

        $service = app(EnterpriseWikiDocumentSourceElementService::class);
        $result = $service->inspect($document);

        $this->assertSame([], $result['elements']);
    }

    public function test_images_for_document_returns_the_raw_docx_image_data(): void
    {
        Storage::fake('local');

        $customer = $this->createCustomer();
        $document = $this->createDocument($customer, 'example.docx');
        Storage::disk('local')->put($document->file_path, 'docx-bytes');

        $expectedPath = Storage::disk('local')->path($document->file_path);
        $image = $this->image();

        $this->mock(DocumentTextExtractor::class, function ($mock) use ($expectedPath, $image): void {
            $mock->shouldReceive('extractDocxImages')
                ->once()
                ->with($expectedPath)
                ->andReturn([$image]);
        });

        $service = app(EnterpriseWikiDocumentSourceElementService::class);
        $images = $service->imagesForDocument($document);

        $this->assertCount(1, $images);
        $this->assertSame('img0', $images[0]->sourceImageKey);
    }

    public function test_image_by_source_key_resolves_a_specific_image_or_null(): void
    {
        Storage::fake('local');

        $customer = $this->createCustomer();
        $document = $this->createDocument($customer, 'example.docx');
        Storage::disk('local')->put($document->file_path, 'docx-bytes');

        $expectedPath = Storage::disk('local')->path($document->file_path);
        $imageA = $this->image(['sourceImageKey' => 'img0', 'imageIndex' => 0]);
        $imageB = $this->image(['sourceImageKey' => 'img1', 'imageIndex' => 1]);

        $this->mock(DocumentTextExtractor::class, function ($mock) use ($expectedPath, $imageA, $imageB): void {
            $mock->shouldReceive('extractDocxImages')
                ->with($expectedPath)
                ->andReturn([$imageA, $imageB]);
        });

        $service = app(EnterpriseWikiDocumentSourceElementService::class);

        $this->assertSame('img1', $service->imageBySourceKey($document, 'img1')->sourceImageKey);
        $this->assertNull($service->imageBySourceKey($document, 'img99'));
    }

    private function image(array $overrides = []): DocxImageData
    {
        return new DocxImageData(
            sourceImageKey: $overrides['sourceImageKey'] ?? 'img0',
            imageIndex: $overrides['imageIndex'] ?? 0,
            documentOrder: $overrides['documentOrder'] ?? 0,
            relationshipId: $overrides['relationshipId'] ?? 'rId1',
            originalMediaPath: $overrides['originalMediaPath'] ?? 'word/media/image1.png',
            mimeType: $overrides['mimeType'] ?? 'image/png',
            width: array_key_exists('width', $overrides) ? $overrides['width'] : 800,
            height: array_key_exists('height', $overrides) ? $overrides['height'] : 600,
            contentHash: $overrides['contentHash'] ?? 'hash-0',
            sectionNumber: $overrides['sectionNumber'] ?? null,
            sectionTitle: $overrides['sectionTitle'] ?? null,
            caption: array_key_exists('caption', $overrides) ? $overrides['caption'] : 'Figur: Eksempel',
            altText: array_key_exists('altText', $overrides) ? $overrides['altText'] : 'Alt-tekst',
            textBefore: $overrides['textBefore'] ?? null,
            textAfter: $overrides['textAfter'] ?? null,
            bytes: $overrides['bytes'] ?? 'bytes',
        );
    }

    public function test_inspect_falls_back_to_manual_source_for_non_docx_documents(): void
    {
        Storage::fake('local');

        $customer = $this->createCustomer();
        $document = $this->createDocument($customer, 'example.pdf');
        Storage::disk('local')->put($document->file_path, 'pdf-bytes');

        $service = app(EnterpriseWikiDocumentSourceElementService::class);
        $result = $service->inspect($document);

        $this->assertFalse($result['supports_structured_elements']);
        $this->assertTrue($result['manual_source_allowed']);
        $this->assertSame([], $result['elements']);
    }

    private function createCustomer(string $name = 'Testkunde AS'): Customer
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

    private function createDocument(Customer $customer, string $filename): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => $filename,
            'file_path' => sprintf('customers/%d/wiki-documents/%s', $customer->id, $filename),
            'file_hash_sha256' => hash('sha256', $filename.Str::random(8)),
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
            'extracted_text' => 'Testtekst for strukturert elementinspeksjon.',
        ]);
    }
}
