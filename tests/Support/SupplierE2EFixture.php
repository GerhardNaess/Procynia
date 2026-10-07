<?php

namespace Tests\Support;

use App\Models\BusinessArea;
use App\Models\ComplianceRequirement;
use App\Models\ComplianceSource;
use App\Models\Customer;
use App\Models\CustomerPackageEntitlement;
use App\Models\CustomerRole;
use App\Models\ImprovementCase;
use App\Models\Risk;
use App\Models\Supplier;
use App\Models\SupplierAssessment;
use App\Models\SupplierComplianceRequirement;
use App\Models\SupplierCriticalityChange;
use App\Models\SupplierDocument;
use App\Models\SupplierImprovementCase;
use App\Models\SupplierRisk;
use App\Models\SupplierStatusChange;
use App\Models\User;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Test-only setup and cleanup for the Leverandøroppfølging E2E spec
 * (tests/e2e/supplier-management.spec.js). Not autoloaded in production (autoload-dev only) —
 * invoked via `php artisan tinker --execute=...`.
 *
 * A CUSTOMER OF ITS OWN. Leverandøroppfølging is a GRC module and the shared E2E customer holds
 * ISO, so each run gets a customer of its own holding GRC, named «E2E Lev <SUFFIX> Kunde» and copied
 * from the E2E customer's language and nationality — the same arrangement as
 * NavigationE2EFixture::packageCustomer(). Its people, roles and suppliers all live inside it, so
 * nothing the run does can touch the shared E2E data, and the shared users are never given a
 * supplier role.
 *
 * Cleanup removes the run's customer; its suppliers, their status and criticality history, their
 * assessments, their documentation, the run's fagområder, the cases created in Avvik og
 * forbedringer, the risks in Risiko and the kravkilde and requirements in Etterlevelse og revisjon,
 * with the rows linking them to suppliers, go with it. Risks
 * are removed first: a risk holds its fagområde with RESTRICT. The history triggers allow that
 * one delete (the customer going), so no trigger is switched off.
 */
class SupplierE2EFixture
{
    public const PREFIX = 'E2E Lev';

    private const SYSTEM_OWNER_EMAIL = 'e2e.systemowner@procynia.test';

    private const SUFFIX_PATTERN = '[A-Z0-9]{6}';

    /** Leftovers from interrupted runs are only swept once they are this old, so a run in flight is never touched. */
    private const SWEEP_MIN_AGE_MINUTES = 10;

    /**
     * The run's GRC customer with two people: a supplier manager (view, edit, assess, delete) who works
     * through the pages, and a colleague with supplier.view who can be intern ansvarlig. The spec
     * registers every supplier itself.
     *
     * @return array{email: string, name: string, colleague_name: string}
     */
    public static function seedJourney(string $suffix, string $password): array
    {
        $template = Customer::query()->findOrFail((int) User::query()->where('email', self::SYSTEM_OWNER_EMAIL)->value('customer_id'));
        $name = self::namer($suffix);

        return DB::transaction(function () use ($template, $suffix, $password, $name): array {
            $customer = Customer::query()->create([
                'name' => $name('Kunde'),
                'slug' => 'e2e-lev-'.strtolower($suffix),
                'language_id' => $template->language_id,
                'nationality_id' => $template->nationality_id,
                'is_active' => true,
            ]);
            CustomerPackageEntitlement::query()->create([
                'customer_id' => $customer->id,
                'package_key' => 'grc',
                'status' => CustomerPackageEntitlement::STATUS_ACTIVE,
                'activated_at' => now(),
            ]);

            $manager = self::person($customer, $suffix, $password, $name('Leverandøransvarlig'), 'ansvarlig');
            self::role($customer, $name('Leverandørforvalter'), [
                CustomerPermissionCatalog::SUPPLIER_VIEW,
                CustomerPermissionCatalog::SUPPLIER_EDIT,
                CustomerPermissionCatalog::SUPPLIER_ASSESS,
                CustomerPermissionCatalog::SUPPLIER_DELETE,
            ], $manager);

            $colleague = self::person($customer, $suffix, $password, $name('Kollega'), 'kollega');
            self::role($customer, $name('Leverandørleser'), [CustomerPermissionCatalog::SUPPLIER_VIEW], $colleague);

            return ['email' => $manager->email, 'name' => $manager->name, 'colleague_name' => $colleague->name];
        });
    }

    /**
     * A supplier of the run's customer as one registered before criticality existed: active, owned
     * by the supplier manager, and not yet classified — the starting point for «Vurder kritikalitet».
     *
     * @return array{id: int, name: string}
     */
    public static function unclassifiedSupplier(string $suffix, string $name): array
    {
        return self::activeSupplier($suffix, $name);
    }

