<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

/**
 * Stores a normalized supplier identity harvested from Doffin notices.
 */
class DoffinSupplier extends Model
{
    protected $fillable = [
        'supplier_name',
        'organization_number',
        'normalized_name',
    ];

    /**
     * Return the notice link rows for the supplier.
     */
    public function noticeSuppliers(): HasMany
    {
        return $this->hasMany(DoffinNoticeSupplier::class);
    }

    /**
     * Return the notices associated with the supplier.
     */
    public function notices(): BelongsToMany
    {
        return $this->belongsToMany(DoffinNotice::class, 'doffin_notice_suppliers')
            ->withPivot(['id', 'winner_lots_json', 'source'])
            ->withTimestamps();
    }

    /**
     * Return the read-only listing query used by supplier index screens.
     */
    public function scopeWithListingMetrics(Builder $query): Builder
    {
        return $query
            ->withCount('notices')
            ->selectSub(
                DB::query()
                    ->fromSub(
                        DB::table('doffin_notice_suppliers as dns')
                            ->join('doffin_notices as dn', 'dn.id', '=', 'dns.doffin_notice_id')
                            ->whereColumn('dns.doffin_supplier_id', 'doffin_suppliers.id')
                            ->where('dn.estimated_value_currency_code', 'NOK')
                            ->whereNotNull('dn.estimated_value_amount')
                            ->selectRaw('distinct dns.doffin_notice_id, dn.estimated_value_amount'),
                        'supplier_notice_values',
                    )
                    ->selectRaw('sum(supplier_notice_values.estimated_value_amount)'),
                'total_estimated_value_amount',
            );
    }

    /**
     * Apply the shared supplier list search across the visible admin fields.
     */
    public function scopeSearchListing(Builder $query, ?string $value): Builder
    {
        $search = Str::of((string) $value)->squish()->toString();

        if ($search === '') {
            return $query;
        }

        $needle = '%'.Str::lower($search).'%';

        return $query->where(function (Builder $builder) use ($needle): void {
            $builder
                ->whereRaw('LOWER(supplier_name) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(COALESCE(organization_number, \'\')) LIKE ?', [$needle]);
        });
    }
}
