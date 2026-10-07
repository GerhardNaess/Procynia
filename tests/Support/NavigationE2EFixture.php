<?php

namespace Tests\Support;

use App\Models\BusinessArea;
use App\Models\Customer;
use App\Models\CustomerPackageEntitlement;
use App\Models\CustomerRole;
use App\Models\ImprovementCase;
use App\Models\User;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Test-only setup and cleanup for the Styring navigation checks in tests/e2e/app-navigation.spec.js.
 * Not autoloaded in production (autoload-dev only) — invoked via `php artisan tinker --execute=...`.
 *
 * Everything it creates is named «E2E Nav <SUFFIX> <anything>», and cleanup matches that prefix and
 * nothing else — the same rule as ImprovementE2EFixture. Roles and fagområder live in the E2E
 * customer; the package checks get customers of their own (packageCustomer), named the same way,
 * because what they compare is what different customers have bought.
 */
class NavigationE2EFixture
{
    public const PREFIX = 'E2E Nav';

    private const SYSTEM_OWNER_EMAIL = 'e2e.systemowner@procynia.test';

    private const USER_EMAIL = 'e2e.user@procynia.test';

    /**
     * Hands the E2E user a role with the given permission keys in one new fagområde.
     *
     * @param  list<string>  $permissionKeys
     * @return array{area_id: int}
     */
    public static function grant(string $suffix, array $permissionKeys): array
    {
        $customerId = self::customerId();
        $user = User::query()->where('email', self::USER_EMAIL)->firstOrFail();

        return DB::transaction(function () use ($customerId, $user, $suffix, $permissionKeys): array {
            $area = BusinessArea::query()->create(['customer_id' => $customerId, 'name' => self::name($suffix, 'Område')]);

            $role = CustomerRole::query()->create(['customer_id' => $customerId, 'name' => self::name($suffix, 'Rolle'), 'is_active' => true]);
            $role->syncPermissions($permissionKeys);
            $role->syncBusinessAreas(false, [$area->id]);
            $user->customerRoles()->attach($role->id, ['customer_id' => $customerId]);

            return ['area_id' => (int) $area->id];
        });
    }

    /**
     * improvement.view for the E2E user and one case they can open, for checking the rail on a
     * detail page.
     *
     * @return array{case_id: int}
     */
    public static function improvementCase(string $suffix): array
    {
        ['area_id' => $areaId] = self::grant($suffix, [CustomerPermissionCatalog::IMPROVEMENT_VIEW]);

        $case = ImprovementCase::query()->create([
            'customer_id' => self::customerId(),
            'business_area_id' => $areaId,
            'type' => ImprovementCase::TYPE_DEVIATION,
            'title' => self::name($suffix, 'Avvik'),
            'description' => 'Registrert av E2E-fixturen.',
        ]);

        return ['case_id' => (int) $case->id];
    }

    /**
     * A customer of its own holding exactly the given packages — no Anbud unless it is listed — and
     * one person in it whose role may view every module, so what the rail shows is decided by the
     * packages alone.
     *
     * @param  list<string>  $packages
     * @return array{email: string}
     */
    public static function packageCustomer(string $suffix, string $label, array $packages, string $password): array
    {
        $template = Customer::query()->findOrFail(self::customerId());

        return DB::transaction(function () use ($template, $suffix, $label, $packages, $password): array {
            $slug = Str::slug($label);
            $customer = Customer::query()->create([
                'name' => self::name($suffix, $label),
                'slug' => 'e2e-nav-'.strtolower($suffix).'-'.$slug,
                'language_id' => $template->language_id,
                'nationality_id' => $template->nationality_id,
                'is_active' => true,
            ]);

            foreach ($packages as $package) {
                CustomerPackageEntitlement::query()->create([
                    'customer_id' => $customer->id,
                    'package_key' => $package,
                    'status' => CustomerPackageEntitlement::STATUS_ACTIVE,
                    'activated_at' => now(),
                ]);
            }

            $person = User::query()->create([
                'name' => self::name($suffix, $label.' bruker'),
                'email' => 'e2e.nav.'.strtolower($suffix).'.'.$slug.'@procynia.test',
                'password' => bcrypt($password),
                'role' => User::ROLE_USER,
                'bid_role' => User::BID_ROLE_CONTRIBUTOR,
                'customer_id' => $customer->id,
                'is_active' => true,
            ]);

            $role = CustomerRole::query()->create(['customer_id' => $customer->id, 'name' => self::name($suffix, 'Alt innsyn'), 'is_active' => true]);
            $role->syncPermissions([
                CustomerPermissionCatalog::WIKI_VIEW,
                CustomerPermissionCatalog::QUALITY_VIEW,
                CustomerPermissionCatalog::RISK_VIEW,
                CustomerPermissionCatalog::OBJECTIVE_VIEW,
                CustomerPermissionCatalog::IMPROVEMENT_VIEW,
                CustomerPermissionCatalog::COMPLIANCE_VIEW,
            ]);
            $role->syncBusinessAreas(true, []);
            $person->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);

            return ['email' => $person->email];
        });
    }

    public static function cleanup(string $suffix): void
    {
        $customerId = self::customerId();
        $pattern = '^'.preg_quote(self::PREFIX).' '.preg_quote(strtoupper($suffix)).'( |$)';

        // The package customers, with everything the fixture put in them.
        DB::transaction(function () use ($pattern): void {
            foreach (Customer::query()->where('name', '~', $pattern)->get() as $customer) {
                CustomerRole::query()->where('customer_id', $customer->id)->delete();
                User::query()->where('customer_id', $customer->id)->delete();
                CustomerPackageEntitlement::query()->where('customer_id', $customer->id)->delete();
                $customer->delete();
            }
        });

        DB::transaction(function () use ($customerId, $pattern): void {
            $areaIds = BusinessArea::query()->where('customer_id', $customerId)->where('name', '~', $pattern)->pluck('id');

            ImprovementCase::query()->where('customer_id', $customerId)->whereIn('business_area_id', $areaIds)->delete();
            // Role permissions, area grants and user-role links cascade from the role.
            CustomerRole::query()->where('customer_id', $customerId)->where('name', '~', $pattern)->delete();
            BusinessArea::query()->whereIn('id', $areaIds)->delete();
        });
    }

    private static function name(string $suffix, string $label): string
    {
        return self::PREFIX.' '.strtoupper($suffix).' '.$label;
    }

    private static function customerId(): int
    {
        return (int) User::query()->where('email', self::SYSTEM_OWNER_EMAIL)->value('customer_id');
    }
}
