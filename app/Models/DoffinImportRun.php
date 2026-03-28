<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DoffinImportRun extends Model
{
    protected $fillable = [
        'trigger',
        'started_at',
        'finished_at',
        'status',
        'fetched_count',
        'created_count',
        'updated_count',
        'skipped_count',
        'failed_count',
        'error_message',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'meta' => 'array',
        ];
    }
}
