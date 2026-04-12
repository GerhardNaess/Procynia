<?php

namespace App\Services\Ai\Requirements;

use App\Data\Ai\Requirements\DocumentRequirementSegmentData;
use App\Data\Ai\Requirements\RequirementExtractionBlockData;
use App\Data\Ai\Requirements\RequirementExtractionCandidateData;
use App\Data\Ai\Requirements\RequirementExtractionResultData;
use App\Data\Ai\Requirements\RequirementSegmentExtractionResultData;
use App\Models\SavedNoticeAiDocument;
use App\Models\SavedNoticeAiRequirement;
use App\Services\OpenAi\OpenAiClient;
use App\Services\RequirementExtractor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Throwable;

class RequirementCandidateExtractor
{
    private const SEGMENT_REQUIRED_FIELDS = [
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
        'source_excerpt',
        'source_page_start',
        'source_page_end',
        'source_section_title',
        'interpretation_risk',
        'is_requirement',
        'confidence',
        'warnings',
    ];

    private const SEGMENT_REQUIRED_NON_EMPTY_STRING_FIELDS = [
        'requirement_type',
        'obligation_type',
        'original_text',
        'normalized_text',
        'source_excerpt',
        'interpretation_risk',
    ];

    public function __construct(
        private readonly OpenAiClient $openAiClient,
        private readonly RequirementExtractor $legacyRequirementExtractor,
        private readonly RequirementSegmentExtractionPromptBuilder $promptBuilder,
        private readonly RequirementExtractionPromptBuilder $blockPromptBuilder,
    ) {
    }

    /**
     * Purpose: Extract structured requirement candidates for one source-preserving document segment.
     * Inputs: The source AI document, the segment, and an optional run id for tracing.
     * Returns: A controlled segment extraction result with candidates and trace metadata.
     * Side effects: Calls OpenAI once and emits observability logs.
     */
    public function extract(SavedNoticeAiDocument $document, DocumentRequirementSegmentData $segment, ?string $runId = null): RequirementSegmentExtractionResultData
    {
        $runId ??= (string) Str::uuid();
        $startedAt = microtime(true);
        $payload = $this->segmentRequestPayload($document, $segment);
        $model = (string) ($payload['model'] ?? '');

        Log::info('[PROCYNIA][AI_COST][EXTRACTION][PRE_OPENAI] Segment extraction OpenAI call starting.', $this->segmentAiCostContext(
            $document,
            $segment,
            $runId,
            [
                'openai_call_count' => 1,
                'model' => $model,
                'segment_text_length' => mb_strlen($segment->text, 'UTF-8'),
                'input_text_length' => mb_strlen($this->segmentInputText($payload), 'UTF-8'),
                'max_output_tokens' => $payload['max_output_tokens'] ?? null,
            ],
        ));

        try {
            $response = $this->openAiClient->post('responses', $payload, 180);
        } catch (ConnectionException $exception) {
            Log::warning('[PROCYNIA][AI_COST][EXTRACTION][POST_OPENAI] Segment extraction OpenAI call failed before a response was returned.', $this->segmentAiCostContext(
                $document,
                $segment,
                $runId,
                [
                    'openai_call_count' => 1,
                    'model' => $model,
                    'request_id' => null,
                    'response_id' => null,
                    'status' => null,
                    'input_tokens' => null,
                    'output_tokens' => null,
                    'total_tokens' => null,
                    'elapsed_ms' => $this->elapsedMs($startedAt),
                    'error_type' => str_contains(mb_strtolower($exception->getMessage(), 'UTF-8'), 'timed out')
                        ? 'timeout'
                        : 'connection_error',
                    'error_message' => $exception->getMessage(),
                ],
            ));

            Log::warning('[PROCYNIA][REQ_PIPELINE] Segment extraction failed before OpenAI returned a response.', [
                'run_id' => $runId,
                'document_id' => $document->id,
                'saved_notice_ai_document_id' => $document->id,
                'saved_notice_id' => $document->saved_notice_id,
                'segment_id' => $segment->segmentId,
                'segment_index' => $segment->segmentIndex,
                'stage' => 'openai_connection',
                'exception_message' => $exception->getMessage(),
            ]);

            return $this->failedSegmentResult(
                document: $document,
                segment: $segment,
                runId: $runId,
                model: $model,
                elapsedMs: $this->elapsedMs($startedAt),
                errorType: str_contains(mb_strtolower($exception->getMessage(), 'UTF-8'), 'timed out')
                    ? 'timeout'
                    : 'connection_error',
                errorMessage: $exception->getMessage(),
            );
        } catch (RuntimeException $exception) {
            Log::warning('[PROCYNIA][AI_COST][EXTRACTION][POST_OPENAI] Segment extraction OpenAI call failed before a response was returned.', $this->segmentAiCostContext(
                $document,
                $segment,
                $runId,
                [
                    'openai_call_count' => 1,
                    'model' => $model,
                    'request_id' => null,
                    'response_id' => null,
                    'status' => null,
                    'input_tokens' => null,
                    'output_tokens' => null,
                    'total_tokens' => null,
                    'elapsed_ms' => $this->elapsedMs($startedAt),
                    'error_type' => 'connection_error',
                    'error_message' => $exception->getMessage(),
                ],
            ));

            Log::warning('[PROCYNIA][REQ_PIPELINE] Segment extraction failed before OpenAI returned a response.', [
                'run_id' => $runId,
                'document_id' => $document->id,
                'saved_notice_ai_document_id' => $document->id,
                'saved_notice_id' => $document->saved_notice_id,
                'segment_id' => $segment->segmentId,
                'segment_index' => $segment->segmentIndex,
                'stage' => 'openai_runtime',
                'exception_message' => $exception->getMessage(),
            ]);

            return $this->failedSegmentResult(
                document: $document,
                segment: $segment,
                runId: $runId,
                model: $model,
                elapsedMs: $this->elapsedMs($startedAt),
                errorType: 'connection_error',
                errorMessage: $exception->getMessage(),
            );
        } catch (Throwable $exception) {
            Log::warning('[PROCYNIA][AI_COST][EXTRACTION][POST_OPENAI] Segment extraction OpenAI call failed before a response was returned.', $this->segmentAiCostContext(
                $document,
                $segment,
                $runId,
                [
                    'openai_call_count' => 1,
                    'model' => $model,
                    'request_id' => null,
                    'response_id' => null,
                    'status' => null,
                    'input_tokens' => null,
                    'output_tokens' => null,
                    'total_tokens' => null,
                    'elapsed_ms' => $this->elapsedMs($startedAt),
                    'error_type' => 'openai_error',
                    'error_message' => $exception->getMessage(),
                ],
            ));

            Log::warning('[PROCYNIA][REQ_PIPELINE] Segment extraction failed before OpenAI returned a response.', [
                'run_id' => $runId,
                'document_id' => $document->id,
                'saved_notice_ai_document_id' => $document->id,
                'saved_notice_id' => $document->saved_notice_id,
                'segment_id' => $segment->segmentId,
                'segment_index' => $segment->segmentIndex,
                'stage' => 'openai_throwable',
                'exception_message' => $exception->getMessage(),
            ]);

            return $this->failedSegmentResult(
                document: $document,
                segment: $segment,
                runId: $runId,
                model: $model,
                elapsedMs: $this->elapsedMs($startedAt),
                errorType: 'openai_error',
                errorMessage: $exception->getMessage(),
            );
        }

        $requestId = $this->segmentRequestIdFrom($response);
        $responseId = $this->segmentResponseIdFrom($response);
        $status = $response->status();
        $rawResponseBody = trim($response->body());
        $tokenUsage = $this->segmentTokenUsageFromResponse($response);

        Log::info('[PROCYNIA][AI_COST][EXTRACTION][POST_OPENAI] Segment extraction OpenAI call completed.', $this->segmentAiCostContext($document, $segment, $runId, [
            'openai_call_count' => 1,
            'model' => $model,
            'request_id' => $requestId,
            'response_id' => $responseId,
            'status' => $status,
            'input_tokens' => $tokenUsage['input_tokens'],
            'output_tokens' => $tokenUsage['output_tokens'],
            'total_tokens' => $tokenUsage['total_tokens'],
            'elapsed_ms' => $this->elapsedMs($startedAt),
        ]));

        if (! $response->successful()) {
            Log::warning('[PROCYNIA][REQ_PIPELINE] Segment extraction returned a non-success HTTP response and will return a failure result.', [
                'run_id' => $runId,
                'document_id' => $document->id,
                'saved_notice_ai_document_id' => $document->id,
                'saved_notice_id' => $document->saved_notice_id,
                'segment_id' => $segment->segmentId,
                'segment_index' => $segment->segmentIndex,
                'stage' => 'openai_http_status',
                'http_status' => $status,
                'request_id' => $requestId,
                'response_id' => $responseId,
            ]);

            return $this->failedSegmentResult(
                document: $document,
                segment: $segment,
                runId: $runId,
                model: $model,
                requestId: $requestId,
                responseId: $responseId,
                upstreamStatus: $status,
                rawResponseBody: $rawResponseBody,
                tokenUsage: $tokenUsage,
                elapsedMs: $this->elapsedMs($startedAt),
                errorType: $this->segmentErrorTypeForStatus($status),
                errorMessage: sprintf('OpenAI segment extraction request failed with HTTP status [%d].', $status),
            );
        }

        try {
            $rawText = $this->segmentResponseTextFromOpenAi($response->json());
            $parsed = $this->decodeSegmentPayload($rawText);
            $rawCandidates = $parsed['candidates'] ?? [];
            $decodedCandidateCount = count($rawCandidates);

            Log::info('[PROCYNIA][REQ_PIPELINE] Decoded structured segment candidates.', [
                'run_id' => $runId,
                'document_id' => $document->id,
                'saved_notice_ai_document_id' => $document->id,
                'saved_notice_id' => $document->saved_notice_id,
                'segment_id' => $segment->segmentId,
                'segment_index' => $segment->segmentIndex,
                'decoded_candidate_count' => $decodedCandidateCount,
                'zero_result' => $decodedCandidateCount === 0,
                'request_id' => $requestId,
                'response_id' => $responseId,
            ]);

            $candidates = $this->segmentMapCandidates($document, $segment, $runId, $requestId, $responseId, $rawCandidates);
            $mappedCandidateCount = count($candidates);

            Log::info('[PROCYNIA][REQ_PIPELINE] Structured segment candidates mapped into internal candidates.', [
                'run_id' => $runId,
                'document_id' => $document->id,
                'saved_notice_ai_document_id' => $document->id,
                'saved_notice_id' => $document->saved_notice_id,
                'segment_id' => $segment->segmentId,
                'segment_index' => $segment->segmentIndex,
                'decoded_candidate_count' => $decodedCandidateCount,
                'mapped_candidate_count' => $mappedCandidateCount,
                'zero_result' => $mappedCandidateCount === 0,
                'request_id' => $requestId,
                'response_id' => $responseId,
            ]);
        } catch (Throwable $exception) {
            Log::warning('[PROCYNIA][AI][EXTRACTION] Segment extraction response parsing failed.', [
                'run_id' => $runId,
                'saved_notice_id' => $document->saved_notice_id,
                'saved_notice_ai_document_id' => $document->id,
                'saved_notice_ai_document_chunk_id' => $segment->savedNoticeAiDocumentChunkId,
                'segment_id' => $segment->segmentId,
                'segment_index' => $segment->segmentIndex,
                'request_id' => $requestId,
                'response_id' => $responseId,
                'error' => $exception->getMessage(),
                'raw_response' => $rawResponseBody,
            ]);

            Log::warning('[PROCYNIA][REQ_PIPELINE] Segment extraction response parsing failed and returned a failure result.', [
                'run_id' => $runId,
                'document_id' => $document->id,
                'saved_notice_ai_document_id' => $document->id,
                'saved_notice_id' => $document->saved_notice_id,
                'segment_id' => $segment->segmentId,
                'segment_index' => $segment->segmentIndex,
                'stage' => 'response_parsing',
                'exception_message' => $exception->getMessage(),
                'request_id' => $requestId,
                'response_id' => $responseId,
                'raw_response_length' => mb_strlen($rawResponseBody, 'UTF-8'),
            ]);

            return $this->failedSegmentResult(
                document: $document,
                segment: $segment,
                runId: $runId,
                model: $model,
                requestId: $requestId,
                responseId: $responseId,
                upstreamStatus: $status,
                rawResponseBody: $rawResponseBody,
                tokenUsage: $tokenUsage,
                elapsedMs: $this->elapsedMs($startedAt),
                errorType: 'unexpected_response',
                errorMessage: $exception->getMessage(),
            );
        }

        $dedupedCandidates = $this->segmentDedupeCandidates($candidates);
        $dedupedCandidateCount = count($dedupedCandidates);

        Log::info('[PROCYNIA][REQ_PIPELINE] Normalized segment candidates deduped.', [
            'run_id' => $runId,
            'document_id' => $document->id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_id' => $document->saved_notice_id,
            'segment_id' => $segment->segmentId,
            'segment_index' => $segment->segmentIndex,
            'normalized_requirement_count' => $mappedCandidateCount,
            'deduped_requirement_count' => $dedupedCandidateCount,
            'zero_result' => $dedupedCandidateCount === 0,
        ]);

        return new RequirementSegmentExtractionResultData(
            ok: true,
            savedNoticeId: $segment->savedNoticeId,
            savedNoticeAiDocumentId: $segment->savedNoticeAiDocumentId,
            savedNoticeAiDocumentChunkId: $segment->savedNoticeAiDocumentChunkId,
            documentTitle: $segment->documentTitle,
            documentFilename: $segment->documentFilename,
            segmentId: $segment->segmentId,
            segmentIndex: $segment->segmentIndex,
            model: $model,
            requestId: $requestId,
            responseId: $responseId,
            upstreamStatus: $status,
            fallbackUsed: false,
            errorType: null,
            errorMessage: null,
            openAiCallCount: 1,
            elapsedMs: $this->elapsedMs($startedAt),
            inputTokens: $tokenUsage['input_tokens'],
            outputTokens: $tokenUsage['output_tokens'],
            totalTokens: $tokenUsage['total_tokens'],
            parseStrategy: 'json',
            rawOutput: $rawText,
            candidateCount: count($dedupedCandidates),
            candidates: $dedupedCandidates,
            metadata: [
                'prompt_version' => $this->promptBuilder->promptVersion(),
                'candidate_count' => $dedupedCandidateCount,
                'raw_candidate_count' => $decodedCandidateCount,
                'mapped_candidate_count' => $mappedCandidateCount,
                'deduped_candidate_count' => $dedupedCandidateCount,
                'row_count' => $decodedCandidateCount,
                'segment_text_length' => mb_strlen($segment->text, 'UTF-8'),
                'input_text_length' => mb_strlen($this->segmentInputText($payload), 'UTF-8'),
                'parse_strategy' => 'json',
            ],
        );
    }

