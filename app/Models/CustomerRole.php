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
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
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
