<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A document deletion could not withdraw the document's substance from the active Wiki safely.
 *
 * Always fail-closed: the whole deletion is rolled back and the document stays. A half-withdrawn
 * Wiki — one still asserting knowledge from a document that no longer exists, or carrying a version
 * its own invariants reject — is worse than a document that is still there.
 *
 * Reserved for genuine integrity failures. A page left with no substance of its own is NOT one of
 * them: that page is deleted with the document, because current state is what decides what the Wiki
 * holds.
 */
class EnterpriseWikiWithdrawalNotRepresentableException extends RuntimeException
{
    public static function writerRejectedVersion(int $pageId, string $operation, Throwable $previous): self
    {
        return new self(
            sprintf(
                'Withdrawal (%s) could not write a new current version for page [%d]: %s The deletion has been '
                .'rolled back.',
                $operation,
                $pageId,
                $previous->getMessage(),
            ),
            0,
            $previous,
        );
    }

    /** @param list<string> $violations */
    public static function activeWikiNotClean(int $documentId, array $violations): self
    {
        return new self(sprintf(
            'After withdrawing document [%d] the active Wiki would still reference it or the pages it produced: %s. '
            .'The deletion has been rolled back.',
            $documentId,
            implode('; ', $violations),
        ));
    }
}
