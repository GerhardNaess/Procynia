<?php

namespace App\Services\Ai;

use App\Models\AiUsageEvent;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Purpose: Record AI usage and surface unusually high per-user tempo as a warning.
 * Inputs: The current customer, user, logical AI operation key and operation count.
 * Returns: An optional user-facing warning when the user exceeds the configured tempo threshold.
 * Side effects: Increments Redis/cache rate limit counters, stores safe usage events, and logs unusual tempo.
 */
class AiUsageGuard
{
    public const LIMIT_TYPE_USER = AiUsageEvent::LIMIT_TYPE_USER;

    public const LIMIT_TYPE_CUSTOMER = AiUsageEvent::LIMIT_TYPE_CUSTOMER;

    public const LIMIT_TYPE_MONTHLY_BUDGET = AiUsageEvent::LIMIT_TYPE_MONTHLY_BUDGET;

    public const OPERATION_SAVED_NOTICE_DOCUMENTS_UPLOAD = 'saved_notice_documents_upload';

    public const OPERATION_SAVED_NOTICE_REQUIREMENT_ANSWER_DRAFT = 'saved_notice_requirement_answer_draft';

    public const OPERATION_SAVED_NOTICE_EVIDENCE_REFRESH = 'saved_notice_evidence_refresh';

    public const OPERATION_SAVED_NOTICE_ASSESSMENT_REFRESH = 'saved_notice_assessment_refresh';

    public const OPERATION_KNOWLEDGE_DOCUMENT_UPLOAD = 'knowledge_document_upload';

    public const OPERATION_KNOWLEDGE_CHUNK_METADATA_UPDATE = 'knowledge_chunk_metadata_update';

    public const OPERATION_KNOWLEDGE_VOCABULARY_ANALYSIS_BATCH = 'knowledge_vocabulary_analysis_batch';

    /**
     * Purpose: Record the current AI operation and return an optional high-tempo warning.
     * Inputs: The current customer, the current user, a canonical operation key, and an optional operation count.
     * Returns: A warning message when the user exceeds the configured per-minute tempo threshold, or null otherwise.
     * Side effects: Increments rate limit counters, stores safe usage events, and logs unusual tempo.
     */
    public function assertCanStartAiOperation(Customer $customer, User $user, string $operationKey, int $operationCount = 1): ?string
    {
        $operationKey = $this->normalizeOperationKey($operationKey);
        $operationCount = max(1, $operationCount);

        $userLimitPerMinute = max(0, (int) config('procynia.ai.usage_guard.user_per_minute', 5));
        $userDecaySeconds = max(1, (int) config('procynia.ai.usage_guard.user_decay_seconds', 60));
        $userKey = $this->userKey((int) $user->id, $operationKey);

        $warningMessage = null;
        $currentUserAttempts = RateLimiter::attempts($userKey);

        if ($userLimitPerMinute > 0 && (($currentUserAttempts + $operationCount) > $userLimitPerMinute)) {
            $warningMessage = __('procynia.ai.usage_guard.user_high_tempo_warning', [
                'limit' => $userLimitPerMinute,
            ]);

            $this->logHighTempoUsage($customer, $user, $operationKey, $operationCount, $currentUserAttempts, $userLimitPerMinute);
        }

        RateLimiter::increment($userKey, $userDecaySeconds, $operationCount);

        $this->recordAllowedUsageEvent($customer, $user, $operationKey, $operationCount);

        return $warningMessage;
    }

    /**
     * Purpose: Persist a safe usage event for an allowed AI operation.
     * Inputs: The current customer, user, operation key and logical operation count.
     * Returns: None.
     * Side effects: Stores a non-sensitive usage event row when the database is available.
     */
    private function recordAllowedUsageEvent(Customer $customer, User $user, string $operationKey, int $operationCount): void
    {
        $this->recordUsageEvent(
            $customer,
            $user,
            $operationKey,
            AiUsageEvent::STATUS_ALLOWED,
            null,
            $operationCount,
        );
    }

    /**
     * Purpose: Persist a non-sensitive AI usage event without letting logging failures block the AI operation itself.
     * Inputs: The current customer, user, canonical operation key, status, limit type and operation count.
     * Returns: None.
     * Side effects: Writes one ai_usage_events row when possible and swallows persistence failures safely.
     */
    private function recordUsageEvent(
        Customer $customer,
        User $user,
        string $operationKey,
        string $status,
        ?string $limitType,
        int $operationCount,
    ): void {
        try {
            AiUsageEvent::query()->create([
                'customer_id' => (int) $customer->id,
                'user_id' => (int) $user->id,
                'operation_key' => $operationKey,
                'status' => $status,
                'limit_type' => $limitType,
                'operation_count' => max(1, $operationCount),
            ]);
        } catch (Throwable) {
            Log::warning('[PROCYNIA][AI_USAGE_GUARD] AI usage event could not be stored.', [
                'customer_id' => (int) $customer->id,
                'user_id' => (int) $user->id,
                'operation_key' => $operationKey,
                'status' => $status,
                'limit_type' => $limitType,
                'operation_count' => max(1, $operationCount),
            ]);
        }
    }

    /**
     * Purpose: Write a safe log line when an AI operation shows unusually high tempo.
     * Inputs: The current customer, user, canonical operation key, operation count, current attempts and configured limit.
     * Returns: None.
     * Side effects: Writes a non-sensitive warning log line.
     */
    private function logHighTempoUsage(
        Customer $customer,
        User $user,
        string $operationKey,
        int $operationCount,
        int $currentAttempts,
        int $userLimitPerMinute,
    ): void
    {
        Log::warning('[PROCYNIA][AI_USAGE_GUARD] AI operation shows unusually high tempo.', [
            'customer_id' => (int) $customer->id,
            'user_id' => (int) $user->id,
            'operation_key' => $operationKey,
            'operation_count' => max(1, $operationCount),
            'current_attempts' => $currentAttempts,
            'user_limit_per_minute' => $userLimitPerMinute,
        ]);
    }

    /**
     * Purpose: Normalize the canonical operation key used for rate-limiting and usage logging.
     * Inputs: The raw operation key string from the caller.
     * Returns: A trimmed operation key or a safe fallback when the caller provides an empty value.
     * Side effects: None.
     */
    private function normalizeOperationKey(string $operationKey): string
    {
        $normalized = trim($operationKey);

        return $normalized !== '' ? $normalized : 'unknown';
    }

    /**
     * Purpose: Build the Redis/cache key for the per-user AI usage bucket.
     * Inputs: The authenticated user id and the canonical operation key.
     * Returns: The cache key used by Laravel RateLimiter.
     * Side effects: None.
     */
    private function userKey(int $userId, string $operationKey): string
    {
        return sprintf('ai:user:%d:%s', $userId, $operationKey);
    }

}
