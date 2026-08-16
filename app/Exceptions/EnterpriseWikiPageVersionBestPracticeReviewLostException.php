<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A new current version was about to be promoted without the best-practice assessment its
 * predecessor carried.
 *
 * The write is rolled back and the page keeps its previous current version, exactly as for lost
 * block provenance. "Assessed, nothing to add" and "never assessed" must stay distinguishable for
 * the life of the page — a rewrite that quietly erases the record puts the page back in the state
 * the contract was introduced to end.
 */
class EnterpriseWikiPageVersionBestPracticeReviewLostException extends RuntimeException
{
    public function __construct(
        public readonly int $pageId,
        public readonly int $supersededVersionId,
        public readonly int $supersededReviewCount,
    ) {
        parent::__construct(sprintf(
            'Page [%d]: the new version would have dropped the best-practice assessment of %d topic(s) recorded on version [%d]. '
            .'The write has been rolled back; the page keeps its previous current version.',
            $pageId,
            $supersededReviewCount,
            $supersededVersionId,
        ));
    }
}
