<?php

namespace Tests\Unit;

use App\Data\Ai\Requirements\DocumentRequirementSegmentData;
use App\Data\Ai\Requirements\RequirementExtractionCandidateData;
use App\Models\SavedNoticeAiDocument;
use App\Models\SavedNoticeAiRequirement;
use App\Services\Ai\Requirements\RequirementCandidateExtractor;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Purpose: Verify the canonical mapping from a segment-level AI row to the internal candidate DTO.
 * Inputs: None.
 * Returns: None.
 * Side effects: None.
 */
class RequirementExtractionCandidateDataTest extends TestCase
{
    /**
     * Purpose: Ensure one valid segment row becomes one internal requirement candidate with preserved provenance.
     * Inputs: None.
     * Returns: None.
     * Side effects: None.
     */
    public function test_it_maps_a_segment_row_into_the_internal_candidate_contract(): void
    {
        $document = new SavedNoticeAiDocument();
        $document->forceFill([
            'id' => 99,
            'saved_notice_id' => 123,
            'original_filename' => 'schema-check.docx',
        ]);

        $segment = new DocumentRequirementSegmentData(
            savedNoticeId: 123,
            savedNoticeAiDocumentId: 99,
            savedNoticeAiDocumentChunkId: 555,
            documentTitle: 'schema-check.docx',
            documentFilename: 'schema-check.docx',
            segmentId: 'saved-notice-ai-document-99-segment-0',
            segmentIndex: 0,
            pageStart: 3,
            pageEnd: 3,
            sectionTitle: '3.1 Krav',
            text: 'Leverandøren skal levere dokumentasjon innen 10 dager.',
            sourceExcerpt: 'Leverandøren skal levere dokumentasjon innen 10 dager.',
            charStart: 100,
            charEnd: 156,
            wordCount: 8,
            sourceChunkIds: [555],
            sourceReference: [
                'saved_notice_id' => 123,
                'saved_notice_ai_document_id' => 99,
                'saved_notice_ai_document_chunk_id' => 555,
                'document_title' => 'schema-check.docx',
                'document_filename' => 'schema-check.docx',
                'source_segment_id' => 'saved-notice-ai-document-99-segment-0',
                'source_segment_index' => 0,
                'source_page_start' => 3,
                'source_page_end' => 3,
                'source_section_title' => '3.1 Krav',
                'source_excerpt' => 'Leverandøren skal levere dokumentasjon innen 10 dager.',
                'source_reference_text' => 'Leverandøren skal levere dokumentasjon innen 10 dager.',
                'char_start' => 100,
                'char_end' => 156,
                'source_chunk_ids' => [555],
            ],
        );

        $candidate = RequirementExtractionCandidateData::fromSegmentRow([
            'requirement_identifier' => '1.2',
            'parent_reference' => '3.1 Krav',
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
            'obligation_type' => 'must',
            'original_text' => 'Leverandøren skal levere dokumentasjon innen 10 dager.',
            'normalized_text' => 'Leverandøren skal levere dokumentasjon innen 10 dager.',
            'comment' => null,
            'evaluation_notes' => 'Delivery documentation is mandatory.',
            'response_expectation' => 'Submit documentation within 10 days.',
            'expected_evidence' => ['Documentasjon', 'Bekreftelse'],
            'keywords' => ['dokumentasjon', '10 dager'],
            'domain' => ['delivery'],
            'related_references' => ['3.1'],
            'source_excerpt' => 'Leverandøren skal levere dokumentasjon innen 10 dager.',
            'source_page_start' => 3,
            'source_page_end' => 3,
            'source_section_title' => '3.1 Krav',
            'interpretation_risk' => 'low',
            'is_requirement' => true,
            'confidence' => 0.95,
            'warnings' => [],
        ], $segment, 0);

        $serialized = $candidate->jsonSerialize();

        $this->assertSame(99, $candidate->sourceDocumentId);
        $this->assertSame('saved-notice-ai-document-99-segment-0', $candidate->sourceBlockId);
        $this->assertSame(0, $candidate->sourceBlockIndex);
        $this->assertSame('1.2', $candidate->requirementIdentifier);
        $this->assertSame('1.2', $serialized['requirement_identifier']);
        $this->assertSame(SavedNoticeAiRequirement::EXTRACTION_METHOD_AI_SEGMENTED, $candidate->extractionMethod);
        $this->assertSame('saved-notice-ai-document-99-segment-0', $serialized['source_reference']['source_segment_id']);
        $this->assertSame(3, $serialized['source_reference']['source_page_start']);
        $this->assertSame(3, $serialized['source_reference']['source_page_end']);
        $this->assertSame('3.1 Krav', $serialized['source_reference']['source_section_title']);
        $this->assertSame('Leverandøren skal levere dokumentasjon innen 10 dager.', $serialized['source_reference']['source_excerpt']);
        $this->assertSame('ai_segmented', $serialized['extraction_method']);
        $this->assertSame(99, $serialized['source_document_id']);
        $this->assertSame('saved-notice-ai-document-99-segment-0', $serialized['source_block_id']);
        $this->assertSame(0, $serialized['source_block_index']);
    }

