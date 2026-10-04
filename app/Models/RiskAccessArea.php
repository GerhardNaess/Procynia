<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tilgangsområde for risks — «Beredskap», «HR», «Økonomi» — named by the customer.
 *
 * It decides *which* risks a role reaches; the role's permission keys decide *what* it may do with
 * them. It is never a role of its own. See the create_risk_tables migration.
 */
class RiskAccessArea extends Model
{
    protected $fillable = [
        'customer_id',
        'name',
        'description',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(CustomerRole::class, 'customer_role_risk_access_areas', 'risk_access_area_id', 'customer_role_id')
            ->withTimestamps();
    }

    public function risks(): HasMany
    {
        return $this->hasMany(Risk::class);
    }

    /** @param  Builder<self>  $query */
    public function scopeForCustomer(Builder $query, int $customerId): Builder
    {
        return $query->where('customer_id', $customerId);
    }
}
