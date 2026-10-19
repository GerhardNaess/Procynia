<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An automated writer tried to give a page in pending_review a new working version.
 *
 * The review assignment belongs to the version that was submitted (submitted_by_user_id,
 * submitted_at and reviewer_user_id live on the version row). A machine-written version under an
 * open review therefore does not join that review — it strands it: the reviewer can no longer
 * approve, because the working version is not the one they were given ("missing_assignment"), and
 * the page has to be sent back and submitted again. Generation, patching, repair and relinking
 * propose knowledge; none of them may take a review away from the person holding it.
 *
 * Thrown by EnterpriseWikiPageVersionWriter under the page row lock, so a page that entered review
 * while a generation was in flight is refused too, not only one that was already in review when the
 * run was planned. Callers that write on a person's behalf (a manual edit, the withdrawal of a
 * deleted document's content) opt out explicitly; every other writer gets this by default.
 *
 * Message-only, matching the other page-generation exceptions: GenerateEnterpriseWikiAppliedPage
 * records "[EnterpriseWikiPageUnderReviewException] …" as the pivot's generation_error, which is
 * what the run's failed-page summary and its findings explanation recognise. No customer text.
 */
class EnterpriseWikiPageUnderReviewException extends RuntimeException
{
    public function __construct(
        public readonly int $pageId,
    ) {
        parent::__construct(
            "Page [{$pageId}] is in pending_review. An automated change never writes a new working version "
            .'while a review is open — the update is deferred until the review is closed.'
        );
    }
}
