<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiModelPriceSyncRun extends Model
{
    public const STATUS_RUNNING   = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED    = 'failed';

    protected $fillable = [
        'provider',
        'started_at',
        'finished_at',
        'status',
        'models_seen',
        'prices_created',
        'prices_changed',
        'prices_unchanged',
        'warnings_count',
        'error_message',
        'raw_snapshot_path',
    ];

    protected function casts(): array
    {
        return [
            'started_at'  => 'datetime',
            'finished_at' => 'datetime',
            'models_seen'      => 'integer',
            'prices_created'   => 'integer',
            'prices_changed'   => 'integer',
            'prices_unchanged' => 'integer',
            'warnings_count'   => 'integer',
        ];
    }
}
