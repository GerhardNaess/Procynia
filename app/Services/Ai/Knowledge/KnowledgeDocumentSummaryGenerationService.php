<?php

namespace App\Services\Ai\Knowledge;

use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemChunk;
use App\Services\Ai\AiTokenLogger;
use App\Services\Ai\AiUsageGuard;
use App\Services\OpenAi\OpenAiClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Throwable;

class KnowledgeDocumentSummaryGenerationService
{
    private const MAX_OUTPUT_TOKENS = 700;

    private const TEMPERATURE = 0;

    public function __construct(
        private readonly OpenAiClient $openAiClient,
        private readonly AiTokenLogger $tokenLogger = new AiTokenLogger(),
    ) {
    }

    /**
     * Purpose: Generate a concise AI summary for one knowledge document.
     * Inputs: The persisted knowledge document to summarize.
     * Returns: A document-level summary string or null when no safe summary could be produced.
     * Side effects: May call OpenAI and emits observability logs.
     */
    public function generateForDocument(KnowledgeItem $document, ?int $userId = null): ?string
    {
        $document->loadMissing([
            'customer.language',
            'currentVersion',
            'chunks' => static fn ($query) => $query->orderBy('chunk_index'),
        ]);

        $languageCode = $this->resolveCustomerLanguageCode($document);
        $contextRows = $this->documentContextRows($document);
        $fallbackText = '';

        if ($contextRows === []) {
            $fallbackText = $this->cleanRawTextForSummary($document->textForKnowledgeProcessing() ?? '');

            if ($fallbackText === '') {
                Log::info('[PROCYNIA][KNOWLEDGE_SUMMARY] Document summary skipped because no usable document context was available.', [
                    'knowledge_item_id' => $document->id,
                    'customer_id' => (int) $document->customer_id,
                ]);

                return null;
            }
        }

        $payload = $this->openAiRequestPayload($document, $languageCode, $contextRows, $fallbackText);
        $model = (string) data_get($payload, 'model', '');
        $chunkCount = count($contextRows);

        Log::info('[PROCYNIA][KNOWLEDGE_SUMMARY] Document summary generation starting.', [
            'customer_id' => (int) $document->customer_id,
            'knowledge_item_id' => $document->id,
            'chunk_count' => $chunkCount,
            'language_code' => $languageCode,
            'model' => $model,
        ]);

        try {
            $response = $this->openAiClient->createResponse($payload);
            $decoded = $this->decodePayload($response);
            $summary = $this->normalizeSummary(data_get($decoded, 'summary'));

            if ($summary === '') {
                throw new RuntimeException('OpenAI document summary response did not include a usable summary.');
            }

            Log::info('[PROCYNIA][KNOWLEDGE_SUMMARY] Document summary generation completed.', [
                'customer_id' => (int) $document->customer_id,
                'knowledge_item_id' => $document->id,
                'chunk_count' => $chunkCount,
                'language_code' => $languageCode,
                'model' => $model,
                'summary_length' => mb_strlen($summary, 'UTF-8'),
            ]);

            $this->tokenLogger->record([
                'customer_id'      => (int) $document->customer_id,
                'user_id'          => $userId,
                'operation_key'    => AiUsageGuard::OPERATION_KNOWLEDGE_DOCUMENT_UPLOAD,
                'model'            => $model,
                'provider'         => data_get($response, '_meta.provider'),
                'deployment_name'  => data_get($response, '_meta.deployment_name'),
                'provider_region'  => data_get($response, '_meta.provider_region'),
                'input_tokens'     => data_get($response, 'usage.input_tokens', 0),
                'output_tokens'    => data_get($response, 'usage.output_tokens', 0),
                'total_tokens'     => data_get($response, 'usage.total_tokens', 0),
                'knowledge_item_id' => $document->id,
                'request_id'       => data_get($response, '_meta.request_id'),
            ]);

            return $summary;
        } catch (Throwable $exception) {
            Log::warning('[PROCYNIA][KNOWLEDGE_SUMMARY] Document summary generation failed.', [
                'customer_id' => (int) $document->customer_id,
                'knowledge_item_id' => $document->id,
                'chunk_count' => $chunkCount,
                'language_code' => $languageCode,
                'model' => $model,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Purpose: Build the OpenAI Responses API payload for one document summary request.
     * Inputs: The document, language code, structured chunk context and optional fallback raw text.
     * Returns: The exact request payload sent to OpenAI.
     * Side effects: None.
     *
     * @param array<int, array<string, mixed>> $contextRows
     */
    private function openAiRequestPayload(
        KnowledgeItem $document,
        string $languageCode,
        array $contextRows,
        string $fallbackText,
    ): array {
        $targetLanguage = $this->languageLabel($languageCode);
        $payload = [
            'document' => [
                'id' => $document->id,
                'title' => $document->title,
                'original_filename' => $document->original_filename,
                'document_type' => $document->document_type,
                'chunk_count' => count($contextRows),
                'language_code' => $languageCode,
            ],
            'document_context' => $contextRows !== []
                ? [
                    'source_type' => 'chunks',
                    'chunks' => $contextRows,
                ]
                : [
                    'source_type' => 'raw_text',
                    'raw_text' => $fallbackText,
                ],
            'instructions' => [
                'Write a document-level summary in '.$targetLanguage.'.',
                'Use the full ordered document context, not the first chunk only.',
                'Ignore table of contents, page numbers, dotted leader lines, and repeated navigation text.',
                'Focus on the document theme, purpose, scope, main sections, and notable responsibilities or processes.',
                'Return a concise paragraph suitable for a document card.',
            ],
        ];

        return [
            'model' => $this->openAiModel(),
            'input' => [
                [
                    'role' => 'developer',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $this->systemPrompt($targetLanguage),
                        ],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $this->userPrompt($payload),
                        ],
                    ],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'knowledge_document_summary',
                    'description' => 'Document-level summary for one knowledge document.',
                    'strict' => true,
                    'schema' => $this->summarySchema(),
                ],
            ],
            'max_output_tokens' => self::MAX_OUTPUT_TOKENS,
        ];

        if ($this->openAiModelSupportsTemperature($payload['model'])) {
            $payload['temperature'] = self::TEMPERATURE;
        }

        return $payload;
    }

    /**
     * Purpose: Build the developer instructions for document summaries.
     * Inputs: The requested output language label.
     * Returns: A short instruction string for the model.
     * Side effects: None.
     */
    private function systemPrompt(string $targetLanguage): string
    {
        return implode("\n", [
            'You summarize one uploaded knowledge document for an internal knowledge base.',
            'You must use the full document context, not only one chunk or the first visible section.',
            'You must ignore table of contents lines, dotted leader entries, page numbers, and repeated navigation text.',
            'You must not mention chunks, embeddings, or internal extraction details.',
            'Write the summary in '.$targetLanguage.'.',
            'Return only JSON that matches the schema.',
        ]);
    }

    /**
     * Purpose: Build the user-facing payload for document summary generation.
     * Inputs: The normalized prompt payload.
     * Returns: A JSON string the model can inspect deterministically.
     * Side effects: None.
     *
     * @param array<string, mixed> $payload
     */
    private function userPrompt(array $payload): string
    {
        try {
            return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode the knowledge document summary prompt payload.', 0, $exception);
        }
    }

    /**
     * Purpose: Define the strict JSON schema for the summary response.
     * Inputs: None.
     * Returns: The JSON schema array used for structured output.
     * Side effects: None.
     */
    private function summarySchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'summary' => [
                    'type' => 'string',
                ],
            ],
            'required' => [
                'summary',
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * Purpose: Convert the loaded document chunks into a compact ordered context payload.
     * Inputs: The persisted knowledge document.
     * Returns: Ordered chunk context rows suitable for prompt serialization.
     * Side effects: None.
     *
     * @return array<int, array<string, mixed>>
     */
    private function documentContextRows(KnowledgeItem $document): array
    {
        if (! $document->relationLoaded('chunks')) {
            return [];
        }

        $rows = [];

        foreach ($document->chunks as $chunk) {
            if (! $chunk instanceof KnowledgeItemChunk) {
                continue;
            }

            $content = trim((string) $chunk->content);

            if ($content === '') {
                continue;
            }

            $rows[] = [
                'chunk_index' => (int) $chunk->chunk_index,
                'chunk_type' => (string) $chunk->chunk_type,
                'heading_path' => $this->nullableString($chunk->heading_path),
                'section_path' => $this->nullableString($chunk->section_path),
                'title' => $this->nullableString($chunk->title),
                'content' => Str::limit(Str::squish($content), 1200, ''),
            ];
        }

        return array_slice($rows, 0, 60);
    }

    /**
     * Purpose: Remove noisy raw text from a fallback summary source.
     * Inputs: Raw extracted text or stored content.
     * Returns: A compact text block with dotted leader TOC lines removed.
     * Side effects: None.
     */
    private function cleanRawTextForSummary(string $text): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));

        if ($text === '') {
            return '';
        }

        $lines = preg_split('/\n/u', $text) ?: [];
        $cleanLines = [];

        foreach ($lines as $line) {
            $normalizedLine = trim(preg_replace('/\s+/u', ' ', (string) $line) ?? '');

            if ($normalizedLine === '' || $this->isTocSummaryLine($normalizedLine)) {
                continue;
            }

            $cleanLines[] = $normalizedLine;
        }

        return trim(implode("\n", $cleanLines));
    }

    /**
     * Purpose: Detect one summary line that looks like TOC or navigation noise.
     * Inputs: One normalized text line.
     * Returns: True when the line should be ignored for fallback summary generation.
     * Side effects: None.
     */
    private function isTocSummaryLine(string $line): bool
    {
        $line = trim($line);

        if ($line === '') {
            return true;
        }

        if (preg_match('/\.{2,}\s*\d+\s*$/u', $line) === 1) {
            return true;
        }

        if (preg_match('/^\s*(bilag|vedlegg)\b/iu', $line) === 1 && mb_strlen($line, 'UTF-8') < 100) {
            return true;
        }

        return preg_match('/^\d+(?:\.\d+)*\s+[^\d].*\.{2,}\s*\d+\s*$/u', $line) === 1;
    }

    /**
     * Purpose: Decode the model output from the OpenAI Responses API.
     * Inputs: The raw OpenAI response payload.
     * Returns: The JSON-decoded assistant output.
     * Side effects: Throws when no usable JSON payload is present.
     */
    private function decodePayload(array $response): array
    {
        $text = $this->responseTextFromOpenAi($response);
        $text = $this->stripCodeFences($text);

        if ($text === '') {
            throw new RuntimeException('OpenAI knowledge document summary response did not include any text output.');
        }

        try {
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('OpenAI knowledge document summary response was not valid JSON.', 0, $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('OpenAI knowledge document summary response did not decode to a JSON object.');
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
                    throw new RuntimeException('OpenAI refused to return a knowledge document summary.');
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
     * Purpose: Normalize the model output summary into a stable display string.
     * Inputs: Raw summary text from the AI model.
     * Returns: A compact summary string or an empty string when the summary is unusable.
     * Side effects: None.
     */
    private function normalizeSummary(mixed $summary): string
    {
        if (! is_string($summary)) {
            return '';
        }

        return Str::limit(Str::squish(trim($summary)), 1200, '');
    }

    /**
     * Purpose: Resolve a safe nullable string value.
     * Inputs: A candidate value.
     * Returns: A trimmed string or null when the value is empty.
     * Side effects: None.
     */
    private function nullableString(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text !== '' ? $text : null;
    }

    /**
     * Purpose: Return the configured OpenAI model for summary generation requests.
     * Inputs: None.
     * Returns: The configured model name.
     * Side effects: None.
     */
    private function openAiModel(): string
    {
        $model = trim((string) config('services.openai.model', 'gpt-4.1-mini'));

        if ($model === '') {
            throw new RuntimeException('OpenAI summary model is not configured.');
        }

        return $model;
    }

    private function openAiModelSupportsTemperature(string $model): bool
    {
        return ! Str::startsWith(Str::lower(trim($model)), 'gpt-5');
    }

    /**
     * Purpose: Resolve the customer's language code for summary generation.
     * Inputs: The knowledge document and its customer relation.
     * Returns: A supported language code or Norwegian as the safe default.
     * Side effects: None.
     */
    private function resolveCustomerLanguageCode(KnowledgeItem $document): string
    {
        $code = trim((string) ($document->customer?->language?->code ?? ''));

        return match ($code) {
            'en', 'sv', 'da' => $code,
            default => 'no',
        };
    }

    /**
     * Purpose: Convert a language code into a friendly language label for prompts.
     * Inputs: A normalized language code.
     * Returns: The language label the model should follow.
     * Side effects: None.
     */
    private function languageLabel(string $languageCode): string
    {
        return match ($languageCode) {
            'en' => 'English',
            'sv' => 'Swedish',
            'da' => 'Danish',
            default => 'Norwegian',
        };
    }
}
