<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A new AI generation would replace a Wiki answer a person has edited by hand (A43).
 *
 * Thrown by RequirementWikiAnswerService::generate() before any research or AI call when the
 * answer carries a hand-edit and the caller did not say it may be replaced, and under the row lock
 * when the answer was edited while the generation was running. The edited text is never
 * overwritten without the person asking for it.
 */
class RequirementWikiAnswerEditedException extends RuntimeException
{
    public function __construct(
        public readonly int $requirementId,
    ) {
        parent::__construct("The Wiki answer for requirement {$requirementId} has been edited by hand and is not replaced without consent.");
    }
}
