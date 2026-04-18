<?php

namespace Tests\Unit;

use App\Models\SavedNoticeAiDocument;
use App\Models\SavedNoticeAiRequirement;
use App\Services\Ai\Requirements\FullDocumentRequirementExtractionPrompt;
use App\Services\Ai\Requirements\RequirementCandidateExtractor;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Purpose: Verify the Phase 1 full-document extraction path sends one document request and returns raw output.
 * Inputs: None.
 * Returns: None.
 * Side effects: Fakes the OpenAI HTTP response and inspects the outgoing request.
 */
class RequirementCandidateExtractorFullDocumentTest extends TestCase
{
    /**
     * Purpose: Ensure the Phase 1 test path uses the extracted document text in one OpenAI call.
     * Inputs: None.
     * Returns: None.
     * Side effects: Fakes the OpenAI HTTP response and inspects the extractor result.
     */
    public function test_it_sends_the_full_document_text_in_one_call_and_returns_raw_output(): void
    {
        config()->set('services.openai.api_key', 'test-key');

        $document = new SavedNoticeAiDocument();
        $document->forceFill([
            'id' => 203,
            'saved_notice_id' => 404,
            'original_filename' => 'full-document-test.docx',
            'extracted_text' => "Første krav: Leverandøren skal levere dokumentasjon innen 10 dager.\n\nAndre krav: Leverandøren skal beskrive løsning og bemanning.",
        ]);

        $rawOutput = "Krav 1: Leverandøren skal levere dokumentasjon innen 10 dager.\nKrav 2: Leverandøren skal beskrive løsning og bemanning.";

        Http::fake([
            '*' => Http::response([
                'id' => 'resp_full_document_test',
                'object' => 'response',
                'status' => 'completed',
                'output_text' => $rawOutput,
                'usage' => [
                    'input_tokens' => 120,
                    'output_tokens' => 40,
                    'total_tokens' => 160,
                ],
            ], 200),
        ]);

        $result = app(RequirementCandidateExtractor::class)->extractFullDocumentRaw($document, 'run-full-document-test');

        $this->assertTrue($result['ok']);
        $this->assertSame($document->id, $result['document_id']);
        $this->assertSame($rawOutput, $result['raw_output']);
        $this->assertSame(200, $result['status']);
        $this->assertSame(FullDocumentRequirementExtractionPrompt::promptVersion(), $result['prompt_version']);
        $this->assertSame(FullDocumentRequirementExtractionPrompt::model(), $result['model']);
        $this->assertSame(FullDocumentRequirementExtractionPrompt::maxOutputTokens(), $result['max_output_tokens']);
        $this->assertSame(mb_strlen((string) $document->extracted_text, 'UTF-8'), $result['document_text_length']);
        $this->assertSame(mb_strlen(FullDocumentRequirementExtractionPrompt::text(), 'UTF-8'), $result['prompt_text_length']);
        $this->assertSame(
            mb_strlen(FullDocumentRequirementExtractionPrompt::text(), 'UTF-8')
                + mb_strlen(FullDocumentRequirementExtractionPrompt::inputTextForDocument((string) $document->extracted_text), 'UTF-8'),
            $result['input_text_length'],
        );
        $this->assertSame(120, $result['input_tokens']);
        $this->assertSame(40, $result['output_tokens']);
        $this->assertSame(160, $result['total_tokens']);
        $this->assertTrue($result['bypassed_chunk_input']);
        $this->assertTrue($result['bypassed_segment_input']);
        $this->assertTrue($result['phase_1_requirement_extraction']);

        Http::assertSentCount(1);

        Http::assertSent(function ($request) use ($document): bool {
            $data = $request->data();
            $developerText = data_get($data, 'input.0.content.0.text');
            $userText = data_get($data, 'input.1.content.0.text');

            return data_get($data, 'model') === FullDocumentRequirementExtractionPrompt::model()
                && data_get($data, 'text.format.type') === 'json_schema'
                && data_get($data, 'text.format.name') === FullDocumentRequirementExtractionPrompt::promptName()
                && data_get($data, 'text.format.strict') === true
                && data_get($data, 'text.format.schema.properties.candidates.items.required') === [
                    'requirement_identifier',
                    'parent_reference',
                    'original_text',
                    'source_reference_text',
                    'is_requirement',
                    'confidence',
                ]
                && data_get($data, 'max_output_tokens') === FullDocumentRequirementExtractionPrompt::maxOutputTokens()
                && data_get($data, 'input.0.role') === 'developer'
                && data_get($data, 'input.1.role') === 'user'
                && is_string($developerText)
                && is_string($userText)
                && $developerText === FullDocumentRequirementExtractionPrompt::text()
                && $userText === FullDocumentRequirementExtractionPrompt::inputTextForDocument((string) $document->extracted_text);
        });
    }

