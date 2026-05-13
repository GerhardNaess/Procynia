<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OperationalRunbook extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(OperationalRunbookAttachment::class)
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->orderBy('id');
    }

    protected static function booted(): void
    {
        static::deleting(function (self $runbook): void {
            $runbook->attachments()->get()->each->delete();
        });
    }
}
