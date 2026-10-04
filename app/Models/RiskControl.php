<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A risk handled by an existing Kvalitet control. Only the relation is stored — the control itself
 * stays the `control` QualityItem it always was.
 *
 * Reached only through its risk, and so only through RiskAccessService::visibleRisks(). Kvalitet
 * never reads this model.
 */
class RiskControl extends Model
{
    protected $fillable = [
        'customer_id',
        'risk_id',
        'quality_item_id',
        'created_by',
    ];

    public function risk(): BelongsTo
    {
        return $this->belongsTo(Risk::class);
    }

    public function control(): BelongsTo
    {
        return $this->belongsTo(QualityItem::class, 'quality_item_id');
    }
}
