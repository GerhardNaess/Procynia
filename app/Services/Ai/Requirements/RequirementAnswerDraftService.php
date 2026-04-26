<?php

namespace App\Services\Ai\Requirements;

use App\Models\KnowledgeItem;
use App\Models\SavedNoticeAiAnswerBasisItem;
use App\Models\SavedNoticeAiEvidence;
use App\Models\SavedNoticeAiRequirement;
use App\Services\OpenAi\OpenAiClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Throwable;

class RequirementAnswerDraftService
{
    private const MAX_EVIDENCE_ROWS = 5;

    private const MAX_OUTPUT_TOKENS = 1200;

    private const TEMPERATURE = 0;

    public function __construct(
        private readonly OpenAiClient $openAiClient,
    ) {
    }

    /**
     * Purpose: Generate and persist one canonical editable answer draft for a requirement.
     * Inputs: The requirement to draft.
     * Returns: The persisted requirement row with a canonical answer draft.
     * Side effects: May call OpenAI and updates the requirement row.
     */
    public function ensureAnswerDraft(
        SavedNoticeAiRequirement $requirement,
        Collection $answerBasisItems,
        bool $forceGenerate = false,
        ?string $caseInstructions = null,
        ?Collection $retrievedKnowledgeChunks = null,
        ?array $groundingJudge = null,
    ): SavedNoticeAiRequirement
    {
        $requirement->loadMissing([
            'evidence.knowledgeItem',
            'evidence.knowledgeItemChunk',
        ]);

        $evidenceRows = $this->answerEvidenceRows($requirement);
        $answerBasisRows = $this->answerBasisRows($answerBasisItems);
        $retrievedKnowledgeRows = $this->retrievedKnowledgeRows($retrievedKnowledgeChunks);
        $answerDraftText = $this->requestAnswerDraft(
            $requirement,
            $evidenceRows,
            $answerBasisRows,
            $caseInstructions,
            $retrievedKnowledgeRows,
            $groundingJudge,
        );

        return DB::transaction(function () use ($requirement, $answerDraftText): SavedNoticeAiRequirement {
            $requirement->forceFill([
                'answer_draft_text' => $answerDraftText,
                'answer_draft_generated_at' => now(),
            ])->save();

            return $requirement->refresh();
        });
    }

    /**
     * Purpose: Persist an edited answer draft for a requirement.
     * Inputs: The requirement and the updated answer text.
     * Returns: The persisted requirement row with the edited draft.
     * Side effects: Updates the requirement row.
     */
    public function updateAnswerDraft(SavedNoticeAiRequirement $requirement, string $answerDraftText): SavedNoticeAiRequirement
    {
        $normalizedAnswerDraftText = $this->normalizeDraftText($answerDraftText);

        if ($normalizedAnswerDraftText === '') {
            throw new RuntimeException('Answer draft text cannot be empty.');
        }

        return DB::transaction(function () use ($requirement, $normalizedAnswerDraftText): SavedNoticeAiRequirement {
            $requirement->forceFill([
                'answer_draft_text' => $normalizedAnswerDraftText,
                'answer_draft_generated_at' => $requirement->answer_draft_generated_at ?? now(),
            ])->save();

            return $requirement->refresh();
        });
    }

