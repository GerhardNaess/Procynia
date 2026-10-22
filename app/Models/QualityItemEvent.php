<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One thing that happened to a Kvalitet item, as it happened: who, when, what. Append-only.
 *
 * Started for evidence (findings A29 and A20, 2026-10-22): registering it and withdrawing it. The
 * same rows are meant to carry the events still to come — status changes, owner changes, reviews,
 * deletions — each a new `event` name with its own metadata; see docs/external-api-phase-0-plan.md
 * §2h. quality_item_id has no foreign key on purpose: the history outlives the item.
 *
 * The database refuses changes and deletes (quality_item_events_keep), except a deleted user's id
 * going to null and the customer's own deletion; the model refuses them too.
 */
class QualityItemEvent extends Model
{
    public const UPDATED_AT = null;

    public const EVIDENCE_REGISTERED = 'evidence_registered';

    public const EVIDENCE_WITHDRAWN = 'evidence_withdrawn';

    protected $fillable = [
        'customer_id',
        'quality_item_id',
        'quality_item_document_id',
        'event',
        'actor_user_id',
        'actor_name',
        'occurred_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new LogicException('A Kvalitet event cannot be changed.');
        });

        static::deleting(static function (): void {
            throw new LogicException('A Kvalitet event cannot be deleted.');
        });
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