    /**
     * Purpose: Execute a single full-document requirement extraction call for temporary verification.
     * Inputs: The source AI document and an optional run id for tracing.
     * Returns: A raw full-document extraction result without segment parsing or persistence.
     * Side effects: Calls OpenAI once and emits observability logs.
     */
    public function extractFullDocumentRaw(SavedNoticeAiDocument $document, ?string $runId = null): array
    {
        $runId ??= (string) Str::uuid();
        $result = $this->performFullDocumentExtractionRequest($document, $runId);

        Log::info('[PROCYNIA][REQ_PIPELINE] Phase 1 extraction raw result.', [
            'run_id' => $runId,
            'document_id' => $document->id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_id' => $document->saved_notice_id,
            'document_title' => $document->original_filename,
            'document_filename' => $document->original_filename,
            'prompt_version' => $result['prompt_version'],
            'model' => $result['model'],
            'request_id' => $result['request_id'],
            'response_id' => $result['response_id'],
            'status' => $result['status'],
            'document_text_length' => $result['document_text_length'],
            'prompt_text_length' => $result['prompt_text_length'],
            'input_text_length' => $result['input_text_length'],
            'raw_output_length' => $result['raw_output_length'],
            'raw_output_preview' => $this->previewText((string) ($result['raw_output'] ?? '')),
            'input_tokens' => $result['input_tokens'],
            'output_tokens' => $result['output_tokens'],
            'total_tokens' => $result['total_tokens'],
            'elapsed_ms' => $result['elapsed_ms'],
            'bypassed_chunk_input' => true,
            'bypassed_segment_input' => true,
            'phase_1_requirement_extraction' => true,
        ]);

        return $result;
    }

    /**
     * Purpose: Extract structured requirement candidates from one full-document extraction call.
     * Inputs: The source AI document and an optional run id for tracing.
     * Returns: A document-level extraction result with parsed candidates and provenance metadata.
     * Side effects: Calls OpenAI once and emits observability logs.
     */
    public function extractFullDocument(SavedNoticeAiDocument $document, ?string $runId = null): RequirementExtractionResultData
    {
        $runId ??= (string) Str::uuid();
        $startedAt = microtime(true);
        $requestResult = $this->performFullDocumentExtractionRequest($document, $runId);
        $documentTitle = (string) $document->original_filename;
        $documentFilename = $documentTitle;
        $requestId = data_get($requestResult, 'request_id');
        $responseId = data_get($requestResult, 'response_id');
        $rawOutput = (string) data_get($requestResult, 'raw_output', '');
        $rawOutputLength = (int) data_get($requestResult, 'raw_output_length', mb_strlen($rawOutput, 'UTF-8'));
        $rawOutputPreview = (string) data_get($requestResult, 'raw_output_preview', $this->previewText($rawOutput));
        $parseStrategy = data_get($requestResult, 'parse_strategy');

        if (! $requestResult['ok']) {
            $failureStage = (string) data_get($requestResult, 'failure_stage', 'openai_request');
            $failureType = (string) data_get($requestResult, 'failure_type', data_get($requestResult, 'error_type', 'unknown_error'));
            $failureMessage = (string) data_get($requestResult, 'error_message', 'Phase 1 extraction failed.');
            $failureResult = $this->buildFullDocumentFailureResult(
                $document,
                $runId,
                array_merge($requestResult, [
                    'parse_strategy' => 'failed',
                ]),
                $failureStage,
                $failureType,
                $failureMessage,
                [
                    'raw_candidate_count' => 0,
                    'mapped_candidate_count' => 0,
                    'deduped_candidate_count' => 0,
                ],
            );

            Log::warning('[PROCYNIA][REQ_PIPELINE] Phase 1 extraction failed.', [
                'run_id' => $runId,
                'document_id' => $document->id,
                'saved_notice_ai_document_id' => $document->id,
                'saved_notice_id' => $document->saved_notice_id,
                'failure_stage' => $failureStage,
                'failure_type' => $failureType,
                'error_type' => $failureType,
                'error_message' => $failureMessage,
                'request_id' => $requestId,
                'response_id' => $responseId,
                'document_text_length' => data_get($requestResult, 'document_text_length'),
                'prompt_text_length' => data_get($requestResult, 'prompt_text_length'),
                'input_text_length' => data_get($requestResult, 'input_text_length'),
                'raw_output_length' => data_get($requestResult, 'raw_output_length'),
                'raw_output_preview' => data_get($requestResult, 'raw_output_preview'),
                'parse_strategy' => data_get($requestResult, 'parse_strategy'),
                'candidate_count' => 0,
                'openai_call_count' => (int) data_get($requestResult, 'openai_call_count', 1),
                'input_tokens_total' => data_get($requestResult, 'input_tokens'),
                'output_tokens_total' => data_get($requestResult, 'output_tokens'),
                'total_tokens_total' => data_get($requestResult, 'total_tokens'),
                'elapsed_ms' => data_get($requestResult, 'elapsed_ms'),
                'phase_1_requirement_extraction' => true,
            ]);

            return $failureResult;
        }

        Log::info('[PROCYNIA][AI_HANG] Phase 1 parsing started.', [
            'timestamp' => now()->toIso8601String(),
            'run_id' => $runId,
            'document_id' => $document->id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_id' => $document->saved_notice_id,
            'document_title' => $documentTitle,
            'document_filename' => $documentFilename,
            'raw_output_length' => $rawOutputLength,
            'raw_output_preview' => $rawOutputPreview,
            'request_id' => $requestId,
            'response_id' => $responseId,
            'phase_1_requirement_extraction' => true,
        ]);

        try {
            $parsed = $this->parsePhaseOneOutput($rawOutput);
        } catch (JsonException $exception) {
            $failureStage = 'response_parsing';
            $failureType = 'parsing_error';

            Log::warning('[PROCYNIA][REQ_PIPELINE] Phase 1 extraction response parsing failed.', [
                'run_id' => $runId,
                'document_id' => $document->id,
                'saved_notice_ai_document_id' => $document->id,
                'saved_notice_id' => $document->saved_notice_id,
                'failure_stage' => $failureStage,
                'failure_type' => $failureType,
                'error_message' => $exception->getMessage(),
                'request_id' => $requestId,
                'response_id' => $responseId,
                'raw_output_length' => $rawOutputLength,
                'raw_output_preview' => $rawOutputPreview,
                'parse_strategy' => 'invalid_json',
                'phase_1_requirement_extraction' => true,
            ]);

            return $this->buildFullDocumentFailureResult(
                $document,
                $runId,
                array_merge($requestResult, [
                    'raw_output_length' => $rawOutputLength,
                    'raw_output' => $rawOutput,
                    'raw_output_preview' => $rawOutputPreview,
                    'parse_strategy' => 'invalid_json',
                ]),
                $failureStage,
                $failureType,
                $exception->getMessage(),
                [
                    'raw_candidate_count' => 0,
                    'mapped_candidate_count' => 0,
                    'deduped_candidate_count' => 0,
                ],
            );
        } catch (RuntimeException $exception) {
            $failureStage = 'response_format';
            $failureType = 'unexpected_response';

            Log::warning('[PROCYNIA][REQ_PIPELINE] Phase 1 extraction returned an unexpected response format.', [
                'run_id' => $runId,
                'document_id' => $document->id,
                'saved_notice_ai_document_id' => $document->id,
                'saved_notice_id' => $document->saved_notice_id,
                'failure_stage' => $failureStage,
                'failure_type' => $failureType,
                'error_message' => $exception->getMessage(),
                'request_id' => $requestId,
                'response_id' => $responseId,
                'raw_output_length' => $rawOutputLength,
                'raw_output_preview' => $rawOutputPreview,
                'parse_strategy' => 'response_format',
                'phase_1_requirement_extraction' => true,
            ]);

            return $this->buildFullDocumentFailureResult(
                $document,
                $runId,
                array_merge($requestResult, [
                    'raw_output_length' => $rawOutputLength,
                    'raw_output' => $rawOutput,
                    'raw_output_preview' => $rawOutputPreview,
                    'parse_strategy' => 'response_format',
                ]),
                $failureStage,
                $failureType,
                $exception->getMessage(),
                [
                    'raw_candidate_count' => 0,
                    'mapped_candidate_count' => 0,
                    'deduped_candidate_count' => 0,
                ],
            );
        }

        $decodedCandidateCount = (int) ($parsed['row_count'] ?? 0);
        $parseStrategy = (string) ($parsed['strategy'] ?? 'unknown');

        Log::info('[PROCYNIA][REQ_PIPELINE] Decoded phase 1 candidates.', [
            'run_id' => $runId,
            'document_id' => $document->id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_id' => $document->saved_notice_id,
            'decoded_candidate_count' => $decodedCandidateCount,
            'zero_result' => $decodedCandidateCount === 0,
            'request_id' => $requestId,
            'response_id' => $responseId,
            'raw_output_length' => $rawOutputLength,
            'raw_output_preview' => $rawOutputPreview,
            'parse_strategy' => $parseStrategy,
        ]);

        Log::info('[PROCYNIA][AI_HANG] Phase 1 parsing finished.', [
            'timestamp' => now()->toIso8601String(),
            'run_id' => $runId,
            'document_id' => $document->id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_id' => $document->saved_notice_id,
            'document_title' => $documentTitle,
            'document_filename' => $documentFilename,
            'decoded_candidate_count' => $decodedCandidateCount,
            'parsed_strategy' => $parseStrategy,
            'raw_output_length' => $rawOutputLength,
            'raw_output_preview' => $rawOutputPreview,
            'phase_1_requirement_extraction' => true,
        ]);

        Log::info('[PROCYNIA][AI_HANG] Phase 1 mapping started.', [
            'timestamp' => now()->toIso8601String(),
            'run_id' => $runId,
            'document_id' => $document->id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_id' => $document->saved_notice_id,
            'decoded_candidate_count' => $decodedCandidateCount,
            'raw_output_length' => $rawOutputLength,
            'raw_output_preview' => $rawOutputPreview,
            'parse_strategy' => $parseStrategy,
            'phase_1_requirement_extraction' => true,
        ]);

        try {
            $candidates = $this->mapCandidates($document, $parsed['rows'] ?? []);
        } catch (Throwable $exception) {
            $failureStage = 'mapping';
            $failureType = 'mapping_error';

            Log::warning('[PROCYNIA][REQ_PIPELINE] Phase 1 extraction mapping failed.', [
                'run_id' => $runId,
                'document_id' => $document->id,
                'saved_notice_ai_document_id' => $document->id,
                'saved_notice_id' => $document->saved_notice_id,
                'failure_stage' => $failureStage,
                'failure_type' => $failureType,
                'error_message' => $exception->getMessage(),
                'request_id' => $requestId,
                'response_id' => $responseId,
                'raw_output_length' => $rawOutputLength,
                'raw_output_preview' => $rawOutputPreview,
                'parse_strategy' => $parseStrategy,
                'decoded_candidate_count' => $decodedCandidateCount,
                'phase_1_requirement_extraction' => true,
            ]);

            return $this->buildFullDocumentFailureResult(
                $document,
                $runId,
                array_merge($requestResult, [
                    'raw_output_length' => $rawOutputLength,
                    'raw_output' => $rawOutput,
                    'raw_output_preview' => $rawOutputPreview,
                    'parse_strategy' => $parseStrategy,
                ]),
                $failureStage,
                $failureType,
                $exception->getMessage(),
                [
                    'raw_candidate_count' => $decodedCandidateCount,
                    'mapped_candidate_count' => 0,
                    'deduped_candidate_count' => 0,
                ],
            );
        }

        $mappedCandidateCount = count($candidates);

        Log::info('[PROCYNIA][REQ_PIPELINE] Phase 1 candidates mapped into internal candidates.', [
            'run_id' => $runId,
            'document_id' => $document->id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_id' => $document->saved_notice_id,
            'decoded_candidate_count' => $decodedCandidateCount,
            'mapped_candidate_count' => $mappedCandidateCount,
            'zero_result' => $mappedCandidateCount === 0,
            'request_id' => $requestId,
            'response_id' => $responseId,
            'raw_output_length' => $rawOutputLength,
            'raw_output_preview' => $rawOutputPreview,
            'parse_strategy' => $parseStrategy,
        ]);

        try {
            $dedupedCandidates = $this->dedupeCandidates($candidates);
        } catch (Throwable $exception) {
            $failureStage = 'dedupe';
            $failureType = 'mapping_error';

            Log::warning('[PROCYNIA][REQ_PIPELINE] Phase 1 extraction dedupe failed.', [
                'run_id' => $runId,
                'document_id' => $document->id,
                'saved_notice_ai_document_id' => $document->id,
                'saved_notice_id' => $document->saved_notice_id,
                'failure_stage' => $failureStage,
                'failure_type' => $failureType,
                'error_message' => $exception->getMessage(),
                'request_id' => $requestId,
                'response_id' => $responseId,
                'raw_output_length' => $rawOutputLength,
                'raw_output_preview' => $rawOutputPreview,
                'parse_strategy' => $parseStrategy,
                'decoded_candidate_count' => $decodedCandidateCount,
                'mapped_candidate_count' => $mappedCandidateCount,
                'phase_1_requirement_extraction' => true,
            ]);

            return $this->buildFullDocumentFailureResult(
                $document,
                $runId,
                array_merge($requestResult, [
                    'raw_output_length' => $rawOutputLength,
                    'raw_output' => $rawOutput,
                    'raw_output_preview' => $rawOutputPreview,
                    'parse_strategy' => $parseStrategy,
                ]),
                $failureStage,
                $failureType,
                $exception->getMessage(),
                [
                    'raw_candidate_count' => $decodedCandidateCount,
                    'mapped_candidate_count' => $mappedCandidateCount,
                    'deduped_candidate_count' => 0,
                ],
            );
        }

        $dedupedCandidateCount = count($dedupedCandidates);

        Log::info('[PROCYNIA][AI_HANG] Phase 1 mapping finished.', [
            'timestamp' => now()->toIso8601String(),
            'run_id' => $runId,
            'document_id' => $document->id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_id' => $document->saved_notice_id,
            'decoded_candidate_count' => $decodedCandidateCount,
            'mapped_candidate_count' => $mappedCandidateCount,
            'deduped_candidate_count' => $dedupedCandidateCount,
            'raw_output_length' => $rawOutputLength,
            'raw_output_preview' => $rawOutputPreview,
            'parse_strategy' => $parseStrategy,
            'phase_1_requirement_extraction' => true,
        ]);

        Log::info('[PROCYNIA][REQ_PIPELINE] Normalized phase 1 candidates deduped.', [
            'run_id' => $runId,
            'document_id' => $document->id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_id' => $document->saved_notice_id,
            'normalized_requirement_count' => $mappedCandidateCount,
            'deduped_requirement_count' => $dedupedCandidateCount,
            'zero_result' => $dedupedCandidateCount === 0,
            'raw_output_length' => $rawOutputLength,
            'raw_output_preview' => $rawOutputPreview,
            'parse_strategy' => $parseStrategy,
        ]);

        return new RequirementExtractionResultData(
            ok: true,
            partial: false,
            savedNoticeId: (int) $document->saved_notice_id,
            savedNoticeAiDocumentId: $document->id,
            runId: $runId,
            documentTitle: $documentTitle,
            documentFilename: $documentFilename,
            model: $requestResult['model'],
            relevanceModel: $this->relevanceModel(),
            extractionModel: $requestResult['model'],
            segmentCount: 0,
            relevantSegmentCount: 0,
            relevanceCallCount: 0,
            extractionCallCount: 1,
            openAiCallCount: 1,
            segments: [],
            relevanceResults: [],
            extractionResults: [],
            candidates: $dedupedCandidates,
            metadata: [
                'prompt_versions' => [
                    'relevance' => $this->relevancePromptVersion(),
                    'extraction' => FullDocumentRequirementExtractionPrompt::promptVersion(),
                ],
                'phase_1_requirement_extraction' => true,
                'document_text_length' => $requestResult['document_text_length'],
                'prompt_text_length' => $requestResult['prompt_text_length'],
                'input_text_length' => $requestResult['input_text_length'],
                'raw_candidate_count' => $decodedCandidateCount,
                'mapped_candidate_count' => $mappedCandidateCount,
                'deduped_candidate_count' => $dedupedCandidateCount,
                'relevant_segment_count' => 0,
                'relevance_call_count' => 0,
                'extraction_call_count' => 1,
                'openai_call_count' => 1,
                'extraction_input_tokens' => $requestResult['input_tokens'],
                'extraction_output_tokens' => $requestResult['output_tokens'],
                'extraction_total_tokens' => $requestResult['total_tokens'],
                'input_tokens_total' => $requestResult['input_tokens'],
                'output_tokens_total' => $requestResult['output_tokens'],
                'total_tokens_total' => $requestResult['total_tokens'],
                'candidate_count' => $dedupedCandidateCount,
                'failure_count' => 0,
                'partial' => false,
                'fallback_used' => false,
                'elapsed_ms' => $requestResult['elapsed_ms'],
                'request_id' => $requestResult['request_id'],
                'response_id' => $requestResult['response_id'],
                'status' => $requestResult['status'],
                'raw_output_length' => $requestResult['raw_output_length'],
                'raw_output' => $requestResult['raw_output'],
                'parse_strategy' => $parsed['strategy'] ?? 'unknown',
            ],
            errorType: null,
            errorMessage: null,
        );
    }

