<?php

namespace Tests\Feature\App;

use App\Data\Ai\Requirements\Excel\WorkbookFieldRoleData;
use App\Data\Ai\Requirements\Excel\WorkbookSheetSchemaData;
use App\Data\Ai\Requirements\RequirementExtractionCandidateData;
use App\Models\Customer;
use App\Models\RequirementExtractionRun;
use App\Models\SavedNotice;
use App\Models\SavedNoticeAiDocument;
use App\Models\User;
use App\Services\Ai\Requirements\Excel\WorkbookStructureDiscoveryAiClient;
use App\Services\Ai\Requirements\RequirementExtractionRunService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\Support\XlsxFixtureBuilder;
use Tests\TestCase;

/**
 * Excel upload as production sees it: same endpoint, same document row, same extraction run, same
 * requirement table — only the provenance differs.
 *
 * Structure discovery is mocked; the requirement extraction run is mocked at its entry point so
 * these tests stay about the import path rather than about the AI extraction that follows it. No
 * live API calls.
 */
class XlsxRequirementImportTest extends TestCase
{
    use CreatesEnterpriseWikiFixtures;
    use UsesProjectPostgresConnection;

    private XlsxFixtureBuilder $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        DB::beginTransaction();
        Storage::fake('local');
        config(['services.enterprise_wiki.ai_enabled' => true]);
        $this->fixtures = new XlsxFixtureBuilder;
    }

    protected function tearDown(): void
    {
        $this->fixtures->cleanup();

        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function context(): array
    {
        $customer = $this->createWikiCustomer('Excel Import Test AS');
        $customer->forceFill([
            'subscription_plan' => Customer::PLAN_PRO,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'included_ai_credits' => 50,
        ])->save();

        $user = User::factory()->create([
            'name' => 'Excel Tester',
            'email' => 'excel.'.Str::lower(Str::random(8)).'@example.test',
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        $savedNotice = SavedNotice::query()->create([
            'customer_id' => $customer->id,
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
            'source_type' => SavedNotice::SOURCE_TYPE_PUBLIC_NOTICE,
            'external_id' => 'EXCEL-'.Str::upper(Str::random(8)),
            'title' => 'Excel-import testsak',
            'buyer_name' => 'Procynia',
            'external_url' => 'https://doffin.no/notices/excel-import-test',
            'summary' => 'Kort oppsummering',
            'publication_date' => '2026-03-20 00:00:00',
            'deadline' => '2026-12-31 00:00:00',
            'status' => 'ACTIVE',
            'cpv_code' => '72000000',
        ]);

        return ['customer' => $customer, 'user' => $user, 'saved_notice' => $savedNotice];
    }

    /** ID | Krav | Skal/Bør | Vekt | Svar | Kommentar */
    private function matrixWorkbook(): string
    {
        return $this->fixtures->build([
            'Kravspesifikasjon' => [
                ['ID', 'Krav', 'Skal/Bør', 'Vekt', 'Svar', 'Kommentar'],
                ['K-1', 'Løsningen skal støtte SSO mot Entra ID.', 'Skal', '30', 'Ja, dette støttes fullt ut.', 'Se vedlegg 2'],
                ['K-2', 'Løsningen bør støtte SCIM-provisjonering.', 'Bør', '10', null, null],
            ],
        ]);
    }

    private function matrixDiscovery(array $sheetOverrides = []): array
    {
        return [
            'requirement_sheets' => [array_merge([
                'sheet_index' => 0,
                'sheet_name' => 'Kravspesifikasjon',
                'header_range' => 'A1:F1',
                'data_range' => 'A2:F3',
                'logical_unit_strategy' => WorkbookSheetSchemaData::UNIT_ROW,
                'grouping_column_letter' => null,
                'section_row_numbers' => [],
                'field_roles' => [
                    ['column_letter' => 'A', 'role' => WorkbookFieldRoleData::ROLE_REQUIREMENT_ID, 'header_label' => 'ID', 'confidence' => 0.9],
                    ['column_letter' => 'B', 'role' => WorkbookFieldRoleData::ROLE_REQUIREMENT_TEXT, 'header_label' => 'Krav', 'confidence' => 0.95],
                    ['column_letter' => 'C', 'role' => WorkbookFieldRoleData::ROLE_QUALIFICATION, 'header_label' => 'Skal/Bør', 'confidence' => 0.9],
                    ['column_letter' => 'D', 'role' => WorkbookFieldRoleData::ROLE_WEIGHTING, 'header_label' => 'Vekt', 'confidence' => 0.9],
                    ['column_letter' => 'E', 'role' => WorkbookFieldRoleData::ROLE_RESPONSE, 'header_label' => 'Svar', 'confidence' => 0.9],
                    ['column_letter' => 'F', 'role' => WorkbookFieldRoleData::ROLE_COMMENT, 'header_label' => 'Kommentar', 'confidence' => 0.8],
                ],
                'warnings' => [],
                'confidence' => 0.9,
                'reason' => 'One requirement per row.',
            ], $sheetOverrides)],
            'supporting_sheets' => [],
            'warnings' => [],
            'confidence' => 0.9,
        ];
    }

    private function mockDiscovery(array $discovery, int $times = 1): void
    {
        $this->mock(WorkbookStructureDiscoveryAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('discoverStructure')->times($times)->andReturn([
                'discovery' => $discovery,
                'metrics' => ['latency_ms' => 5, 'input_tokens' => 10, 'output_tokens' => 5, 'orientation_chars' => 500],
            ]));
    }

    /** The extraction run is the pipeline's entry point; stubbing it keeps these tests on the import path. */
    private function spyExtractionRun(): void
    {
        $this->mock(RequirementExtractionRunService::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('createQueuedRunForDocument')
            ->andReturnUsing(static function (SavedNoticeAiDocument $document): RequirementExtractionRun {
                // Unsaved on purpose: the controller only reads ->uuid, and the extraction run
                // itself is not what these tests are about.
                return new RequirementExtractionRun(['uuid' => 'run-'.$document->id]);
            }));
    }

    private function upload(array $context, string $path, string $name = 'Kravspesifikasjon.xlsx')
    {
        return $this->actingAs($context['user'])->post(
            "/app/ai/{$context['saved_notice']->id}/documents",
            ['documents' => [new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true)]],
        );
    }

    // ── the happy path ───────────────────────────────────────────────────────

    public function test_an_excel_upload_creates_the_same_document_row_and_extraction_run_as_word(): void
    {
        $context = $this->context();
        $this->mockDiscovery($this->matrixDiscovery());
        $this->spyExtractionRun();

        $this->upload($context, $this->matrixWorkbook())->assertRedirect();

        $document = SavedNoticeAiDocument::query()->where('saved_notice_id', $context['saved_notice']->id)->firstOrFail();

        $this->assertSame('Kravspesifikasjon.xlsx', $document->original_filename);
        $this->assertSame(SavedNoticeAiDocument::PROCESSING_STATUS_TEXT_EXTRACTED, $document->processing_status);
        $this->assertNotSame('', trim((string) $document->extracted_text));
        $this->assertGreaterThan(0, $document->chunks()->count());
    }

    public function test_excel_no_longer_goes_through_the_flat_text_fallback(): void
    {
        $context = $this->context();
        $this->mockDiscovery($this->matrixDiscovery());
        $this->spyExtractionRun();

        $this->upload($context, $this->matrixWorkbook());

        $document = SavedNoticeAiDocument::query()->where('saved_notice_id', $context['saved_notice']->id)->firstOrFail();

        // The old fallback produced one undifferentiated blob with no coordinates in it at all.
        $this->assertStringContainsString('Kravspesifikasjon!A2:F2', (string) $document->extracted_text);
        $this->assertCount(2, $document->structured_text_elements);
    }

    public function test_each_logical_requirement_becomes_one_traceable_structured_element(): void
    {
        $context = $this->context();
        $this->mockDiscovery($this->matrixDiscovery());
        $this->spyExtractionRun();

        $this->upload($context, $this->matrixWorkbook());

        $elements = SavedNoticeAiDocument::query()
            ->where('saved_notice_id', $context['saved_notice']->id)
            ->firstOrFail()
            ->structured_text_elements;

        $this->assertSame('sheet_range', $elements[0]['element_type']);
        $this->assertStringContainsString('sheet:0:range:A2:F2', $elements[0]['element_key']);
        $this->assertSame('Løsningen skal støtte SSO mot Entra ID.', $elements[0]['text']);
        $this->assertSame('K-1', $elements[0]['number']);
        $this->assertSame('Kravspesifikasjon!A2:F2', $elements[0]['source_metadata']['source_label']);
        $this->assertSame('Skal', $elements[0]['source_metadata']['source_qualification']);
        $this->assertSame('30', $elements[0]['source_metadata']['source_weighting']);
    }

    public function test_the_element_text_appears_verbatim_in_the_document_text_so_provenance_can_be_recovered(): void
    {
        $context = $this->context();
        $this->mockDiscovery($this->matrixDiscovery());
        $this->spyExtractionRun();

        $this->upload($context, $this->matrixWorkbook());

        $document = SavedNoticeAiDocument::query()->where('saved_notice_id', $context['saved_notice']->id)->firstOrFail();
        $text = (string) $document->extracted_text;

        foreach ($document->structured_text_elements as $element) {
            // This exact-match relationship is how RequirementCandidateExtractor recovers
            // source_element_key after the AI call.
            $this->assertStringContainsString($element['text'], $text);
            $this->assertSame(
                $element['text'],
                mb_substr($text, $element['char_start'], mb_strlen($element['text'], 'UTF-8'), 'UTF-8'),
                'char_start must point at the requirement wording itself.',
            );
        }
    }

    public function test_the_suppliers_own_answer_column_never_becomes_requirement_text(): void
    {
        $context = $this->context();
        $this->mockDiscovery($this->matrixDiscovery());
        $this->spyExtractionRun();

        $this->upload($context, $this->matrixWorkbook());

        $document = SavedNoticeAiDocument::query()->where('saved_notice_id', $context['saved_notice']->id)->firstOrFail();

        $this->assertStringNotContainsString('Ja, dette støttes fullt ut.', (string) $document->extracted_text);
        $this->assertStringContainsString('Kommentar: Se vedlegg 2', (string) $document->extracted_text);
    }

    public function test_two_requirement_sheets_both_contribute(): void
    {
        $context = $this->context();
        $path = $this->fixtures->build([
            'Funksjonelle krav' => [['ID', 'Krav'], ['F-1', 'Systemet skal ha søk.']],
            'Tekniske krav' => [['ID', 'Krav'], ['T-1', 'Systemet skal kjøre i EØS.']],
        ]);

        $sheet = static fn (int $index, string $name): array => [
            'sheet_index' => $index,
            'sheet_name' => $name,
            'header_range' => 'A1:B1',
            'data_range' => 'A2:B2',
            'logical_unit_strategy' => WorkbookSheetSchemaData::UNIT_ROW,
            'grouping_column_letter' => null,
            'section_row_numbers' => [],
            'field_roles' => [
                ['column_letter' => 'A', 'role' => WorkbookFieldRoleData::ROLE_REQUIREMENT_ID, 'header_label' => 'ID', 'confidence' => 0.9],
                ['column_letter' => 'B', 'role' => WorkbookFieldRoleData::ROLE_REQUIREMENT_TEXT, 'header_label' => 'Krav', 'confidence' => 0.9],
            ],
            'warnings' => [],
            'confidence' => 0.9,
            'reason' => 'Same layout.',
        ];

        $this->mockDiscovery([
            'requirement_sheets' => [$sheet(0, 'Funksjonelle krav'), $sheet(1, 'Tekniske krav')],
            'supporting_sheets' => [],
            'warnings' => [],
            'confidence' => 0.9,
        ]);
        $this->spyExtractionRun();

        $this->upload($context, $path, 'To ark.xlsx');

        $elements = SavedNoticeAiDocument::query()->where('saved_notice_id', $context['saved_notice']->id)->firstOrFail()->structured_text_elements;

        // The controller document-scopes element keys (doc<id>-...), the same convention DOCX
        // paragraphs and list items already use; the workbook-local part is what identifies the range.
        $this->assertCount(2, $elements);
        $this->assertStringEndsWith('sheet:0:range:A2:B2', $elements[0]['element_key']);
        $this->assertStringEndsWith('sheet:1:range:A2:B2', $elements[1]['element_key']);
        $this->assertStringStartsWith('doc', $elements[0]['element_key']);
    }

    public function test_the_same_workbook_produces_the_same_source_keys_every_time(): void
    {
        $path = $this->matrixWorkbook();
        $keys = [];

        // One shared mock for both uploads: rebinding mid-test would leave the first expectation
        // counting calls it never made.
        $this->mockDiscovery($this->matrixDiscovery(), times: 2);
        $this->spyExtractionRun();

        foreach ([0, 1] as $ignored) {
            $context = $this->context();
            $this->upload($context, $path);

            // Compare the workbook-local part: the document id prefix is per-document by design.
            $keys[] = array_map(
                static fn (string $key): string => Str::after($key, '-'),
                array_column(
                    SavedNoticeAiDocument::query()->where('saved_notice_id', $context['saved_notice']->id)->firstOrFail()->structured_text_elements,
                    'element_key',
                ),
            );
        }

        $this->assertSame($keys[0], $keys[1]);
    }

    public function test_excel_provenance_survives_the_persisted_source_reference_whitelist(): void
    {
        // buildSourceReference() rebuilds the persisted reference from a named set of keys, so a
        // source kind's own provenance only survives because it is passed through deliberately.
        // Verified end to end against a real import: without this the sheet and range a
        // requirement came from stop at the document's structured elements.
        $element = [
            'element_key' => 'doc1-sheet:0:range:A2:E2',
            'element_type' => 'sheet_range',
            'text' => 'Løsningen skal støtte SSO.',
            'number' => 'K-1',
            'section_title' => null,
            'section_number' => null,
            'source_metadata' => [
                'source_label' => 'Kravspesifikasjon!A2:E2',
                'source_range' => 'A2:E2',
                'source_sheet_name' => 'Kravspesifikasjon',
                'source_qualification' => 'Skal',
                'source_weighting' => '30',
            ],
        ];

        $candidate = new RequirementExtractionCandidateData(
            sourceDocumentId: 1, sourceBlockId: 'b', sourceBlockIndex: 0, requirementIdentifier: null,
            parentReference: null, requirementType: 'documentation', obligationType: 'shall',
            extractionMethod: 'ai', originalText: 'x', normalizedText: 'x', comment: null,
            evaluationNotes: null, responseExpectation: null, expectedEvidence: [], keywords: [],
            domain: [], relatedReferences: [], sourceReference: [], interpretationRisk: null,
            isRequirement: true, confidence: 1.0, warnings: [],
        );

        $resolved = $candidate->withResolvedTextElement($element, 'text_matched');

        $this->assertSame('sheet_range', $resolved->sourceReference['source_element_type']);
        $this->assertSame('Kravspesifikasjon!A2:E2', $resolved->sourceReference['source_metadata']['source_label']);
        $this->assertSame('Skal', $resolved->sourceReference['source_metadata']['source_qualification']);
        $this->assertSame('30', $resolved->sourceReference['source_metadata']['source_weighting']);
    }

    // ── fail closed ──────────────────────────────────────────────────────────

    private function assertNothingImported(array $context): void
    {
        $this->assertSame(0, SavedNoticeAiDocument::query()->where('saved_notice_id', $context['saved_notice']->id)->count());
        $this->assertSame(0, $context['saved_notice']->aiRequirements()->count());
    }

    public function test_an_unreadable_structure_stops_the_import_with_a_clear_message(): void
    {
        $context = $this->context();
        // Discovery names a sheet that is not in this workbook.
        $this->mockDiscovery($this->matrixDiscovery(['sheet_index' => 7]));
        $this->spyExtractionRun();

        $response = $this->upload($context, $this->matrixWorkbook());

        $response->assertRedirect();
        $response->assertSessionHas('error', __('procynia.ai.excel_import_structure_unclear', ['file' => 'Kravspesifikasjon.xlsx']));
        $this->assertNothingImported($context);
    }

    public function test_an_invented_range_stops_the_import(): void
    {
        $context = $this->context();
        $this->mockDiscovery($this->matrixDiscovery(['data_range' => 'A2:F900']));
        $this->spyExtractionRun();

        $this->upload($context, $this->matrixWorkbook())->assertSessionHas('error');
        $this->assertNothingImported($context);
    }

    public function test_a_workbook_too_large_to_read_completely_stops_the_import_before_any_ai_call(): void
    {
        $context = $this->context();
        $rows = [['ID', 'Krav']];

        for ($index = 1; $index <= 5010; $index++) {
            $rows[] = ['K-'.$index, 'Krav nummer '.$index];
        }

        // Discovery must never be reached: a truncated workbook cannot yield a complete set.
        $this->mock(WorkbookStructureDiscoveryAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('discoverStructure')->never());
        $this->spyExtractionRun();

        $response = $this->upload($context, $this->fixtures->build(['Krav' => $rows]), 'Stor.xlsx');

        $response->assertSessionHas('error', __('procynia.ai.excel_import_too_large', ['file' => 'Stor.xlsx']));
        $this->assertNothingImported($context);
    }

    public function test_structure_discovery_being_unavailable_stops_the_import_rather_than_falling_back(): void
    {
        $context = $this->context();
        config(['services.enterprise_wiki.ai_enabled' => false]);
        $this->spyExtractionRun();

        $response = $this->upload($context, $this->matrixWorkbook());

        $response->assertSessionHas('error', __('procynia.ai.excel_import_ai_unavailable', ['file' => 'Kravspesifikasjon.xlsx']));
        $this->assertNothingImported($context);
    }

    public function test_a_refused_workbook_leaves_no_stored_file_behind(): void
    {
        $context = $this->context();
        $this->mockDiscovery($this->matrixDiscovery(['sheet_index' => 7]));
        $this->spyExtractionRun();

        $this->upload($context, $this->matrixWorkbook());

        $this->assertSame([], Storage::disk('local')->allFiles(sprintf('saved-notices/%d/ai-documents', $context['saved_notice']->id)));
    }

    // ── the other formats are untouched ──────────────────────────────────────

    public function test_a_pdf_upload_still_uses_the_ordinary_text_path(): void
    {
        $context = $this->context();
        $this->spyExtractionRun();
        // Discovery must not run for a non-Excel upload.
        $this->mock(WorkbookStructureDiscoveryAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('discoverStructure')->never());

        $path = sys_get_temp_dir().'/procynia-test-'.Str::random(8).'.pdf';
        file_put_contents($path, "%PDF-1.4\n%%EOF\n");

        $response = $this->actingAs($context['user'])->post(
            "/app/ai/{$context['saved_notice']->id}/documents",
            ['documents' => [new UploadedFile($path, 'Kravspesifikasjon.pdf', 'application/pdf', null, true)]],
        );

        $response->assertRedirect();
        $response->assertSessionMissing('error');
        $this->assertSame(1, SavedNoticeAiDocument::query()->where('saved_notice_id', $context['saved_notice']->id)->count());

        @unlink($path);
    }

    public function test_another_customers_case_cannot_receive_an_excel_upload(): void
    {
        $owner = $this->context();
        $intruder = $this->context();
        $this->spyExtractionRun();

        $this->actingAs($intruder['user'])->post(
            "/app/ai/{$owner['saved_notice']->id}/documents",
            ['documents' => [new UploadedFile($this->matrixWorkbook(), 'Krav.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true)]],
        )->assertNotFound();

        $this->assertSame(0, SavedNoticeAiDocument::query()->where('saved_notice_id', $owner['saved_notice']->id)->count());
    }
}
