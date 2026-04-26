<?php

namespace App\Services\Ai\Requirements;

use App\Models\SavedNoticeAiRequirement;
use App\Services\OpenAi\OpenAiClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Throwable;

class RequirementGroundingJudgeService
{
    private const MAX_OUTPUT_TOKENS = 1000;

    private const TEMPERATURE = 0;

    public function __construct(
        private readonly OpenAiClient $openAiClient,
    ) {
    }

    /**
     * Purpose: Evaluate whether the retrieved knowledge supports generating an answer draft.
     * Inputs: The requirement, retrieved knowledge rows, and the existing coverage summary.
     * Returns: A strict structured grounding judgment payload.
     * Side effects: May call OpenAI and may log parse failures.
     */
    public function judge(
        SavedNoticeAiRequirement $requirement,
        Collection $retrievedKnowledgeRows,
        array $knowledgeGrounding,
    ): array {
        $response = $this->openAiClient->createResponse(
            $this->openAiRequestPayload($requirement, $retrievedKnowledgeRows, $knowledgeGrounding),
        );

        try {
            return $this->validateJudgePayload(
                $this->decodeJudgePayload($response),
            );
        } catch (Throwable $exception) {
            $this->logWarningIfAvailable('[PROCYNIA][AI_GROUNDING_JUDGE] Grounding judge failed during response parsing.', [
                'saved_notice_ai_requirement_id' => $requirement->id,
                'saved_notice_id' => $requirement->saved_notice_id,
                'request_id' => data_get($response, '_meta.request_id'),
                'response_id' => data_get($response, 'id'),
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * Purpose: Build the OpenAI Responses API payload for one grounding judgment.
     * Inputs: The requirement, retrieved knowledge rows, and coverage summary.
     * Returns: The exact request payload sent to OpenAI.
     * Side effects: None.
     */
    private function openAiRequestPayload(
        SavedNoticeAiRequirement $requirement,
        Collection $retrievedKnowledgeRows,
        array $knowledgeGrounding,
    ): array {
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
                            'text' => $this->userPrompt($requirement, $retrievedKnowledgeRows, $knowledgeGrounding),
                        ],
                    ],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'requirement_grounding_judge',
                    'description' => 'Structured grounding judgment for one tender requirement.',
                    'strict' => true,
                    'schema' => $this->judgeSchema(),
                ],
            ],
            'temperature' => self::TEMPERATURE,
            'max_output_tokens' => self::MAX_OUTPUT_TOKENS,
        ];
    }

    /**
     * Purpose: Build the developer instructions for the grounding judge.
     * Inputs: None.
     * Returns: A short instruction string for the model.
     * Side effects: None.
     */
    private function systemPrompt(): string
    {
        return implode("\n", [
            'You judge grounding quality only.',
            'You are not writing a tender answer.',
            'The requirement text is the customer request, not supplier evidence.',
            'Do not treat requirement wording as documentation of supplier capability.',
            'Only retrieved knowledge context, chunk metadata, section context and approved document context are evidence.',
            'Break the requirement into concrete requirement points before judging support.',
            'Decide whether the knowledge supports each concrete requirement point fully, partially or not at all.',
            'Separate directly supported points, related but insufficient points, and unsupported points.',
            'Directly supported means the retrieved knowledge explicitly documents the same capability, process, system, obligation or practice the requirement asks for, including clear technical equivalents when the practical meaning is the same.',
            'Related but insufficient means the retrieved knowledge is nearby or useful background, but does not prove the supplier satisfies the concrete requirement.',
            'Unsupported means the retrieved knowledge does not document the requirement point.',
            'Do not mark general SOC/IRT, monitoring, incident response, governance or portal information as directly supported unless it explicitly documents the exact requested process or capability.',
            'If a requirement asks for Microsoft change follow-up, recommended actions, consequence assessment, prioritization or change management integration, those must be documented explicitly before they are treated as directly supported.',
            'Do not require exact word matching when the same practical capability is documented with equivalent technical wording.',
            'Do not infer direct support from general domain similarity or from the mere presence of one related acronym or tool.',
            'Direct support must be anchored in the same relevant context and backed by evidence from the supplied knowledge context.',
            'Identify what is missing when the support is partial or unsupported.',
            'Return directly supported points as evidence-backed objects with requirement_point, support_summary, and evidence_reference or evidence_quote.',
            'Return only JSON that matches the schema.',
            'Write all string values in Norwegian.',
            'Be conservative when a requested capability, system, tool, process, certification, role or commitment is not explicitly documented in the supplied knowledge context.',
            'A requirement term appearing in the requirement text is not evidence by itself.',
            'Examples of direct support: a requirement about telemetry from Microsoft 365 and Azure can be directly supported by knowledge that describes collecting data from cloud services, analysing events across data sources, correlating activity from Microsoft 365 and Azure, and searching across logs.',
            'Examples of related but insufficient support: a requirement about proactive Microsoft change follow-up is not directly supported by general SOC/IRT monitoring, incident response or governance text unless the change follow-up, recommended actions, consequence assessment, prioritization and change-management integration are documented explicitly.',
        ]);
    }

    /**
     * Purpose: Build the user-facing payload for the grounding judge.
     * Inputs: The requirement, retrieved knowledge rows, and coverage summary.
     * Returns: A JSON string that is easy for the model to inspect.
     * Side effects: None.
     */
    private function userPrompt(
        SavedNoticeAiRequirement $requirement,
        Collection $retrievedKnowledgeRows,
        array $knowledgeGrounding,
    ): string {
        $payload = [
            'instruction' => 'Evaluate whether the knowledge context is sufficient to generate a safe answer draft.',
            'support_classification' => [
                'directly_supported' => 'The knowledge explicitly documents the same capability, process, system, obligation or practice the requirement asks for, including clearly equivalent technical wording when the practical meaning is the same.',
                'related_but_insufficient' => 'The knowledge is nearby or useful background, but it does not prove the supplier satisfies the concrete requirement.',
                'unsupported' => 'The knowledge does not document the requirement point.',
            ],
            'judging_rules' => [
                'Break the requirement into concrete points.',
                'Evaluate each point against the retrieved knowledge context and supporting metadata.',
                'Do not require exact word matching when equivalent technical wording clearly documents the same practical capability.',
                'Do not treat general domain similarity as direct support.',
                'Do not treat the requirement text as supplier evidence.',
            ],
            'requirement' => [
                'id' => $requirement->id,
                'identifier' => $requirement->requirement_identifier,
                'text' => $requirement->requirement_text,
                'type' => $requirement->requirement_type,
                'approval_status' => $requirement->approval_status,
                'review_status' => $requirement->review_status,
            ],
            'coverage' => [
                'level' => data_get($knowledgeGrounding, 'level'),
                'max_score' => (float) data_get($knowledgeGrounding, 'max_score', 0),
                'sources_count' => (int) data_get($knowledgeGrounding, 'sources_count', 0),
            ],
            'examples' => [
                'directly_supported' => [
                    'requirement_point' => 'Telemetri fra Microsoft 365 og Azure.',
                    'support_summary' => 'Kunnskapsgrunnlaget beskriver innsamling av data fra skytjenester, korrelasjon av aktivitet fra Microsoft 365 og Azure og søk på tvers av logger.',
                    'evidence_reference' => 'Chunk med logganalyse og korrelasjon på tvers av Microsoft 365 og Azure.',
                    'evidence_quote' => 'samler inn data fra kundens endepunkter, nettverksutstyr og skytjenester ... korrelere aktivitet fra for eksempel endepunkter, brannmurer, Microsoft 365, Azure ... søke etter ... på tvers av samtlige logger',
                ],
                'related_but_insufficient' => [
                    'requirement_point' => 'Proaktiv oppfølging av Microsoft-endringer.',
                    'support_summary' => 'Generell SOC/IRT-overvåkning og hendelseshåndtering er nyttig bakgrunn, men dokumenterer ikke Microsoft-endringsoppfølging eller anbefalte tiltak.',
                ],
            ],
            'retrieved_knowledge_strategy' => $retrievedKnowledgeRows->isEmpty()
                ? 'No relevant knowledge chunks were retrieved. Mark the grounding unsupported unless the available document context clearly proves the requirement.'
                : 'Use only the supplied knowledge context. Requirement wording is not evidence.',
            'retrieved_knowledge_chunks' => $this->promptRetrievedKnowledgeRows($retrievedKnowledgeRows),
        ];

        try {
            return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode the grounding judge prompt payload.', 0, $exception);
        }
    }

    /**
     * Purpose: Convert retrieved knowledge chunks into compact judge context.
     * Inputs: The retrieved knowledge chunk rows.
     * Returns: A deterministic array with the minimum context needed by the model.
     * Side effects: None.
     */
    private function promptRetrievedKnowledgeRows(Collection $retrievedKnowledgeRows): array
    {
        return $retrievedKnowledgeRows
            ->map(function (array $retrievalRow): array {
                $content = trim((string) data_get($retrievalRow, 'content_preview', data_get($retrievalRow, 'content', '')));
                $headingPath = trim((string) data_get($retrievalRow, 'heading_path', ''));
                $knowledgeItemTitle = trim((string) data_get($retrievalRow, 'document_title', data_get($retrievalRow, 'knowledge_item_title', '')));

                return [
                    'score' => (float) data_get($retrievalRow, 'score', 0),
                    'knowledge_item_id' => (int) data_get($retrievalRow, 'knowledge_item_id', 0),
                    'document_title' => $knowledgeItemTitle !== '' ? $knowledgeItemTitle : null,
                    'knowledge_item_summary' => $this->normalizeNullableString(data_get($retrievalRow, 'knowledge_item_summary')),
                    'chunk_id' => (int) data_get($retrievalRow, 'chunk_id', 0),
                    'chunk_index' => (int) data_get($retrievalRow, 'chunk_index', 0),
                    'heading_path' => $headingPath !== '' ? $headingPath : null,
                    'topic' => $this->normalizeNullableString(data_get($retrievalRow, 'topic')),
                    'sub_topic' => $this->normalizeNullableString(data_get($retrievalRow, 'sub_topic')),
                    'keywords' => array_values(array_filter(array_map(
                        static fn (mixed $keyword): string => trim((string) $keyword),
                        (array) data_get($retrievalRow, 'keywords', []),
                    ), static fn (string $keyword): bool => $keyword !== '')),
                    'section_title' => $this->normalizeNullableString(data_get($retrievalRow, 'section_title')),
                    'section_path' => $this->normalizeNullableString(data_get($retrievalRow, 'section_path')),
                    'content_preview' => Str::limit(Str::squish($content), 1200, '...'),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Purpose: Define the strict JSON schema for the grounding judge response.
     * Inputs: None.
     * Returns: The JSON schema array used for structured output.
     * Side effects: None.
     */
    private function judgeSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'enum' => ['supported', 'partial', 'unsupported'],
                ],
                'can_generate_answer' => [
                    'type' => 'boolean',
                ],
                'directly_supported_points' => [
                    'type' => 'array',
                    'items' => $this->supportedPointSchema(),
                ],
                'related_but_insufficient_points' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                    ],
                ],
                'unsupported_points' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                    ],
                ],
                'missing_knowledge_summary' => [
                    'type' => 'string',
                ],
                'recommended_document_title' => [
                    'type' => ['string', 'null'],
                ],
                'suggested_filename' => [
                    'type' => ['string', 'null'],
                ],
                'reasoning_summary' => [
                    'type' => 'string',
                ],
            ],
            'required' => [
                'status',
                'can_generate_answer',
                'directly_supported_points',
                'related_but_insufficient_points',
                'unsupported_points',
                'missing_knowledge_summary',
                'recommended_document_title',
                'suggested_filename',
                'reasoning_summary',
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * Purpose: Decode the model output from the OpenAI Responses API.
     * Inputs: The raw OpenAI response payload.
     * Returns: The JSON-decoded assistant output.
     * Side effects: Throws when no usable JSON payload is present.
     */
    private function decodeJudgePayload(array $response): array
    {
        $text = $this->responseTextFromOpenAi($response);
        $text = $this->stripCodeFences($text);

        if ($text === '') {
            throw new RuntimeException('OpenAI grounding judge response did not include any text output.');
        }

        try {
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('OpenAI grounding judge response was not valid JSON.', 0, $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('OpenAI grounding judge response did not decode to a JSON object.');
        }

        return $decoded;
    }

    /**
     * Purpose: Extract the assistant text payload from a Responses API result.
     * Inputs: The raw OpenAI response payload.
     * Returns: The concatenated response text.
     * Side effects: None.
     */
    private function responseTextFromOpenAi(array $response): string
    {
        $topLevelText = trim((string) data_get($response, 'output_text', ''));

        if ($topLevelText !== '') {
            return $topLevelText;
        }

        $segments = [];
        $outputItems = data_get($response, 'output', []);

        if (! is_array($outputItems)) {
            return '';
        }

        foreach ($outputItems as $outputItem) {
            if (data_get($outputItem, 'type') !== 'message' || data_get($outputItem, 'role') !== 'assistant') {
                continue;
            }

            $contentItems = data_get($outputItem, 'content', []);

            if (! is_array($contentItems)) {
                continue;
            }

            foreach ($contentItems as $contentItem) {
                $contentType = data_get($contentItem, 'type');

                if ($contentType === 'refusal') {
                    throw new RuntimeException('OpenAI refused to return a grounding judgment.');
                }

                if (in_array($contentType, ['output_text', 'text'], true)) {
                    $segment = trim((string) data_get($contentItem, 'text', ''));

                    if ($segment !== '') {
                        $segments[] = $segment;
                    }
                }
            }
        }

        return trim(implode('', $segments));
    }

    /**
     * Purpose: Remove Markdown code fences if the model wrapped the JSON payload.
     * Inputs: Raw model text.
     * Returns: The cleaned text.
     * Side effects: None.
     */
    private function stripCodeFences(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/', '', $text) ?? $text;

        return trim($text);
    }

    /**
     * Purpose: Validate the structured grounding payload before returning it.
     * Inputs: The decoded OpenAI output.
     * Returns: The validated grounding judgment payload.
     * Side effects: Throws when the payload violates the contract.
     */
    private function validateJudgePayload(array $payload): array
    {
        try {
            $status = $this->requiredStringFromPayload($payload, 'status', 255);

            if (! in_array($status, ['supported', 'partial', 'unsupported'], true)) {
                throw new RuntimeException('The status field must be one of supported, partial or unsupported.');
            }

            $canGenerateAnswer = $this->requiredBoolFromPayload($payload, 'can_generate_answer');
            $directlySupportedPoints = $this->requiredSupportedPointArrayFromValue(
                data_get($payload, 'directly_supported_points', data_get($payload, 'supported_points', [])),
                'directly_supported_points',
                1000,
            );
            $relatedButInsufficientPoints = $this->requiredStringArrayFromValue(
                data_get($payload, 'related_but_insufficient_points', []),
                'related_but_insufficient_points',
                1000,
            );
            $unsupportedPoints = $this->requiredStringArrayFromValue(
                data_get($payload, 'unsupported_points', []),
                'unsupported_points',
                1000,
            );
            $missingKnowledgeSummary = $this->requiredStringFromPayload($payload, 'missing_knowledge_summary', 1000);
            $reasoningSummary = $this->requiredStringFromPayload($payload, 'reasoning_summary', 1000);
            $recommendedDocumentTitle = $this->nullableStringFromPayload($payload, 'recommended_document_title', 255);
            $suggestedFilename = $this->nullableStringFromPayload($payload, 'suggested_filename', 255);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'OpenAI grounding judge response did not match the required contract: '.$exception->getMessage(),
                0,
                $exception,
            );
        }

        if ($status === 'supported' && $canGenerateAnswer !== true) {
            throw new RuntimeException('OpenAI grounding judge returned an inconsistent supported verdict.');
        }

        if ($status === 'supported' && $directlySupportedPoints === []) {
            throw new RuntimeException('OpenAI grounding judge marked the requirement as supported without any directly supported points.');
        }

        if ($status === 'supported' && $relatedButInsufficientPoints !== []) {
            throw new RuntimeException('OpenAI grounding judge marked the requirement as supported while still reporting related but insufficient points.');
        }

        if ($status === 'supported' && $unsupportedPoints !== []) {
            throw new RuntimeException('OpenAI grounding judge marked the requirement as supported while still reporting unsupported points.');
        }

        if ($status === 'unsupported' && $directlySupportedPoints !== []) {
            throw new RuntimeException('OpenAI grounding judge marked the requirement as unsupported while still reporting directly supported points.');
        }

        if ($status !== 'supported' && $canGenerateAnswer !== false) {
            throw new RuntimeException('OpenAI grounding judge returned an inconsistent blocking verdict.');
        }

        return [
            'status' => $status,
            'can_generate_answer' => $canGenerateAnswer,
            'directly_supported_points' => $directlySupportedPoints,
            'related_but_insufficient_points' => $this->normalizeStringList($relatedButInsufficientPoints),
            'unsupported_points' => $this->normalizeStringList($unsupportedPoints),
            'missing_knowledge_summary' => $missingKnowledgeSummary,
            'recommended_document_title' => $recommendedDocumentTitle,
            'suggested_filename' => $suggestedFilename,
            'reasoning_summary' => $reasoningSummary,
            'supported_points' => $this->supportedPointRequirementPoints($directlySupportedPoints),
        ];
    }

    /**
     * Purpose: Convert supported point objects to the compatibility string list.
     * Inputs: The normalized supported point objects.
     * Returns: A trimmed unique list of requirement point strings.
     * Side effects: None.
     */
    private function supportedPointRequirementPoints(array $supportedPoints): array
    {
        $normalized = [];
        $seen = [];

        foreach ($supportedPoints as $supportedPoint) {
            $requirementPoint = trim((string) data_get($supportedPoint, 'requirement_point', ''));

            if ($requirementPoint === '') {
                continue;
            }

            $key = mb_strtolower($requirementPoint, 'UTF-8');

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = $requirementPoint;
        }

        return $normalized;
    }

    /**
     * Purpose: Define the schema for one directly supported point in the judge response.
     * Inputs: None.
     * Returns: The JSON schema array for a supported point object.
     * Side effects: None.
     */
    private function supportedPointSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'requirement_point' => [
                    'type' => 'string',
                ],
                'support_summary' => [
                    'type' => 'string',
                ],
                'evidence_reference' => [
                    'type' => ['string', 'null'],
                ],
                'evidence_quote' => [
                    'type' => ['string', 'null'],
                ],
            ],
            'required' => [
                'requirement_point',
                'support_summary',
                'evidence_reference',
                'evidence_quote',
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * Purpose: Normalize a string list into trimmed non-empty values.
     * Inputs: An array of strings.
     * Returns: A deterministic list of strings.
     * Side effects: None.
     */
    private function normalizeStringList(array $values): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $values,
        ), static fn (string $value): bool => $value !== ''));
    }

    /**
     * Purpose: Normalize a possibly nullable string into a trimmed nullable value.
     * Inputs: A raw scalar or null.
     * Returns: A trimmed string or null.
     * Side effects: None.
     */
    private function normalizeNullableString(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * Purpose: Read and validate a required string field from a judge payload.
     * Inputs: The raw payload, the field name, and the maximum length.
     * Returns: The trimmed string value.
     * Side effects: Throws when the field is missing or invalid.
     */
    private function requiredStringFromPayload(array $payload, string $field, int $maxLength): string
    {
        $value = data_get($payload, $field);

        if (! is_string($value)) {
            throw new RuntimeException(sprintf('The %s field is required.', str_replace('_', ' ', $field)));
        }

        $value = trim($value);

        if ($value === '') {
            throw new RuntimeException(sprintf('The %s field is required.', str_replace('_', ' ', $field)));
        }

        if (mb_strlen($value, 'UTF-8') > $maxLength) {
            throw new RuntimeException(sprintf('The %s field is too long.', str_replace('_', ' ', $field)));
        }

        return $value;
    }

    /**
     * Purpose: Read and validate a required boolean field from a judge payload.
     * Inputs: The raw payload and the field name.
     * Returns: The boolean value.
     * Side effects: Throws when the field is missing or invalid.
     */
    private function requiredBoolFromPayload(array $payload, string $field): bool
    {
        $value = data_get($payload, $field);

        if (! is_bool($value)) {
            throw new RuntimeException(sprintf('The %s field is required.', str_replace('_', ' ', $field)));
        }

        return $value;
    }

    /**
     * Purpose: Read and validate a required string array from a judge payload.
     * Inputs: The raw payload, the field name, and the maximum item length.
     * Returns: The array of raw string items.
     * Side effects: Throws when the field is missing or invalid.
     */
    private function requiredStringArrayFromPayload(array $payload, string $field, int $maxLength): array
    {
        $value = data_get($payload, $field);

        if (! is_array($value)) {
            throw new RuntimeException(sprintf('The %s field is required.', str_replace('_', ' ', $field)));
        }

        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new RuntimeException(sprintf('The %s field must contain only strings.', str_replace('_', ' ', $field)));
            }

            if (mb_strlen(trim($item), 'UTF-8') > $maxLength) {
                throw new RuntimeException(sprintf('The %s field contains an item that is too long.', str_replace('_', ' ', $field)));
            }
        }

        return $value;
    }

    /**
     * Purpose: Validate an already extracted array value from a judge payload.
     * Inputs: The raw value, the logical field name, and the maximum item length.
     * Returns: The validated array of raw string items.
     * Side effects: Throws when the value is missing or invalid.
     */
    private function requiredStringArrayFromValue(mixed $value, string $field, int $maxLength): array
    {
        if (! is_array($value)) {
            throw new RuntimeException(sprintf('The %s field is required.', str_replace('_', ' ', $field)));
        }

        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new RuntimeException(sprintf('The %s field must contain only strings.', str_replace('_', ' ', $field)));
            }

            if (mb_strlen(trim($item), 'UTF-8') > $maxLength) {
                throw new RuntimeException(sprintf('The %s field contains an item that is too long.', str_replace('_', ' ', $field)));
            }
        }

        return $value;
    }

    /**
     * Purpose: Validate the directly supported point objects returned by the judge.
     * Inputs: The raw value, field name and maximum string length.
     * Returns: A normalized array of supported point objects.
     * Side effects: Throws when the value does not match the expected contract.
     */
    private function requiredSupportedPointArrayFromValue(mixed $value, string $field, int $maxLength): array
    {
        if (! is_array($value)) {
            throw new RuntimeException(sprintf('The %s field is required.', str_replace('_', ' ', $field)));
        }

        $normalized = [];

        foreach ($value as $item) {
            if (! is_array($item)) {
                throw new RuntimeException(sprintf('The %s field must contain only objects.', str_replace('_', ' ', $field)));
            }

            $requirementPoint = $this->requiredStringFromArrayItem($item, 'requirement_point', $maxLength);
            $supportSummary = $this->requiredStringFromArrayItem($item, 'support_summary', $maxLength);
            $evidenceReference = $this->nullableStringFromArrayItem($item, 'evidence_reference', $maxLength);
            $evidenceQuote = $this->nullableStringFromArrayItem($item, 'evidence_quote', $maxLength);

            if ($evidenceReference === null && $evidenceQuote === null) {
                throw new RuntimeException(sprintf(
                    'The %s field must include either evidence_reference or evidence_quote.',
                    str_replace('_', ' ', $field),
                ));
            }

            $normalized[] = [
                'requirement_point' => $requirementPoint,
                'support_summary' => $supportSummary,
                'evidence_reference' => $evidenceReference,
                'evidence_quote' => $evidenceQuote,
            ];
        }

        return $normalized;
    }

    /**
     * Purpose: Read and validate a required string field from a judge item array.
     * Inputs: The item array, the field name, and the maximum length.
     * Returns: The trimmed string value.
     * Side effects: Throws when the field is missing or invalid.
     */
    private function requiredStringFromArrayItem(array $item, string $field, int $maxLength): string
    {
        $value = data_get($item, $field);

        if (! is_string($value)) {
            throw new RuntimeException(sprintf('The %s field is required.', str_replace('_', ' ', $field)));
        }

        $value = trim($value);

        if ($value === '') {
            throw new RuntimeException(sprintf('The %s field is required.', str_replace('_', ' ', $field)));
        }

        if (mb_strlen($value, 'UTF-8') > $maxLength) {
            throw new RuntimeException(sprintf('The %s field is too long.', str_replace('_', ' ', $field)));
        }

        return $value;
    }

    /**
     * Purpose: Read and normalize an optional string field from a judge item array.
     * Inputs: The item array, field name, and maximum length.
     * Returns: A trimmed string or null.
     * Side effects: Throws when the field type is invalid or too long.
     */
    private function nullableStringFromArrayItem(array $item, string $field, int $maxLength): ?string
    {
        $value = data_get($item, $field);

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new RuntimeException(sprintf('The %s field must be a string or null.', str_replace('_', ' ', $field)));
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value, 'UTF-8') > $maxLength) {
            throw new RuntimeException(sprintf('The %s field is too long.', str_replace('_', ' ', $field)));
        }

        return $value;
    }

    /**
     * Purpose: Read and validate an optional string field from a judge payload.
     * Inputs: The raw payload, the field name, and the maximum length.
     * Returns: A trimmed string or null.
     * Side effects: Throws when the field is invalid.
     */
    private function nullableStringFromPayload(array $payload, string $field, int $maxLength): ?string
    {
        $value = data_get($payload, $field);

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new RuntimeException(sprintf('The %s field must be a string or null.', str_replace('_', ' ', $field)));
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value, 'UTF-8') > $maxLength) {
            throw new RuntimeException(sprintf('The %s field is too long.', str_replace('_', ' ', $field)));
        }

        return $value;
    }

    /**
     * Purpose: Return the configured OpenAI model for grounding judge requests.
     * Inputs: None.
     * Returns: The configured model name.
     * Side effects: None.
     */
    private function openAiModel(): string
    {
        $model = 'gpt-4.1-mini';

        try {
            if (function_exists('app')) {
                $container = app();

                if (method_exists($container, 'bound') && $container->bound('config')) {
                    $config = $container->make('config');

                    $model = trim((string) $config->get(
                        'services.openai.requirement_answer_model',
                        $config->get('services.openai.model', $model),
                    ));
                }
            }
        } catch (Throwable) {
            $model = 'gpt-4.1-mini';
        }

        if ($model === '') {
            throw new RuntimeException('OpenAI grounding judge model is not configured.');
        }

        return $model;
    }

    /**
     * Purpose: Log a grounding-judge warning when the logging facility is available.
     * Inputs: A log message and structured context.
     * Returns: None.
     * Side effects: Logs the warning only when the container has a log binding.
     */
    private function logWarningIfAvailable(string $message, array $context): void
    {
        try {
            if (! function_exists('app')) {
                return;
            }

            $container = app();

            if (! method_exists($container, 'bound') || ! $container->bound('log')) {
                return;
            }

            $logger = $container->make('log');

            if (is_object($logger) && method_exists($logger, 'warning')) {
                $logger->warning($message, $context);
            }
        } catch (Throwable) {
            // Intentionally swallow logging failures in non-Laravel unit tests.
        }
    }
}
