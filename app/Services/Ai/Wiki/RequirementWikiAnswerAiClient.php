<?php

namespace App\Services\Ai\Wiki;

use App\Services\Ai\Wiki\Responses\EnterpriseWikiResponsesDecoder;
use App\Services\OpenAi\OpenAiClient;
use RuntimeException;

/**
 * Generates a requirement answer using only approved, customer-scoped Enterprise Wiki claims
 * (see RequirementWikiAnswerService) — never the existing Knowledge Base/RAG pipeline, never the
 * existing answer-draft flow. Follows the same established Wiki AI client conventions as
 * WikiPageClaimExtractionAiClient: fixed model, ENTERPRISE_WIKI_AI_ENABLED gate, shared
 * EnterpriseWikiResponsesDecoder.
 *
 * COVERAGE CONTRACT: coverage_status is one of 'full' (the claims fully answer the requirement),
 * 'partial' (the claims address part of the requirement — missing_summary must then describe the
 * gap), or 'none' (the claims do not provide enough to answer at all — answer_text must then be
 * null; the model must never invent an answer when coverage is 'none').
 */
class RequirementWikiAnswerAiClient
{
    private const MODEL = 'gpt-4.1-mini';

    private const TEMPERATURE = 0;

    private const MAX_OUTPUT_TOKENS = 2000;

    private const MAX_CLAIM_TEXT_CHARS = 500;

    private const PROMPT_NAME = 'requirement_wiki_answer';

    public function __construct(
        private readonly OpenAiClient $openAiClient,
        private readonly EnterpriseWikiResponsesDecoder $responsesDecoder,
    ) {}

    public static function isAvailable(): bool
    {
        return (bool) config('services.enterprise_wiki.ai_enabled', false);
    }

    /**
     * @param  list<array{claim_key: string, claim_text: string}>  $candidateClaims
     * @return array{coverage_status: string, answer_text: ?string, missing_summary: ?string, used_claim_keys: list<string>}
     *
     * @throws RuntimeException on API error, empty response, invalid JSON, or a malformed schema result
     */
    public function generateAnswer(
        string $requirementIdentifier,
        string $requirementText,
        array $candidateClaims,
        string $languageCode,
    ): array {
        if (! self::isAvailable()) {
            throw new RuntimeException('RequirementWikiAnswerAiClient: wiki AI generation is not enabled.');
        }

        $trimmedClaims = array_map(
            static fn (array $claim): array => [
                'claim_key' => (string) $claim['claim_key'],
                'claim_text' => mb_substr(trim((string) $claim['claim_text']), 0, self::MAX_CLAIM_TEXT_CHARS),
            ],
            $candidateClaims,
        );

        $payload = $this->buildPayload($requirementIdentifier, $requirementText, $trimmedClaims, $this->languageName($languageCode));
        $response = $this->openAiClient->createResponse($payload);
        $decoded = $this->responsesDecoder->decode($response, 'RequirementWikiAnswerAiClient');

        return $this->normalize($decoded, $trimmedClaims);
    }

    /**
     * @param  list<array{claim_key: string, claim_text: string}>  $candidateClaims
     * @return array{coverage_status: string, answer_text: ?string, missing_summary: ?string, used_claim_keys: list<string>}
     */
    private function normalize(array $decoded, array $candidateClaims): array
    {
        $coverageStatus = $decoded['coverage_status'] ?? null;

        if (! is_string($coverageStatus) || ! in_array($coverageStatus, ['full', 'partial', 'none'], true)) {
            throw new RuntimeException('RequirementWikiAnswerAiClient: response coverage_status was missing or invalid.');
        }

        $answerText = $decoded['answer_text'] ?? null;

        if (! (is_string($answerText) || $answerText === null)) {
            throw new RuntimeException('RequirementWikiAnswerAiClient: response answer_text was malformed.');
        }

        // Never persist a fabricated answer when the model itself reports no coverage.
        if ($coverageStatus === 'none') {
            $answerText = null;
        } else {
            $answerText = is_string($answerText) ? trim($answerText) : null;
            $answerText = $answerText !== '' ? $answerText : null;
        }

        $missingSummary = $decoded['missing_summary'] ?? null;
        $missingSummary = is_string($missingSummary) ? trim($missingSummary) : null;
        $missingSummary = $missingSummary !== '' ? $missingSummary : null;

        $validClaimKeys = array_column($candidateClaims, 'claim_key');
        $usedClaimKeys = $decoded['used_claim_keys'] ?? [];
        $usedClaimKeys = is_array($usedClaimKeys) ? $usedClaimKeys : [];
        $usedClaimKeys = array_values(array_intersect(
            array_map(static fn (mixed $key): string => (string) $key, $usedClaimKeys),
            $validClaimKeys,
        ));

        return [
            'coverage_status' => $coverageStatus,
            'answer_text' => $answerText,
            'missing_summary' => $missingSummary,
            'used_claim_keys' => $usedClaimKeys,
        ];
    }

    /**
     * @param  list<array{claim_key: string, claim_text: string}>  $candidateClaims
     */
    private function buildPayload(string $requirementIdentifier, string $requirementText, array $candidateClaims, string $languageName): array
    {
        $claimsBlock = implode("\n", array_map(
            static fn (array $claim): string => sprintf('[%s] %s', $claim['claim_key'], $claim['claim_text']),
            $candidateClaims,
        ));

        $userText = implode("\n\n", array_filter([
            'REQUIREMENT IDENTIFIER: '.($requirementIdentifier !== '' ? $requirementIdentifier : '(none)'),
            'REQUIREMENT TEXT: '.$requirementText,
            "APPROVED WIKI CLAIMS:\n".$claimsBlock,
        ]));

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
                            'text' => $userText,
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
            'You answer a single procurement requirement using ONLY the approved Enterprise Wiki claims provided below.',
            "Answer language: {$languageName}.",
            'Rules:',
            '- Use only the provided claims. Never use external knowledge, assumptions, or anything not stated in the claims.',
            '- Set coverage_status to "full" only when the claims together fully answer the requirement.',
            '- Set coverage_status to "partial" when the claims address part of the requirement. In this case answer_text must contain the partial answer, and missing_summary must clearly state what is missing.',
            '- Set coverage_status to "none" when the claims do not provide enough to answer the requirement at all. In this case answer_text must be null — never invent or guess an answer.',
            '- used_claim_keys must list only the claim_key values of claims you actually relied on.',
            '- Return only JSON matching the schema. No text before or after JSON.',
        ]);
    }

    private static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'coverage_status' => [
                    'type' => 'string',
                    'enum' => ['full', 'partial', 'none'],
                ],
                'answer_text' => [
                    'anyOf' => [
                        ['type' => 'string'],
                        ['type' => 'null'],
                    ],
                ],
                'missing_summary' => [
                    'anyOf' => [
                        ['type' => 'string'],
                        ['type' => 'null'],
                    ],
                ],
                'used_claim_keys' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
            'required' => ['coverage_status', 'answer_text', 'missing_summary', 'used_claim_keys'],
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
