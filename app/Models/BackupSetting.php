<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackupSetting extends Model
{
    protected $fillable = [
        'backup_enabled',
        'backup_directory',
        'retention_policy',
        'last_scheduler_heartbeat_at',
        'last_started_at',
        'last_stopped_at',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'backup_enabled' => 'boolean',
            'retention_policy' => 'array',
            'last_scheduler_heartbeat_at' => 'datetime',
            'last_started_at' => 'datetime',
            'last_stopped_at' => 'datetime',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
