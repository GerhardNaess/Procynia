<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A requirement that is met through a Kvalitet process. Only the relation is stored.
 *
 * Reached only through its requirement, and so only through ComplianceAccessService. Kvalitet never
 * reads this model. See ComplianceQualityContextService.
 */
class ComplianceRequirementProcess extends Model
{
    protected $fillable = [
        'customer_id',
        'requirement_id',
        'quality_process_id',
        'created_by',
    ];

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(ComplianceRequirement::class, 'requirement_id');
    }

    public function process(): BelongsTo
    {
        return $this->belongsTo(QualityItem::class, 'quality_process_id');
    }
}
