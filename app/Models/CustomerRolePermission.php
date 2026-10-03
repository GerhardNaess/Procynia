<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One permission key held by one customer role. The key references
 * App\Support\CustomerPermissionCatalog, which is code — see the create_customer_role_tables
 * migration for why the catalogue is not a table.
 */
class CustomerRolePermission extends Model
{
    protected $fillable = [
        'customer_role_id',
        'permission_key',
    ];

    public function role(): BelongsTo
    {
        return $this->belongsTo(CustomerRole::class, 'customer_role_id');
    }
}
