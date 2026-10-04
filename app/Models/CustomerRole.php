<?php

namespace App\Models;

use App\Support\CustomerPermissionCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A role the customer defined and named themselves — «Kvalitetsdirektør», «Wiki-ansvarlig».
 *
 * Separate from users.bid_role, which stays Procynia's vocabulary for anbud. See the
 * create_customer_role_tables migration for why the two models do not merge.
 */
class CustomerRole extends Model
{
    protected $fillable = [
        'customer_id',
        'name',
        'description',
        'is_active',
        'all_business_areas',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'all_business_areas' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(CustomerRolePermission::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'customer_user_roles', 'customer_role_id', 'user_id')
            ->withTimestamps();
    }

    /**
     * The fagområder this role is linked to explicitly. Where its rights apply — never what they
     * are; that is still only the role's permission keys. Ignored while the role has «Alle»
     * (all_business_areas), which reaches every current and future area without rows here.
     * See RiskAccessService.
     */
    public function businessAreas(): BelongsToMany
    {
        return $this->belongsToMany(BusinessArea::class, 'customer_role_business_areas', 'customer_role_id', 'business_area_id')
            ->withPivot('customer_id')
            ->withTimestamps();
    }

    /**
     * Set where the role's rights apply: «Alle», or exactly the given areas.
     *
     * «Alle» is stored as the flag alone and the explicit links are cleared, so the role never
     * carries two answers and turning «Alle» off later starts from an honest, empty selection.
     * Areas that are not the role's own customer's are dropped rather than stored, so a role can
     * never reach across tenants however the request was built.
     *
     * @param  iterable<mixed>  $areaIds
     */
    public function syncBusinessAreas(bool $all, iterable $areaIds): void
    {
        $ids = $all ? [] : collect($areaIds)
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $ownIds = $ids === [] ? [] : BusinessArea::query()
            ->forCustomer((int) $this->customer_id)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if ((bool) $this->all_business_areas !== $all) {
            $this->forceFill(['all_business_areas' => $all])->save();
        }

        $this->businessAreas()->sync(
            collect($ownIds)->mapWithKeys(fn (int $id): array => [$id => ['customer_id' => $this->customer_id]])->all()
        );

        $this->unsetRelation('businessAreas');
    }

    /**
     * The permission keys this role grants, with keys the running code no longer knows filtered out.
     *
     * @return list<string>
     */
    public function permissionKeys(): array
    {
        return CustomerPermissionCatalog::filterKnown(
            $this->permissions->pluck('permission_key')->all()
        );
    }

    public function grants(string $permissionKey): bool
    {
        return in_array($permissionKey, $this->permissionKeys(), true);
    }

    /**
     * Replace the role's permission set with exactly the given keys. Unknown keys are dropped
     * rather than stored, so the table can never hold a permission nothing enforces.
     *
     * @param  iterable<mixed>  $permissionKeys
     */
    public function syncPermissions(iterable $permissionKeys): void
    {
        $wanted = CustomerPermissionCatalog::filterKnown($permissionKeys);

        $this->permissions()->whereNotIn('permission_key', $wanted ?: ['__none__'])->delete();

        $existing = $this->permissions()->pluck('permission_key')->all();

        foreach (array_diff($wanted, $existing) as $key) {
            $this->permissions()->create(['permission_key' => $key]);
        }

        $this->unsetRelation('permissions');
    }

    /** @param  Builder<self>  $query */
    public function scopeForCustomer(Builder $query, int $customerId): Builder
    {
        return $query->where('customer_id', $customerId);
    }
}
