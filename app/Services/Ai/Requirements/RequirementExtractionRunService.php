<?php

namespace App\Services\Ai\Requirements;

use App\Data\Ai\Requirements\RequirementExtractionCandidateData;
use App\Data\Ai\Requirements\RequirementExtractionResultData;
use App\Jobs\Ai\Requirements\ProcessRequirementExtractionRun;
use App\Models\RequirementExtractionCall;
use App\Models\RequirementExtractionRun;
use App\Models\SavedNoticeAiDocument;
use App\Models\SavedNoticeAiDocumentChunk;
use App\Models\SavedNoticeAiRequirement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class RequirementExtractionRunService
{
    public function __construct(
        private readonly RequirementCandidateExtractor $candidateExtractor,
        private readonly RequirementEditorService $requirementEditorService,
    ) {
    }

    /**
     * Purpose: Create or reuse the queued extraction run for one AI document and dispatch the worker job when needed.
     * Inputs: The persisted AI document that already contains extracted text.
     * Returns: The active extraction run row.
     * Side effects: Creates a queued run row, updates the document status mirror, and dispatches one async job.
     */
    public function createQueuedRunForDocument(SavedNoticeAiDocument $document): RequirementExtractionRun
    {
        $queuedAt = now();
        $shouldDispatch = false;

        $run = DB::transaction(function () use ($document, $queuedAt, &$shouldDispatch): RequirementExtractionRun {
            $lockedDocument = SavedNoticeAiDocument::query()
                ->whereKey($document->id)
                ->lockForUpdate()
                ->firstOrFail();

            $activeRun = RequirementExtractionRun::query()
                ->where('saved_notice_ai_document_id', $lockedDocument->id)
                ->whereIn('status', [
                    RequirementExtractionRun::STATUS_QUEUED,
                    RequirementExtractionRun::STATUS_PROCESSING,
                    RequirementExtractionRun::STATUS_MERGING,
                ])
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($activeRun instanceof RequirementExtractionRun) {
                $activeRun->forceFill([
                    'strategy' => RequirementExtractionRun::STRATEGY_PHASE_1_REQUIREMENT_EXTRACTION,
                    'prompt_version' => FullDocumentRequirementExtractionPrompt::promptVersion(),
                    'model' => FullDocumentRequirementExtractionPrompt::model(),
                    'failure_stage' => null,
                ])->save();

                $lockedDocument->forceFill([
                    'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_QUEUED,
                    'queued_at' => $lockedDocument->queued_at ?? $queuedAt,
                    'processing_started_at' => $lockedDocument->processing_started_at,
                    'processing_finished_at' => $lockedDocument->processing_finished_at,
                    'processing_error_type' => null,
                    'processing_error_message' => null,
                ])->save();

                Log::info('[PROCYNIA][REQ_PIPELINE] Async phase 1 requirement extraction run queued.', [
                    'run_id' => $activeRun->uuid,
                    'document_id' => $lockedDocument->id,
                    'saved_notice_ai_document_id' => $lockedDocument->id,
                    'saved_notice_id' => $lockedDocument->saved_notice_id,
                    'strategy' => RequirementExtractionRun::STRATEGY_PHASE_1_REQUIREMENT_EXTRACTION,
                    'status' => RequirementExtractionRun::STATUS_QUEUED,
                    'phase_1_requirement_extraction' => true,
                ]);

                return $activeRun;
            }

            $run = RequirementExtractionRun::query()->create([
                'uuid' => (string) Str::uuid(),
                'saved_notice_id' => $lockedDocument->saved_notice_id,
                'saved_notice_ai_document_id' => $lockedDocument->id,
                'status' => RequirementExtractionRun::STATUS_QUEUED,
                'strategy' => RequirementExtractionRun::STRATEGY_PHASE_1_REQUIREMENT_EXTRACTION,
                'prompt_version' => FullDocumentRequirementExtractionPrompt::promptVersion(),
                'model' => FullDocumentRequirementExtractionPrompt::model(),
                'failure_stage' => null,
                'error_type' => null,
                'error_message' => null,
                'candidate_count' => 0,
                'persisted_requirement_count' => 0,
                'openai_call_count' => 0,
                'input_tokens_total' => 0,
                'output_tokens_total' => 0,
                'total_tokens_total' => 0,
                'queued_at' => $queuedAt,
                'started_at' => null,
                'finished_at' => null,
                'last_heartbeat_at' => $queuedAt,
            ]);

            $lockedDocument->forceFill([
                'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_QUEUED,
                'queued_at' => $queuedAt,
                'processing_started_at' => null,
                'processing_finished_at' => null,
                'processing_error_type' => null,
                'processing_error_message' => null,
            ])->save();

            $shouldDispatch = true;

            Log::info('[PROCYNIA][REQ_PIPELINE] Async phase 1 requirement extraction run queued.', [
                'run_id' => $run->uuid,
                'document_id' => $lockedDocument->id,
                'saved_notice_ai_document_id' => $lockedDocument->id,
                'saved_notice_id' => $lockedDocument->saved_notice_id,
                'strategy' => RequirementExtractionRun::STRATEGY_PHASE_1_REQUIREMENT_EXTRACTION,
                'status' => RequirementExtractionRun::STATUS_QUEUED,
                'phase_1_requirement_extraction' => true,
            ]);

            return $run;
        });

        if ($shouldDispatch) {
            try {
                ProcessRequirementExtractionRun::dispatch($run->id)->onQueue($this->queueName());
            } catch (Throwable $throwable) {
                $documentForFailure = SavedNoticeAiDocument::query()->find($run->saved_notice_ai_document_id);

                if ($documentForFailure instanceof SavedNoticeAiDocument) {
                    $this->markRunFailed(
                        $run,
                        $documentForFailure,
                        'unexpected',
                        'unknown_error',
                        $throwable->getMessage(),
                    );
                }

                throw $throwable;
            }
        }

        return $run;
    }

    /**
     * Purpose: Process one queued extraction run end to end.
     * Inputs: The extraction run row.
     * Returns: None.
     * Side effects: Calls the Phase 1 extractor once, stages requirements, promotes them transactionally, and updates status mirrors.
     */
    public function processRun(RequirementExtractionRun $run): void
    {
        $run = RequirementExtractionRun::query()->find($run->id);

        if (! $run instanceof RequirementExtractionRun || $run->isTerminal()) {
            return;
        }

        $document = SavedNoticeAiDocument::query()->find($run->saved_notice_ai_document_id);

        if (! $document instanceof SavedNoticeAiDocument) {
            return;
        }

        $this->markRunProcessing($run, $document);

        $splitResult = app(DocumentSplitPlanner::class)->plan($document, $run->uuid);

        Log::info('[TT][SPLIT] Async split result received.', [
            'run_id' => $run->uuid,
            'document_id' => $document->id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_id' => $document->saved_notice_id,
            'ok' => $splitResult['ok'] ?? null,
            'split_plan_count' => count($splitResult['split_plan'] ?? []),
            'error_type' => $splitResult['error_type'] ?? null,
            'error_message' => $splitResult['error_message'] ?? null,
        ]);

        if (! ($splitResult['ok'] ?? false)) {
            $this->markRunFailed(
                $run,
                $document,
                'document_split',
                (string) ($splitResult['error_type'] ?? 'document_split_failed'),
                (string) ($splitResult['error_message'] ?? 'Document split planning failed.'),
                [
                    'candidate_count' => 0,
                    'persisted_requirement_count' => 0,
                    'openai_call_count' => 1,
                    'input_tokens_total' => (int) ($splitResult['input_tokens'] ?? 0),
                    'output_tokens_total' => (int) ($splitResult['output_tokens'] ?? 0),
                    'total_tokens_total' => (int) ($splitResult['total_tokens'] ?? 0),
                ],
            );

            return;
        }

        $chunkBuildResult = $this->persistDocumentSplitChunks($document, $run, $splitResult);

        if (! $chunkBuildResult['ok']) {
            $this->markRunFailed(
                $run,
                $document,
                'document_split_persist',
                (string) ($chunkBuildResult['error_type'] ?? 'document_split_persist_failed'),
                (string) ($chunkBuildResult['error_message'] ?? 'Document split chunk persistence failed.'),
                [
                    'candidate_count' => 0,
                    'persisted_requirement_count' => (int) ($chunkBuildResult['chunk_count'] ?? 0),
                    'openai_call_count' => 1,
                    'input_tokens_total' => (int) ($splitResult['input_tokens'] ?? 0),
                    'output_tokens_total' => (int) ($splitResult['output_tokens'] ?? 0),
                    'total_tokens_total' => (int) ($splitResult['total_tokens'] ?? 0),
                ],
            );

            return;
        }

        if ($this->stagedRequirementCount($run) > 0) {
            $this->clearStagedRequirements($run);
        }

        $chunks = SavedNoticeAiDocumentChunk::query()
            ->where('saved_notice_ai_document_id', $document->id)
            ->orderBy('chunk_index')
            ->get();

        if ($chunks->isEmpty()) {
            $this->markRunFailed(
                $run,
                $document,
                'chunk_load',
                'invalid_state',
                'No persisted document chunks were available for extraction.',
                [
                    'candidate_count' => 0,
                    'persisted_requirement_count' => 0,
                    'openai_call_count' => 0,
                    'input_tokens_total' => 0,
                    'output_tokens_total' => 0,
                    'total_tokens_total' => 0,
                ],
            );

            return;
        }

        Log::info('[PROCYNIA][REQ_PIPELINE] Chunk-based extraction started.', [
            'run_id' => $run->uuid,
            'document_id' => $document->id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_id' => $document->saved_notice_id,
            'chunk_count' => $chunks->count(),
            'phase_1_requirement_extraction' => true,
        ]);

        $allCandidates = [];
        $inputTokensTotal = 0;
        $outputTokensTotal = 0;
        $totalTokensTotal = 0;
        $openAiCallCount = 0;
        $lastResultModel = FullDocumentRequirementExtractionPrompt::model();

        foreach ($chunks as $chunk) {
            $chunkCall = $this->startCall($run, $document, $chunk->id);
            $chunkDocument = clone $document;
            $chunkDocument->extracted_text = (string) $chunk->content;

            try {
                $result = $this->candidateExtractor->extractFullDocument($chunkDocument, $run->uuid . '-chunk-' . $chunk->chunk_index);
            } catch (Throwable $throwable) {
                $this->failCall($chunkCall, $document, 'chunk_extraction_failed', $throwable->getMessage());

                $this->markRunFailed(
                    $run,
                    $document,
                    'chunk_extraction',
                    'chunk_extraction_failed',
                    $throwable->getMessage(),
                    [
                        'candidate_count' => count($allCandidates),
                        'persisted_requirement_count' => 0,
                        'openai_call_count' => $openAiCallCount + 1,
                        'input_tokens_total' => $inputTokensTotal,
                        'output_tokens_total' => $outputTokensTotal,
                        'total_tokens_total' => $totalTokensTotal,
                    ],
                );

                return;
            }

            $openAiCallCount += (int) ($result->openAiCallCount ?? 1);
            $inputTokensTotal += (int) data_get($result->metadata, 'input_tokens_total', 0);
            $outputTokensTotal += (int) data_get($result->metadata, 'output_tokens_total', 0);
            $totalTokensTotal += (int) data_get($result->metadata, 'total_tokens_total', 0);
            $lastResultModel = $result->model ?? $lastResultModel;

            if (! $result->ok) {
                $this->failCallFromResult($chunkCall, $document, $result);

                $this->markRunFailed(
                    $run,
                    $document,
                    $result->failureStage ?? 'chunk_extraction',
                    $result->failureType ?? ($result->errorType ?? 'chunk_extraction_failed'),
                    $result->errorMessage ?? 'Chunk extraction failed.',
                    [
                        'candidate_count' => count($allCandidates),
                        'persisted_requirement_count' => 0,
                        'openai_call_count' => $openAiCallCount,
                        'input_tokens_total' => $inputTokensTotal,
                        'output_tokens_total' => $outputTokensTotal,
                        'total_tokens_total' => $totalTokensTotal,
                    ],
                );

                return;
            }

            $this->finishCall($chunkCall, $document, $result);
            foreach ($result->candidates as $candidate) {
    $allCandidates[] = $candidate;
}

            Log::info('[PROCYNIA][REQ_PIPELINE] Chunk extraction completed.', [
                'run_id' => $run->uuid,
                'document_id' => $document->id,
                'saved_notice_ai_document_id' => $document->id,
                'saved_notice_ai_document_chunk_id' => $chunk->id,
                'saved_notice_id' => $document->saved_notice_id,
                'chunk_index' => $chunk->chunk_index,
                'candidate_count' => count($result->candidates),
                'phase_1_requirement_extraction' => true,
            ]);
        }

        $dedupedCandidates = $this->documentDedupeCandidates($allCandidates);

        Log::info('[PROCYNIA][REQ_PIPELINE] Document-level dedupe completed.', [
            'run_id' => $run->uuid,
            'document_id' => $document->id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_id' => $document->saved_notice_id,
            'raw_candidate_count' => count($allCandidates),
            'deduped_candidate_count' => count($dedupedCandidates),
            'phase_1_requirement_extraction' => true,
        ]);

        try {
            $stagedRequirementCount = $this->stageChunkCandidates(
                $run,
                $document,
                $dedupedCandidates,
                $lastResultModel,
                $openAiCallCount,
                $inputTokensTotal,
                $outputTokensTotal,
                $totalTokensTotal,
            );
        } catch (Throwable $throwable) {
            $this->markRunFailed(
                $run,
                $document,
                'staging',
                'persistence_error',
                $throwable->getMessage(),
                [
                    'candidate_count' => count($dedupedCandidates),
                    'persisted_requirement_count' => 0,
                    'openai_call_count' => $openAiCallCount,
                    'input_tokens_total' => $inputTokensTotal,
                    'output_tokens_total' => $outputTokensTotal,
                    'total_tokens_total' => $totalTokensTotal,
                ],
            );

            return;
        }

        $this->markRunMerging($run, $document);
        $publishedRequirementCount = $this->promoteRun($run, $document);

        Log::info('[PROCYNIA][REQ_PIPELINE] Chunk-based extraction completed.', [
            'run_id' => $run->uuid,
            'document_id' => $document->id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_id' => $document->saved_notice_id,
            'chunk_count' => $chunks->count(),
            'raw_candidate_count' => count($allCandidates),
            'deduped_candidate_count' => count($dedupedCandidates),
            'staged_requirement_count' => $stagedRequirementCount,
            'published_requirement_count' => $publishedRequirementCount,
            'openai_call_count' => $openAiCallCount,
            'input_tokens_total' => $inputTokensTotal,
            'output_tokens_total' => $outputTokensTotal,
            'total_tokens_total' => $totalTokensTotal,
            'phase_1_requirement_extraction' => true,
        ]);

        return;
    }

    /**
     * Purpose: Persist validated split-plan groups as deterministic document chunks.
     * Inputs: The source document, the extraction run, and the split-plan result.
     * Returns: A structured result with chunk count and validation status.
     * Side effects: Replaces existing chunks for the document and writes split diagnostics to logs.
     */
    private function persistDocumentSplitChunks(
        SavedNoticeAiDocument $document,
        RequirementExtractionRun $run,
        array $splitResult,
    ): array {
        $documentText = trim((string) $document->extracted_text);
        $splitPlan = is_array($splitResult['split_plan'] ?? null) ? $splitResult['split_plan'] : [];

        if ($documentText === '') {
            Log::warning('[TT][SPLIT] Chunk build failed because extracted text was empty.', [
                'run_id' => $run->uuid,
                'document_id' => $document->id,
                'saved_notice_ai_document_id' => $document->id,
                'saved_notice_id' => $document->saved_notice_id,
            ]);

            return [
                'ok' => false,
                'error_type' => 'invalid_request',
                'error_message' => 'Document extracted text is missing.',
                'chunk_count' => 0,
                'coverage_ok' => false,
            ];
        }

        if ($splitPlan === []) {
            Log::warning('[TT][SPLIT] Chunk build failed because split plan was empty.', [
                'run_id' => $run->uuid,
                'document_id' => $document->id,
                'saved_notice_ai_document_id' => $document->id,
                'saved_notice_id' => $document->saved_notice_id,
            ]);

            return [
                'ok' => false,
                'error_type' => 'invalid_split_plan',
                'error_message' => 'Document split plan was empty.',
                'chunk_count' => 0,
                'coverage_ok' => false,
            ];
        }

        $resolvedGroups = [];
        $documentLength = mb_strlen($documentText, 'UTF-8');

        foreach ($splitPlan as $groupIndex => $group) {
            $groupId = trim((string) ($group['group_id'] ?? ''));
            $groupType = trim((string) ($group['group_type'] ?? ''));
            $title = trim((string) ($group['title'] ?? ''));
            $reason = trim((string) ($group['reason'] ?? ''));
            $startPosition = (int) ($group['start_position'] ?? -1);
            $endPosition = (int) ($group['end_position'] ?? -1);

            if ($startPosition < 0 || $endPosition <= $startPosition || $endPosition > $documentLength) {
                Log::warning('[TT][SPLIT] Invalid chunk positions detected.', [
                    'run_id' => $run->uuid,
                    'document_id' => $document->id,
                    'saved_notice_ai_document_id' => $document->id,
                    'saved_notice_id' => $document->saved_notice_id,
                    'group_index' => $groupIndex,
                    'group_id' => $groupId,
                    'start_position' => $startPosition,
                    'end_position' => $endPosition,
                    'document_length' => $documentLength,
                ]);

                return [
                    'ok' => false,
                    'error_type' => 'invalid_positions',
                    'error_message' => 'Split plan contained invalid chunk positions.',
                    'chunk_count' => count($resolvedGroups),
                    'coverage_ok' => false,
                ];
            }

            if ($resolvedGroups !== []) {
                $previousEndPosition = (int) $resolvedGroups[count($resolvedGroups) - 1]['end_position'];

                if ($startPosition !== $previousEndPosition) {
                    Log::warning('[TT][SPLIT] Non-contiguous chunk positions detected.', [
                        'run_id' => $run->uuid,
                        'document_id' => $document->id,
                        'saved_notice_ai_document_id' => $document->id,
                        'saved_notice_id' => $document->saved_notice_id,
                        'group_index' => $groupIndex,
                        'group_id' => $groupId,
                        'start_position' => $startPosition,
                        'previous_end_position' => $previousEndPosition,
                    ]);

                    return [
                        'ok' => false,
                        'error_type' => 'non_contiguous_positions',
                        'error_message' => 'Split plan positions were not contiguous.',
                        'chunk_count' => count($resolvedGroups),
                        'coverage_ok' => false,
                    ];
                }
            }

            $content = trim(mb_substr($documentText, $startPosition, $endPosition - $startPosition, 'UTF-8'));

            if ($content === '') {
                Log::warning('[TT][SPLIT] Empty chunk content detected.', [
                    'run_id' => $run->uuid,
                    'document_id' => $document->id,
                    'saved_notice_ai_document_id' => $document->id,
                    'saved_notice_id' => $document->saved_notice_id,
                    'group_index' => $groupIndex,
                    'group_id' => $groupId,
                ]);

                return [
                    'ok' => false,
                    'error_type' => 'empty_chunk_content',
                    'error_message' => 'Split plan produced an empty chunk.',
                    'chunk_count' => count($resolvedGroups),
                    'coverage_ok' => false,
                ];
            }

            $resolvedGroups[] = [
                'chunk_index' => $groupIndex,
                'group_id' => $groupId,
                'group_type' => $groupType,
                'title' => $title,
                'reason' => $reason,
                'start_anchor' => null,
                'end_anchor' => null,
                'start_position' => $startPosition,
                'end_position' => $endPosition,
                'content' => $content,
            ];

            Log::info('[TT][SPLIT] Chunk resolved.', [
                'run_id' => $run->uuid,
                'document_id' => $document->id,
                'saved_notice_ai_document_id' => $document->id,
                'saved_notice_id' => $document->saved_notice_id,
                'chunk_index' => $groupIndex,
                'group_id' => $groupId,
                'group_type' => $groupType,
                'start_found' => true,
                'end_found' => true,
                'start_position' => $startPosition,
                'end_position' => $endPosition,
                'chunk_length' => mb_strlen($content, 'UTF-8'),
            ]);
        }

        $coverageOk = $resolvedGroups !== []
            && (int) $resolvedGroups[0]['start_position'] === 0
            && (int) $resolvedGroups[count($resolvedGroups) - 1]['end_position'] === $documentLength;

        DB::transaction(function () use ($document, $resolvedGroups): void {
            SavedNoticeAiDocumentChunk::query()
                ->where('saved_notice_ai_document_id', $document->id)
                ->delete();

            foreach ($resolvedGroups as $group) {
                SavedNoticeAiDocumentChunk::query()->create([
                    'saved_notice_ai_document_id' => $document->id,
                    'chunk_index' => $group['chunk_index'],
                    'content' => $group['content'],
                    'char_start' => $group['start_position'],
                    'char_end' => $group['end_position'],
                    'word_count' => str_word_count($group['content']),
                ]);
            }
        });

        Log::info('[TT][SPLIT] Chunk persistence completed.', [
            'run_id' => $run->uuid,
            'document_id' => $document->id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_id' => $document->saved_notice_id,
            'chunk_count' => count($resolvedGroups),
            'coverage_ok' => $coverageOk,
            'document_length' => $documentLength,
            'covered_until' => $resolvedGroups !== [] ? (int) $resolvedGroups[count($resolvedGroups) - 1]['end_position'] : 0,
        ]);

        return [
            'ok' => true,
            'error_type' => null,
            'error_message' => null,
            'chunk_count' => count($resolvedGroups),
            'coverage_ok' => $coverageOk,
        ];
    }

    /**
     * Purpose: Find the first occurrence of an anchor from a given cursor position.
     * Inputs: The full document text, the anchor text, and the cursor offset.
     * Returns: The start position, or null when not found.
     * Side effects: None.
     */
    private function findAnchorPosition(string $documentText, string $anchor, int $offset): ?int
    {
        foreach ($this->buildAnchorCandidates($anchor) as $candidate) {
            $exactPosition = mb_strpos($documentText, $candidate, $offset, 'UTF-8');

            if ($exactPosition !== false) {
                return $exactPosition;
            }

            $normalizedPosition = $this->findNormalizedAnchorPosition($documentText, $candidate, $offset);

            if ($normalizedPosition !== null) {
                return $normalizedPosition;
            }
        }

        return null;
    }

    /**
     * Purpose: Find the end position for an anchor starting from a known lower bound.
     * Inputs: The full document text, the anchor text, and the lower bound offset.
     * Returns: The end position, or null when not found.
     * Side effects: None.
     */
    private function findAnchorEndPosition(string $documentText, string $anchor, int $offset): ?int
    {
        foreach ($this->buildAnchorCandidates($anchor) as $candidate) {
            $position = mb_strpos($documentText, $candidate, $offset, 'UTF-8');

            if ($position !== false) {
                return $position + mb_strlen($candidate, 'UTF-8');
            }

            $normalizedMatch = $this->findNormalizedAnchorMatch($documentText, $candidate, $offset);

            if ($normalizedMatch !== null) {
                return $normalizedMatch['end'];
            }
        }

        return null;
    }


    /**
     * Purpose: Find an anchor using whitespace-normalized matching when exact matching fails.
     * Inputs: The full document text, the anchor text, and the cursor offset.
     * Returns: The start position in the original text, or null when not found.
     * Side effects: None.
     */
    private function findNormalizedAnchorPosition(string $documentText, string $anchor, int $offset): ?int
    {
        $match = $this->findNormalizedAnchorMatch($documentText, $anchor, $offset);

        return $match['start'] ?? null;
    }

    /**
     * Purpose: Find an anchor using whitespace-normalized matching and map the match back to original text offsets.
     * Inputs: The full document text, the anchor text, and the cursor offset.
     * Returns: The original start and end offsets, or null when not found.
     * Side effects: None.
     */
    private function findNormalizedAnchorMatch(string $documentText, string $anchor, int $offset): ?array
    {
        $window = mb_substr($documentText, $offset, null, 'UTF-8');

        if ($window === '') {
            return null;
        }

        $normalizedAnchorData = $this->normalizeTextForAnchorSearch($anchor);
        $normalizedWindowData = $this->normalizeTextForAnchorSearch($window);

        $normalizedAnchor = $normalizedAnchorData['text'];
        $normalizedWindow = $normalizedWindowData['text'];

        if ($normalizedAnchor === '' || $normalizedWindow === '') {
            return null;
        }

        $normalizedPosition = mb_strpos($normalizedWindow, $normalizedAnchor, 0, 'UTF-8');

        if ($normalizedPosition === false) {
            return null;
        }

        $startMap = $normalizedWindowData['map'] ?? [];
        $endMap = $normalizedWindowData['end_map'] ?? [];
        $normalizedLength = mb_strlen($normalizedAnchor, 'UTF-8');
        $normalizedEndIndex = $normalizedPosition + $normalizedLength - 1;

        if (! array_key_exists($normalizedPosition, $startMap) || ! array_key_exists($normalizedEndIndex, $endMap)) {
            return null;
        }

        return [
            'start' => $offset + (int) $startMap[$normalizedPosition],
            'end' => $offset + (int) $endMap[$normalizedEndIndex],
        ];
    }

    /**
     * Purpose: Build candidate anchor variants that tolerate trailing TOC page numbers without changing the canonical anchor.
     * Inputs: One raw anchor string.
     * Returns: A de-duplicated ordered list of candidate anchors.
     * Side effects: None.
     */
    private function buildAnchorCandidates(string $anchor): array
    {
        $baseAnchor = trim($anchor);

        if ($baseAnchor === '') {
            return [];
        }

        $candidates = [$baseAnchor];
        $withoutTrailingPageNumber = trim((string) preg_replace('/\s+\d{1,4}$/u', '', $baseAnchor));

        if ($withoutTrailingPageNumber !== '' && $withoutTrailingPageNumber !== $baseAnchor) {
            $candidates[] = $withoutTrailingPageNumber;
        }

        return array_values(array_unique(array_filter($candidates, static fn (string $candidate): bool => $candidate !== '')));
    }

    /**
     * Purpose: Normalize text for whitespace-tolerant anchor matching while preserving a map back to original offsets.
     * Inputs: Raw text.
     * Returns: A normalized text string and offset maps into the original text.
     * Side effects: None.
     */
    private function normalizeTextForAnchorSearch(string $text): array
    {
        $length = mb_strlen($text, 'UTF-8');
        $normalized = '';
        $startMap = [];
        $endMap = [];
        $lastWasWhitespace = false;

        for ($index = 0; $index < $length; $index++) {
            $char = mb_substr($text, $index, 1, 'UTF-8');

            if (preg_match('/\s/u', $char) === 1) {
                if ($normalized === '' || $lastWasWhitespace) {
                    continue;
                }

                $startMap[mb_strlen($normalized, 'UTF-8')] = $index;
                $normalized .= ' ';
                $endMap[mb_strlen($normalized, 'UTF-8') - 1] = $index + 1;
                $lastWasWhitespace = true;

                continue;
            }

            $startMap[mb_strlen($normalized, 'UTF-8')] = $index;
            $normalized .= $char;
            $endMap[mb_strlen($normalized, 'UTF-8') - 1] = $index + 1;
            $lastWasWhitespace = false;
        }

        $trimmedText = trim($normalized);

        if ($trimmedText === '') {
            return [
                'text' => '',
                'map' => [],
                'end_map' => [],
            ];
        }

        $leadingTrimmedLength = mb_strlen($normalized, 'UTF-8') - mb_strlen(ltrim($normalized), 'UTF-8');
        $trimmedLength = mb_strlen($trimmedText, 'UTF-8');
        $trimmedStartMap = [];
        $trimmedEndMap = [];

        for ($i = 0; $i < $trimmedLength; $i++) {
            $trimmedStartMap[$i] = $startMap[$i + $leadingTrimmedLength];
            $trimmedEndMap[$i] = $endMap[$i + $leadingTrimmedLength];
        }

        return [
            'text' => $trimmedText,
            'map' => $trimmedStartMap,
            'end_map' => $trimmedEndMap,
        ];
    }


    /**
     * Purpose: Persist staged AI requirement rows for a successful full-document extraction result.
     * Inputs: The extraction run, the source document, and the extraction result.
     * Returns: The number of staged requirement rows created.
     * Side effects: Creates staged requirement rows and updates the run counters.
     */
    public function stageCandidates(
        RequirementExtractionRun $run,
        SavedNoticeAiDocument $document,
        RequirementExtractionResultData $result,
    ): int {
        return DB::transaction(function () use ($run, $document, $result): int {
            $stagedRequirementCount = 0;
            $candidateCount = count($result->candidates);
            $inputTokens = (int) data_get($result->metadata, 'input_tokens_total', 0);
            $outputTokens = (int) data_get($result->metadata, 'output_tokens_total', 0);
            $totalTokens = (int) data_get($result->metadata, 'total_tokens_total', 0);
            $extractionPromptVersion = (string) data_get($result->metadata, 'prompt_versions.extraction', FullDocumentRequirementExtractionPrompt::promptVersion());
            $extractionRequestId = trim((string) data_get($result->metadata, 'request_id', ''));
            $extractionResponseId = trim((string) data_get($result->metadata, 'response_id', ''));
            $extractionRequestId = $extractionRequestId !== '' ? $extractionRequestId : null;
            $extractionResponseId = $extractionResponseId !== '' ? $extractionResponseId : null;
            $elapsedMs = (int) data_get($result->metadata, 'elapsed_ms', 0);

            foreach ($result->candidates as $candidate) {
                if (! $candidate instanceof RequirementExtractionCandidateData || ! $candidate->isRequirement) {
                    continue;
                }

                $extractionMetadata = array_merge($candidate->jsonSerialize(), [
                    'run_id' => $run->uuid,
                    'document_title' => $document->original_filename,
                    'document_filename' => $document->original_filename,
                    'phase_1_requirement_extraction' => true,
                    'full_document_extraction' => $result->toArray(),
                    'extraction_prompt_version' => $extractionPromptVersion,
                    'extraction_model' => $result->model,
                    'extraction_request_id' => $extractionRequestId,
                    'extraction_response_id' => $extractionResponseId,
                    'extraction_elapsed_ms' => $elapsedMs,
                ]);

                $persistenceData = $candidate->toPersistenceData(
                    $document,
                    null,
                    $extractionMetadata,
                );

                $this->requirementEditorService->createAiCandidate(
                    $document,
                    null,
                    $persistenceData,
                    null,
                    [
                        'extraction_run_id' => $run->id,
                        'publication_status' => SavedNoticeAiRequirement::PUBLICATION_STATUS_STAGED,
                        'published_at' => null,
                        'superseded_at' => null,
                    ],
                );

                $stagedRequirementCount++;
            }

            $run->forceFill([
                'candidate_count' => $candidateCount,
                'persisted_requirement_count' => $stagedRequirementCount,
                'openai_call_count' => (int) data_get($result->metadata, 'openai_call_count', 1),
                'input_tokens_total' => $inputTokens,
                'output_tokens_total' => $outputTokens,
                'total_tokens_total' => $totalTokens,
                'last_heartbeat_at' => now(),
            ])->save();

            return $stagedRequirementCount;
        });
    }



    /**
     * Purpose: Persist chunk-based AI requirement rows after document-level deduplication.
     * Inputs: The extraction run, the source document, de-duplicated candidates, and aggregate usage metadata.
     * Returns: The number of staged requirement rows created.
     * Side effects: Creates staged requirement rows and updates the run counters.
     */
    private function stageChunkCandidates(
        RequirementExtractionRun $run,
        SavedNoticeAiDocument $document,
        array $candidates,
        ?string $model,
        int $openAiCallCount,
        int $inputTokensTotal,
        int $outputTokensTotal,
        int $totalTokensTotal,
    ): int {
        return DB::transaction(function () use (
            $run,
            $document,
            $candidates,
            $model,
            $openAiCallCount,
            $inputTokensTotal,
            $outputTokensTotal,
            $totalTokensTotal,
        ): int {
            $stagedRequirementCount = 0;

            foreach ($candidates as $candidate) {
                if (! $candidate instanceof RequirementExtractionCandidateData || ! $candidate->isRequirement) {
                    continue;
                }

                $extractionMetadata = array_merge($candidate->jsonSerialize(), [
                    'run_id' => $run->uuid,
                    'document_title' => $document->original_filename,
                    'document_filename' => $document->original_filename,
                    'phase_1_requirement_extraction' => true,
                    'chunk_based_extraction' => true,
                    'extraction_prompt_version' => FullDocumentRequirementExtractionPrompt::promptVersion(),
                    'extraction_model' => $model,
                ]);

                $persistenceData = $candidate->toPersistenceData(
                    $document,
                    null,
                    $extractionMetadata,
                );

                $this->requirementEditorService->createAiCandidate(
                    $document,
                    null,
                    $persistenceData,
                    null,
                    [
                        'extraction_run_id' => $run->id,
                        'publication_status' => SavedNoticeAiRequirement::PUBLICATION_STATUS_STAGED,
                        'published_at' => null,
                        'superseded_at' => null,
                    ],
                );

                $stagedRequirementCount++;
            }

            $run->forceFill([
                'candidate_count' => count($candidates),
                'persisted_requirement_count' => $stagedRequirementCount,
                'openai_call_count' => $openAiCallCount,
                'input_tokens_total' => $inputTokensTotal,
                'output_tokens_total' => $outputTokensTotal,
                'total_tokens_total' => $totalTokensTotal,
                'last_heartbeat_at' => now(),
            ])->save();

            return $stagedRequirementCount;
        });
    }
    /**
     * Purpose: Mark a run and its document mirror as failed without touching already published AI requirements.
     * Inputs: The extraction run, the source document, and the failure details.
     * Returns: The updated run row.
     * Side effects: Persists the terminal failure state on the run and document.
     */
    public function markRunFailed(
        RequirementExtractionRun $run,
        SavedNoticeAiDocument $document,
        ?string $failureStage = null,
        ?string $errorType = null,
        ?string $errorMessage = null,
        array $statistics = [],
    ): RequirementExtractionRun {
        $finishedAt = now();

        $run->forceFill([
            'status' => RequirementExtractionRun::STATUS_FAILED,
            'failure_stage' => $failureStage,
            'error_type' => $errorType,
            'error_message' => $errorMessage,
            'candidate_count' => (int) ($statistics['candidate_count'] ?? $run->candidate_count ?? 0),
            'persisted_requirement_count' => (int) ($statistics['persisted_requirement_count'] ?? $run->persisted_requirement_count ?? 0),
            'openai_call_count' => (int) ($statistics['openai_call_count'] ?? $run->openai_call_count ?? 0),
            'input_tokens_total' => (int) ($statistics['input_tokens_total'] ?? $run->input_tokens_total ?? 0),
            'output_tokens_total' => (int) ($statistics['output_tokens_total'] ?? $run->output_tokens_total ?? 0),
            'total_tokens_total' => (int) ($statistics['total_tokens_total'] ?? $run->total_tokens_total ?? 0),
            'finished_at' => $finishedAt,
            'last_heartbeat_at' => $finishedAt,
        ])->save();

        $document->forceFill([
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_FAILED,
            'processing_finished_at' => $finishedAt,
            'processing_error_type' => $errorType,
            'processing_error_message' => $errorMessage,
        ])->save();

        return $run;
    }

    /**
     * Purpose: Promote staged AI requirement rows into the published set for one document in one transaction.
     * Inputs: The extraction run and the source document.
     * Returns: The number of published requirement rows belonging to the run.
     * Side effects: Supersedes prior published AI rows for the same document and finalizes the run/document status.
     */
    public function promoteRun(RequirementExtractionRun $run, SavedNoticeAiDocument $document): int
    {
        return DB::transaction(function () use ($run, $document): int {
            $now = now();
            $stagedRows = SavedNoticeAiRequirement::query()
                ->where('saved_notice_ai_document_id', $document->id)
                ->where('extraction_run_id', $run->id)
                ->where('publication_status', SavedNoticeAiRequirement::PUBLICATION_STATUS_STAGED)
                ->get(['id']);
            $stagedRequirementIds = $stagedRows->pluck('id')->all();

            SavedNoticeAiRequirement::query()
                ->where('saved_notice_ai_document_id', $document->id)
                ->where('source_type', SavedNoticeAiRequirement::SOURCE_TYPE_AI_CANDIDATE)
                ->where('publication_status', SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED)
                ->where(function ($query) use ($run): void {
                    $query->whereNull('extraction_run_id')
                        ->orWhere('extraction_run_id', '!=', $run->id);
                })
                ->update([
                    'publication_status' => SavedNoticeAiRequirement::PUBLICATION_STATUS_SUPERSEDED,
                    'superseded_at' => $now,
                ]);

            if ($stagedRequirementIds !== []) {
                SavedNoticeAiRequirement::query()
                    ->whereIn('id', $stagedRequirementIds)
                    ->update([
                        'publication_status' => SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED,
                        'published_at' => $now,
                        'superseded_at' => null,
                    ]);
            }

            $publishedRequirementCount = SavedNoticeAiRequirement::query()
                ->where('saved_notice_ai_document_id', $document->id)
                ->where('extraction_run_id', $run->id)
                ->where('publication_status', SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED)
                ->count();

            $run->forceFill([
                'status' => RequirementExtractionRun::STATUS_COMPLETED,
                'failure_stage' => null,
                'candidate_count' => (int) $run->candidate_count,
                'persisted_requirement_count' => $publishedRequirementCount,
                'openai_call_count' => (int) $run->openai_call_count,
                'input_tokens_total' => (int) $run->input_tokens_total,
                'output_tokens_total' => (int) $run->output_tokens_total,
                'total_tokens_total' => (int) $run->total_tokens_total,
                'error_type' => null,
                'error_message' => null,
                'finished_at' => $now,
                'last_heartbeat_at' => $now,
            ])->save();

            $document->forceFill([
                'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_COMPLETED,
                'processing_finished_at' => $now,
                'processing_error_type' => null,
                'processing_error_message' => null,
            ])->save();

            return $publishedRequirementCount;
        });
    }

    /**
     * Purpose: Update a run and document to the processing state.
     * Inputs: The extraction run and source document.
     * Returns: None.
     * Side effects: Persists the processing status mirror on both records.
     */
    private function markRunProcessing(RequirementExtractionRun $run, SavedNoticeAiDocument $document): void
    {
        $startedAt = now();

        $run->forceFill([
            'status' => RequirementExtractionRun::STATUS_PROCESSING,
            'started_at' => $run->started_at ?? $startedAt,
            'last_heartbeat_at' => $startedAt,
        ])->save();

        $document->forceFill([
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_PROCESSING,
            'processing_started_at' => $document->processing_started_at ?? $startedAt,
            'processing_finished_at' => null,
            'processing_error_type' => null,
            'processing_error_message' => null,
        ])->save();
    }

    /**
     * Purpose: Update a run and document to the merging state before transactional publication.
     * Inputs: The extraction run and source document.
     * Returns: None.
     * Side effects: Persists the merging status mirror on both records.
     */
    private function markRunMerging(RequirementExtractionRun $run, SavedNoticeAiDocument $document): void
    {
        $heartbeatAt = now();

        $run->forceFill([
            'status' => RequirementExtractionRun::STATUS_MERGING,
            'last_heartbeat_at' => $heartbeatAt,
        ])->save();

        $document->forceFill([
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_MERGING,
            'processing_error_type' => null,
            'processing_error_message' => null,
        ])->save();
    }

    /**
     * Purpose: Record a successful OpenAI call for the current extraction run.
     * Inputs: The run-level call record, the source document, and the extraction result.
     * Returns: None.
     * Side effects: Persists the call outcome and token usage in the database.
     */
    private function finishCall(
        ?RequirementExtractionCall $call,
        SavedNoticeAiDocument $document,
        RequirementExtractionResultData $result,
    ): void {
        if (! $call instanceof RequirementExtractionCall) {
            return;
        }

        $requestId = data_get($result->metadata, 'request_id');
        $responseId = data_get($result->metadata, 'response_id');
        $statusCode = data_get($result->metadata, 'status');
        $inputTokens = data_get($result->metadata, 'input_tokens_total', 0);
        $outputTokens = data_get($result->metadata, 'output_tokens_total', 0);
        $totalTokens = data_get($result->metadata, 'total_tokens_total', 0);
        $elapsedMs = data_get($result->metadata, 'elapsed_ms');
        $finishedAt = now();

        $call->forceFill([
            'status' => RequirementExtractionCall::STATUS_COMPLETED,
            'request_id' => is_string($requestId) ? $requestId : null,
            'response_id' => is_string($responseId) ? $responseId : null,
            'status_code' => is_int($statusCode) ? $statusCode : null,
            'input_tokens' => is_numeric($inputTokens) ? (int) $inputTokens : null,
            'output_tokens' => is_numeric($outputTokens) ? (int) $outputTokens : null,
            'total_tokens' => is_numeric($totalTokens) ? (int) $totalTokens : null,
            'elapsed_ms' => is_numeric($elapsedMs) ? (int) $elapsedMs : null,
            'error_type' => null,
            'error_message' => null,
            'finished_at' => $finishedAt,
        ])->save();
    }

    /**
     * Purpose: Record a failed OpenAI call for the current extraction run.
     * Inputs: The call record, the source document, and the failure details.
     * Returns: None.
     * Side effects: Persists the call failure outcome in the database.
     */
    private function failCall(
        RequirementExtractionCall $call,
        SavedNoticeAiDocument $document,
        string $errorType,
        string $errorMessage,
    ): void {
        $finishedAt = now();

        $call->forceFill([
            'status' => RequirementExtractionCall::STATUS_FAILED,
            'error_type' => $errorType,
            'error_message' => $errorMessage,
            'finished_at' => $finishedAt,
        ])->save();
    }

    /**
     * Purpose: Record a failed OpenAI call from a full-document extraction failure result.
     * Inputs: The call record, the source document, and the failure result.
     * Returns: None.
     * Side effects: Persists the call failure outcome in the database.
     */
    private function failCallFromResult(
        RequirementExtractionCall $call,
        SavedNoticeAiDocument $document,
        RequirementExtractionResultData $result,
    ): void {
        $requestId = data_get($result->metadata, 'request_id');
        $responseId = data_get($result->metadata, 'response_id');
        $statusCode = data_get($result->metadata, 'status');
        $inputTokens = data_get($result->metadata, 'input_tokens_total', 0);
        $outputTokens = data_get($result->metadata, 'output_tokens_total', 0);
        $totalTokens = data_get($result->metadata, 'total_tokens_total', 0);
        $elapsedMs = data_get($result->metadata, 'elapsed_ms');
        $finishedAt = now();

        $call->forceFill([
            'status' => RequirementExtractionCall::STATUS_FAILED,
            'request_id' => is_string($requestId) ? $requestId : null,
            'response_id' => is_string($responseId) ? $responseId : null,
            'status_code' => is_int($statusCode) ? $statusCode : null,
            'input_tokens' => is_numeric($inputTokens) ? (int) $inputTokens : null,
            'output_tokens' => is_numeric($outputTokens) ? (int) $outputTokens : null,
            'total_tokens' => is_numeric($totalTokens) ? (int) $totalTokens : null,
            'elapsed_ms' => is_numeric($elapsedMs) ? (int) $elapsedMs : null,
            'error_type' => $result->errorType ?? 'unexpected_error',
            'error_message' => $result->errorMessage ?? 'Phase 1 extraction failed.',
            'finished_at' => $finishedAt,
        ])->save();
    }

    /**
     * Purpose: Create an in-flight OpenAI call record for the run.
     * Inputs: The run and source document.
     * Returns: The persisted call row.
     * Side effects: Persists the running call state.
     */
    private function startCall(
        RequirementExtractionRun $run,
        SavedNoticeAiDocument $document,
        ?int $savedNoticeAiDocumentChunkId = null,
    ): RequirementExtractionCall {
        return RequirementExtractionCall::query()->create([
            'requirement_extraction_run_id' => $run->id,
            'saved_notice_id' => $document->saved_notice_id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_ai_document_chunk_id' => $savedNoticeAiDocumentChunkId,
            'status' => RequirementExtractionCall::STATUS_RUNNING,
            'strategy' => RequirementExtractionRun::STRATEGY_PHASE_1_REQUIREMENT_EXTRACTION,
            'prompt_version' => FullDocumentRequirementExtractionPrompt::promptVersion(),
            'model' => FullDocumentRequirementExtractionPrompt::model(),
            'request_id' => null,
            'response_id' => null,
            'status_code' => null,
            'input_tokens' => null,
            'output_tokens' => null,
            'total_tokens' => null,
            'elapsed_ms' => null,
            'error_type' => null,
            'error_message' => null,
            'started_at' => now(),
            'finished_at' => null,
        ]);
    }

    /**
     * Purpose: Deduplicate candidates at the document scope so repeated block hits collapse into one published requirement.
     * Inputs: A list of block-level extraction candidates.
     * Returns: A de-duplicated candidate list keyed by document-scope requirement identity.
     * Side effects: None.
     */
    private function documentDedupeCandidates(array $candidates): array
    {
        $deduped = [];

        foreach ($candidates as $candidate) {
            if (! $candidate instanceof RequirementExtractionCandidateData || ! $candidate->isRequirement) {
                continue;
            }

            $fingerprint = $this->documentCandidateFingerprint($candidate);

            if (! array_key_exists($fingerprint, $deduped)) {
                $deduped[$fingerprint] = $candidate;
                continue;
            }

            $deduped[$fingerprint] = $this->mergeDocumentCandidates($deduped[$fingerprint], $candidate);
        }

        return array_values($deduped);
    }

    /**
     * Purpose: Build a stable fingerprint for document-scope deduplication.
     * Inputs: One extraction candidate.
     * Returns: A normalized fingerprint string.
     * Side effects: None.
     */
    private function documentCandidateFingerprint(RequirementExtractionCandidateData $candidate): string
    {
        return implode('|', [
            $this->normalizeFingerprintValue($candidate->requirementIdentifier),
            $this->normalizeFingerprintValue($candidate->parentReference),
            $this->normalizeFingerprintValue($candidate->requirementType),
            $this->normalizeFingerprintValue($candidate->obligationType),
            $this->normalizeFingerprintValue($candidate->originalText),
            $this->normalizeFingerprintValue($candidate->normalizedText),
        ]);
    }

    /**
     * Purpose: Merge two duplicate candidates while preserving the strongest available provenance metadata.
     * Inputs: The representative candidate and a duplicate candidate.
     * Returns: A new candidate object with merged source reference metadata.
     * Side effects: None.
     */
    private function mergeDocumentCandidates(
        RequirementExtractionCandidateData $representative,
        RequirementExtractionCandidateData $duplicate,
    ): RequirementExtractionCandidateData {
        $primaryCandidate = $representative->confidence >= $duplicate->confidence ? $representative : $duplicate;
        $secondaryCandidate = $primaryCandidate === $representative ? $duplicate : $representative;
        $sourceReference = $this->mergeSourceReference(
            $primaryCandidate->sourceReference,
            $secondaryCandidate->sourceReference,
        );

        $mergedExpectedEvidence = array_values(array_unique(array_merge($representative->expectedEvidence, $duplicate->expectedEvidence)));
        $mergedKeywords = array_values(array_unique(array_merge($representative->keywords, $duplicate->keywords)));
        $mergedDomain = array_values(array_unique(array_merge($representative->domain, $duplicate->domain)));
        $mergedRelatedReferences = array_values(array_unique(array_merge($representative->relatedReferences, $duplicate->relatedReferences)));
        $mergedWarnings = array_values(array_unique(array_merge($representative->warnings, $duplicate->warnings)));

        $sourceBlockId = $primaryCandidate->sourceBlockId;
        $sourceBlockIndex = $primaryCandidate->sourceBlockIndex;
        $sourceDocumentId = $primaryCandidate->sourceDocumentId;
        $requirementIdentifier = $primaryCandidate->requirementIdentifier;
        $parentReference = $primaryCandidate->parentReference;
        $requirementType = $primaryCandidate->requirementType;
        $obligationType = $primaryCandidate->obligationType;
        $extractionMethod = $primaryCandidate->extractionMethod;
        $originalText = $primaryCandidate->originalText;
        $normalizedText = $primaryCandidate->normalizedText;
        $comment = $primaryCandidate->comment;
        $evaluationNotes = $primaryCandidate->evaluationNotes;
        $responseExpectation = $primaryCandidate->responseExpectation;
        $interpretationRisk = $primaryCandidate->interpretationRisk;
        $confidence = max($representative->confidence, $duplicate->confidence);

        return new RequirementExtractionCandidateData(
            sourceDocumentId: $sourceDocumentId,
            sourceBlockId: $sourceBlockId,
            sourceBlockIndex: $sourceBlockIndex,
            requirementIdentifier: $requirementIdentifier,
            parentReference: $parentReference,
            requirementType: $requirementType,
            obligationType: $obligationType,
            extractionMethod: $extractionMethod,
            originalText: $originalText,
            normalizedText: $normalizedText,
            comment: $comment,
            evaluationNotes: $evaluationNotes,
            responseExpectation: $responseExpectation,
            expectedEvidence: $mergedExpectedEvidence,
            keywords: $mergedKeywords,
            domain: $mergedDomain,
            relatedReferences: $mergedRelatedReferences,
            sourceReference: $sourceReference,
            interpretationRisk: $interpretationRisk,
            isRequirement: $representative->isRequirement || $duplicate->isRequirement,
            confidence: $confidence,
            warnings: $mergedWarnings,
        );
    }

    /**
     * Purpose: Merge two source reference payloads while keeping the representative block identity intact.
     * Inputs: The primary and duplicate source reference arrays.
     * Returns: A merged source reference array with combined chunk provenance.
     * Side effects: None.
     */
    private function mergeSourceReference(array $primary, array $duplicate): array
    {
        $merged = $primary;

        foreach ($duplicate as $key => $value) {
            if (! array_key_exists($key, $merged) || $this->isBlankSourceReferenceValue($merged[$key])) {
                $merged[$key] = $value;
            }
        }

        $primaryChunkIds = $this->normalizeChunkIdList($primary['source_chunk_ids'] ?? []);
        $duplicateChunkIds = $this->normalizeChunkIdList($duplicate['source_chunk_ids'] ?? []);
        $merged['source_chunk_ids'] = array_values(array_unique(array_merge($primaryChunkIds, $duplicateChunkIds)));

        return $merged;
    }

    /**
     * Purpose: Determine whether a source reference value should be replaced during provenance merge.
     * Inputs: One source reference value.
     * Returns: True when the value is blank.
     * Side effects: None.
     */
    private function isBlankSourceReferenceValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            return $value === [];
        }

        return false;
    }

    /**
     * Purpose: Normalize a list of source chunk ids for provenance merging.
     * Inputs: A raw list of chunk identifiers.
     * Returns: A clean integer list.
     * Side effects: None.
     */
    private function normalizeChunkIdList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(static function (mixed $value): ?int {
            if (is_int($value)) {
                return $value > 0 ? $value : null;
            }

            if (is_string($value) && is_numeric($value)) {
                $numeric = (int) $value;

                return $numeric > 0 ? $numeric : null;
            }

            return null;
        }, $values))));
    }

    /**
     * Purpose: Build a stable normalized string for document-scope deduplication.
     * Inputs: A candidate identity field.
     * Returns: A comparable string.
     * Side effects: None.
     */
    private function normalizeFingerprintValue(mixed $value): string
    {
        if (is_array($value)) {
            return trim(mb_strtolower(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '', 'UTF-8'));
        }

        return trim(mb_strtolower((string) $value, 'UTF-8'));
    }

    /**
     * Purpose: Clear staged rows for a run when a previous attempt left behind incomplete staging state.
     * Inputs: The extraction run.
     * Returns: None.
     * Side effects: Deletes staged requirement rows and their revisions for the run.
     */
    private function clearStagedRequirements(RequirementExtractionRun $run): void
    {
        SavedNoticeAiRequirement::query()
            ->where('extraction_run_id', $run->id)
            ->where('publication_status', SavedNoticeAiRequirement::PUBLICATION_STATUS_STAGED)
            ->delete();
    }

    /**
     * Purpose: Count staged requirement rows for a run.
     * Inputs: The extraction run.
     * Returns: The number of staged requirement rows.
     * Side effects: None.
     */
    private function stagedRequirementCount(RequirementExtractionRun $run): int
    {
        return SavedNoticeAiRequirement::query()
            ->where('extraction_run_id', $run->id)
            ->where('publication_status', SavedNoticeAiRequirement::PUBLICATION_STATUS_STAGED)
            ->count();
    }

    /**
     * Purpose: Count staged rows that are ready to be promoted without repeating the extraction call.
     * Inputs: The extraction run.
     * Returns: The number of promotable staged rows.
     * Side effects: None.
     */
    private function promotableStagedRequirementCount(RequirementExtractionRun $run): int
    {
        $stagedRequirementCount = $this->stagedRequirementCount($run);

        if ($stagedRequirementCount === 0) {
            return 0;
        }

        return (int) $run->persisted_requirement_count === $stagedRequirementCount
            ? $stagedRequirementCount
            : 0;
    }

    /**
     * Purpose: Determine whether the run already has published AI rows from a prior successful promotion.
     * Inputs: The extraction run.
     * Returns: True when at least one published row exists for the run.
     * Side effects: None.
     */
    private function hasPublishedRequirementsForRun(RequirementExtractionRun $run): bool
    {
        return SavedNoticeAiRequirement::query()
            ->where('extraction_run_id', $run->id)
            ->where('publication_status', SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED)
            ->exists();
    }

    /**
     * Purpose: Convert a microtime start value into a rounded millisecond duration.
     * Inputs: The float timestamp captured before the work started.
     * Returns: The elapsed duration in milliseconds.
     * Side effects: None.
     */
    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    /**
     * Purpose: Build a bounded preview of long model output for logs.
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
     * Purpose: Resolve the queue name used by the async AI requirement workers.
     * Inputs: None.
     * Returns: The queue name.
     * Side effects: None.
     */
    private function queueName(): string
    {
        return 'ai-requirements';
    }
}
