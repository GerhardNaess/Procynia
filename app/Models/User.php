<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\CustomerContext;
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
    'is_qa',
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
            'is_qa' => 'boolean',
        ];
    }

    /**
     * Central access gate for the Filament admin panel (security finding F-03).
     *
     * Filament is Procynia's internal administration surface — it is not a customer portal. Only
     * active internal administrators may open it.
     *
     * This previously checked is_active alone, which let every active user through, and then briefly
     * admitted customer admins as well so that UserResource kept working for them. That second step
     * has been reversed as a deliberate product decision: a customer administrator is a
     * customer-scoped role, not an internal system administrator. They administer their own users
     * through the customer frontend (App\Http\Controllers\App\UserController, /app/users), which is
     * tenant-scoped by construction and covered by CustomerUserManagementTest.
     *
     * isInternalAdmin() is the authoritative definition and is reused rather than re-expressed here:
     * super_admin AND customer_id === null. A super_admin that somehow carries a customer_id is not
     * an internal administrator and is refused too.
     *
     * This is the first layer only. Every resource, page and widget keeps its own canAccess() /
     * canView(), and record-level canEdit() / canDelete() stay in place beneath that.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return app(CustomerContext::class)->isInternalAdmin($this);
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

        $customer = $this->customer;

        if ($customer === null) {
            return false;
        }

        if (! $customer->roleHasPermission($this->resolvedBidRole(), Customer::PERMISSION_CREATE_USERS, $this->isQa())) {
            return false;
        }

        if ($this->isSystemOwner()) {
            return true;
        }

        return $this->resolvedBidManagerScope() !== null || $this->resolvedBidRole() === self::BID_ROLE_CONTRIBUTOR;
    }

    public function canManageCustomerBilling(): bool
    {
        if (! $this->canAccessCustomerFrontend()) {
            return false;
        }

        return in_array($this->resolvedBidRole(), [
            self::BID_ROLE_SYSTEM_OWNER,
            self::BID_ROLE_BID_MANAGER,
        ], true);
    }

    public function canViewAllCasesViaSettings(): bool
    {
        if (! $this->canAccessCustomerFrontend() || $this->customer_id === null) {
            return false;
        }

        $customer = $this->customer;

        return $customer !== null
            && $customer->roleHasPermission($this->resolvedBidRole(), Customer::PERMISSION_VIEW_ALL_CASES, $this->isQa());
    }

    /**
     * Manual Wiki claim approval/undo (WikiClaimController::approve()/unapprove()). System
     * Owner always passes (Customer::roleHasPermission() short-circuits true for that role);
     * otherwise this is granted only when the customer's permission gallery lists either the
     * user's ordinary bid_role, "Alle", or "qa" (and the user actually has QA).
     */
    public function canApproveWikiClaims(): bool
    {
        if (! $this->canAccessCustomerFrontend() || $this->customer_id === null) {
            return false;
        }

        $customer = $this->customer;

        return $customer !== null
            && $customer->roleHasPermission($this->resolvedBidRole(), Customer::PERMISSION_APPROVE_WIKI_CLAIMS, $this->isQa());
    }

    /**
     * May this user give a Wiki page its final approval?
     *
     * Separate from canApproveWikiClaims(): approving a claim vouches for one statement against its
     * source, approving a page publishes it. System Owner still passes — roleHasPermission()
     * short-circuits — but as an override, not because the workflow names them the approver.
     */
    public function canApproveWikiPages(): bool
    {
        if (! $this->canAccessCustomerFrontend() || $this->customer_id === null) {
            return false;
        }

        $customer = $this->customer;

        return $customer !== null
            && $customer->roleHasPermission($this->resolvedBidRole(), Customer::PERMISSION_APPROVE_WIKI_PAGES, $this->isQa());
    }

    /**
     * May this user be assigned as reviewer for a page, given who submitted it?
     *
     * Four things must hold: same customer, active account, the approve_wiki_pages capability, and
     * not being the submitter. The last one is separation of duties — see
     * docs/enterprise-wiki-approval-model.md §10 — and it holds for everyone, System Owner
     * included: an emergency exception has not been decided, and quietly inventing one would let a
     * single person both hand work over and sign it off.
     */
    public function canBeEnterpriseWikiReviewerFor(EnterpriseWikiPage $page, ?int $submittedByUserId = null): bool
    {
        if (! $this->is_active || ! $this->canApproveWikiPages()) {
            return false;
        }

        if ((int) $this->customer_id !== (int) $page->customer_id) {
            return false;
        }

        return $submittedByUserId === null || (int) $submittedByUserId !== (int) $this->id;
    }

    /**
     * May this user act on a version that has been assigned to somebody?
     *
     * Normally only the named reviewer. A System Owner may step in when a review is stuck — that is
     * the administrative override the model already gives them (§4) — but never on a version they
     * submitted themselves, which is the one rule the override does not reach.
     */
    public function canReviewEnterpriseWikiVersion(EnterpriseWikiPageVersion $version, EnterpriseWikiPage $page): bool
    {
        if (! $this->canBeEnterpriseWikiReviewerFor($page, $version->submitted_by_user_id)) {
            return false;
        }

        if ($version->reviewer_user_id === null) {
            // Never submitted through the assignment flow — capability alone decides, as before.
            return true;
        }

        return (int) $version->reviewer_user_id === (int) $this->id || $this->isSystemOwner();
    }

    /**
     * May this user send a page for review? The page owner carries it; a System Owner can step in,
     * which also covers pages whose original owner could not be determined.
     */
    public function canSubmitEnterpriseWikiPage(EnterpriseWikiPage $page): bool
    {
        if (! $this->is_active || ! $this->canAccessCustomerFrontend()) {
            return false;
        }

        if ((int) $this->customer_id !== (int) $page->customer_id) {
            return false;
        }

        return $this->isSystemOwner()
            || ($page->owner_user_id !== null && (int) $page->owner_user_id === (int) $this->id);
    }

    public function canBeEnterpriseWikiDocumentOwner(): bool
    {
        if (! $this->canAccessCustomerFrontend() || $this->customer_id === null) {
            return false;
        }

        $customer = $this->customer;

        return $customer !== null
            && $customer->roleHasPermission($this->resolvedBidRole(), Customer::PERMISSION_BE_ENTERPRISE_WIKI_DOCUMENT_OWNER, $this->isQa());
    }

    public function canAssignEnterpriseWikiDocumentOwner(): bool
    {
        if (! $this->canAccessCustomerFrontend() || $this->customer_id === null) {
            return false;
        }

        $customer = $this->customer;

        return $customer !== null
            && $customer->roleHasPermission($this->resolvedBidRole(), Customer::PERMISSION_ASSIGN_ENTERPRISE_WIKI_DOCUMENT_OWNER, $this->isQa());
    }

    /**
     * No configurable permission for this exists yet, so the fallback rule is applied directly:
     * System Owner may delete any Enterprise Wiki source document in their customer; any other
     * user may only delete a document they are the registered owner of; everyone else is
     * read-only. The caller must still enforce customer scoping (this only decides role/ownership
     * once the document is already confirmed to belong to the user's own customer).
     */
    public function canDeleteEnterpriseWikiDocument(EnterpriseWikiDocument $document): bool
    {
        if (! $this->canAccessCustomerFrontend() || $this->customer_id === null) {
            return false;
        }

        if ((int) $this->customer_id !== (int) $document->customer_id) {
            return false;
        }

        if ($this->isSystemOwner()) {
            return true;
        }

        return $document->owner_user_id !== null && (int) $document->owner_user_id === (int) $this->id;
    }

    /**
     * Which EnterpriseWikiPage statuses this user may READ, anywhere in the Enterprise Wiki UI
     * — the ordinary page list (WikiController::visibleStatuses()), the single-page view
     * (WikiController::show()), and the graph (EnterpriseWikiGraphDataService) all call this so
     * they never disagree about which pages belong to a customer's Wiki view.
     *
     * Approval status is a workflow/authority concern, not a read-access concern: any user who
     * can access this customer's Enterprise Wiki at all may read a page regardless of whether it
     * is draft, pending_review, approved, rejected, archived, or superseded — including a page
     * they uploaded the source document for, or that they are the Document Owner of, neither of
     * which is otherwise represented by bid_role. Status still fully controls which WORKFLOW
     * ACTIONS are available (submit/approve/reject remain System-Owner-only in
     * WikiController::submit()/approve()/reject(); claim approval remains gated by
     * canApproveWikiClaims()/canHandleClaim()) — this method governs reading only.
     *
     * @return list<string>
     */
    public function visibleEnterpriseWikiPageStatuses(): array
    {
        if (! $this->canAccessCustomerFrontend() || $this->customer_id === null) {
            return [];
        }

        return EnterpriseWikiPage::STATUSES;
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

    /**
     * QA is an additive capability, not a bid_role — a user keeps their ordinary role
     * (bid_manager, contributor, ...) and QA layers extra permissions on top of it. See
     * Customer::roleHasPermission() for how this is combined with the ordinary role and "Alle".
     */
    public function isQa(): bool
    {
        return (bool) $this->is_qa;
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
