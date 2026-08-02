<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown by EnterpriseWikiMaintainerDecisionSplitCoordinator when a single split-flow batch call
 * fails for any reason (capacity exhausted after its own retry, schema violation, refusal, or any
 * other API/decode failure) — wraps the underlying cause with batch-specific diagnostic metadata
 * so the whole split flow aborts loudly rather than silently continuing with only some batches
 * decided. No partial maintainer_decision_json is ever persisted from this path: the exception
 * propagates out of EnterpriseWikiMaintainerDecisionAiClient::decide() exactly like any other
 * failure, before EnterpriseWikiDocumentFlowService ever writes anything to the run row.
 */
class EnterpriseWikiMaintainerDecisionBatchFailedException extends RuntimeException
{
    public function __construct(
        public readonly int $batchNumber,
        public readonly int $totalBatches,
        public readonly int $candidateCount,
        Throwable $cause,
    ) {
        parent::__construct(
            "EnterpriseWikiMaintainerDecisionSplitCoordinator: batch {$batchNumber}/{$totalBatches} ".
            "({$candidateCount} candidates) failed — aborting the whole maintainer decision. ".
            "Cause: {$cause->getMessage()}",
            0,
            $cause,
        );
    }
}
