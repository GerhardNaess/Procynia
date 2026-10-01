<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a process needs to start, and what it leaves behind.
 *
 * One model with a direction rather than two: input and output are the same kind of thing seen from
 * two ends, and one process's output is routinely the next one's input.
 */
class QualityProcessIo extends Model
{
    public const DIRECTION_INPUT = 'input';

    public const DIRECTION_OUTPUT = 'output';

    /** @var list<string> */
    public const DIRECTIONS = [
        self::DIRECTION_INPUT,
        self::DIRECTION_OUTPUT,
    ];

    protected $table = 'quality_process_io';

    protected $fillable = [
        'customer_id',
        'quality_item_id',
        'direction',
        'position',
        'label',
        'description',
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
