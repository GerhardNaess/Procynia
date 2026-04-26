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
    private const MAX_OUTPUT_TOKENS = 4000;

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
            $decoded = $this->decodeJudgePayload($response);

            return $this->validateJudgePayload(
                $decoded,
                (string) $requirement->requirement_text,
                $this->normalizeNullableString($requirement->requirement_identifier),
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
            'Only retrieved knowledge context, chunk metadata, section context and approved document context are evidence.',
            'Decide whether the retrieved knowledge gives enough documented material to generate a safe answer draft.',
            'Break specific requirements into concrete requirement points.',
            'For broad descriptive requirements, judge whether the knowledge context gives enough concrete material to describe the requested service, capability, process or approach safely.',
            'A requested answer length, word count, formatting instruction or instruction to describe something is not itself a missing knowledge point.',
            'Do not require every possible detail for a broad descriptive answer when the customer only asks for an overall description.',
            'If the requirement asks for specific named systems, tools, standards, roles, commitments, integrations or obligations, those specific elements must be documented before they are directly supported.',
            'Separate directly supported points, related but insufficient points, and unsupported points.',
            'Directly supported means the knowledge documents the same capability, process, service, obligation or practice the requirement asks for, including clear technical equivalents when the practical meaning is the same.',
            'Related but insufficient means the knowledge is in the same primary subject, capability or delivery area as the requirement, but does not prove the concrete requirement point.',
            'Nearby operational background alone is not related but insufficient when it only shares generic words with the requirement.',
            'Unsupported means the knowledge does not document the requirement point or only shares broad generic concepts with it.',
            'Do not require exact word matching when the same practical capability is documented with equivalent technical wording.',
            'Do not infer direct support from broad domain similarity alone.',
            'Do not infer related but insufficient support from generic vocabulary overlap alone.',
            'Generic words such as service, delivery, operation, analysis, responsibility, measure, deadline, report, platform, process and customer are not enough by themselves.',
            'Use the supplied requirement relevance profile to identify the requirement-specific terms that must anchor related evidence.',
            'When the closest evidence is only broad background from another subject area, leave related_but_insufficient_points empty and explain the requirement-specific documentation gap in unsupported_points and missing_knowledge_summary.',
            'Do not treat requirement wording as supplier evidence.',
            'Return directly supported points as objects with requirement_point, support_summary, evidence_reference and evidence_quote.',
            'evidence_reference and evidence_quote may be null when the support is clear from the supplied context.',
            'Return recommended_document_title and suggested_filename based on the requirement-specific subject matter when documentation is missing.',
            'Do not return generic document names such as Dokumentasjon for udekket krav when the requirement text contains usable context.',
            'Return only JSON that matches the schema.',
            'Write all string values in Norwegian.',
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
                'directly_supported_service_description' => 'When the requirement asks to describe a service or solution, a document that describes that same service or solution and its operating model counts as direct support.',
                'related_but_insufficient' => 'The knowledge shares the same primary subject, capability or delivery area as the requirement, but it does not prove the supplier satisfies the concrete requirement.',
                'unsupported' => 'The knowledge does not document the requirement point, or it only shares broad generic vocabulary with the requirement.',
            ],
            'judging_rules' => [
                'Break the requirement into concrete points.',
                'Evaluate each point against the retrieved knowledge context and supporting metadata.',
                'For broad descriptive requirements, do not treat answer length or requested word count as a missing knowledge point.',
                'For broad descriptive requirements, decide whether the retrieved knowledge gives enough concrete material for a safe bounded description.',
                'Do not require exact word matching when equivalent technical wording clearly documents the same practical capability.',
                'Do not treat length, word count, page count, tone or formatting requirements as missing documentation when the substantive capability is documented.',
                'Treat a broad request to describe a service or solution as directly supported when the retrieved knowledge describes that same service or solution at an operating-model level.',
                'Do not treat general domain similarity as direct support.',
                'Do not treat broad generic vocabulary overlap as related_but_insufficient.',
                'Only populate related_but_insufficient_points when the evidence shares the same primary subject, capability or delivery area as the requirement.',
                'Use requirement_relevance_profile.significant_requirement_terms as anchors for deciding whether nearby evidence is actually related.',
                'If evidence is from a different subject area, put the requirement-specific gap in unsupported_points instead of related_but_insufficient_points.',
                'Do not treat the requirement text as supplier evidence.',
                'When documentation is missing, recommended_document_title and suggested_filename must use the requirement-specific subject matter instead of a generic fallback name.',
            ],
            'requirement_relevance_profile' => $this->requirementRelevanceProfileFromRequirementText((string) $requirement->requirement_text),
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
                    'requirement_point' => 'Beskrivelse av en tjeneste eller prosess.',
                    'support_summary' => 'Kunnskapsgrunnlaget beskriver formål, omfang, leveransemodell, roller og arbeidsprosesser godt nok til å lage en trygg overordnet beskrivelse.',
                    'evidence_reference' => 'Chunk med relevant tjeneste- eller prosessbeskrivelse.',
                    'evidence_quote' => null,
                ],
                'related_but_insufficient' => [
                    'requirement_point' => 'Spesifikk forpliktelse, integrasjon eller egenskap i samme primære fagområde som kravet.',
                    'support_summary' => 'Kunnskapsgrunnlaget beskriver samme leveranseområde, men dokumenterer ikke den konkrete forpliktelsen, integrasjonen eller egenskapen kravet ber om.',
                ],
                'unsupported_due_to_generic_overlap' => [
                    'requirement_point' => 'Kravspesifikk evne eller leveranse som ikke er dokumentert.',
                    'support_summary' => 'Kunnskapsgrunnlaget deler bare generelle ord med kravet og dokumenterer ikke samme primære fagområde eller konkrete leveranse.',
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
     * Purpose: Build a generic relevance profile from the requirement so broad vocabulary overlap is not mistaken for useful related evidence.
     * Inputs: The raw requirement text.
     * Returns: Significant requirement terms, ignored generic overlap terms and filtering metadata.
     * Side effects: None.
     */
    private function requirementRelevanceProfileFromRequirementText(string $requirementText): array
    {
        $cleanedRequirement = $this->requirementTextWithoutAnswerLengthInstructions($requirementText);
        $tokens = $this->significantRequirementTokensFromText($cleanedRequirement);

        return [
            'significant_requirement_terms' => $tokens,
            'ignored_generic_overlap_terms' => $this->genericOverlapTerms(),
            'strict_related_filtering' => $tokens !== [],
            'related_but_insufficient_instruction' => $tokens === []
                ? 'Use normal semantic judgment, but do not use generic vocabulary overlap alone.'
                : 'Only classify evidence as related but insufficient when it shares the same primary subject, capability or delivery area as these significant requirement terms. Generic vocabulary alone is unsupported, not related.',
        ];
    }

    /**
     * Purpose: Remove answer-length wording before extracting relevance terms.
     * Inputs: The raw requirement text.
     * Returns: Requirement text without customer answer-length instructions.
     * Side effects: None.
     */
    private function requirementTextWithoutAnswerLengthInstructions(string $requirementText): string
    {
        $text = Str::lower(Str::squish($requirementText));
        $text = preg_replace('/\b(?:besvarelsen|svaret)\s+skal\s+være\s+(?:på|ca\.?|cirka|omtrent|maks|maksimum|inntil)\s+\d{2,5}\s+ord\b/u', ' ', $text) ?? $text;
        $text = preg_replace('/\b(?:på|ca\.?|cirka|omtrent|maks|maksimum|inntil)\s+\d{2,5}\s+ord\b/u', ' ', $text) ?? $text;

        return Str::squish($text);
    }

    /**
     * Purpose: Extract domain-agnostic significant terms from a requirement.
     * Inputs: Cleaned requirement text.
     * Returns: Unique lower-case terms that represent the requirement-specific subject matter.
     * Side effects: None.
     */
    private function significantRequirementTokensFromText(string $text): array
    {
        $matches = [];
        preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\-\/]{2,}/u', Str::lower($text), $matches);

        $stopTerms = array_flip(array_merge($this->genericOverlapTerms(), [
            'alle',
            'andre',
            'basert',
            'beskrive',
            'beskrivelse',
            'besvarelse',
            'besvarelsen',
            'bruk',
            'denne',
            'dette',
            'eller',
            'etter',
            'flere',
            'for',
            'fra',
            'gjennom',
            'gjelder',
            'hvor',
            'ikke',
            'innen',
            'inkludert',
            'kan',
            'konkrete',
            'krav',
            'kravet',
            'kunden',
            'kundens',
            'kunne',
            'leverandør',
            'leverandøren',
            'leveranser',
            'levere',
            'minst',
            'månedlig',
            'månedlige',
            'når',
            'også',
            'over',
            'samt',
            'skal',
            'som',
            'støtte',
            'til',
            'under',
            'ved',
            'være',
        ]));

        $tokens = [];
        $seen = [];

        foreach ((array) ($matches[0] ?? []) as $token) {
            $token = trim((string) $token, " \t\n\r\0\x0B-/.,;:()[]{}");

            if ($token === '' || mb_strlen($token, 'UTF-8') < 4) {
                continue;
            }

            if (isset($stopTerms[$token])) {
                continue;
            }

            if (is_numeric($token)) {
                continue;
            }

            if (isset($seen[$token])) {
                continue;
            }

            $seen[$token] = true;
            $tokens[] = $token;
        }

        return array_slice($tokens, 0, 20);
    }

    /**
     * Purpose: List generic words that must not by themselves make evidence related to a requirement.
     * Inputs: None.
     * Returns: Domain-agnostic generic overlap terms.
     * Side effects: None.
     */
    private function genericOverlapTerms(): array
    {
        return [
            'analyse',
            'analyser',
            'anbefaling',
            'anbefalinger',
            'ansvar',
            'dokumentasjon',
            'drift',
            'frist',
            'frister',
            'kunde',
            'leveranse',
            'plattform',
            'prosess',
            'prosesser',
            'rapport',
            'rapportering',
            'samhandling',
            'styring',
            'tiltak',
            'tjeneste',
            'tjenester',
        ];
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
    private function validateJudgePayload(array $payload, string $requirementText, ?string $requirementIdentifier = null): array
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
            $relatedButInsufficientPoints = $this->flexibleStringArrayFromValue(
                data_get($payload, 'related_but_insufficient_points', []),
                'related_but_insufficient_points',
                1000,
            );
            $relatedButInsufficientPoints = $this->filterRelatedButInsufficientPointsByRequirementRelevance(
                $relatedButInsufficientPoints,
                $requirementText,
            );
            $unsupportedPoints = $this->flexibleStringArrayFromValue(
                data_get($payload, 'unsupported_points', []),
                'unsupported_points',
                1000,
            );
            $missingKnowledgeSummary = $this->nullableStringFromPayload($payload, 'missing_knowledge_summary', 1000);
            $reasoningSummary = $this->nullableStringFromPayload($payload, 'reasoning_summary', 1000);
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
            $status = 'partial';
            $canGenerateAnswer = false;
        }

        if ($status === 'supported' && $directlySupportedPoints === []) {
            $status = 'partial';
            $canGenerateAnswer = false;
        }

        if ($status !== 'supported') {
            $canGenerateAnswer = false;
        }

        if ($status === 'unsupported' && $directlySupportedPoints !== []) {
            $status = 'partial';
        }

        if ($missingKnowledgeSummary === null) {
            $missingKnowledgeSummary = $status === 'supported'
                ? 'Kunnskapsgrunnlaget dokumenterer kravet godt nok til å lage et trygt svarutkast.'
                : 'Kunnskapsgrunnlaget dokumenterer ikke kravet godt nok til å lage et trygt svarutkast.';
        }

        if ($reasoningSummary === null) {
            $reasoningSummary = $missingKnowledgeSummary;
        }

        $documentSuggestion = $this->requirementSpecificDocumentSuggestion(
            $requirementText,
            $requirementIdentifier,
            $recommendedDocumentTitle,
            $suggestedFilename,
        );
        $recommendedDocumentTitle = $documentSuggestion['recommended_document_title'];
        $suggestedFilename = $documentSuggestion['suggested_filename'];

        return [
            'status' => $status,
            'can_generate_answer' => $status === 'supported' && $canGenerateAnswer === true,
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
     * Purpose: Create requirement-specific document title and filename suggestions when the model returns generic missing-document names.
     * Inputs: Requirement text, optional requirement identifier, and model-produced title and filename.
     * Returns: A normalized title and filename that keep meaningful model values but replace generic fallbacks.
     * Side effects: None.
     */
    private function requirementSpecificDocumentSuggestion(
        string $requirementText,
        ?string $requirementIdentifier,
        ?string $recommendedDocumentTitle,
        ?string $suggestedFilename,
    ): array {
        $generatedTitle = $this->recommendedDocumentTitleFromRequirementText($requirementText, $requirementIdentifier);
        $generatedFilename = $this->suggestedFilenameFromRecommendedDocumentTitle($generatedTitle);

        if ($this->shouldReplaceRecommendedDocumentTitle($recommendedDocumentTitle)) {
            $recommendedDocumentTitle = $generatedTitle;
        }

        if ($this->shouldReplaceSuggestedFilename($suggestedFilename)) {
            $suggestedFilename = $generatedFilename;
        }

        return [
            'recommended_document_title' => $recommendedDocumentTitle,
            'suggested_filename' => $suggestedFilename,
        ];
    }

    /**
     * Purpose: Build a readable Norwegian document title from the requirement's own significant terms.
     * Inputs: Requirement text and an optional requirement identifier for fallback context.
     * Returns: A deterministic document title for missing documentation.
     * Side effects: None.
     */
    private function recommendedDocumentTitleFromRequirementText(string $requirementText, ?string $requirementIdentifier): string
    {
        $terms = $this->significantRequirementDisplayTermsFromText($requirementText);

        if ($terms !== []) {
            return Str::limit('Dokumentasjon for '.$this->norwegianDisplayList($terms), 120, '');
        }

        if ($requirementIdentifier !== null && $requirementIdentifier !== '') {
            return Str::limit('Dokumentasjon for krav '.$requirementIdentifier, 120, '');
        }

        return 'Dokumentasjon for kravkontekst';
    }

    /**
     * Purpose: Build a safe DOCX filename from the normalized recommended document title.
     * Inputs: A recommended document title.
     * Returns: A lower-case slug filename ending in .docx.
     * Side effects: None.
     */
    private function suggestedFilenameFromRecommendedDocumentTitle(string $recommendedDocumentTitle): string
    {
        $slug = Str::slug($recommendedDocumentTitle, '-');

        if ($slug === '') {
            $slug = 'dokumentasjon-for-kravkontekst';
        }

        return Str::limit($slug, 110, '').'.docx';
    }

    /**
     * Purpose: Decide whether a recommended document title is missing or only a generic fallback.
     * Inputs: A nullable model-produced title.
     * Returns: True when Procynia should replace it with a requirement-specific title.
     * Side effects: None.
     */
    private function shouldReplaceRecommendedDocumentTitle(?string $title): bool
    {
        $normalized = Str::lower(Str::squish((string) ($title ?? '')));

        if ($normalized === '') {
            return true;
        }

        return in_array($normalized, [
            'dokumentasjon for udekket krav',
            'dokumentasjon for krav',
            'dokumentasjon for manglende krav',
            'dokumentasjon for manglende dokumentasjon',
        ], true);
    }

    /**
     * Purpose: Decide whether a suggested filename is missing or only a generic fallback.
     * Inputs: A nullable model-produced filename.
     * Returns: True when Procynia should replace it with a requirement-specific filename.
     * Side effects: None.
     */
    private function shouldReplaceSuggestedFilename(?string $filename): bool
    {
        $normalized = Str::lower(Str::squish((string) ($filename ?? '')));

        if ($normalized === '') {
            return true;
        }

        return in_array($normalized, [
            'dokumentasjon-for-udekket-krav.docx',
            'dokumentasjon-for-krav.docx',
            'dokumentasjon-for-manglende-krav.docx',
            'dokumentasjon-for-manglende-dokumentasjon.docx',
        ], true);
    }

    /**
     * Purpose: Extract display terms from the requirement for document names without using domain-specific hardcoding.
     * Inputs: The raw requirement text.
     * Returns: Requirement-specific terms in original order and casing where available.
     * Side effects: None.
     */
    private function significantRequirementDisplayTermsFromText(string $requirementText): array
    {
        $cleanedRequirement = $this->requirementTextWithoutAnswerLengthInstructionsForDisplay($requirementText);
        $matches = [];
        preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\-\/]{2,}/u', $cleanedRequirement, $matches);

        $stopTerms = array_flip(array_merge($this->genericOverlapTerms(), [
            'alle',
            'andre',
            'basert',
            'beskrive',
            'beskrivelse',
            'besvarelse',
            'besvarelsen',
            'bruk',
            'denne',
            'dette',
            'eller',
            'etter',
            'flere',
            'for',
            'fra',
            'gjennom',
            'gjelder',
            'hvor',
            'ikke',
            'innen',
            'inkludert',
            'kan',
            'konkrete',
            'krav',
            'kravet',
            'kunden',
            'kundens',
            'kunne',
            'leverandør',
            'leverandøren',
            'leveranser',
            'levere',
            'minst',
            'månedlig',
            'månedlige',
            'når',
            'også',
            'over',
            'samt',
            'skal',
            'som',
            'støtte',
            'til',
            'under',
            'ved',
            'være',
        ]));

        $terms = [];
        $seen = [];

        foreach ((array) ($matches[0] ?? []) as $term) {
            $term = trim((string) $term, " \t\n\r\0\x0B-/.,;:()[]{}");
            $normalized = Str::lower(Str::squish($term));

            if ($normalized === '' || mb_strlen($normalized, 'UTF-8') < 4) {
                continue;
            }

            if (isset($stopTerms[$normalized])) {
                continue;
            }

            if (is_numeric($normalized)) {
                continue;
            }

            if (isset($seen[$normalized])) {
                continue;
            }

            $seen[$normalized] = true;
            $terms[] = $term;

            if (count($terms) >= 6) {
                break;
            }
        }

        return $terms;
    }

    /**
     * Purpose: Remove answer-length wording while preserving original casing for document-name generation.
     * Inputs: The raw requirement text.
     * Returns: Requirement text without answer-length instructions.
     * Side effects: None.
     */
    private function requirementTextWithoutAnswerLengthInstructionsForDisplay(string $requirementText): string
    {
        $text = Str::squish($requirementText);
        $text = preg_replace('/\b(?:besvarelsen|svaret)\s+skal\s+være\s+(?:på|ca\.?|cirka|omtrent|maks|maksimum|inntil)\s+\d{2,5}\s+ord\b/iu', ' ', $text) ?? $text;
        $text = preg_replace('/\b(?:på|ca\.?|cirka|omtrent|maks|maksimum|inntil)\s+\d{2,5}\s+ord\b/iu', ' ', $text) ?? $text;

        return Str::squish($text);
    }

    /**
     * Purpose: Format requirement-specific terms as a short Norwegian display list.
     * Inputs: A list of extracted terms.
     * Returns: A readable phrase for titles.
     * Side effects: None.
     */
    private function norwegianDisplayList(array $terms): string
    {
        $terms = array_values(array_filter(array_map(
            static fn (mixed $term): string => trim((string) $term),
            $terms,
        ), static fn (string $term): bool => $term !== ''));

        if (count($terms) <= 1) {
            return (string) ($terms[0] ?? 'kravkontekst');
        }

        $last = array_pop($terms);

        return implode(', ', $terms).' og '.$last;
    }
    /**
     * Purpose: Remove related-but-insufficient points that only match generic vocabulary and not the requirement-specific subject matter.
     * Inputs: Model-produced related points and the raw requirement text.
     * Returns: A filtered related point list that keeps only same-subject nearby evidence.
     * Side effects: None.
     */
    private function filterRelatedButInsufficientPointsByRequirementRelevance(array $points, string $requirementText): array
    {
        $profile = $this->requirementRelevanceProfileFromRequirementText($requirementText);

        if (data_get($profile, 'strict_related_filtering') !== true) {
            return $points;
        }

        $significantTerms = array_values(array_filter(
            (array) data_get($profile, 'significant_requirement_terms', []),
            static fn (mixed $term): bool => is_string($term) && trim($term) !== '',
        ));

        if ($significantTerms === []) {
            return $points;
        }

        $filtered = [];

        foreach ($points as $point) {
            $normalizedPoint = Str::lower(Str::squish((string) $point));

            foreach ($significantTerms as $term) {
                $normalizedTerm = Str::lower(Str::squish($term));

                if ($normalizedTerm !== '' && str_contains($normalizedPoint, $normalizedTerm)) {
                    $filtered[] = $point;
                    break;
                }
            }
        }

        return $filtered;
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
     * Purpose: Normalize a model-produced list that may contain strings or simple point objects.
     * Inputs: The raw value, logical field name and maximum string length.
     * Returns: A normalized list of point texts.
     * Side effects: Throws only when the list itself is not usable.
     */
    private function flexibleStringArrayFromValue(mixed $value, string $field, int $maxLength): array
    {
        if (! is_array($value)) {
            throw new RuntimeException(sprintf('The %s field is required.', str_replace('_', ' ', $field)));
        }

        $normalized = [];

        foreach ($value as $item) {
            if (is_string($item)) {
                $text = trim($item);
            } elseif (is_array($item)) {
                $text = trim((string) (data_get($item, 'text')
                    ?? data_get($item, 'requirement_point')
                    ?? data_get($item, 'support_summary')
                    ?? ''));
            } else {
                throw new RuntimeException(sprintf('The %s field must contain strings or simple objects.', str_replace('_', ' ', $field)));
            }

            if ($text === '') {
                continue;
            }

            if (mb_strlen($text, 'UTF-8') > $maxLength) {
                throw new RuntimeException(sprintf('The %s field contains an item that is too long.', str_replace('_', ' ', $field)));
            }

            $normalized[] = $text;
        }

        return $normalized;
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
            if (is_string($item)) {
                $text = trim($item);

                if ($text === '') {
                    continue;
                }

                if (mb_strlen($text, 'UTF-8') > $maxLength) {
                    throw new RuntimeException(sprintf('The %s field contains an item that is too long.', str_replace('_', ' ', $field)));
                }

                $normalized[] = [
                    'requirement_point' => $text,
                    'support_summary' => $text,
                    'evidence_reference' => null,
                    'evidence_quote' => null,
                ];

                continue;
            }

            if (! is_array($item)) {
                throw new RuntimeException(sprintf('The %s field must contain only objects or strings.', str_replace('_', ' ', $field)));
            }

            $requirementPoint = $this->requiredStringFromArrayItem($item, 'requirement_point', $maxLength);
            $supportSummary = $this->requiredStringFromArrayItem($item, 'support_summary', $maxLength);
            $evidenceReference = $this->nullableStringFromArrayItem($item, 'evidence_reference', $maxLength);
            $evidenceQuote = $this->nullableStringFromArrayItem($item, 'evidence_quote', $maxLength);

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