    /**
     * Purpose: Extract structured requirement candidates from one contextual document block.
     * Inputs: The source AI document, one extraction block, and an optional run id for tracing.
     * Returns: A block-level extraction result with parsed candidates and provenance metadata.
     * Side effects: Calls OpenAI once and emits observability logs.
     */
    public function extractStructuredBlock(SavedNoticeAiDocument $document, RequirementExtractionBlockData $block, ?string $runId = null): RequirementExtractionResultData
    {
        $runId ??= (string) Str::uuid();
        $startedAt = microtime(true);
        $requestResult = $this->performStructuredBlockExtractionRequest($document, $block, $runId);
        $documentTitle = (string) $document->original_filename;
        $documentFilename = $documentTitle;

        if (! $requestResult['ok']) {
            return new RequirementExtractionResultData(
                ok: false,
                partial: false,
                savedNoticeId: (int) $document->saved_notice_id,
                savedNoticeAiDocumentId: $document->id,
                runId: $runId,
                documentTitle: $documentTitle,
                documentFilename: $documentFilename,
                model: $requestResult['model'],
                relevanceModel: $requestResult['model'],
                extractionModel: $requestResult['model'],
                segmentCount: 1,
                relevantSegmentCount: 1,
                relevanceCallCount: 0,
                extractionCallCount: 1,
                openAiCallCount: 1,
                segments: [],
                relevanceResults: [],
                extractionResults: [],
                candidates: [],
                metadata: [
                    'prompt_versions' => [
                        'extraction' => $requestResult['prompt_version'],
                    ],
                    'structured_block_mode' => true,
                    'block_count' => 1,
                    'saved_notice_ai_document_chunk_id' => $block->savedNoticeAiDocumentChunkId,
                    'block_id' => $block->sourceBlockId,
                    'block_index' => $block->sourceBlockIndex,
                    'block_type' => $block->blockType,
                    'document_text_length' => $requestResult['document_text_length'],
                    'input_text_length' => $requestResult['input_text_length'],
                    'raw_candidate_count' => 0,
                    'mapped_candidate_count' => 0,
                    'deduped_candidate_count' => 0,
                    'relevant_segment_count' => 1,
                    'relevance_call_count' => 0,
                    'extraction_call_count' => 1,
                    'openai_call_count' => 1,
                    'input_tokens_total' => $requestResult['input_tokens'],
                    'output_tokens_total' => $requestResult['output_tokens'],
                    'total_tokens_total' => $requestResult['total_tokens'],
                    'candidate_count' => 0,
                    'failure_count' => 1,
                    'partial' => false,
                    'fallback_used' => false,
                    'elapsed_ms' => $requestResult['elapsed_ms'],
                    'request_id' => $requestResult['request_id'],
                    'response_id' => $requestResult['response_id'],
                    'status' => $requestResult['status'],
                    'raw_output_length' => $requestResult['raw_output_length'],
                    'raw_output' => $requestResult['raw_output'],
                    'parse_strategy' => 'failed',
                    'structured_block_mode' => true,
                ],
                errorType: $requestResult['error_type'],
                errorMessage: $requestResult['error_message'],
            );
        }

        Log::info('[PROCYNIA][AI_HANG] Parsing started.', [
            'timestamp' => now()->toIso8601String(),
            'run_id' => $runId,
            'document_id' => $document->id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_id' => $document->saved_notice_id,
            'document_title' => $documentTitle,
            'document_filename' => $documentFilename,
            'saved_notice_ai_document_chunk_id' => $block->savedNoticeAiDocumentChunkId,
            'block_id' => $block->sourceBlockId,
            'block_index' => $block->sourceBlockIndex,
            'block_type' => $block->blockType,
            'raw_output_length' => $requestResult['raw_output_length'],
            'structured_block_mode' => true,
        ]);

        $parsed = $this->parseOutput($requestResult['raw_output']);
        $decodedCandidateCount = (int) ($parsed['row_count'] ?? 0);

        Log::info('[PROCYNIA][REQ_PIPELINE] Decoded structured block candidates.', [
            'run_id' => $runId,
            'document_id' => $document->id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_id' => $document->saved_notice_id,
            'saved_notice_ai_document_chunk_id' => $block->savedNoticeAiDocumentChunkId,
            'block_id' => $block->sourceBlockId,
            'block_index' => $block->sourceBlockIndex,
            'decoded_candidate_count' => $decodedCandidateCount,
            'zero_result' => $decodedCandidateCount === 0,
            'request_id' => $requestResult['request_id'],
            'response_id' => $requestResult['response_id'],
        ]);

        Log::info('[PROCYNIA][AI_HANG] Parsing finished.', [
            'timestamp' => now()->toIso8601String(),
            'run_id' => $runId,
            'document_id' => $document->id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_id' => $document->saved_notice_id,
            'document_title' => $documentTitle,
            'document_filename' => $documentFilename,
            'saved_notice_ai_document_chunk_id' => $block->savedNoticeAiDocumentChunkId,
            'block_id' => $block->sourceBlockId,
            'block_index' => $block->sourceBlockIndex,
            'decoded_candidate_count' => $decodedCandidateCount,
            'parsed_strategy' => $parsed['strategy'] ?? 'unknown',
            'structured_block_mode' => true,
        ]);

        Log::info('[PROCYNIA][AI_HANG] Mapping started.', [
            'timestamp' => now()->toIso8601String(),
            'run_id' => $runId,
            'document_id' => $document->id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_id' => $document->saved_notice_id,
            'saved_notice_ai_document_chunk_id' => $block->savedNoticeAiDocumentChunkId,
            'block_id' => $block->sourceBlockId,
            'block_index' => $block->sourceBlockIndex,
            'decoded_candidate_count' => $decodedCandidateCount,
            'structured_block_mode' => true,
        ]);

        $candidates = $this->mapCandidates($document, $parsed['rows'] ?? [], $block);
        $mappedCandidateCount = count($candidates);

        Log::info('[PROCYNIA][REQ_PIPELINE] Structured block candidates mapped into internal candidates.', [
            'run_id' => $runId,
            'document_id' => $document->id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_id' => $document->saved_notice_id,
            'saved_notice_ai_document_chunk_id' => $block->savedNoticeAiDocumentChunkId,
            'block_id' => $block->sourceBlockId,
            'block_index' => $block->sourceBlockIndex,
            'decoded_candidate_count' => $decodedCandidateCount,
            'mapped_candidate_count' => $mappedCandidateCount,
            'zero_result' => $mappedCandidateCount === 0,
            'request_id' => $requestResult['request_id'],
            'response_id' => $requestResult['response_id'],
        ]);

        $dedupedCandidates = $this->dedupeCandidates($candidates);
        $dedupedCandidateCount = count($dedupedCandidates);

        Log::info('[PROCYNIA][AI_HANG] Mapping finished.', [
            'timestamp' => now()->toIso8601String(),
            'run_id' => $runId,
            'document_id' => $document->id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_id' => $document->saved_notice_id,
            'saved_notice_ai_document_chunk_id' => $block->savedNoticeAiDocumentChunkId,
            'block_id' => $block->sourceBlockId,
            'block_index' => $block->sourceBlockIndex,
            'decoded_candidate_count' => $decodedCandidateCount,
            'mapped_candidate_count' => $mappedCandidateCount,
            'deduped_candidate_count' => $dedupedCandidateCount,
            'structured_block_mode' => true,
        ]);

        Log::info('[PROCYNIA][REQ_PIPELINE] Normalized structured block candidates deduped.', [
            'run_id' => $runId,
            'document_id' => $document->id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_id' => $document->saved_notice_id,
            'saved_notice_ai_document_chunk_id' => $block->savedNoticeAiDocumentChunkId,
            'block_id' => $block->sourceBlockId,
            'block_index' => $block->sourceBlockIndex,
            'normalized_requirement_count' => $mappedCandidateCount,
            'deduped_requirement_count' => $dedupedCandidateCount,
            'zero_result' => $dedupedCandidateCount === 0,
        ]);

        return new RequirementExtractionResultData(
            ok: true,
            partial: false,
            savedNoticeId: (int) $document->saved_notice_id,
            savedNoticeAiDocumentId: $document->id,
            runId: $runId,
            documentTitle: $documentTitle,
            documentFilename: $documentFilename,
            model: $requestResult['model'],
            relevanceModel: $requestResult['model'],
            extractionModel: $requestResult['model'],
            segmentCount: 1,
            relevantSegmentCount: 1,
            relevanceCallCount: 0,
            extractionCallCount: 1,
            openAiCallCount: 1,
            segments: [],
            relevanceResults: [],
            extractionResults: [],
            candidates: $dedupedCandidates,
            metadata: [
                'prompt_versions' => [
                    'extraction' => $requestResult['prompt_version'],
                ],
                'structured_block_mode' => true,
                'block_count' => 1,
                'saved_notice_ai_document_chunk_id' => $block->savedNoticeAiDocumentChunkId,
                'block_id' => $block->sourceBlockId,
                'block_index' => $block->sourceBlockIndex,
                'block_type' => $block->blockType,
                'document_text_length' => $requestResult['document_text_length'],
                'input_text_length' => $requestResult['input_text_length'],
                'raw_candidate_count' => $decodedCandidateCount,
                'mapped_candidate_count' => $mappedCandidateCount,
                'deduped_candidate_count' => $dedupedCandidateCount,
                'relevant_segment_count' => 1,
                'relevance_call_count' => 0,
                'extraction_call_count' => 1,
                'openai_call_count' => 1,
                'input_tokens_total' => $requestResult['input_tokens'],
                'output_tokens_total' => $requestResult['output_tokens'],
                'total_tokens_total' => $requestResult['total_tokens'],
                'candidate_count' => $dedupedCandidateCount,
                'failure_count' => 0,
                'partial' => false,
                'fallback_used' => false,
                'elapsed_ms' => $requestResult['elapsed_ms'],
                'request_id' => $requestId,
                'response_id' => $responseId,
                'status' => $requestResult['status'],
                'raw_output_length' => $requestResult['raw_output_length'],
                'raw_output' => $requestResult['raw_output'],
                'raw_output_preview' => $rawOutputPreview,
                'parse_strategy' => $parseStrategy,
                'failure_stage' => null,
                'failure_type' => null,
                'structured_block_mode' => true,
            ],
            errorType: null,
            errorMessage: null,
            failureStage: null,
            failureType: null,
        );
    }

