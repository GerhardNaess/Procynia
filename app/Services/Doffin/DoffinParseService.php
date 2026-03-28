<?php

namespace App\Services\Doffin;

use App\Models\Notice;
use App\Models\SyncLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class DoffinParseService
{
    public function __construct(
        private readonly DoffinXmlParser $parser,
    ) {
    }

    public function parseStoredNotice(string $noticeId): array
    {
        $startedAt = now();
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

            $parsed = $this->parser->parse($notice->rawXml->xml_content);
            $filledFields = array_keys(array_filter($parsed, static fn (mixed $value): bool => $value !== null));

            $notice = DB::transaction(function () use ($notice, $parsed, $filledFields, $noticeId, $startedAt) {
                $finishedAt = now();

                $notice->fill($parsed);
                $notice->parsed_at = $finishedAt;
                $notice->save();

                SyncLog::query()->create([
                    'job_type' => 'parse',
                    'status' => 'success',
                    'notice_id' => $notice->id,
                    'message' => "Parsed notice {$noticeId}.",
                    'context' => $this->successContext($filledFields),
                    'started_at' => $startedAt,
                    'finished_at' => $finishedAt,
                ]);

                return $notice->fresh();
            });

            Log::info('Parsed stored Doffin notice successfully.', [
                'notice_id' => $noticeId,
                'filled_fields' => $filledFields,
            ]);

            return [
                'notice' => $notice,
                'updated_fields' => $filledFields,
            ];
        } catch (Throwable $throwable) {
            Log::error('Failed to parse stored Doffin notice.', [
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
                'job_type' => 'parse',
                'status' => 'failed',
                'notice_id' => $notice?->id,
                'message' => "Failed to parse notice {$noticeId}.",
                'context' => $throwable->getMessage(),
                'started_at' => $startedAt,
                'finished_at' => now(),
            ]);
        } catch (Throwable $loggingThrowable) {
            Log::error('Failed to store Doffin parse failure log.', [
                'notice_id' => $noticeId,
                'logging_error' => $loggingThrowable->getMessage(),
            ]);
        }
    }

    private function successContext(array $filledFields): string
    {
        if ($filledFields === []) {
            return 'filled_fields=none';
        }

        return 'filled_fields='.implode(',', $filledFields);
    }
}
