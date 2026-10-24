<?php

namespace App\Models;

use App\Models\Scopes\HumanUsersScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer's grant of read access to a client application, through one integration identity,
 * and the write operations the System Owner has switched on for it (ApiOperationCatalog).
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
        'allowed_operations',
    ];

    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
            'allowed_operations' => 'array',
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
        // The one relation that reads an integration identity; every other read of users leaves it out.
        return $this->belongsTo(User::class, 'integration_user_id')->withoutGlobalScope(HumanUsersScope::class);
    }

    /** @return BelongsTo<User, $this> */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }

    /** Whether the System Owner has switched this operation on for the grant. Says nothing else. */
    public function allowsOperation(string $operation): bool
    {
        return in_array($operation, (array) ($this->allowed_operations ?? []), true);
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }
}
