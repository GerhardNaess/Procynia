<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedNoticePhaseComment extends Model
{
    protected $fillable = [
        'saved_notice_id',
        'user_id',
        'phase_status',
        'comment',
    ];

    public function savedNotice(): BelongsTo
    {
        return $this->belongsTo(SavedNotice::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getPhaseStatusLabelAttribute(): string
    {
        $status = (string) ($this->phase_status ?? '');

        return SavedNotice::BID_STATUS_LABELS[$status] ?? $status;
    }
}
