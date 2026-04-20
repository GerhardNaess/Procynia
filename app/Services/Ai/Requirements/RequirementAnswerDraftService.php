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
    ): SavedNoticeAiRequirement
    {
        $requirement->loadMissing([
            'evidence.knowledgeItem',
            'evidence.knowledgeItemChunk',
        ]);

        if (! $forceGenerate && filled($requirement->answer_draft_text)) {
            return $requirement;
        }

        $evidenceRows = $this->answerEvidenceRows($requirement);
        $answerBasisRows = $this->answerBasisRows($answerBasisItems);
        $answerDraftText = $this->requestAnswerDraft($requirement, $evidenceRows, $answerBasisRows, $caseInstructions);

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
    ): string
    {
        $response = $this->openAiClient->createResponse(
            $this->openAiRequestPayload($requirement, $evidenceRows, $answerBasisRows, $caseInstructions),
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
                            'text' => $this->userPrompt($requirement, $evidenceRows, $answerBasisRows, $caseInstructions),
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
            'Use only the provided requirement text and evidence.',
            'Use the selected answer basis items as the primary supplier-owned source material when drafting the answer.',
            'Apply the case-specific AI instructions from the user payload for tone, terminology, style, and capitalization.',
            'If the case-specific instructions conflict with grounded facts or the JSON schema, keep the facts and schema.',
            'Return only JSON that matches the schema.',
            'Write all string values in Norwegian.',
            'Do not write an assessment, critique, or coverage commentary.',
            'Do not invent facts that are not supported by the evidence.',
            'Do not use unselected answer basis items.',
            'If evidence is missing, draft cautiously from the requirement text only.',
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
