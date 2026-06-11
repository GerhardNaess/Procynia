<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminPageHelp extends Model
{
    protected $fillable = [
        'page_key',
        'title',
        'description',
        'intro',
        'sections',
        'is_active',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'sections'  => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