    /**
     * An active supplier of the run's customer, owned by the supplier manager, classified with the
     * given level and interval (Nei to all four questions) — or not classified without a level.
     *
     * @return array{id: int, name: string}
     */
    public static function activeSupplier(string $suffix, string $name, ?string $criticality = null, ?int $intervalMonths = null): array
    {
        $customer = Customer::query()->where('name', '~', self::pattern($suffix))->sole();
        $manager = User::query()->where('email', 'e2e.lev.'.strtolower($suffix).'.ansvarlig@procynia.test')->sole();

        $supplier = new Supplier([
            'customer_id' => $customer->id,
            'name' => $name,
            'category' => 'it_cloud',
            'deliverable_description' => 'Drift av ordreintegrasjon',
            'owner_user_id' => $manager->id,
            'created_by' => $manager->id,
            'updated_by' => $manager->id,
        ]);
        $supplier->status = Supplier::STATUS_ACTIVE;

        if ($criticality !== null) {
            $supplier->forceFill(['criticality' => $criticality, 'review_interval_months' => $intervalMonths]
                + array_fill_keys(Supplier::CRITICALITY_QUESTIONS, false));
        }

        $supplier->save();

        return ['id' => (int) $supplier->id, 'name' => $supplier->name];
    }

    /**
     * Gives the supplier manager Avvik og forbedringer — improvement.view and .edit in one fagområde
     * of the run's customer — so the spec can follow a supplier up there. The manager is also the
     * only one offered as ansvarlig.
     *
     * @return array{area_name: string}
     */
    public static function seedImprovementAccess(string $suffix): array
    {
        $customer = Customer::query()->where('name', '~', self::pattern($suffix))->sole();
        $manager = User::query()->where('email', 'e2e.lev.'.strtolower($suffix).'.ansvarlig@procynia.test')->sole();
        $name = self::namer($suffix);

        return DB::transaction(function () use ($customer, $manager, $name): array {
            $area = BusinessArea::query()->create(['customer_id' => $customer->id, 'name' => $name('Innkjøp')]);
            $role = self::role($customer, $name('Saksbehandler'), [
                CustomerPermissionCatalog::IMPROVEMENT_VIEW,
                CustomerPermissionCatalog::IMPROVEMENT_EDIT,
            ], $manager);
            $role->syncBusinessAreas(false, [$area->id]);

            return ['area_name' => $area->name];
        });
    }

    /**
     * Gives the supplier manager Risiko — risk.view, .create and .edit in one fagområde of the run's
     * customer — and registers one risk there, so the spec can both create a risk from a supplier
     * and link an existing one.
     *
     * @return array{area_name: string, existing_title: string}
     */
    public static function seedRiskAccess(string $suffix): array
    {
        $customer = Customer::query()->where('name', '~', self::pattern($suffix))->sole();
        $manager = User::query()->where('email', 'e2e.lev.'.strtolower($suffix).'.ansvarlig@procynia.test')->sole();
        $name = self::namer($suffix);

        return DB::transaction(function () use ($customer, $manager, $name): array {
            $area = BusinessArea::query()->create(['customer_id' => $customer->id, 'name' => $name('Drift')]);
            $role = self::role($customer, $name('Risikoeier'), [
                CustomerPermissionCatalog::RISK_VIEW,
                CustomerPermissionCatalog::RISK_CREATE,
                CustomerPermissionCatalog::RISK_EDIT,
            ], $manager);
            $role->syncBusinessAreas(false, [$area->id]);
            $existing = Risk::query()->create([
                'customer_id' => $customer->id,
                'business_area_id' => $area->id,
                'title' => $name('Tap av driftsleverandør'),
                'cause' => 'Én leverandør drifter alle fagsystemene',
                'event' => 'Leverandøren går konkurs',
                'consequence' => 'Fagsystemene stopper',
                'status' => Risk::STATUS_IDENTIFIED,
                'created_by' => $manager->id,
                'updated_by' => $manager->id,
            ]);

            return ['area_name' => $area->name, 'existing_title' => $existing->title];
        });
    }

    /**
     * Gives the supplier manager compliance.view in Etterlevelse og revisjon and registers a kravkilde
     * there with two active requirements, so the spec can add one to a supplier.
     *
     * @return array{source_label: string, reference: string, title: string, other_title: string}
     */
    public static function seedComplianceAccess(string $suffix): array
    {
        $customer = Customer::query()->where('name', '~', self::pattern($suffix))->sole();
        $manager = User::query()->where('email', 'e2e.lev.'.strtolower($suffix).'.ansvarlig@procynia.test')->sole();
        $name = self::namer($suffix);

        return DB::transaction(function () use ($customer, $manager, $name): array {
            self::role($customer, $name('Kravleser'), [CustomerPermissionCatalog::COMPLIANCE_VIEW], $manager);
            $source = ComplianceSource::query()->create([
                'customer_id' => $customer->id,
                'name' => $name('Driftsavtale'),
                'version' => null,
                'kind' => ComplianceSource::KIND_CONTRACT,
            ]);
            $requirement = ComplianceRequirement::query()->create([
                'customer_id' => $customer->id,
                'source_id' => $source->id,
                'reference' => '4.2',
                'title' => $name('Sikkerhetskopi hver natt'),
                'requirement_text' => 'Leverandøren skal ta sikkerhetskopi av alle data hver natt.',
            ]);
            $other = ComplianceRequirement::query()->create([
                'customer_id' => $customer->id,
                'source_id' => $source->id,
                'reference' => '4.3',
                'title' => $name('Varsling av hendelser'),
                'requirement_text' => 'Leverandøren skal varsle sikkerhetshendelser innen 24 timer.',
            ]);

            return ['source_label' => $source->name, 'reference' => $requirement->reference, 'title' => $requirement->title, 'other_title' => $other->title];
        });
    }

