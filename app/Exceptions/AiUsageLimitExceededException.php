<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Purpose: Carry a controlled AI usage guard failure back to web and JSON requests.
 * Inputs: The blocked AI operation context and a user-facing message.
 * Returns: A renderable runtime exception that Laravel can convert into a response.
 * Side effects: None.
 */
class AiUsageLimitExceededException extends RuntimeException
{
    public function __construct(
        private readonly string $limitType,
        private readonly int $retryAfterSeconds,
        private readonly string $operationKey,
        private readonly int $operationCount,
        private readonly ?int $customerId,
        private readonly ?int $userId,
        string $userMessage,
    ) {
        parent::__construct($userMessage);
    }

    /**
     * Purpose: Render the blocked AI usage response for web and JSON requests.
     * Inputs: The current request.
     * Returns: A JSON 429 response for API requests or a redirect back with a warning flash for browser requests.
     * Side effects: None.
     */
    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'fail',
                'message' => $this->getMessage(),
            ], 429);
        }

        return back()->with('warning', $this->getMessage());
    }

    /**
     * Purpose: Retrieve the limit type that blocked the AI operation.
     * Inputs: None.
     * Returns: The canonical limit type string.
     * Side effects: None.
     */
    public function limitType(): string
    {
        return $this->limitType;
    }

    /**
     * Purpose: Retrieve the retry delay suggested for the blocked AI operation.
     * Inputs: None.
     * Returns: The number of seconds until the next retry should be attempted.
     * Side effects: None.
     */
    public function retryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }

    /**
     * Purpose: Retrieve the canonical operation key that triggered the guard.
     * Inputs: None.
     * Returns: The operation key used for the rate limit buckets.
     * Side effects: None.
     */
    public function operationKey(): string
    {
        return $this->operationKey;
    }

    /**
     * Purpose: Retrieve the logical AI operation count for the blocked request.
     * Inputs: None.
     * Returns: The number of logical AI operations represented by the request.
     * Side effects: None.
     */
    public function operationCount(): int
    {
        return $this->operationCount;
    }

    /**
     * Purpose: Retrieve the customer id associated with the blocked operation.
     * Inputs: None.
     * Returns: The customer id or null when no customer context was available.
     * Side effects: None.
     */
    public function customerId(): ?int
    {
        return $this->customerId;
    }

    /**
     * Purpose: Retrieve the user id associated with the blocked operation.
     * Inputs: None.
     * Returns: The user id or null when no user context was available.
     * Side effects: None.
     */
    public function userId(): ?int
    {
        return $this->userId;
    }

    /**
     * Purpose: Retrieve the user-facing message for the blocked operation.
     * Inputs: None.
     * Returns: The controlled and localized message that should be shown to the user.
     * Side effects: None.
     */
    public function userMessage(): string
    {
        return $this->getMessage();
    }
}
