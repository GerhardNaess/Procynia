<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ImprovementCase;

/**
 * GET /api/v1/improvements and GET /api/v1/improvements/{caseId} — the forbedringer the
 * integration identity may read, on the same model, access rule and fields as the avvik
 * (DeviationController). The rules are ImprovementCaseReadController's.
 */
class ImprovementController extends ImprovementCaseReadController
{
    protected function type(): string
    {
        return ImprovementCase::TYPE_IMPROVEMENT;
    }
}
