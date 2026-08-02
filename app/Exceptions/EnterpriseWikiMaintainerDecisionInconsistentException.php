<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by EnterpriseWikiMaintainerDecisionService::runForDocument() when
 * EnterpriseWikiMaintainerDecisionConsistencyValidator still finds logical self-contradictions
 * after one bounded AI repair pass (see EnterpriseWikiMaintainerDecisionAiClient::repair()).
 *
 * Deliberately a plain exception, not a silent fallback: the caller (EnterpriseWikiDocumentFlowService)
 * already fails the ingest run and surfaces the error for any unexpected Throwable from the
 * maintainer-decision step, so this reuses that existing, traceable failure path instead of
 * inventing a new one — an inconsistent decision must never be applied unnoticed.
 */
class EnterpriseWikiMaintainerDecisionInconsistentException extends RuntimeException
{
    /** @param  string[]  $issues */
    public function __construct(public readonly array $issues)
    {
        parent::__construct(
            'Maintainer decision is still logically inconsistent after repair: '.implode(' | ', $issues)
        );
    }
}
