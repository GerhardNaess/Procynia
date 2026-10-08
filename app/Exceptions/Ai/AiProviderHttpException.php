<?php

namespace App\Exceptions\Ai;

use RuntimeException;

/**
 * The AI provider answered with an HTTP error. Carries the status so cost settlement can tell a
 * request the provider certainly refused (4xx) from one it may have worked on (408, 5xx) — the
 * message alone is for humans — and any usage the provider still reported, so a failed call that
 * did cost money is priced instead of looking free. A RuntimeException, so existing callers catch
 * it unchanged.
 */
class AiProviderHttpException extends RuntimeException
{
    /** @param array<string, mixed>|null $usage */
    public function __construct(string $message, public readonly int $status, public readonly ?array $usage = null)
    {
        parent::__construct($message);
    }
}
