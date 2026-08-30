<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only "already told them" ledger for AI quota notifications. Never pruned per period. */
class CustomerAiNotificationState extends Model
{
    public const EVENT_QUOTA_WARNING = 'quota_warning';

    public const EVENT_QUOTA_CRITICAL = 'quota_critical';

    public const EVENT_QUOTA_EXHAUSTED = 'quota_exhausted';

    public const EVENT_AI_SUSPENDED = 'ai_suspended';

    public const EVENT_AI_RESUMED = 'ai_resumed';

    public const EVENT_CREDITS_ADJUSTED = 'credits_adjusted';

    protected $fillable = [
        'customer_id', 'event_key', 'period_start', 'period_end',
        'threshold_percent', 'recipient_count', 'notified_at',
    ];

    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'threshold_percent' => 'integer',
            'recipient_count' => 'integer',
            'notified_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
