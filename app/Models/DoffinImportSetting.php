<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DoffinImportSetting extends Model
{
    protected $fillable = [
        'scheduled_import_enabled',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_import_enabled' => 'boolean',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
