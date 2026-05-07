<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingProduct extends Model
{
    public const CATEGORY_BASE_PLAN = 'base_plan';
    public const CATEGORY_USER_SEAT = 'user_seat';
    public const CATEGORY_USER_SERVICE = 'user_service';
    public const CATEGORY_ADDON = 'addon';
    public const CATEGORY_ONE_OFF = 'one_off';
    public const CATEGORY_ENTERPRISE = 'enterprise';

    public const BILLING_SCOPE_CUSTOMER = 'customer';
    public const BILLING_SCOPE_USER = 'user';
    public const BILLING_SCOPE_QUANTITY = 'quantity';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'metadata' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(BillingPrice::class);
    }

    public function activePrices(): HasMany
    {
        return $this->prices()->where('is_active', true);
    }
}
