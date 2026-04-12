<?php

namespace App\Services\Ai\Requirements;

use App\Data\Ai\Requirements\DocumentRequirementSegmentData;
use App\Data\Ai\Requirements\RequirementExtractionCandidateData;
use App\Models\SavedNoticeAiDocument;
use App\Models\SavedNoticeAiRequirement;
use JsonException;
use RuntimeException;

class RequirementSegmentExtractionPromptBuilder
{
    public const PROMPT_VERSION = '2026-04-10.segment-extraction.v1';

    public const MAX_OUTPUT_TOKENS = 1200;

    public const TEMPERATURE = 0;

    /**
     * Purpose: Build the structured OpenAI payload for one relevant segment extraction request.
     * Inputs: The source AI document and one relevant segment.
     * Returns: The exact payload sent to OpenAI.
     * Side effects: None.
     */
    public function buildRequestPayload(SavedNoticeAiDocument $document, DocumentRequirementSegmentData $segment): array
    {
        return [
            'model' => $this->openAiModel(),
            'input' => [
                [
                    'role' => 'developer',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $this->systemPrompt(),
                        ],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $this->userPrompt($document, $segment),
                        ],
                    ],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'requirement_segment_extraction',
                    'description' => 'Structured requirement extraction result for one document segment.',
                    'strict' => true,
                    'schema' => $this->schema(),
                ],
            ],
            'temperature' => self::TEMPERATURE,
            'max_output_tokens' => self::MAX_OUTPUT_TOKENS,
        ];
    }

    /**
     * Purpose: Return the prompt version used by the extraction stage.
     * Inputs: None.
     * Returns: The prompt version string.
     * Side effects: None.
     */
    public function promptVersion(): string
    {
        return self::PROMPT_VERSION;
    }

    /**
     * Purpose: Return the configured OpenAI model for segment extraction.
     * Inputs: None.
     * Returns: The configured model name.
     * Side effects: None.
     */
    public function model(): string
    {
        return $this->openAiModel();
    }

    /**
     * Purpose: Build the developer instructions for the segment extraction stage.
     * Inputs: None.
     * Returns: A concise instruction string for OpenAI.
     * Side effects: None.
     */
    private function systemPrompt(): string
    {
        return implode("\n", [
            'You extract atomic tender requirement candidates from one relevant document segment.',
            'Use only the provided segment text and metadata.',
            'Return only JSON that matches the schema.',
            'Keep original_text exact and normalized_text compact.',
            'If no real requirement is present, return an empty candidates array.',
            'Do not invent identifiers or provenance.',
        ]);
    }

    /**
     * Purpose: Build the user-facing JSON payload for the extraction stage.
     * Inputs: The source AI document and one relevant segment.
     * Returns: A JSON string with the minimum useful context for extraction.
     * Side effects: None.
     */
    private function userPrompt(SavedNoticeAiDocument $document, DocumentRequirementSegmentData $segment): string
    {
        $payload = [
            'instruction' => 'Extract only real requirement candidates from this segment.',
            'document' => [
                'saved_notice_ai_document_id' => $document->id,
                'saved_notice_id' => $document->saved_notice_id,
                'title' => $document->original_filename,
                'original_filename' => $document->original_filename,
            ],
            'segment' => $segment->toPromptArray(),
        ];

        try {
            return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode the segment extraction prompt payload.', 0, $exception);
        }
    }

    /**
     * Purpose: Resolve the configured OpenAI model for extraction requests.
     * Inputs: None.
     * Returns: The configured model name.
     * Side effects: None.
     */
    private function openAiModel(): string
    {
        $model = trim((string) config('services.openai.requirement_extraction_model', config('services.openai.model', 'gpt-4.1-mini')));

        if ($model === '') {
            throw new RuntimeException('OpenAI requirement extraction model is not configured.');
        }

        return $model;
    }

    /**
     * Purpose: Define the JSON schema for the segment extraction output.
     * Inputs: None.
     * Returns: The strict JSON schema array used for structured output.
     * Side effects: None.
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'candidates' => [
                    'type' => 'array',
                    'items' => $this->candidateSchema(),
                ],
            ],
            'required' => [
                'candidates',
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * Purpose: Define the JSON schema for one extracted requirement candidate.
     * Inputs: None.
     * Returns: The strict JSON schema for a single candidate row.
     * Side effects: None.
     */
    private function candidateSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'requirement_identifier' => [
                    'anyOf' => [
                        ['type' => 'string'],
                        ['type' => 'null'],
                    ],
                ],
                'parent_reference' => [
                    'anyOf' => [
                        ['type' => 'string'],
                        ['type' => 'null'],
                    ],
                ],
                'requirement_type' => [
                    'type' => 'string',
                    'enum' => SavedNoticeAiRequirement::REQUIREMENT_TYPES,
                ],
                'obligation_type' => [
                    'type' => 'string',
                    'enum' => RequirementExtractionCandidateData::OBLIGATION_TYPES,
                ],
                'original_text' => [
                    'type' => 'string',
                ],
                'normalized_text' => [
                    'type' => 'string',
                ],
                'comment' => [
                    'anyOf' => [
                        ['type' => 'string'],
                        ['type' => 'null'],
                    ],
                ],
                'evaluation_notes' => [
                    'anyOf' => [
                        ['type' => 'string'],
                        ['type' => 'null'],
                    ],
                ],
                'response_expectation' => [
                    'anyOf' => [
                        ['type' => 'string'],
                        ['type' => 'null'],
                    ],
                ],
                'expected_evidence' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                    ],
                ],
                'keywords' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                    ],
                ],
                'domain' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                    ],
                ],
                'related_references' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                    ],
                ],
                'source_excerpt' => [
                    'type' => 'string',
                ],
                'source_page_start' => [
                    'anyOf' => [
                        ['type' => 'integer'],
                        ['type' => 'null'],
                    ],
                ],
                'source_page_end' => [
                    'anyOf' => [
                        ['type' => 'integer'],
                        ['type' => 'null'],
                    ],
                ],
                'source_section_title' => [
                    'anyOf' => [
                        ['type' => 'string'],
                        ['type' => 'null'],
                    ],
                ],
                'interpretation_risk' => [
                    'type' => 'string',
                    'enum' => RequirementExtractionCandidateData::INTERPRETATION_RISKS,
                ],
                'is_requirement' => [
                    'type' => 'boolean',
                ],
                'confidence' => [
                    'type' => 'number',
                    'minimum' => 0,
                    'maximum' => 1,
                ],
                'warnings' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                    ],
                ],
            ],
            'required' => [
                'requirement_identifier',
                'parent_reference',
                'requirement_type',
                'obligation_type',
                'original_text',
                'normalized_text',
                'comment',
                'evaluation_notes',
                'response_expectation',
                'expected_evidence',
                'keywords',
                'domain',
                'related_references',
                'source_excerpt',
                'source_page_start',
                'source_page_end',
                'source_section_title',
                'interpretation_risk',
                'is_requirement',
                'confidence',
                'warnings',
            ],
            'additionalProperties' => false,
        ];
    }
}
