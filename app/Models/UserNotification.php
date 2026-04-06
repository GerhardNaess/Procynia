<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserNotification extends Model
{
    public const SEVERITY_INFO = 'info';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_CRITICAL = 'critical';

    public const SEVERITIES = [
        self::SEVERITY_INFO,
        self::SEVERITY_WARNING,
        self::SEVERITY_CRITICAL,
    ];

    public const SEVERITY_LABELS = [
        self::SEVERITY_INFO => 'Info',
        self::SEVERITY_WARNING => 'Advarsel',
        self::SEVERITY_CRITICAL => 'Kritisk',
    ];

    protected $fillable = [
        'customer_id',
        'user_id',
        'saved_notice_id',
        'event_type',
        'severity',
        'title',
        'message',
        'target_url',
        'is_read',
        'read_at',
        'metadata',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'read_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function savedNotice(): BelongsTo
    {
        return $this->belongsTo(SavedNotice::class);
    }

    public function getSeverityLabelAttribute(): string
    {
        return self::SEVERITY_LABELS[$this->severity] ?? (string) $this->severity;
    }
}
