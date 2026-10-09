<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What changed when a final experience period was recomputed (a late settlement, a corrected
 * price): the period stays correctable, but never changes without a trace. Append-only.
 */
class AiCustomerExperiencePeriodRevision extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'ai_customer_experience_period_id' => 'integer',
            'revision' => 'integer',
            'changes' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(AiCustomerExperiencePeriod::class, 'ai_customer_experience_period_id');
    }
}
