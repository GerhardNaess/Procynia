<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The two halves of phase 1 could not be joined into one plan.
 *
 * Always fail-closed: the maintainer decision aborts and the run reports why, rather than
 * continuing with half a plan. A phase-1 half is cheap to regenerate; a decision applied from a
 * partial plan writes pages that nothing planned.
 */
class EnterpriseWikiMaintainerDecisionGlobalPlanMergeException extends RuntimeException
{
    public static function missingDocumentHalf(string $field): self
    {
        return new self(
            "Maintainer decision phase 1A returned no usable [{$field}] — the document plan is incomplete, "
            .'so the decision cannot be assembled.'
        );
    }

    public static function missingCandidateHalf(string $field): self
    {
        return new self(
            "Maintainer decision phase 1B returned no usable [{$field}] — the candidate plan is incomplete, "
            .'so the decision cannot be assembled.'
        );
    }

    public static function malformedEntityPage(int $index): self
    {
        return new self("Maintainer decision phase 1B returned a malformed entity_pages[{$index}] entry.");
    }

    public static function conflictingExistingPage(string $title, string $slot, string $field): self
    {
        return new self(
            "Maintainer decision phase 1B plans to UPDATE existing page [{$title}], which collides with the "
            ."planned [{$slot}] on [{$field}]. Both halves claim the same page, and dropping either would "
            .'silently discard an intended change to real content.'
        );
    }
}
