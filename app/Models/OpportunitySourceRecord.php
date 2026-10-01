<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one register calls a procurement Procynia has identified.
 *
 * (source, external_id) is the same pair notice_sources and watch_profile_inbox_records are keyed
 * by, and it is unique here too: one register record, one row. What this table adds is which
 * procurement it is a record of — and, when the register said so, which document.
 *
 * opportunity_notice_id is nullable because the two answers arrive separately often enough. Doffin
 * gives both from its detail endpoint and neither from search; a null means Procynia has not been
 * told which document this is, never that the record has none.
 *
 * The row is also how Procynia knows a procurement exists in two registers at once, which is the
 * fact the bid manager will eventually be shown. It is provenance, and it is never deleted when a
 * case is: the registers published what they published.
 */
class OpportunitySourceRecord extends Model
{
    protected $fillable = [
        'opportunity_id',
        'opportunity_notice_id',
        'source',
        'external_id',
        'source_url',
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

    public function opportunityNotice(): BelongsTo
    {
        return $this->belongsTo(OpportunityNotice::class);
    }
}
