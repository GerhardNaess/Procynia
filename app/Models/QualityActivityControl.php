<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One control, placed on one activity of a process.
 *
 * The control is an ordinary quality item of type `control`; this row only says where in the
 * process it applies. The activity is named by its key in the flow payload — see the migration.
 */
class QualityActivityControl extends Model
{
    protected $fillable = [
        'customer_id',
        'quality_item_id',
        'activity_key',
        'control_item_id',
        'created_by_user_id',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(QualityItem::class, 'quality_item_id');
    }

    public function control(): BelongsTo
    {
        return $this->belongsTo(QualityItem::class, 'control_item_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
