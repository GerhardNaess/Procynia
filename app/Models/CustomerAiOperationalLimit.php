<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A customer's NOK safety ceiling. Not a plan entitlement — Procynia's own exposure limit. */
class CustomerAiOperationalLimit extends Model
{
    protected $fillable = [
        'customer_id', 'is_enabled', 'daily_nok_limit', 'monthly_nok_limit',
        'changed_by_user_id', 'reason',
    ];

    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'is_enabled' => 'boolean',
            'daily_nok_limit' => 'decimal:2',
            'monthly_nok_limit' => 'decimal:2',
            'changed_by_user_id' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
