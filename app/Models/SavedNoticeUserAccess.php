<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedNoticeUserAccess extends Model
{
    public const ACCESS_ROLE_CONTRIBUTOR = 'contributor';

    public const ACCESS_ROLE_VIEWER = 'viewer';

    public const ACCESS_ROLE_LABELS = [
        self::ACCESS_ROLE_CONTRIBUTOR => 'Contributor',
        self::ACCESS_ROLE_VIEWER => 'Viewer',
    ];

    public const ACCESS_ROLES = [
        self::ACCESS_ROLE_CONTRIBUTOR,
        self::ACCESS_ROLE_VIEWER,
    ];

    protected $table = 'saved_notice_user_access';

    protected $fillable = [
        'saved_notice_id',
        'user_id',
        'granted_by_user_id',
        'access_role',
        'expires_at',
        'revoked_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function savedNotice(): BelongsTo
    {
        return $this->belongsTo(SavedNotice::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }

    public function getAccessRoleLabelAttribute(): string
    {
        return self::ACCESS_ROLE_LABELS[$this->access_role] ?? $this->access_role;
    }

    public static function accessRoleOptions(): array
    {
        return self::ACCESS_ROLE_LABELS;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNull('revoked_at')
            ->where(function (Builder $builder): void {
                $builder
                    ->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }
}
