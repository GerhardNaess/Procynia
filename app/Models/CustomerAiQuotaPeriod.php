<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Lockable monthly state for commercial AI quota; committed usage remains in CustomerAiCaseUsage. */
class CustomerAiQuotaPeriod extends Model
{
    protected $fillable = ['customer_id', 'period_start', 'period_end', 'extra_credits'];

    protected function casts(): array
    {
        return ['customer_id' => 'integer', 'period_start' => 'date', 'period_end' => 'date', 'extra_credits' => 'integer'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
