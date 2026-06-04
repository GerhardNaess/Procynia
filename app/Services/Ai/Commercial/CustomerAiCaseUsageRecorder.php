<?php

namespace App\Services\Ai\Commercial;

use App\Models\CustomerAiCaseUsage;
use App\Models\SavedNotice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Purpose: Record AI-active SavedNotice cases without blocking the underlying AI flow.
 * Inputs: A SavedNotice, the source operation key, optional user and source event references, and an optional activation time.
 * Returns: The persisted case usage row, or null when persistence fails.
 * Side effects: Writes customer_ai_case_usages rows and logs warnings on failure.
 */
class CustomerAiCaseUsageRecorder
{
    /**
     * Purpose: Persist one case usage row for the SavedNotice and calendar month.
     * Inputs: The SavedNotice, source operation key, optional activated-by user, optional source event ids, and optional activation time.
     * Returns: The existing or newly created case usage row, or null when persistence fails.
     * Side effects: Writes one customer_ai_case_usages row at most once per customer, SavedNotice, and month.
     */
    public function record(
        SavedNotice $savedNotice,
        string $sourceOperationKey,
        ?User $activatedByUser = null,
        ?int $sourceAiUsageEventId = null,
        ?int $sourceAiTokenEventId = null,
        ?CarbonInterface $activatedAt = null,
    ): ?CustomerAiCaseUsage
    {
        $customerId = (int) ($savedNotice->customer_id ?? 0);
        $savedNoticeId = (int) ($savedNotice->id ?? 0);

        if ($customerId <= 0 || $savedNoticeId <= 0) {
            Log::warning('[PROCYNIA][AI_CASE_USAGE] Skipped case usage recording because the SavedNotice or customer context was missing.', [
                'saved_notice_id' => $savedNoticeId > 0 ? $savedNoticeId : null,
                'customer_id' => $customerId > 0 ? $customerId : null,
                'source_operation_key' => trim($sourceOperationKey) !== '' ? trim($sourceOperationKey) : 'unknown',
            ]);

            return null;
        }

        $sourceOperationKey = trim($sourceOperationKey) !== '' ? trim($sourceOperationKey) : 'unknown';
        $activatedByUserId = $activatedByUser?->getKey();
        $activatedByUserId = is_numeric($activatedByUserId) && (int) $activatedByUserId > 0
            ? (int) $activatedByUserId
            : null;
        $sourceAiUsageEventId = $sourceAiUsageEventId !== null && $sourceAiUsageEventId > 0 ? $sourceAiUsageEventId : null;
        $sourceAiTokenEventId = $sourceAiTokenEventId !== null && $sourceAiTokenEventId > 0 ? $sourceAiTokenEventId : null;

        $activationMoment = CarbonImmutable::instance($activatedAt ?? now())
            ->setTimezone(config('app.timezone') ?: 'UTC');

        $lookup = [
            'customer_id' => $customerId,
            'saved_notice_id' => $savedNoticeId,
            'period_start' => $activationMoment->startOfMonth()->toDateString(),
            'period_end' => $activationMoment->endOfMonth()->toDateString(),
        ];

        try {
            return DB::transaction(function () use (
                $lookup,
                $activationMoment,
                $activatedByUserId,
                $sourceOperationKey,
                $sourceAiUsageEventId,
                $sourceAiTokenEventId,
            ): CustomerAiCaseUsage {
                return CustomerAiCaseUsage::query()->firstOrCreate(
                    $lookup,
                    [
                        'activated_at' => $activationMoment,
                        'activated_by_user_id' => $activatedByUserId,
                        'source_operation_key' => $sourceOperationKey,
                        'source_ai_usage_event_id' => $sourceAiUsageEventId,
                        'source_ai_token_event_id' => $sourceAiTokenEventId,
                    ],
                );
            });
        } catch (UniqueConstraintViolationException) {
            $existing = CustomerAiCaseUsage::query()->where($lookup)->first();

            if ($existing instanceof CustomerAiCaseUsage) {
                return $existing;
            }

            Log::warning('[PROCYNIA][AI_CASE_USAGE] Unique constraint violation occurred, but the case usage row could not be reloaded.', [
                'customer_id' => $customerId,
                'saved_notice_id' => $savedNoticeId,
                'period_start' => $lookup['period_start'],
                'period_end' => $lookup['period_end'],
                'source_operation_key' => $sourceOperationKey,
            ]);

            return null;
        } catch (Throwable $throwable) {
            Log::warning('[PROCYNIA][AI_CASE_USAGE] Failed to record AI case usage.', [
                'customer_id' => $customerId,
                'saved_notice_id' => $savedNoticeId,
                'period_start' => $lookup['period_start'],
                'period_end' => $lookup['period_end'],
                'source_operation_key' => $sourceOperationKey,
                'error' => $throwable->getMessage(),
            ]);

            return null;
        }
    }
}
