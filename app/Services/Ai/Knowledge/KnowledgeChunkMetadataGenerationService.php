<?php

namespace App\Services\Ai\Knowledge;

use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemChunk;
use App\Models\KnowledgeMetadataTermSuggestion;
use App\Services\OpenAi\OpenAiClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Throwable;

class KnowledgeChunkMetadataGenerationService
{
    private const MAX_OUTPUT_TOKENS = 1800;

    private const TEMPERATURE = 0;

    public function __construct(
        private readonly OpenAiClient $openAiClient,
        private readonly KnowledgeMetadataVocabularyService $vocabularyService,
        private readonly KnowledgeChunkMetadataValidator $validator,
    ) {
    }

    /**
     * Purpose: Generate, validate and prepare metadata for one knowledge chunk.
     * Inputs: The parent knowledge document and the chunk to analyze.
     * Returns: A normalized metadata payload and a ready-to-embed text input.
     * Side effects: May call OpenAI, may persist suggestion rows, and emits observability logs.
     */
    public function generateForChunk(KnowledgeItem $document, KnowledgeItemChunk $chunk): array
    {
        $vocabularyMap = $this->vocabularyService->buildForCustomer((int) $document->customer_id);
        $startedAt = microtime(true);
        $payload = $this->openAiRequestPayload($document, $chunk, $vocabularyMap);
        $model = (string) ($payload['model'] ?? '');

        Log::info('[PROCYNIA][KNOWLEDGE_METADATA] Metadata generation starting.', [
            'customer_id' => (int) $document->customer_id,
            'knowledge_item_id' => $document->id,
            'knowledge_item_chunk_id' => $chunk->id,
            'chunk_index' => $chunk->chunk_index,
            'available_field_count' => count((array) data_get($vocabularyMap, 'available_fields', [])),
            'term_count' => array_sum((array) data_get($vocabularyMap, 'field_counts', [])),
            'chunk_content_length' => mb_strlen(trim((string) $chunk->content), 'UTF-8'),
            'model' => $model,
        ]);

        try {
            $response = $this->openAiClient->createResponse($payload);
            $decoded = $this->decodePayload($response);
            $validated = $this->validator->validate($document, $chunk, $decoded, $vocabularyMap);
        } catch (Throwable $exception) {
            $validated = $this->failedValidationResult($chunk);

            Log::warning('[PROCYNIA][KNOWLEDGE_METADATA] Metadata generation failed; using fallback metadata.', [
                'customer_id' => (int) $document->customer_id,
                'knowledge_item_id' => $document->id,
                'knowledge_item_chunk_id' => $chunk->id,
                'chunk_index' => $chunk->chunk_index,
                'error' => $exception->getMessage(),
            ]);

            return $this->finalizeResult($document, $chunk, $validated, $vocabularyMap, $model, $startedAt);
        }

        try {
            $this->persistSuggestions($document, $chunk, (array) data_get($validated, 'new_term_suggestions', []));
        } catch (Throwable $exception) {
            Log::warning('[PROCYNIA][KNOWLEDGE_METADATA] Metadata suggestion persistence failed; continuing without suggestions.', [
                'customer_id' => (int) $document->customer_id,
                'knowledge_item_id' => $document->id,
                'knowledge_item_chunk_id' => $chunk->id,
                'chunk_index' => $chunk->chunk_index,
                'error' => $exception->getMessage(),
            ]);
        }

        Log::info('[PROCYNIA][KNOWLEDGE_METADATA] Metadata generation completed.', [
            'customer_id' => (int) $document->customer_id,
            'knowledge_item_id' => $document->id,
            'knowledge_item_chunk_id' => $chunk->id,
            'chunk_index' => $chunk->chunk_index,
            'metadata_status' => data_get($validated, 'metadata_status'),
            'confidence_score' => data_get($validated, 'confidence_score'),
            'suggestion_count' => count((array) data_get($validated, 'new_term_suggestions', [])),
            'elapsed_ms' => $this->elapsedMs($startedAt),
        ]);

        return $this->finalizeResult($document, $chunk, $validated, $vocabularyMap, $model, $startedAt);
    }