    /**
     * Purpose: Execute the full-document OpenAI request and return the raw transport data.
     * Inputs: The source AI document and an optional run id for tracing.
     * Returns: A transport payload with response data, token usage, and raw assistant text.
     * Side effects: Calls OpenAI once and emits observability logs.
     */
    private function performFullDocumentExtractionRequest(SavedNoticeAiDocument $document, string $runId): array
    {
        $startedAt = microtime(true);
        $documentText = trim((string) $document->extracted_text);
        $documentTextLength = mb_strlen($documentText, 'UTF-8');
        $promptTextLength = mb_strlen(FullDocumentRequirementExtractionPrompt::text(), 'UTF-8');
        $payload = FullDocumentRequirementExtractionPrompt::requestPayload($documentText);
        $model = (string) ($payload['model'] ?? FullDocumentRequirementExtractionPrompt::model());
        $userInputText = (string) data_get($payload, 'input.1.content.0.text', FullDocumentRequirementExtractionPrompt::inputTextForDocument($documentText));
        $inputTextLength = $promptTextLength + mb_strlen($userInputText, 'UTF-8');
        $promptVersion = FullDocumentRequirementExtractionPrompt::promptVersion();

        Log::info('[PROCYNIA][AI_HANG] Phase 1 prompt built.', [
            'timestamp' => now()->toIso8601String(),
            'run_id' => $runId,
            'document_id' => $document->id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_id' => $document->saved_notice_id,
            'document_title' => $document->original_filename,
            'document_filename' => $document->original_filename,
            'document_text_length' => $documentTextLength,
            'prompt_text_length' => $promptTextLength,
            'input_text_length' => $inputTextLength,
            'prompt_version' => $promptVersion,
            'model' => $model,
            'max_output_tokens' => $payload['max_output_tokens'],
            'phase_1_requirement_extraction' => true,
            'bypassed_chunk_input' => true,
            'bypassed_segment_input' => true,
        ]);

        Log::info('[PROCYNIA][AI_COST][EXTRACTION][PRE_OPENAI] Phase 1 extraction OpenAI call starting.', [
            'run_id' => $runId,
            'document_id' => $document->id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_id' => $document->saved_notice_id,
            'document_title' => $document->original_filename,
            'document_filename' => $document->original_filename,
            'document_text_length' => $documentTextLength,
            'prompt_text_length' => $promptTextLength,
            'openai_call_count' => 1,
            'model' => $model,
            'input_text_length' => $inputTextLength,
            'max_output_tokens' => $payload['max_output_tokens'],
            'bypassed_chunk_input' => true,
            'bypassed_segment_input' => true,
            'phase_1_requirement_extraction' => true,
        ]);

        Log::info('[PROCYNIA][AI_HANG] Phase 1 OpenAI call starting.', [
            'timestamp' => now()->toIso8601String(),
            'run_id' => $runId,
            'document_id' => $document->id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_id' => $document->saved_notice_id,
            'document_title' => $document->original_filename,
            'document_filename' => $document->original_filename,
            'document_text_length' => $documentTextLength,
            'model' => $model,
            'max_output_tokens' => $payload['max_output_tokens'],
            'phase_1_requirement_extraction' => true,
            'bypassed_chunk_input' => true,
            'bypassed_segment_input' => true,
        ]);

        try {
            $response = $this->openAiClient->post('responses', $payload, 180);
        } catch (ConnectionException $exception) {
            $elapsedMs = $this->elapsedMs($startedAt);
            $errorType = str_contains(mb_strtolower($exception->getMessage(), 'UTF-8'), 'timed out')
                ? 'timeout'
                : 'connection_error';
            $failureStage = $errorType === 'timeout' ? 'openai_timeout' : 'openai_connection';

            Log::warning('[PROCYNIA][AI_COST][EXTRACTION][POST_OPENAI] Phase 1 extraction OpenAI call failed before a response was returned.', [
                'run_id' => $runId,
                'document_id' => $document->id,
                'saved_notice_ai_document_id' => $document->id,
                'saved_notice_id' => $document->saved_notice_id,
                'document_title' => $document->original_filename,
                'document_filename' => $document->original_filename,
                'document_text_length' => $documentTextLength,
                'prompt_text_length' => $promptTextLength,
                'input_text_length' => $inputTextLength,
                'openai_call_count' => 1,
                'model' => $model,
                'request_id' => null,
                'response_id' => null,
                'status' => null,
                'input_tokens' => null,
                'output_tokens' => null,
                'total_tokens' => null,
                'elapsed_ms' => $elapsedMs,
                'bypassed_chunk_input' => true,
                'bypassed_segment_input' => true,
                'failure_stage' => $failureStage,
                'failure_type' => $errorType,
                'error_type' => $errorType,
                'error_message' => $exception->getMessage(),
                'phase_1_requirement_extraction' => true,
            ]);

            Log::warning('[PROCYNIA][AI_HANG] Phase 1 OpenAI call failed before a response was returned.', [
                'timestamp' => now()->toIso8601String(),
                'run_id' => $runId,
                'document_id' => $document->id,
                'saved_notice_ai_document_id' => $document->id,
                'saved_notice_id' => $document->saved_notice_id,
                'document_title' => $document->original_filename,
                'document_filename' => $document->original_filename,
                'document_text_length' => $documentTextLength,
                'prompt_text_length' => $promptTextLength,
                'input_text_length' => $inputTextLength,
                'model' => $model,
                'phase_1_requirement_extraction' => true,
                'failure_stage' => $failureStage,
                'failure_type' => $errorType,
                'error_type' => $errorType,
                'error_message' => $exception->getMessage(),
            ]);

            Log::warning('[PROCYNIA][REQ_PIPELINE] Phase 1 extraction failed before OpenAI returned a response.', [
                'run_id' => $runId,
                'document_id' => $document->id,
                'saved_notice_ai_document_id' => $document->id,
                'saved_notice_id' => $document->saved_notice_id,
                'document_title' => $document->original_filename,
                'document_filename' => $document->original_filename,
                'stage' => $failureStage,
                'failure_stage' => $failureStage,
                'failure_type' => $errorType,
                'exception_message' => $exception->getMessage(),
                'phase_1_requirement_extraction' => true,
            ]);

            return [
                'ok' => false,
                'run_id' => $runId,
                'document_id' => $document->id,
                'saved_notice_ai_document_id' => $document->id,
                'saved_notice_id' => $document->saved_notice_id,
                'document_title' => $document->original_filename,
                'document_filename' => $document->original_filename,
                'prompt_version' => FullDocumentRequirementExtractionPrompt::promptVersion(),
                'model' => $model,
                'max_output_tokens' => FullDocumentRequirementExtractionPrompt::maxOutputTokens(),
                'request_id' => null,
                'response_id' => null,
                'status' => null,
                'document_text_length' => $documentTextLength,
                'prompt_text_length' => $promptTextLength,
                'input_text_length' => $inputTextLength,
                'raw_output_length' => 0,
                'raw_output' => '',
                'raw_output_preview' => '',
                'parse_strategy' => null,
                'input_tokens' => null,
                'output_tokens' => null,
                'total_tokens' => null,
                'elapsed_ms' => $elapsedMs,
                'bypassed_chunk_input' => true,
                'bypassed_segment_input' => true,
                'failure_stage' => $failureStage,
                'failure_type' => $errorType,
                'error_type' => $errorType,
                'error_message' => $exception->getMessage(),
                'phase_1_requirement_extraction' => true,
            ];
        } catch (Throwable $exception) {
            $elapsedMs = $this->elapsedMs($startedAt);
            $failureStage = 'openai_request';
            $failureType = 'unknown_error';

            Log::warning('[PROCYNIA][AI_COST][EXTRACTION][POST_OPENAI] Phase 1 extraction OpenAI call failed before a response was returned.', [
                'run_id' => $runId,
                'document_id' => $document->id,
                'saved_notice_ai_document_id' => $document->id,
                'saved_notice_id' => $document->saved_notice_id,
                'document_title' => $document->original_filename,
                'document_filename' => $document->original_filename,
                'document_text_length' => $documentTextLength,
                'prompt_text_length' => $promptTextLength,
                'input_text_length' => $inputTextLength,
                'openai_call_count' => 1,
                'model' => $model,
                'request_id' => null,
                'response_id' => null,
                'status' => null,
                'input_tokens' => null,
                'output_tokens' => null,
                'total_tokens' => null,
                'elapsed_ms' => $elapsedMs,
                'bypassed_chunk_input' => true,
                'bypassed_segment_input' => true,
                'failure_stage' => $failureStage,
                'failure_type' => $failureType,
                'error_type' => $failureType,
                'error_message' => $exception->getMessage(),
                'phase_1_requirement_extraction' => true,
            ]);

            Log::warning('[PROCYNIA][AI_HANG] Phase 1 OpenAI call failed before a response was returned.', [
                'timestamp' => now()->toIso8601String(),
                'run_id' => $runId,
                'document_id' => $document->id,
                'saved_notice_ai_document_id' => $document->id,
                'saved_notice_id' => $document->saved_notice_id,
                'document_title' => $document->original_filename,
                'document_filename' => $document->original_filename,
                'document_text_length' => $documentTextLength,
                'prompt_text_length' => $promptTextLength,
                'input_text_length' => $inputTextLength,
                'model' => $model,
                'phase_1_requirement_extraction' => true,
                'failure_stage' => $failureStage,
                'failure_type' => $failureType,
                'error_type' => $failureType,
                'error_message' => $exception->getMessage(),
            ]);

            Log::warning('[PROCYNIA][REQ_PIPELINE] Phase 1 extraction failed before OpenAI returned a response.', [
                'run_id' => $runId,
                'document_id' => $document->id,
                'saved_notice_ai_document_id' => $document->id,
                'saved_notice_id' => $document->saved_notice_id,
                'document_title' => $document->original_filename,
                'document_filename' => $document->original_filename,
                'stage' => $failureStage,
                'failure_stage' => $failureStage,
                'failure_type' => $failureType,
                'exception_message' => $exception->getMessage(),
                'phase_1_requirement_extraction' => true,
            ]);

            return [
                'ok' => false,
                'run_id' => $runId,
                'document_id' => $document->id,
                'saved_notice_ai_document_id' => $document->id,
                'saved_notice_id' => $document->saved_notice_id,
                'document_title' => $document->original_filename,
                'document_filename' => $document->original_filename,
                'prompt_version' => FullDocumentRequirementExtractionPrompt::promptVersion(),
                'model' => $model,
                'max_output_tokens' => FullDocumentRequirementExtractionPrompt::maxOutputTokens(),
                'request_id' => null,
                'response_id' => null,
                'status' => null,
                'document_text_length' => $documentTextLength,
                'prompt_text_length' => $promptTextLength,
                'input_text_length' => $inputTextLength,
                'raw_output_length' => 0,
                'raw_output' => '',
                'raw_output_preview' => '',
                'parse_strategy' => null,
                'input_tokens' => null,
                'output_tokens' => null,
                'total_tokens' => null,
                'elapsed_ms' => $elapsedMs,
                'bypassed_chunk_input' => true,
                'bypassed_segment_input' => true,
                'failure_stage' => $failureStage,
                'failure_type' => $failureType,
                'error_type' => $failureType,
                'error_message' => $exception->getMessage(),
                'phase_1_requirement_extraction' => true,
            ];
        }

        $requestId = $this->requestIdFrom($response);
        $responseId = $this->responseIdFrom($response);
        $status = $response->status();
        $rawResponseBody = trim($response->body());
        $upstreamError = $this->upstreamErrorDetailsFromRawBody($rawResponseBody);
        $tokenUsage = $this->tokenUsageFromResponse($response);
        $rawOutput = $this->responseTextFromOpenAi($response->json());
        $elapsedMs = $this->elapsedMs($startedAt);

        Log::info('[PROCYNIA][AI_COST][EXTRACTION][POST_OPENAI] Phase 1 extraction OpenAI call completed.', [
            'run_id' => $runId,
            'document_id' => $document->id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_id' => $document->saved_notice_id,
            'document_title' => $document->original_filename,
            'document_filename' => $document->original_filename,
            'document_text_length' => $documentTextLength,
            'prompt_text_length' => $promptTextLength,
            'input_text_length' => $inputTextLength,
            'openai_call_count' => 1,
            'model' => $model,
            'request_id' => $requestId,
            'response_id' => $responseId,
            'status' => $status,
            'input_tokens' => $tokenUsage['input_tokens'],
            'output_tokens' => $tokenUsage['output_tokens'],
            'total_tokens' => $tokenUsage['total_tokens'],
            'elapsed_ms' => $elapsedMs,
            'raw_output_length' => mb_strlen($rawOutput, 'UTF-8'),
            'raw_output_preview' => $this->previewText($rawOutput),
            'bypassed_chunk_input' => true,
            'bypassed_segment_input' => true,
            'phase_1_requirement_extraction' => true,
        ]);

        Log::info('[PROCYNIA][AI_HANG] Phase 1 OpenAI call returned.', [
            'timestamp' => now()->toIso8601String(),
            'run_id' => $runId,
            'document_id' => $document->id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_id' => $document->saved_notice_id,
            'document_title' => $document->original_filename,
            'document_filename' => $document->original_filename,
            'document_text_length' => $documentTextLength,
            'model' => $model,
            'request_id' => $requestId,
            'response_id' => $responseId,
            'status' => $status,
            'input_tokens' => $tokenUsage['input_tokens'],
            'output_tokens' => $tokenUsage['output_tokens'],
            'total_tokens' => $tokenUsage['total_tokens'],
            'elapsed_ms' => $elapsedMs,
            'raw_output_length' => mb_strlen($rawOutput, 'UTF-8'),
            'raw_output_preview' => $this->previewText($rawOutput),
            'phase_1_requirement_extraction' => true,
            'bypassed_chunk_input' => true,
            'bypassed_segment_input' => true,
        ]);

        $errorType = null;
        $errorMessage = null;
        $failureStage = null;

        if (! $response->successful()) {
            $errorType = $this->errorTypeForStatus($status);
            $errorMessage = $upstreamError['message'] ?? sprintf('OpenAI phase 1 extraction request failed with HTTP status [%d].', $status);
            $failureStage = 'openai_http_status';

            Log::warning('[PROCYNIA][REQ_PIPELINE] Phase 1 extraction returned a non-success HTTP response and will return a failure result.', [
                'run_id' => $runId,
                'document_id' => $document->id,
                'saved_notice_ai_document_id' => $document->id,
                'saved_notice_id' => $document->saved_notice_id,
                'document_title' => $document->original_filename,
                'document_filename' => $document->original_filename,
                'stage' => $failureStage,
                'failure_stage' => $failureStage,
                'failure_type' => $errorType,
                'http_status' => $status,
                'request_id' => $requestId,
                'response_id' => $responseId,
                'prompt_text_length' => $promptTextLength,
                'input_text_length' => $inputTextLength,
                'raw_output_length' => mb_strlen($rawOutput, 'UTF-8'),
                'raw_output_preview' => $this->previewText($rawOutput),
                'raw_response_body_length' => mb_strlen($rawResponseBody, 'UTF-8'),
                'raw_response_body' => $rawResponseBody,
                'upstream_error_message' => $upstreamError['message'],
                'upstream_error_type' => $upstreamError['type'],
                'upstream_error_code' => $upstreamError['code'],
                'upstream_error_param' => $upstreamError['param'],
                'phase_1_requirement_extraction' => true,
            ]);
        }

        return [
            'ok' => $response->successful(),
            'run_id' => $runId,
            'document_id' => $document->id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_id' => $document->saved_notice_id,
            'document_title' => $document->original_filename,
            'document_filename' => $document->original_filename,
            'prompt_version' => $promptVersion,
            'model' => $model,
            'max_output_tokens' => FullDocumentRequirementExtractionPrompt::maxOutputTokens(),
            'request_id' => $requestId,
            'response_id' => $responseId,
            'status' => $status,
            'document_text_length' => $documentTextLength,
            'prompt_text_length' => $promptTextLength,
            'input_text_length' => $inputTextLength,
            'raw_output_length' => mb_strlen($rawOutput, 'UTF-8'),
            'raw_output' => $rawOutput,
            'raw_output_preview' => $this->previewText($rawOutput),
            'raw_response_body' => $rawResponseBody,
            'raw_response_body_length' => mb_strlen($rawResponseBody, 'UTF-8'),
            'upstream_error_message' => $upstreamError['message'],
            'upstream_error_type' => $upstreamError['type'],
            'upstream_error_code' => $upstreamError['code'],
            'upstream_error_param' => $upstreamError['param'],
            'input_tokens' => $tokenUsage['input_tokens'],
            'output_tokens' => $tokenUsage['output_tokens'],
            'total_tokens' => $tokenUsage['total_tokens'],
            'elapsed_ms' => $elapsedMs,
            'bypassed_chunk_input' => true,
            'bypassed_segment_input' => true,
            'failure_stage' => $failureStage,
            'failure_type' => $errorType,
            'error_type' => $errorType,
            'error_message' => $errorMessage,
            'phase_1_requirement_extraction' => true,
        ];
    }

