<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The link between an external identity and a Procynia user.
 *
 * `external_subject` is the Entra `oid` claim — stable across email, display-name and even tenant
 * domain changes. `external_email` is recorded for support and for the one-time linking decision,
 * but is never sufficient to authenticate on its own: addresses get reassigned, and two customers
 * can legitimately contain the same address.
 */
class UserIdentity extends Model
{
    protected $fillable = [
        'user_id',
        'identity_provider_id',
        'external_subject',
        'external_email',
        'last_login_at',
    ];

    protected function casts(): array
    {
        return [
            'last_login_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function identityProvider(): BelongsTo
    {
        return $this->belongsTo(IdentityProvider::class);
    }
}
