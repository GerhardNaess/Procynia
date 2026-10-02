<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One optional clarification the user turned down, for one process.
 *
 * The row is a memory, not a rule: QualityFlowClarificationService decides whether it still applies
 * by comparing `description` with the description being interpreted now. See the migration for why
 * it is kept that way round.
 */
class QualityFlowClarificationDismissal extends Model
{
    protected $fillable = [
        'customer_id',
        'quality_item_id',
        'question',
        'question_key',
        'description',
        'dismissed_by_user_id',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(QualityItem::class, 'quality_item_id');
    }

    public function dismissedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dismissed_by_user_id');
    }
}
