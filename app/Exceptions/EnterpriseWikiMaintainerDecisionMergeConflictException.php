<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by EnterpriseWikiMaintainerDecisionMerger when two split-flow batches produce genuinely
 * conflicting output for what is, by identity (EnterpriseWikiConceptIdentityMatcher), the same
 * concept or the same proposed page — e.g. one batch decides "create" and another "exclude" for
 * the same candidate, or two batches propose the same page title with different slugs.
 *
 * Deliberately never resolved by guessing or "last writer wins" — batches are supposed to be
 * partitioned by distinct candidate names, so a real collision here indicates either a
 * mis-partitioned batch or the model treating the same concept as two different candidates. The
 * caller (EnterpriseWikiMaintainerDecisionSplitCoordinator) lets this propagate and abort the
 * whole decision — no partial merge is ever persisted.
 */
class EnterpriseWikiMaintainerDecisionMergeConflictException extends RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function conflictingCandidateDecision(
        string $name,
        string $firstDecision,
        int $firstBatch,
        string $secondDecision,
        int $secondBatch,
    ): self {
        return new self(
            "Concept candidate \"{$name}\" was decided \"{$firstDecision}\" in batch {$firstBatch} but ".
            "\"{$secondDecision}\" in batch {$secondBatch} — conflicting batch decisions for the same concept."
        );
    }

    public static function conflictingPageSlug(
        string $title,
        string $firstSlug,
        int $firstBatch,
        string $secondSlug,
        int $secondBatch,
    ): self {
        return new self(
            "Page \"{$title}\" was proposed with slug \"{$firstSlug}\" in batch {$firstBatch} but ".
            "slug \"{$secondSlug}\" in batch {$secondBatch} — conflicting page proposals for the same title."
        );
    }
}