    /**
     * Purpose: Send the requirement and its evidence context to OpenAI and parse the structured answer draft response.
     * Inputs: The requirement and the evidence rows that should ground the draft.
     * Returns: The validated answer draft text.
     * Side effects: Calls the OpenAI Responses API.
     */
    private function requestAnswerDraft(
        SavedNoticeAiRequirement $requirement,
        Collection $evidenceRows,
        Collection $answerBasisRows,
        ?string $caseInstructions,
        Collection $retrievedKnowledgeRows,
        ?array $groundingJudge,
    ): string
    {
        $response = $this->openAiClient->createResponse(
            $this->openAiRequestPayload(
                $requirement,
                $evidenceRows,
                $answerBasisRows,
                $caseInstructions,
                $retrievedKnowledgeRows,
                $groundingJudge,
            ),
        );

        try {
            return $this->validateAnswerDraftPayload(
                $this->decodeAnswerDraftPayload($response),
            );
        } catch (Throwable $exception) {
            Log::warning('OpenAI requirement answer draft failed during response parsing.', [
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
     * Purpose: Build the OpenAI Responses API payload for one requirement answer draft.
     * Inputs: The requirement and the evidence rows used as input.
     * Returns: The exact request payload sent to OpenAI.
     * Side effects: None.
     */
    private function openAiRequestPayload(
        SavedNoticeAiRequirement $requirement,
        Collection $evidenceRows,
        Collection $answerBasisRows,
        ?string $caseInstructions,
        Collection $retrievedKnowledgeRows,
        ?array $groundingJudge,
    ): array
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
                            'text' => $this->userPrompt(
                                $requirement,
                                $evidenceRows,
                                $answerBasisRows,
                                $caseInstructions,
                                $retrievedKnowledgeRows,
                                $groundingJudge,
                            ),
                        ],
                    ],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'requirement_answer_draft',
                    'description' => 'Editable supplier answer draft for one tender requirement.',
                    'strict' => true,
                    'schema' => $this->answerDraftSchema(),
                ],
            ],
            'temperature' => self::TEMPERATURE,
            'max_output_tokens' => self::MAX_OUTPUT_TOKENS,
        ];
    }

    /**
     * Purpose: Build the developer instructions for the answer draft generator.
     * Inputs: None.
     * Returns: A short instruction string for the model.
     * Side effects: None.
     */
    private function systemPrompt(): string
    {
        return implode("\n", [
            'You draft one editable supplier answer for one tender requirement.',
            'Use the requirement text only to understand the customer request; it is not evidence.',
            'Use only the provided evidence, selected answer basis items, retrieved knowledge chunks, and grounding judge as factual basis.',
            'Use the selected answer basis items as the primary supplier-owned source material when drafting the answer.',
            'Use the retrieved knowledge chunks as the primary grounded source material for factual content.',
            'If a capability, system, tool, process, certification, role, or commitment is not documented in the supplied knowledge context, do not claim it.',
            'Use only directly_supported_points from the grounding judge as allowed claim candidates.',
            'Each directly_supported_points item is a concrete evidence-backed object with requirement_point, support_summary, and evidence_reference or evidence_quote.',
            'Stay within the evidence-backed requirement_point and support_summary for each allowed claim candidate.',
            'Treat related_but_insufficient_points as context only, not as supplier evidence.',
            'Do not use unsupported_points or related_but_insufficient_points as supplier claims.',
            'Apply the case-specific AI instructions from the user payload for tone, terminology, style, and capitalization.',
            'If the case-specific instructions conflict with grounded facts or the JSON schema, keep the facts and schema.',
            'Return only JSON that matches the schema.',
            'Write all string values in Norwegian.',
            'Do not write an assessment, critique, or coverage commentary.',
            'Do not invent facts that are not supported by the evidence.',
            'Do not use unselected answer basis items.',
            'Do not copy requirement wording into the answer unless it is also grounded in the supplied knowledge context.',
            'If the provided sources are thin, keep the answer conservative and only state supported facts.',
            'Keep the answer practical, concise, and suitable for direct editing by the user.',
        ]);
    }

    /**
     * Purpose: Build the user-facing payload for the answer draft request.
     * Inputs: The requirement and its evidence rows.
     * Returns: A JSON string that is easy for the model to inspect.
     * Side effects: None.
     */
    private function userPrompt(
        SavedNoticeAiRequirement $requirement,
        Collection $evidenceRows,
        Collection $answerBasisRows,
        ?string $caseInstructions,
        Collection $retrievedKnowledgeRows,
        ?array $groundingJudge,
    ): string
    {
        $payload = [
            'instruction' => 'Generate one editable supplier answer draft for this requirement.',
            'requirement' => [
                'id' => $requirement->id,
                'identifier' => $requirement->requirement_identifier,
                'text' => $requirement->requirement_text,
                'type' => $requirement->requirement_type,
                'approval_status' => $requirement->approval_status,
                'review_status' => $requirement->review_status,
            ],
            'answer_style' => 'Write a practical answer a supplier could submit after editing. Do not assess compliance.',
            'answer_basis_strategy' => $answerBasisRows->isEmpty()
                ? 'No answer basis items are selected. Draft from the requirement text and evidence only.'
                : 'Use the selected answer basis items as the primary supplier-owned source material. Keep the wording grounded in those items.',
            'answer_basis_items' => $this->promptAnswerBasisRows($answerBasisRows),
            'evidence_strategy' => $evidenceRows->isEmpty()
                ? 'No evidence rows are available. Draft from the requirement text only.'
                : 'Use the supplied evidence only. Selected evidence has priority over suggested evidence.',
            'evidence' => $this->promptEvidenceRows($evidenceRows),
            'retrieved_knowledge_strategy' => $retrievedKnowledgeRows->isEmpty()
                ? 'No relevant knowledge chunks were retrieved. Do not invent supplier claims.'
                : 'Use the retrieved knowledge chunks and grounding judge as the factual foundation. Requirement wording is not evidence.',
            'retrieved_knowledge_chunks' => $this->promptRetrievedKnowledgeRows($retrievedKnowledgeRows),
            'grounding_judge_strategy' => is_array($groundingJudge)
                ? 'The grounding judge is an internal guardrail. Use only directly_supported_points as allowed claim candidates. Treat related_but_insufficient_points as context only. Do not use related_but_insufficient_points or unsupported_points as supplier claims.'
                : 'No grounding judge payload was supplied.',
            'grounding_judge' => $this->promptGroundingJudge($groundingJudge),
        ];

        $normalizedCaseInstructions = $this->normalizeCaseInstructions($caseInstructions);

        if ($normalizedCaseInstructions !== null) {
            $payload['case_instructions'] = $normalizedCaseInstructions;
        }

        try {
            return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode the requirement answer draft prompt payload.', 0, $exception);
        }
    }

    /**
     * Purpose: Select the persisted evidence rows that should ground the answer draft.
     * Inputs: The requirement with its evidence relation loaded.
     * Returns: A short, deterministic collection of selected or suggested evidence rows.
     * Side effects: None.
     */
    private function answerEvidenceRows(SavedNoticeAiRequirement $requirement): Collection
    {
        $selectedRows = $this->sortEvidenceRows($requirement->evidence
            ->filter(static fn (SavedNoticeAiEvidence $evidence): bool => $evidence->selection_status === SavedNoticeAiEvidence::SELECTION_STATUS_SELECTED))
            ->take(self::MAX_EVIDENCE_ROWS)
            ->values();

        if ($selectedRows->isNotEmpty()) {
            return $selectedRows;
        }

        return $this->sortEvidenceRows($requirement->evidence
            ->filter(static fn (SavedNoticeAiEvidence $evidence): bool => $evidence->selection_status === SavedNoticeAiEvidence::SELECTION_STATUS_SUGGESTED))
            ->take(self::MAX_EVIDENCE_ROWS)
            ->values();
    }

    /**
     * Purpose: Normalize the selected answer basis items for the prompt context.
     * Inputs: The selected basis item collection.
     * Returns: A deterministic array with the minimum context needed by the model.
     * Side effects: None.
     */
    private function answerBasisRows(Collection $answerBasisItems): Collection
    {
        return $answerBasisItems
            ->filter(static fn ($item): bool => $item instanceof SavedNoticeAiAnswerBasisItem)
            ->values();
    }

    /**
     * Purpose: Order evidence rows deterministically for prompt snapshots.
     * Inputs: A collection of evidence rows.
     * Returns: The same collection sorted by canonical evidence priority.
     * Side effects: None.
     */
    private function sortEvidenceRows(Collection $evidenceRows): Collection
    {
        return $evidenceRows
            ->sort(function (SavedNoticeAiEvidence $left, SavedNoticeAiEvidence $right): int {
                if ($left->is_primary !== $right->is_primary) {
                    return $right->is_primary <=> $left->is_primary;
                }

                if ($left->match_rank !== $right->match_rank) {
                    return $left->match_rank <=> $right->match_rank;
                }

                return $left->id <=> $right->id;
            })
            ->values();
    }

    /**
     * Purpose: Convert evidence rows into the prompt context structure.
     * Inputs: The answer draft evidence rows.
     * Returns: A deterministic array with the minimum context needed by the model.
     * Side effects: None.
     */
    private function promptEvidenceRows(Collection $evidenceRows): array
    {
        return $evidenceRows
            ->map(static function (SavedNoticeAiEvidence $evidence): array {
                $knowledgeItem = $evidence->knowledgeItem;
                $knowledgeChunk = $evidence->knowledgeItemChunk;
                $knowledgeDocumentType = $knowledgeItem?->document_type ?? $knowledgeItem?->content_type;

                return [
                    'id' => $evidence->id,
                    'selection_status' => $evidence->selection_status,
                    'match_type' => $evidence->match_type,
                    'match_score' => $evidence->match_score,
                    'match_rank' => $evidence->match_rank,
                    'is_primary' => $evidence->is_primary,
                    'knowledge_item' => [
                        'id' => $knowledgeItem?->id,
                        'title' => $knowledgeItem?->title,
                        'original_filename' => $knowledgeItem?->original_filename,
                        'document_type' => $knowledgeDocumentType,
                        'document_type_label' => filled($knowledgeDocumentType)
                            ? (KnowledgeItem::DOCUMENT_TYPE_LABELS[$knowledgeDocumentType] ?? $knowledgeDocumentType)
                            : null,
                    ],
                    'knowledge_chunk' => [
                        'id' => $knowledgeChunk?->id,
                        'chunk_index' => $knowledgeChunk?->chunk_index,
                        'content' => $knowledgeChunk?->content,
                        'start_offset' => $knowledgeChunk?->start_offset,
                        'end_offset' => $knowledgeChunk?->end_offset,
                    ],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Purpose: Convert answer basis rows into the prompt context structure.
     * Inputs: The selected answer basis rows.
     * Returns: A deterministic array with the minimum context needed by the model.
     * Side effects: None.
     */
    private function promptAnswerBasisRows(Collection $answerBasisRows): array
    {
        return $answerBasisRows
            ->map(static function (SavedNoticeAiAnswerBasisItem $answerBasisItem): array {
                return [
                    'id' => $answerBasisItem->id,
                    'type' => $answerBasisItem->answer_basis_type,
                    'type_label' => $answerBasisItem->answer_basis_type_label,
                    'title' => $answerBasisItem->title,
                    'original_filename' => $answerBasisItem->original_filename,
                    'body_text' => $answerBasisItem->body_text,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Purpose: Convert retrieved knowledge chunks into compact prompt context.
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
                    'keywords' => $this->normalizeStringList((array) data_get($retrievalRow, 'keywords', [])),
                    'section_title' => $this->normalizeNullableString(data_get($retrievalRow, 'section_title')),
                    'section_path' => $this->normalizeNullableString(data_get($retrievalRow, 'section_path')),
                    'content_preview' => Str::limit(Str::squish($content), 1200, '...'),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Purpose: Convert the grounding judge result into compact prompt context.
     * Inputs: The grounding judge payload or null.
     * Returns: A deterministic array for the prompt.
     * Side effects: None.
     */
    private function promptGroundingJudge(?array $groundingJudge): ?array
    {
        if (! is_array($groundingJudge)) {
            return null;
        }

        $directlySupportedPoints = $this->normalizeSupportedPointObjects(
            data_get($groundingJudge, 'directly_supported_points', data_get($groundingJudge, 'supported_points', [])),
        );
        $relatedButInsufficientPoints = $this->normalizeStringList((array) data_get($groundingJudge, 'related_but_insufficient_points', []));
        $unsupportedPoints = $this->normalizeStringList((array) data_get($groundingJudge, 'unsupported_points', []));

        return [
            'status' => in_array(data_get($groundingJudge, 'status'), ['supported', 'partial', 'unsupported'], true)
                ? data_get($groundingJudge, 'status')
                : null,
            'can_generate_answer' => (bool) data_get($groundingJudge, 'can_generate_answer', false),
            'directly_supported_points' => $directlySupportedPoints,
            'related_but_insufficient_points' => $relatedButInsufficientPoints,
            'unsupported_points' => $unsupportedPoints,
            'missing_knowledge_summary' => $this->normalizeNullableString(data_get($groundingJudge, 'missing_knowledge_summary')),
            'recommended_document_title' => $this->normalizeNullableString(data_get($groundingJudge, 'recommended_document_title')),
            'suggested_filename' => $this->normalizeNullableString(data_get($groundingJudge, 'suggested_filename')),
            'reasoning_summary' => $this->normalizeNullableString(data_get($groundingJudge, 'reasoning_summary')),
            'supported_points' => $this->supportedPointRequirementPoints($directlySupportedPoints),
        ];
    }

    /**
     * Purpose: Normalize the directly supported points into stable objects for the answer prompt.
     * Inputs: Raw supported point payload.
     * Returns: Deterministic supported point objects.
     * Side effects: None.
     */
    private function normalizeSupportedPointObjects(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $normalized = [];

        foreach ($values as $value) {
            if (is_string($value)) {
                $normalizedValue = trim((string) $value);

                if ($normalizedValue === '') {
                    continue;
                }

                $normalized[] = [
                    'requirement_point' => $normalizedValue,
                    'support_summary' => $normalizedValue,
                    'evidence_reference' => null,
                    'evidence_quote' => null,
                ];

                continue;
            }

            if (! is_array($value)) {
                continue;
            }

            $requirementPoint = $this->normalizeNullableString(data_get($value, 'requirement_point'));
            $supportSummary = $this->normalizeNullableString(data_get($value, 'support_summary'));
            $evidenceReference = $this->normalizeNullableString(data_get($value, 'evidence_reference'));
            $evidenceQuote = $this->normalizeNullableString(data_get($value, 'evidence_quote'));

            if ($requirementPoint === null || $supportSummary === null) {
                continue;
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
     * Purpose: Normalize retrieved knowledge rows before prompt composition.
     * Inputs: The retrieved knowledge collection or null.
     * Returns: A deterministic collection with array rows only.
     * Side effects: None.
     */
    private function retrievedKnowledgeRows(?Collection $retrievedKnowledgeChunks): Collection
    {
        if (! $retrievedKnowledgeChunks instanceof Collection) {
            return collect();
        }

        return $retrievedKnowledgeChunks
            ->filter(static fn ($row): bool => is_array($row))
            ->values();
    }

    /**
     * Purpose: Define the strict JSON schema for the answer draft response.
     * Inputs: None.
     * Returns: The JSON schema array used for structured output.
     * Side effects: None.
     */
    private function answerDraftSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'answer_draft_text' => [
                    'type' => 'string',
                ],
            ],
            'required' => [
                'answer_draft_text',
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
    private function decodeAnswerDraftPayload(array $response): array
    {
        $text = $this->responseTextFromOpenAi($response);
        $text = $this->stripCodeFences($text);

        if ($text === '') {
            throw new RuntimeException('OpenAI answer draft response did not include any text output.');
        }

        try {
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('OpenAI answer draft response was not valid JSON.', 0, $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('OpenAI answer draft response did not decode to a JSON object.');
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
                    throw new RuntimeException('OpenAI refused to return an answer draft.');
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
     * Purpose: Validate the structured OpenAI payload before persisting it.
     * Inputs: The decoded OpenAI output.
     * Returns: The validated answer draft text.
     * Side effects: Throws when the payload violates the contract.
     */
    private function validateAnswerDraftPayload(array $payload): string
    {
        try {
            $validated = Validator::make($payload, [
                'answer_draft_text' => ['required', 'string', 'min:1', 'max:20000'],
            ])->validate();
        } catch (Throwable $exception) {
            throw new RuntimeException('OpenAI answer draft response did not match the required contract.', 0, $exception);
        }

        $answerDraftText = $this->normalizeDraftText((string) $validated['answer_draft_text']);

        if ($answerDraftText === '') {
            throw new RuntimeException('OpenAI answer draft text was empty after trimming.');
        }

        return $answerDraftText;
    }

    /**
     * Purpose: Normalize persisted answer draft text without changing its content meaning.
     * Inputs: Raw draft text.
     * Returns: Trimmed text with normalized line endings.
     * Side effects: None.
     */
    private function normalizeDraftText(string $value): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $value);

        return trim($normalized);
    }

    /**
     * Purpose: Normalize optional case-level instructions before they are added to the prompt.
     * Inputs: Raw instructions text.
     * Returns: A trimmed string or null when no instructions are set.
     * Side effects: None.
     */
    private function normalizeCaseInstructions(?string $value): ?string
    {
        $normalized = trim(str_replace(["\r\n", "\r"], "\n", (string) $value));

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * Purpose: Normalize an optional string value for prompt context.
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
     * Purpose: Normalize a list of strings for prompt context.
     * Inputs: A mixed array payload.
     * Returns: A trimmed unique list of non-empty strings.
     * Side effects: None.
     */
    private function normalizeStringList(array $values): array
    {
        $normalized = [];
        $seen = [];

        foreach ($values as $value) {
            $item = trim((string) $value);

            if ($item === '') {
                continue;
            }

            $key = mb_strtolower($item, 'UTF-8');

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = $item;
        }

        return $normalized;
    }

    /**
     * Purpose: Convert supported point objects to a compatibility string list.
     * Inputs: The supported point objects from the judge.
     * Returns: A trimmed unique list of requirement point strings.
     * Side effects: None.
     */
    private function supportedPointRequirementPoints(array $supportedPoints): array
    {
        $normalized = [];
        $seen = [];

        foreach ($supportedPoints as $supportedPoint) {
            $requirementPoint = is_string($supportedPoint)
                ? trim($supportedPoint)
                : trim((string) data_get($supportedPoint, 'requirement_point', data_get($supportedPoint, 'support_summary', '')));

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
     * Purpose: Return the configured OpenAI model for answer draft requests.
     * Inputs: None.
     * Returns: The configured model name.
     * Side effects: None.
     */
    private function openAiModel(): string
    {
        $model = trim((string) config(
            'services.openai.requirement_answer_model',
            config('services.openai.requirement_extraction_model', config('services.openai.model', 'gpt-4.1-mini')),
        ));

        if ($model === '') {
            throw new RuntimeException('OpenAI answer draft model is not configured.');
        }

        return $model;
    }
}
