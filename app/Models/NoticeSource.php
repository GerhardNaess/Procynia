<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One external record that represents a Procynia notice.
 *
 * A notice is the normalised thing Procynia works on; this is how the outside world names it.
 * Today that is always Doffin, one row per notice, and nothing in the application is required to
 * know this table exists — `notices.notice_id` still answers "what is the Doffin id" for every
 * consumer that asks. The table's job is to make room for the second answer without moving the
 * first one.
 *
 * (source, external_id) is unique. That is the whole of the deduplication in this phase: the same
 * Doffin notice cannot be registered twice. Two different sources using the same external id is
 * not a conflict and never was — they are different records that happen to share a string.
 */
class NoticeSource extends Model
{
    protected $fillable = [
        'notice_id',
        'source',
        'external_id',
        'source_url',
        'first_seen_at',
        'last_seen_at',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function notice(): BelongsTo
    {
        return $this->belongsTo(Notice::class);
    }
}
