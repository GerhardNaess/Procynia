<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer's grant of read access to a client application, through one integration identity.
 *
 * @property int $id
 * @property int $api_client_id
 * @property int $customer_id
 * @property int $integration_user_id
 */
class ApiClientCustomerGrant extends Model
{
    protected $fillable = [
        'api_client_id',
        'customer_id',
        'integration_user_id',
        'granted_by_user_id',
        'granted_at',
        'revoked_at',
        'revoked_by_user_id',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ApiClient, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(ApiClient::class, 'api_client_id');
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function integrationUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'integration_user_id');
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }
}
