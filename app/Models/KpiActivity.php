<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A KPI that measures one activity in a Kvalitet process. The activity is named by its key in the
 * process's flow payload; nothing about it is copied.
 *
 * Reached only through its KPI, and so only through ObjectiveAccessService::visibleKpis(). Kvalitet
 * never reads this model. See KpiQualityContextService.
 */
class KpiActivity extends Model
{
    protected $fillable = [
        'customer_id',
        'kpi_id',
        'quality_process_id',
        'activity_key',
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
