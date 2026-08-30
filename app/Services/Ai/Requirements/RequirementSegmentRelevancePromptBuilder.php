<?php

namespace App\Services\Ai\Requirements;

use App\Data\Ai\Requirements\DocumentRequirementSegmentData;
use App\Models\SavedNoticeAiDocument;
use App\Services\Ai\AiPromptSecurity;
use JsonException;
use RuntimeException;

class RequirementSegmentRelevancePromptBuilder
{
    public const PROMPT_VERSION = '2026-04-10.segment-relevance.v1';

    public const MAX_OUTPUT_TOKENS = 256;

    public const TEMPERATURE = 0;

    /**
     * Purpose: Build the structured OpenAI payload for one segment relevance classification request.
     * Inputs: The source AI document and one source-preserving segment.
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
                    'name' => 'requirement_segment_relevance',
                    'description' => 'Structured relevance classification for one tender-document segment.',
                    'strict' => true,
                    'schema' => $this->schema(),
                ],
            ],
            'temperature' => self::TEMPERATURE,
            'max_output_tokens' => self::MAX_OUTPUT_TOKENS,
        ];
    }

    /**
     * Purpose: Return the prompt version used by the relevance classifier.
     * Inputs: None.
     * Returns: The prompt version string.
     * Side effects: None.
     */
    public function promptVersion(): string
    {
        return self::PROMPT_VERSION;
    }

    /**
     * Purpose: Return the configured OpenAI model for relevance classification.
     * Inputs: None.
     * Returns: The configured model name.
     * Side effects: None.
     */
    public function model(): string
    {
        return $this->openAiModel();
    }

    /**
     * Purpose: Build the model instructions for the relevance classifier.
     * Inputs: None.
     * Returns: A concise instruction string for OpenAI.
     * Side effects: None.
     */
    private function systemPrompt(): string
    {
        return implode("\n", [
            'You classify one document segment for requirement extraction.',
            'Relevant segments contain procurement requirements, obligations, deadlines, document requests, evaluation criteria tied to compliance, attachments, or contractual duties.',
            'Irrelevant segments are cover pages, boilerplate, tables of contents, signatures, and general background text.',
            'Return only JSON that matches the schema.',
            '',
            AiPromptSecurity::systemClause('DOCUMENT SEGMENT'),
        ]);
    }

    /**
     * Purpose: Build the user payload for the relevance classifier.
     * Inputs: The source AI document and one segment.
     * Returns: A JSON string with the minimum context needed for classification.
     * Side effects: None.
     */
    private function userPrompt(SavedNoticeAiDocument $document, DocumentRequirementSegmentData $segment): string
    {
        $payload = [
            'instruction' => 'Classify whether this segment should be sent to requirement extraction.',
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
            throw new RuntimeException('Unable to encode the segment relevance prompt payload.', 0, $exception);
        }
    }

    /**
     * Purpose: Resolve the configured OpenAI model for relevance classification.
     * Inputs: None.
     * Returns: The configured model name.
     * Side effects: None.
     */
    private function openAiModel(): string
    {
        $model = trim((string) config(
            'services.openai.requirement_relevance_model',
            config('services.openai.requirement_extraction_model', config('services.openai.model', 'gpt-4.1-mini')),
        ));

        if ($model === '') {
            throw new RuntimeException('OpenAI segment relevance model is not configured.');
        }

        return $model;
    }

    /**
     * Purpose: Define the JSON schema for the relevance classifier output.
     * Inputs: None.
     * Returns: The strict JSON schema array used for structured output.
     * Side effects: None.
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'is_relevant' => [
                    'type' => 'boolean',
                ],
                'confidence' => [
                    'type' => 'number',
                    'minimum' => 0,
                    'maximum' => 1,
                ],
                'reason' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => 500,
                ],
            ],
            'required' => [
                'is_relevant',
                'confidence',
                'reason',
            ],
            'additionalProperties' => false,
        ];
    }
}
