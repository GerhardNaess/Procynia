<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Purpose: Store non-sensitive AI usage events for safety limits and operational insight.
 * Inputs: Customer, user, operation key, status, limit type and operation count.
 * Returns: Eloquent rows that can be queried for AI usage trends.
 * Side effects: Persists safe usage metadata only.
 */
class AiUsageEvent extends Model
{
    public const STATUS_ALLOWED = 'allowed';

    public const STATUS_BLOCKED = 'blocked';

    public const LIMIT_TYPE_USER = 'user';

    public const LIMIT_TYPE_CUSTOMER = 'customer';

    protected $fillable = [
        'customer_id',
        'user_id',
        'operation_key',
        'status',
        'limit_type',
        'operation_count',
    ];

    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'user_id' => 'integer',
            'operation_count' => 'integer',
        ];
    }

    /**
     * Purpose: Resolve the customer linked to this AI usage event.
     * Inputs: None.
     * Returns: The owning customer relation.
     * Side effects: None.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Purpose: Resolve the user linked to this AI usage event.
     * Inputs: None.
     * Returns: The triggering user relation.
     * Side effects: None.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
