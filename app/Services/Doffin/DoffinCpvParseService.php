<?php

namespace App\Services\Doffin;

use App\Models\Notice;
use App\Models\SyncLog;
use App\Support\CpvCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class DoffinCpvParseService
{
    public function __construct(
        private readonly DoffinCpvParser $parser,
        private readonly CpvCatalog $cpvCatalog,
    ) {
    }

    public function parseStoredNotice(string $noticeId): array
    {
        $startedAt = now();
        $notice = Notice::query()
            ->with(['rawXml', 'cpvCodes'])
            ->where('notice_id', $noticeId)
            ->first();

        try {
            if ($notice === null) {
                throw new RuntimeException("Notice {$noticeId} was not found.");
            }

            if ($notice->rawXml === null) {
                throw new RuntimeException("Notice {$noticeId} has no raw XML stored.");
            }

            $parsedCodes = $this->parser->parse($notice->rawXml->xml_content);

            DB::transaction(function () use ($notice, $noticeId, $parsedCodes, $startedAt): void {
                $existingCodes = $notice->cpvCodes()
                    ->pluck('cpv_code')
                    ->all();

                $codesToDelete = array_values(array_diff($existingCodes, $parsedCodes));

                if ($codesToDelete !== []) {
                    $notice->cpvCodes()
                        ->whereIn('cpv_code', $codesToDelete)
                        ->delete();
                }

                foreach ($parsedCodes as $code) {
                    $notice->cpvCodes()->updateOrCreate(
                        ['cpv_code' => $code],
                        $this->cpvDescriptionAttributes($code),
                    );
                }

                SyncLog::query()->create([
                    'job_type' => 'parse_cpv',
                    'status' => 'success',
                    'notice_id' => $notice->id,
                    'message' => 'CPV parsing completed',
                    'context' => "Parsed ".count($parsedCodes)." CPV codes for notice {$noticeId}",
                    'started_at' => $startedAt,
                    'finished_at' => now(),
                ]);
            });

            Log::info('Parsed Doffin CPV codes successfully.', [
                'notice_id' => $noticeId,
                'cpv_codes' => $parsedCodes,
            ]);

            return [
                'notice' => $notice->fresh('cpvCodes'),
                'cpv_codes' => $parsedCodes,
                'cpv_count' => count($parsedCodes),
            ];
        } catch (Throwable $throwable) {
            Log::error('Failed to parse Doffin CPV codes.', [
                'notice_id' => $noticeId,
                'notice_row_id' => $notice?->id,
                'error' => $throwable->getMessage(),
            ]);

            $this->storeFailureLog($notice, $noticeId, $throwable, $startedAt);

            throw $throwable;
        }
    }

    private function storeFailureLog(?Notice $notice, string $noticeId, Throwable $throwable, $startedAt): void
    {
        try {
            SyncLog::query()->create([
                'job_type' => 'parse_cpv',
                'status' => 'failed',
                'notice_id' => $notice?->id,
                'message' => 'CPV parsing failed',
                'context' => $throwable->getMessage(),
                'started_at' => $startedAt,
                'finished_at' => now(),
            ]);
        } catch (Throwable $loggingThrowable) {
            Log::error('Failed to store Doffin CPV failure log.', [
                'notice_id' => $noticeId,
                'logging_error' => $loggingThrowable->getMessage(),
            ]);
        }
    }

    private function cpvDescriptionAttributes(string $code): array
    {
        return [
            'cpv_description_en' => $this->cpvCatalog->english($code),
            'cpv_description_no' => $this->cpvCatalog->norwegian($code),
        ];
    }
}
