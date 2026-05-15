<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OperationalRunbookCategory extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function runbooks(): HasMany
    {
        return $this->hasMany(OperationalRunbook::class, 'operational_runbook_category_id');
    }

    protected static function booted(): void
    {
        static::saving(function (self $category): void {
            if (! Schema::hasTable('operational_runbook_categories')) {
                return;
            }

            $name = trim((string) $category->name);

            if ($name === '') {
                throw ValidationException::withMessages([
                    'name' => __('procynia.operational_runbooks.messages.category_name_required'),
                ]);
            }

            $slug = Str::slug($name);

            if ($slug === '') {
                throw ValidationException::withMessages([
                    'name' => __('procynia.operational_runbooks.messages.category_name_required'),
                ]);
            }

            $categoryId = $category->getKey();
            $normalizedName = mb_strtolower($name, 'UTF-8');

            $nameExists = static::query()
                ->whereRaw('LOWER(name) = ?', [$normalizedName]);

            if ($categoryId !== null) {
                $nameExists->whereKeyNot($categoryId);
            }

            if ($nameExists->exists()) {
                throw ValidationException::withMessages([
                    'name' => __('procynia.operational_runbooks.messages.category_exists'),
                ]);
            }

            $slugExists = static::query()
                ->where('slug', $slug);

            if ($categoryId !== null) {
                $slugExists->whereKeyNot($categoryId);
            }

            if ($slugExists->exists()) {
                throw ValidationException::withMessages([
                    'name' => __('procynia.operational_runbooks.messages.category_exists'),
                ]);
            }

            $category->name = $name;
            $category->slug = $slug;
            $category->sort_order = (int) ($category->sort_order ?? 0);
            $category->is_active = (bool) ($category->is_active ?? true);
        });
    }
}
