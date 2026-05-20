<?php

namespace Tests\Unit\Services\Ai\Requirements;

use App\Models\Customer;
use App\Models\DocumentTemplate;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\Notice;
use App\Models\SavedNotice;
use App\Models\SavedNoticeAiRequirement;
use App\Services\Ai\Requirements\RequirementWordExportService;
use Illuminate\Support\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;
use ZipArchive;

class RequirementWordExportServiceTest extends TestCase
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

    public function test_it_uses_the_active_customer_template_when_available(): void
    {
        Storage::fake('local');

        $context = $this->createCustomer('Templatekunde AS');
        $savedNotice = $this->createSavedNotice($context->id, 'TEMPLATE-001', 'Template target');
        $this->createWordExportTemplate($context, 'kunde-template.docx', 'Kunde A header', 'Kunde A footer');

        $service = app(RequirementWordExportService::class);
        $docxBinary = $service->build($savedNotice, new Collection([
            $this->createRequirement('1.1', 'Første kravtekst.'),
        ]));

        $documentXml = $this->readDocumentXml($docxBinary);

        $this->assertStringContainsString('Kunde A header', $documentXml);
        $this->assertStringContainsString('Første kravtekst.', $documentXml);
        $this->assertStringNotContainsString('[[PROCYNIA_CONTENT]]', $documentXml);
    }

    public function test_it_does_not_use_another_customers_template(): void
    {
        Storage::fake('local');

        $owner = $this->createCustomer('Eierkunde AS');
        $other = $this->createCustomer('Annenkunde AS');
        $savedNotice = $this->createSavedNotice($owner->id, 'TEMPLATE-002', 'Owner target');

        $this->createWordExportTemplate($owner, 'owner-template.docx', 'Eierkundens header', 'Eierkundens footer');
        $this->createWordExportTemplate($other, 'other-template.docx', 'Andre kundes header', 'Andre kundes footer');

        $service = app(RequirementWordExportService::class);
        $docxBinary = $service->build($savedNotice, new Collection([
            $this->createRequirement('2.1', 'Kravtekst for eierkunde.'),
        ]));

        $documentXml = $this->readDocumentXml($docxBinary);

        $this->assertStringContainsString('Eierkundens header', $documentXml);
        $this->assertStringNotContainsString('Andre kundes header', $documentXml);
    }

    public function test_it_falls_back_to_standard_export_when_template_file_is_missing(): void
    {
        Storage::fake('local');

        $context = $this->createCustomer('Fallbackkunde AS');
        $savedNotice = $this->createSavedNotice($context->id, 'TEMPLATE-003', 'Fallback target');
        $template = $this->createWordExportTemplate($context, 'fallback-template.docx', 'Fallback header', 'Fallback footer');

        Storage::disk('local')->delete($template->file_path);

        $service = app(RequirementWordExportService::class);
        $docxBinary = $service->build($savedNotice, new Collection([
            $this->createRequirement('3.1', 'Fallback kravtekst.'),
        ]));

        $documentXml = $this->readDocumentXml($docxBinary);

        $this->assertStringContainsString('Fallback kravtekst.', $documentXml);
        $this->assertStringNotContainsString('Fallback header', $documentXml);
    }

    private function createRequirement(string $identifier, string $text): SavedNoticeAiRequirement
    {
        return (new SavedNoticeAiRequirement())->forceFill([
            'requirement_identifier' => $identifier,
            'requirement_text' => $text,
            'answer_draft_text' => 'Svarutkast',
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_MANDATORY,
            'answer_draft_retrieval_sources' => [],
        ]);
    }

    private function createSavedNotice(int $customerId, string $externalId, string $title): SavedNotice
    {
        $savedNotice = new SavedNotice();
        $savedNotice->forceFill([
            'id' => random_int(1000, 9999),
            'customer_id' => $customerId,
            'title' => $title,
            'deadline' => now()->addWeeks()->toDateTimeString(),
            'external_id' => $externalId,
        ]);
        $savedNotice->setRelation('notice', (new Notice())->forceFill([
            'contracting_body_name' => 'Procynia AS',
        ]));

        return $savedNotice;
    }

    private function createCustomer(string $name): Customer
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
            'is_active' => true,
        ]);
    }

    private function createWordExportTemplate(Customer $customer, string $filename, string $headerText, string $footerText): DocumentTemplate
    {
        $templateDocxPath = $this->createWordExportTemplateDocx($headerText, $footerText);
        $storedPath = sprintf(
            'document-templates/customer-%d/%s__%s',
            $customer->id,
            Str::ulid(),
            $filename,
        );

        Storage::disk('local')->put($storedPath, file_get_contents($templateDocxPath));

        if (is_file($templateDocxPath)) {
            unlink($templateDocxPath);
        }

        return DocumentTemplate::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Standard Word-mal',
            'description' => 'Brukes i eksporttesten.',
            'template_type' => DocumentTemplate::TEMPLATE_TYPE_WORD_EXPORT,
            'file_disk' => 'local',
            'file_path' => $storedPath,
            'original_filename' => $filename,
            'is_active' => true,
            'is_default' => true,
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);
    }

    private function createWordExportTemplateDocx(string $headerText, string $footerText): string
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        $section->addText($headerText);
        $section->addText('[[PROCYNIA_CONTENT]]');
        $section->addText($footerText);

        $tmpFile = tempnam(sys_get_temp_dir(), 'procynia_template_docx_');
        if ($tmpFile === false) {
            $this->fail('Unable to create a temporary template file.');
        }

        @unlink($tmpFile);
        $docxPath = $tmpFile.'.docx';

        IOFactory::createWriter($phpWord, 'Word2007')->save($docxPath);

        return $docxPath;
    }

    private function readDocumentXml(string $docxBinary): string
    {
        $path = tempnam(sys_get_temp_dir(), 'procynia_word_export_');

        if ($path === false) {
            $this->fail('Unable to create a temporary DOCX file.');
        }

        file_put_contents($path, $docxBinary);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true);

        $documentXml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        @unlink($path);

        $this->assertNotSame('', $documentXml);

        return $documentXml;
    }

    private function useProjectPostgresConnection(): void
    {
        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.database' => 'procynia',
            'database.connections.pgsql.host' => 'postgres',
            'database.connections.pgsql.port' => '5432',
            'database.connections.pgsql.username' => 'gehard',
            'database.connections.pgsql.password' => 'Opaque01',
            'database.connections.pgsql.search_path' => 'public',
        ]);

        DB::purge('pgsql');
        DB::setDefaultConnection('pgsql');
        DB::reconnect('pgsql');
    }
}
