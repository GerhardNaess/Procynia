<?php

namespace Tests\Unit;

use App\Data\Ai\Requirements\DocumentRequirementSegmentData;
use App\Models\SavedNoticeAiDocument;
use App\Services\Ai\Requirements\RequirementSegmentExtractionPromptBuilder;
use Tests\TestCase;

class RequirementSegmentExtractionPromptBuilderTest extends TestCase
{
    public function test_it_requires_every_candidate_property_in_the_segment_extraction_schema(): void
    {
        $builder = app(RequirementSegmentExtractionPromptBuilder::class);
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
            pageStart: 1,
            pageEnd: 1,
            sectionTitle: '1. Krav',
            text: 'Leverandøren skal levere dokumentasjon innen 10 dager.',
            sourceExcerpt: 'Leverandøren skal levere dokumentasjon innen 10 dager.',
            charStart: 0,
            charEnd: 56,
            wordCount: 8,
            sourceChunkIds: [555],
            sourceReference: [
                'saved_notice_ai_document_id' => 99,
                'saved_notice_id' => 123,
                'saved_notice_ai_document_chunk_id' => 555,
                'source_segment_id' => 'saved-notice-ai-document-99-segment-0',
                'source_segment_index' => 0,
                'document_title' => 'schema-check.docx',
                'document_filename' => 'schema-check.docx',
                'source_page_start' => 1,
                'source_page_end' => 1,
                'source_section_title' => '1. Krav',
                'source_excerpt' => 'Leverandøren skal levere dokumentasjon innen 10 dager.',
                'source_reference_text' => 'Leverandøren skal levere dokumentasjon innen 10 dager.',
                'char_start' => 0,
                'char_end' => 56,
                'source_chunk_ids' => [555],
            ],
        );

        $payload = $builder->buildRequestPayload($document, $segment);
        $schema = $payload['text']['format']['schema'] ?? [];
        $candidateSchema = $schema['properties']['candidates']['items'] ?? [];
        $properties = array_keys($candidateSchema['properties'] ?? []);
        $required = $candidateSchema['required'] ?? [];

        sort($properties);
        sort($required);

        $this->assertSame('requirement_segment_extraction', $payload['text']['format']['name']);
        $this->assertSame($properties, $required);
        $this->assertContains('requirement_identifier', $required);
        $this->assertContains('parent_reference', $required);
        $this->assertContains('comment', $required);
        $this->assertContains('evaluation_notes', $required);
        $this->assertContains('response_expectation', $required);
        $this->assertContains('source_page_start', $required);
        $this->assertContains('source_page_end', $required);
        $this->assertContains('source_section_title', $required);
    }
}
