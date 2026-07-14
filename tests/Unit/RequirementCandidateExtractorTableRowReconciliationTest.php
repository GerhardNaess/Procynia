<?php

namespace Tests\Unit;

use App\Data\Ai\Requirements\DocxTableCellData;
use App\Data\Ai\Requirements\DocxTableRowData;
use App\Models\SavedNoticeAiDocument;
use App\Services\Ai\Requirements\RequirementCandidateExtractor;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Purpose: Verify source_row_key is only ever persisted when verified against a row actually
 * sent to the AI for that window — a hallucinated or cross-window key must be rejected, and a
 * genuinely table-derived candidate the AI left unattributed must be recoverable by exact text
 * match, never by a guess.
 * Inputs: None.
 * Returns: None.
 * Side effects: None.
 */
class RequirementCandidateExtractorTableRowReconciliationTest extends TestCase
{
    private function buildRow(string $sourceRowKey, string $reqNo, string $requirementText, string $type = 'M'): DocxTableRowData
    {
        return new DocxTableRowData(
            sourceRowKey: $sourceRowKey,
            tableIndex: 0,
            rowIndex: 0,
            charStart: 0,
            charEnd: mb_strlen($requirementText, 'UTF-8'),
            cells: [
                new DocxTableCellData(0, 'Req. No.', 'req_no', $reqNo),
                new DocxTableCellData(1, 'Requirement text', 'requirement_text', $requirementText),
                new DocxTableCellData(2, 'Type', 'type', $type),
            ],
            sectionNumber: '2.1',
            sectionTitle: 'Buying responsibility, not activities',
        );
    }

