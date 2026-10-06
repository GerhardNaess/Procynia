<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer's standing with one commercial package.
 *
 * A row is the only way a customer holds a package: there is no mandatory package that applies
 * without one — see App\Services\Modules\ModuleEntitlementService.
 */
class CustomerPackageEntitlement extends Model
{
    /** Ordered by the customer, not yet granted. Carries no access. */
    public const STATUS_REQUESTED = 'requested';

    /** Granted. Every technical module the package maps to is reachable. */
    public const STATUS_ACTIVE = 'active';

    /** The request was turned down. Carries no access, and may be re-requested. */
    public const STATUS_DECLINED = 'declined';

    /** Previously active, since withdrawn. Carries no access. */
    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'customer_id',
        'package_key',
        'status',
        'requested_by',
        'requested_at',
        'activated_at',
        'deactivated_at',
        'note',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'activated_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
