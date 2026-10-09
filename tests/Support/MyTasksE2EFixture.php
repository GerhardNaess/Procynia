<?php

namespace Tests\Support;

use App\Models\BusinessArea;
use App\Models\ComplianceRequirement;
use App\Models\ComplianceSource;
use App\Models\Customer;
use App\Models\CustomerPackageEntitlement;
use App\Models\CustomerRole;
use App\Models\ImprovementCase;
use App\Models\Kpi;
use App\Models\Objective;
use App\Models\QualityItem;
use App\Models\Risk;
use App\Models\User;
use App\Services\Modules\ModuleEntitlementService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * For tests/e2e/my-tasks-governance.spec.js: a customer of the run's own holding Basis and GRC — no
 * Anbud — with one person who holds every styringsmodul permission and owns one object in each of
 * Risiko, Avvik og forbedringer, Etterlevelse og revisjon, Kvalitet and Mål og KPI, each with
 * something to follow up. Created outside any login, so every assignment is announced in the bell.
 */
class MyTasksE2EFixture
{
    private const PREFIX = 'E2E Oppg';

    private const SYSTEM_OWNER_EMAIL = 'e2e.systemowner@procynia.test';

    private const SWEEP_MIN_AGE_MINUTES = 10;

    /** @return array{email: string, titles: array<string, string>} */
    public static function seed(string $suffix, string $password): array
    {
        $template = Customer::query()->findOrFail((int) User::query()->where('email', self::SYSTEM_OWNER_EMAIL)->value('customer_id'));
        $name = fn (string $label): string => self::PREFIX.' '.strtoupper($suffix).' '.$label;

        $customer = Customer::query()->create([
            'name' => $name('Kunde'), 'slug' => 'e2e-oppg-'.strtolower($suffix),
            'language_id' => $template->language_id, 'nationality_id' => $template->nationality_id, 'is_active' => true,
        ]);
        $entitlements = app(ModuleEntitlementService::class);
        $entitlements->activatePackage($customer, 'basis');
        $entitlements->activatePackage($customer, 'grc');

        $person = User::query()->create([
            'name' => $name('Ansvarlig'), 'email' => 'e2e.oppg.'.strtolower($suffix).'@procynia.test',
            'password' => bcrypt($password), 'role' => User::ROLE_USER, 'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id, 'is_active' => true,
        ]);
        $role = CustomerRole::query()->create(['customer_id' => $customer->id, 'name' => $name('Styring'), 'is_active' => true]);
        $role->syncPermissions([
            CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_EDIT, CustomerPermissionCatalog::RISK_ASSESS,
            CustomerPermissionCatalog::IMPROVEMENT_VIEW, CustomerPermissionCatalog::IMPROVEMENT_EDIT,
            CustomerPermissionCatalog::COMPLIANCE_VIEW, CustomerPermissionCatalog::COMPLIANCE_ASSESS,
            CustomerPermissionCatalog::QUALITY_VIEW, CustomerPermissionCatalog::QUALITY_EDIT,
            CustomerPermissionCatalog::OBJECTIVE_VIEW, CustomerPermissionCatalog::OBJECTIVE_EDIT, CustomerPermissionCatalog::OBJECTIVE_MEASURE,
        ]);
        $role->syncBusinessAreas(true, []);
        $person->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);
        $area = BusinessArea::query()->create(['customer_id' => $customer->id, 'name' => $name('Drift')]);

        $titles = [
            'risk' => $name('Leverandørsvikt'),
            'improvements' => $name('Avvik i rutine'),
            'compliance' => $name('Tilgangsstyring'),
            'quality' => $name('Tilgangskontroll'),
            'objectives' => $name('Leveransepresisjon'),
        ];

        Risk::query()->create([
            'customer_id' => $customer->id, 'business_area_id' => $area->id, 'title' => $titles['risk'],
            'cause' => 'manglende rutiner', 'event' => 'en hendelse inntreffer', 'consequence' => 'virksomheten rammes',
            'status' => Risk::STATUS_IDENTIFIED, 'owner_user_id' => $person->id,
        ]);
        ImprovementCase::query()->create([
            'customer_id' => $customer->id, 'business_area_id' => $area->id, 'type' => ImprovementCase::TYPE_DEVIATION,
            'title' => $titles['improvements'], 'description' => 'Rutinen ble ikke fulgt.', 'status' => ImprovementCase::STATUS_OPEN,
            'owner_user_id' => $person->id, 'reported_by_user_id' => $person->id, 'due_date' => now()->subDays(3)->toDateString(),
        ]);
        $source = ComplianceSource::query()->create(['customer_id' => $customer->id, 'name' => $name('ISO 27001'), 'kind' => ComplianceSource::KIND_STANDARD]);
        ComplianceRequirement::query()->create([
            'customer_id' => $customer->id, 'source_id' => $source->id, 'title' => $titles['compliance'],
            'requirement_text' => 'Tilgang skal styres.', 'owner_user_id' => $person->id,
        ]);
        QualityItem::query()->create([
            'customer_id' => $customer->id, 'quality_type' => QualityItem::TYPE_CONTROL, 'title' => $titles['quality'],
            'status' => QualityItem::STATUS_ACTIVE, 'owner_user_id' => $person->id,
        ]);
        Objective::query()->create([
            'customer_id' => $customer->id, 'business_area_id' => $area->id, 'title' => $titles['objectives'],
            'owner_user_id' => $person->id, 'target_date' => now()->subDays(5)->toDateString(),
        ]);

        return ['email' => $person->email, 'titles' => $titles];
    }

    public static function cleanup(?string $suffix = null): void
    {
        $pattern = $suffix === null
            ? '^'.preg_quote(self::PREFIX).' [A-Z0-9]{6} Kunde$'
            : '^'.preg_quote(self::PREFIX).' '.preg_quote(strtoupper($suffix)).' Kunde$';

        $customers = Customer::query()->where('name', '~', $pattern)
            ->when($suffix === null, fn (Builder $query) => $query->where('created_at', '<', now()->subMinutes(self::SWEEP_MIN_AGE_MINUTES)))
            ->get();

        foreach ($customers as $customer) {
            DB::transaction(function () use ($customer): void {
                CustomerRole::query()->where('customer_id', $customer->id)->delete();
                User::query()->where('customer_id', $customer->id)->delete();
                CustomerPackageEntitlement::query()->where('customer_id', $customer->id)->delete();
                // Before the fagområde they hold with RESTRICT.
                Risk::query()->where('customer_id', $customer->id)->delete();
                Kpi::query()->where('customer_id', $customer->id)->delete();
                Objective::query()->where('customer_id', $customer->id)->delete();
                $customer->delete();
            });
        }
    }

    /** @return array{customers: int} */
    public static function remaining(string $suffix): array
    {
        return ['customers' => Customer::query()->where('name', '~', '^'.preg_quote(self::PREFIX).' '.preg_quote(strtoupper($suffix)).' Kunde$')->count()];
    }
}