    private function fakeCandidateResponse(array $candidate): void
    {
        config()->set('services.openai.api_key', 'test-key');

        Http::fake([
            '*' => Http::response([
                'id' => 'resp_reconciliation_test',
                'object' => 'response',
                'status' => 'completed',
                'output_text' => json_encode(['candidates' => [$candidate]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'usage' => ['input_tokens' => 100, 'output_tokens' => 30, 'total_tokens' => 130],
            ], 200),
        ]);
    }

    public function test_it_verifies_an_ai_echoed_source_row_key_and_overrides_identifier_and_type_from_the_row(): void
    {
        $document = new SavedNoticeAiDocument;
        $document->forceFill([
            'id' => 501,
            'saved_notice_id' => 601,
            'original_filename' => 'reconciliation-verified.docx',
            'extracted_text' => 'The Services in the Agreement are described in Annex 1.',
        ]);

        $row = $this->buildRow('doc501-tbl0-row0', '2.1.1', 'The Services in the Agreement are described in Annex 1.');

        $this->fakeCandidateResponse([
            'requirement_identifier' => 'wrong-guess',
            'parent_reference' => null,
            'original_text' => 'The Services in the Agreement are described in Annex 1.',
            'source_reference_text' => 'Row 2.1.1',
            'source_row_key' => 'doc501-tbl0-row0',
            'is_requirement' => true,
            'confidence' => 0.98,
        ]);

        $result = app(RequirementCandidateExtractor::class)->extractFullDocument($document, 'run-verified', [$row]);

        $this->assertTrue($result->ok);
        $this->assertCount(1, $result->candidates);
        $candidate = $result->candidates[0];

        $this->assertSame('doc501-tbl0-row0', $candidate->sourceRowKey);
        $this->assertSame('2.1.1', $candidate->requirementIdentifier);
        $this->assertSame('ai_verified', $candidate->sourceReference['source_row_key_origin']);
        $this->assertSame('M', $candidate->sourceReference['source_row_type_code']);
        $this->assertSame('2.1', $candidate->sourceReference['source_section_number']);
        $this->assertSame('Buying responsibility, not activities', $candidate->sourceReference['source_section_title']);
    }

    public function test_it_rejects_a_hallucinated_source_row_key_that_was_never_sent_to_the_ai(): void
    {
        $document = new SavedNoticeAiDocument;
        $document->forceFill([
            'id' => 502,
            'saved_notice_id' => 602,
            'original_filename' => 'reconciliation-hallucinated.docx',
            'extracted_text' => 'A completely unrelated narrative requirement sentence.',
        ]);

        $row = $this->buildRow('doc502-tbl0-row0', '2.1.1', 'The Services in the Agreement are described in Annex 1.');

        $this->fakeCandidateResponse([
            'requirement_identifier' => '9.9.9',
            'parent_reference' => null,
            'original_text' => 'A completely unrelated narrative requirement sentence.',
            'source_reference_text' => null,
            'source_row_key' => 'doc502-tbl3-row17',
            'is_requirement' => true,
            'confidence' => 0.9,
        ]);

        $result = app(RequirementCandidateExtractor::class)->extractFullDocument($document, 'run-hallucinated', [$row]);

        $this->assertTrue($result->ok);
        $this->assertCount(1, $result->candidates);
        $candidate = $result->candidates[0];

        $this->assertNull($candidate->sourceRowKey);
        $this->assertSame('ai_rejected_hallucinated', $candidate->sourceReference['source_row_key_origin']);
        $this->assertSame('doc502-tbl3-row17', $candidate->sourceReference['source_row_key_rejected']);
        $this->assertContains('source_row_key_rejected:doc502-tbl3-row17', $candidate->warnings);
    }

    public function test_it_recovers_a_missing_source_row_key_by_exact_text_match_against_the_windows_rows(): void
    {
        $document = new SavedNoticeAiDocument;
        $document->forceFill([
            'id' => 503,
            'saved_notice_id' => 603,
            'original_filename' => 'reconciliation-recovered.docx',
            'extracted_text' => 'The Services in the Agreement are described in Annex 1.',
        ]);

        $row = $this->buildRow('doc503-tbl0-row0', '2.1.1', 'The Services in the Agreement are described in Annex 1.');

        $this->fakeCandidateResponse([
            'requirement_identifier' => null,
            'parent_reference' => null,
            'original_text' => 'The Services in the Agreement are described in Annex 1.',
            'source_reference_text' => null,
            'is_requirement' => true,
            'confidence' => 0.95,
        ]);

        $result = app(RequirementCandidateExtractor::class)->extractFullDocument($document, 'run-recovered', [$row]);

        $this->assertTrue($result->ok);
        $this->assertCount(1, $result->candidates);
        $candidate = $result->candidates[0];

        $this->assertSame('doc503-tbl0-row0', $candidate->sourceRowKey);
        $this->assertSame('2.1.1', $candidate->requirementIdentifier);
        $this->assertSame('text_matched', $candidate->sourceReference['source_row_key_origin']);
    }

    public function test_it_does_not_guess_when_the_same_text_matches_more_than_one_row(): void
    {
        $document = new SavedNoticeAiDocument;
        $document->forceFill([
            'id' => 504,
            'saved_notice_id' => 604,
            'original_filename' => 'reconciliation-ambiguous.docx',
            'extracted_text' => 'Ambiguous duplicated requirement text.',
        ]);

        $rowA = $this->buildRow('doc504-tbl0-row0', '2.1.1', 'Ambiguous duplicated requirement text.');
        $rowB = $this->buildRow('doc504-tbl0-row1', '2.1.2', 'Ambiguous duplicated requirement text.');

        $this->fakeCandidateResponse([
            'requirement_identifier' => null,
            'parent_reference' => null,
            'original_text' => 'Ambiguous duplicated requirement text.',
            'source_reference_text' => null,
            'is_requirement' => true,
            'confidence' => 0.9,
        ]);

        $result = app(RequirementCandidateExtractor::class)->extractFullDocument($document, 'run-ambiguous', [$rowA, $rowB]);

        $this->assertTrue($result->ok);
        $this->assertCount(1, $result->candidates);
        $candidate = $result->candidates[0];

        $this->assertNull($candidate->sourceRowKey);
        $this->assertArrayNotHasKey('source_row_key_origin', $candidate->sourceReference);
    }

    /**
     * A single table row's requirement-text cell can genuinely contain more than one distinct
     * requirement (the AI is explicitly allowed to split it — see class doc comment on
     * RequirementCandidateExtractor). Both resulting candidates legitimately share the same
     * source_row_key; this must not be treated as ambiguous or rejected.
     */
    public function test_it_allows_multiple_candidates_to_share_the_same_verified_source_row_key(): void
    {
        $document = new SavedNoticeAiDocument;
        $document->forceFill([
            'id' => 505,
            'saved_notice_id' => 605,
            'original_filename' => 'reconciliation-split-row.docx',
            'extracted_text' => 'The Contractor shall deliver documentation. The Contractor shall also notify the Customer.',
        ]);

        $row = $this->buildRow(
            'doc505-tbl0-row0',
            '2.1.1',
            'The Contractor shall deliver documentation. The Contractor shall also notify the Customer.',
        );

        config()->set('services.openai.api_key', 'test-key');

        Http::fake([
            '*' => Http::response([
                'id' => 'resp_split_row_test',
                'object' => 'response',
                'status' => 'completed',
                'output_text' => json_encode([
                    'candidates' => [
                        [
                            'requirement_identifier' => null,
                            'parent_reference' => null,
                            'original_text' => 'The Contractor shall deliver documentation.',
                            'source_reference_text' => null,
                            'source_row_key' => 'doc505-tbl0-row0',
                            'is_requirement' => true,
                            'confidence' => 0.95,
                        ],
                        [
                            'requirement_identifier' => null,
                            'parent_reference' => null,
                            'original_text' => 'The Contractor shall also notify the Customer.',
                            'source_reference_text' => null,
                            'source_row_key' => 'doc505-tbl0-row0',
                            'is_requirement' => true,
                            'confidence' => 0.93,
                        ],
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'usage' => ['input_tokens' => 100, 'output_tokens' => 40, 'total_tokens' => 140],
            ], 200),
        ]);

        $result = app(RequirementCandidateExtractor::class)->extractFullDocument($document, 'run-split-row', [$row]);

        $this->assertTrue($result->ok);
        $this->assertCount(2, $result->candidates);

        foreach ($result->candidates as $candidate) {
            $this->assertSame('doc505-tbl0-row0', $candidate->sourceRowKey);
            $this->assertSame('ai_verified', $candidate->sourceReference['source_row_key_origin']);
            $this->assertSame('2.1.1', $candidate->requirementIdentifier);
        }
    }

    /**
     * A table with no recognizable identifier column (e.g. no "Req. No." / "ID" style header) —
     * identifierCellValue() must return null, and enrichment must leave the AI's own (possibly
     * empty) identifier alone rather than overriding it with something wrong or empty-string.
     */
    public function test_it_does_not_override_the_identifier_when_the_row_has_no_recognizable_id_column(): void
    {
        $document = new SavedNoticeAiDocument;
        $document->forceFill([
            'id' => 506,
            'saved_notice_id' => 606,
            'original_filename' => 'reconciliation-no-id-column.docx',
            'extracted_text' => 'Free-text requirement with no identifier column at all.',
        ]);

        $row = new DocxTableRowData(
            sourceRowKey: 'doc506-tbl0-row0',
            tableIndex: 0,
            rowIndex: 0,
            charStart: 0,
            charEnd: 55,
            cells: [
                new DocxTableCellData(0, 'Description', 'description', 'Free-text requirement with no identifier column at all.'),
                new DocxTableCellData(1, 'Owner', 'owner', 'Contractor'),
            ],
            sectionNumber: null,
            sectionTitle: null,
        );

        $this->fakeCandidateResponse([
            'requirement_identifier' => 'AI-GUESS-1',
            'parent_reference' => null,
            'original_text' => 'Free-text requirement with no identifier column at all.',
            'source_reference_text' => null,
            'source_row_key' => 'doc506-tbl0-row0',
            'is_requirement' => true,
            'confidence' => 0.9,
        ]);

        $result = app(RequirementCandidateExtractor::class)->extractFullDocument($document, 'run-no-id-column', [$row]);

        $this->assertTrue($result->ok);
        $this->assertCount(1, $result->candidates);
        $candidate = $result->candidates[0];

        $this->assertSame('doc506-tbl0-row0', $candidate->sourceRowKey);
        $this->assertSame('ai_verified', $candidate->sourceReference['source_row_key_origin']);
        // No identifier column to be authoritative about — the AI's own identifier must survive
        // untouched, not be overwritten with null/empty.
        $this->assertSame('AI-GUESS-1', $candidate->requirementIdentifier);
        $this->assertArrayNotHasKey('source_row_identifier', $candidate->sourceReference);
    }

    /**
     * Reinforces "no fuzzy acceptance of hallucinated provenance": a near-miss text (one word
     * different from the row's actual cell value) must NOT be treated as a match. Only an exact
     * (post-normalization) match recovers a source_row_key — anything less must stay unattributed
     * rather than guessed.
     */
    public function test_it_does_not_fuzzy_match_a_near_miss_text_against_a_row(): void
    {
        $document = new SavedNoticeAiDocument;
        $document->forceFill([
            'id' => 507,
            'saved_notice_id' => 607,
            'original_filename' => 'reconciliation-near-miss.docx',
            'extracted_text' => 'The Contractor shall deliver documentation within 10 days.',
        ]);

        $row = $this->buildRow('doc507-tbl0-row0', '2.1.1', 'The Contractor shall deliver documentation within 10 days.');

        $this->fakeCandidateResponse([
            'requirement_identifier' => null,
            'parent_reference' => null,
            // Paraphrased, not verbatim — differs from the row's cell text by more than
            // trailing punctuation/whitespace, so no confident match should be made.
            'original_text' => 'The Contractor must deliver documentation within 10 days.',
            'source_reference_text' => null,
            'is_requirement' => true,
            'confidence' => 0.9,
        ]);

        $result = app(RequirementCandidateExtractor::class)->extractFullDocument($document, 'run-near-miss', [$row]);

        $this->assertTrue($result->ok);
        $this->assertCount(1, $result->candidates);
        $candidate = $result->candidates[0];

        $this->assertNull($candidate->sourceRowKey);
        $this->assertArrayNotHasKey('source_row_key_origin', $candidate->sourceReference);
    }
}
