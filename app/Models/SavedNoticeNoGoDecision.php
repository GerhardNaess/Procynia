<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedNoticeNoGoDecision extends Model
{
    protected $fillable = [
        'saved_notice_id',
        'customer_id',
        'closed_by_user_id',
        'closure_reason',
        'closure_note',
        'closed_at',
        'reopened_by_user_id',
        'reopen_reason',
        'reopened_at',
        'reopened_from_archived_at',
        'reopened_from_history_type',
    ];

    protected $casts = [
        'closed_at' => 'datetime',
        'reopened_at' => 'datetime',
        'reopened_from_archived_at' => 'datetime',
    ];

    public function savedNotice(): BelongsTo
    {
        return $this->belongsTo(SavedNotice::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by_user_id');
    }
}
