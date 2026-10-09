<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One billing period as the payment provider reported it: [period_start, period_end), UTC.
 *
 * Written only by CustomerBillingPeriodRecorder (webhooks and billing:sync-subscriptions); read
 * only through CustomerBillingPeriodResolver.
 */
class CustomerBillingPeriod extends Model
{
    public const PROVIDER_STRIPE = 'stripe';

    /** Subscription states that still bill — the ones a current period must be recorded for. */
    public const BILLING_SUBSCRIPTION_STATUSES = ['active', 'trialing', 'past_due', 'unpaid'];

    protected $fillable = [
        'customer_id', 'provider', 'provider_subscription_id', 'period_start', 'period_end',
        'interval', 'interval_count', 'subscription_status', 'cancel_at_period_end',
        'provider_event_at', 'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'period_start' => 'immutable_datetime',
            'period_end' => 'immutable_datetime',
            'interval_count' => 'integer',
            'cancel_at_period_end' => 'boolean',
            'provider_event_at' => 'immutable_datetime',
            'synced_at' => 'immutable_datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
