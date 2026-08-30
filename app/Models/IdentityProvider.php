<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An external identity provider — today always Microsoft Entra ID.
 *
 * One row per (customer, Entra tenant). A row with `customer_id = null` is Procynia's own internal
 * tenant, used by internal administrators; it is deliberately a separate row rather than a special
 * case in code.
 *
 * The provider decides which customer an incoming identity may belong to. That is the whole point:
 * an identity arriving from tenant A resolves to the provider registered for tenant A, and that
 * provider is bound to customer A, so it cannot reach a user in customer B even if the email
 * happens to match.
 *
 * Only non-secret configuration lives here. The client secret is read from config, never the
 * database.
 */
class IdentityProvider extends Model
{
    public const PROVIDER_ENTRA = 'entra';

    protected $fillable = [
        'customer_id',
        'provider',
        'tenant_id',
        'client_id',
        'issuer',
        'is_enabled',
        'allowed_email_domains',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'allowed_email_domains' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function identities(): HasMany
    {
        return $this->hasMany(UserIdentity::class);
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    /** A provider with no customer is Procynia's own tenant for internal administrators. */
    public function isInternal(): bool
    {
        return $this->customer_id === null;
    }

    /**
     * Optional second gate on top of the tenant.
     *
     * A tenant can contain guest accounts from other organisations, so a customer may want to accept
     * only its own domains. Empty means the tenant itself is the only restriction.
     */
    public function allowsEmailDomain(?string $email): bool
    {
        $domains = array_filter((array) ($this->allowed_email_domains ?? []));

        if ($domains === []) {
            return true;
        }

        if ($email === null || ! str_contains($email, '@')) {
            return false;
        }

        $domain = mb_strtolower(trim(substr(strrchr($email, '@') ?: '', 1)));

        foreach ($domains as $allowed) {
            if ($domain === mb_strtolower(trim((string) $allowed))) {
                return true;
            }
        }

        return false;
    }
}