    /**
     * Purpose: Execute one structured block OpenAI request and return the raw transport data.
     * Inputs: The source AI document, one extraction block, and an optional run id for tracing.
     * Returns: A transport payload with response data, token usage, and raw assistant text.
     * Side effects: Calls OpenAI once and emits observability logs.
     */
    private function performStructuredBlockExtractionRequest(SavedNoticeAiDocument $document, RequirementExtractionBlockData $block, string $runId): array
    {
        $startedAt = microtime(true);
        $documentText = trim($block->content);
        $documentTextLength = mb_strlen($documentText, 'UTF-8');
        $payload = $this->blockPromptBuilder->buildRequestPayload($document, collect([$block]));
        $model = (string) ($payload['model'] ?? $this->blockPromptBuilder->model());
        $inputTextLength = mb_strlen((string) data_get($payload, 'input.1.content.0.text', ''), 'UTF-8');
        $promptVersion = $this->blockPromptBuilder->promptVersion();

        Log::info('[PROCYNIA][AI_HANG] Prompt built.', [
            'timestamp' => now()->toIso8601String(),
            'run_id' => $runId,
            'document_id' => $document->id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_id' => $document->saved_notice_id,
            'document_title' => $document->original_filename,
            'document_filename' => $document->original_filename,
            'document_text_length' => $documentTextLength,
            'input_text_length' => $inputTextLength,
            'prompt_version' => $promptVersion,
            'model' => $model,
            'max_output_tokens' => $payload['max_output_tokens'] ?? null,
            'structured_block_mode' => true,
            'saved_notice_ai_document_chunk_id' => $block->savedNoticeAiDocumentChunkId,
            'block_id' => $block->sourceBlockId,
            'block_index' => $block->sourceBlockIndex,
            'block_type' => $block->blockType,
        ]);

        Log::info('[PROCYNIA][AI_COST][EXTRACTION][PRE_OPENAI] Structured block extraction OpenAI call starting.', [
            'run_id' => $runId,
            'document_id' => $document->id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_id' => $document->saved_notice_id,
            'document_title' => $document->original_filename,
            'document_filename' => $document->original_filename,
            'document_text_length' => $documentTextLength,
            'openai_call_count' => 1,
            'model' => $model,
            'input_text_length' => $inputTextLength,
            'max_output_tokens' => $payload['max_output_tokens'] ?? null,
            'structured_block_mode' => true,
            'saved_notice_ai_document_chunk_id' => $block->savedNoticeAiDocumentChunkId,
            'block_id' => $block->sourceBlockId,
            'block_index' => $block->sourceBlockIndex,
            'block_type' => $block->blockType,
        ]);

        Log::info('[PROCYNIA][AI_HANG] OpenAI call starting.', [
            'timestamp' => now()->toIso8601String(),
            'run_id' => $runId,
            'document_id' => $document->id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_id' => $document->saved_notice_id,
            'document_title' => $document->original_filename,
            'document_filename' => $document->original_filename,
            'document_text_length' => $documentTextLength,
            'model' => $model,
            'max_output_tokens' => $payload['max_output_tokens'] ?? null,
            'structured_block_mode' => true,
            'saved_notice_ai_document_chunk_id' => $block->savedNoticeAiDocumentChunkId,
            'block_id' => $block->sourceBlockId,
            'block_index' => $block->sourceBlockIndex,
            'block_type' => $block->blockType,
        ]);

        try {
            $response = $this->openAiClient->post('responses', $payload, 180);
        } catch (Throwable $exception) {
            $elapsedMs = $this->elapsedMs($startedAt);
            $errorType = str_contains(mb_strtolower($exception->getMessage(), 'UTF-8'), 'timed out')
                ? 'timeout'
                : 'connection_error';

            Log::warning('[PROCYNIA][AI_COST][EXTRACTION][POST_OPENAI] Structured block extraction OpenAI call failed before a response was returned.', $this->aiCostContext($document, $runId, [
                'document_title' => $document->original_filename,
                'document_filename' => $document->original_filename,
                'document_text_length' => $documentTextLength,
                'openai_call_count' => 1,
                'model' => $model,
                'request_id' => null,
                'response_id' => null,
                'status' => null,
                'input_tokens' => null,
                'output_tokens' => null,
                'total_tokens' => null,
                'elapsed_ms' => $elapsedMs,
                'structured_block_mode' => true,
                'saved_notice_ai_document_chunk_id' => $block->savedNoticeAiDocumentChunkId,
                'block_id' => $block->sourceBlockId,
                'block_index' => $block->sourceBlockIndex,
                'block_type' => $block->blockType,
                'error_type' => $errorType,
                'error_message' => $exception->getMessage(),
            ]));

            Log::warning('[PROCYNIA][AI_HANG] OpenAI call failed before a response was returned.', [
                'timestamp' => now()->toIso8601String(),
                'run_id' => $runId,
                'document_id' => $document->id,
                'saved_notice_ai_document_id' => $document->id,
                'saved_notice_id' => $document->saved_notice_id,
                'document_title' => $document->original_filename,
                'document_filename' => $document->original_filename,
                'document_text_length' => $documentTextLength,
                'model' => $model,
                'structured_block_mode' => true,
                'saved_notice_ai_document_chunk_id' => $block->savedNoticeAiDocumentChunkId,
                'block_id' => $block->sourceBlockId,
                'block_index' => $block->sourceBlockIndex,
                'block_type' => $block->blockType,
                'error_type' => $errorType,
                'error_message' => $exception->getMessage(),
            ]);

            Log::warning('[PROCYNIA][REQ_PIPELINE] Structured block extraction failed before OpenAI returned a response.', [
                'run_id' => $runId,
                'document_id' => $document->id,
                'saved_notice_ai_document_id' => $document->id,
                'saved_notice_id' => $document->saved_notice_id,
                'document_title' => $document->original_filename,
                'document_filename' => $document->original_filename,
                'stage' => 'openai_connection',
                'exception_message' => $exception->getMessage(),
                'saved_notice_ai_document_chunk_id' => $block->savedNoticeAiDocumentChunkId,
                'block_id' => $block->sourceBlockId,
                'block_index' => $block->sourceBlockIndex,
            ]);

            return [
                'ok' => false,
                'run_id' => $runId,
                'document_id' => $document->id,
                'saved_notice_ai_document_id' => $document->id,
                'saved_notice_id' => $document->saved_notice_id,
                'document_title' => $document->original_filename,
                'document_filename' => $document->original_filename,
                'prompt_version' => $promptVersion,
                'model' => $model,
                'max_output_tokens' => $payload['max_output_tokens'] ?? null,
                'request_id' => null,
                'response_id' => null,
                'status' => null,
                'document_text_length' => $documentTextLength,
                'input_text_length' => $inputTextLength,
                'raw_output_length' => 0,
                'raw_output' => '',
                'input_tokens' => null,
                'output_tokens' => null,
                'total_tokens' => null,
                'elapsed_ms' => $elapsedMs,
                'structured_block_mode' => true,
                'saved_notice_ai_document_chunk_id' => $block->savedNoticeAiDocumentChunkId,
                'block_id' => $block->sourceBlockId,
                'block_index' => $block->sourceBlockIndex,
                'block_type' => $block->blockType,
                'error_type' => $errorType,
                'error_message' => $exception->getMessage(),
            ];
        }

        $requestId = $this->segmentRequestIdFrom($response);
        $responseId = $this->segmentResponseIdFrom($response);
        $status = $response->status();
        $rawResponseBody = trim($response->body());
        $tokenUsage = $this->segmentTokenUsageFromResponse($response);
        $rawOutput = $this->segmentResponseTextFromOpenAi($response->json());
        $elapsedMs = $this->elapsedMs($startedAt);

        Log::info('[PROCYNIA][AI_COST][EXTRACTION][POST_OPENAI] Structured block extraction OpenAI call completed.', [
            'run_id' => $runId,
            'document_id' => $document->id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_id' => $document->saved_notice_id,
            'document_title' => $document->original_filename,
            'document_filename' => $document->original_filename,
            'document_text_length' => $documentTextLength,
            'openai_call_count' => 1,
            'model' => $model,
            'request_id' => $requestId,
            'response_id' => $responseId,
            'status' => $status,
            'input_tokens' => $tokenUsage['input_tokens'],
            'output_tokens' => $tokenUsage['output_tokens'],
            'total_tokens' => $tokenUsage['total_tokens'],
            'elapsed_ms' => $elapsedMs,
            'structured_block_mode' => true,
            'saved_notice_ai_document_chunk_id' => $block->savedNoticeAiDocumentChunkId,
            'block_id' => $block->sourceBlockId,
            'block_index' => $block->sourceBlockIndex,
            'block_type' => $block->blockType,
        ]);

        Log::info('[PROCYNIA][AI_HANG] OpenAI call returned.', [
            'timestamp' => now()->toIso8601String(),
            'run_id' => $runId,
            'document_id' => $document->id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_id' => $document->saved_notice_id,
            'document_title' => $document->original_filename,
            'document_filename' => $document->original_filename,
            'document_text_length' => $documentTextLength,
            'model' => $model,
            'request_id' => $requestId,
            'response_id' => $responseId,
            'status' => $status,
            'input_tokens' => $tokenUsage['input_tokens'],
            'output_tokens' => $tokenUsage['output_tokens'],
            'total_tokens' => $tokenUsage['total_tokens'],
            'elapsed_ms' => $elapsedMs,
            'structured_block_mode' => true,
            'saved_notice_ai_document_chunk_id' => $block->savedNoticeAiDocumentChunkId,
            'block_id' => $block->sourceBlockId,
            'block_index' => $block->sourceBlockIndex,
            'block_type' => $block->blockType,
        ]);

        $errorType = null;
        $errorMessage = null;

        if (! $response->successful()) {
            $errorType = $this->errorTypeForStatus($status);
            $errorMessage = sprintf('OpenAI structured block extraction request failed with HTTP status [%d].', $status);

            Log::warning('[PROCYNIA][REQ_PIPELINE] Structured block extraction returned a non-success HTTP response and will return a failure result.', [
                'run_id' => $runId,
                'document_id' => $document->id,
                'saved_notice_ai_document_id' => $document->id,
                'saved_notice_id' => $document->saved_notice_id,
                'document_title' => $document->original_filename,
                'document_filename' => $document->original_filename,
                'stage' => 'openai_http_status',
                'http_status' => $status,
                'request_id' => $requestId,
                'response_id' => $responseId,
                'saved_notice_ai_document_chunk_id' => $block->savedNoticeAiDocumentChunkId,
                'block_id' => $block->sourceBlockId,
                'block_index' => $block->sourceBlockIndex,
            ]);
        }

        return [
            'ok' => $response->successful(),
            'run_id' => $runId,
            'document_id' => $document->id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_id' => $document->saved_notice_id,
            'document_title' => $document->original_filename,
            'document_filename' => $document->original_filename,
            'prompt_version' => $promptVersion,
            'model' => $model,
            'max_output_tokens' => $payload['max_output_tokens'] ?? null,
            'request_id' => $requestId,
            'response_id' => $responseId,
            'status' => $status,
            'document_text_length' => $documentTextLength,
            'input_text_length' => $inputTextLength,
            'raw_output_length' => mb_strlen($rawOutput, 'UTF-8'),
            'raw_output' => $rawOutput,
            'raw_response_body' => $rawResponseBody,
            'input_tokens' => $tokenUsage['input_tokens'],
            'output_tokens' => $tokenUsage['output_tokens'],
            'total_tokens' => $tokenUsage['total_tokens'],
            'elapsed_ms' => $elapsedMs,
            'structured_block_mode' => true,
            'saved_notice_ai_document_chunk_id' => $block->savedNoticeAiDocumentChunkId,
            'block_id' => $block->sourceBlockId,
            'block_index' => $block->sourceBlockIndex,
            'block_type' => $block->blockType,
            'error_type' => $errorType,
            'error_message' => $errorMessage,
        ];
    }

    /**
     * Purpose: Resolve the configured model name used for relevance metadata in extraction results.
     * Inputs: None.
     * Returns: The configured relevance model name.
     * Side effects: None.
     */
    private function relevanceModel(): string
    {
        $model = trim((string) config(
            'services.openai.requirement_relevance_model',
            config('services.openai.requirement_extraction_model', config('services.openai.model', 'gpt-4.1-mini')),
        ));

        return $model !== '' ? $model : 'gpt-4.1-mini';
    }

    /**
     * Purpose: Return the prompt version used for relevance metadata in extraction results.
     * Inputs: None.
     * Returns: The configured prompt version string.
     * Side effects: None.
     */
    private function relevancePromptVersion(): string
    {
        return RequirementSegmentRelevancePromptBuilder::PROMPT_VERSION;
    }

    /**
     * Purpose: Build the OpenAI Responses API payload for a single segment extraction request.
     * Inputs: The source AI document and the source segment.
     * Returns: The exact payload sent to OpenAI.
     * Side effects: None.
     */
    private function segmentRequestPayload(SavedNoticeAiDocument $document, DocumentRequirementSegmentData $segment): array
    {
        return $this->promptBuilder->buildRequestPayload($document, $segment);
    }

