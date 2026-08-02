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

    /**
     * Wiki run-587 figure pipeline: two different pages (from the global plan and/or different
     * batches — figures are offered identically to every batch, see
     * EnterpriseWikiMaintainerDecisionSplitCoordinator, so no batch is more entitled to a figure
     * than another) each planned the same source_element_key onto themselves. Never resolved by
     * "last writer wins" — a figure belongs to at most one page unless the model gives both pages
     * an explicit, different reason to show it, which this merge step has no way to adjudicate.
     */
    public static function conflictingFigureAssignment(
        string $sourceElementKey,
        string $firstPageTitle,
        string $secondPageTitle,
    ): self {
        return new self(
            "Figure \"{$sourceElementKey}\" was planned onto page \"{$firstPageTitle}\" and also onto ".
            "page \"{$secondPageTitle}\" — conflicting figure placement for the same source element."
        );
    }
}
