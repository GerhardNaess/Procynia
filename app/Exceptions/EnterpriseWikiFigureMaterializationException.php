<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a page's required planned_figures still fail
 * EnterpriseWikiPlannedFigureCoverageValidator after the single bounded repair attempt
 * (EnterpriseWikiGenerateAppliedPagesService) — built for the Wiki run-587 incident, where 4 of 4
 * professionally significant figures were extracted, classified, and made citable, but never once
 * materialized on any page.
 *
 * Message-only, matching EnterpriseWikiInvalidWikilinksException/
 * EnterpriseWikiPageGenerationIncompleteException's convention (the job's existing exception
 * handling — GenerateEnterpriseWikiAppliedPage::markPivotFailed() — captures only getMessage(),
 * prefixed with the exception class name, as the pivot's generation_error). No binary image data
 * or raw customer text is included — only source_element_key/classification/section labels
 * already present in maintainer_decision_json's own planned_figures.
 */
class EnterpriseWikiFigureMaterializationException extends RuntimeException
{
    /** @param  list<string>  $failedSourceElementKeys */
    public function __construct(
        public readonly int $runId,
        public readonly int $pageId,
        public readonly string $pageType,
        public readonly array $failedSourceElementKeys,
        public readonly bool $repairAttempted,
        public readonly string $reason,
    ) {
        parent::__construct(sprintf(
            'Run [%d] page [%d] (%s): required planned figure(s) still missing/invalid after%s repair: %s (%s).',
            $runId,
            $pageId,
            $pageType,
            $repairAttempted ? ' one' : ' no',
            implode(', ', $failedSourceElementKeys),
            $reason,
        ));
    }
}
