<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One decision on a document-owner requirement: who, when, what it was before, what it became and
 * why. Append-only — EnterpriseWikiPageVersionDocumentOwnerApproval holds the current state, this
 * is how it got there (decision B9). The database refuses changes too.
 */
class EnterpriseWikiDocumentOwnerDecision extends Model
{
    protected $fillable = [
        'customer_id',
        'approval_id',
        'decided_by_user_id',
        'previous_status',
        'decision',
        'comment',
        'is_override',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'approval_id' => 'integer',
            'decided_by_user_id' => 'integer',
            'is_override' => 'boolean',
            'decided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new LogicException('A document-owner decision is history and cannot be changed.');
        });

        static::deleting(static function (): void {
            throw new LogicException('A document-owner decision is history and cannot be deleted.');
        });
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiPageVersionDocumentOwnerApproval::class, 'approval_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }
}
