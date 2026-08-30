<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only reservation history that protects a customer's finite quota during provider calls. */
class CustomerAiUsageReservation extends Model
{
    public const STATUS_RESERVED = 'reserved';
    public const STATUS_COMMITTED = 'committed';
    public const STATUS_RELEASED = 'released';
    public const STATUS_UNCERTAIN = 'uncertain';

    protected $fillable = [
        'customer_id', 'saved_notice_id', 'period_start', 'period_end', 'operation',
        'correlation_key', 'status', 'reserved_at', 'finalized_at', 'released_at', 'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'customer_id' => 'integer', 'saved_notice_id' => 'integer', 'period_start' => 'date', 'period_end' => 'date',
            'reserved_at' => 'datetime', 'finalized_at' => 'datetime', 'released_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }

    public function savedNotice(): BelongsTo { return $this->belongsTo(SavedNotice::class); }
}
