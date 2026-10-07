<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * That a requirement in Etterlevelse og revisjon applies to a supplier. Only that: not that the
 * supplier meets it, breaks it or shares the status the requirement has been assessed to there.
 * Nothing about the requirement is kept here — its reference, title, kravkilde, lifecycle and
 * assessments are read from Etterlevelse og revisjon, through that module's access rules, when they
 * are shown.
 *
 * Written only by SupplierComplianceRequirementService. Has no access rules of its own: reach it
 * through a supplier from SupplierAccessService and show a requirement only when
 * ComplianceAccessService::visibleRequirements() returns it.
 */
class SupplierComplianceRequirement extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'customer_id',
        'supplier_id',
        'compliance_requirement_id',
        'created_by',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(ComplianceRequirement::class, 'compliance_requirement_id');
    }
}
