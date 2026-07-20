<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit trail for human decisions made on an Enterprise Wiki claim — who decided,
 * when, any comment, and the previous/new state of whichever decision axis changed
 * (approval_status via WikiClaimController::approve()/reject()/unapprove(), or blocking_override
 * via WikiClaimController::updateBlockingOverride()). Never updated or deleted; a new row is
 * always appended so the full history survives even when a later decision reverses an earlier
 * one (unlike EnterpriseWikiClaim's own approval_status/approved_by_user_id/approved_at columns,
 * which are overwritten in place).
 */
class EnterpriseWikiClaimDecision extends Model
{
    public const TYPE_APPROVAL_STATUS = 'approval_status';

    public const TYPE_BLOCKING_OVERRIDE = 'blocking_override';

    public $timestamps = false;

    protected $fillable = [
        'enterprise_wiki_claim_id',
        'decided_by_user_id',
        'decision_type',
        'previous_state',
        'new_state',
        'comment',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'previous_state' => 'array',
            'new_state' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiClaim::class, 'enterprise_wiki_claim_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }
}
