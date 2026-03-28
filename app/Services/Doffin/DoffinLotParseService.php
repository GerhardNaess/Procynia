<?php

namespace App\Services\Doffin;

use App\Models\Notice;
use App\Models\SyncLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class DoffinLotParseService
{
    public function __construct(
        private readonly DoffinLotParser $parser,
    ) {
    }

    public function parseStoredNotice(string $noticeId): array
    {
        $startedAt = now();
        $notice = Notice::query()
            ->with(['rawXml', 'lots'])
            ->where('notice_id', $noticeId)
            ->first();

        try {
            if ($notice === null) {
                throw new RuntimeException("Notice {$noticeId} was not found.");
            }

            if ($notice->rawXml === null) {
                throw new RuntimeException("Notice {$noticeId} has no raw XML stored.");
            }

            $parsedLots = $this->parser->parse($notice->rawXml->xml_content);

            DB::transaction(function () use ($notice, $noticeId, $parsedLots, $startedAt): void {
                $existingLots = $notice->lots()
                    ->get(['id', 'lot_title', 'lot_description']);

                $existingByKey = [];

                foreach ($existingLots as $lot) {
                    $existingByKey[$this->lotKey([
                        'lot_title' => $lot->lot_title,
                        'lot_description' => $lot->lot_description,
                    ])] = $lot;
                }

                $parsedByKey = [];

                foreach ($parsedLots as $lot) {
                    $parsedByKey[$this->lotKey($lot)] = $lot;
                }

                $keysToDelete = array_diff(array_keys($existingByKey), array_keys($parsedByKey));
                $keysToCreate = array_diff(array_keys($parsedByKey), array_keys($existingByKey));

                if ($keysToDelete !== []) {
                    $idsToDelete = array_map(
                        static fn (string $key): int => $existingByKey[$key]->id,
                        $keysToDelete,
                    );

                    $notice->lots()
                        ->whereIn('id', $idsToDelete)
                        ->delete();
                }

                foreach ($parsedByKey as $key => $lot) {
                    if (! array_key_exists($key, $existingByKey)) {
                        continue;
                    }

                    $existingLot = $existingByKey[$key];
                    $normalizedLot = [
                        'lot_title' => $this->normalizeText($lot['lot_title'] ?? null),
                        'lot_description' => $this->normalizeText($lot['lot_description'] ?? null),
                    ];

                    if (
                        $existingLot->lot_title !== $normalizedLot['lot_title']
                        || $existingLot->lot_description !== $normalizedLot['lot_description']
                    ) {
                        $existingLot->fill($normalizedLot);
                        $existingLot->save();
                    }
                }

                foreach ($keysToCreate as $key) {
                    $notice->lots()->create($parsedByKey[$key]);
                }

                SyncLog::query()->create([
                    'job_type' => 'parse_lots',
                    'status' => 'success',
                    'notice_id' => $notice->id,
                    'message' => 'Lot parsing completed',
                    'context' => "Parsed ".count($parsedLots)." lots for notice {$noticeId}",
                    'started_at' => $startedAt,
                    'finished_at' => now(),
                ]);
            });

            Log::info('Parsed Doffin lots successfully.', [
                'notice_id' => $noticeId,
                'lots' => $parsedLots,
            ]);

            return [
                'notice' => $notice->fresh('lots'),
                'lots' => $parsedLots,
                'lot_count' => count($parsedLots),
            ];
        } catch (Throwable $throwable) {
            Log::error('Failed to parse Doffin lots.', [
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
                'job_type' => 'parse_lots',
                'status' => 'failed',
                'notice_id' => $notice?->id,
                'message' => 'Lot parsing failed',
                'context' => $throwable->getMessage(),
                'started_at' => $startedAt,
                'finished_at' => now(),
            ]);
        } catch (Throwable $loggingThrowable) {
            Log::error('Failed to store Doffin lot failure log.', [
                'notice_id' => $noticeId,
                'logging_error' => $loggingThrowable->getMessage(),
            ]);
        }
    }

    private function lotKey(array $lot): string
    {
        return json_encode([
            'lot_title' => $this->normalizeText($lot['lot_title'] ?? null),
            'lot_description' => $this->normalizeText($lot['lot_description'] ?? null),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function normalizeText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = preg_replace('/^[\s\p{Z}\p{Cc}\p{Cf}]+|[\s\p{Z}\p{Cc}\p{Cf}]+$/u', '', $value);

        return $trimmed === null || $trimmed === '' ? null : $trimmed;
    }
}
