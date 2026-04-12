<?php

namespace App\Services\Ai\Requirements;

use App\Data\Ai\Requirements\RequirementExtractionBlockData;
use App\Data\Ai\Requirements\RequirementExtractionCandidateData;
use App\Models\SavedNoticeAiDocument;
use App\Models\SavedNoticeAiRequirement;
use Illuminate\Support\Collection;
use JsonException;
use RuntimeException;

class RequirementExtractionPromptBuilder
{
    public const PROMPT_VERSION = '2026-04-08.v1';

    /**
     * Purpose: Build the complete OpenAI Responses API payload for requirement extraction.
     * Inputs: The source AI document and the contextual extraction blocks.
     * Returns: The exact payload sent to OpenAI.
     * Side effects: None.
     */
    public function buildRequestPayload(SavedNoticeAiDocument $document, Collection $blocks): array
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
                            'text' => $this->userPrompt($document, $blocks),
                        ],
                    ],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'requirement_extraction',
                    'description' => 'Structured tender requirement extraction result for Procynia.',
                    'strict' => true,
                    'schema' => $this->schema(),
                ],
            ],
            'temperature' => 0,
            'max_output_tokens' => 3000,
        ];
    }

    /**
     * Purpose: Return the prompt version used for traceability.
     * Inputs: None.
     * Returns: The prompt version string.
     * Side effects: None.
     */
    public function promptVersion(): string
    {
        return self::PROMPT_VERSION;
    }

    /**
     * Purpose: Return the configured OpenAI model used for structured block extraction.
     * Inputs: None.
     * Returns: The model name.
     * Side effects: None.
     */
    public function model(): string
    {
        return $this->openAiModel();
    }

    private function systemPrompt(): string
    {
        return implode("\n", [
            'You extract answerable tender requirements for Procynia.',
            'The inputs are contextual document blocks, not isolated trigger words.',
            'Return only structured JSON that matches the schema.',
            'Keep original_text as the readable source-language requirement text.',
            'Keep normalized_text as a whitespace-normalized semantically useful version.',
            'Preserve requirement IDs when present, but do not invent IDs.',
            'Only return real requirements that a bidder must answer, commit to, or comply with.',
            'Exclude background text, purpose statements, general information, evaluation text, and descriptive prose that is not a requirement.',
            'If a block contains multiple separable requirements, return multiple candidates.',
            'If no real requirement is present, return an empty candidates array.',
            'Write explanatory fields in Norwegian when possible.',
        ]);
    }

    private function userPrompt(SavedNoticeAiDocument $document, Collection $blocks): string
    {
        $payload = [
            'instruction' => 'Extract only real requirement candidates from the document blocks.',
            'document' => [
                'saved_notice_ai_document_id' => $document->id,
                'saved_notice_id' => $document->saved_notice_id,
                'title' => $document->original_filename,
                'original_filename' => $document->original_filename,
            ],
            'blocks' => $blocks
                ->map(static fn (RequirementExtractionBlockData $block): array => $block->toPromptArray())
                ->values()
                ->all(),
        ];

        try {
            return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode the requirement extraction prompt payload.', 0, $exception);
        }
    }

    private function openAiModel(): string
    {
        $model = trim((string) config('services.openai.requirement_extraction_model', config('services.openai.model', 'gpt-4.1-mini')));

        if ($model === '') {
            throw new RuntimeException('OpenAI requirement extraction model is not configured.');
        }

        return $model;
    }

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

    private function candidateSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'source_document_id' => [
                    'type' => 'integer',
                ],
                'source_block_id' => [
                    'type' => 'string',
                ],
                'source_block_index' => [
                    'type' => 'integer',
                ],
                'requirement_identifier' => [
                    'anyOf' => [
                        [
                            'type' => 'string',
                        ],
                        [
                            'type' => 'null',
                        ],
                    ],
                ],
                'parent_reference' => [
                    'anyOf' => [
                        [
                            'type' => 'string',
                        ],
                        [
                            'type' => 'null',
                        ],
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
                        [
                            'type' => 'string',
                        ],
                        [
                            'type' => 'null',
                        ],
                    ],
                ],
                'evaluation_notes' => [
                    'anyOf' => [
                        [
                            'type' => 'string',
                        ],
                        [
                            'type' => 'null',
                        ],
                    ],
                ],
                'response_expectation' => [
                    'anyOf' => [
                        [
                            'type' => 'string',
                        ],
                        [
                            'type' => 'null',
                        ],
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
                'source_reference' => [
                    'type' => 'object',
                    'properties' => [
                        'saved_notice_ai_document_id' => [
                            'type' => 'integer',
                        ],
                        'saved_notice_ai_document_chunk_id' => [
                            'type' => 'integer',
                        ],
                        'source_block_id' => [
                            'type' => 'string',
                        ],
                        'source_block_index' => [
                            'type' => 'integer',
                        ],
                        'document_filename' => [
                            'anyOf' => [
                                [
                                    'type' => 'string',
                                ],
                                [
                                    'type' => 'null',
                                ],
                            ],
                        ],
                        'chunk_index' => [
                            'type' => 'integer',
                        ],
                        'char_start' => [
                            'anyOf' => [
                                [
                                    'type' => 'integer',
                                ],
                                [
                                    'type' => 'null',
                                ],
                            ],
                        ],
                        'char_end' => [
                            'anyOf' => [
                                [
                                    'type' => 'integer',
                                ],
                                [
                                    'type' => 'null',
                                ],
                            ],
                        ],
                        'source_chunk_ids' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'integer',
                            ],
                        ],
                    ],
                    'required' => [
                        'saved_notice_ai_document_id',
                        'saved_notice_ai_document_chunk_id',
                        'source_block_id',
                        'source_block_index',
                        'document_filename',
                        'chunk_index',
                        'char_start',
                        'char_end',
                        'source_chunk_ids',
                    ],
                    'additionalProperties' => false,
                ],
                'interpretation_risk' => [
                    'anyOf' => [
                        [
                            'type' => 'string',
                            'enum' => RequirementExtractionCandidateData::INTERPRETATION_RISKS,
                        ],
                        [
                            'type' => 'null',
                        ],
                    ],
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
                'source_document_id',
                'source_block_id',
                'source_block_index',
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
                'source_reference',
                'interpretation_risk',
                'is_requirement',
                'confidence',
                'warnings',
            ],
            'additionalProperties' => false,
        ];
    }
}
