<?php

namespace App\Services\Doffin;

use App\Models\DoffinImportRun;
use App\Models\SyncLog;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class DoffinBatchImportService
{
    public function __construct(
        private readonly DoffinClient $client,
        private readonly DoffinImportService $importService,
        private readonly DoffinNoticePipelineService $pipelineService,
    ) {
    }

    public function importBatch(int $limit = 10, ?callable $progress = null, string $trigger = 'manual'): array
    {
        if ($limit < 1) {
            throw new RuntimeException('The batch import limit must be a positive integer.');
        }

        $startedAt = now();
        $run = DoffinImportRun::query()->create([
            'trigger' => $trigger,
            'started_at' => $startedAt,
            'status' => 'running',
        ]);
        $foundNoticeIds = [];
        $processedNoticeIds = [];
        $importedNoticeIds = [];
        $failedNoticeIds = [];
        $failures = [];
        $counters = [
            'fetched_count' => 0,
            'created_count' => 0,
            'updated_count' => 0,
            'skipped_count' => 0,
            'failed_count' => 0,
        ];

        try {
            $this->dispatchProgress($progress, 'batch_started', [
                'limit' => $limit,
                'trigger' => $trigger,
            ]);

            $searchResponse = $this->client->search();
            $foundNoticeIds = $this->importService->extractNoticeIds($searchResponse, $limit);
            $counters['fetched_count'] = count($foundNoticeIds);

            $this->dispatchProgress($progress, 'notice_ids_found', [
                'found_count' => count($foundNoticeIds),
                'notice_ids' => $foundNoticeIds,
            ]);

            foreach ($foundNoticeIds as $noticeId) {
                $processedNoticeIds[] = $noticeId;

                $this->dispatchProgress($progress, 'processing_notice', [
                    'notice_id' => $noticeId,
                ]);

                try {
                    $importResult = $this->importService->importNoticeById($noticeId);
                    $importedNoticeIds[] = $noticeId;
                    $this->applyImportCounters($counters, $importResult);

                    $pipelineResult = $this->pipelineService->process($noticeId);

                    $this->dispatchProgress($progress, 'notice_processed', [
                        'notice_id' => $noticeId,
                        'relevance_score' => $pipelineResult['relevance_score'] ?? null,
                        'relevance_level' => $pipelineResult['relevance_level'] ?? null,
                    ]);
                } catch (Throwable $throwable) {
                    $failedNoticeIds[] = $noticeId;
                    $counters['failed_count']++;
                    $failures[] = [
                        'notice_id' => $noticeId,
                        'error' => $throwable->getMessage(),
                    ];

                    Log::error('Failed to process Doffin notice during batch import.', [
                        'notice_id' => $noticeId,
                        'error' => $throwable->getMessage(),
                    ]);

                    $this->dispatchProgress($progress, 'notice_failed', [
                        'notice_id' => $noticeId,
                        'error' => $throwable->getMessage(),
                    ]);
                }
            }

            $successCount = count($processedNoticeIds) - count($failedNoticeIds);
            $result = [
                'requested_limit' => $limit,
                'found_count' => count($foundNoticeIds),
                'found_notice_ids' => $foundNoticeIds,
                'processed_count' => count($processedNoticeIds),
                'processed_notice_ids' => $processedNoticeIds,
                'success_count' => $successCount,
                'fetched_count' => $counters['fetched_count'],
                'created_count' => $counters['created_count'],
                'updated_count' => $counters['updated_count'],
                'skipped_count' => $counters['skipped_count'],
                'failed_count' => $counters['failed_count'],
                'imported_notice_ids' => $importedNoticeIds,
                'failed_notice_ids' => $failedNoticeIds,
                'failures' => $failures,
                'completed_steps' => ['search', 'import', 'process_notice'],
                'status' => $this->resolveStatus($successCount, count($failedNoticeIds)),
            ];

            $this->finalizeRun(
                $run,
                $result['status'],
                $result,
                [
                    'requested_limit' => $limit,
                    'found_notice_ids' => $foundNoticeIds,
                    'processed_notice_ids' => $processedNoticeIds,
                    'imported_notice_ids' => $importedNoticeIds,
                    'failed_notice_ids' => $failedNoticeIds,
                    'failures' => $failures,
                    'completed_steps' => $result['completed_steps'],
                ],
            );

            SyncLog::query()->create([
                'job_type' => 'import_batch',
                'status' => $result['status'],
                'notice_id' => null,
                'message' => $this->resolveMessage($result['status']),
                'context' => $this->successContext($result),
                'started_at' => $startedAt,
                'finished_at' => now(),
            ]);

            Log::info('Completed Doffin batch import.', $result);

            return $result;
        } catch (Throwable $throwable) {
            Log::error('Doffin batch import failed before completion.', [
                'requested_limit' => $limit,
                'trigger' => $trigger,
                'found_notice_ids' => $foundNoticeIds,
                'processed_notice_ids' => $processedNoticeIds,
                'error' => $throwable->getMessage(),
            ]);

            $failureResult = [
                'fetched_count' => $counters['fetched_count'],
                'created_count' => $counters['created_count'],
                'updated_count' => $counters['updated_count'],
                'skipped_count' => $counters['skipped_count'],
                'failed_count' => $counters['failed_count'],
            ];

            $this->finalizeRun(
                $run,
                'failed',
                $failureResult,
                [
                    'requested_limit' => $limit,
                    'found_notice_ids' => $foundNoticeIds,
                    'processed_notice_ids' => $processedNoticeIds,
                    'failed_notice_ids' => $failedNoticeIds,
                    'failures' => $failures,
                ],
                $throwable->getMessage(),
            );

            $this->storeFailureLog(
                $limit,
                $foundNoticeIds,
                $processedNoticeIds,
                $failedNoticeIds,
                $throwable,
                $startedAt,
            );

            throw new RuntimeException("Doffin batch import failed: {$throwable->getMessage()}", previous: $throwable);
        }
    }

    private function applyImportCounters(array &$counters, array $importResult): void
    {
        $operation = $importResult['operation'] ?? null;

        if ($operation === 'created') {
            $counters['created_count']++;

            return;
        }

        if ($operation === 'updated') {
            $counters['updated_count']++;
        }
    }

    private function resolveStatus(int $successCount, int $failedCount): string
    {
        if ($successCount > 0 && $failedCount === 0) {
            return 'success';
        }

        if ($successCount > 0 && $failedCount > 0) {
            return 'partial';
        }

        return 'failed';
    }

    private function resolveMessage(string $status): string
    {
        return match ($status) {
            'success' => 'Batch import completed',
            'partial' => 'Batch import partially completed',
            default => 'Batch import failed',
        };
    }

    private function successContext(array $result): string
    {
        $context = sprintf(
            'requested=%d found=%d processed=%d success=%d failed=%d created=%d updated=%d skipped=%d',
            $result['requested_limit'],
            $result['found_count'],
            $result['processed_count'],
            $result['success_count'],
            $result['failed_count'],
            $result['created_count'],
            $result['updated_count'],
            $result['skipped_count'],
        );

        if ($result['failed_notice_ids'] !== []) {
            $context .= ' failed_ids='.implode(',', $result['failed_notice_ids']);
        }

        return $context;
    }

    private function storeFailureLog(
        int $limit,
        array $foundNoticeIds,
        array $processedNoticeIds,
        array $failedNoticeIds,
        Throwable $throwable,
        $startedAt,
    ): void {
        try {
            $successfulNoticeIds = array_values(array_diff($processedNoticeIds, $failedNoticeIds));
            $context = sprintf(
                'requested=%d found=%d processed=%d success=%d failed=%d error=%s',
                $limit,
                count($foundNoticeIds),
                count($processedNoticeIds),
                count($successfulNoticeIds),
                count($failedNoticeIds),
                $throwable->getMessage(),
            );

            SyncLog::query()->create([
                'job_type' => 'import_batch',
                'status' => 'failed',
                'notice_id' => null,
                'message' => 'Batch import failed',
                'context' => $context,
                'started_at' => $startedAt,
                'finished_at' => now(),
            ]);
        } catch (Throwable $loggingThrowable) {
            Log::error('Failed to store Doffin batch import failure log.', [
                'logging_error' => $loggingThrowable->getMessage(),
            ]);
        }
    }

    private function finalizeRun(
        DoffinImportRun $run,
        string $status,
        array $counters,
        array $meta = [],
        ?string $errorMessage = null,
    ): void {
        $run->fill([
            'finished_at' => now(),
            'status' => $status,
            'fetched_count' => (int) ($counters['fetched_count'] ?? 0),
            'created_count' => (int) ($counters['created_count'] ?? 0),
            'updated_count' => (int) ($counters['updated_count'] ?? 0),
            'skipped_count' => (int) ($counters['skipped_count'] ?? 0),
            'failed_count' => (int) ($counters['failed_count'] ?? 0),
            'error_message' => $errorMessage,
            'meta' => $meta,
        ])->save();
    }

    private function dispatchProgress(?callable $progress, string $type, array $payload = []): void
    {
        if ($progress === null) {
            return;
        }

        $progress($type, $payload);
    }
}
