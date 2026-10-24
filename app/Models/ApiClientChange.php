<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One registration or change of a client application, with its actor. Append-only.
 */
class ApiClientChange extends Model
{
    public const REGISTERED = 'registered';

    public const CHANGED = 'changed';

    public const ENABLED = 'enabled';

    public const DISABLED = 'disabled';

    public const UPDATED_AT = null;

    protected $fillable = [
        'api_client_id',
        'actor_user_id',
        'change_type',
        'before',
        'after',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
