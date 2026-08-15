<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A document deletion could not withdraw the document's substance from the active Wiki safely.
 *
 * Always fail-closed: the whole deletion is rolled back and the document stays. A half-withdrawn
 * Wiki — a page emptied of substance, or one still asserting knowledge from a document that no
 * longer exists — is worse than a document that is still there and can be deleted once the affected
 * page has been dealt with deliberately.
 *
 * V1 does not repair these cases automatically. It names them, so we can see how often they actually
 * occur before deciding whether bounded regeneration is worth building.
 */
class EnterpriseWikiWithdrawalNotRepresentableException extends RuntimeException
{
    public static function pageWouldBeEmpty(int $pageId, int $documentId): self
    {
        return new self(sprintf(
            'Deleting document [%d] would leave page [%d] with no substance of its own — only headings or '
            .'cross-references would remain. The deletion has been rolled back: decide what should happen to that '
            .'page first, rather than leaving an empty page behind.',
            $documentId,
            $pageId,
        ));
    }

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
