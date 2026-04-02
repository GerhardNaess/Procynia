<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable([
    'name',
    'email',
    'password',
    'role',
    'bid_role',
    'bid_manager_scope',
    'primary_affiliation_scope',
    'primary_department_id',
    'is_active',
    'customer_id',
    'department_id',
    'nationality_id',
    'preferred_language_id',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_SUPER_ADMIN = 'super_admin';

    public const ROLE_CUSTOMER_ADMIN = 'customer_admin';

    public const ROLE_USER = 'user';

    public const BID_ROLE_SYSTEM_OWNER = 'system_owner';

    public const BID_ROLE_BID_MANAGER = 'bid_manager';

    public const BID_ROLE_CONTRIBUTOR = 'contributor';

    public const BID_ROLE_VIEWER = 'viewer';

    public const BID_ROLES = [
        self::BID_ROLE_SYSTEM_OWNER,
        self::BID_ROLE_BID_MANAGER,
        self::BID_ROLE_CONTRIBUTOR,
        self::BID_ROLE_VIEWER,
    ];

    public const BID_MANAGER_SCOPE_COMPANY = 'company';

    public const BID_MANAGER_SCOPE_DEPARTMENTS = 'departments';

    public const BID_MANAGER_SCOPES = [
        self::BID_MANAGER_SCOPE_COMPANY,
        self::BID_MANAGER_SCOPE_DEPARTMENTS,
    ];

    public const PRIMARY_AFFILIATION_SCOPE_COMPANY = 'company';

    public const PRIMARY_AFFILIATION_SCOPE_DEPARTMENT = 'department';

    public const PRIMARY_AFFILIATION_SCOPES = [
        self::PRIMARY_AFFILIATION_SCOPE_COMPANY,
        self::PRIMARY_AFFILIATION_SCOPE_DEPARTMENT,
    ];

    public const BID_ROLE_LABELS = [
        self::BID_ROLE_SYSTEM_OWNER => 'System Owner',
        self::BID_ROLE_BID_MANAGER => 'Bid Manager',
        self::BID_ROLE_CONTRIBUTOR => 'Contributor',
        self::BID_ROLE_VIEWER => 'Viewer',
    ];

    public const BID_MANAGER_SCOPE_LABELS = [
        self::BID_MANAGER_SCOPE_COMPANY => 'Hele selskapet',
        self::BID_MANAGER_SCOPE_DEPARTMENTS => 'Utvalgte avdelinger',
    ];

    public const PRIMARY_AFFILIATION_SCOPE_LABELS = [
        self::PRIMARY_AFFILIATION_SCOPE_COMPANY => 'Hele selskapet',
        self::PRIMARY_AFFILIATION_SCOPE_DEPARTMENT => 'Primær avdeling',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return (bool) $this->is_active;
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function primaryDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'primary_department_id');
    }

    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class)
            ->withTimestamps()
            ->orderBy('departments.name');
    }

    public function membershipDepartmentIds(): array
    {
        $departmentIds = ($this->relationLoaded('departments')
            ? $this->departments->pluck('id')
            : $this->departments()->pluck('departments.id'))
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($departmentIds !== []) {
            return $departmentIds;
        }

        $primaryDepartmentId = $this->primaryAffiliationDepartmentId();

        return $primaryDepartmentId !== null ? [$primaryDepartmentId] : [];
    }

    public function hasDepartmentMembership(): bool
    {
        return $this->membershipDepartmentIds() !== [];
    }

    public function managedDepartments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'bid_manager_departments')
            ->withTimestamps()
            ->orderBy('departments.name');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function watchProfiles(): HasMany
    {
        return $this->hasMany(WatchProfile::class);
    }

    public function watchProfileInboxRecords(): HasMany
    {
        return $this->hasMany(WatchProfileInboxRecord::class);
    }

    public function nationality(): BelongsTo
    {
        return $this->belongsTo(Nationality::class);
    }

    public function preferredLanguage(): BelongsTo
    {
        return $this->belongsTo(Language::class, 'preferred_language_id');
    }

    public static function bidRoleOptions(): array
    {
        return self::BID_ROLE_LABELS;
    }

    public static function bidManagerScopeOptions(): array
    {
        return self::BID_MANAGER_SCOPE_LABELS;
    }

    public static function primaryAffiliationScopeOptions(): array
    {
        return self::PRIMARY_AFFILIATION_SCOPE_LABELS;
    }

    public static function roleOptions(): array
    {
        return [
            self::ROLE_SUPER_ADMIN => 'Super admin',
            self::ROLE_CUSTOMER_ADMIN => 'Customer admin',
            self::ROLE_USER => 'User',
        ];
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN;
    }

    public function isCustomerAdmin(): bool
    {
        return $this->role === self::ROLE_CUSTOMER_ADMIN;
    }

    public function isRegularUser(): bool
    {
        return $this->role === self::ROLE_USER;
    }

    public function canManageUsers(): bool
    {
        return $this->isSuperAdmin() || $this->isCustomerAdmin();
    }

    public function canManageCustomerUsers(): bool
    {
        if (! $this->canAccessCustomerFrontend()) {
            return false;
        }

        if ($this->isSystemOwner()) {
            return true;
        }

        return $this->isBidManager()
            && $this->resolvedBidManagerScope() !== null;
    }

    public static function customerRoleForBidRole(string $bidRole): string
    {
        return in_array($bidRole, [self::BID_ROLE_SYSTEM_OWNER, self::BID_ROLE_BID_MANAGER], true)
            ? self::ROLE_CUSTOMER_ADMIN
            : self::ROLE_USER;
    }

    public function resolvedBidRole(): string
    {
        $value = (string) ($this->getAttribute('bid_role') ?? '');

        return in_array($value, self::BID_ROLES, true)
            ? $value
            : self::BID_ROLE_CONTRIBUTOR;
    }

    public function isBidManager(): bool
    {
        return $this->resolvedBidRole() === self::BID_ROLE_BID_MANAGER;
    }

    public function isSystemOwner(): bool
    {
        return $this->resolvedBidRole() === self::BID_ROLE_SYSTEM_OWNER;
    }

    public function resolvedBidManagerScope(): ?string
    {
        if (! $this->isBidManager()) {
            return null;
        }

        $value = (string) ($this->getAttribute('bid_manager_scope') ?? '');

        return in_array($value, self::BID_MANAGER_SCOPES, true)
            ? $value
            : null;
    }

    public function hasCompanyWideBidManagementScope(): bool
    {
        return $this->resolvedBidManagerScope() === self::BID_MANAGER_SCOPE_COMPANY;
    }

    public function hasCompanyWideCustomerManagementScope(): bool
    {
        return $this->isSystemOwner() || $this->hasCompanyWideBidManagementScope();
    }

    public function hasDepartmentScopedBidManagement(): bool
    {
        return $this->resolvedBidManagerScope() === self::BID_MANAGER_SCOPE_DEPARTMENTS;
    }

    public function managedDepartmentIds(): array
    {
        if (! $this->hasDepartmentScopedBidManagement()) {
            return [];
        }

        return ($this->relationLoaded('managedDepartments')
            ? $this->managedDepartments->pluck('id')
            : $this->managedDepartments()->pluck('departments.id'))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function resolvedPrimaryAffiliationScope(): string
    {
        $value = (string) ($this->getAttribute('primary_affiliation_scope') ?? '');

        if (in_array($value, self::PRIMARY_AFFILIATION_SCOPES, true)) {
            return $value;
        }

        return $this->department_id !== null
            ? self::PRIMARY_AFFILIATION_SCOPE_DEPARTMENT
            : self::PRIMARY_AFFILIATION_SCOPE_COMPANY;
    }

    public function primaryAffiliationDepartmentId(): ?int
    {
        if ($this->resolvedPrimaryAffiliationScope() !== self::PRIMARY_AFFILIATION_SCOPE_DEPARTMENT) {
            return null;
        }

        $primaryDepartmentId = $this->getAttribute('primary_department_id');

        if ($primaryDepartmentId !== null) {
            return (int) $primaryDepartmentId;
        }

        return $this->department_id !== null ? (int) $this->department_id : null;
    }

    public function hasCompanyPrimaryAffiliation(): bool
    {
        return $this->resolvedPrimaryAffiliationScope() === self::PRIMARY_AFFILIATION_SCOPE_COMPANY;
    }

    public function hasDepartmentPrimaryAffiliation(): bool
    {
        return $this->resolvedPrimaryAffiliationScope() === self::PRIMARY_AFFILIATION_SCOPE_DEPARTMENT
            && $this->primaryAffiliationDepartmentId() !== null;
    }

    public function getBidRoleLabelAttribute(): string
    {
        return self::BID_ROLE_LABELS[$this->resolvedBidRole()];
    }

    public function getBidManagerScopeLabelAttribute(): ?string
    {
        $scope = $this->resolvedBidManagerScope();

        return $scope !== null ? self::BID_MANAGER_SCOPE_LABELS[$scope] : null;
    }

    public function getPrimaryAffiliationScopeLabelAttribute(): string
    {
        return self::PRIMARY_AFFILIATION_SCOPE_LABELS[$this->resolvedPrimaryAffiliationScope()];
    }

    public function canAccessCustomerFrontend(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return $this->customer_id !== null
            && in_array($this->role, [self::ROLE_CUSTOMER_ADMIN, self::ROLE_USER], true);
    }
}
