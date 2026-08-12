<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised before page generation when a required planned section has no deterministic source
 * evidence binding. This is a planning/evidence failure, not an AI generation failure.
 */
class EnterpriseWikiPlannedSectionEvidenceMissingException extends RuntimeException
{
    /** @param list<string> $plannedTopics */
    public function __construct(
        public readonly int $runId,
        public readonly int $pageId,
        public readonly string $pageType,
        public readonly array $plannedTopics,
    ) {
        parent::__construct(sprintf(
            'Run [%d] page [%d] (%s): planned_section_no_evidence before generation: %s.',
            $runId,
            $pageId,
            $pageType,
            implode(', ', $plannedTopics),
        ));
    }
}
