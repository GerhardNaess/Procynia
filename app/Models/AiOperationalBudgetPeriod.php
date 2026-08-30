<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Running NOK total for one budget scope and window. Locked during enforcement. */
class AiOperationalBudgetPeriod extends Model
{
    public const SCOPE_GLOBAL = 'global';

    public const SCOPE_CUSTOMER = 'customer';

    public const WINDOW_DAILY = 'daily';

    public const WINDOW_MONTHLY = 'monthly';

    protected $fillable = [
        'scope', 'customer_id', 'window', 'period_start', 'period_end',
        'committed_nok', 'reserved_nok', 'unknown_cost_count',
    ];

    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'committed_nok' => 'decimal:4',
            'reserved_nok' => 'decimal:4',
            'unknown_cost_count' => 'integer',
        ];
    }

    /** Everything already spoken for: settled cost plus money still held by in-flight calls. */
    public function encumberedNok(): float
    {
        return (float) $this->committed_nok + (float) $this->reserved_nok;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