    /** @return array{customers: int, suppliers: int, status_changes: int, criticality_changes: int, assessments: int, documents: int, improvement_cases: int, case_links: int, risks: int, risk_links: int, requirements: int, requirement_links: int, business_areas: int, roles: int, users: int} */
    public static function remaining(string $suffix): array
    {
        $customerIds = Customer::query()->where('name', '~', self::pattern($suffix))->pluck('id');

        return [
            'customers' => $customerIds->count(),
            'suppliers' => Supplier::query()->whereIn('customer_id', $customerIds)->count(),
            'status_changes' => SupplierStatusChange::query()->whereIn('customer_id', $customerIds)->count(),
            'criticality_changes' => SupplierCriticalityChange::query()->whereIn('customer_id', $customerIds)->count(),
            'assessments' => SupplierAssessment::query()->whereIn('customer_id', $customerIds)->count(),
            'documents' => SupplierDocument::query()->whereIn('customer_id', $customerIds)->count(),
            // Also by the run's suffix in the title, wherever it might have landed.
            'improvement_cases' => ImprovementCase::query()->where(fn (Builder $query) => $query
                ->whereIn('customer_id', $customerIds)
                ->orWhere('title', 'like', '%'.strtoupper($suffix).'%'))->count(),
            'case_links' => SupplierImprovementCase::query()->whereIn('customer_id', $customerIds)->count(),
            'risks' => Risk::query()->where(fn (Builder $query) => $query
                ->whereIn('customer_id', $customerIds)
                ->orWhere('title', 'like', '%'.strtoupper($suffix).'%'))->count(),
            'risk_links' => SupplierRisk::query()->whereIn('customer_id', $customerIds)->count(),
            'requirements' => ComplianceRequirement::query()->where(fn (Builder $query) => $query
                ->whereIn('customer_id', $customerIds)
                ->orWhere('title', 'like', '%'.strtoupper($suffix).'%'))->count(),
            'requirement_links' => SupplierComplianceRequirement::query()->whereIn('customer_id', $customerIds)->count(),
            'business_areas' => BusinessArea::query()->whereIn('customer_id', $customerIds)->count(),
            'roles' => CustomerRole::query()->whereIn('customer_id', $customerIds)->count(),
            'users' => User::query()->where('email', 'like', 'e2e.lev.'.strtolower($suffix).'.%')->count(),
        ];
    }

    /** Removes the run's customer and everything in it; without a suffix, sweeps old leftovers. */
    public static function cleanup(?string $suffix = null): void
    {
        $pattern = $suffix === null ? '^'.preg_quote(self::PREFIX).' '.self::SUFFIX_PATTERN.' Kunde$' : self::pattern($suffix);
        $customers = Customer::query()->where('name', '~', $pattern)
            ->when($suffix === null, fn (Builder $query) => $query->where('created_at', '<', now()->subMinutes(self::SWEEP_MIN_AGE_MINUTES)))
            ->get();

        foreach ($customers as $customer) {
            DB::transaction(function () use ($customer): void {
                // Role permissions and user-role links cascade from the role. Deleting the people
                // nulls their names out of the history, which the trigger allows.
                CustomerRole::query()->where('customer_id', $customer->id)->delete();
                User::query()->where('customer_id', $customer->id)->delete();
                CustomerPackageEntitlement::query()->where('customer_id', $customer->id)->delete();
                // Before the fagområder they hold with RESTRICT; their links to suppliers cascade.
                Risk::query()->where('customer_id', $customer->id)->delete();
                // Suppliers, their history, assessments, documentation, the fagområde, the cases and the
                // kravkilde with its requirements go with the customer.
                $customer->delete();
            });
        }
    }

    private static function person(Customer $customer, string $suffix, string $password, string $name, string $mailbox): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => 'e2e.lev.'.strtolower($suffix).'.'.$mailbox.'@procynia.test',
            'password' => bcrypt($password),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    /** @param  list<string>  $permissionKeys */
    private static function role(Customer $customer, string $name, array $permissionKeys, User $holder): CustomerRole
    {
        $role = CustomerRole::query()->create(['customer_id' => $customer->id, 'name' => $name, 'is_active' => true]);
        $role->syncPermissions($permissionKeys);
        $holder->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);

        return $role;
    }

    private static function namer(string $suffix): \Closure
    {
        return fn (string $label): string => self::PREFIX.' '.strtoupper($suffix).' '.$label;
    }

    private static function pattern(string $suffix): string
    {
        return '^'.preg_quote(self::PREFIX).' '.preg_quote(strtoupper($suffix)).' Kunde$';
    }
}
