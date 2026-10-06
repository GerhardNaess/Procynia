<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A requirement that is met through a Kvalitet control. Only the relation is stored — the control's
 * criterion, method, frequency and evidence stay in Kvalitet.
 *
 * Reached only through its requirement, and so only through ComplianceAccessService. Kvalitet never
 * reads this model. See ComplianceQualityContextService.
 */
class ComplianceRequirementControl extends Model
{
    protected $fillable = [
        'customer_id',
        'requirement_id',
        'control_item_id',
        'created_by',
    ];

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(ComplianceRequirement::class, 'requirement_id');
    }

    public function control(): BelongsTo
    {
        return $this->belongsTo(QualityItem::class, 'control_item_id');
    }
}
