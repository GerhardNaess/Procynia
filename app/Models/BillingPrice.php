<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingPrice extends Model
{
    public const INTERVAL_MONTHLY = 'monthly';
    public const INTERVAL_YEARLY = 'yearly';
    public const INTERVAL_ONE_TIME = 'one_time';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'unit_amount' => 'integer',
            'is_recurring' => 'boolean',
            'is_active' => 'boolean',
            'included_quantity' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(BillingProduct::class, 'billing_product_id');
    }

    public function billingLines(): HasMany
    {
        return $this->hasMany(CustomerBillingLine::class);
    }

    public function userServiceLevels(): HasMany
    {
        return $this->hasMany(CustomerUserServiceLevel::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeRecurring(Builder $query): Builder
    {
        return $query->where('is_recurring', true);
    }
}
