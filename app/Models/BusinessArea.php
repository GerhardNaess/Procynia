<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A fagområde — «Beredskap», «Informasjonssikkerhet», «HR», «Økonomi» — named by the customer.
 *
 * It says *where* a role's rights apply; the role's permission keys say *what* it may do. It is
 * never a role of its own, and it is a concept of the customer, not of any one module. Risiko is
 * so far the only module that scopes by it (every risk has one primary fagområde). A role reaches
 * an area through an explicit link or through CustomerRole::$all_business_areas («Alle»), which
 * is never expanded into links. See the generalize_risk_access_areas_to_business_areas migration.
 */
class BusinessArea extends Model
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

    /** Roles linked to this area explicitly. Roles with «Alle» reach it without a row here. */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(CustomerRole::class, 'customer_role_business_areas', 'business_area_id', 'customer_role_id')
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