    /**
     * Purpose: Build a structured failure result for one segment extraction attempt.
     * Inputs: The source AI document, the segment, tracing metadata, and the error details.
     * Returns: A deterministic failure result that preserves the segment provenance.
     * Side effects: Emits a warning log.
     */
    private function failedSegmentResult(
        SavedNoticeAiDocument $document,
        DocumentRequirementSegmentData $segment,
        string $runId,
        string $model,
        ?string $requestId = null,
        ?string $responseId = null,
        ?int $upstreamStatus = null,
        ?string $rawResponseBody = null,
        array $tokenUsage = [],
        ?int $elapsedMs = null,
        string $errorType = 'unexpected_error',
        string $errorMessage = 'Segment extraction failed.',
    ): RequirementSegmentExtractionResultData {
        Log::warning('[PROCYNIA][AI][EXTRACTION] Segment extraction failed.', [
            'run_id' => $runId,
            'saved_notice_id' => $document->saved_notice_id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_ai_document_chunk_id' => $segment->savedNoticeAiDocumentChunkId,
            'segment_id' => $segment->segmentId,
            'segment_index' => $segment->segmentIndex,
            'model' => $model,
            'request_id' => $requestId,
            'response_id' => $responseId,
            'upstream_status' => $upstreamStatus,
            'error_type' => $errorType,
            'error_message' => $errorMessage,
            'raw_response' => $rawResponseBody,
        ]);

        return new RequirementSegmentExtractionResultData(
            ok: false,
            savedNoticeId: $segment->savedNoticeId,
            savedNoticeAiDocumentId: $segment->savedNoticeAiDocumentId,
            savedNoticeAiDocumentChunkId: $segment->savedNoticeAiDocumentChunkId,
            documentTitle: $segment->documentTitle,
            documentFilename: $segment->documentFilename,
            segmentId: $segment->segmentId,
            segmentIndex: $segment->segmentIndex,
            model: $model,
            requestId: $requestId,
            responseId: $responseId,
            upstreamStatus: $upstreamStatus,
            fallbackUsed: false,
            errorType: $errorType,
            errorMessage: $errorMessage,
            openAiCallCount: 1,
            elapsedMs: $elapsedMs,
            inputTokens: $tokenUsage['input_tokens'] ?? null,
            outputTokens: $tokenUsage['output_tokens'] ?? null,
            totalTokens: $tokenUsage['total_tokens'] ?? null,
            parseStrategy: 'error',
            rawOutput: $rawResponseBody,
            candidateCount: 0,
            candidates: [],
            metadata: [
                'prompt_version' => $this->promptBuilder->promptVersion(),
            ],
        );
    }

    /**
     * Purpose: Build a consistent log context for segment extraction cost tracing.
     * Inputs: The AI document, the segment, the extraction run id, and any additional context fields.
     * Returns: A structured log context with stable document and segment identifiers.
     * Side effects: None.
     */
    private function segmentAiCostContext(SavedNoticeAiDocument $document, DocumentRequirementSegmentData $segment, string $runId, array $extra = []): array
    {
        return array_merge([
            'run_id' => $runId,
            'document_id' => $document->id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_id' => $document->saved_notice_id,
            'document_title' => $segment->documentTitle,
            'document_filename' => $segment->documentFilename,
            'saved_notice_ai_document_chunk_id' => $segment->savedNoticeAiDocumentChunkId,
            'segment_id' => $segment->segmentId,
            'segment_index' => $segment->segmentIndex,
        ], $extra);
    }

    /**
     * Purpose: Extract the assistant text payload from a Responses API result.
     * Inputs: The raw OpenAI response payload.
     * Returns: The concatenated response text.
     * Side effects: None.
     */
    private function segmentResponseTextFromOpenAi(array $response): string
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
                    throw new RuntimeException('OpenAI refused to return a segment extraction result.');
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
     * Purpose: Decode the JSON payload returned by OpenAI for segment extraction.
     * Inputs: The raw assistant text.
     * Returns: The decoded structured payload.
     * Side effects: Throws when the payload cannot be parsed.
     */
    private function decodeSegmentPayload(string $rawText): array
    {
        $text = $this->segmentStripCodeFences(trim($rawText));

        if ($text === '') {
            throw new RuntimeException('OpenAI segment extraction response did not include any text output.');
        }

        try {
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('OpenAI segment extraction response was not valid JSON.', 0, $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('OpenAI segment extraction response did not decode to a JSON object.');
        }

        $candidates = data_get($decoded, 'candidates');

        if (! is_array($candidates)) {
            throw new RuntimeException('OpenAI segment extraction response did not include a candidates array.');
        }

        return [
            'candidates' => array_values($candidates),
        ];
    }

    /**
     * Purpose: Remove Markdown code fences if the model wrapped the JSON payload.
     * Inputs: Raw model text.
     * Returns: The cleaned text.
     * Side effects: None.
     */
    private function segmentStripCodeFences(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/', '', $text) ?? $text;

        return trim($text);
    }

    /**
     * Purpose: Convert parsed extraction rows into canonical candidate DTOs.
     * Inputs: The source segment and the parsed row list.
     * Returns: A deterministic list of requirement candidates.
     * Side effects: None.
     */
    private function segmentMapCandidates(
        SavedNoticeAiDocument $document,
        DocumentRequirementSegmentData $segment,
        string $runId,
        ?string $requestId,
        ?string $responseId,
        array $rows,
    ): array
    {
        $candidates = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                Log::warning('[PROCYNIA][REQ_PIPELINE] Requirement candidate rejected before mapping.', [
                    'run_id' => $runId,
                    'document_id' => $document->id,
                    'saved_notice_ai_document_id' => $document->id,
                    'saved_notice_id' => $document->saved_notice_id,
                    'segment_id' => $segment->segmentId,
                    'segment_index' => $segment->segmentIndex,
                    'candidate_index' => $index,
                    'rejection_reason_category' => 'invalid_candidate_type',
                    'missing_field' => null,
                    'request_id' => $requestId,
                    'response_id' => $responseId,
                ]);

                continue;
            }

            $missingField = null;

            foreach (self::SEGMENT_REQUIRED_FIELDS as $field) {
                if (! array_key_exists($field, $row)) {
                    $missingField = $field;
                    break;
                }

                if (in_array($field, self::SEGMENT_REQUIRED_NON_EMPTY_STRING_FIELDS, true)) {
                    $value = $row[$field];

                    if (! is_string($value) || trim($value) === '') {
                        $missingField = $field;
                        break;
                    }
                }
            }

            if ($missingField !== null) {
                Log::warning('[PROCYNIA][REQ_PIPELINE] Requirement candidate rejected before mapping.', [
                    'run_id' => $runId,
                    'document_id' => $document->id,
                    'saved_notice_ai_document_id' => $document->id,
                    'saved_notice_id' => $document->saved_notice_id,
                    'segment_id' => $segment->segmentId,
                    'segment_index' => $segment->segmentIndex,
                    'candidate_index' => $index,
                    'rejection_reason_category' => 'missing_required_field',
                    'missing_field' => $missingField,
                    'request_id' => $requestId,
                    'response_id' => $responseId,
                ]);

                continue;
            }

            $candidate = RequirementExtractionCandidateData::fromSegmentRow($row, $segment, (int) $index);

            if ($candidate->originalText === '' && $candidate->normalizedText === '') {
                Log::warning('[PROCYNIA][REQ_PIPELINE] Requirement candidate rejected before mapping.', [
                    'run_id' => $runId,
                    'document_id' => $document->id,
                    'saved_notice_ai_document_id' => $document->id,
                    'saved_notice_id' => $document->saved_notice_id,
                    'segment_id' => $segment->segmentId,
                    'segment_index' => $segment->segmentIndex,
                    'candidate_index' => $index,
                    'rejection_reason_category' => 'empty_requirement_text',
                    'missing_field' => 'original_text',
                    'request_id' => $requestId,
                    'response_id' => $responseId,
                ]);

                continue;
            }

            $candidates[] = $candidate;
        }

