<?php

namespace App\Services\EnterpriseWiki;

/**
 * Deterministic, conservative classification of whether a recorded error message describes a
 * TEMPORARY condition (safe to auto-retry later) or a PERMANENT one (a real defect that must not
 * be silently retried forever). Built for EnterpriseWikiEscalatedRunRecoveryService: an escalated
 * run whose qa_status flipped to `failed` because a repair attempt hit an OpenAI rate-limit/quota
 * error (see the Wiki run-585 incident — `[DEEP_REPAIR] OpenAI request ... HTTP status [429]:
 * insufficient_quota`) must remain recoverable, while one that failed on a genuine schema or logic
 * defect must not.
 *
 * No exception in this codebase carries a structured HTTP status code today (OpenAiClient throws
 * a plain RuntimeException with the status embedded in its message — see OpenAiClient::send()),
 * so this works off the persisted message text. Deliberately narrow and allow-list based: only
 * markers confidently known to indicate a transient condition count; anything else — including an
 * unrecognised message — defaults to NOT transient, so an unknown failure is never silently
 * treated as safe to retry.
 */
class EnterpriseWikiTransientFailureClassifier
{
    /** @var string[] */
    private const TRANSIENT_MARKERS = [
        'http status [429]',
        'insufficient_quota',
        'credit_balance_exhausted',
        'rate_limit',
        'rate limit',
        'too many requests',
        'timed out',
        'timeout',
        'connection reset',
        'connection refused',
        'could not resolve host',
        'server_error',
        'http status [500]',
        'http status [502]',
        'http status [503]',
        'http status [504]',
    ];

    public static function isTransient(?string $message): bool
    {
        if ($message === null || trim($message) === '') {
            return false;
        }

        $normalized = mb_strtolower($message);

        foreach (self::TRANSIENT_MARKERS as $marker) {
            if (str_contains($normalized, $marker)) {
                return true;
            }
        }

        return false;
    }
}
