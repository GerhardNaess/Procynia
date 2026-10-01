<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One eForms notice: a call for tenders, a change to it, or an award.
 *
 * A procurement publishes several of these over its life, and each may be held by more than one
 * register — TED republishes, and the same document reaches Doffin and TED both. So this sits
 * between the procurement and the register records: several notices to an opportunity, several
 * source records to a notice.
 *
 * notice_identifier is unique across the whole table rather than within an opportunity. A document
 * belongs to exactly one procurement; two opportunities claiming it would mean one of them is
 * wrong, and the constraint says so before the data can.
 */
class OpportunityNotice extends Model
{
    protected $fillable = [
        'opportunity_id',
        'notice_identifier',
        'first_seen_at',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    /** The registers that hold this document — typically Doffin and TED, sometimes TED twice. */
    public function sourceRecords(): HasMany
    {
        return $this->hasMany(OpportunitySourceRecord::class);
    }
}