        return $candidates;
    }

    /**
     * Purpose: Deduplicate obvious duplicate candidates inside one segment extraction result.
     * Inputs: The extracted candidates for one segment.
     * Returns: A stable list of unique candidates.
     * Side effects: None.
     */
    private function segmentDedupeCandidates(array $candidates): array
    {
        $results = [];
        $seen = [];

        foreach ($candidates as $candidate) {
            if (! $candidate instanceof RequirementExtractionCandidateData) {
                continue;
            }

            $fingerprint = $this->segmentFingerprint($candidate);

            if (isset($seen[$fingerprint])) {
                continue;
            }

            $seen[$fingerprint] = true;
            $results[] = $candidate;
        }

        return $results;
    }

    /**
     * Purpose: Build a stable fingerprint for one extracted candidate.
     * Inputs: The canonical AI candidate.
     * Returns: A deterministic fingerprint string.
     * Side effects: None.
     */
    private function segmentFingerprint(RequirementExtractionCandidateData $candidate): string
    {
        return implode('|', [
            $candidate->sourceBlockId,
            mb_strtolower(trim((string) ($candidate->requirementIdentifier ?? '')), 'UTF-8'),
            mb_strtolower(trim($candidate->originalText), 'UTF-8'),
        ]);
    }

    /**
     * Purpose: Extract token usage fields from an OpenAI response payload.
     * Inputs: The raw HTTP response from the OpenAI API.
     * Returns: Normalised input, output, and total token counts or null when absent.
     * Side effects: None.
     */
    private function segmentTokenUsageFromResponse(Response $response): array
    {
        $decoded = $response->json();

        if (! is_array($decoded)) {
            return $this->segmentEmptyTokenUsage();
        }

        return [
            'input_tokens' => $this->segmentNormalizeTokenCount(data_get($decoded, 'usage.input_tokens')),
            'output_tokens' => $this->segmentNormalizeTokenCount(data_get($decoded, 'usage.output_tokens')),
            'total_tokens' => $this->segmentNormalizeTokenCount(data_get($decoded, 'usage.total_tokens')),
        ];
    }

    /**
     * Purpose: Return the absence of token usage for error paths.
     * Inputs: None.
     * Returns: A token usage structure with null counts.
     * Side effects: None.
     */
    private function segmentEmptyTokenUsage(): array
    {
        return [
            'input_tokens' => null,
            'output_tokens' => null,
            'total_tokens' => null,
        ];
    }

    /**
     * Purpose: Convert a raw usage count into a normalised integer or null.
     * Inputs: A token count from the OpenAI payload.
     * Returns: A bounded integer value or null when unusable.
     * Side effects: None.
     */
    private function segmentNormalizeTokenCount(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (is_float($value) || is_string($value)) {
            $numeric = (int) $value;

            return $numeric >= 0 ? $numeric : null;
        }

        return null;
    }

    /**
     * Purpose: Extract the request id from a response payload.
     * Inputs: The raw HTTP response from OpenAI.
     * Returns: The request id or null when absent.
     * Side effects: None.
     */
    private function segmentRequestIdFrom(Response $response): ?string
    {
        foreach (['x-request-id', 'x-openai-request-id', 'openai-request-id'] as $header) {
            $requestId = trim((string) $response->header($header));

            if ($requestId !== '') {
                return $requestId;
            }
        }

        return null;
    }

    /**
     * Purpose: Extract the response id from a response payload.
     * Inputs: The raw HTTP response from OpenAI.
     * Returns: The response id or null when absent.
     * Side effects: None.
     */
    private function segmentResponseIdFrom(Response $response): ?string
    {
        $responseId = trim((string) data_get($response->json(), 'id', ''));

        return $responseId !== '' ? $responseId : null;
    }

    /**
     * Purpose: Convert an HTTP failure status into a stable error type label.
     * Inputs: The upstream HTTP status code.
     * Returns: A short machine-readable error type.
     * Side effects: None.
     */
    private function segmentErrorTypeForStatus(int $status): string
    {
        return match (true) {
            $status === 408 => 'timeout',
            $status === 429 => 'rate_limited',
            $status >= 500 => 'upstream_error',
            $status >= 400 => 'request_error',
            default => 'http_error',
        };
    }

    /**
     * Purpose: Derive the elapsed time in milliseconds from the given start time.
     * Inputs: The floating-point timestamp captured before the call started.
     * Returns: The elapsed time rounded to the nearest millisecond.
     * Side effects: None.
     */
    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    /**
     * Purpose: Extract the exact user prompt text from a payload for logging calculations.
     * Inputs: The OpenAI request payload.
     * Returns: The first user text block when present, otherwise an empty string.
     * Side effects: None.
     */
    private function segmentInputText(array $payload): string
    {
        return (string) data_get($payload, 'input.1.content.0.text', data_get($payload, 'input.0.content.0.text', ''));
    }

    /**
     * Purpose: Parse raw assistant output into canonical extraction rows.
     * Inputs: The raw assistant text.
     * Returns: A strategy label plus normalized candidate rows.
     * Side effects: None.
     */
    private function parseOutput(string $rawText): array
    {
        $text = $this->stripCodeFences(trim($rawText));

        if ($text === '') {
            return [
                'strategy' => 'empty',
                'row_count' => 0,
                'rows' => [],
            ];
        }

        $jsonRows = $this->parseJsonRows($text);

        if ($jsonRows !== null) {
            return [
                'strategy' => 'json',
                'row_count' => count($jsonRows),
                'rows' => $jsonRows,
            ];
        }

        $tableRows = $this->parseDelimitedTableRows($text, '|');

        if ($tableRows !== []) {
            return [
                'strategy' => 'markdown_table',
                'row_count' => count($tableRows),
                'rows' => $tableRows,
            ];
        }

        $tabRows = $this->parseDelimitedTableRows($text, "\t");

        if ($tabRows !== []) {
            return [
                'strategy' => 'tab_delimited',
                'row_count' => count($tabRows),
                'rows' => $tabRows,
            ];
        }

        $spaceRows = $this->parseSpaceDelimitedTableRows($text);

        return [
            'strategy' => $spaceRows !== [] ? 'space_delimited' : 'unparsed',
            'row_count' => count($spaceRows),
            'rows' => $spaceRows,
        ];
    }

    /**
     * Purpose: Parse the strict Phase 1 JSON schema output.
     * Inputs: The raw assistant text.
     * Returns: A strict JSON-schema result with normalized candidate rows.
     * Side effects: Throws on empty output, invalid JSON, or schema mismatches.
     */
    private function parsePhaseOneOutput(string $rawText): array
    {
        $text = $this->stripCodeFences(trim($rawText));

        if ($text === '') {
            throw new RuntimeException('OpenAI phase 1 extraction response did not include any text output.');
        }

        $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException('OpenAI phase 1 extraction response did not decode to a JSON object.');
        }

        $rows = data_get($decoded, 'candidates');

        if (! is_array($rows)) {
            throw new RuntimeException('OpenAI phase 1 extraction response did not include a candidates array.');
        }

        $normalizedRows = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                throw new RuntimeException(sprintf('OpenAI phase 1 extraction response candidate [%d] was not an object.', $index));
            }

            $this->validatePhaseOneCandidateRow($row, (int) $index);

            $normalizedRow = $this->normalizeCanonicalRow($this->normalizeParsedRowKeys($row));

            if ($normalizedRow === null) {
                throw new RuntimeException(sprintf('OpenAI phase 1 extraction response candidate [%d] did not contain usable requirement text.', $index));
            }

            $normalizedRows[] = $normalizedRow;
        }

        return [
            'strategy' => 'json_schema',
            'row_count' => count($normalizedRows),
            'rows' => $normalizedRows,
        ];
    }

    /**
     * Purpose: Verify that one Phase 1 candidate row matches the lightweight schema.
     * Inputs: The decoded candidate row and its index.
     * Returns: None.
     * Side effects: Throws on schema mismatches.
     */
    private function validatePhaseOneCandidateRow(array $row, int $index): void
    {
        $requiredKeys = [
            'original_text',
            'is_requirement',
            'confidence',
        ];

        foreach ($requiredKeys as $requiredKey) {
            if (! array_key_exists($requiredKey, $row)) {
                throw new RuntimeException(sprintf('OpenAI phase 1 extraction response candidate [%d] was missing %s.', $index, $requiredKey));
            }
        }

        if (! is_string($row['original_text']) || trim($row['original_text']) === '') {
            throw new RuntimeException(sprintf('OpenAI phase 1 extraction response candidate [%d] had an invalid original_text field.', $index));
        }

        if (! is_bool($row['is_requirement'])) {
            throw new RuntimeException(sprintf('OpenAI phase 1 extraction response candidate [%d] had a non-boolean is_requirement field.', $index));
        }

        if (! is_int($row['confidence']) && ! is_float($row['confidence'])) {
            throw new RuntimeException(sprintf('OpenAI phase 1 extraction response candidate [%d] had an invalid confidence field.', $index));
        }

        $unexpectedKeys = array_values(array_diff(array_keys($row), [
            'requirement_identifier',
            'parent_reference',
            'original_text',
            'source_reference_text',
            'is_requirement',
            'confidence',
        ]));

        if ($unexpectedKeys !== []) {
            throw new RuntimeException(sprintf(
                'OpenAI phase 1 extraction response candidate [%d] contained unexpected fields: %s.',
                $index,
                implode(', ', $unexpectedKeys),
            ));
        }

        foreach (['requirement_identifier', 'parent_reference', 'source_reference_text'] as $optionalKey) {
            if (array_key_exists($optionalKey, $row) && ! is_string($row[$optionalKey]) && $row[$optionalKey] !== null) {
                throw new RuntimeException(sprintf('OpenAI phase 1 extraction response candidate [%d] had an invalid %s field.', $index, $optionalKey));
            }
        }
    }

    /**
     * Purpose: Parse a JSON response if the model returned structured objects instead of a table.
     * Inputs: The cleaned assistant text.
     * Returns: Normalized rows or null when the payload is not usable JSON.
     * Side effects: None.
     */
    private function parseJsonRows(string $text): ?array
    {
        try {
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        $rows = data_get($decoded, 'candidates');

        if (is_array($rows)) {
            return $this->normalizeRowList($rows);
        }

        if (array_is_list($decoded)) {
            return $this->normalizeRowList($decoded);
        }

        $rows = data_get($decoded, 'rows');

        if (is_array($rows)) {
            return $this->normalizeRowList($rows);
        }

        $rows = data_get($decoded, 'requirements');

        if (is_array($rows)) {
            return $this->normalizeRowList($rows);
        }

        return null;
    }

    /**
     * Purpose: Parse a pipe- or tab-delimited table into normalized candidate rows.
     * Inputs: The cleaned assistant text and the table delimiter to use.
     * Returns: Normalized candidate rows.
     * Side effects: None.
     */
    private function parseDelimitedTableRows(string $text, string $delimiter): array
    {
        $lines = preg_split('/\R/u', $text) ?: [];
        $headerMap = null;
        $rows = [];
        $separatorPattern = $delimiter === '|'
            ? '/^\s*\|?\s*[:\-\s\|]+\s*\|?\s*$/u'
            : '/^\s*[-:\s\t]+\s*$/u';

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || preg_match($separatorPattern, $line) === 1) {
                continue;
            }

            $cells = $this->splitDelimitedLine($line, $delimiter);

            if ($cells === []) {
                continue;
            }

            if ($headerMap === null) {
                $headerMap = $this->buildHeaderMap($cells);

                if ($headerMap === null) {
                    continue;
                }

                continue;
            }

            $row = $this->mapCellsToCanonicalRow($headerMap, $cells);

            if ($row === null) {
                continue;
            }

            $rows[] = $row;
        }

        if ($rows === []) {
            $rows = $this->parseFixedColumnTableRows($text);
        }

        return $rows;
    }

    /**
     * Purpose: Parse a plain-text table where columns are separated by repeated whitespace.
     * Inputs: The cleaned assistant text.
     * Returns: Normalized candidate rows.
     * Side effects: None.
     */
    private function parseSpaceDelimitedTableRows(string $text): array
    {
        $lines = preg_split('/\R/u', $text) ?: [];
        $headerMap = null;
        $rows = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || preg_match('/^\s*[-:\s]+$/u', $line) === 1) {
                continue;
            }

            if (! preg_match('/\s{2,}/u', $line)) {
                continue;
            }

            $cells = preg_split('/\s{2,}/u', $line) ?: [];
            $cells = array_values(array_filter(array_map('trim', $cells), static fn (string $value): bool => $value !== ''));

            if ($cells === []) {
                continue;
            }

            if ($headerMap === null) {
                $headerMap = $this->buildHeaderMap($cells);

                if ($headerMap === null) {
                    continue;
                }

                continue;
            }

            $row = $this->mapCellsToCanonicalRow($headerMap, $cells);

            if ($row === null) {
                continue;
            }

            $rows[] = $row;
        }

        if ($rows === []) {
            $rows = $this->parseFixedColumnTableRows($text);
        }

        return $rows;
    }

    /**
     * Purpose: Try a positional fallback for a fixed fifteen-column table.
     * Inputs: The cleaned assistant text.
     * Returns: Normalized candidate rows.
     * Side effects: None.
     */
    private function parseFixedColumnTableRows(string $text): array
    {
        $lines = preg_split('/\R/u', $text) ?: [];
        $rows = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || ! preg_match('/[\|\t]/u', $line)) {
                continue;
            }

            $cells = $this->splitDelimitedLine($line, str_contains($line, '|') ? '|' : "\t");

            if (count($cells) < 15) {
                continue;
            }

            $row = $this->mapFixedColumnRow(array_slice($cells, 0, 15));

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Purpose: Build a header map from one candidate table header row.
     * Inputs: The header cells.
     * Returns: A map of column index to canonical key or null when the row is not recognisable.
     * Side effects: None.
     */
    private function buildHeaderMap(array $cells): ?array
    {
        $headerMap = [];
        $recognizedCount = 0;

        foreach ($cells as $index => $cell) {
            $canonicalKey = $this->canonicalHeaderKey((string) $cell);

            if ($canonicalKey !== null) {
                $recognizedCount++;
            }

            $headerMap[$index] = $canonicalKey;
        }

        return $recognizedCount >= 3 ? $headerMap : null;
    }

    /**
     * Purpose: Map parsed cells into a normalized canonical row structure.
     * Inputs: The header map and row cells.
     * Returns: The normalized row or null when the row is empty.
     * Side effects: None.
     */
    private function mapCellsToCanonicalRow(array $headerMap, array $cells): ?array
    {
        $row = [];

        foreach ($headerMap as $index => $key) {
            if ($key === null) {
                continue;
            }

            $row[$key] = $cells[$index] ?? null;
        }

        return $this->normalizeCanonicalRow($row);
    }

    /**
     * Purpose: Map a fixed column list into the canonical extraction structure.
     * Inputs: A row of fifteen cells in the configured output order.
     * Returns: The normalized row or null when no requirement text is present.
     * Side effects: None.
     */
    private function mapFixedColumnRow(array $cells): ?array
    {
        $row = [
            'requirement_identifier' => $cells[0] ?? null,
            'parent_reference' => $cells[1] ?? null,
            'requirement_type' => $cells[2] ?? null,
            'obligation_type' => $cells[3] ?? null,
            'original_text' => $cells[4] ?? null,
            'normalized_text' => $cells[5] ?? null,
            'comment' => $cells[6] ?? null,
            'evaluation_notes' => $cells[7] ?? null,
            'response_expectation' => $cells[8] ?? null,
            'expected_evidence' => $cells[9] ?? null,
            'keywords' => $cells[10] ?? null,
            'domain' => $cells[11] ?? null,
            'related_references' => $cells[12] ?? null,
            'source_reference_text' => $cells[13] ?? null,
            'interpretation_risk' => $cells[14] ?? null,
        ];

        return $this->normalizeCanonicalRow($row);
    }

    /**
     * Purpose: Normalize a row from either JSON or table output into canonical keys.
     * Inputs: A raw parsed row.
     * Returns: A cleaned row or null when the row is not usable.
     * Side effects: None.
     */
    private function normalizeCanonicalRow(array $row): ?array
    {
        $originalText = $this->normalizeScalar($row['original_text'] ?? null);
        $normalizedText = $this->normalizeScalar($row['normalized_text'] ?? null);
        $sourceReferenceText = $this->normalizeScalar($row['source_reference_text'] ?? null);

        if ($originalText === '' && $normalizedText === '') {
            return null;
        }

        if ($this->looksLikeHeaderRow($row, $originalText, $normalizedText)) {
            return null;
        }

        $canonical = [
            'requirement_identifier' => $this->normalizeScalar($row['requirement_identifier'] ?? null) ?: null,
            'parent_reference' => $this->normalizeScalar($row['parent_reference'] ?? null) ?: null,
            'requirement_type' => $this->normalizeScalar($row['requirement_type'] ?? null) ?: null,
            'obligation_type' => $this->normalizeScalar($row['obligation_type'] ?? null) ?: null,
            'original_text' => $originalText !== '' ? $originalText : $normalizedText,
            'normalized_text' => $normalizedText !== '' ? $normalizedText : $originalText,
            'comment' => $this->normalizeScalar($row['comment'] ?? null) ?: null,
            'evaluation_notes' => $this->normalizeScalar($row['evaluation_notes'] ?? null) ?: null,
            'response_expectation' => $this->normalizeScalar($row['response_expectation'] ?? null) ?: null,
            'expected_evidence' => $this->normalizeListField($row['expected_evidence'] ?? null),
            'keywords' => $this->normalizeListField($row['keywords'] ?? null),
            'domain' => $this->normalizeListField($row['domain'] ?? null),
            'related_references' => $this->normalizeListField($row['related_references'] ?? null),
            'source_reference_text' => $sourceReferenceText !== '' ? $sourceReferenceText : null,
            'interpretation_risk' => $this->normalizeScalar($row['interpretation_risk'] ?? null) ?: null,
            'is_requirement' => $this->normalizeBoolean($row['is_requirement'] ?? true),
            'confidence' => $this->normalizeConfidence($row['confidence'] ?? 1.0),
            'warnings' => $this->normalizeListField($row['warnings'] ?? null),
        ];

        return $canonical;
    }

    private function looksLikeHeaderRow(array $row, string $originalText, string $normalizedText): bool
    {
        $requirementIdentifier = mb_strtolower($this->normalizeScalar($row['requirement_identifier'] ?? null), 'UTF-8');
        $originalText = mb_strtolower($originalText, 'UTF-8');
        $normalizedText = mb_strtolower($normalizedText, 'UTF-8');

        return $requirementIdentifier === 'krav id'
            || str_contains($originalText, 'kravtekst original')
            || str_contains($normalizedText, 'kravtekst normalisert')
            || str_contains($requirementIdentifier, 'krav id');
    }

    private function canonicalHeaderKey(string $label): ?string
    {
        $normalized = Str::ascii(mb_strtolower(trim($label), 'UTF-8'));
        $normalized = preg_replace('/[^a-z0-9]+/u', ' ', $normalized) ?? $normalized;
        $normalized = trim(preg_replace('/\s+/u', ' ', $normalized) ?? $normalized);

        return match ($normalized) {
            'krav id' => 'requirement_identifier',
            'foreldre id kapittel tema' => 'parent_reference',
            'kravtype' => 'requirement_type',
            'obligatorisk eller evalueringsdrivende' => 'obligation_type',
            'kravtekst original' => 'original_text',
            'kravtekst normalisert for semantisk sok' => 'normalized_text',
            'viktig merknad kommentar' => 'comment',
            'evalueringsmomenter' => 'evaluation_notes',
            'hva ma besvares' => 'response_expectation',
            'forventet dokumentasjon bevis' => 'expected_evidence',
            'nokkelord' => 'keywords',
            'fagomrade' => 'domain',
            'relaterte krav avhengigheter' => 'related_references',
            'kildehenvisning i dokumentet' => 'source_reference_text',
            'tolkingsrisiko uklarhet' => 'interpretation_risk',
            default => null,
        };
    }

    private function splitDelimitedLine(string $line, string $delimiter): array
    {
        if ($delimiter === '|') {
            $line = trim($line);
            $line = trim($line, '|');
            $cells = preg_split('/(?<!\\\\)\|/u', $line) ?: [];
        } elseif ($delimiter === "\t") {
            $cells = explode("\t", $line);
        } else {
            $cells = preg_split('/\s{2,}/u', $line) ?: [];
        }

        $cells = array_map(function (mixed $value): string {
            $text = trim((string) $value);
            $text = str_replace('\|', '|', $text);

            return $text;
        }, $cells);

        if ($delimiter === '|' && $cells !== []) {
            while ($cells !== [] && $cells[0] === '') {
                array_shift($cells);
            }

            while ($cells !== [] && $cells[array_key_last($cells)] === '') {
                array_pop($cells);
            }
        }

        return array_values($cells);
    }

    private function normalizeRowList(array $rows): array
    {
        $normalized = [];

        foreach ($rows as $rowIndex => $row) {
            if (! is_array($row)) {
                continue;
            }

            $normalizedRow = $this->normalizeCanonicalRow($this->normalizeParsedRowKeys($row));

            if ($normalizedRow !== null) {
                $normalized[] = $normalizedRow;
            }
        }

        return $normalized;
    }

    private function normalizeParsedRowKeys(array $row): array
    {
        $normalized = [];

        foreach ($row as $key => $value) {
            $canonicalKey = $this->canonicalHeaderKey((string) $key) ?? $this->normalizeFallbackKey((string) $key);
            $normalized[$canonicalKey] = $value;
        }

        return $normalized;
    }

    private function normalizeFallbackKey(string $label): string
    {
        $normalized = Str::ascii(mb_strtolower(trim($label), 'UTF-8'));
        $normalized = preg_replace('/[^a-z0-9]+/u', '_', $normalized) ?? $normalized;
        $normalized = trim(preg_replace('/_+/u', '_', $normalized) ?? $normalized, '_');

        return $normalized === '' ? 'unknown' : $normalized;
    }

    private function normalizeScalar(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_array($value)) {
            $value = implode(', ', array_map(static fn (mixed $item): string => trim((string) $item), $value));
        }

        $text = trim((string) $value);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private function normalizeListField(mixed $value): array
    {
        if (is_array($value)) {
            $items = $value;
        } else {
            $text = $this->normalizeScalar($value);

            if ($text === '') {
                return [];
            }

            $items = preg_split('/\s*(?:,|;|•|\n)\s*/u', $text) ?: [];
        }

        $normalized = [];

        foreach ($items as $item) {
            $normalizedItem = $this->normalizeScalar($item);

            if ($normalizedItem !== '') {
                $normalized[] = $normalizedItem;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function normalizeBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $text = mb_strtolower($this->normalizeScalar($value), 'UTF-8');

        return in_array($text, ['1', 'true', 'yes', 'ja', 'sant'], true);
    }

    private function normalizeConfidence(mixed $value): float
    {
        if (is_int($value) || is_float($value) || is_string($value)) {
            $numeric = (float) $value;

            if (is_finite($numeric)) {
                return max(0.0, min(1.0, $numeric));
            }
        }

        return 1.0;
    }

    private function dedupeCandidates(array $candidates): array
    {
        $results = [];
        $seen = [];

        foreach ($candidates as $candidate) {
            if (! $candidate instanceof RequirementExtractionCandidateData) {
                continue;
            }

            $fingerprint = $this->fingerprint($candidate);

            if (isset($seen[$fingerprint])) {
                continue;
            }

            $seen[$fingerprint] = true;
            $results[] = $candidate;
        }

        return $results;
    }

    private function fingerprint(RequirementExtractionCandidateData $candidate): string
    {
        return implode('|', [
            $candidate->sourceBlockId,
            mb_strtolower(trim((string) ($candidate->requirementIdentifier ?? '')), 'UTF-8'),
            mb_strtolower(trim($candidate->originalText), 'UTF-8'),
        ]);
    }

    private function mapCandidates(SavedNoticeAiDocument $document, array $rows, ?RequirementExtractionBlockData $block = null): array
    {
        $candidates = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $candidate = $block instanceof RequirementExtractionBlockData
                ? RequirementExtractionCandidateData::fromArray($row, $block)
                : RequirementExtractionCandidateData::fromPromptRow($row, $document, (int) $index);

            if ($candidate->originalText === '' && $candidate->normalizedText === '') {
                continue;
            }

            $candidates[] = $candidate;
        }

        return $candidates;
    }

    private function fallbackResult(
        SavedNoticeAiDocument $document,
        string $documentText,
        string $model,
        string $runId,
        string $errorType,
        string $errorMessage,
        ?int $upstreamStatus = null,
        ?string $requestId = null,
        ?string $responseId = null,
        ?string $rawResponseBody = null,
        array $tokenUsage = [],
    ): RequirementExtractionResultData {
        Log::warning('[PROC][AI][REQ] OpenAI requirement extraction failed, falling back to the legacy extractor.', [
            'run_id' => $runId,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_id' => $document->saved_notice_id,
            'model' => $model,
            'upstream_status' => $upstreamStatus,
            'request_id' => $requestId,
            'response_id' => $responseId,
            'error_type' => $errorType,
            'error_message' => $errorMessage,
            'raw_response' => $rawResponseBody,
            'openai_call_count' => 1,
            'input_tokens' => $tokenUsage['input_tokens'] ?? null,
            'output_tokens' => $tokenUsage['output_tokens'] ?? null,
            'total_tokens' => $tokenUsage['total_tokens'] ?? null,
        ]);

        $candidates = $this->legacyCandidates($document, $documentText);

        return new RequirementExtractionResultData(
            ok: true,
            savedNoticeId: (int) $document->saved_notice_id,
            savedNoticeAiDocumentId: $document->id,
            model: $model,
            requestId: $requestId,
            responseId: $responseId,
            fallbackUsed: true,
            errorType: $errorType,
            errorMessage: $errorMessage,
            upstreamStatus: $upstreamStatus,
            blocks: [],
            candidates: $candidates,
            metadata: [
                'prompt_version' => FullDocumentRequirementExtractionPrompt::promptVersion(),
                'candidate_count' => count($candidates),
                'row_count' => count($candidates),
                'document_text_length' => mb_strlen($documentText, 'UTF-8'),
                'input_text_length' => mb_strlen(FullDocumentRequirementExtractionPrompt::inputTextForDocument($documentText), 'UTF-8'),
                'parse_strategy' => 'legacy_fallback',
                'fallback_used' => true,
                'fallback_error_type' => $errorType,
                'raw_output' => $rawResponseBody,
                'openai_call_count' => 1,
                'input_tokens' => $tokenUsage['input_tokens'] ?? null,
                'output_tokens' => $tokenUsage['output_tokens'] ?? null,
                'total_tokens' => $tokenUsage['total_tokens'] ?? null,
            ],
        );
    }

    /**
     * Purpose: Build a consistent log context for requirement extraction cost tracing.
     * Inputs: The AI document, the extraction run id, and any additional context fields.
     * Returns: A structured log context with stable document and notice identifiers.
     * Side effects: None.
     */
    private function aiCostContext(SavedNoticeAiDocument $document, string $runId, array $extra = []): array
    {
        return array_merge([
            'run_id' => $runId,
            'document_id' => $document->id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_id' => $document->saved_notice_id,
        ], $extra);
    }

    /**
     * Purpose: Extract token usage fields from an OpenAI response payload.
     * Inputs: The raw HTTP response from the OpenAI API.
     * Returns: Normalised input, output, and total token counts or null when absent.
     * Side effects: None.
     */
    private function tokenUsageFromResponse(Response $response): array
    {
        $decoded = $response->json();

        if (! is_array($decoded)) {
            return $this->emptyTokenUsage();
        }

        return [
            'input_tokens' => $this->normalizeTokenCount(data_get($decoded, 'usage.input_tokens')),
            'output_tokens' => $this->normalizeTokenCount(data_get($decoded, 'usage.output_tokens')),
            'total_tokens' => $this->normalizeTokenCount(data_get($decoded, 'usage.total_tokens')),
        ];
    }

    /**
     * Purpose: Return the absence of token usage for error paths and empty inputs.
     * Inputs: None.
     * Returns: A token usage structure with null counts.
     * Side effects: None.
     */
    private function emptyTokenUsage(): array
    {
        return [
            'input_tokens' => null,
            'output_tokens' => null,
            'total_tokens' => null,
        ];
    }

    /**
     * Purpose: Normalise a token count from an OpenAI response payload.
     * Inputs: A raw token count value from the response body.
     * Returns: A nullable integer token count.
     * Side effects: None.
     */
    private function normalizeTokenCount(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    private function legacyCandidates(SavedNoticeAiDocument $document, string $documentText): array
    {
        $candidates = [];
        $legacyRows = $this->legacyRequirementExtractor->extractFromChunk($documentText);

        foreach ($legacyRows as $index => $legacyCandidate) {
            if (! is_array($legacyCandidate)) {
                continue;
            }

            $candidates[] = RequirementExtractionCandidateData::fromLegacyArray($legacyCandidate, $document, (int) $index);
        }

        return $this->dedupeCandidates($candidates);
    }

    /**
     * Purpose: Build a bounded preview of long assistant output for logs.
     * Inputs: Raw text and an optional length cap.
     * Returns: A compact single-line preview string.
     * Side effects: None.
     */
    private function previewText(string $text, int $limit = 1500): string
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        if ($normalized === '') {
            return '';
        }

        return Str::limit($normalized, $limit, '...');
    }

    /**
     * Purpose: Build a consistent failure result payload for the full-document path.
     * Inputs: The document, run id, transport result, failure classification, and extra metadata.
     * Returns: A terminal extraction result with failure metadata populated.
     * Side effects: None.
     */
    private function buildFullDocumentFailureResult(
        SavedNoticeAiDocument $document,
        string $runId,
        array $requestResult,
        string $failureStage,
        string $failureType,
        string $errorMessage,
        array $extraMetadata = [],
        bool $partial = true,
    ): RequirementExtractionResultData {
        $documentText = trim((string) $document->extracted_text);
        $rawOutput = (string) data_get($requestResult, 'raw_output', '');
        $rawOutputLength = (int) data_get($requestResult, 'raw_output_length', mb_strlen($rawOutput, 'UTF-8'));
        $rawOutputPreview = (string) data_get($requestResult, 'raw_output_preview', $this->previewText($rawOutput));
        $rawResponseBody = (string) data_get($requestResult, 'raw_response_body', '');
        $rawResponseBodyLength = (int) data_get($requestResult, 'raw_response_body_length', mb_strlen($rawResponseBody, 'UTF-8'));
        $documentTextLength = (int) data_get($requestResult, 'document_text_length', mb_strlen($documentText, 'UTF-8'));
        $promptTextLength = data_get($requestResult, 'prompt_text_length');
        $inputTextLength = data_get($requestResult, 'input_text_length');
        $inputTokens = data_get($requestResult, 'input_tokens');
        $outputTokens = data_get($requestResult, 'output_tokens');
        $totalTokens = data_get($requestResult, 'total_tokens');
        $elapsedMs = data_get($requestResult, 'elapsed_ms');
        $requestId = data_get($requestResult, 'request_id');
        $responseId = data_get($requestResult, 'response_id');
        $status = data_get($requestResult, 'status');
        $openAiCallCount = (int) data_get($requestResult, 'openai_call_count', 1);
        $parseStrategy = data_get($requestResult, 'parse_strategy', 'failed');
        $upstreamErrorMessage = data_get($requestResult, 'upstream_error_message');
        $upstreamErrorType = data_get($requestResult, 'upstream_error_type');
        $upstreamErrorCode = data_get($requestResult, 'upstream_error_code');
        $upstreamErrorParam = data_get($requestResult, 'upstream_error_param');

        $metadata = array_merge([
            'prompt_versions' => [
                'relevance' => $this->relevancePromptVersion(),
                'extraction' => FullDocumentRequirementExtractionPrompt::promptVersion(),
            ],
            'phase_1_requirement_extraction' => true,
            'document_text_length' => $documentTextLength,
            'prompt_text_length' => is_numeric($promptTextLength) ? (int) $promptTextLength : null,
            'input_text_length' => is_numeric($inputTextLength) ? (int) $inputTextLength : null,
            'raw_candidate_count' => 0,
            'mapped_candidate_count' => 0,
            'deduped_candidate_count' => 0,
            'relevant_segment_count' => 0,
            'relevance_call_count' => 0,
            'extraction_call_count' => 1,
            'openai_call_count' => $openAiCallCount,
            'extraction_input_tokens' => is_numeric($inputTokens) ? (int) $inputTokens : null,
            'extraction_output_tokens' => is_numeric($outputTokens) ? (int) $outputTokens : null,
            'extraction_total_tokens' => is_numeric($totalTokens) ? (int) $totalTokens : null,
            'input_tokens_total' => is_numeric($inputTokens) ? (int) $inputTokens : 0,
            'output_tokens_total' => is_numeric($outputTokens) ? (int) $outputTokens : 0,
            'total_tokens_total' => is_numeric($totalTokens) ? (int) $totalTokens : 0,
            'candidate_count' => 0,
            'failure_count' => 1,
            'partial' => $partial,
            'fallback_used' => false,
            'elapsed_ms' => is_numeric($elapsedMs) ? (int) $elapsedMs : null,
            'request_id' => is_string($requestId) ? $requestId : null,
            'response_id' => is_string($responseId) ? $responseId : null,
            'status' => is_int($status) ? $status : null,
            'raw_output_length' => $rawOutputLength,
            'raw_output' => $rawOutput,
            'raw_output_preview' => $rawOutputPreview,
            'raw_response_body' => $rawResponseBody,
            'raw_response_body_length' => $rawResponseBodyLength,
            'upstream_error_message' => is_string($upstreamErrorMessage) ? $upstreamErrorMessage : null,
            'upstream_error_type' => is_string($upstreamErrorType) ? $upstreamErrorType : null,
            'upstream_error_code' => is_scalar($upstreamErrorCode) ? trim((string) $upstreamErrorCode) : null,
            'upstream_error_param' => is_scalar($upstreamErrorParam) ? trim((string) $upstreamErrorParam) : null,
            'parse_strategy' => $parseStrategy,
            'failure_stage' => $failureStage,
            'failure_type' => $failureType,
        ], $extraMetadata);

        return new RequirementExtractionResultData(
            ok: false,
            partial: $partial,
            savedNoticeId: (int) $document->saved_notice_id,
            savedNoticeAiDocumentId: $document->id,
            runId: $runId,
            documentTitle: (string) $document->original_filename,
            documentFilename: (string) $document->original_filename,
            model: (string) data_get($requestResult, 'model', FullDocumentRequirementExtractionPrompt::model()),
            relevanceModel: $this->relevanceModel(),
            extractionModel: (string) data_get($requestResult, 'model', FullDocumentRequirementExtractionPrompt::model()),
            segmentCount: 0,
            relevantSegmentCount: 0,
            relevanceCallCount: 0,
            extractionCallCount: 1,
            openAiCallCount: $openAiCallCount,
            segments: [],
            relevanceResults: [],
            extractionResults: [],
            candidates: [],
            metadata: $metadata,
            errorType: $failureType,
            errorMessage: $errorMessage,
            failureStage: $failureStage,
            failureType: $failureType,
        );
    }

    private function errorTypeForStatus(int $status): string
    {
        return match (true) {
            $status === 408 => 'timeout',
            $status === 429 => 'upstream_error',
            $status >= 500 => 'upstream_error',
            $status >= 400 => 'invalid_request',
            default => 'unexpected_response',
        };
    }

    /**
     * Purpose: Extract upstream OpenAI error fields from a raw error response body.
     * Inputs: The raw HTTP response body returned by OpenAI.
     * Returns: A normalised error detail map suitable for logs and failure summaries.
     * Side effects: None.
     */
    private function upstreamErrorDetailsFromRawBody(string $rawResponseBody): array
    {
        if (trim($rawResponseBody) === '') {
            return [
                'message' => null,
                'type' => null,
                'code' => null,
                'param' => null,
            ];
        }

        $decoded = json_decode($rawResponseBody, true);

        if (! is_array($decoded)) {
            return [
                'message' => null,
                'type' => null,
                'code' => null,
                'param' => null,
            ];
        }

        $error = data_get($decoded, 'error');

        if (! is_array($error)) {
            return [
                'message' => null,
                'type' => null,
                'code' => null,
                'param' => null,
            ];
        }

        return [
            'message' => is_string(data_get($error, 'message')) ? trim((string) data_get($error, 'message')) : null,
            'type' => is_string(data_get($error, 'type')) ? trim((string) data_get($error, 'type')) : null,
            'code' => is_scalar(data_get($error, 'code')) ? trim((string) data_get($error, 'code')) : null,
            'param' => is_scalar(data_get($error, 'param')) ? trim((string) data_get($error, 'param')) : null,
        ];
    }

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
                    throw new RuntimeException('OpenAI refused to return requirement extraction candidates.');
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

    private function stripCodeFences(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/', '', $text) ?? $text;

        return trim($text);
    }

    private function requestIdFrom(Response $response): ?string
    {
        foreach (['x-request-id', 'x-openai-request-id', 'openai-request-id'] as $header) {
            $requestId = trim((string) $response->header($header));

            if ($requestId !== '') {
                return $requestId;
            }
        }

        return null;
    }

    private function responseIdFrom(Response $response): ?string
    {
        $decoded = $response->json();

        if (! is_array($decoded)) {
            return null;
        }

        $responseId = trim((string) data_get($decoded, 'id', ''));

        return $responseId === '' ? null : $responseId;
    }
}
