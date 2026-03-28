<?php

namespace App\Services\Doffin;

use App\Models\Notice;
use App\Models\SyncLog;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class DoffinNoticePipelineService
{
    public function __construct(
        private readonly DoffinParseService $parseService,
        private readonly DoffinCpvParseService $cpvParseService,
        private readonly DoffinLotParseService $lotParseService,
        private readonly DoffinRelevanceService $relevanceService,
        private readonly DoffinImportService $importService,
    ) {
    }

    public function process(string $noticeId): array
    {
        $startedAt = now();
        $failedStep = null;
        $completedSteps = [];

        $notice = Notice::query()
            ->with('rawXml')
            ->where('notice_id', $noticeId)
            ->first();

        try {
            if ($notice === null) {
                throw new RuntimeException("Notice {$noticeId} was not found.");
            }

            if ($notice->rawXml === null) {
                throw new RuntimeException("Notice {$noticeId} has no raw XML stored.");
            }

            $failedStep = 'parse_notice';
            $parseResult = $this->parseService->parseStoredNotice($noticeId);
            $completedSteps[] = 'parse_notice';

            $failedStep = 'parse_cpv';
            $cpvResult = $this->cpvParseService->parseStoredNotice($noticeId);
            $completedSteps[] = 'parse_cpv';

            $failedStep = 'parse_lots';
            $lotResult = $this->lotParseService->parseStoredNotice($noticeId);
            $completedSteps[] = 'parse_lots';

            $failedStep = 'score';
            $scoreResult = $this->relevanceService->scoreNotice($noticeId);
            $completedSteps[] = 'score';

            $failedStep = 'update_department_visibility';
            $this->importService->updateDepartmentVisibility($notice->fresh('cpvCodes'));
            $completedSteps[] = 'update_department_visibility';

            $result = [
                'notice_id' => $noticeId,
                'parsed_notice_fields' => $parseResult['updated_fields'] ?? [],
                'parsed_cpv_codes' => $cpvResult['cpv_codes'] ?? [],
                'parsed_lots_count' => $lotResult['lot_count'] ?? 0,
                'relevance_score' => $scoreResult['relevance_score'] ?? null,
                'relevance_level' => $scoreResult['relevance_level'] ?? null,
                'completed_steps' => $completedSteps,
                'failed_step' => null,
            ];

            SyncLog::query()->create([
                'job_type' => 'process_notice',
                'status' => 'success',
                'notice_id' => $notice->id,
                'message' => 'Notice pipeline completed',
                'context' => sprintf(
                    'notice=%s steps=%s score=%d level=%s cpv=%d lots=%d',
                    $noticeId,
                    implode(',', $completedSteps),
                    (int) $result['relevance_score'],
                    (string) $result['relevance_level'],
                    count($result['parsed_cpv_codes']),
                    (int) $result['parsed_lots_count'],
                ),
                'started_at' => $startedAt,
                'finished_at' => now(),
            ]);

            Log::info('Processed Doffin notice pipeline successfully.', $result);

            return $result;
        } catch (Throwable $throwable) {
            Log::error('Failed to process Doffin notice pipeline.', [
                'notice_id' => $noticeId,
                'notice_row_id' => $notice?->id,
                'failed_step' => $failedStep,
                'completed_steps' => $completedSteps,
                'error' => $throwable->getMessage(),
            ]);

            $this->storeFailureLog($notice, $noticeId, $failedStep, $completedSteps, $throwable, $startedAt);

            $prefix = $failedStep === null
                ? "Notice pipeline failed for {$noticeId}"
                : "Notice pipeline failed for {$noticeId} at {$failedStep}";

            throw new RuntimeException("{$prefix}: {$throwable->getMessage()}", previous: $throwable);
        }
    }

    private function storeFailureLog(
        ?Notice $notice,
        string $noticeId,
        ?string $failedStep,
        array $completedSteps,
        Throwable $throwable,
        $startedAt,
    ): void {
        try {
            $context = "notice={$noticeId}";

            if ($completedSteps !== []) {
                $context .= ' completed_steps='.implode(',', $completedSteps);
            }

            if ($failedStep !== null) {
                $context .= " failed_step={$failedStep}";
            }

            $context .= ' error='.$throwable->getMessage();

            SyncLog::query()->create([
                'job_type' => 'process_notice',
                'status' => 'failed',
                'notice_id' => $notice?->id,
                'message' => 'Notice pipeline failed',
                'context' => $context,
                'started_at' => $startedAt,
                'finished_at' => now(),
            ]);
        } catch (Throwable $loggingThrowable) {
            Log::error('Failed to store Doffin notice pipeline failure log.', [
                'notice_id' => $noticeId,
                'logging_error' => $loggingThrowable->getMessage(),
            ]);
        }
    }
}
