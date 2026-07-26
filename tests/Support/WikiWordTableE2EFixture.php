<?php

namespace Tests\Support;

use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentSourceElementService;
use App\Services\EnterpriseWiki\EnterpriseWikiTableBlockBuilder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Test-only fixture for the Word table rendering E2E spec (tests/e2e/wiki-word-table.spec.js).
 * Not autoloaded in production (autoload-dev only), not a registered Artisan command — invoked
 * via `php artisan tinker --execute=...` from the Playwright spec, mirroring
 * WikiGraphFilterScaleTestFixture.
 *
 * Seeds a real .docx document with a controlled table and builds its page content
 * deterministically (via the real EnterpriseWikiTableBlockBuilder, the same class production
 * ingest uses), so the rendering/citation can be verified in a real browser without a real
 * OpenAI call for the whole ingest pipeline.
 */
class WikiWordTableE2EFixture
{
    private const PAGE_SLUG = 'e2e-word-table-verifisering';

    private const FILE_PATH_PREFIX = 'e2e-word-table/';

    public static function seed(int $customerId): string
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:body>
        <w:p><w:r><w:t>Denne siden beskriver tjenestekatalogen for klientdrift.</w:t></w:r></w:p>
        <w:tbl>
            <w:tr>
                <w:tc><w:p><w:r><w:t>Tjeneste</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>SLA</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>Pris</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>Beskrivelse</w:t></w:r></w:p></w:tc>
            </w:tr>
            <w:tr>
                <w:tc><w:p><w:r><w:t>Administrert klient</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>99,5 %</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>kr 420</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>Fullstendig drift og overvåkning, inkludert patching, sikkerhetskopiering og brukerstøtte. Æøå-tegn testes her også.</w:t></w:r></w:p></w:tc>
            </w:tr>
            <w:tr>
                <w:tc><w:p><w:r><w:t>Standard support</w:t></w:r></w:p></w:tc>
                <w:tc><w:p></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>kr 100</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>Grunnleggende brukerstøtte uten avtalt SLA.</w:t></w:r></w:p></w:tc>
            </w:tr>
        </w:tbl>
    </w:body>
</w:document>
XML;

        $tmpPath = tempnam(sys_get_temp_dir(), 'e2e-word-table-').'.docx';
        $zip = new ZipArchive;
        $zip->open($tmpPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', $xml);
        $zip->close();
        $bytes = file_get_contents($tmpPath);
        unlink($tmpPath);

        $filePath = self::FILE_PATH_PREFIX.Str::random(8).'.docx';
        Storage::disk('local')->put($filePath, $bytes);

        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customerId,
            'original_filename' => 'Tjenestebeskrivelse (E2E).docx',
            'file_path' => $filePath,
            'file_hash_sha256' => hash('sha256', $bytes),
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
            'extracted_text' => 'E2E-verifikasjonsdokument for Word-tabellstøtte.',
        ]);

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customerId,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_APPLYING,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
        ]);

        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customerId,
            'slug' => self::PAGE_SLUG,
            'title' => 'Tjenestekatalog (E2E)',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_APPROVED,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
        ]);

        $sourceElementService = app(EnterpriseWikiDocumentSourceElementService::class);
        $tableBlockBuilder = app(EnterpriseWikiTableBlockBuilder::class);

        $tables = $sourceElementService->tablesForDocument($document);
        $tableBlocks = $tableBlockBuilder->buildTableBlocks($document, $tables, [0], 1);

        $proseBlock = [
            'block_key' => 'block-0001',
            'position' => 0,
            'markdown' => "# Tjenestekatalog (E2E)\n\nDenne siden beskriver tjenestekatalogen for klientdrift.",
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_label' => $document->original_filename,
            'source_hash' => $document->file_hash_sha256,
            'document_version_hash' => $document->file_hash_sha256,
            'source_element_key' => 'paragraph-0',
            'source_element_type' => EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_PARAGRAPH,
            'source_row_key' => null,
            'source_excerpt' => 'Denne siden beskriver tjenestekatalogen...',
            'page_reference' => 'Avsnitt 1',
            'source_elements' => [],
            'best_practice_reason' => null,
            'link_intents' => [],
        ];

        $contentBlocks = [$proseBlock, ...$tableBlocks];
        $markdown = trim($proseBlock['markdown']."\n\n".implode("\n\n", array_column($tableBlocks, 'markdown')));

        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => $markdown,
            'content_blocks_json' => $contentBlocks,
            'generated_by_model' => 'e2e-word-table-fixture',
        ]);

        $position = 0;

        foreach ($tableBlocks as $tableBlock) {
            foreach ($tableBlockBuilder->tableClaimPayloads($document, $tableBlock) as $payload) {
                $claim = EnterpriseWikiClaim::query()->create([
                    'enterprise_wiki_page_id' => $page->id,
                    'enterprise_wiki_page_version_id' => $version->id,
                    'claim_text' => $payload['claim_text'],
                    'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                    'page_excerpt' => $payload['excerpt'],
                    'content_block_key' => $tableBlock['block_key'],
                    'position_order' => $position++,
                    'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
                    'conflict_flag' => false,
                    'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
                ]);

                EnterpriseWikiSourceReference::query()->create([
                    'enterprise_wiki_claim_id' => $claim->id,
                    'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
                    'source_id' => $document->id,
                    'source_element_key' => $payload['source_row_key'],
                    'source_element_type' => EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_TABLE_ROW,
                    'source_row_key' => $payload['source_row_key'],
                    'source_cell_key' => $payload['source_cell_key'],
                    'source_column_key' => $payload['source_column_key'],
                    'source_label' => $document->original_filename,
                    'excerpt' => $payload['excerpt'],
                    'source_hash' => $document->file_hash_sha256,
                    'page_reference' => $payload['page_reference'],
                ]);
            }
        }

        return self::PAGE_SLUG;
    }

    public static function cleanup(int $customerId): void
    {
        $pages = EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->where('slug', self::PAGE_SLUG)
            ->get();

        foreach ($pages as $page) {
            EnterpriseWikiClaim::query()->where('enterprise_wiki_page_id', $page->id)->delete();
            EnterpriseWikiIngestRunPage::query()->where('enterprise_wiki_page_id', $page->id)->delete();
            EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $page->id)->delete();
            $page->delete();
        }

        $documents = EnterpriseWikiDocument::query()
            ->where('customer_id', $customerId)
            ->where('file_path', 'like', self::FILE_PATH_PREFIX.'%')
            ->get();

        foreach ($documents as $document) {
            EnterpriseWikiIngestRun::query()
                ->where('customer_id', $customerId)
                ->where('source_type', EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT)
                ->where('source_id', $document->id)
                ->delete();
            Storage::disk('local')->delete($document->file_path);
            $document->delete();
        }
    }
}
