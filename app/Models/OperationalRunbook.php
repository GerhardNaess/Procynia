<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

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

    public function categoryDefinition(): BelongsTo
    {
        return $this->belongsTo(OperationalRunbookCategory::class, 'operational_runbook_category_id');
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
        static::saving(function (self $runbook): void {
            if (! Schema::hasTable('operational_runbook_categories')) {
                return;
            }

            if ($runbook->operational_runbook_category_id) {
                $category = OperationalRunbookCategory::query()->find($runbook->operational_runbook_category_id);

                if ($category) {
                    $runbook->category = $category->slug;
                }

                return;
            }

            if (! filled($runbook->category)) {
                return;
            }

            $category = OperationalRunbookCategory::query()
                ->where('slug', $runbook->category)
                ->first();

            if ($category) {
                $runbook->operational_runbook_category_id = $category->getKey();
                $runbook->category = $category->slug;
            }
        });

        static::deleting(function (self $runbook): void {
            $runbook->attachments()->get()->each->delete();
        });
    }
}
