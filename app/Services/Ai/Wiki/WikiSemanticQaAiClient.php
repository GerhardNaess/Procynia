<?php

namespace App\Services\Ai\Wiki;

use App\Services\Ai\Wiki\Responses\EnterpriseWikiResponsesDecoder;
use App\Services\OpenAi\OpenAiClient;
use RuntimeException;

/**
 * Semantic QA reviewer: compares generated wiki content against the original source document.
 *
 * This reviewer does NOT modify any page version or generate new content.
 * It returns a structured diagnosis for use by the QA orchestrator (8G-4).
 *
 * Input:  extracted_text (authoritative source) + content_markdown (generated article)
 * Output: structured semantic QA result — all 10 scored/classified fields
 */
class WikiSemanticQaAiClient
{
    public const MODEL = 'gpt-4.1-mini';

    public const PROMPT_VERSION = '1.0';

    private const TEMPERATURE = 0;

    private const MAX_OUTPUT_TOKENS = 1500;

    private const MAX_SOURCE_CHARS = 15000;

    private const MAX_CONTENT_CHARS = 10000;

    private const PROMPT_NAME = 'wiki_semantic_qa';

    public function __construct(
        private readonly OpenAiClient $openAiClient,
        private readonly EnterpriseWikiResponsesDecoder $responsesDecoder,
    ) {}

    public static function isAvailable(): bool
    {
        return (bool) config('services.enterprise_wiki.ai_enabled', false);
    }

    /**
     * Review generated wiki content against the original source document.
     *
     * @return array{
     *   pass: bool,
     *   quality_score: float,
     *   coverage_score: float,
     *   factual_consistency_score: float,
     *   unsupported_claims: list<string>,
     *   missing_topics: list<string>,
     *   missing_key_facts: list<string>,
     *   critique: string,
     *   recommended_repair_action: string,
     *   confidence: float,
     *   model: string,
     *   prompt_version: string
     * }
     *
     * @throws RuntimeException when AI is disabled, the API fails, or the response is invalid
     */
    public function review(
        string $sourceText,
        string $generatedContent,
        string $languageCode,
    ): array {
        if (! self::isAvailable()) {
            throw new RuntimeException('WikiSemanticQaAiClient: wiki AI is not enabled.');
        }

        $truncatedSource = mb_substr(trim($sourceText), 0, self::MAX_SOURCE_CHARS);
        $truncatedContent = mb_substr(trim($generatedContent), 0, self::MAX_CONTENT_CHARS);

        $payload = $this->buildPayload($truncatedSource, $truncatedContent, $this->languageName($languageCode));
        $response = $this->openAiClient->createResponse($payload, timeoutSeconds: 120);
        $decoded = $this->responsesDecoder->decode($response, 'WikiSemanticQaAiClient');

        $this->validateResult($decoded);

        return [
            'pass' => (bool) $decoded['pass'],
            'quality_score' => (float) $decoded['quality_score'],
            'coverage_score' => (float) $decoded['coverage_score'],
            'factual_consistency_score' => (float) $decoded['factual_consistency_score'],
            'unsupported_claims' => array_values((array) $decoded['unsupported_claims']),
            'missing_topics' => array_values((array) $decoded['missing_topics']),
            'missing_key_facts' => array_values((array) $decoded['missing_key_facts']),
            'critique' => (string) $decoded['critique'],
            'recommended_repair_action' => (string) $decoded['recommended_repair_action'],
            'confidence' => (float) $decoded['confidence'],
            'model' => self::MODEL.'/'.self::PROMPT_VERSION,
            'prompt_version' => self::PROMPT_VERSION,
        ];
    }

    private function validateResult(array $decoded): void
    {
        $required = [
            'pass', 'quality_score', 'coverage_score', 'factual_consistency_score',
            'unsupported_claims', 'missing_topics', 'missing_key_facts',
            'critique', 'recommended_repair_action', 'confidence',
        ];

        foreach ($required as $field) {
            if (! array_key_exists($field, $decoded)) {
                throw new RuntimeException(
                    "WikiSemanticQaAiClient: response is missing required field [{$field}]."
                );
            }
        }

        if (! is_bool($decoded['pass'])) {
            throw new RuntimeException('WikiSemanticQaAiClient: response field [pass] must be boolean.');
        }

        foreach (['quality_score', 'coverage_score', 'factual_consistency_score', 'confidence'] as $field) {
            if ((! is_int($decoded[$field]) && ! is_float($decoded[$field]))
                || (float) $decoded[$field] < 0.0
                || (float) $decoded[$field] > 1.0) {
                throw new RuntimeException("WikiSemanticQaAiClient: response field [{$field}] must be numeric and between 0 and 1.");
            }
        }

        foreach (['unsupported_claims', 'missing_topics', 'missing_key_facts'] as $field) {
            if (! is_array($decoded[$field])
                || array_filter($decoded[$field], static fn (mixed $value): bool => ! is_string($value)) !== []) {
                throw new RuntimeException("WikiSemanticQaAiClient: response field [{$field}] must be an array of strings.");
            }
        }

        if (! is_string($decoded['critique']) || ! is_string($decoded['recommended_repair_action'])) {
            throw new RuntimeException('WikiSemanticQaAiClient: critique and recommended_repair_action must be strings.');
        }

        $validActions = ['none', 'targeted_revision', 'full_regeneration', 'escalate'];

        if (! in_array($decoded['recommended_repair_action'], $validActions, true)) {
            throw new RuntimeException(
                "WikiSemanticQaAiClient: invalid recommended_repair_action [{$decoded['recommended_repair_action']}]."
            );
        }
    }

