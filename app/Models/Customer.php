<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Cashier\Billable;

class Customer extends Model
{
    use Billable;

    public const PERMISSION_CREATE_DEPARTMENTS = 'create_departments';

    public const PERMISSION_CREATE_USERS = 'create_users';

    public const PERMISSION_VIEW_ALL_CASES = 'view_all_cases';

    public const DEFAULT_PERMISSION_SETTINGS = [
        self::PERMISSION_CREATE_DEPARTMENTS => ['system_owner'],
        self::PERMISSION_CREATE_USERS => ['system_owner', 'bid_manager', 'contributor'],
        self::PERMISSION_VIEW_ALL_CASES => ['system_owner', 'bid_manager', 'contributor'],
    ];

    protected $fillable = [
        'name',
        'slug',
        'nationality_id',
        'language_id',
        'is_active',
        'permission_settings',
        'stripe_id',
        'pm_type',
        'pm_last_four',
        'trial_ends_at',
        'subscription_plan',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'permission_settings' => 'array',
            'trial_ends_at' => 'datetime',
        ];
    }

    public function resolvedPermissionSettings(): array
    {
        $stored = $this->permission_settings ?? [];

        return array_merge(self::DEFAULT_PERMISSION_SETTINGS, $stored);
    }

    public function roleHasPermission(string $bidRole, string $permission): bool
    {
        if ($bidRole === 'system_owner') {
            return true;
        }

        $roles = $this->resolvedPermissionSettings()[$permission] ?? [];

        return in_array($bidRole, $roles, true) || in_array('all', $roles, true);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function nationality(): BelongsTo
    {
        return $this->belongsTo(Nationality::class);
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function watchProfiles(): HasMany
    {
        return $this->hasMany(WatchProfile::class);
    }

    public function watchProfileInboxRecords(): HasMany
    {
        return $this->hasMany(WatchProfileInboxRecord::class);
    }

    public function noticeDecisions(): HasMany
    {
        return $this->hasMany(NoticeDecision::class);
    }

    public function noticeAttentions(): HasMany
    {
        return $this->hasMany(NoticeAttention::class);
    }

    public function knowledgeItems(): HasMany
    {
        return $this->hasMany(KnowledgeItem::class);
    }
}
