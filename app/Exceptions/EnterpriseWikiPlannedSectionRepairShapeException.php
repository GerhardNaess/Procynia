<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised before repaired content can be spliced or persisted when the response does not preserve
 * the authoritative planned-section tuple shape.
 */
class EnterpriseWikiPlannedSectionRepairShapeException extends RuntimeException
{
    public function __construct(
        public readonly int $expectedSectionCount,
        public readonly int $returnedSectionCount,
    ) {
        parent::__construct(sprintf(
            'repair_section_count_mismatch: repaired sections count [%d] did not match expected planned section count [%d].',
            $returnedSectionCount,
            $expectedSectionCount,
        ));
    }
}
