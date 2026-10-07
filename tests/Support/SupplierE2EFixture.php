<?php

namespace Tests\Support;

use App\Models\Customer;
use App\Models\CustomerPackageEntitlement;
use App\Models\CustomerRole;
use App\Models\Supplier;
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
 * Cleanup removes the run's customer; its suppliers and their history go with it. The history
 * trigger allows that one delete (the customer going), so no trigger is switched off.
 */
class SupplierE2EFixture
{
    public const PREFIX = 'E2E Lev';

    private const SYSTEM_OWNER_EMAIL = 'e2e.systemowner@procynia.test';

    private const SUFFIX_PATTERN = '[A-Z0-9]{6}';

    /** Leftovers from interrupted runs are only swept once they are this old, so a run in flight is never touched. */
    private const SWEEP_MIN_AGE_MINUTES = 10;

    /**
     * The run's GRC customer with two people: a supplier manager (view, edit, delete) who works
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
                CustomerPermissionCatalog::SUPPLIER_DELETE,
            ], $manager);

            $colleague = self::person($customer, $suffix, $password, $name('Kollega'), 'kollega');
            self::role($customer, $name('Leverandørleser'), [CustomerPermissionCatalog::SUPPLIER_VIEW], $colleague);

            return ['email' => $manager->email, 'name' => $manager->name, 'colleague_name' => $colleague->name];
        });
    }

    /** @return array{customers: int, suppliers: int, status_changes: int, roles: int, users: int} */
    public static function remaining(string $suffix): array
    {
        $customerIds = Customer::query()->where('name', '~', self::pattern($suffix))->pluck('id');

        return [
            'customers' => $customerIds->count(),
            'suppliers' => Supplier::query()->whereIn('customer_id', $customerIds)->count(),
            'status_changes' => SupplierStatusChange::query()->whereIn('customer_id', $customerIds)->count(),
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
                // Suppliers and their history go with the customer.
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
