<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a generated Enterprise Wiki page still has one or more planned/owned-topic
 * sections missing, empty, or link-only after the single bounded repair attempt
 * (EnterpriseWikiGenerateAppliedPagesService) — built for the Wiki run-586 incident, where a
 * concept page was persisted with two `## ` headings and zero body text under either.
 *
 * Message-only, matching EnterpriseWikiInvalidWikilinksException's convention (the job's existing
 * exception handling — GenerateEnterpriseWikiAppliedPage::markPivotFailed() — captures only
 * getMessage(), prefixed with the exception class name, as the pivot's generation_error). No raw
 * customer/document text is included — only short topic labels already present in
 * maintainer_decision_json's own owned_topics.
 */
class EnterpriseWikiPageGenerationIncompleteException extends RuntimeException
{
    /** @param  list<string>  $missingOrEmptySections */
    public function __construct(
        public readonly int $runId,
        public readonly int $pageId,
        public readonly string $pageType,
        public readonly array $missingOrEmptySections,
        public readonly bool $repairAttempted,
        public readonly ?int $pageVersionId = null,
    ) {
        parent::__construct(sprintf(
            'Run [%d] page [%d] (%s): planned section(s) still missing or empty after%s repair: %s.',
            $runId,
            $pageId,
            $pageType,
            $repairAttempted ? ' one' : ' no',
            implode(', ', $missingOrEmptySections),
        ));
    }
}