    /**
     * Purpose: Build the embedding input for one chunk from metadata, summary and original content.
     * Inputs: The knowledge document, the chunk, and validated metadata.
     * Returns: A deterministic embedding text payload.
     * Side effects: None.
     */
    public function buildEmbeddingInput(KnowledgeItem $document, KnowledgeItemChunk $chunk, array $metadata): string
    {
        $lines = [];
        $title = trim((string) ($chunk->title ?: $chunk->section_title ?: $document->title ?: $document->original_filename));

        if ($title !== '') {
            $lines[] = 'Title: '.$title;
        }

        $sectionPath = trim((string) ($chunk->section_path ?? ''));

        if ($sectionPath !== '') {
            $lines[] = 'Section path: '.$sectionPath;
        }

        $serviceProductTag = trim((string) data_get($metadata, 'service_product_tag', ''));
        $themeTag = trim((string) data_get($metadata, 'theme_tag', ''));
        $topic = trim((string) data_get($metadata, 'topic', ''));
        $subTopic = trim((string) data_get($metadata, 'sub_topic', ''));
        $keywords = array_values(array_filter(array_map(
            static fn (mixed $keyword): string => trim((string) $keyword),
            (array) data_get($metadata, 'keywords', []),
        ), static fn (string $keyword): bool => $keyword !== ''));
        $matchedTerms = array_values(array_filter(array_map(
            static fn (mixed $term): string => trim((string) $term),
            (array) data_get($metadata, 'matched_terms', []),
        ), static fn (string $term): bool => $term !== ''));
        $summary = trim((string) data_get($metadata, 'summary_for_retrieval', ''));
        $content = trim((string) $chunk->content);

        if ($serviceProductTag !== '') {
            $lines[] = 'Service/product tag: '.$serviceProductTag;
        }

        if ($themeTag !== '') {
            $lines[] = 'Theme tag: '.$themeTag;
        }

        if ($topic !== '') {
            $lines[] = 'Topic: '.$topic;
        }

        if ($subTopic !== '') {
            $lines[] = 'Sub-topic: '.$subTopic;
        }

        if ($keywords !== []) {
            $lines[] = 'Keywords: '.implode(', ', $keywords);
        }

        if ($matchedTerms !== []) {
            $lines[] = 'Matched terms: '.implode(', ', $matchedTerms);
        }

        if ($summary !== '') {
            $lines[] = 'Summary: '.$summary;
        }

        $lines[] = 'Content: '.$content;

        return implode("\n", $lines);
    }

