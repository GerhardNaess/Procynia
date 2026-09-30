<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One procurement, however many registers describe it and however many notices it publishes.
 *
 * The row is its procedure_identifier — the eForms UUID Doffin calls procedureId and TED calls
 * procedure-identifier, and which both publish with the same value for the same procurement. It
 * holds nothing else about the procurement on purpose: the title, the buyer and the deadline are
 * already on the records that announced them, and a copy here would be a version free to drift.
 *
 * What it gives the rest of Procynia is a thing to be the same. A customer's case hangs off this
 * row, so saving the Doffin record and later the TED record of one procurement reaches one case.
 */
class Opportunity extends Model
{
    protected $fillable = [
        'procedure_identifier',
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

    /** The documents published under this procurement — the call, its changes, the award. */
    public function notices(): HasMany
    {
        return $this->hasMany(OpportunityNotice::class);
    }

    /** Every register record Procynia has seen for this procurement. */
    public function sourceRecords(): HasMany
    {
        return $this->hasMany(OpportunitySourceRecord::class);
    }

    /** The customer cases built on this procurement — at most one per customer. */
    public function savedNotices(): HasMany
    {
        return $this->hasMany(SavedNotice::class);
    }
}
