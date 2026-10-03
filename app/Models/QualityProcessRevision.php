<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One approved revision of a process flow. Immutable once written.
 *
 * The working version is QualityProcessBlueprint; this is the record of what was approved. See the
 * migration for why the two are kept apart. The only way a revision leaves the database is with
 * the process itself, through the foreign key.
 */
class QualityProcessRevision extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'customer_id',
        'quality_item_id',
        'revision_number',
        'payload',
        'description',
        'source',
        'approved_by_user_id',
        'approved_by_name',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'revision_number' => 'integer',
            'payload' => 'array',
            'approved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // A revision is a statement about what was approved at a point in time. Changing or
        // removing one would rewrite that statement after the fact.
        static::updating(static function (): void {
            throw new LogicException('An approved process revision cannot be changed.');
        });

        static::deleting(static function (): void {
            throw new LogicException('An approved process revision cannot be deleted.');
        });
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(QualityItem::class, 'quality_item_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
