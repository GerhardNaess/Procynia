<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What the management decided. A decision (kind = decision) is a statement with no follow-up; a
 * tiltak (kind = action) always has one:
 *
 *  - follow_up = own: followed up here. owner_user_id, due_date and status are live; the tiltak is in
 *    the owner's Mine oppgaver (ManagementReviewTaskSource) until it is completed or cancelled, also
 *    after the review is finalized.
 *  - follow_up = improvement_case: handed to — or linked to — a case in Avvik og forbedringer, which
 *    owns the follow-up from then on (ImprovementTaskSource). owner and due date stay as decided.
 *
 * What was decided (text, kind, section) is frozen once the review is finalized or the tiltak handed
 * off (management_review_decisions_locked).
 */
class ManagementReviewDecision extends Model
{
    public const KIND_DECISION = 'decision';

    public const KIND_ACTION = 'action';

    public const KINDS = [self::KIND_DECISION, self::KIND_ACTION];

    public const FOLLOW_UP_OWN = 'own';

    public const FOLLOW_UP_IMPROVEMENT_CASE = 'improvement_case';

    public const STATUS_OPEN = 'open';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_COMPLETED, self::STATUS_CANCELLED];

    public const ORIGIN_HANDOFF = 'handoff';

    public const ORIGIN_LINKED = 'linked';

    protected $fillable = [
        'customer_id',
        'management_review_id',
        'section_key',
        'kind',
        'text',
        'owner_user_id',
        'due_date',
        'follow_up',
        'status',
        'completed_at',
        'completed_by_user_id',
        'completion_note',
        'improvement_case_id',
        'improvement_origin',
        'handoff_key',
        'handed_off_at',
        'handed_off_by_user_id',
        'position',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date:Y-m-d',
            'completed_at' => 'datetime',
            'handed_off_at' => 'datetime',
        ];
    }

    public function isAction(): bool
    {
        return $this->kind === self::KIND_ACTION;
    }

    public function isFollowedUpHere(): bool
    {
        return $this->follow_up === self::FOLLOW_UP_OWN;
    }

    public function isInImprovements(): bool
    {
        return $this->follow_up === self::FOLLOW_UP_IMPROVEMENT_CASE;
    }

    public function isOpen(): bool
    {
        return $this->isFollowedUpHere() && $this->status === self::STATUS_OPEN;
    }

    /** Open and past its due day. The due day itself is not overdue. */
    public function isOverdue(CarbonInterface $today): bool
    {
        return $this->isOpen() && $this->due_date !== null && $this->due_date->toDateString() < $today->toDateString();
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(ManagementReview::class, 'management_review_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }

    public function improvementCase(): BelongsTo
    {
        return $this->belongsTo(ImprovementCase::class);
    }
}
