<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminNotification extends Model
{
    public const TYPE_AI_PRICE_CREATED     = 'ai_model_price_created';
    public const TYPE_AI_PRICE_CHANGED     = 'ai_model_price_changed';
    public const TYPE_AI_PRICE_MISSING     = 'ai_model_price_missing';
    public const TYPE_AI_PRICE_SYNC_FAILED = 'ai_model_price_sync_failed';
    public const TYPE_AI_PRICE_STALE       = 'ai_model_price_stale';

    public const SEVERITY_INFO     = 'info';
    public const SEVERITY_WARNING  = 'warning';
    public const SEVERITY_CRITICAL = 'critical';

    protected $fillable = [
        'type',
        'severity',
        'title',
        'message',
        'data',
        'dedupe_key',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'data'    => 'array',
            'read_at' => 'datetime',
        ];
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    public function markAsRead(): void
    {
        $this->forceFill(['read_at' => now()])->save();
    }
}
