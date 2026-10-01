<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The fields only a control has: what is checked, by whom, how often and how.
 *
 * A 1:1 extension of the quality item rather than four more nullable columns on it, which would be
 * null for every item that is not a control.
 */
class QualityControlDetail extends Model
{
    public const FREQUENCY_CONTINUOUS = 'continuous';

    public const FREQUENCY_DAILY = 'daily';

    public const FREQUENCY_WEEKLY = 'weekly';

    public const FREQUENCY_MONTHLY = 'monthly';

    public const FREQUENCY_QUARTERLY = 'quarterly';

    public const FREQUENCY_ANNUALLY = 'annually';

    public const FREQUENCY_AD_HOC = 'ad_hoc';

    /** @var list<string> */
    public const FREQUENCIES = [
        self::FREQUENCY_CONTINUOUS,
        self::FREQUENCY_DAILY,
        self::FREQUENCY_WEEKLY,
        self::FREQUENCY_MONTHLY,
        self::FREQUENCY_QUARTERLY,
        self::FREQUENCY_ANNUALLY,
        self::FREQUENCY_AD_HOC,
    ];

    protected $fillable = [
        'customer_id',
        'quality_item_id',
        'criterion',
        'responsibility',
        'frequency',
        'method',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(QualityItem::class, 'quality_item_id');
    }
}
