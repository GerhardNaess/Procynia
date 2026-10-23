<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change of a case's bid status: from, to, who and when. Written only by
 * SavedNoticeStatusService, in the same transaction as the change; never changed afterwards
 * (database trigger saved_notice_status_changes_immutable).
 */
class SavedNoticeStatusChange extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'saved_notice_id',
        'customer_id',
        'from_status',
        'to_status',
        'changed_by_user_id',
        'reason',
        'note',
        'changed_at',
    ];

    protected $casts = [
        'changed_at' => 'datetime',
    ];

    public function savedNotice(): BelongsTo
    {
        return $this->belongsTo(SavedNotice::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
