<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ordered step of a process.
 *
 * `responsibility` is free text on purpose: a step is carried out by a role, which outlives the
 * person holding it and usually has no account in the system.
 */
class QualityProcessStep extends Model
{
    protected $fillable = [
        'customer_id',
        'quality_item_id',
        'position',
        'title',
        'description',
        'responsibility',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(QualityItem::class, 'quality_item_id');
    }
}
