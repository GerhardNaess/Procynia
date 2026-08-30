<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One administrative change to a customer's AI capacity. Never updated, never deleted. */
class CustomerAiCreditAdjustment extends Model
{
    protected $fillable = [
        'customer_id', 'period_start', 'period_end', 'amount', 'reason', 'actor_user_id',
    ];

    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'amount' => 'integer',
            'actor_user_id' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
