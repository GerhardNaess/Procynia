<?php

namespace App\Services\Ai;

use App\Exceptions\AiUsageLimitExceededException;
use App\Models\AiUsageEvent;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Purpose: Enforce technical AI safety limits before any AI call or AI job dispatch starts.
 * Inputs: The current customer, user, logical AI operation key and operation count.
 * Returns: None when the operation may continue.
 * Side effects: Increments Redis/cache rate limit counters, stores safe usage events, and logs blocked usage.
 */
class AiUsageGuard
{
    public const LIMIT_TYPE_USER = AiUsageEvent::LIMIT_TYPE_USER;

    public const LIMIT_TYPE_CUSTOMER = AiUsageEvent::LIMIT_TYPE_CUSTOMER;

    public const OPERATION_SAVED_NOTICE_DOCUMENTS_UPLOAD = 'saved_notice_documents_upload';

    public const OPERATION_SAVED_NOTICE_REQUIREMENT_ANSWER_DRAFT = 'saved_notice_requirement_answer_draft';

    public const OPERATION_SAVED_NOTICE_EVIDENCE_REFRESH = 'saved_notice_evidence_refresh';

    public const OPERATION_SAVED_NOTICE_ASSESSMENT_REFRESH = 'saved_notice_assessment_refresh';

    public const OPERATION_KNOWLEDGE_DOCUMENT_UPLOAD = 'knowledge_document_upload';

    public const OPERATION_KNOWLEDGE_CHUNK_METADATA_UPDATE = 'knowledge_chunk_metadata_update';

    public const OPERATION_KNOWLEDGE_VOCABULARY_ANALYSIS_BATCH = 'knowledge_vocabulary_analysis_batch';

    /**
     * Purpose: Verify that the current user and customer may start one more AI operation.
     * Inputs: The current customer, the current user, a canonical operation key, and an optional operation count.
     * Returns: None when the operation is allowed.
     * Side effects: Records safe usage events and throws a controlled exception when a limit is exceeded.
     */
    public function assertCanStartAiOperation(Customer $customer, User $user, string $operationKey, int $operationCount = 1): void
    {
        $operationKey = $this->normalizeOperationKey($operationKey);
        $operationCount = max(1, $operationCount);

        $userLimitPerMinute = max(0, (int) config('procynia.ai.usage_guard.user_per_minute', 5));
        $customerLimitPerHour = max(0, (int) config('procynia.ai.usage_guard.customer_per_hour', 50));
        $userDecaySeconds = max(1, (int) config('procynia.ai.usage_guard.user_decay_seconds', 60));
        $customerDecaySeconds = max(1, (int) config('procynia.ai.usage_guard.customer_decay_seconds', 3600));

        $userKey = $this->userKey((int) $user->id, $operationKey);
        $customerKey = $this->customerKey((int) $customer->id, $operationKey);

        $userLimit = $this->limitExceeded(
            $userKey,
            $userLimitPerMinute,
            $userDecaySeconds,
            $operationCount,
            self::LIMIT_TYPE_USER,
        );

        if ($userLimit !== null) {
            $this->recordBlockedUsageEvent($customer, $user, $operationKey, self::LIMIT_TYPE_USER, $operationCount);
            $this->logBlockedUsage($customer, $user, $operationKey, self::LIMIT_TYPE_USER, $operationCount);

            throw new AiUsageLimitExceededException(
                self::LIMIT_TYPE_USER,
                $userLimit['retry_after_seconds'],
                $operationKey,
                $operationCount,
                (int) $customer->id,
                (int) $user->id,
                $userLimit['message'],
            );
        }

        $customerLimit = $this->limitExceeded(
            $customerKey,
            $customerLimitPerHour,
            $customerDecaySeconds,
            $operationCount,
            self::LIMIT_TYPE_CUSTOMER,
        );

        if ($customerLimit !== null) {
            $this->recordBlockedUsageEvent($customer, $user, $operationKey, self::LIMIT_TYPE_CUSTOMER, $operationCount);
            $this->logBlockedUsage($customer, $user, $operationKey, self::LIMIT_TYPE_CUSTOMER, $operationCount);

            throw new AiUsageLimitExceededException(
                self::LIMIT_TYPE_CUSTOMER,
                $customerLimit['retry_after_seconds'],
                $operationKey,
                $operationCount,
                (int) $customer->id,
                (int) $user->id,
                $customerLimit['message'],
            );
        }

        RateLimiter::increment($userKey, $userDecaySeconds, $operationCount);
        RateLimiter::increment($customerKey, $customerDecaySeconds, $operationCount);

        $this->recordAllowedUsageEvent($customer, $user, $operationKey, $operationCount);
    }

