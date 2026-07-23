<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by EnterpriseWikiOrphanConceptLinkService::linkConceptToTarget() for every rejection
 * that is a caller/data problem rather than an unexpected failure — the `reason` lets
 * WikiController map each case to a specific, non-generic redirect/flash message instead of
 * string-matching the exception text (the pattern EnterpriseWikiClaimContentRepairService's
 * callers were forced into for lack of a dedicated exception).
 */
class EnterpriseWikiOrphanConceptLinkException extends RuntimeException
{
    public const REASON_UNAUTHORIZED = 'unauthorized';

    public const REASON_TARGET_NOT_FOUND = 'target_not_found';

    public const REASON_INVALID_TARGET_TYPE = 'invalid_target_type';

    public const REASON_STALE_VERSION = 'stale_version';

    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function unauthorized(): self
    {
        return new self('User is not authorized to edit this Wiki page.', self::REASON_UNAUTHORIZED);
    }

    public static function targetNotFound(int $targetPageId): self
    {
        return new self("Target page [{$targetPageId}] does not exist or belongs to a different customer.", self::REASON_TARGET_NOT_FOUND);
    }

    public static function invalidTargetType(string $pageType): self
    {
        return new self("Target page has page_type [{$pageType}]; only article/summary targets satisfy orphan_concept_page.", self::REASON_INVALID_TARGET_TYPE);
    }

    public static function staleVersion(): self
    {
        return new self('The concept page has changed since the panel was opened.', self::REASON_STALE_VERSION);
    }
}
