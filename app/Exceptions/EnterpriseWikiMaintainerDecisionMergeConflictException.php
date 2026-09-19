<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by EnterpriseWikiMaintainerDecisionMerger when two split-flow batches produce genuinely
 * conflicting output for what is, by identity (EnterpriseWikiConceptIdentityMatcher), the same
 * concept or the same proposed page — e.g. one batch decides "create" and another "exclude" for
 * the same candidate, or two batches propose identity-matched page titles with different slugs.
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

    /**
     * Both proposals are reported in full, because the two titles are frequently NOT the same
     * string — they are matched by EnterpriseWikiConceptIdentityMatcher, which deliberately treats
     * "Deteksjonsutvikling og threat hunting" and "Threat hunting" as one identity. The earlier
     * message named only the second proposal's title and attributed it to both batches, so run 24
     * reported a page ("Threat hunting") that batch 0 had never proposed, and the investigation had
     * to reconstruct the real first title from the persisted batch payloads to get anywhere.
     */
    public static function conflictingPageSlug(
        string $firstTitle,
        string $firstSlug,
        int $firstBatch,
        string $secondTitle,
        string $secondSlug,
        int $secondBatch,
    ): self {
        // Both batch indexes stay lowercase and in the same "batch N" form the candidate-decision
        // message uses, so one grep finds either failure in the logs.
        return new self(
            "Page proposals collided: batch {$firstBatch} proposed \"{$firstTitle}\" with slug ".
            "\"{$firstSlug}\", batch {$secondBatch} proposed \"{$secondTitle}\" with slug ".
            "\"{$secondSlug}\" — conflicting page proposals for the same concept identity."
        );
    }
}