    /**
     * Purpose: Determine whether a logical rate limit would be exceeded by one AI operation request.
     * Inputs: The rate limit bucket key, the maximum allowed count, the decay window, the requested count and limit type.
     * Returns: A retry payload when the request must be blocked, or null when the request is still allowed.
     * Side effects: None.
     *
     * @return array{retry_after_seconds:int,message:string}|null
     */
    private function limitExceeded(
        string $key,
        int $limit,
        int $decaySeconds,
        int $operationCount,
        string $limitType,
    ): ?array {
        $currentAttempts = RateLimiter::attempts($key);

        if (($currentAttempts + $operationCount) <= $limit) {
            return null;
        }

        $retryAfterSeconds = RateLimiter::availableIn($key);

        if ($retryAfterSeconds <= 0) {
            $retryAfterSeconds = $decaySeconds;
        }

        return [
            'retry_after_seconds' => $retryAfterSeconds,
            'message' => $this->blockedMessage($limitType, $retryAfterSeconds),
        ];
    }

    /**
     * Purpose: Build the user-facing message for a blocked AI operation.
     * Inputs: The limit type and the suggested retry window in seconds.
     * Returns: A controlled localized message without technical rate-limit terminology.
     * Side effects: None.
     */
    private function blockedMessage(string $limitType, int $retryAfterSeconds): string
    {
        $message = $limitType === self::LIMIT_TYPE_CUSTOMER
            ? __('procynia.ai.usage_guard.customer_blocked_base')
            : __('procynia.ai.usage_guard.user_blocked_base');

        $message .= ' '.$this->retryMessage($retryAfterSeconds);

        if ($limitType === self::LIMIT_TYPE_CUSTOMER) {
            $message .= ' '.__('procynia.ai.usage_guard.customer_blocked_active_bid_hint');
        }

        return trim($message);
    }

    /**
     * Purpose: Build the retry sentence for a blocked AI operation.
     * Inputs: The retry delay in seconds.
     * Returns: A localized retry sentence with coarse time guidance.
     * Side effects: None.
     */
    private function retryMessage(int $retryAfterSeconds): string
    {
        if ($retryAfterSeconds < 60) {
            return __('procynia.ai.usage_guard.retry_short');
        }

        if ($retryAfterSeconds < 3600) {
            return __('procynia.ai.usage_guard.retry_minutes', [
                'minutes' => max(1, (int) ceil($retryAfterSeconds / 60)),
            ]);
        }

        return __('procynia.ai.usage_guard.retry_hours', [
            'hours' => max(1, (int) ceil($retryAfterSeconds / 3600)),
        ]);
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
     * Purpose: Persist a safe usage event for a blocked AI operation.
     * Inputs: The current customer, user, operation key, limit type and logical operation count.
     * Returns: None.
     * Side effects: Stores a non-sensitive usage event row when the database is available.
     */
    private function recordBlockedUsageEvent(Customer $customer, User $user, string $operationKey, string $limitType, int $operationCount): void
    {
        $this->recordUsageEvent(
            $customer,
            $user,
            $operationKey,
            AiUsageEvent::STATUS_BLOCKED,
            $limitType,
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
     * Purpose: Write a safe log line when an AI operation is blocked by a technical usage limit.
     * Inputs: The current customer, user, canonical operation key, limit type and operation count.
     * Returns: None.
     * Side effects: Writes a non-sensitive warning log line.
     */
    private function logBlockedUsage(Customer $customer, User $user, string $operationKey, string $limitType, int $operationCount): void
    {
        Log::warning('[PROCYNIA][AI_USAGE_GUARD] AI operation blocked.', [
            'customer_id' => (int) $customer->id,
            'user_id' => (int) $user->id,
            'operation_key' => $operationKey,
            'limit_type' => $limitType,
            'operation_count' => max(1, $operationCount),
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

    /**
     * Purpose: Build the Redis/cache key for the per-customer AI usage bucket.
     * Inputs: The customer id and the canonical operation key.
     * Returns: The cache key used by Laravel RateLimiter.
     * Side effects: None.
     */
    private function customerKey(int $customerId, string $operationKey): string
    {
        return sprintf('ai:customer:%d:%s', $customerId, $operationKey);
    }
}
