<?php

namespace App\Support\Ai;

use App\Exceptions\Ai\AiProviderHttpException;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

/**
 * One rule for "may the provider have done — and charged for — this failed call?", shared by the
 * usage meter (settlement of the attempt) and cost control (the NOK hold and the Anbud credit), so
 * the two can never disagree about the same call.
 *
 * Uncertain: a timeout, a connection that broke after the request may have been sent, HTTP 408
 * and every 5xx. Certain: any other 4xx (including 429) — the provider refused before working.
 */
final class AiProviderFailure
{
    public static function isUncertain(Throwable $exception): bool
    {
        if ($exception instanceof AiProviderHttpException) {
            return self::isUncertainStatus($exception->status);
        }

        $message = mb_strtolower($exception->getMessage(), 'UTF-8');

        return $exception instanceof ConnectionException
            || str_contains($message, 'timed out')
            || str_contains($message, 'timeout');
    }

    public static function isUncertainStatus(int $status): bool
    {
        return $status === 408 || $status >= 500;
    }
}
