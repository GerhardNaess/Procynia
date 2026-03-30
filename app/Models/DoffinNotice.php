<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Stores a normalized Doffin notice harvested from the public API.
 */
class DoffinNotice extends Model
{
    protected $fillable = [
        'notice_id',
        'notice_type',
        'heading',
        'publication_date',
        'issue_date',
        'buyer_name',
        'buyer_org_id',
        'cpv_codes_json',
        'place_of_performance_json',
        'estimated_value_amount',
        'estimated_value_currency_code',
        'estimated_value_display',
        'awarded_names_json',
        'raw_payload_json',
        'last_harvested_at',
    ];

    protected function casts(): array
    {
        return [
            'publication_date' => 'datetime',
            'issue_date' => 'datetime',
            'cpv_codes_json' => 'array',
            'place_of_performance_json' => 'array',
            'estimated_value_amount' => 'decimal:2',
            'awarded_names_json' => 'array',
            'raw_payload_json' => 'array',
            'last_harvested_at' => 'datetime',
        ];
    }

    /**
     * Return the supplier link rows for the notice.
     */
    public function noticeSuppliers(): HasMany
    {
        return $this->hasMany(DoffinNoticeSupplier::class);
    }

    /**
     * Return the suppliers associated with the notice.
     */
    public function suppliers(): BelongsToMany
    {
        return $this->belongsToMany(DoffinSupplier::class, 'doffin_notice_suppliers')
            ->withPivot(['id', 'winner_lots_json', 'source'])
            ->withTimestamps();
    }
}
