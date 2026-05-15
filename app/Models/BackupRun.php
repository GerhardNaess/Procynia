<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackupRun extends Model
{
    public const TYPE_SCHEDULED = 'scheduled';
    public const TYPE_MANUAL = 'manual';
    public const TYPE_RESTORE_TEST = 'restore_test';

    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'type',
        'status',
        'started_at',
        'finished_at',
        'duration_seconds',
        'database_backup_path',
        'storage_backup_path',
        'database_backup_size_bytes',
        'storage_backup_size_bytes',
        'error_message',
        'triggered_by',
        'triggered_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function triggeredByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_SUCCESS, self::STATUS_FAILED, self::STATUS_SKIPPED], true);
    }
}
