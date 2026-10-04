<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A risk that concerns one activity in a Kvalitet process. The activity is named by its key in the
 * process's flow payload; nothing about it is copied.
 *
 * Reached only through its risk, and so only through RiskAccessService::visibleRisks(). Kvalitet
 * never reads this model. See RiskQualityContextService.
 */
class RiskActivity extends Model
{
    protected $fillable = [
        'customer_id',
        'risk_id',
        'quality_item_id',
        'activity_key',
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
