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

    private const PHASE_ONE_WINDOW_TRIGGER_CHARS = 8000;
    private const PHASE_ONE_WINDOW_TARGET_CHARS = 5500;
    private const PHASE_ONE_WINDOW_MIN_CHARS = 3500;
    private const PHASE_ONE_WINDOW_OVERLAP_CHARS = 1200;
    private const PHASE_ONE_WINDOW_BOUNDARY_SCAN_CHARS = 500;

    public function __construct(
        private readonly OpenAiClient $openAiClient,
        private readonly RequirementExtractor $legacyRequirementExtractor,
        private readonly RequirementSegmentExtractionPromptBuilder $promptBuilder,
        private readonly RequirementExtractionPromptBuilder $blockPromptBuilder,
    ) {
    }

    public function extract(SavedNoticeAiDocument $document, DocumentRequirementSegmentData $segment, ?string $runId = null): RequirementSegmentExtractionResultData
    {
        $runId ??= (string) Str::uuid();
        $startedAt = microtime(true);
        $payload = $this->segmentRequestPayload($document, $segment);
        $model = (string) ($payload['model'] ?? '');

        try {
            $response = $this->openAiClient->post('responses', $payload, 180);
        } catch (ConnectionException $exception) {
            return $this->failedSegmentResult(
                document: $document,
                segment: $segment,
                runId: $runId,
                model: $model,
                elapsedMs: $this->elapsedMs($startedAt),
                errorType: str_contains(mb_strtolower($exception->getMessage(), 'UTF-8'), 'timed out') ? 'timeout' : 'connection_error',
                errorMessage: $exception->getMessage(),
            );
        } catch (RuntimeException $exception) {
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

        if (! $response->successful()) {
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
            $candidates = $this->segmentMapCandidates($document, $segment, $runId, $requestId, $responseId, $rawCandidates);
            $mappedCandidateCount = count($candidates);
        } catch (Throwable $exception) {
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

    public function extractFullDocumentRaw(SavedNoticeAiDocument $document, ?string $runId = null): array
    {
        $runId ??= (string) Str::uuid();

        return $this->performFullDocumentExtractionRequest($document, $runId);
    }

    public function extractFullDocument(SavedNoticeAiDocument $document, ?string $runId = null): RequirementExtractionResultData
    {
        $runId ??= (string) Str::uuid();
        $startedAt = microtime(true);
        $documentTitle = (string) $document->original_filename;
        $documentFilename = $documentTitle;
        $documentText = trim((string) $document->extracted_text);
        $windows = $this->buildPhaseOneExtractionWindows($documentText);

        $windowCount = count($windows);
        $requestResults = [];
        $windowSummaries = [];
        $windowFailures = [];
        $rawCandidateCount = 0;
        $filteredCandidateCount = 0;
        $mappedCandidates = [];
        $successfulRequestCount = 0;
        $parsedWindowCount = 0;
        $openAiCallCount = 0;
        $promptVersion = FullDocumentRequirementExtractionPrompt::promptVersion();
        $model = FullDocumentRequirementExtractionPrompt::model();

        foreach ($windows as $windowIndex => $window) {
            $windowRunId = $windowCount > 1
                ? sprintf('%s-window-%d', $runId, $windowIndex + 1)
                : $runId;

            $windowMeta = [
                'window_index' => (int) ($window['window_index'] ?? $windowIndex),
                'window_count' => $windowCount,
                'window_start_position' => (int) ($window['start_position'] ?? 0),
                'window_end_position' => (int) ($window['end_position'] ?? 0),
                'windowed_extraction' => $windowCount > 1,
            ];

            $requestResult = $this->performFullDocumentExtractionRequestForText(
                document: $document,
                documentText: (string) ($window['text'] ?? ''),
                runId: $windowRunId,
                windowMeta: $windowMeta,
            );

            $requestResults[] = $requestResult;
            $openAiCallCount += (int) ($requestResult['openai_call_count'] ?? 1);
            $promptVersion = (string) ($requestResult['prompt_version'] ?? $promptVersion);
            $model = (string) ($requestResult['model'] ?? $model);

            $windowSummary = [
                'window_index' => $windowMeta['window_index'],
                'window_count' => $windowMeta['window_count'],
                'start_position' => $windowMeta['window_start_position'],
                'end_position' => $windowMeta['window_end_position'],
                'document_text_length' => (int) ($requestResult['document_text_length'] ?? 0),
                'input_text_length' => is_numeric($requestResult['input_text_length'] ?? null) ? (int) $requestResult['input_text_length'] : null,
                'request_id' => $requestResult['request_id'] ?? null,
                'response_id' => $requestResult['response_id'] ?? null,
                'status' => $requestResult['status'] ?? null,
                'ok' => (bool) ($requestResult['ok'] ?? false),
                'error_type' => $requestResult['error_type'] ?? null,
                'error_message' => $requestResult['error_message'] ?? null,
            ];

            if (! ($requestResult['ok'] ?? false)) {
                $windowFailures[] = [
                    'window_index' => $windowMeta['window_index'],
                    'stage' => (string) ($requestResult['failure_stage'] ?? 'openai_request'),
                    'type' => (string) ($requestResult['failure_type'] ?? $requestResult['error_type'] ?? 'openai_request_failed'),
                    'message' => (string) ($requestResult['error_message'] ?? 'Extraction window request failed.'),
                ];
                $windowSummaries[] = $windowSummary;

                continue;
            }

            $successfulRequestCount++;

            try {
                $parsed = $this->parsePhaseOneOutput((string) ($requestResult['raw_output'] ?? ''));
                $rows = $parsed['rows'] ?? [];
                $windowRawCandidateCount = count($rows);
                $filteredRows = $this->filterPhaseOneRows($rows);
                $windowFilteredCandidateCount = count($filteredRows);
                $windowFilteredOutCount = $windowRawCandidateCount - $windowFilteredCandidateCount;
                $windowCandidates = $this->mapCandidates($document, $filteredRows);

                $rawCandidateCount += $windowRawCandidateCount;
                $filteredCandidateCount += $windowFilteredCandidateCount;
                $mappedCandidates = [...$mappedCandidates, ...$windowCandidates];
                $parsedWindowCount++;

                $windowSummary['parse_strategy'] = (string) ($parsed['strategy'] ?? 'json_schema');
                $windowSummary['raw_candidate_count'] = $windowRawCandidateCount;
                $windowSummary['filtered_candidate_count'] = $windowFilteredCandidateCount;
                $windowSummary['filtered_out_count'] = $windowFilteredOutCount;
                $windowSummary['mapped_candidate_count'] = count($windowCandidates);
            } catch (JsonException $exception) {
                $outputTokens = is_numeric($requestResult['output_tokens'] ?? null) ? (int) $requestResult['output_tokens'] : null;
                $errorType = $this->phaseOneJsonParseErrorType($outputTokens, FullDocumentRequirementExtractionPrompt::maxOutputTokens());
                $errorMessage = $this->phaseOneJsonParseErrorMessage($errorType, $exception->getMessage());

                $this->logPhaseOneJsonParseFailure(
                    $requestResult,
                    $exception,
                    $errorType,
                    $outputTokens,
                    FullDocumentRequirementExtractionPrompt::maxOutputTokens(),
                );

                $windowFailures[] = [
                    'window_index' => $windowMeta['window_index'],
                    'stage' => $errorType,
                    'type' => $errorType,
                    'message' => $errorMessage,
                ];
                $windowSummary['ok'] = false;
                $windowSummary['error_type'] = $errorType;
                $windowSummary['error_message'] = $errorMessage;
            } catch (Throwable $exception) {
                $windowFailures[] = [
                    'window_index' => $windowMeta['window_index'],
                    'stage' => 'unexpected_response',
                    'type' => 'unexpected_response',
                    'message' => $exception->getMessage(),
                ];
                $windowSummary['ok'] = false;
                $windowSummary['error_type'] = 'unexpected_response';
                $windowSummary['error_message'] = $exception->getMessage();
            }

            $windowSummaries[] = $windowSummary;
        }

        $mappedCandidateCount = count($mappedCandidates);
        $dedupedCandidates = $this->dedupeCandidates($mappedCandidates);
        $dedupedCandidateCount = count($dedupedCandidates);
        $partial = $windowFailures !== [];
        $elapsedMs = $this->elapsedMs($startedAt);

        if ($parsedWindowCount === 0) {
            $primaryFailure = $windowFailures[0] ?? [
                'stage' => 'windowed_extraction',
                'type' => 'windowed_extraction_failed',
                'message' => 'Phase 1 extraction failed for all extraction windows.',
            ];

            return $this->buildFullDocumentFailureResult(
                document: $document,
                runId: $runId,
                requestResult: $requestResults[0] ?? [],
                failureStage: (string) ($primaryFailure['stage'] ?? 'windowed_extraction'),
                failureType: (string) ($primaryFailure['type'] ?? 'windowed_extraction_failed'),
                errorMessage: (string) ($primaryFailure['message'] ?? 'Phase 1 extraction failed for all extraction windows.'),
                extraMetadata: [
                    'full_document_mode' => true,
                    'segment_count' => $windowCount,
                    'relevant_segment_count' => $successfulRequestCount,
                    'extraction_call_count' => $windowCount,
                    'openai_call_count' => max(1, $openAiCallCount),
                    'windowed_extraction' => $windowCount > 1,
                    'window_count' => $windowCount,
                    'successful_request_count' => $successfulRequestCount,
                    'parsed_window_count' => $parsedWindowCount,
                    'failed_window_count' => count($windowFailures),
                    'window_target_characters' => self::PHASE_ONE_WINDOW_TARGET_CHARS,
                    'window_overlap_characters' => self::PHASE_ONE_WINDOW_OVERLAP_CHARS,
                    'window_results' => $windowSummaries,
                ],
                partial: false,
            );
        }

        $inputTokensTotal = (int) array_sum(array_map(
            static fn (array $result): int => is_numeric($result['input_tokens'] ?? null) ? (int) $result['input_tokens'] : 0,
            $requestResults
        ));
        $outputTokensTotal = (int) array_sum(array_map(
            static fn (array $result): int => is_numeric($result['output_tokens'] ?? null) ? (int) $result['output_tokens'] : 0,
            $requestResults
        ));
        $totalTokensTotal = (int) array_sum(array_map(
            static fn (array $result): int => is_numeric($result['total_tokens'] ?? null) ? (int) $result['total_tokens'] : 0,
            $requestResults
        ));

        $singleRequestResult = $windowCount === 1 ? ($requestResults[0] ?? []) : [];

        return new RequirementExtractionResultData(
            ok: true,
            partial: $partial,
            savedNoticeId: (int) $document->saved_notice_id,
            savedNoticeAiDocumentId: $document->id,
            runId: $runId,
            documentTitle: $documentTitle,
            documentFilename: $documentFilename,
            model: $model,
            relevanceModel: $this->relevanceModel(),
            extractionModel: $model,
            segmentCount: 0,
            relevantSegmentCount: 0,
            relevanceCallCount: 0,
            extractionCallCount: $windowCount,
            openAiCallCount: max(1, $openAiCallCount),
            segments: [],
            relevanceResults: [],
            extractionResults: [],
            candidates: $dedupedCandidates,
            metadata: [
                'prompt_versions' => [
                    'relevance' => $this->relevancePromptVersion(),
                    'extraction' => $promptVersion,
                ],
                'full_document_mode' => true,
                'segment_count' => 0,
                'relevant_segment_count' => 0,
                'relevance_call_count' => 0,
                'extraction_call_count' => $windowCount,
                'openai_call_count' => max(1, $openAiCallCount),
                'candidate_count' => $dedupedCandidateCount,
                'raw_candidate_count' => $rawCandidateCount,
                'filtered_candidate_count' => $filteredCandidateCount,
                'mapped_candidate_count' => $mappedCandidateCount,
                'deduped_candidate_count' => $dedupedCandidateCount,
                'failure_count' => count($windowFailures),
                'partial' => $partial,
                'fallback_used' => false,
                'document_text_length' => mb_strlen($documentText, 'UTF-8'),
                'prompt_text_length' => $windowCount === 1 && is_numeric($singleRequestResult['prompt_text_length'] ?? null)
                    ? (int) $singleRequestResult['prompt_text_length']
                    : null,
                'input_text_length' => $windowCount === 1 && is_numeric($singleRequestResult['input_text_length'] ?? null)
                    ? (int) $singleRequestResult['input_text_length']
                    : null,
                'input_tokens_total' => $inputTokensTotal,
                'output_tokens_total' => $outputTokensTotal,
                'total_tokens_total' => $totalTokensTotal,
                'elapsed_ms' => $elapsedMs,
                'request_id' => $windowCount === 1 ? ($singleRequestResult['request_id'] ?? null) : null,
                'response_id' => $windowCount === 1 ? ($singleRequestResult['response_id'] ?? null) : null,
                'status' => $windowCount === 1 ? ($singleRequestResult['status'] ?? null) : null,
                'raw_output_length' => $windowCount === 1 ? (int) ($singleRequestResult['raw_output_length'] ?? 0) : 0,
                'raw_output' => $windowCount === 1 ? (string) ($singleRequestResult['raw_output'] ?? '') : '',
                'raw_output_preview' => $windowCount === 1 ? (string) ($singleRequestResult['raw_output_preview'] ?? '') : '',
                'parse_strategy' => $windowCount === 1
                    ? (string) (($windowSummaries[0]['parse_strategy'] ?? 'json_schema'))
                    : 'windowed_json_schema',
                'windowed_extraction' => $windowCount > 1,
                'window_count' => $windowCount,
                'window_target_characters' => self::PHASE_ONE_WINDOW_TARGET_CHARS,
                'window_overlap_characters' => self::PHASE_ONE_WINDOW_OVERLAP_CHARS,
                'window_results' => $windowSummaries,
                'failure_stage' => null,
                'failure_type' => null,
            ],
            errorType: null,
            errorMessage: null,
            failureStage: null,
            failureType: null,
        );
    }


    public function extractStructuredBlock(SavedNoticeAiDocument $document, RequirementExtractionBlockData $block, ?string $runId = null): RequirementExtractionResultData
    {
        $runId ??= (string) Str::uuid();
        $requestResult = $this->performStructuredBlockExtractionRequest($document, $block, $runId);
        $documentTitle = (string) $document->original_filename;
        $documentFilename = $documentTitle;
        $requestId = data_get($requestResult, 'request_id');
        $responseId = data_get($requestResult, 'response_id');
        $rawOutputPreview = $this->previewText((string) data_get($requestResult, 'raw_output', ''));
        $parseStrategy = 'unparsed';

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
                    'request_id' => $requestId,
                    'response_id' => $responseId,
                    'status' => $requestResult['status'],
                    'raw_output_length' => $requestResult['raw_output_length'],
                    'raw_output' => $requestResult['raw_output'],
                    'parse_strategy' => 'failed',
                ],
                errorType: $requestResult['error_type'],
                errorMessage: $requestResult['error_message'],
            );
        }

        $parsed = $this->parseOutput($requestResult['raw_output']);
        $decodedCandidateCount = (int) ($parsed['row_count'] ?? 0);
        $parseStrategy = (string) ($parsed['strategy'] ?? 'unknown');
        $candidates = $this->mapCandidates($document, $parsed['rows'] ?? [], $block);
        $mappedCandidateCount = count($candidates);
        $dedupedCandidates = $this->dedupeCandidates($candidates);
        $dedupedCandidateCount = count($dedupedCandidates);

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
            ],
            errorType: null,
            errorMessage: null,
            failureStage: null,
            failureType: null,
        );
    }

    private function performFullDocumentExtractionRequest(SavedNoticeAiDocument $document, string $runId): array
    {
        return $this->performFullDocumentExtractionRequestForText(
            document: $document,
            documentText: trim((string) $document->extracted_text),
            runId: $runId,
        );
    }

    private function performFullDocumentExtractionRequestForText(
        SavedNoticeAiDocument $document,
        string $documentText,
        string $runId,
        array $windowMeta = [],
    ): array {
        $startedAt = microtime(true);
        $documentText = trim($documentText);
        $documentTextLength = mb_strlen($documentText, 'UTF-8');
        $promptTextLength = mb_strlen(FullDocumentRequirementExtractionPrompt::text(), 'UTF-8');
        $payload = FullDocumentRequirementExtractionPrompt::requestPayload($documentText);
        $model = (string) ($payload['model'] ?? FullDocumentRequirementExtractionPrompt::model());
        $userInputText = (string) data_get($payload, 'input.1.content.0.text', FullDocumentRequirementExtractionPrompt::inputTextForDocument($documentText));
        $inputTextLength = $promptTextLength + mb_strlen($userInputText, 'UTF-8');
        $promptVersion = FullDocumentRequirementExtractionPrompt::promptVersion();

        try {
            $response = $this->openAiClient->post('responses', $payload, 180);
        } catch (ConnectionException $exception) {
            $elapsedMs = $this->elapsedMs($startedAt);
            $errorType = str_contains(mb_strtolower($exception->getMessage(), 'UTF-8'), 'timed out') ? 'timeout' : 'connection_error';
            $failureStage = $errorType === 'timeout' ? 'openai_timeout' : 'openai_connection';

            return array_merge([
                'ok' => false,
                'run_id' => $runId,
                'document_id' => $document->id,
                'saved_notice_ai_document_id' => $document->id,
                'saved_notice_id' => $document->saved_notice_id,
                'document_title' => $document->original_filename,
                'document_filename' => $document->original_filename,
                'prompt_version' => $promptVersion,
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
                'raw_response_body' => '',
                'raw_response_body_length' => 0,
                'upstream_error_message' => null,
                'upstream_error_type' => null,
                'upstream_error_code' => null,
                'upstream_error_param' => null,
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
                'openai_call_count' => 1,
            ], $windowMeta);
        } catch (Throwable $exception) {
            $elapsedMs = $this->elapsedMs($startedAt);

            return array_merge([
                'ok' => false,
                'run_id' => $runId,
                'document_id' => $document->id,
                'saved_notice_ai_document_id' => $document->id,
                'saved_notice_id' => $document->saved_notice_id,
                'document_title' => $document->original_filename,
                'document_filename' => $document->original_filename,
                'prompt_version' => $promptVersion,
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
                'raw_response_body' => '',
                'raw_response_body_length' => 0,
                'upstream_error_message' => null,
                'upstream_error_type' => null,
                'upstream_error_code' => null,
                'upstream_error_param' => null,
                'input_tokens' => null,
                'output_tokens' => null,
                'total_tokens' => null,
                'elapsed_ms' => $elapsedMs,
                'bypassed_chunk_input' => true,
                'bypassed_segment_input' => true,
                'failure_stage' => 'openai_request',
                'failure_type' => 'unknown_error',
                'error_type' => 'unknown_error',
                'error_message' => $exception->getMessage(),
                'phase_1_requirement_extraction' => true,
                'openai_call_count' => 1,
            ], $windowMeta);
        }

        $requestId = $this->requestIdFrom($response);
        $responseId = $this->responseIdFrom($response);
        $status = $response->status();
        $rawResponseBody = trim($response->body());
        $upstreamError = $this->upstreamErrorDetailsFromRawBody($rawResponseBody);
        $tokenUsage = $this->tokenUsageFromResponse($response);
        $rawOutput = $this->responseTextFromOpenAi($response->json());
        $rawOutputLength = mb_strlen($rawOutput, 'UTF-8');
        $rawOutputPreview = $this->previewText($rawOutput);
        $elapsedMs = $this->elapsedMs($startedAt);

        $errorType = null;
        $errorMessage = null;
        $failureStage = null;

        if (! $response->successful()) {
            $errorType = $this->errorTypeForStatus($status);
            $errorMessage = $upstreamError['message'] ?? sprintf('OpenAI phase 1 extraction request failed with HTTP status [%d].', $status);
            $failureStage = 'openai_http_status';
        }

        return array_merge([
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
            'raw_output_length' => $rawOutputLength,
            'raw_output' => $rawOutput,
            'raw_output_preview' => $rawOutputPreview,
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
            'openai_call_count' => 1,
        ], $windowMeta);
    }

    private function buildPhaseOneExtractionWindows(string $text): array
    {
        $text = trim($text);
        $length = mb_strlen($text, 'UTF-8');

        if ($length === 0) {
            return [[
                'text' => '',
                'start_position' => 0,
                'end_position' => 0,
                'window_index' => 0,
                'window_count' => 1,
            ]];
        }

        $lineRecords = $this->phaseOneLineRecordsWithOffsets($text);
        $familyHeaderPositions = [];

        foreach ($lineRecords as $lineRecord) {
            if ($this->isPhaseOneFamilyHeaderLine((string) ($lineRecord['text'] ?? ''))) {
                $familyHeaderPositions[] = (int) ($lineRecord['start_position'] ?? 0);
            }
        }

        $familyHeaderPositions = array_values(array_unique($familyHeaderPositions));
        sort($familyHeaderPositions);

        if (count($familyHeaderPositions) <= 1) {
            return [[
                'text' => $text,
                'start_position' => 0,
                'end_position' => $length,
                'window_index' => 0,
                'window_count' => 1,
            ]];
        }

        $windowStarts = [0];

        foreach (array_slice($familyHeaderPositions, 1) as $familyHeaderPosition) {
            if ($familyHeaderPosition > 0 && end($windowStarts) !== $familyHeaderPosition) {
                $windowStarts[] = $familyHeaderPosition;
            }
        }

        $windowStarts = array_values(array_unique($windowStarts));
        sort($windowStarts);

        $windows = [];
        $windowCount = count($windowStarts);

        foreach ($windowStarts as $windowIndex => $windowStart) {
            $windowEnd = $windowIndex < $windowCount - 1 ? $windowStarts[$windowIndex + 1] : $length;
            $windowText = trim((string) mb_substr($text, $windowStart, $windowEnd - $windowStart, 'UTF-8'));

            if ($windowText === '') {
                continue;
            }

            $windows[] = [
                'text' => $windowText,
                'start_position' => $windowStart,
                'end_position' => $windowEnd,
                'window_index' => count($windows),
                'window_count' => 0,
            ];
        }

        $windowCount = count($windows);

        foreach ($windows as $index => $window) {
            $windows[$index]['window_index'] = $index;
            $windows[$index]['window_count'] = $windowCount;
        }

        return $windows !== [] ? $windows : [[
            'text' => $text,
            'start_position' => 0,
            'end_position' => $length,
            'window_index' => 0,
            'window_count' => 1,
        ]];
    }

    private function shouldUseWindowedPhaseOneExtraction(string $text): bool
    {
        return count($this->buildPhaseOneExtractionWindows($text)) > 1;
    }

    /**
     * @return array<int, array{text: string, start_position: int, end_position: int}>
     */
    private function phaseOneLineRecordsWithOffsets(string $text): array
    {
        $lineRecords = [];
        preg_match_all('/.*?(?:\R|$)/u', $text, $matches, PREG_OFFSET_CAPTURE);

        foreach (($matches[0] ?? []) as $match) {
            $lineText = (string) ($match[0] ?? '');

            if ($lineText === '') {
                continue;
            }

            $byteOffset = (int) ($match[1] ?? 0);
            $startPosition = mb_strlen(substr($text, 0, $byteOffset), 'UTF-8');
            $endPosition = $startPosition + mb_strlen($lineText, 'UTF-8');

            $lineRecords[] = [
                'text' => $lineText,
                'start_position' => $startPosition,
                'end_position' => $endPosition,
            ];
        }

        return $lineRecords;
    }

    private function isPhaseOneFamilyHeaderLine(string $lineText): bool
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($lineText)) ?? trim($lineText);

        if ($normalized === '') {
            return false;
        }

        return preg_match('/^(?:Skal|Bør|Må|Kan|S|E|Evalueringskrav)\s*-\s*krav$/iu', $normalized) === 1;
    }

    private function choosePhaseOneWindowEnd(string $text, int $start, int $idealEnd, int $length): int
    {
        if ($idealEnd >= $length) {
            return $length;
        }

        $minEnd = min($length, $start + self::PHASE_ONE_WINDOW_MIN_CHARS);
        $searchStart = max($minEnd, $idealEnd - self::PHASE_ONE_WINDOW_BOUNDARY_SCAN_CHARS);
        $searchEnd = min($length, $idealEnd + self::PHASE_ONE_WINDOW_BOUNDARY_SCAN_CHARS);

        $boundary = $this->findNearestBoundaryPosition($text, $searchStart, $searchEnd, $idealEnd, '/\R\R+/u')
            ?? $this->findNearestBoundaryPosition($text, $searchStart, $searchEnd, $idealEnd, '/\R/u')
            ?? $this->findNearestBoundaryPosition($text, $searchStart, $searchEnd, $idealEnd, '/[.!?;:]\s+/u');

        if ($boundary !== null && $boundary >= $minEnd) {
            return $boundary;
        }

        return $idealEnd;
    }

    private function findNearestBoundaryPosition(
        string $text,
        int $searchStart,
        int $searchEnd,
        int $preferredPosition,
        string $pattern,
    ): ?int {
        if ($searchEnd <= $searchStart) {
            return null;
        }

        $segment = mb_substr($text, $searchStart, $searchEnd - $searchStart, 'UTF-8');

        if ($segment === '') {
            return null;
        }

        preg_match_all($pattern, $segment, $matches, PREG_OFFSET_CAPTURE);

        if (($matches[0] ?? []) === []) {
            return null;
        }

        $bestPosition = null;
        $bestDistance = null;

        foreach ($matches[0] as $match) {
            $matchedText = (string) ($match[0] ?? '');
            $byteOffset = (int) ($match[1] ?? 0);
            $localStart = mb_strlen(substr($segment, 0, $byteOffset), 'UTF-8');
            $candidatePosition = $searchStart + $localStart + mb_strlen($matchedText, 'UTF-8');
            $distance = abs($candidatePosition - $preferredPosition);

            if ($bestDistance === null || $distance < $bestDistance) {
                $bestDistance = $distance;
                $bestPosition = $candidatePosition;
            }
        }

        return $bestPosition;
    }

    private function performStructuredBlockExtractionRequest(SavedNoticeAiDocument $document, RequirementExtractionBlockData $block, string $runId): array
    {
        $startedAt = microtime(true);
        $documentText = trim($block->content);
        $documentTextLength = mb_strlen($documentText, 'UTF-8');
        $payload = $this->blockPromptBuilder->buildRequestPayload($document, collect([$block]));
        $model = (string) ($payload['model'] ?? $this->blockPromptBuilder->model());
        $inputTextLength = mb_strlen((string) data_get($payload, 'input.1.content.0.text', ''), 'UTF-8');
        $promptVersion = $this->blockPromptBuilder->promptVersion();

        try {
            $response = $this->openAiClient->post('responses', $payload, 180);
        } catch (Throwable $exception) {
            $elapsedMs = $this->elapsedMs($startedAt);
            $errorType = str_contains(mb_strtolower($exception->getMessage(), 'UTF-8'), 'timed out') ? 'timeout' : 'connection_error';

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

        $errorType = null;
        $errorMessage = null;

        if (! $response->successful()) {
            $errorType = $this->errorTypeForStatus($status);
            $errorMessage = sprintf('OpenAI structured block extraction request failed with HTTP status [%d].', $status);
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

    private function relevanceModel(): string
    {
        $model = trim((string) config(
            'services.openai.requirement_relevance_model',
            config('services.openai.requirement_extraction_model', config('services.openai.model', 'gpt-4.1-mini')),
        ));

        return $model !== '' ? $model : 'gpt-4.1-mini';
    }

    private function relevancePromptVersion(): string
    {
        return RequirementSegmentRelevancePromptBuilder::PROMPT_VERSION;
    }

    private function segmentRequestPayload(SavedNoticeAiDocument $document, DocumentRequirementSegmentData $segment): array
    {
        return $this->promptBuilder->buildRequestPayload($document, $segment);
    }

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

    private function decodeSegmentPayload(string $rawText): array
    {
        $text = $this->sanitizeJsonTextForDecoding($this->segmentStripCodeFences(trim($rawText)));

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

    private function segmentStripCodeFences(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/', '', $text) ?? $text;

        return trim($text);
    }

    private function segmentMapCandidates(
        SavedNoticeAiDocument $document,
        DocumentRequirementSegmentData $segment,
        string $runId,
        ?string $requestId,
        ?string $responseId,
        array $rows,
    ): array {
        $candidates = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
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
                continue;
            }

            $candidate = RequirementExtractionCandidateData::fromSegmentRow($row, $segment, (int) $index);

            if ($candidate->originalText === '' && $candidate->normalizedText === '') {
                continue;
            }

            $candidates[] = $candidate;
        }

        return $candidates;
    }

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

    private function segmentFingerprint(RequirementExtractionCandidateData $candidate): string
    {
        return implode('|', [
            $candidate->sourceBlockId,
            mb_strtolower(trim((string) ($candidate->requirementIdentifier ?? '')), 'UTF-8'),
            mb_strtolower(trim($candidate->originalText), 'UTF-8'),
        ]);
    }

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

    private function segmentEmptyTokenUsage(): array
    {
        return [
            'input_tokens' => null,
            'output_tokens' => null,
            'total_tokens' => null,
        ];
    }

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

    private function segmentResponseIdFrom(Response $response): ?string
    {
        $responseId = trim((string) data_get($response->json(), 'id', ''));

        return $responseId !== '' ? $responseId : null;
    }

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

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function segmentInputText(array $payload): string
    {
        return (string) data_get($payload, 'input.1.content.0.text', data_get($payload, 'input.0.content.0.text', ''));
    }

    private function parseOutput(string $rawText): array
    {
        $text = $this->sanitizeJsonTextForDecoding($this->stripCodeFences(trim($rawText)));

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

    private function parsePhaseOneOutput(string $rawText): array
    {
        $text = $this->sanitizeJsonTextForDecoding($this->stripCodeFences(trim($rawText)));

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

    private function phaseOneJsonParseErrorType(?int $outputTokens, int $maxOutputTokens): string
    {
        if ($outputTokens !== null && $outputTokens >= $maxOutputTokens) {
            return 'truncated_response';
        }

        return 'invalid_json_response';
    }

    private function phaseOneJsonParseErrorMessage(string $errorType, string $jsonErrorMessage): string
    {
        return $errorType === 'truncated_response'
            ? 'AI response appears to have been truncated at the configured output token limit before valid JSON could be parsed.'
            : $jsonErrorMessage;
    }

    private function logPhaseOneJsonParseFailure(
        array $requestResult,
        JsonException $exception,
        string $errorType,
        ?int $outputTokens,
        int $maxOutputTokens,
    ): void {
        $rawOutput = (string) data_get($requestResult, 'raw_output', '');
        $sanitizedPreview = $this->previewText($this->sanitizeJsonTextForDecoding($rawOutput), 500);

        Log::warning('[PROCYNIA][REQ_PIPELINE] Phase 1 extraction response parsing failed.', [
            'error_type' => $errorType,
            'json_error_message' => $exception->getMessage(),
            'output_tokens' => $outputTokens,
            'max_output_tokens' => $maxOutputTokens,
            'request_id' => data_get($requestResult, 'request_id'),
            'response_id' => data_get($requestResult, 'response_id'),
            'output_length' => mb_strlen($rawOutput, 'UTF-8'),
            'output_preview' => $sanitizedPreview,
        ]);
    }

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

    private function parseJsonRows(string $text): ?array
    {
        $text = $this->sanitizeJsonTextForDecoding($text);

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

    private function normalizeCanonicalRow(array $row): ?array
    {
        $originalText = $this->normalizeScalar($row['original_text'] ?? null);
        $normalizedText = $this->normalizeScalar($row['normalized_text'] ?? null);

        if ($normalizedText === '') {
            $normalizedText = $originalText;
        }

        $sourceReferenceText = $this->normalizeScalar($row['source_reference_text'] ?? null);

        if ($originalText === '' && $normalizedText === '') {
            return null;
        }

        if ($this->looksLikeHeaderRow($row, $originalText, $normalizedText)) {
            return null;
        }

        $normalizedRow = [
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

        return $normalizedRow;
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
            $text = str_replace('\\|', '|', $text);

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

        foreach ($rows as $row) {
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


    private function filterPhaseOneRows(array $rows): array
    {
        $filtered = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            if ($this->shouldRejectPhaseOneRow($row)) {
                continue;
            }

            $filtered[] = $row;
        }

        return $filtered;
    }

    private function shouldRejectPhaseOneRow(array $row): bool
    {
        $sourceReferenceText = mb_strtolower($this->normalizeScalar($row['source_reference_text'] ?? null), 'UTF-8');
        $parentReference = mb_strtolower($this->normalizeScalar($row['parent_reference'] ?? null), 'UTF-8');
        $referenceContext = trim($sourceReferenceText . ' ' . $parentReference);

        if ($referenceContext === '') {
            return false;
        }

        foreach ($this->nonRequirementContextMarkers() as $marker) {
            if (str_contains($referenceContext, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function nonRequirementContextMarkers(): array
    {
        return [
            'veiledning om',
            'guidance on',
            'innholdsfortegnelse',
            'table of contents',
            'forklaring av nummerering',
            'nummerering av',
            'kravnummer =',
            'legend',
            'how to answer',
            'besvarelse av krav',
        ];
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
            mb_strtolower(trim((string) ($candidate->requirementIdentifier ?? '')), 'UTF-8'),
            mb_strtolower(trim((string) ($candidate->parentReference ?? '')), 'UTF-8'),
            mb_strtolower(trim((string) ($candidate->requirementType ?? '')), 'UTF-8'),
            mb_strtolower(trim((string) ($candidate->obligationType ?? '')), 'UTF-8'),
            mb_strtolower(trim(preg_replace('/\s+/u', ' ', trim((string) ($candidate->normalizedText !== '' ? $candidate->normalizedText : $candidate->originalText))) ?? ''), 'UTF-8'),
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

    private function aiCostContext(SavedNoticeAiDocument $document, string $runId, array $extra = []): array
    {
        return array_merge([
            'run_id' => $runId,
            'document_id' => $document->id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_id' => $document->saved_notice_id,
        ], $extra);
    }

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

    private function emptyTokenUsage(): array
    {
        return [
            'input_tokens' => null,
            'output_tokens' => null,
            'total_tokens' => null,
        ];
    }

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

    private function previewText(string $text, int $limit = 1500): string
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        if ($normalized === '') {
            return '';
        }

        return Str::limit($normalized, $limit, '...');
    }

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

    private function sanitizeJsonTextForDecoding(string $text): string
    {
        $text = str_replace("\xEF\xBB\xBF", '', $text);
        $text = preg_replace('/[\x00-\x1F\x7F]/', ' ', $text) ?? $text;
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = str_replace("\xEF\xBB\xBF", '', $text);
        $text = preg_replace('/\p{C}+/u', ' ', $text) ?? $text;

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
