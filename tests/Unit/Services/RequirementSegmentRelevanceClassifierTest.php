<?php

namespace Tests\Unit\Services;

use App\Data\Ai\Requirements\DocumentRequirementSegmentData;
use App\Models\SavedNoticeAiDocument;
use App\Services\Ai\Contracts\AiTextGenerationClient;
use App\Services\Ai\Requirements\RequirementSegmentRelevanceClassifier;
use App\Services\Ai\Requirements\RequirementSegmentRelevancePromptBuilder;
use Mockery;
use Tests\TestCase;

class RequirementSegmentRelevanceClassifierTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_uses_the_provider_agnostic_text_generation_client_without_changing_prompt_or_response_contract(): void
    {
        $document = $this->documentFixture();
        $segment = $this->segmentFixture();
        $promptBuilder = new RequirementSegmentRelevancePromptBuilder();
        $expectedPayload = $promptBuilder->buildRequestPayload($document, $segment);

        $client = Mockery::mock(AiTextGenerationClient::class);
        $client->shouldReceive('createResponse')
            ->once()
            ->with($expectedPayload, 90)
            ->andReturn($this->fakeAiResponse([
                'is_relevant' => true,
                'confidence' => 0.97,
                'reason' => 'The segment explicitly states a requirement.',
            ]));

        $classifier = new RequirementSegmentRelevanceClassifier($client, $promptBuilder);

        $result = $classifier->classify($document, $segment, 'run-123');

        $this->assertTrue($result->ok);
        $this->assertSame(101, $result->savedNoticeId);
        $this->assertSame(202, $result->savedNoticeAiDocumentId);
        $this->assertSame(303, $result->savedNoticeAiDocumentChunkId);
        $this->assertSame('segment-1', $result->segmentId);
        $this->assertSame(0, $result->segmentIndex);
        $this->assertTrue($result->isRelevant);
        $this->assertSame(0.97, $result->confidence);
        $this->assertSame('The segment explicitly states a requirement.', $result->reason);
        $this->assertSame('json', $result->parseStrategy);
        $this->assertSame('resp_test_123', $result->responseId);
        $this->assertSame('req_test_123', $result->requestId);
        $this->assertSame(13, $result->inputTokens);
        $this->assertSame(7, $result->outputTokens);
        $this->assertSame(20, $result->totalTokens);
        $this->assertSame('2026-04-10.segment-relevance.v1', $result->metadata['prompt_version']);
    }

    private function documentFixture(): SavedNoticeAiDocument
    {
        $document = new SavedNoticeAiDocument();
        $document->forceFill([
            'id' => 202,
            'saved_notice_id' => 101,
            'original_filename' => 'segment-relevance.docx',
        ]);

        return $document;
    }

    private function segmentFixture(): DocumentRequirementSegmentData
    {
        return new DocumentRequirementSegmentData(
            savedNoticeId: 101,
            savedNoticeAiDocumentId: 202,
            savedNoticeAiDocumentChunkId: 303,
            documentTitle: 'segment-relevance.docx',
            documentFilename: 'segment-relevance.docx',
            segmentId: 'segment-1',
            segmentIndex: 0,
            pageStart: 1,
            pageEnd: 1,
            sectionTitle: 'Krav',
            text: 'Leverandøren skal beskrive løsningen.',
            sourceExcerpt: 'Leverandøren skal beskrive løsningen.',
            charStart: 0,
            charEnd: 36,
            wordCount: 5,
            sourceChunkIds: [303],
            sourceReference: [
                'source_reference_text' => 'Leverandøren skal beskrive løsningen.',
            ],
        );
    }

    private function fakeAiResponse(array $fields): array
    {
        $json = json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return [
            'id' => 'resp_test_123',
            'object' => 'response',
            'output_text' => is_string($json) ? $json : '',
            'output' => [
                [
                    'type' => 'message',
                    'role' => 'assistant',
                    'status' => 'completed',
                    'content' => [
                        [
                            'type' => 'output_text',
                            'text' => is_string($json) ? $json : '',
                        ],
                    ],
                ],
            ],
            '_meta' => [
                'request_id' => 'req_test_123',
            ],
            'usage' => [
                'input_tokens' => 13,
                'output_tokens' => 7,
                'total_tokens' => 20,
            ],
        ];
    }
}
