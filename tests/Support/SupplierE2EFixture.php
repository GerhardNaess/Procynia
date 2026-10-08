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
use App\Models\SupplierAssuranceDecision;
use App\Models\SupplierComplianceRequirement;
use App\Models\SupplierControlRequirement;
use App\Models\SupplierCriticalityChange;
use App\Models\SupplierDocument;
use App\Models\SupplierDueDiligenceAssessment;
use App\Models\SupplierImprovementCase;
use App\Models\SupplierProfile;
use App\Models\SupplierProfileChange;
use App\Models\SupplierRequirementEvaluation;
use App\Models\SupplierRequirementEvaluationDocument;
use App\Models\SupplierRequirementOverride;
use App\Models\SupplierRisk;
use App\Models\SupplierStatusChange;
use App\Models\User;
use App\Services\Modules\ModuleEntitlementService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
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
 * leverandørprofil and profile history, the kontrollkrav, overrides and controls with their
 * documentation snapshots, the assurance decisions, the aktsomhetsvurderinger, their assessments, their documentation, the run's fagområder, the cases created in Avvik og
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
            app(ModuleEntitlementService::class)->activatePackage($customer, 'grc');

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
     * For the option journey (tests/e2e/package-change.spec.js): the run's customer holding Basis,
     * Risiko and Leverandøroppfølging — nothing else — with a System Owner who also holds risk.view
     * (all fagområder) and supplier.view, the explicit grants System Owner needs like everyone else,
     * and one active supplier already registered.
     *
     * @return array{email: string, supplier_name: string}
     */
    public static function seedPackageJourney(string $suffix, string $password): array
    {
        self::seedJourney($suffix, $password);

        $customer = Customer::query()->where('name', '~', self::pattern($suffix))->sole();
        $entitlements = app(ModuleEntitlementService::class);

        foreach (['objectives', 'compliance'] as $option) {
            $entitlements->cancelOption($customer, $option);
        }

        $name = self::namer($suffix);
        $owner = self::person($customer, $suffix, $password, $name('Systemeier'), 'eier');
        $owner->forceFill(['role' => User::ROLE_CUSTOMER_ADMIN, 'bid_role' => User::BID_ROLE_SYSTEM_OWNER])->save();
        self::role($customer, $name('Leverandør- og risikoinnsyn'), [CustomerPermissionCatalog::SUPPLIER_VIEW, CustomerPermissionCatalog::RISK_VIEW], $owner)
            ->syncBusinessAreas(true, []);
        $supplier = self::activeSupplier($suffix, $name('Driftspartner AS'));

        return ['email' => $owner->email, 'supplier_name' => $supplier['name']];
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
     * given level and interval (Ja to the questions in $yes, Nei to the rest) — or not classified
     * without a level.
     *
     * @param  list<string>  $yes
     * @return array{id: int, name: string}
     */
    public static function activeSupplier(string $suffix, string $name, ?string $criticality = null, ?int $intervalMonths = null, array $yes = []): array
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
                + array_fill_keys($yes, true)
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

    /**
     * Gives the supplier manager supplier.assure (Leverandørkontroll) and registers two catalogue
     * requirements by hand — no templates: «Databehandleravtale», mandatory, for data processors, and
     * «Oversikt over underleverandører», important, for suppliers that use subcontractors.
     *
     * @return array{dpa_title: string, subcontractors_title: string}
     */
    public static function seedAssurance(string $suffix): array
    {
        $customer = Customer::query()->where('name', '~', self::pattern($suffix))->sole();
        $manager = User::query()->where('email', 'e2e.lev.'.strtolower($suffix).'.ansvarlig@procynia.test')->sole();
        $name = self::namer($suffix);

        return DB::transaction(function () use ($customer, $manager, $name): array {
            self::role($customer, $name('Leverandørkontrollør'), [CustomerPermissionCatalog::SUPPLIER_ASSURE], $manager);
            $requirement = fn (string $title, string $level, array $rule): SupplierControlRequirement => SupplierControlRequirement::query()->create([
                'customer_id' => $customer->id,
                'title' => $title,
                'theme' => 'privacy',
                'level' => $level,
                'control_point' => 'before_contract',
                'applies_when' => $rule,
                'basis_text' => 'Personvernforordningen art. 28',
                'created_by' => $manager->id,
                'updated_by' => $manager->id,
            ]);
            $dpa = $requirement($name('Databehandleravtale'), 'mandatory', [['processor']]);
            $subcontractors = $requirement($name('Oversikt over underleverandører'), 'important', [['subcontractors']]);

            return ['dpa_title' => $dpa->title, 'subcontractors_title' => $subcontractors->title];
        });
    }

    /**
     * Kontroll forfalt with explicit historical dates, instead of moving the clock: a Viktig
     * requirement for every supplier, controlled every 6 months, and a Dokumentert control of it on
     * the supplier dated 7 months ago, resting on a report without expiry. Its control date passed a
     * month ago. Written as SupplierRequirementEvaluationService would store it.
     *
     * @return array{requirement_title: string, document_title: string, due_on: string}
     */
    public static function seedOverdueControl(string $suffix, int $supplierId): array
    {
        $supplier = Supplier::query()->whereKey($supplierId)->where('name', 'like', '%'.$suffix.'%')->sole();
        $manager = User::query()->where('email', 'e2e.lev.'.strtolower($suffix).'.ansvarlig@procynia.test')->sole();
        $name = self::namer($suffix);
        $evaluatedOn = now()->subMonthsNoOverflow(7)->toDateString();

        return DB::transaction(function () use ($supplier, $manager, $name, $evaluatedOn): array {
            $requirement = SupplierControlRequirement::query()->create([
                'customer_id' => $supplier->customer_id,
                'title' => $name('Uavhengig sikkerhetsrapport'),
                'theme' => 'information_security',
                'level' => 'important',
                'control_point' => 'ongoing',
                'control_interval_months' => 6,
                'applies_when' => [],
                'created_by' => $manager->id,
                'updated_by' => $manager->id,
            ]);
            $document = SupplierDocument::query()->create([
                'customer_id' => $supplier->customer_id,
                'supplier_id' => $supplier->id,
                'document_type' => 'audit_report',
                'title' => $name('SOC 2-rapport'),
            ]);
            $evaluationId = DB::table('supplier_requirement_evaluations')->insertGetId([
                'customer_id' => $supplier->customer_id, 'supplier_id' => $supplier->id, 'requirement_id' => $requirement->id,
                'status' => SupplierRequirementEvaluation::STATUS_DOCUMENTED, 'rationale' => 'Rapporten dekker kravet.',
                'evaluated_on' => $evaluatedOn, 'evaluated_by_user_id' => $manager->id, 'recorded_at' => now(),
                'requirement_title' => $requirement->title, 'requirement_level' => $requirement->level, 'requirement_theme' => $requirement->theme,
                'applicability_reason' => 'Gjelder alle leverandører', 'supplier_name' => $supplier->name, 'criticality' => $supplier->criticality,
            ]);
            DB::table('supplier_requirement_evaluation_documents')->insert([
                'customer_id' => $supplier->customer_id, 'evaluation_id' => $evaluationId, 'supplier_document_id' => $document->id,
                'document_type' => $document->document_type, 'document_title' => $document->title,
            ]);

            return [
                'requirement_title' => $requirement->title,
                'document_title' => $document->title,
                'due_on' => Carbon::parse($evaluatedOn)->addMonthsNoOverflow(6)->toDateString(),
            ];
        });
    }

    /**
     * A product supplier whose profile makes an aktsomhetsvurdering relevant (supplier-assurance-v2-plan
     * §11.3): textiles, produced outside Norway/the EEA with subcontractors, labour intensity not
     * clarified. Written as the profile itself, without a history row — the journey does not read it.
     *
     * @return array{high_risk_category: string}
     */
    public static function seedProductProfile(string $suffix, int $supplierId): array
    {
        $supplier = Supplier::query()->whereKey($supplierId)->where('name', 'like', '%'.$suffix.'%')->sole();
        $manager = User::query()->where('email', 'e2e.lev.'.strtolower($suffix).'.ansvarlig@procynia.test')->sole();

        (new SupplierProfile)->forceFill([
            'supplier_id' => $supplier->id,
            'customer_id' => $supplier->customer_id,
            'production_outside_eea' => 'yes',
            'high_risk_categories' => ['textiles'],
            'uses_subcontractors' => 'yes',
            'labour_intensive' => 'unknown',
            'updated_by' => $manager->id,
        ])->save();

        return ['high_risk_category' => 'textiles'];
    }

    /** @return array{customers: int, suppliers: int, status_changes: int, criticality_changes: int, profiles: int, profile_changes: int, control_requirements: int, requirement_overrides: int, requirement_evaluations: int, evaluation_documents: int, assurance_decisions: int, due_diligence_assessments: int, assessments: int, documents: int, improvement_cases: int, case_links: int, risks: int, risk_links: int, requirements: int, requirement_links: int, business_areas: int, roles: int, users: int} */
    public static function remaining(string $suffix): array
    {
        $customerIds = Customer::query()->where('name', '~', self::pattern($suffix))->pluck('id');

        return [
            'customers' => $customerIds->count(),
            'suppliers' => Supplier::query()->whereIn('customer_id', $customerIds)->count(),
            'status_changes' => SupplierStatusChange::query()->whereIn('customer_id', $customerIds)->count(),
            'criticality_changes' => SupplierCriticalityChange::query()->whereIn('customer_id', $customerIds)->count(),
            'profiles' => SupplierProfile::query()->whereIn('customer_id', $customerIds)->count(),
            'profile_changes' => SupplierProfileChange::query()->whereIn('customer_id', $customerIds)->count(),
            'control_requirements' => SupplierControlRequirement::query()->whereIn('customer_id', $customerIds)->count(),
            'requirement_overrides' => SupplierRequirementOverride::query()->whereIn('customer_id', $customerIds)->count(),
            'requirement_evaluations' => SupplierRequirementEvaluation::query()->whereIn('customer_id', $customerIds)->count(),
            'evaluation_documents' => SupplierRequirementEvaluationDocument::query()->whereIn('customer_id', $customerIds)->count(),
            'assurance_decisions' => SupplierAssuranceDecision::query()->whereIn('customer_id', $customerIds)->count(),
            'due_diligence_assessments' => SupplierDueDiligenceAssessment::query()->whereIn('customer_id', $customerIds)->count(),
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