    private function buildPayload(string $sourceText, string $generatedContent, string $languageName): array
    {
        return [
            'model' => self::MODEL,
            'input' => [
                [
                    'role' => 'developer',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $this->developerPrompt($languageName),
                        ],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $this->userPrompt($sourceText, $generatedContent),
                        ],
                    ],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => self::PROMPT_NAME,
                    'strict' => true,
                    'schema' => self::schema(),
                ],
            ],
            'temperature' => self::TEMPERATURE,
            'store' => false,
            'max_output_tokens' => self::MAX_OUTPUT_TOKENS,
        ];
    }

    private function developerPrompt(string $languageName): string
    {
        return implode("\n", [
            "You are a semantic QA reviewer for internal wiki content. The content language is {$languageName}.",
            '',
            'You are given:',
            '1. A SOURCE DOCUMENT (the authoritative original text)',
            '2. GENERATED WIKI CONTENT (an AI-written article based on the source)',
            '',
            'Your task: evaluate whether the generated content faithfully and sufficiently represents the source document.',
            '',
            'Evaluation criteria:',
            '- COVERAGE: Are the main topics and key facts from the source present in the generated content?',
            '- FACTUAL CONSISTENCY: Does the generated content make claims not supported by the source?',
            '- COMPLETENESS: Are important requirements, actors, dependencies, or constraints from the source missing?',
            '',
            'Scoring rules:',
            '- quality_score: overall quality of the generated content as a representation of the source (0.0–1.0)',
            '- coverage_score: proportion of the source\'s main topics covered in the generated content (0.0–1.0)',
            '- factual_consistency_score: proportion of generated claims that are supported by the source (0.0–1.0)',
            '- confidence: your confidence in this assessment (0.0–1.0)',
            '',
            'Pass/fail rules:',
            '- Set pass=true if the generated content is an accurate and sufficiently complete representation of the source.',
            '- Set pass=false if important topics are missing, unsupported claims are present, or key facts are omitted.',
            '- A concise article that covers the main points is acceptable even if not exhaustive.',
            '',
            'recommended_repair_action must be exactly one of:',
            '- "none" — content passes; no repair needed',
            '- "targeted_revision" — specific gaps or errors exist that can be fixed by targeted editing',
            '- "full_regeneration" — content is fundamentally misaligned with the source',
            '- "escalate" — source is too ambiguous or unclear to assess; human review required',
            '',
            'Return only JSON matching the schema. No text before or after JSON.',
        ]);
    }

    private function userPrompt(string $sourceText, string $generatedContent): string
    {
        return implode("\n", [
            '--- SOURCE DOCUMENT (authoritative original) ---',
            '',
            $sourceText,
            '',
            '--- GENERATED WIKI CONTENT (to evaluate) ---',
            '',
            $generatedContent,
        ]);
    }

    private static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pass' => ['type' => 'boolean'],
                'quality_score' => ['type' => 'number'],
                'coverage_score' => ['type' => 'number'],
                'factual_consistency_score' => ['type' => 'number'],
                'unsupported_claims' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'missing_topics' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'missing_key_facts' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'critique' => ['type' => 'string'],
                'recommended_repair_action' => ['type' => 'string'],
                'confidence' => ['type' => 'number'],
            ],
            'required' => [
                'pass',
                'quality_score',
                'coverage_score',
                'factual_consistency_score',
                'unsupported_claims',
                'missing_topics',
                'missing_key_facts',
                'critique',
                'recommended_repair_action',
                'confidence',
            ],
            'additionalProperties' => false,
        ];
    }

    private function languageName(string $code): string
    {
        return match ($code) {
            'en' => 'English',
            default => 'Norwegian',
        };
    }
}
