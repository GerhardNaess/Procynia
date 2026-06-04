<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Purpose: Store the first AI-active SavedNotice case usage per customer and calendar month.
 * Inputs: Customer, SavedNotice, activation metadata, and optional source event references.
 * Returns: Ledger rows that can be aggregated for commercial AI case reporting.
 * Side effects: Persists one idempotent case usage row per customer, SavedNotice, and period.
 */
class CustomerAiCaseUsage extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'saved_notice_id',
        'activated_at',
        'activated_by_user_id',
        'period_start',
        'period_end',
        'source_operation_key',
        'source_ai_usage_event_id',
        'source_ai_token_event_id',
    ];

    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'saved_notice_id' => 'integer',
            'activated_at' => 'datetime',
            'activated_by_user_id' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'source_ai_usage_event_id' => 'integer',
            'source_ai_token_event_id' => 'integer',
        ];
    }

    /**
     * Purpose: Resolve the customer that owns this case usage row.
     * Inputs: None.
     * Returns: The owning customer relation.
     * Side effects: None.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Purpose: Resolve the SavedNotice that activated this case usage row.
     * Inputs: None.
     * Returns: The related SavedNotice relation.
     * Side effects: None.
     */
    public function savedNotice(): BelongsTo
    {
        return $this->belongsTo(SavedNotice::class);
    }

    /**
     * Purpose: Resolve the user who first activated this case usage row.
     * Inputs: None.
     * Returns: The activating user relation.
     * Side effects: None.
     */
    public function activatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by_user_id');
    }
}
