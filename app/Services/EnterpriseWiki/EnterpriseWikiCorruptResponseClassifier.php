<?php

namespace App\Services\EnterpriseWiki;

use App\Exceptions\EnterpriseWikiInvalidUtf8Exception;
use App\Services\Ai\Wiki\Responses\Exceptions\EnterpriseWikiResponseInvalidJsonException;
use Throwable;

/**
 * One question, asked in one place: is this failure the response being UNUSABLE, rather than the
 * decision inside it being wrong?
 *
 * An unusable response is a transmission fault — raw control bytes where characters belong, invalid
 * UTF-8, output that is not JSON at all. No amount of re-prompting logic can reason about it, and
 * the same call repeated usually succeeds. A wrong decision is the opposite: repeating it repeats
 * the mistake, so it must propagate to a validator or a bounded semantic repair instead.
 *
 * This exists because the system had grown THREE answers to that one question, on three paths:
 * EnterpriseWikiUtf8Guard on generation, validateNoControlCharacters on planning, and a
 * corrupted-response retry loop that lived on the in-process candidate-batch path only. Run 60
 * failed hard on control characters in a queued candidate batch — the guard existed, it just was
 * not on the path production uses. A policy that depends on which caller you came through is not a
 * policy.
 *
 * The detectors stay where they are (they are the only places that know what a valid decision or a
 * valid block looks like). What moved is the RESPONSE: one bounded retry, then hard fail, applied
 * by EnterpriseWikiAiCapacityRetryExecutor to every call that goes through it.
 */
class EnterpriseWikiCorruptResponseClassifier
{
    /**
     * The marker validateNoControlCharacters() puts in its message. Matched as a string because the
     * detector reports many field-level errors in one exception rather than throwing a dedicated
     * type per field.
     */
    public const CONTROL_CHARACTER_MARKER = 'contains an invalid control character — the AI response text is corrupted';

    public static function isCorrupt(Throwable $failure): bool
    {
        return $failure instanceof EnterpriseWikiInvalidUtf8Exception
            || $failure instanceof EnterpriseWikiResponseInvalidJsonException
            || str_contains($failure->getMessage(), self::CONTROL_CHARACTER_MARKER);
    }
}
