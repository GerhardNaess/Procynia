<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A bounded repair delta was rejected before anything was merged.
 *
 * Thrown by EnterpriseWikiMaintainerDecisionDeltaMerger when a delta does not address the objects
 * the repair call was actually given — an unknown object id, an object outside the repair group, a
 * removal of a slot that must always exist, or a collision with an object the validators had
 * already accepted. Every one of these means the model answered about something other than the
 * fault it was shown, and merging any part of such an answer would silently rewrite validated
 * planning. Fail closed instead: the run fails with the reasons intact.
 */
class EnterpriseWikiMaintainerDecisionDeltaRejectedException extends RuntimeException
{
    /** @param string[] $reasons */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct(
            'Enterprise Wiki maintainer decision repair delta rejected: '.implode(' | ', $reasons)
        );
    }
}
