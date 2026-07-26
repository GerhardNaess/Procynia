<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\Ai\Wiki\WikiPageClaimExtractionAiClient;
use App\Services\Ai\Wiki\WikiPageContentAiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

/**
 * End-to-end coverage for a simple, rectangular Word table flowing through Wiki page generation
 * and claim extraction: a genuine "table" content block is produced (never AI-paraphrased), and
 * deterministic per-cell claims carry exact row/column provenance. No real OpenAI calls — both
 * AI clients are mocked (see setUp()).
 */
class EnterpriseWikiTableIngestTest extends TestCase
{
    use RefreshDatabase;

    private const ARTICLE_MARKDOWN = "# Test Artikkel\n\nDette dokumentet beskriver tjenestekatalogen.";

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->mock(WikiPageClaimExtractionAiClient::class)
            ->shouldReceive('extractClaims')
            ->andReturn(['claims' => []])
            ->byDefault();
    }

    public function test_a_simple_table_becomes_a_genuine_table_block_when_its_rows_are_cited(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createTableDocument($customer);
        [$run, $article] = $this->createAppliedRunWithArticle($customer, $document);

        $this->mockAiClientCitingTableRow('tbl0-row0');

        Artisan::call('wiki:generate-applied-pages', ['--run-id' => $run->id]);

        $version = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $article->id)
            ->where('is_current', true)
            ->firstOrFail();

        $tableBlocks = collect($version->content_blocks_json)
            ->filter(fn (array $block): bool => ($block['block_type'] ?? null) === 'table')
            ->values();

        $this->assertCount(1, $tableBlocks);
        $tableBlock = $tableBlocks[0];

        $this->assertSame(['Tjeneste', 'Pris'], $tableBlock['table_data']['headers']);
        $this->assertCount(2, $tableBlock['table_data']['rows']);
        $this->assertSame('Administrert klient', $tableBlock['table_data']['rows'][0]['label']);
        $this->assertSame('£42', $tableBlock['table_data']['rows'][0]['cells'][1]['value']);

        // The original AI-authored prose block must still be present, unaffected.
        $this->assertStringContainsString('Dette dokumentet beskriver tjenestekatalogen.', $version->content_markdown);
        // The table's own Markdown fallback must also be present in the joined content_markdown.
        $this->assertStringContainsString('| Tjeneste | Pris |', $version->content_markdown);
    }

    public function test_no_table_block_is_added_when_no_table_row_was_cited(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createTableDocument($customer);
        [$run, $article] = $this->createAppliedRunWithArticle($customer, $document);

        // Cites the document's own lead paragraph ("Tjenestekatalog"), not any table row — a
        // real, valid source_element_key, just not a table_row one.
        $this->mockAiClientCitingTableRow('paragraph-0');

        Artisan::call('wiki:generate-applied-pages', ['--run-id' => $run->id]);

        $version = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $article->id)
            ->where('is_current', true)
            ->firstOrFail();

        $tableBlocks = collect($version->content_blocks_json)
            ->filter(fn (array $block): bool => ($block['block_type'] ?? null) === 'table');

        $this->assertCount(0, $tableBlocks);
    }

    public function test_table_rows_produce_deterministic_claims_with_precise_cell_provenance(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createTableDocument($customer);
        [$run, $article] = $this->createAppliedRunWithArticle($customer, $document);

        $this->mockAiClientCitingTableRow('tbl0-row0');
        Artisan::call('wiki:generate-applied-pages', ['--run-id' => $run->id]);
        Artisan::call('wiki:extract-page-claims', ['--run-id' => $run->id]);

        $claims = EnterpriseWikiClaim::query()
            ->where('enterprise_wiki_page_id', $article->id)
            ->where('confidence', EnterpriseWikiClaim::CONFIDENCE_HIGH)
            ->get();

        // 2 rows × 1 non-label column (Pris) each = 2 deterministic claims.
        $this->assertCount(2, $claims);

        $texts = $claims->pluck('claim_text')->all();
        $this->assertContains('Administrert klient: Pris er £42.', $texts);
        $this->assertContains('Standard support: Pris er £10.', $texts);

        $claim = $claims->firstWhere('claim_text', 'Administrert klient: Pris er £42.');
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED, $claim->content_origin);

        $reference = EnterpriseWikiSourceReference::query()
            ->where('enterprise_wiki_claim_id', $claim->id)
            ->firstOrFail();

        $this->assertSame('tbl0-row0', $reference->source_row_key);
        $this->assertSame('tbl0-row0-col1', $reference->source_cell_key);
        $this->assertSame('pris', $reference->source_column_key);
        $this->assertStringContainsString('Tabell 1', $reference->page_reference);
        $this->assertStringContainsString('Rad «Administrert klient»', $reference->page_reference);
        $this->assertStringContainsString('Kolonne «Pris»', $reference->page_reference);
    }

    public function test_re_extracting_claims_for_the_same_run_does_not_duplicate_table_claims(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createTableDocument($customer);
        [$run, $article] = $this->createAppliedRunWithArticle($customer, $document);

        $this->mockAiClientCitingTableRow('tbl0-row0');
        Artisan::call('wiki:generate-applied-pages', ['--run-id' => $run->id]);
        Artisan::call('wiki:extract-page-claims', ['--run-id' => $run->id]);

        $firstCount = EnterpriseWikiClaim::query()->where('enterprise_wiki_page_id', $article->id)->count();

        // Re-running extraction for the same (already-completed) run must be a no-op per the
        // existing claims_extracted_at checkpoint — table claims share that same checkpoint.
        Artisan::call('wiki:extract-page-claims', ['--run-id' => $run->id]);

        $secondCount = EnterpriseWikiClaim::query()->where('enterprise_wiki_page_id', $article->id)->count();

        $this->assertSame($firstCount, $secondCount);
        $this->assertGreaterThan(0, $firstCount);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function mockAiClientCitingTableRow(string $rowKey): void
    {
        $this->mock(WikiPageContentAiClient::class)
            ->shouldReceive('generatePageFromSource')
            ->andReturnUsing(fn (
                string $pageTitle,
                string $pageType,
                string $sourceText,
                string $languageCode,
                string $additionalContext = '',
                array $linkCatalog = [],
                array $sourceElements = [],
            ): array => [
                'markdown' => self::ARTICLE_MARKDOWN,
                'blocks' => [
                    [
                        'markdown' => self::ARTICLE_MARKDOWN,
                        'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                        'source_element_keys' => [$rowKey],
                        'best_practice_reason' => null,
                        'link_intents' => [],
                    ],
                ],
            ])
            ->byDefault();
    }

    private function mockAiClientCitingManualElement(int $documentId): void
    {
        $this->mock(WikiPageContentAiClient::class)
            ->shouldReceive('generatePageFromSource')
            ->andReturnUsing(fn (
                string $pageTitle,
                string $pageType,
                string $sourceText,
                string $languageCode,
                string $additionalContext = '',
                array $linkCatalog = [],
                array $sourceElements = [],
            ): array => [
                'markdown' => self::ARTICLE_MARKDOWN,
                'blocks' => [
                    [
                        'markdown' => self::ARTICLE_MARKDOWN,
                        'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                        'source_element_keys' => ['document-'.$documentId.'-full-text'],
                        'best_practice_reason' => null,
                        'link_intents' => [],
                    ],
                ],
            ])
            ->byDefault();
    }

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
            'name' => 'Testkunde Tabeller AS',
            'slug' => 'testkunde-tabeller-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    private function createTableDocument(Customer $customer): EnterpriseWikiDocument
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:body>
        <w:p><w:r><w:t>Tjenestekatalog</w:t></w:r></w:p>
        <w:tbl>
            <w:tr>
                <w:tc><w:p><w:r><w:t>Tjeneste</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>Pris</w:t></w:r></w:p></w:tc>
            </w:tr>
            <w:tr>
                <w:tc><w:p><w:r><w:t>Administrert klient</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>£42</w:t></w:r></w:p></w:tc>
            </w:tr>
            <w:tr>
                <w:tc><w:p><w:r><w:t>Standard support</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>£10</w:t></w:r></w:p></w:tc>
            </w:tr>
        </w:tbl>
    </w:body>
</w:document>
XML;

        $tmpPath = tempnam(sys_get_temp_dir(), 'wiki-table-').'.docx';
        $zip = new ZipArchive;
        $zip->open($tmpPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', $xml);
        $zip->close();

        $bytes = file_get_contents($tmpPath);
        unlink($tmpPath);

        $filePath = sprintf('customers/%d/wiki-documents/%s.docx', $customer->id, Str::random(8));
        Storage::disk('local')->put($filePath, $bytes);

        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'Tjenestebeskrivelse.docx',
            'file_path' => $filePath,
            'file_hash_sha256' => hash('sha256', $bytes),
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
            'extracted_text' => 'Tjenestekatalog Tjeneste Pris Administrert klient £42 Standard support £10',
        ]);
    }

    /**
     * @return array{0: EnterpriseWikiIngestRun, 1: EnterpriseWikiPage}
     */
    private function createAppliedRunWithArticle(Customer $customer, EnterpriseWikiDocument $document): array
    {
        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
        ]);

        $article = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'test-artikkel-'.Str::lower(Str::random(4)),
            'title' => 'Test Artikkel',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $article->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
        ]);

        return [$run, $article];
    }
}
