<?php

namespace Tests\Unit\Services;

use App\Models\SavedNoticeAiRequirement;
use App\Services\Ai\Requirements\RequirementGroundingJudgeService;
use App\Services\OpenAi\OpenAiClient;
use Mockery;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class RequirementGroundingJudgeServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_parses_and_validates_supported_judge_payloads(): void
    {
        $client = Mockery::mock(OpenAiClient::class);
        $client->shouldReceive('createResponse')
            ->once()
            ->with(Mockery::on(function (array $payload): bool {
                $inputPayload = json_decode((string) data_get($payload, 'input.1.content.0.text', ''), true);

                return data_get($payload, 'text.format.name') === 'requirement_grounding_judge'
                    && is_array($inputPayload)
                    && data_get($inputPayload, 'requirement.text') === 'Leverandøren skal beskrive ITSM/SPOC.'
                    && data_get($inputPayload, 'coverage.level') === 'amber'
                    && data_get($inputPayload, 'retrieved_knowledge_chunks.0.section_path') === 'SOC > Servicedesk > SPOC';
            }))
            ->andReturn($this->openAiResponse([
                'status' => 'supported',
                'can_generate_answer' => true,
                'supported_points' => ['  ITSM er dokumentert.  ', 'SPOC er dokumentert.'],
                'unsupported_points' => [],
                'missing_knowledge_summary' => 'Grunnlaget er tilstrekkelig.',
                'recommended_document_title' => 'Servicedesk og SPOC',
                'suggested_filename' => 'servicedesk-og-spoc.docx',
                'reasoning_summary' => 'Relevant støtte er til stede.',
            ]));

        $service = new RequirementGroundingJudgeService($client);
        $requirement = $this->requirementFixture();

        $result = $service->judge(
            $requirement,
            collect([
                [
                    'score' => 0.74,
                    'knowledge_item_id' => 11,
                    'document_title' => 'Servicedesk',
                    'knowledge_item_summary' => 'Dokumentasjon om servicedesk og SPOC.',
                    'chunk_id' => 101,
                    'chunk_index' => 0,
                    'heading_path' => 'Dokumentstruktur',
                    'topic' => 'Servicedesk',
                    'sub_topic' => 'SPOC',
                    'keywords' => ['ITSM', 'SPOC'],
                    'section_title' => 'SPOC',
                    'section_path' => 'SOC > Servicedesk > SPOC',
                    'content_preview' => 'Servicedesk håndterer saker i ITSM/SPOC.',
                ],
            ]),
            [
                'level' => 'amber',
                'max_score' => 0.74,
                'sources_count' => 1,
            ],
        );

        $this->assertSame('supported', $result['status']);
        $this->assertTrue($result['can_generate_answer']);
        $this->assertSame(['ITSM er dokumentert.', 'SPOC er dokumentert.'], $result['supported_points']);
        $this->assertSame([], $result['unsupported_points']);
        $this->assertSame('Grunnlaget er tilstrekkelig.', $result['missing_knowledge_summary']);
        $this->assertSame('Servicedesk og SPOC', $result['recommended_document_title']);
        $this->assertSame('servicedesk-og-spoc.docx', $result['suggested_filename']);
        $this->assertSame('Relevant støtte er til stede.', $result['reasoning_summary']);
    }

    public function test_it_accepts_partial_judge_payloads_that_block_generation(): void
    {
        $client = Mockery::mock(OpenAiClient::class);
        $client->shouldReceive('createResponse')
            ->once()
            ->andReturn($this->openAiResponse([
                'status' => 'partial',
                'can_generate_answer' => false,
                'supported_points' => ['ITSM er dokumentert.'],
                'unsupported_points' => ['SPOC er ikke dokumentert.'],
                'missing_knowledge_summary' => 'SPOC mangler.',
                'recommended_document_title' => null,
                'suggested_filename' => null,
                'reasoning_summary' => 'Delvis støtte er funnet.',
            ]));

        $service = new RequirementGroundingJudgeService($client);
        $requirement = $this->requirementFixture();

        $result = $service->judge($requirement, collect(), [
            'level' => 'amber',
            'max_score' => 0.52,
            'sources_count' => 1,
        ]);

        $this->assertSame('partial', $result['status']);
        $this->assertFalse($result['can_generate_answer']);
        $this->assertSame(['ITSM er dokumentert.'], $result['supported_points']);
        $this->assertSame(['SPOC er ikke dokumentert.'], $result['unsupported_points']);
        $this->assertSame('SPOC mangler.', $result['missing_knowledge_summary']);
    }

    public function test_it_rejects_inconsistent_judge_payloads(): void
    {
        $client = Mockery::mock(OpenAiClient::class);
        $client->shouldReceive('createResponse')
            ->once()
            ->andReturn($this->openAiResponse([
                'status' => 'supported',
                'can_generate_answer' => false,
                'supported_points' => ['ITSM er dokumentert.'],
                'unsupported_points' => [],
                'missing_knowledge_summary' => 'Invalid output.',
                'recommended_document_title' => null,
                'suggested_filename' => null,
                'reasoning_summary' => 'Invalid output.',
            ]));

        $service = new RequirementGroundingJudgeService($client);

        $this->expectException(RuntimeException::class);

        $service->judge($this->requirementFixture(), collect(), [
            'level' => 'amber',
            'max_score' => 0.51,
            'sources_count' => 1,
        ]);
    }

    public function test_it_rejects_invalid_json_payloads(): void
    {
        $client = Mockery::mock(OpenAiClient::class);
        $client->shouldReceive('createResponse')
            ->once()
            ->andReturn([
                'id' => (string) Str::ulid(),
                'object' => 'response',
                'status' => 'completed',
                'output_text' => 'not json',
                'output' => [],
            ]);

        $service = new RequirementGroundingJudgeService($client);

        $this->expectException(RuntimeException::class);

        $service->judge($this->requirementFixture(), collect(), [
            'level' => 'amber',
            'max_score' => 0.51,
            'sources_count' => 1,
        ]);
    }

    private function requirementFixture(): SavedNoticeAiRequirement
    {
        $requirement = new SavedNoticeAiRequirement();
        $requirement->forceFill([
            'id' => 42,
            'saved_notice_id' => 7,
            'requirement_identifier' => '1.1',
            'requirement_text' => 'Leverandøren skal beskrive ITSM/SPOC.',
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_MANDATORY,
            'approval_status' => SavedNoticeAiRequirement::APPROVAL_STATUS_DRAFT,
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_PENDING,
            'current_requirement_snapshot' => [
                'topic' => 'Servicedesk',
                'sub_topic' => 'SPOC',
                'keywords' => ['ITSM', 'SPOC'],
            ],
            'original_candidate_snapshot' => [],
        ]);

        return $requirement;
    }

    private function openAiResponse(array $fields): array
    {
        $json = json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($json)) {
            throw new RuntimeException('Unable to build a fake OpenAI response.');
        }

        return [
            'id' => (string) Str::ulid(),
            'object' => 'response',
            'status' => 'completed',
            'output' => [
                [
                    'id' => (string) Str::ulid(),
                    'type' => 'message',
                    'role' => 'assistant',
                    'status' => 'completed',
                    'content' => [
                        [
                            'type' => 'output_text',
                            'text' => $json,
                        ],
                    ],
                ],
            ],
            'output_text' => $json,
            'usage' => [
                'input_tokens' => 40,
                'output_tokens' => 12,
                'total_tokens' => 52,
            ],
        ];
    }
}
