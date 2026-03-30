<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stores the winning-supplier link between a Doffin notice and supplier.
 */
class DoffinNoticeSupplier extends Model
{
    protected $fillable = [
        'doffin_notice_id',
        'doffin_supplier_id',
        'winner_lots_json',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'winner_lots_json' => 'array',
        ];
    }

    /**
     * Return the linked Doffin notice.
     */
    public function notice(): BelongsTo
    {
        return $this->belongsTo(DoffinNotice::class, 'doffin_notice_id');
    }

    /**
     * Return the linked supplier.
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(DoffinSupplier::class, 'doffin_supplier_id');
    }
}
