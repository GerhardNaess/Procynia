<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An avvik or a forbedring that concerns one activity in a Kvalitet process. The activity is named
 * by its key in the process's flow payload; nothing about it is copied.
 *
 * Reached only through its case, and so only through ImprovementCaseAccessService::visibleCases().
 * Kvalitet never reads this model. See ImprovementCaseQualityContextService.
 */
class ImprovementCaseActivity extends Model
{
    protected $fillable = [
        'customer_id',
        'improvement_case_id',
        'quality_process_id',
        'activity_key',
        'created_by',
    ];

    public function improvementCase(): BelongsTo
    {
        return $this->belongsTo(ImprovementCase::class);
    }

    public function process(): BelongsTo
    {
        return $this->belongsTo(QualityItem::class, 'quality_process_id');
    }
}
