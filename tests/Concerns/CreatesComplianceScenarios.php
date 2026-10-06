<?php

namespace Tests\Concerns;

use App\Models\ComplianceAudit;
use App\Models\ComplianceRequirement;
use App\Models\ComplianceSource;
use App\Models\Customer;
use App\Models\CustomerPackageEntitlement;
use App\Models\CustomerRole;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Support\Str;

/**
 * The customers, roles, people, sources and requirements the Etterlevelse og revisjon tests build
 * on. Every grant goes through a real customer role, so the tests exercise the same path the
 * product does — including System Owner, who holds compliance.* only through one.
 */
trait CreatesComplianceScenarios
{
    /**
     * A customer holding the given package (GRC carries Etterlevelse og revisjon), or none, and its
     * System Owner.
     *
     * @return array{customer: Customer, owner: User}
     */
    private function complianceContext(?string $package = 'grc'): array
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        $customer = Customer::query()->create([
            'name' => 'Krav AS',
            'slug' => 'krav-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);

        if ($package !== null) {
            CustomerPackageEntitlement::query()->updateOrCreate(
                ['customer_id' => $customer->id, 'package_key' => $package],
                ['status' => CustomerPackageEntitlement::STATUS_ACTIVE, 'activated_at' => now()],
            );
        }

        $owner = User::query()->create([
            'name' => 'System Owner '.Str::upper(Str::random(4)),
            'email' => 'krav-eier-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        return ['customer' => $customer, 'owner' => $owner];
    }

    private function complianceMember(Customer $customer, string $name = ''): User
    {
        return User::query()->create([
            'name' => $name !== '' ? $name : 'Medarbeider '.Str::upper(Str::random(6)),
            'email' => 'krav-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    /** @param  list<string>  $permissionKeys */
    private function complianceGrant(Customer $customer, User $user, array $permissionKeys, bool $active = true): CustomerRole
    {
        $role = CustomerRole::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Rolle '.Str::upper(Str::random(8)),
            'is_active' => $active,
        ]);
        $role->syncPermissions($permissionKeys);
        $user->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);

        return $role;
    }

    private function complianceReader(Customer $customer): User
    {
        $user = $this->complianceMember($customer);
        $this->complianceGrant($customer, $user, [CustomerPermissionCatalog::COMPLIANCE_VIEW]);

        return $user;
    }

    private function complianceEditor(Customer $customer): User
    {
        $user = $this->complianceMember($customer);
        $this->complianceGrant($customer, $user, [CustomerPermissionCatalog::COMPLIANCE_VIEW, CustomerPermissionCatalog::COMPLIANCE_EDIT]);

        return $user;
    }

    /** May do everything this batch offers: view, edit and delete. */
    private function complianceManager(Customer $customer): User
    {
        $user = $this->complianceMember($customer);
        $this->complianceGrant($customer, $user, [
            CustomerPermissionCatalog::COMPLIANCE_VIEW,
            CustomerPermissionCatalog::COMPLIANCE_EDIT,
            CustomerPermissionCatalog::COMPLIANCE_DELETE,
        ]);

        return $user;
    }

    private function complianceSource(Customer $customer, string $name = 'ISO 27001', ?string $version = '2022', string $kind = ComplianceSource::KIND_STANDARD): ComplianceSource
    {
        return ComplianceSource::query()->create([
            'customer_id' => $customer->id,
            'name' => $name,
            'version' => $version,
            'kind' => $kind,
        ]);
    }

    private function complianceRequirement(ComplianceSource $source, string $title, ?User $owner = null, ?string $reference = null, ?int $interval = null): ComplianceRequirement
    {
        return ComplianceRequirement::query()->create([
            'customer_id' => $source->customer_id,
            'source_id' => $source->id,
            'reference' => $reference,
            'title' => $title,
            'requirement_text' => 'Kravtekst for '.$title,
            'owner_user_id' => $owner?->id,
            'review_interval_months' => $interval,
        ]);
    }

    /** @return array<string, mixed> */
    private function requirementPayload(ComplianceSource $source, User $owner, string $title = 'Tilgangsstyring', ?string $reference = 'A.5.15', ?int $interval = 12): array
    {
        return [
            'source_id' => $source->id,
            'reference' => $reference,
            'title' => $title,
            'requirement_text' => 'Regler for fysisk og logisk tilgang skal etableres.',
            'owner_user_id' => $owner->id,
            'review_interval_months' => $interval,
        ];
    }

    /** compliance.view + compliance.audit: plans and runs audits, and nothing else. */
    private function complianceAuditor(Customer $customer, array $extra = []): User
    {
        $user = $this->complianceMember($customer);
        $this->complianceGrant($customer, $user, [CustomerPermissionCatalog::COMPLIANCE_VIEW, CustomerPermissionCatalog::COMPLIANCE_AUDIT, ...$extra]);

        return $user;
    }

    /**
     * An audit as the forms would have registered it — planned — or moved straight to another status
     * the way the lifecycle leaves it (without history; tests that care about history go through
     * the lifecycle).
     */
    private function complianceAudit(Customer $customer, ?User $responsible, string $title = 'Internrevisjon tilgangsstyring', string $status = ComplianceAudit::STATUS_PLANNED, string $type = ComplianceAudit::TYPE_INTERNAL): ComplianceAudit
    {
        $audit = ComplianceAudit::query()->create([
            'customer_id' => $customer->id,
            'title' => $title,
            'audit_type' => $type,
            'responsible_user_id' => $responsible?->id,
            'planned_start_date' => '2026-11-01',
            'planned_end_date' => '2026-11-15',
            'scope_description' => 'Tilgangsstyring og brukeradministrasjon for IT-avdelingen.',
        ]);

        if ($status !== ComplianceAudit::STATUS_PLANNED) {
            $audit->forceFill([
                'status' => $status,
                'conclusion' => $status === ComplianceAudit::STATUS_COMPLETED ? 'Ingen vesentlige avvik.' : null,
            ])->save();
        }

        return $audit;
    }

    /** @return array<string, mixed> */
    private function auditPayload(User $responsible, string $title = 'Internrevisjon tilgangsstyring', string $type = ComplianceAudit::TYPE_INTERNAL): array
    {
        return [
            'title' => $title,
            'audit_type' => $type,
            'responsible_user_id' => $responsible->id,
            'auditor_name' => null,
            'planned_start_date' => '2026-11-01',
            'planned_end_date' => '2026-11-15',
            'scope_description' => 'Intern revisjon av tilgangsstyring og brukeradministrasjon for IT-avdelingen.',
        ];
    }
}
