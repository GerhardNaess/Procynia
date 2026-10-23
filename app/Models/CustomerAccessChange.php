<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One change to who may do what in a customer (F4): a person's access, a customer role, or the
 * Tilganger matrix — with the person who made it. Append-only: written by
 * CustomerAccessChangeRecorder, never changed; the database refuses the same.
 */
class CustomerAccessChange extends Model
{
    public const SUBJECT_USER = 'user';

    public const SUBJECT_CUSTOMER_ROLE = 'customer_role';

    public const SUBJECT_PERMISSION_SETTINGS = 'permission_settings';

    public const UPDATED_AT = null;

    protected $fillable = [
        'customer_id',
        'actor_user_id',
        'subject_type',
        'subject_id',
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

    protected static function booted(): void
    {
        static::updating(static fn (): never => throw new LogicException('An access change is history and is never changed.'));
        static::deleting(static fn (): never => throw new LogicException('An access change is history and is never deleted.'));
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
