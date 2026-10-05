<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A KPI that measures a whole Kvalitet process. Only the relation is stored.
 *
 * Reached only through its KPI, and so only through ObjectiveAccessService::visibleKpis(). Kvalitet
 * never reads this model. See KpiQualityContextService.
 */
class KpiProcess extends Model
{
    protected $fillable = [
        'customer_id',
        'kpi_id',
        'quality_process_id',
        'created_by',
    ];

    public function kpi(): BelongsTo
    {
        return $this->belongsTo(Kpi::class);
    }

    public function process(): BelongsTo
    {
        return $this->belongsTo(QualityItem::class, 'quality_process_id');
    }
}
