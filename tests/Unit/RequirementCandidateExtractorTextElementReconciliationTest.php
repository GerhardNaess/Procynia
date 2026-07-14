<?php

namespace Tests\Unit;

use App\Data\Ai\Requirements\DocxTableCellData;
use App\Data\Ai\Requirements\DocxTableRowData;
use App\Models\SavedNoticeAiDocument;
use App\Services\Ai\Requirements\RequirementCandidateExtractor;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Purpose: Verify a requirement candidate whose text originates from a plain body paragraph or a
 * numbered list item (not a table row) can be traced back to the correct DOCX source element —
 * the same server-authoritative, no-hallucination guarantee RequirementCandidateExtractorTableRow
 * ReconciliationTest already proves for table rows, extended to paragraph/list_item elements.
 * Inputs: None.
 * Returns: None.
 * Side effects: None.
 */
class RequirementCandidateExtractorTextElementReconciliationTest extends TestCase
{
    private function fakeCandidateResponse(array $candidates): void
    {
        config()->set('services.openai.api_key', 'test-key');

        Http::fake([
            '*' => Http::response([
                'id' => 'resp_text_element_reconciliation_test',
                'object' => 'response',
                'status' => 'completed',
                'output_text' => json_encode(['candidates' => $candidates], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'usage' => ['input_tokens' => 100, 'output_tokens' => 30, 'total_tokens' => 130],
            ], 200),
        ]);
    }

    public function test_it_resolves_a_requirement_from_a_plain_paragraph_with_no_explicit_number(): void
    {
        $document = new SavedNoticeAiDocument;
        $document->forceFill([
            'id' => 701,
            'saved_notice_id' => 801,
            'original_filename' => 'text-element-paragraph.docx',
            'extracted_text' => 'The Contractor shall provide documentation within 10 days.',
        ]);

        $paragraphElement = [
            'element_key' => 'doc701-paragraph-0',
            'element_type' => 'paragraph',
            'document_order' => 1,
            'text' => 'The Contractor shall provide documentation within 10 days.',
            'number' => null,
            'section_number' => '2.1',
            'section_title' => 'Buying responsibility, not activities',
            'char_start' => 0,
            'char_end' => 60,
        ];

        $this->fakeCandidateResponse([[
            'requirement_identifier' => null,
            'parent_reference' => null,
            'original_text' => 'The Contractor shall provide documentation within 10 days.',
            'source_reference_text' => null,
            'is_requirement' => true,
            'confidence' => 0.95,
        ]]);

        $result = app(RequirementCandidateExtractor::class)->extractFullDocument($document, 'run-paragraph', [], [$paragraphElement]);

        $this->assertTrue($result->ok);
        $this->assertCount(1, $result->candidates);
        $candidate = $result->candidates[0];

        $this->assertSame('doc701-paragraph-0', $candidate->sourceElementKey);
        $this->assertNull($candidate->sourceRowKey);
        $this->assertSame('text_matched', $candidate->sourceReference['source_element_key_origin']);
        $this->assertSame('paragraph', $candidate->sourceReference['source_element_type']);
        $this->assertSame('2.1', $candidate->sourceReference['source_section_number']);
        $this->assertSame('Buying responsibility, not activities', $candidate->sourceReference['source_section_title']);
        // A plain paragraph has no reconstructed Word number to be authoritative about — the
        // candidate's own (here: absent) identifier must survive untouched.
        $this->assertNull($candidate->requirementIdentifier);
        $this->assertArrayNotHasKey('source_element_number', $candidate->sourceReference);
    }

    public function test_it_resolves_a_requirement_from_a_numbered_list_item_and_overrides_the_identifier_with_its_own_number(): void
    {
        $document = new SavedNoticeAiDocument;
        $document->forceFill([
            'id' => 702,
            'saved_notice_id' => 802,
            'original_filename' => 'text-element-list-item.docx',
            'extracted_text' => 'The Contractor shall notify the Customer without delay.',
        ]);

        $listItemElement = [
            'element_key' => 'doc702-listitem-3',
            'element_type' => 'list_item',
            'document_order' => 4,
            'text' => 'The Contractor shall notify the Customer without delay.',
            'number' => '3.2.1',
            'section_number' => '3.2',
            'section_title' => 'Notification duties',
            'char_start' => 0,
            'char_end' => 57,
        ];

        $this->fakeCandidateResponse([[
            'requirement_identifier' => 'wrong-guess',
            'parent_reference' => null,
            'original_text' => 'The Contractor shall notify the Customer without delay.',
            'source_reference_text' => null,
            'is_requirement' => true,
            'confidence' => 0.97,
        ]]);

        $result = app(RequirementCandidateExtractor::class)->extractFullDocument($document, 'run-list-item', [], [$listItemElement]);

        $this->assertTrue($result->ok);
        $this->assertCount(1, $result->candidates);
        $candidate = $result->candidates[0];

        $this->assertSame('doc702-listitem-3', $candidate->sourceElementKey);
        $this->assertSame('3.2.1', $candidate->requirementIdentifier);
        $this->assertSame('list_item', $candidate->sourceReference['source_element_type']);
        $this->assertSame('3.2.1', $candidate->sourceReference['source_element_number']);
        $this->assertSame('3.2', $candidate->sourceReference['source_section_number']);
        $this->assertSame('Notification duties', $candidate->sourceReference['source_section_title']);
    }

    public function test_it_allows_multiple_candidates_to_share_the_same_verified_text_element_via_substring_containment(): void
    {
        $document = new SavedNoticeAiDocument;
        $document->forceFill([
            'id' => 703,
            'saved_notice_id' => 803,
            'original_filename' => 'text-element-split-paragraph.docx',
            'extracted_text' => 'The Contractor shall deliver documentation. The Contractor shall also notify the Customer.',
        ]);

        $paragraphElement = [
            'element_key' => 'doc703-paragraph-2',
            'element_type' => 'paragraph',
            'document_order' => 2,
            'text' => 'The Contractor shall deliver documentation. The Contractor shall also notify the Customer.',
            'number' => null,
            'section_number' => null,
            'section_title' => null,
            'char_start' => 0,
            'char_end' => 91,
        ];

        $this->fakeCandidateResponse([
            [
                'requirement_identifier' => null,
                'parent_reference' => null,
                'original_text' => 'The Contractor shall deliver documentation.',
                'source_reference_text' => null,
                'is_requirement' => true,
                'confidence' => 0.95,
            ],
            [
                'requirement_identifier' => null,
                'parent_reference' => null,
                'original_text' => 'The Contractor shall also notify the Customer.',
                'source_reference_text' => null,
                'is_requirement' => true,
                'confidence' => 0.93,
            ],
        ]);

        $result = app(RequirementCandidateExtractor::class)->extractFullDocument($document, 'run-split-paragraph', [], [$paragraphElement]);

        $this->assertTrue($result->ok);
        $this->assertCount(2, $result->candidates);

        foreach ($result->candidates as $candidate) {
            $this->assertSame('doc703-paragraph-2', $candidate->sourceElementKey);
            $this->assertSame('text_matched', $candidate->sourceReference['source_element_key_origin']);
        }
    }

    public function test_it_does_not_fuzzy_match_a_near_miss_text_against_a_text_element(): void
    {
        $document = new SavedNoticeAiDocument;
        $document->forceFill([
            'id' => 704,
            'saved_notice_id' => 804,
            'original_filename' => 'text-element-near-miss.docx',
            'extracted_text' => 'The Contractor shall deliver documentation within 10 days.',
        ]);

        $paragraphElement = [
            'element_key' => 'doc704-paragraph-0',
            'element_type' => 'paragraph',
            'document_order' => 0,
            'text' => 'The Contractor shall deliver documentation within 10 days.',
            'number' => null,
            'section_number' => null,
            'section_title' => null,
            'char_start' => 0,
            'char_end' => 59,
        ];

        $this->fakeCandidateResponse([[
            'requirement_identifier' => null,
            'parent_reference' => null,
            // Paraphrased, not verbatim/substring — must not match.
            'original_text' => 'The Contractor must deliver documentation within 10 days.',
            'source_reference_text' => null,
            'is_requirement' => true,
            'confidence' => 0.9,
        ]]);

        $result = app(RequirementCandidateExtractor::class)->extractFullDocument($document, 'run-text-element-near-miss', [], [$paragraphElement]);

        $this->assertTrue($result->ok);
        $this->assertCount(1, $result->candidates);
        $candidate = $result->candidates[0];

        $this->assertNull($candidate->sourceElementKey);
        $this->assertArrayNotHasKey('source_element_key_origin', $candidate->sourceReference);
    }

    public function test_it_does_not_guess_when_the_same_text_matches_more_than_one_text_element(): void
    {
        $document = new SavedNoticeAiDocument;
        $document->forceFill([
            'id' => 705,
            'saved_notice_id' => 805,
            'original_filename' => 'text-element-ambiguous.docx',
            'extracted_text' => 'Ambiguous duplicated requirement text.',
        ]);

        $elementA = [
            'element_key' => 'doc705-paragraph-0',
            'element_type' => 'paragraph',
            'document_order' => 0,
            'text' => 'Ambiguous duplicated requirement text.',
            'number' => null,
            'section_number' => null,
            'section_title' => null,
            'char_start' => 0,
            'char_end' => 39,
        ];
        $elementB = [
            'element_key' => 'doc705-paragraph-1',
            'element_type' => 'paragraph',
            'document_order' => 1,
            'text' => 'Ambiguous duplicated requirement text.',
            'number' => null,
            'section_number' => null,
            'section_title' => null,
            'char_start' => 0,
            'char_end' => 39,
        ];

        $this->fakeCandidateResponse([[
            'requirement_identifier' => null,
            'parent_reference' => null,
            'original_text' => 'Ambiguous duplicated requirement text.',
            'source_reference_text' => null,
            'is_requirement' => true,
            'confidence' => 0.9,
        ]]);

        $result = app(RequirementCandidateExtractor::class)->extractFullDocument($document, 'run-text-element-ambiguous', [], [$elementA, $elementB]);

        $this->assertTrue($result->ok);
        $this->assertCount(1, $result->candidates);
        $candidate = $result->candidates[0];

        $this->assertNull($candidate->sourceElementKey);
        $this->assertArrayNotHasKey('source_element_key_origin', $candidate->sourceReference);
    }

    /**
     * A candidate already resolved against a table row (see RequirementCandidateExtractorTableRow
     * ReconciliationTest) must not also be re-matched against a text element — table-row provenance
     * takes precedence and is never overwritten by the paragraph/list-item recovery pass.
     */
    public function test_it_does_not_override_an_already_resolved_table_row_with_a_text_element_match(): void
    {
        $document = new SavedNoticeAiDocument;
        $document->forceFill([
            'id' => 706,
            'saved_notice_id' => 806,
            'original_filename' => 'text-element-table-precedence.docx',
            'extracted_text' => 'Shared requirement wording appearing in both a table and body text.',
        ]);

        $row = new DocxTableRowData(
            sourceRowKey: 'doc706-tbl0-row0',
            tableIndex: 0,
            rowIndex: 0,
            charStart: 0,
            charEnd: 68,
            cells: [
                new DocxTableCellData(0, 'Req. No.', 'req_no', '4.1'),
                new DocxTableCellData(1, 'Requirement text', 'requirement_text', 'Shared requirement wording appearing in both a table and body text.'),
            ],
            sectionNumber: null,
            sectionTitle: null,
        );

        $paragraphElement = [
            'element_key' => 'doc706-paragraph-5',
            'element_type' => 'paragraph',
            'document_order' => 5,
            'text' => 'Shared requirement wording appearing in both a table and body text.',
            'number' => null,
            'section_number' => null,
            'section_title' => null,
            'char_start' => 0,
            'char_end' => 68,
        ];

        $this->fakeCandidateResponse([[
            'requirement_identifier' => null,
            'parent_reference' => null,
            'original_text' => 'Shared requirement wording appearing in both a table and body text.',
            'source_reference_text' => null,
            'source_row_key' => 'doc706-tbl0-row0',
            'is_requirement' => true,
            'confidence' => 0.95,
        ]]);

        $result = app(RequirementCandidateExtractor::class)->extractFullDocument($document, 'run-table-precedence', [$row], [$paragraphElement]);

        $this->assertTrue($result->ok);
        $this->assertCount(1, $result->candidates);
        $candidate = $result->candidates[0];

        $this->assertSame('doc706-tbl0-row0', $candidate->sourceRowKey);
        $this->assertSame('4.1', $candidate->requirementIdentifier);
        $this->assertSame('doc706-tbl0-row0', $candidate->sourceElementKey);
        $this->assertSame('table_row', $candidate->sourceReference['source_element_type']);
        $this->assertArrayNotHasKey('source_element_key_origin', $candidate->sourceReference);
    }
}
