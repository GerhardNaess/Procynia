<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * A fagområde — «Beredskap», «Informasjonssikkerhet», «HR», «Økonomi» — named by the customer.
 *
 * It says *where* a role's rights apply; the role's permission keys say *what* it may do. It is
 * never a role of its own, and it is a concept of the customer, not of any one module. Risiko
 * scopes by it (every risk has one primary fagområde), and Mål og KPI will (every objective has one).
 * A role reaches an area through an explicit link or through CustomerRole::$all_business_areas
 * («Alle»), which is never expanded into links. See the generalize_risk_access_areas_to_business_areas
 * migration, and BusinessAreaGrants for how a role's areas are resolved.
 */
class BusinessArea extends Model
{
    /**
     * Every table whose rows live in a fagområde, through their own business_area_id. An area that
     * still holds such a row is in use and is not deleted: the content would either go with it or
     * be left in no one's scope. A module that starts scoping by fagområde adds its table here —
     * BusinessAreaDeletionTest fails until it does. Role links are not content and cascade.
     *
     * @var list<string>
     */
    public const SCOPED_CONTENT_TABLES = ['risks'];

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

    /**
     * Whether any module still keeps content in this area. Answers yes or no only: the person
     * asking administers areas and is not thereby a reader of what lies in them.
     */
    public function isInUse(): bool
    {
        foreach (self::SCOPED_CONTENT_TABLES as $table) {
            if (DB::table($table)->where('business_area_id', $this->id)->exists()) {
                return true;
            }
        }

        return false;
    }

    /** @param  Builder<self>  $query */
    public function scopeForCustomer(Builder $query, int $customerId): Builder
    {
        return $query->where('customer_id', $customerId);
    }
}