    /**
     * Purpose: Ensure a full-document chunk with multiple requirement families is split into separate Phase 1 requests.
     * Inputs: None.
     * Returns: None.
     * Side effects: Fakes two OpenAI HTTP responses and inspects the outgoing requests.
     */
    public function test_it_splits_a_full_document_chunk_on_requirement_family_headings_and_keeps_both_families(): void
    {
        config()->set('services.openai.api_key', 'test-key');

        $document = new SavedNoticeAiDocument();
        $document->forceFill([
            'id' => 208,
            'saved_notice_id' => 409,
            'original_filename' => 'full-document-family-split-test.docx',
            'extracted_text' => "Innledning til dokumentet.\n\nSkal-krav\nID\nKravtekst\n1-1.S1\nLeverandøren skal levere dokumentasjon innen 10 dager.\n\nBør-krav\nID\nKravtekst\n1-1.E1\nLeverandøren bør beskrive løsning og bemanning.\n",
        ]);

        Http::fake([
            '*' => Http::sequence()
                ->push([
                    'id' => 'resp_full_document_family_split_test_1',
                    'object' => 'response',
                    'status' => 'completed',
                    'output_text' => json_encode([
                        'candidates' => [[
                            'requirement_identifier' => '1-1.S1',
                            'parent_reference' => null,
                            'original_text' => 'Leverandøren skal levere dokumentasjon innen 10 dager.',
                            'source_reference_text' => 'Skal-krav 1-1.S1',
                            'is_requirement' => true,
                            'confidence' => 0.99,
                        ]],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'usage' => [
                        'input_tokens' => 120,
                        'output_tokens' => 36,
                        'total_tokens' => 156,
                    ],
                ], 200)
                ->push([
                    'id' => 'resp_full_document_family_split_test_2',
                    'object' => 'response',
                    'status' => 'completed',
                    'output_text' => json_encode([
                        'candidates' => [[
                            'requirement_identifier' => '1-1.E1',
                            'parent_reference' => null,
                            'original_text' => 'Leverandøren bør beskrive løsning og bemanning.',
                            'source_reference_text' => 'Bør-krav 1-1.E1',
                            'is_requirement' => true,
                            'confidence' => 0.97,
                        ]],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'usage' => [
                        'input_tokens' => 118,
                        'output_tokens' => 34,
                        'total_tokens' => 152,
                    ],
                ], 200),
        ]);

        $result = app(RequirementCandidateExtractor::class)->extractFullDocument($document, 'run-full-document-family-split-test');

        $this->assertTrue($result->ok);
        $this->assertFalse($result->partial);
        $this->assertSame(2, $result->openAiCallCount);
        $this->assertSame(2, $result->extractionCallCount);
        $this->assertSame(2, $result->metadata['window_count']);
        $this->assertTrue($result->metadata['windowed_extraction']);
        $this->assertSame(2, $result->metadata['raw_candidate_count']);
        $this->assertSame(2, $result->metadata['mapped_candidate_count']);
        $this->assertSame(2, $result->metadata['deduped_candidate_count']);
        $this->assertCount(2, $result->candidates);
        $this->assertSame(['1-1.S1', '1-1.E1'], array_map(static fn ($candidate) => $candidate->requirementIdentifier, $result->candidates));

        Http::assertSentCount(2);

        $recordedRequests = Http::recorded()->values()->all();

        $this->assertCount(2, $recordedRequests);

        $firstRequest = $recordedRequests[0][0];
        $secondRequest = $recordedRequests[1][0];
        $firstUserText = data_get($firstRequest->data(), 'input.1.content.0.text');
        $secondUserText = data_get($secondRequest->data(), 'input.1.content.0.text');

        $this->assertIsString($firstUserText);
        $this->assertIsString($secondUserText);
        $this->assertStringContainsString('Skal-krav', $firstUserText);
        $this->assertStringNotContainsString('Bør-krav', $firstUserText);
        $this->assertStringContainsString('Bør-krav', $secondUserText);
    }

    /**
     * Purpose: Ensure Phase 1 document input is lightly normalised before it is sent to OpenAI.
     * Inputs: None.
     * Returns: None.
     * Side effects: None.
     */
    public function test_it_normalises_control_characters_and_ocr_style_requirement_identifier_spacing_before_sending_phase_one_requests(): void
    {
        $input = "Linje 1\r\n\r\nKrav\tmed" . chr(11) . "kontrolltegn og 1 - 2 . 2. E 1 samt 1-2.2.E 3 samt 1 -2.2.S 1 og 2 . 6";

        $this->assertSame(
            "Linje 1\n\nKrav med kontrolltegn og 1-2.2.E1 samt 1-2.2.E3 samt 1-2.2.S1 og 2.6",
            FullDocumentRequirementExtractionPrompt::inputTextForDocument($input),
        );
    }

    /**
     * Purpose: Ensure the Phase 1 prompt explicitly tells the model to extract all requirement families in the same section.
     * Inputs: None.
     * Returns: None.
     * Side effects: None.
     */
    public function test_it_explicitly_tells_the_model_not_to_stop_after_the_first_requirement_family(): void
    {
        $text = FullDocumentRequirementExtractionPrompt::text();

        $this->assertSame(8000, FullDocumentRequirementExtractionPrompt::maxOutputTokens());
        $this->assertStringContainsString('Et enkelt dokumentavsnitt kan inneholde flere kravfamilier samtidig, og alle må tas med hvis de har egen requirement_identifier.', $text);
        $this->assertStringContainsString('Ikke stopp etter den første kravfamilien eller den første kravtypen i et avsnitt; hver rad med egen requirement_identifier skal vurderes separat.', $text);
        $this->assertStringContainsString('Hvis et langt avsnitt eller en lang chunk senere introduserer en ny kravtabell eller en ny kravtype, skal du fortsette å trekke ut også disse radene.', $text);
        $this->assertStringContainsString('Når et dokumentavsnitt har flere påfølgende kravblokker, for eksempel først skal-krav og deretter bør-krav, skal begge familiene ekstrakteres fullstendig.', $text);
        $this->assertStringContainsString('En kravblokk som er formulert som et evalueringsspørsmål eller en bør-kravsbetingelse er fortsatt et formelt krav når den har egen requirement_identifier.', $text);
    }

    /**
     * Purpose: Ensure Phase 1 output parsing tolerates stray control characters in JSON string values.
     * Inputs: None.
     * Returns: None.
     * Side effects: Fakes the OpenAI HTTP response and inspects the parsed candidate result.
     */
    public function test_it_sanitizes_control_characters_in_full_document_json_output_before_parsing(): void
    {
        config()->set('services.openai.api_key', 'test-key');

        $document = new SavedNoticeAiDocument();
        $document->forceFill([
            'id' => 207,
            'saved_notice_id' => 408,
            'original_filename' => 'full-document-control-char-test.docx',
            'extracted_text' => "Første krav: Leverandøren skal levere dokumentasjon innen 10 dager.\n\nAndre krav: Leverandøren skal beskrive løsning og bemanning.",
        ]);

        $rawOutput = '{"candidates":[{"requirement_identifier":"1.1","parent_reference":null,"original_text":"Leverandøren' . chr(11) . 'skal levere dokumentasjon innen 10 dager.","source_reference_text":"Bilag 1 punkt 2.7","is_requirement":true,"confidence":0.93}]}';

        Http::fake([
            '*' => Http::response([
                'id' => 'resp_full_document_control_char_test',
                'object' => 'response',
                'status' => 'completed',
                'output_text' => $rawOutput,
                'usage' => [
                    'input_tokens' => 150,
                    'output_tokens' => 54,
                    'total_tokens' => 204,
                ],
            ], 200),
        ]);

        $result = app(RequirementCandidateExtractor::class)->extractFullDocument($document, 'run-full-document-control-char-test');

        $this->assertTrue($result->ok);
        $this->assertCount(1, $result->candidates);
        $this->assertSame('1.1', $result->candidates[0]->requirementIdentifier);
        $this->assertStringContainsString('Leverandøren skal levere dokumentasjon innen 10 dager.', $result->candidates[0]->originalText);
        $this->assertSame('Bilag 1 punkt 2.7', $result->candidates[0]->sourceReference['source_reference_text']);
        $this->assertSame('json_schema', $result->metadata['parse_strategy']);

        Http::assertSentCount(1);
    }

    /**
     * Purpose: Ensure byte-wise JSON sanitization survives raw output that contains both control bytes and invalid UTF-8.
     * Inputs: None.
     * Returns: None.
     * Side effects: Invokes the private Phase 1 parser directly.
     */
    public function test_it_sanitizes_control_bytes_and_invalid_utf8_before_parsing_full_document_json_output(): void
    {
        $extractor = app(RequirementCandidateExtractor::class);
        $method = new \ReflectionMethod($extractor, 'parsePhaseOneOutput');
        $method->setAccessible(true);

        $rawOutput = chr(0xEF) . chr(0xBB) . chr(0xBF) . '{"candidates":[{"requirement_identifier":"1.1","parent_reference":null,"original_text":"Leverandøren' . chr(11) . ' ' . chr(0xC3) . chr(0x28) . 'skal levere dokumentasjon innen 10 dager.","source_reference_text":"Bilag 1 punkt 2.7","is_requirement":true,"confidence":0.93}]}';

        $parsed = $method->invoke($extractor, $rawOutput);

        $this->assertSame('json_schema', $parsed['strategy']);
        $this->assertCount(1, $parsed['rows']);
        $this->assertSame('1.1', $parsed['rows'][0]['requirement_identifier']);
        $this->assertStringContainsString('Leverandøren', $parsed['rows'][0]['original_text']);
        $this->assertStringContainsString('skal levere dokumentasjon innen 10 dager.', $parsed['rows'][0]['original_text']);
    }

    /**
     * Purpose: Ensure the Phase 1 extraction path parses and maps one structured candidate.
     * Inputs: None.
     * Returns: None.
     * Side effects: Fakes the OpenAI HTTP response and inspects the parsed candidate result.
     */
    public function test_it_parses_full_document_candidate_rows_into_internal_candidates(): void
    {
        config()->set('services.openai.api_key', 'test-key');

        $document = new SavedNoticeAiDocument();
        $document->forceFill([
            'id' => 204,
            'saved_notice_id' => 405,
            'original_filename' => 'full-document-structured-test.docx',
            'extracted_text' => "Første krav: Leverandøren skal levere dokumentasjon innen 10 dager.\n\nAndre krav: Leverandøren skal beskrive løsning og bemanning.",
        ]);

        $candidateRow = [
            'requirement_identifier' => '1.1',
            'parent_reference' => '3.1 Krav',
            'original_text' => 'Leverandøren skal levere dokumentasjon innen 10 dager.',
            'source_reference_text' => 'Bilag 1 punkt 2.7',
            'is_requirement' => true,
            'confidence' => 0.93,
        ];

        $responseBody = [
            'id' => 'resp_full_document_structured_test',
            'object' => 'response',
            'status' => 'completed',
            'output_text' => json_encode([
                'candidates' => [$candidateRow],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'usage' => [
                'input_tokens' => 140,
                'output_tokens' => 54,
                'total_tokens' => 194,
            ],
        ];

        Http::fake([
            '*' => Http::response($responseBody, 200),
        ]);

        $result = app(RequirementCandidateExtractor::class)->extractFullDocument($document, 'run-full-document-structured-test');

        $this->assertTrue($result->ok);
        $this->assertFalse($result->partial);
        $this->assertSame(0, $result->segmentCount);
        $this->assertSame(0, $result->relevantSegmentCount);
        $this->assertSame(0, $result->relevanceCallCount);
        $this->assertSame(1, $result->extractionCallCount);
        $this->assertSame(1, $result->openAiCallCount);
        $this->assertCount(1, $result->candidates);
        $this->assertSame(1, count($result->candidates));
        $this->assertSame(1, $result->metadata['raw_candidate_count']);
        $this->assertSame(1, $result->metadata['mapped_candidate_count']);
        $this->assertSame(1, $result->metadata['deduped_candidate_count']);
        $this->assertSame('json_schema', $result->metadata['parse_strategy']);
        $this->assertSame(mb_strlen(FullDocumentRequirementExtractionPrompt::text(), 'UTF-8'), $result->metadata['prompt_text_length']);
        $this->assertSame(
            mb_strlen(FullDocumentRequirementExtractionPrompt::text(), 'UTF-8')
                + mb_strlen(FullDocumentRequirementExtractionPrompt::inputTextForDocument((string) $document->extracted_text), 'UTF-8'),
            $result->metadata['input_text_length'],
        );
        $this->assertSame('1.1', $result->candidates[0]->requirementIdentifier);
        $this->assertSame(SavedNoticeAiRequirement::EXTRACTION_METHOD_AI_PHASE_1, $result->candidates[0]->extractionMethod);
        $this->assertTrue(str_starts_with($result->candidates[0]->sourceBlockId, 'saved-notice-ai-document-204-phase-1-'));
        $this->assertSame('Bilag 1 punkt 2.7', $result->candidates[0]->sourceReference['source_reference_text']);
        $this->assertNull($result->candidates[0]->sourceReference['saved_notice_ai_document_chunk_id']);
        $this->assertTrue($result->candidates[0]->isRequirement);
        $this->assertSame(0.93, $result->candidates[0]->confidence);

        Http::assertSentCount(1);

        Http::assertSent(function ($request) use ($document): bool {
            $data = $request->data();
            $developerText = data_get($data, 'input.0.content.0.text');
            $userText = data_get($data, 'input.1.content.0.text');

            return data_get($data, 'model') === FullDocumentRequirementExtractionPrompt::model()
                && data_get($data, 'text.format.type') === 'json_schema'
                && data_get($data, 'text.format.name') === FullDocumentRequirementExtractionPrompt::promptName()
                && data_get($data, 'text.format.strict') === true
                && data_get($data, 'text.format.schema.properties.candidates.items.required') === [
                    'requirement_identifier',
                    'parent_reference',
                    'original_text',
                    'source_reference_text',
                    'is_requirement',
                    'confidence',
                ]
                && data_get($data, 'max_output_tokens') === FullDocumentRequirementExtractionPrompt::maxOutputTokens()
                && data_get($data, 'input.0.role') === 'developer'
                && data_get($data, 'input.1.role') === 'user'
                && is_string($developerText)
                && is_string($userText)
                && $developerText === FullDocumentRequirementExtractionPrompt::text()
                && $userText === FullDocumentRequirementExtractionPrompt::inputTextForDocument((string) $document->extracted_text);
        });
    }

    /**
     * Purpose: Ensure a schema-mismatched Phase 1 payload is classified as a response-format failure with a bounded preview.
     * Inputs: None.
     * Returns: None.
     * Side effects: Fakes the OpenAI HTTP response and inspects the failure payload.
     */
    public function test_it_classifies_schema_mismatched_full_document_output_as_a_response_format_failure(): void
    {
        config()->set('services.openai.api_key', 'test-key');

        $document = new SavedNoticeAiDocument();
        $document->forceFill([
            'id' => 205,
            'saved_notice_id' => 406,
            'original_filename' => 'full-document-unparsed-test.docx',
            'extracted_text' => "Første krav: Leverandøren skal levere dokumentasjon innen 10 dager.\n\nAndre krav: Leverandøren skal beskrive løsning og bemanning.",
        ]);

        $rawOutput = json_encode([
            'rows' => [
                [
                    'original_text' => 'Dette er ikke et kandidatsvar.',
                    'is_requirement' => true,
                    'confidence' => 0.8,
                ],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($rawOutput)) {
            $this->fail('Unable to build a fake JSON response.');
        }

        Http::fake([
            '*' => Http::response([
                'id' => 'resp_full_document_unparsed_test',
                'object' => 'response',
                'status' => 'completed',
                'output_text' => $rawOutput,
                'usage' => [
                    'input_tokens' => 150,
                    'output_tokens' => 18,
                    'total_tokens' => 168,
                ],
            ], 200),
        ]);

        $result = app(RequirementCandidateExtractor::class)->extractFullDocument($document, 'run-full-document-unparsed-test');

        $this->assertFalse($result->ok);
        $this->assertSame('unexpected_response', $result->failureStage);
        $this->assertSame('unexpected_response', $result->failureType);
        $this->assertSame('unexpected_response', $result->errorType);
        $this->assertSame('OpenAI phase 1 extraction response did not include a candidates array.', $result->errorMessage);
        $this->assertSame('failed', $result->metadata['parse_strategy']);
        $this->assertGreaterThan(0, $result->metadata['raw_output_length']);
        $this->assertLessThanOrEqual(strlen($rawOutput), strlen($result->metadata['raw_output_preview']));
        $this->assertStringStartsWith('{"rows":[{"original_text":"Dette er ikke et kandidatsvar."', $result->metadata['raw_output_preview']);

        Http::assertSentCount(1);
    }

    /**
     * Purpose: Ensure invalid JSON is classified as a response-parsing failure with a bounded preview.
     * Inputs: None.
     * Returns: None.
     * Side effects: Fakes the OpenAI HTTP response and inspects the failure payload.
     */
    public function test_it_classifies_invalid_json_full_document_output_as_a_response_parsing_failure(): void
    {
        config()->set('services.openai.api_key', 'test-key');

        $document = new SavedNoticeAiDocument();
        $document->forceFill([
            'id' => 206,
            'saved_notice_id' => 407,
            'original_filename' => 'full-document-invalid-json-test.docx',
            'extracted_text' => "Første krav: Leverandøren skal levere dokumentasjon innen 10 dager.\n\nAndre krav: Leverandøren skal beskrive løsning og bemanning.",
        ]);

        $rawOutput = '{"candidates":[';

        Http::fake([
            '*' => Http::response([
                'id' => 'resp_full_document_invalid_json_test',
                'object' => 'response',
                'status' => 'completed',
                'output_text' => $rawOutput,
                'usage' => [
                    'input_tokens' => 150,
                    'output_tokens' => 18,
                    'total_tokens' => 168,
                ],
            ], 200),
        ]);

        $result = app(RequirementCandidateExtractor::class)->extractFullDocument($document, 'run-full-document-invalid-json-test');

        $this->assertFalse($result->ok);
        $this->assertSame('unexpected_response', $result->failureStage);
        $this->assertSame('unexpected_response', $result->failureType);
        $this->assertSame('unexpected_response', $result->errorType);
        $this->assertNotSame('', $result->errorMessage);
        $this->assertSame('failed', $result->metadata['parse_strategy']);
        $this->assertGreaterThan(0, $result->metadata['raw_output_length']);
        $this->assertStringStartsWith('{"candidates":[', $result->metadata['raw_output_preview']);

        Http::assertSentCount(1);
    }
}
