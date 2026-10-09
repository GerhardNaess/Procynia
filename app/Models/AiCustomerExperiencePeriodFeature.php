<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trusted AI usage of one attribution key in one experience period: a feature (tender, quality,
 * …), plain `wiki`, or `wiki.<module>` for Wiki work whose source was handed over from a module.
 */
class AiCustomerExperiencePeriodFeature extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'ai_customer_experience_period_id' => 'integer',
            'calls' => 'integer',
            'settled_cost_nok' => 'float',
            'settled_units' => 'float',
            'reserved_units' => 'float',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(AiCustomerExperiencePeriod::class, 'ai_customer_experience_period_id');
    }
}
