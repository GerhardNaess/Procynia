<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Purpose: Store daily exchange rates from authoritative sources for internal cost conversion.
 * Inputs: Base/quote currency pair, rate, rate date and source.
 * Returns: Eloquent rows used by AiTokenCostEstimator to convert USD AI costs to NOK.
 * Side effects: None — read-only from the estimator's perspective.
 */
class ExchangeRate extends Model
{
    public const SOURCE_NORGES_BANK = 'norges_bank';

    protected $fillable = [
        'base_currency',
        'quote_currency',
        'rate',
        'rate_date',
        'source',
        'source_payload_hash',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'rate'       => 'decimal:8',
            'rate_date'  => 'date',
            'fetched_at' => 'datetime',
        ];
    }

    /**
     * Purpose: Find the exchange rate valid on a specific date, falling back to the most recent earlier rate.
     * Inputs: Base currency, quote currency, source and the reference date.
     * Returns: The ExchangeRate row or null when no rate is available.
     * Side effects: None.
     */
    public static function findForDate(
        string $baseCurrency,
        string $quoteCurrency,
        \DateTimeInterface $date,
        string $source = self::SOURCE_NORGES_BANK,
    ): ?self {
        return static::query()
            ->where('base_currency', $baseCurrency)
            ->where('quote_currency', $quoteCurrency)
            ->where('source', $source)
            ->where('rate_date', '<=', $date->format('Y-m-d'))
            ->orderByDesc('rate_date')
            ->first();
    }
}
