<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A risk that concerns a whole Kvalitet process. Only the relation is stored.
 *
 * Reached only through its risk, and so only through RiskAccessService::visibleRisks(). Kvalitet
 * never reads this model. See RiskQualityContextService.
 */
class RiskProcess extends Model
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

    public function process(): BelongsTo
    {
        return $this->belongsTo(QualityItem::class, 'quality_item_id');
    }
}