    /**
     * Purpose: Build the OpenAI Responses API payload for one chunk metadata analysis.
     * Inputs: The document, the chunk, and the approved vocabulary map.
     * Returns: The exact request payload sent to OpenAI.
     * Side effects: None.
     */
    private function openAiRequestPayload(KnowledgeItem $document, KnowledgeItemChunk $chunk, array $vocabularyMap): array
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
                            'text' => $this->userPrompt($document, $chunk, $vocabularyMap),
                        ],
                    ],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'knowledge_chunk_metadata',
                    'description' => 'Structured metadata generation result for one knowledge chunk.',
                    'strict' => true,
                    'schema' => $this->metadataSchema(),
                ],
            ],
            'temperature' => self::TEMPERATURE,
            'max_output_tokens' => self::MAX_OUTPUT_TOKENS,
        ];
    }

    /**
     * Purpose: Build the developer instructions for metadata generation.
     * Inputs: None.
     * Returns: A short instruction string for the model.
     * Side effects: None.
     */
    private function systemPrompt(): string
    {
        return implode("\n", [
            'You generate metadata for a single knowledge chunk.',
            'You are not writing SQL.',
            'You must use only the approved vocabulary values when they fit the chunk.',
            'You must not invent authoritative values.',
            'If a useful concept is not covered by the approved vocabulary, place it in new_term_suggestions.',
            'Do not over-tag.',
            'Keywords must be concrete and relevant to the chunk content.',
            'Matched terms must be terms that truly appear in the chunk or clearly match an approved synonym.',
            'summary_for_retrieval must be short, concrete and useful for later retrieval.',
            'Return only JSON that matches the schema.',
            'Write all string values in Norwegian.',
        ]);
    }

    /**
     * Purpose: Build the user-facing payload for metadata generation.
     * Inputs: The document, the chunk, and the approved vocabulary map.
     * Returns: A JSON string that the model can inspect deterministically.
     * Side effects: None.
     */
    private function userPrompt(KnowledgeItem $document, KnowledgeItemChunk $chunk, array $vocabularyMap): string
    {
        $payload = [
            'document' => [
                'id' => $document->id,
                'title' => $document->title,
                'original_filename' => $document->original_filename,
                'content_type' => $document->content_type,
                'document_type' => $document->document_type,
                'summary' => $document->summary,
            ],
            'chunk' => [
                'id' => $chunk->id,
                'chunk_index' => $chunk->chunk_index,
                'title' => $chunk->title,
                'section_title' => $chunk->section_title,
                'section_path' => $chunk->section_path,
                'content' => $chunk->content,
            ],
            'allowed_metadata_fields' => data_get($vocabularyMap, 'available_fields', []),
            'approved_vocabulary' => data_get($vocabularyMap, 'terms', []),
            'instructions' => [
                'Use existing approved values when they fit the chunk.',
                'Return canonical names for approved values.',
                'Put genuinely new or unknown concepts in new_term_suggestions.',
                'summary_for_retrieval must be short and concrete.',
                'confidence_score must be a number between 0 and 1.',
            ],
        ];

        try {
            return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode the knowledge chunk metadata prompt payload.', 0, $exception);
        }
    }

    /**
     * Purpose: Define the strict JSON schema for the metadata generation response.
     * Inputs: None.
     * Returns: The JSON schema array used for structured output.
     * Side effects: None.
     */
    private function metadataSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'service_product_tag' => [
                    'type' => ['string', 'null'],
                ],
                'theme_tag' => [
                    'type' => ['string', 'null'],
                ],
                'topic' => [
                    'type' => ['string', 'null'],
                ],
                'sub_topic' => [
                    'type' => ['string', 'null'],
                ],
                'keywords' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                    ],
                ],
                'matched_terms' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                    ],
                ],
                'summary_for_retrieval' => [
                    'type' => 'string',
                ],
                'new_term_suggestions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'suggested_term' => [
                                'type' => 'string',
                            ],
                            'suggested_type' => [
                                'type' => 'string',
                            ],
                            'suggested_canonical_parent' => [
                                'type' => ['string', 'null'],
                            ],
                            'reason' => [
                                'type' => ['string', 'null'],
                            ],
                        ],
                        'required' => [
                            'suggested_term',
                            'suggested_type',
                            'reason',
                        ],
                        'additionalProperties' => false,
                    ],
                ],
                'confidence_score' => [
                    'type' => 'number',
                ],
            ],
            'required' => [
                'service_product_tag',
                'theme_tag',
                'topic',
                'sub_topic',
                'keywords',
                'matched_terms',
                'summary_for_retrieval',
                'new_term_suggestions',
                'confidence_score',
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
    private function decodePayload(array $response): array
    {
        $text = $this->responseTextFromOpenAi($response);
        $text = $this->stripCodeFences($text);

        if ($text === '') {
            throw new RuntimeException('OpenAI knowledge metadata response did not include any text output.');
        }

        try {
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('OpenAI knowledge metadata response was not valid JSON.', 0, $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('OpenAI knowledge metadata response did not decode to a JSON object.');
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
                    throw new RuntimeException('OpenAI refused to return knowledge metadata.');
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
     * Purpose: Resolve the configured OpenAI model for metadata generation requests.
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
            throw new RuntimeException('OpenAI knowledge metadata model is not configured.');
        }

        return $model;
    }

    /**
     * Purpose: Create a fallback metadata result for request failures.
     * Inputs: The chunk that failed metadata generation.
     * Returns: A minimal pending-review metadata payload.
     * Side effects: None.
     */
    private function failedValidationResult(KnowledgeItemChunk $chunk): array
    {
        return [
            'service_product_tag' => null,
            'theme_tag' => null,
            'topic' => null,
            'sub_topic' => null,
            'keywords' => [],
            'matched_terms' => [],
            'summary_for_retrieval' => Str::limit(Str::squish((string) $chunk->content), 280, '...'),
            'confidence_score' => 0.0,
            'metadata_status' => KnowledgeItemChunk::METADATA_STATUS_FAILED,
            'new_term_suggestions' => [],
            'approved_central_fields' => [],
            'pending_review' => true,
        ];
    }

    /**
     * Purpose: Persist AI-suggested metadata terms for later review.
     * Inputs: The document, the chunk, and the suggestion payloads.
     * Returns: None.
     * Side effects: Creates suggestion rows in the database.
     */
    private function persistSuggestions(KnowledgeItem $document, KnowledgeItemChunk $chunk, array $suggestions): void
    {
        foreach ($suggestions as $suggestion) {
            if (! is_array($suggestion)) {
                continue;
            }

            $term = trim((string) data_get($suggestion, 'suggested_term', ''));

            if ($term === '') {
                continue;
            }

            KnowledgeMetadataTermSuggestion::query()->create([
                'customer_id' => (int) $document->customer_id,
                'source_chunk_id' => $chunk->id,
                'suggested_term' => $term,
                'suggested_type' => trim((string) data_get($suggestion, 'suggested_type', 'keyword')) ?: 'keyword',
                'suggested_canonical_parent' => $this->normalizeNullableString(data_get($suggestion, 'suggested_canonical_parent')),
                'reason' => $this->normalizeNullableString(data_get($suggestion, 'reason')),
                'status' => KnowledgeMetadataTermSuggestion::STATUS_PENDING,
            ]);
        }
    }

    /**
     * Purpose: Finalize the result payload with an embedding input.
     * Inputs: The document, the chunk, a validated metadata payload, the vocabulary map, the model, and timing metadata.
     * Returns: The normalized result payload.
     * Side effects: None.
     */
    private function finalizeResult(
        KnowledgeItem $document,
        KnowledgeItemChunk $chunk,
        array $validated,
        array $vocabularyMap,
        string $model,
        float $startedAt,
    ): array {
        $embeddingInput = $this->buildEmbeddingInput($document, $chunk, $validated);
        $suggestions = (array) data_get($validated, 'new_term_suggestions', []);

        return array_merge($validated, [
            'embedding_input' => $embeddingInput,
            'model' => $model,
            'vocabulary_map' => $vocabularyMap,
            'suggestion_count' => count($suggestions),
            'elapsed_ms' => $this->elapsedMs($startedAt),
        ]);
    }

    /**
     * Purpose: Convert elapsed time to milliseconds.
     * Inputs: The start time in seconds.
     * Returns: The elapsed milliseconds as an integer.
     * Side effects: None.
     */
    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    /**
     * Purpose: Normalize an optional string into a trimmed nullable string.
     * Inputs: A raw scalar or null.
     * Returns: A trimmed string or null.
     * Side effects: None.
     */
    private function normalizeNullableString(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized !== '' ? $normalized : null;
    }
}