    /**
     * Purpose: Verify that one structured OpenAI candidate becomes one mapped internal candidate and raw count.
     * Inputs: None.
     * Returns: None.
     * Side effects: Fakes the OpenAI HTTP response and inspects the extractor result.
     */
    public function test_it_counts_one_structured_openai_candidate_as_one_internal_candidate(): void
    {
        config()->set('services.openai.api_key', 'test-key');

        $document = new SavedNoticeAiDocument();
        $document->forceFill([
            'id' => 101,
            'saved_notice_id' => 321,
            'original_filename' => 'structured-response.docx',
        ]);

        $segment = new DocumentRequirementSegmentData(
            savedNoticeId: 321,
            savedNoticeAiDocumentId: 101,
            savedNoticeAiDocumentChunkId: 777,
            documentTitle: 'structured-response.docx',
            documentFilename: 'structured-response.docx',
            segmentId: 'saved-notice-ai-document-101-segment-0',
            segmentIndex: 0,
            pageStart: 4,
            pageEnd: 4,
            sectionTitle: '4. Krav',
            text: 'Leverandøren skal levere dokumentasjon innen 10 dager.',
            sourceExcerpt: 'Leverandøren skal levere dokumentasjon innen 10 dager.',
            charStart: 200,
            charEnd: 256,
            wordCount: 8,
            sourceChunkIds: [777],
            sourceReference: [
                'saved_notice_id' => 321,
                'saved_notice_ai_document_id' => 101,
                'saved_notice_ai_document_chunk_id' => 777,
                'document_title' => 'structured-response.docx',
                'document_filename' => 'structured-response.docx',
                'source_segment_id' => 'saved-notice-ai-document-101-segment-0',
                'source_segment_index' => 0,
                'source_page_start' => 4,
                'source_page_end' => 4,
                'source_section_title' => '4. Krav',
                'source_excerpt' => 'Leverandøren skal levere dokumentasjon innen 10 dager.',
                'source_reference_text' => 'Leverandøren skal levere dokumentasjon innen 10 dager.',
                'char_start' => 200,
                'char_end' => 256,
                'source_chunk_ids' => [777],
            ],
        );

        $candidate = [
            'requirement_identifier' => '4.1',
            'parent_reference' => '4. Krav',
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
            'obligation_type' => 'must',
            'original_text' => 'Leverandøren skal levere dokumentasjon innen 10 dager.',
            'normalized_text' => 'Leverandøren skal levere dokumentasjon innen 10 dager.',
            'comment' => null,
            'evaluation_notes' => null,
            'response_expectation' => null,
            'expected_evidence' => [],
            'keywords' => [],
            'domain' => [],
            'related_references' => [],
            'source_excerpt' => 'Leverandøren skal levere dokumentasjon innen 10 dager.',
            'source_page_start' => 4,
            'source_page_end' => 4,
            'source_section_title' => '4. Krav',
            'interpretation_risk' => 'low',
            'is_requirement' => true,
            'confidence' => 0.91,
            'warnings' => [],
        ];

        $responseBody = [
            'id' => 'resp_test_structured_candidate',
            'object' => 'response',
            'status' => 'completed',
            'output' => [
                [
                    'id' => 'msg_test_structured_candidate',
                    'type' => 'message',
                    'role' => 'assistant',
                    'status' => 'completed',
                    'content' => [
                        [
                            'type' => 'output_text',
                            'text' => json_encode([
                                'candidates' => [$candidate],
                            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ],
                    ],
                ],
            ],
            'output_text' => json_encode([
                'candidates' => [$candidate],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'usage' => [
                'input_tokens' => 48,
                'output_tokens' => 28,
                'total_tokens' => 76,
            ],
        ];

        Http::fake([
            '*' => Http::response($responseBody, 200),
        ]);

        $result = app(RequirementCandidateExtractor::class)->extract($document, $segment, 'run-test');

        $this->assertTrue($result->ok);
        $this->assertSame(1, $result->candidateCount);
        $this->assertCount(1, $result->candidates);
        $this->assertSame(1, $result->metadata['raw_candidate_count']);
        $this->assertSame(1, $result->metadata['mapped_candidate_count']);
        $this->assertSame(1, $result->metadata['deduped_candidate_count']);
        $this->assertSame('4.1', $result->candidates[0]->requirementIdentifier);
        $this->assertSame('saved-notice-ai-document-101-segment-0', $result->candidates[0]->sourceBlockId);
        $this->assertSame(101, $result->candidates[0]->sourceDocumentId);

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return data_get($data, 'text.format.type') === 'json_schema'
                && data_get($data, 'text.format.name') === 'requirement_segment_extraction';
        });
    }

}
