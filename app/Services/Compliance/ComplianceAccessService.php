<?php

namespace App\Services\Compliance;

use App\Models\ComplianceAudit;
use App\Models\ComplianceRequirement;
use App\Models\ComplianceSource;
use App\Models\User;
use App\Services\Permissions\CustomerPermissionService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one place that answers "may this user do this, to which requirements and sources?" in
 * Etterlevelse og revisjon.
 *
 * Customer-wide in v1: a role that grants compliance.view reads every requirement and source of the
 * user's own customer; there is no fagområde. compliance.edit registers and changes them, and
 * retires and reopens requirements; compliance.assess registers etterlevelsesvurderinger;
 * compliance.audit plans and runs revisjoner — their fields, their scope and their lifecycle;
 * compliance.delete removes what was registered by mistake.
 *
 * SYSTEM OWNER, EXPLICIT GRANT.
 *
 * compliance is an explicit-grant domain (CustomerPermissionCatalog::explicitGrantDomains()), so
 * CustomerPermissionService gives System Owner none of these keys by virtue of the administrator
 * role. System Owner reaches requirements only through a role of their own, like anyone else.
 *
 * Every read on a user's behalf starts from visibleRequirements(), visibleSources() or
 * visibleAudits(), which narrow
 * the data set *before* any lookup. A requirement the user may not read, or one of another
 * customer, is absent — not listed, not counted, not searchable and 404 by URL, the same answer as
 * an id that does not exist.
 */
class ComplianceAccessService
{
    public function __construct(
        private readonly CustomerPermissionService $permissions,
    ) {}

    /** Whether the user may open Etterlevelse og revisjon at all. */
    public function canOpenModule(?User $user): bool
    {
        return $user instanceof User
            && $user->customer_id !== null
            && $this->permissions->has($user, CustomerPermissionCatalog::COMPLIANCE_VIEW);
    }

    /**
     * Every requirement the user may read, and nothing else. The only correct starting point for
     * any list, search, count or lookup of requirements on a user's behalf.
     *
     * @return Builder<ComplianceRequirement>
     */
    public function visibleRequirements(User $user): Builder
    {
        return $this->scoped(ComplianceRequirement::query(), 'compliance_requirements', $user);
    }

    public function findVisibleRequirement(User $user, int $requirementId): ?ComplianceRequirement
    {
        return $this->visibleRequirements($user)->whereKey($requirementId)->first();
    }

    /**
     * Every source the user may read. The only correct starting point for source lists, filters and
     * lookups on a user's behalf.
     *
     * @return Builder<ComplianceSource>
     */
    public function visibleSources(User $user): Builder
    {
        return $this->scoped(ComplianceSource::query(), 'compliance_sources', $user);
    }

    public function findVisibleSource(User $user, int $sourceId): ?ComplianceSource
    {
        return $this->visibleSources($user)->whereKey($sourceId)->first();
    }

    /**
     * Every audit the user may read, and nothing else. The only correct starting point for any list,
     * search, count or lookup of audits on a user's behalf.
     *
     * @return Builder<ComplianceAudit>
     */
    public function visibleAudits(User $user): Builder
    {
        return $this->scoped(ComplianceAudit::query(), 'compliance_audits', $user);
    }

    public function findVisibleAudit(User $user, int $auditId): ?ComplianceAudit
    {
        return $this->visibleAudits($user)->whereKey($auditId)->first();
    }

    /**
     * Planning and running audits: registering one, changing its fields and scope, and Start,
     * Fullfør, Avbryt and Gjenåpne. compliance.edit is not enough — maintaining the requirement
     * register and auditing whether it is met are separate responsibilities.
     */
    public function canAudit(User $user): bool
    {
        return $this->canOpenModule($user) && $this->permissions->has($user, CustomerPermissionCatalog::COMPLIANCE_AUDIT);
    }

    /** Registering and changing requirements and sources; Sett som utgått and Gjenåpne. */
    public function canEdit(User $user): bool
    {
        return $this->canOpenModule($user) && $this->permissions->has($user, CustomerPermissionCatalog::COMPLIANCE_EDIT);
    }

    /**
     * Registering an etterlevelsesvurdering. compliance.edit alone is not enough: maintaining the
     * register and judging whether it is met are separate responsibilities, as with risk.assess.
     */
    public function canAssess(User $user): bool
    {
        return $this->canOpenModule($user) && $this->permissions->has($user, CustomerPermissionCatalog::COMPLIANCE_ASSESS);
    }

    /** Deleting a requirement or an audit registered by mistake, or a source nothing uses. */
    public function canDelete(User $user): bool
    {
        return $this->canOpenModule($user) && $this->permissions->has($user, CustomerPermissionCatalog::COMPLIANCE_DELETE);
    }

    /**
     * Whether this person may be responsible for a requirement of the customer: active, of the same
     * customer, and able to read requirements. A responsible person who cannot open the requirement
     * is none. Being responsible grants nothing.
     */
    public function isValidOwner(?User $owner, int $customerId): bool
    {
        return $owner instanceof User
            && (bool) $owner->is_active
            && (int) $owner->customer_id === $customerId
            && $this->canOpenModule($owner);
    }

    /**
     * The people who could be responsible: active users of the user's customer who hold
     * compliance.view through an active role of that customer. Through a role only — which is also
     * exactly how System Owner holds it, so the list and isValidOwner() agree.
     *
     * @return list<array{id: int, name: string}>
     */
    public function ownerCandidates(User $user): array
    {
        $customerId = (int) $user->customer_id;

        return User::query()
            ->where('customer_id', $customerId)
            ->where('is_active', true)
            ->whereHas('customerRoles', fn (Builder $roles) => $roles
                ->where('customer_roles.customer_id', $customerId)
                ->where('customer_roles.is_active', true)
                ->whereHas('permissions', fn (Builder $permissions) => $permissions->where('permission_key', CustomerPermissionCatalog::COMPLIANCE_VIEW)))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $candidate): array => ['id' => (int) $candidate->id, 'name' => $candidate->name])
            ->all();
    }

    /**
     * The user's own customer, and only when they may open the module at all. An empty scope must
     * match nothing; it is expressed as a contradiction rather than left to the caller.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function scoped(Builder $query, string $table, User $user): Builder
    {
        if (! $this->canOpenModule($user)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where("{$table}.customer_id", (int) $user->customer_id);
    }
}
