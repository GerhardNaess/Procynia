<?php

namespace App\Services\Health;

use App\Models\RequirementExtractionRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class DocumentHealthService
{
    /**
     * Purpose: Create the document parsing health service.
     * Inputs: None.
     * Returns: A ready-to-use service instance.
     * Side effects: None.
     */
    public function __construct()
    {
    }

    /**
     * Purpose: Check whether document parsing is currently healthy or has recent failures.
     * Inputs: None.
     * Returns: A normalized health payload for the document parsing endpoint.
     * Side effects: Reads requirement extraction runs and failed-job rows.
     *
     * @return array<string, mixed>
     */
    public function documentParsing(): array
    {
        $checkedAt = now();

        try {
            $lastSuccessRun = RequirementExtractionRun::query()
                ->where('status', RequirementExtractionRun::STATUS_COMPLETED)
                ->whereNotNull('finished_at')
                ->orderByDesc('finished_at')
                ->orderByDesc('id')
                ->first();

            $lastSuccessAt = $lastSuccessRun?->finished_at;
            $failedCountLast60Minutes = $this->failedDocumentParsingCount($checkedAt->copy()->subMinutes(60));

            if ($failedCountLast60Minutes > 0) {
                return [
                    'status' => 'fail',
                    'service' => 'document_parsing',
                    'checked_at' => $checkedAt->toIso8601String(),
                    'last_success_at' => $lastSuccessAt?->toIso8601String(),
                    'failed_count_last_60_minutes' => $failedCountLast60Minutes,
                    'message' => 'Recent document parsing jobs failed.',
                ];
            }

            if (! $lastSuccessAt instanceof Carbon) {
                return [
                    'status' => 'ok',
                    'service' => 'document_parsing',
                    'checked_at' => $checkedAt->toIso8601String(),
                    'last_success_at' => null,
                    'failed_count_last_60_minutes' => 0,
                    'message' => 'No recent document parsing activity',
                ];
            }

            return [
                'status' => 'ok',
                'service' => 'document_parsing',
                'checked_at' => $checkedAt->toIso8601String(),
                'last_success_at' => $lastSuccessAt->toIso8601String(),
                'failed_count_last_60_minutes' => 0,
                'message' => 'Document parsing is healthy.',
            ];
        } catch (Throwable) {
            return [
                'status' => 'fail',
                'service' => 'document_parsing',
                'checked_at' => $checkedAt->toIso8601String(),
                'last_success_at' => null,
                'failed_count_last_60_minutes' => 0,
                'message' => 'Document parsing status source is not available',
            ];
        }
    }

    /**
     * Purpose: Count failed extraction jobs from the last sixty minutes.
     * Inputs: The lower bound timestamp for the search window.
     * Returns: The number of recent failed document parsing jobs.
     * Side effects: Reads the canonical failed jobs table.
     */
    private function failedDocumentParsingCount(Carbon $since): int
    {
        $table = (string) config('queue.failed.table', 'failed_jobs');

        return (int) DB::table($table)
            ->where('failed_at', '>=', $since)
            ->where(function ($query): void {
                $query->where('payload', 'like', '%ProcessRequirementExtractionRun%')
                    ->orWhere('payload', 'like', '%requirement_extraction%')
                    ->orWhere('exception', 'like', '%ProcessRequirementExtractionRun%');
            })
            ->count();
    }
}
