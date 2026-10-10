<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * The audit trail of a review: created, finalized, amendment added, tiltak handed off or linked, the
 * follow-up of a tiltak changed after the meeting, next review changed, draft deleted. Append-only.
 * A deleted draft's events stay, with the review id nulled and its title in metadata.
 */
class ManagementReviewEvent extends Model
{
    public const CREATED = 'created';

    public const FINALIZED = 'finalized';

    public const AMENDMENT_ADDED = 'amendment_added';

    public const DECISION_HANDED_OFF = 'decision_handed_off';

    public const DECISION_LINKED = 'decision_linked';

    public const ACTION_COMPLETED = 'action_completed';

    public const ACTION_CANCELLED = 'action_cancelled';

    public const ACTION_REOPENED = 'action_reopened';

    public const ACTION_REASSIGNED = 'action_reassigned';

    public const NEXT_REVIEW_CHANGED = 'next_review_changed';

    public const DELETED = 'deleted';

    public $timestamps = false;

    protected $fillable = [
        'customer_id',
        'management_review_id',
        'decision_id',
        'event',
        'actor_user_id',
        'actor_name',
        'metadata',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('A management review event is history and cannot be changed.');
        });

        static::deleting(function (): void {
            throw new LogicException('A management review event is history and cannot be deleted.');
        });
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(ManagementReview::class, 'management_review_id');
    }
}
