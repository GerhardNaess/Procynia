<?php

namespace Tests\Unit\Services\EnterpriseWiki;

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
