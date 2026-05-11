<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerBillingLine extends Model
{
    public const SOURCE_CUSTOMER_PRICE = 'customer_price';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function billingProduct(): BelongsTo
    {
        return $this->belongsTo(BillingProduct::class, 'billing_product_id');
    }

    public function billingPrice(): BelongsTo
    {
        return $this->belongsTo(BillingPrice::class, 'billing_price_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', ['active', 'pending_cancel']);
    }

    public function scopeRecurring(Builder $query): Builder
    {
        return $query->whereHas('billingPrice', fn (Builder $priceQuery) => $priceQuery->where('interval', '!=', BillingPrice::INTERVAL_ONE_TIME));
    }

    public function scopeOneTime(Builder $query): Builder
    {
        return $query->whereHas('billingPrice', fn (Builder $priceQuery) => $priceQuery->where('interval', BillingPrice::INTERVAL_ONE_TIME));
    }

    public function scopeCustomerSpecificPrice(Builder $query): Builder
    {
        return $query->where('source', self::SOURCE_CUSTOMER_PRICE);
    }
}
