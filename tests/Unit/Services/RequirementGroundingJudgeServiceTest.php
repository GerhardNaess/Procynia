<?php

namespace Tests\Unit\Services;

use App\Models\SavedNoticeAiRequirement;
use App\Services\Ai\Requirements\RequirementGroundingJudgeService;
use App\Services\OpenAi\OpenAiClient;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class RequirementGroundingJudgeServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_parses_and_validates_supported_judge_payloads_with_equivalent_technical_evidence(): void
    {
        $client = Mockery::mock(OpenAiClient::class);
        $capturedPayload = null;
        $client->shouldReceive('createResponse')
            ->once()
            ->andReturnUsing(function (array $payload) use (&$capturedPayload) {
                $capturedPayload = $payload;

                return $this->openAiResponse([
                    'status' => 'supported',
                    'can_generate_answer' => true,
                    'directly_supported_points' => [
                        [
                            'requirement_point' => 'Telemetri fra Microsoft 365 og Azure.',
                            'support_summary' => 'Kunnskapsgrunnlaget beskriver innsamling av data fra skytjenester og korrelasjon av aktivitet fra Microsoft 365 og Azure.',
                            'evidence_reference' => 'Chunk 12 · SOC > Logganalyse',
                            'evidence_quote' => 'samler inn data fra skytjenester ... korrelere aktivitet fra Microsoft 365, Azure ...',
                        ],
                    ],
                    'related_but_insufficient_points' => [],
                    'unsupported_points' => [],
                    'missing_knowledge_summary' => 'Grunnlaget er tilstrekkelig.',
                    'recommended_document_title' => 'Logganalyse og telemetri',
                    'suggested_filename' => 'logganalyse-og-telemetri.docx',
                    'reasoning_summary' => 'Relevant støtte er til stede.',
                ]);
            });

        $service = new RequirementGroundingJudgeService($client);
        $requirement = $this->requirementFixture();

        $result = $service->judge(
            $requirement,
            collect([
                [
                    'score' => 0.74,
                    'knowledge_item_id' => 11,
                    'document_title' => 'Logganalyse',
                    'knowledge_item_summary' => 'Dokumentasjon om datainnsamling og korrelasjon på tvers av kilder.',
                    'chunk_id' => 101,
                    'chunk_index' => 0,
                    'chunk_type' => 'table',
                    'heading_path' => 'SOC > Logganalyse',
                    'topic' => 'Logganalyse',
                    'sub_topic' => 'Telemetri',
                    'summary_for_retrieval' => 'Tabellen viser telemetri for Microsoft 365 og Azure.',
                    'table_text' => 'Microsoft 365 | Azure',
                    'keywords' => ['Microsoft 365', 'Azure', 'logger'],
                    'section_title' => 'Logganalyse',
                    'section_path' => 'SOC > Logganalyse',
                    'content_preview' => 'Leverandøren samler inn data fra skytjenester og korrelerer aktivitet fra Microsoft 365 og Azure på tvers av samtlige logger.',
                ],
            ]),
            [
                'level' => 'amber',
                'max_score' => 0.74,
                'sources_count' => 1,
            ],
        );

        $inputPayload = json_decode((string) data_get($capturedPayload, 'input.1.content.0.text', ''), true);
        $this->assertSame('requirement_grounding_judge', data_get($capturedPayload, 'text.format.name'));
        $this->assertIsArray($inputPayload);
        $this->assertSame('Leverandøren skal støtte telemetri fra Microsoft 365 og Azure.', data_get($inputPayload, 'requirement.text'));
        $this->assertSame('amber', data_get($inputPayload, 'coverage.level'));
        $this->assertSame('SOC > Logganalyse', data_get($inputPayload, 'retrieved_knowledge_chunks.0.section_path'));
        $this->assertSame('Tabellen viser telemetri for Microsoft 365 og Azure.', data_get($inputPayload, 'retrieved_knowledge_chunks.0.summary_for_retrieval'));
        $this->assertSame('Microsoft 365 | Azure', data_get($inputPayload, 'retrieved_knowledge_chunks.0.table_text'));
        $this->assertStringContainsString('Microsoft 365', (string) data_get($inputPayload, 'retrieved_knowledge_chunks.0.content_preview', ''));
        $this->assertStringContainsString('Azure', (string) data_get($inputPayload, 'retrieved_knowledge_chunks.0.content_preview', ''));
        $this->assertStringContainsString(
            'equivalent technical wording',
            implode(' ', (array) data_get($inputPayload, 'judging_rules', [])),
        );
        $this->assertSame('Beskrivelse av en tjeneste eller prosess.', data_get($inputPayload, 'examples.directly_supported.requirement_point'));
        $this->assertNotNull(data_get($inputPayload, 'examples.directly_supported.evidence_reference'));

        $this->assertSame('supported', $result['status']);
        $this->assertTrue($result['can_generate_answer']);
        $this->assertSame('Telemetri fra Microsoft 365 og Azure.', $result['directly_supported_points'][0]['requirement_point']);
        $this->assertSame('Kunnskapsgrunnlaget beskriver innsamling av data fra skytjenester og korrelasjon av aktivitet fra Microsoft 365 og Azure.', $result['directly_supported_points'][0]['support_summary']);
        $this->assertSame('Chunk 12 · SOC > Logganalyse', $result['directly_supported_points'][0]['evidence_reference']);
        $this->assertSame('samler inn data fra skytjenester ... korrelere aktivitet fra Microsoft 365, Azure ...', $result['directly_supported_points'][0]['evidence_quote']);
        $this->assertSame([], $result['related_but_insufficient_points']);
        $this->assertSame([], $result['unsupported_points']);
        $this->assertSame(['Telemetri fra Microsoft 365 og Azure.'], $result['supported_points']);
        $this->assertSame('Grunnlaget er tilstrekkelig.', $result['missing_knowledge_summary']);
        $this->assertStringStartsWith('Dokumentasjon for ', $result['recommended_document_title']);
        $this->assertStringEndsWith('.docx', $result['suggested_filename']);
        $this->assertSame('Relevant støtte er til stede.', $result['reasoning_summary']);
    }

    public function test_it_serializes_table_chunks_with_summary_and_table_text_into_the_prompt(): void
    {
        $client = Mockery::mock(OpenAiClient::class);
        $capturedPayload = null;
        $client->shouldReceive('createResponse')
            ->once()
            ->andReturnUsing(function (array $payload) use (&$capturedPayload) {
                $capturedPayload = $payload;

                return $this->openAiResponse([
                    'status' => 'supported',
                    'can_generate_answer' => true,
                    'directly_supported_points' => [
                        [
                            'requirement_point' => 'Telemetri fra Microsoft 365 og Azure.',
                            'support_summary' => 'Kunnskapsgrunnlaget beskriver innsamling av data fra skytjenester og korrelasjon av aktivitet fra Microsoft 365 og Azure.',
                            'evidence_reference' => 'Chunk 12 · SOC > Logganalyse',
                            'evidence_quote' => 'samler inn data fra skytjenester ... korrelere aktivitet fra Microsoft 365, Azure ...',
                        ],
                    ],
                    'related_but_insufficient_points' => [],
                    'unsupported_points' => [],
                    'missing_knowledge_summary' => 'Grunnlaget er tilstrekkelig.',
                    'recommended_document_title' => 'Logganalyse og telemetri',
                    'suggested_filename' => 'logganalyse-og-telemetri.docx',
                    'reasoning_summary' => 'Relevant støtte er til stede.',
                ]);
            });

        $service = new RequirementGroundingJudgeService($client);
        $requirement = $this->requirementFixture();

        $result = $service->judge(
            $requirement,
            collect([
                [
                    'score' => 0.74,
                    'knowledge_item_id' => 11,
                    'document_title' => 'Logganalyse',
                    'knowledge_item_summary' => 'Dokumentasjon om datainnsamling og korrelasjon på tvers av kilder.',
                    'chunk_id' => 101,
                    'chunk_index' => 0,
                    'chunk_type' => 'table',
                    'heading_path' => 'SOC > Logganalyse',
                    'topic' => 'Logganalyse',
                    'sub_topic' => 'Telemetri',
                    'summary_for_retrieval' => 'Tabellen viser telemetri for Microsoft 365 og Azure.',
                    'table_text' => 'Microsoft 365 | Azure',
                    'keywords' => ['Microsoft 365', 'Azure', 'logger'],
                    'section_title' => 'Logganalyse',
                    'section_path' => 'SOC > Logganalyse',
                    'content_preview' => 'Leverandøren samler inn data fra skytjenester og korrelerer aktivitet fra Microsoft 365 og Azure på tvers av samtlige logger.',
                ],
            ]),
            [
                'level' => 'amber',
                'max_score' => 0.74,
                'sources_count' => 1,
            ],
        );

        $inputPayload = json_decode((string) data_get($capturedPayload, 'input.1.content.0.text', ''), true);
        $this->assertSame('requirement_grounding_judge', data_get($capturedPayload, 'text.format.name'));
        $this->assertIsArray($inputPayload);
        $this->assertSame('table', data_get($inputPayload, 'retrieved_knowledge_chunks.0.chunk_type'));
        $this->assertSame('Tabellen viser telemetri for Microsoft 365 og Azure.', data_get($inputPayload, 'retrieved_knowledge_chunks.0.summary_for_retrieval'));
        $this->assertSame('Microsoft 365 | Azure', data_get($inputPayload, 'retrieved_knowledge_chunks.0.table_text'));
        $this->assertStringContainsString('Microsoft 365', (string) data_get($inputPayload, 'retrieved_knowledge_chunks.0.content_preview', ''));
        $this->assertStringContainsString('Azure', (string) data_get($inputPayload, 'retrieved_knowledge_chunks.0.content_preview', ''));

        $this->assertSame('supported', $result['status']);
        $this->assertSame('Telemetri fra Microsoft 365 og Azure.', $result['directly_supported_points'][0]['requirement_point']);
        $this->assertSame('Kunnskapsgrunnlaget beskriver innsamling av data fra skytjenester og korrelasjon av aktivitet fra Microsoft 365 og Azure.', $result['directly_supported_points'][0]['support_summary']);
        $this->assertSame('Chunk 12 · SOC > Logganalyse', $result['directly_supported_points'][0]['evidence_reference']);
        $this->assertSame('samler inn data fra skytjenester ... korrelere aktivitet fra Microsoft 365, Azure ...', $result['directly_supported_points'][0]['evidence_quote']);
        $this->assertSame([], $result['related_but_insufficient_points']);
        $this->assertSame([], $result['unsupported_points']);
        $this->assertSame(['Telemetri fra Microsoft 365 og Azure.'], $result['supported_points']);
        $this->assertSame('Grunnlaget er tilstrekkelig.', $result['missing_knowledge_summary']);
        $this->assertStringStartsWith('Dokumentasjon for ', $result['recommended_document_title']);
        $this->assertStringEndsWith('.docx', $result['suggested_filename']);
        $this->assertSame('Relevant støtte er til stede.', $result['reasoning_summary']);
    }

    /**
     * Partial coverage does NOT block generation. docs/answering-strategy.md, scenario 2: "Procynia
     * skal fortsatt lage et komplett og profesjonelt svarutkast" — the quality assurance marks what
     * is documented versus professionally completed, the draft itself is still produced. Only
     * `unsupported` blocks (AiController gates on can_generate_answer alone).
     *
     * The judge therefore derives can_generate_answer from the final status instead of trusting the
     * model's own flag, which is why an inconsistent "partial + can_generate_answer: false" payload
     * still comes back generatable.
     */
    public function test_partial_coverage_still_allows_answer_generation(): void
    {
        $client = Mockery::mock(OpenAiClient::class);
        $client->shouldReceive('createResponse')
            ->once()
            ->andReturn($this->openAiResponse([
                'status' => 'partial',
                'can_generate_answer' => false,
                'directly_supported_points' => [
                    [
                        'requirement_point' => 'ITSM er dokumentert.',
                        'support_summary' => 'Etterspurt ITSM-støtte finnes, men ikke for alle konkrete kravpunkter.',
                        'evidence_reference' => 'Chunk 4 · SOC > Servicedesk',
                        'evidence_quote' => 'ITSM brukes i servicedesk-arbeidet.',
                    ],
                ],
                'related_but_insufficient_points' => ['Generell overvåkning er dokumentert.'],
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
        $this->assertTrue(
            $result['can_generate_answer'],
            'Partial coverage must still produce a draft — only unsupported blocks generation.',
        );
        $this->assertSame('ITSM er dokumentert.', $result['directly_supported_points'][0]['requirement_point']);
        $this->assertSame('Etterspurt ITSM-støtte finnes, men ikke for alle konkrete kravpunkter.', $result['directly_supported_points'][0]['support_summary']);
        // Off-topic background is dropped rather than presented as related evidence.
        $this->assertSame([], $result['related_but_insufficient_points']);
        $this->assertSame(['SPOC er ikke dokumentert.'], $result['unsupported_points']);
        $this->assertSame('SPOC mangler.', $result['missing_knowledge_summary']);
    }

    /**
     * The supported -> partial downgrade that IS still in force: a payload cannot claim full
     * documentation while also naming parts of the requirement it could not document.
     */
    public function test_it_downgrades_supported_payloads_that_still_name_unsupported_points(): void
    {
        $client = Mockery::mock(OpenAiClient::class);
        $client->shouldReceive('createResponse')
            ->once()
            ->andReturn($this->openAiResponse([
                'status' => 'supported',
                'can_generate_answer' => true,
                'directly_supported_points' => [
                    [
                        'requirement_point' => 'Telemetri fra Microsoft 365.',
                        'support_summary' => 'Innsamling fra Microsoft 365 er dokumentert.',
                        'evidence_reference' => 'Chunk 12 · SOC > Logganalyse',
                        'evidence_quote' => 'samler inn data fra Microsoft 365',
                    ],
                ],
                'related_but_insufficient_points' => [],
                'unsupported_points' => ['Telemetri fra Azure er ikke dokumentert.'],
                'missing_knowledge_summary' => 'Azure-telemetri mangler.',
                'recommended_document_title' => null,
                'suggested_filename' => null,
                'reasoning_summary' => 'Delvis dekning.',
            ]));

        $service = new RequirementGroundingJudgeService($client);

        $result = $service->judge($this->requirementFixture(), collect(), [
            'level' => 'amber',
            'max_score' => 0.61,
            'sources_count' => 1,
        ]);

        $this->assertSame('partial', $result['status']);
        $this->assertTrue($result['can_generate_answer']);
        $this->assertSame(['Telemetri fra Azure er ikke dokumentert.'], $result['unsupported_points']);
    }

    /**
     * A directly supported point may legitimately carry no evidence_reference/evidence_quote: both
     * the response schema (supportedPointSchema) and the prompt say so explicitly — "evidence_
     * reference and evidence_quote may be null when the support is clear from the supplied
     * context." Missing evidence fields are therefore not a reason to downgrade the status, and the
     * model's own can_generate_answer flag is not a status signal either.
     */
    public function test_it_keeps_supported_status_when_direct_support_carries_no_evidence_fields(): void
    {
        $client = Mockery::mock(OpenAiClient::class);
        $client->shouldReceive('createResponse')
            ->once()
            ->andReturn($this->openAiResponse([
                'status' => 'supported',
                'can_generate_answer' => false,
                'directly_supported_points' => [
                    [
                        'requirement_point' => 'Telemetri fra Microsoft 365 og Azure.',
                        'support_summary' => 'Kunnskapsgrunnlaget beskriver relevant logganalyse.',
                        'evidence_reference' => null,
                        'evidence_quote' => null,
                    ],
                ],
                'related_but_insufficient_points' => [],
                'unsupported_points' => [],
                'missing_knowledge_summary' => 'Invalid output.',
                'recommended_document_title' => null,
                'suggested_filename' => null,
                'reasoning_summary' => 'Invalid output.',
            ]));

        $service = new RequirementGroundingJudgeService($client);

        $result = $service->judge($this->requirementFixture(), collect(), [
            'level' => 'amber',
            'max_score' => 0.74,
            'sources_count' => 1,
        ]);

        $this->assertSame('supported', $result['status']);
        $this->assertTrue($result['can_generate_answer']);
        $this->assertSame([
            [
                'requirement_point' => 'Telemetri fra Microsoft 365 og Azure.',
                'support_summary' => 'Kunnskapsgrunnlaget beskriver relevant logganalyse.',
                'evidence_reference' => null,
                'evidence_quote' => null,
            ],
        ], $result['directly_supported_points']);
        $this->assertSame([], $result['related_but_insufficient_points']);
        $this->assertSame([], $result['unsupported_points']);
        $this->assertSame('Invalid output.', $result['missing_knowledge_summary']);
    }

    /**
     * "supported" never survives without a single directly supported point. What it becomes depends
     * on whether anything relevant is left: off-topic related material is filtered out first, and a
     * judgment with nothing at all left to show is unsupported, not partial — presenting "partial"
     * with an empty evidence list would claim documentation the user cannot see.
     */
    public function test_a_supported_payload_with_no_direct_points_and_only_off_topic_material_becomes_unsupported(): void
    {
        $client = Mockery::mock(OpenAiClient::class);
        $client->shouldReceive('createResponse')
            ->once()
            ->andReturn($this->openAiResponse([
                'status' => 'supported',
                'can_generate_answer' => true,
                'directly_supported_points' => [],
                'related_but_insufficient_points' => ['Generell SOC/IRT-overvåkning er dokumentert.'],
                'unsupported_points' => [],
                'missing_knowledge_summary' => 'Invalid output.',
                'recommended_document_title' => null,
                'suggested_filename' => null,
                'reasoning_summary' => 'Invalid output.',
            ]));

        $service = new RequirementGroundingJudgeService($client);

        $result = $service->judge($this->requirementFixture(), collect(), [
            'level' => 'amber',
            'max_score' => 0.74,
            'sources_count' => 1,
        ]);

        $this->assertSame('unsupported', $result['status']);
        $this->assertFalse($result['can_generate_answer']);
        $this->assertSame([], $result['directly_supported_points']);
        $this->assertSame([], $result['related_but_insufficient_points']);
        $this->assertSame([], $result['unsupported_points']);
        $this->assertSame('Invalid output.', $result['missing_knowledge_summary']);
    }

    /**
     * The other side of the same boundary: when the related material actually names the
     * requirement's own subject matter it survives the relevance filter, and the judgment lands on
     * partial rather than unsupported.
     */
    public function test_a_supported_payload_with_no_direct_points_but_on_topic_related_material_becomes_partial(): void
    {
        $client = Mockery::mock(OpenAiClient::class);
        $client->shouldReceive('createResponse')
            ->once()
            ->andReturn($this->openAiResponse([
                'status' => 'supported',
                'can_generate_answer' => true,
                'directly_supported_points' => [],
                'related_but_insufficient_points' => ['Logganalyse for Azure er beskrevet, men ikke selve telemetrileveransen.'],
                'unsupported_points' => [],
                'missing_knowledge_summary' => 'Telemetrileveransen mangler.',
                'recommended_document_title' => null,
                'suggested_filename' => null,
                'reasoning_summary' => 'Delvis relatert materiale.',
            ]));

        $service = new RequirementGroundingJudgeService($client);

        $result = $service->judge($this->requirementFixture(), collect(), [
            'level' => 'amber',
            'max_score' => 0.74,
            'sources_count' => 1,
        ]);

        $this->assertSame('partial', $result['status']);
        $this->assertTrue($result['can_generate_answer']);
        $this->assertSame([], $result['directly_supported_points']);
        $this->assertSame(
            ['Logganalyse for Azure er beskrevet, men ikke selve telemetrileveransen.'],
            $result['related_but_insufficient_points'],
        );
    }

    /**
     * Shape normalization still happens: bare strings become supported-point objects, and
     * can_generate_answer is recomputed from the status rather than taken from the payload. The
     * status itself stands, because a directly supported point is present.
     */
    public function test_it_normalizes_inconsistent_judge_payload_shapes(): void
    {
        $client = Mockery::mock(OpenAiClient::class);
        $client->shouldReceive('createResponse')
            ->once()
            ->andReturn($this->openAiResponse([
                'status' => 'supported',
                'can_generate_answer' => false,
                'directly_supported_points' => ['ITSM er dokumentert.'],
                'related_but_insufficient_points' => [],
                'unsupported_points' => [],
                'missing_knowledge_summary' => 'Invalid output.',
                'recommended_document_title' => null,
                'suggested_filename' => null,
                'reasoning_summary' => 'Invalid output.',
            ]));

        $service = new RequirementGroundingJudgeService($client);

        $result = $service->judge($this->requirementFixture(), collect(), [
            'level' => 'amber',
            'max_score' => 0.51,
            'sources_count' => 1,
        ]);

        $this->assertSame('supported', $result['status']);
        $this->assertTrue($result['can_generate_answer']);
        $this->assertSame([
            [
                'requirement_point' => 'ITSM er dokumentert.',
                'support_summary' => 'ITSM er dokumentert.',
                'evidence_reference' => null,
                'evidence_quote' => null,
            ],
        ], $result['directly_supported_points']);
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
        $requirement = new SavedNoticeAiRequirement;
        $requirement->forceFill([
            'id' => 42,
            'saved_notice_id' => 7,
            'requirement_identifier' => '1.1',
            'requirement_text' => 'Leverandøren skal støtte telemetri fra Microsoft 365 og Azure.',
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_MANDATORY,
            'approval_status' => SavedNoticeAiRequirement::APPROVAL_STATUS_DRAFT,
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_PENDING,
            'current_requirement_snapshot' => [
                'topic' => 'Logganalyse',
                'sub_topic' => 'Telemetri',
                'keywords' => ['Microsoft 365', 'Azure'],
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
