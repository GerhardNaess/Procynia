<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a checklist.
 *
 * `is_required` is a column rather than a convention in the text: a checklist that cannot tell
 * "must" from "consider" is one nobody can be held to.
 */
class QualityChecklistItem extends Model
{
    protected $fillable = [
        'customer_id',
        'quality_item_id',
        'position',
        'text',
        'guidance',
        'is_required',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_required' => 'boolean',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(QualityItem::class, 'quality_item_id');
    }
}
