<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ImprovementCase;

/**
 * GET /api/v1/deviations and GET /api/v1/deviations/{caseId} — the avvik the integration identity
 * may read. Forbedringer are the same model but not part of these endpoints: they have their own
 * (ImprovementController). The rules are ImprovementCaseReadController's.
 */
class DeviationController extends ImprovementCaseReadController
{
    protected function type(): string
    {
        return ImprovementCase::TYPE_DEVIATION;
    }
}
