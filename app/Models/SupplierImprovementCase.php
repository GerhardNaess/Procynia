<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * That a case in Avvik og forbedringer concerns a supplier — created from it («handoff», possibly
 * from one of its assessments, controls or aktsomhetsvurderinger) or connected afterwards («linked»). Nothing about the case is kept
 * here: its type, status, owner, frist and tiltak are read from Avvik og forbedringer, through that
 * module's access rules, when they are shown.
 *
 * Written only by SupplierImprovementHandoffService. Has no access rules of its own: reach it
 * through a supplier from SupplierAccessService and show a case only when
 * ImprovementCaseAccessService::visibleCases() returns it.
 */
class SupplierImprovementCase extends Model
{
    public const ORIGIN_HANDOFF = 'handoff';

    public const ORIGIN_LINKED = 'linked';

    public const UPDATED_AT = null;

    protected $fillable = [
        'customer_id',
        'supplier_id',
        'improvement_case_id',
        'supplier_assessment_id',
        'supplier_requirement_evaluation_id',
        'supplier_due_diligence_assessment_id',
        'origin',
        'handoff_key',
        'created_by',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function improvementCase(): BelongsTo
    {
        return $this->belongsTo(ImprovementCase::class, 'improvement_case_id');
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(SupplierAssessment::class, 'supplier_assessment_id');
    }

    public function dueDiligence(): BelongsTo
    {
        return $this->belongsTo(SupplierDueDiligenceAssessment::class, 'supplier_due_diligence_assessment_id');
    }

    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(SupplierRequirementEvaluation::class, 'supplier_requirement_evaluation_id');
    }
}
