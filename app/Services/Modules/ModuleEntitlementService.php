<?php

namespace App\Services\Modules;

use App\Models\Customer;
use App\Models\CustomerPackageEntitlement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The single place that answers "what is this customer entitled to".
 *
 * The frontend never decides activation; it renders what this service resolved. Two questions are
 * kept apart on purpose:
 *
 *  - Which commercial packages does the customer hold? (active entitlement rows)
 *  - Which technical modules does that give them? (the package -> module mapping in config)
 *
 * Everything downstream asks the second question. Nothing should branch on a package key.
 *
 * The catalog is Basis plus independent options (config/procynia_modules.php). Cancelling an
 * option is an entitlement change and nothing more: its row is marked revoked, and no module data,
 * link, role or permission is deleted. The data is simply out of reach until the option is ordered
 * again — and then it is all still there.
 */
class ModuleEntitlementService
{
    /** Basis: mandatory, never cancelled. */
    public const KIND_BASE = 'base';

    /** An option: ordered and cancelled on its own, independent of every other option. */
    public const KIND_OPTION = 'option';

    /**
     * The commercial catalog, sorted, with each package's technical modules resolved.
     *
     * @return array<string, array{key: string, kind: string, orderable: bool, sort_order: int, modules: list<string>}>
     */
    public function packages(): array
    {
        $packages = [];

        foreach (config('procynia_modules.packages', []) as $key => $package) {
            $packages[$key] = [
                'key' => (string) $key,
                'kind' => ($package['kind'] ?? null) === self::KIND_BASE ? self::KIND_BASE : self::KIND_OPTION,
                'orderable' => (bool) ($package['orderable'] ?? false),
                'sort_order' => (int) ($package['sort_order'] ?? 0),
                'modules' => $this->knownModules(array_values($package['modules'] ?? [])),
            ];
        }

        uasort($packages, fn (array $left, array $right): int => $left['sort_order'] <=> $right['sort_order']);

        return $packages;
    }

    /**
     * @return array{key: string, kind: string, orderable: bool, sort_order: int, modules: list<string>}|null
     */
    public function package(string $packageKey): ?array
    {
        return $this->packages()[$packageKey] ?? null;
    }

    /**
     * The technical modules a single package switches on.
     *
     * @return list<string>
     */
    public function modulesForPackage(string $packageKey): array
    {
        return $this->package($packageKey)['modules'] ?? [];
    }

    /**
     * Every package the customer currently holds: its active entitlements. There is no package a
     * customer holds without a row.
     *
     * @return list<string>
     */
    public function activePackageKeys(Customer $customer): array
    {
        $catalog = $this->packages();

        $granted = $customer->packageEntitlements()
            ->active()
            ->pluck('package_key')
            ->all();

        // An entitlement row for a package that has since left the catalog grants nothing.
        $keys = array_values(array_unique(array_filter(
            $granted,
            fn (string $key): bool => isset($catalog[$key]),
        )));

        usort($keys, fn (string $left, string $right): int => $catalog[$left]['sort_order'] <=> $catalog[$right]['sort_order']);

        return $keys;
    }

    /**
     * The technical modules the customer can reach, as the union over their active packages.
     *
     * @return list<string>
     */
    public function modulesFor(Customer $customer): array
    {
        $modules = [];

        foreach ($this->activePackageKeys($customer) as $packageKey) {
            foreach ($this->modulesForPackage($packageKey) as $module) {
                $modules[$module] = true;
            }
        }

        return $this->knownModules(array_keys($modules));
    }

    public function hasModule(Customer $customer, string $moduleKey): bool
    {
        return in_array($moduleKey, $this->modulesFor($customer), true);
    }

    public function hasPackage(Customer $customer, string $packageKey): bool
    {
        return in_array($packageKey, $this->activePackageKeys($customer), true);
    }

    public function isOption(string $packageKey): bool
    {
        return ($this->package($packageKey)['kind'] ?? null) === self::KIND_OPTION;
    }

    /**
     * The packages a bundle (Styring, ISO, GRC) stands for: Basis and each option it lists. Empty
     * for anything that is not a bundle.
     *
     * @return list<string>
     */
    public function bundlePackages(string $bundleKey): array
    {
        $options = config("procynia_modules.bundles.{$bundleKey}");

        if (! is_array($options)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            [(string) config('procynia_modules.default_package', ''), ...$options],
            fn (string $key): bool => $this->package($key) !== null,
        )));
    }

    /**
     * Record a customer's interest in a package without granting it.
     *
     * This is the admin-mediated path: an order Procynia has to act on before access follows. It
     * is not what the Abonnement page does — self-service ordering activates immediately via
     * activatePackage() — but the state it writes is still a real one, because a package can be
     * registered, turned down or withdrawn outside the self-service flow.
     *
     * Re-requesting an already active package is a no-op rather than an error: the page should
     * never be able to downgrade live access by double-clicking.
     */
    public function requestPackage(Customer $customer, string $packageKey, ?User $requestedBy = null): CustomerPackageEntitlement
    {
        $package = $this->package($packageKey);

        if ($package === null || ! $package['orderable']) {
            throw new InvalidArgumentException("Package [{$packageKey}] cannot be ordered.");
        }

        $entitlement = $customer->packageEntitlements()->firstOrNew(['package_key' => $packageKey]);

        if ($entitlement->exists && $entitlement->isActive()) {
            return $entitlement;
        }

        $entitlement->fill([
            'status' => CustomerPackageEntitlement::STATUS_REQUESTED,
            'requested_by' => $requestedBy?->id,
            'requested_at' => Carbon::now(),
        ])->save();

        return $entitlement->refresh();
    }

    /**
     * Grant a package there and then.
     *
     * Self-service ordering has no approval step: the customer presses Bestill and the package is
     * theirs, so the entitlement is written active in the same request. Anything else leaves the
     * three surfaces disagreeing — the Abonnement page would say "Bestilt", the left rail "Ikke
     * bestilt" and the route guard would still refuse — which is exactly the state this replaces.
     *
     * The order is still recorded: requested_at and requested_by are kept (first order wins, so a
     * later re-activation does not rewrite who originally ordered it), and activated_at says when
     * access began. A previously revoked or declined row is reactivated in place rather than
     * duplicated, and deactivated_at is cleared so the row cannot read as both live and withdrawn.
     * Reactivating touches only the row: whatever the customer registered before is simply
     * reachable again, untouched.
     *
     * A bundle key (Styring, ISO, GRC) activates Basis and each of its options, in one transaction.
     * The customer then holds those options like any other and can cancel each one alone; the
     * returned row is the last one written.
     */
    public function activatePackage(Customer $customer, string $packageKey, ?User $activatedBy = null): CustomerPackageEntitlement
    {
        $bundle = $this->bundlePackages($packageKey);

        if ($bundle !== []) {
            return DB::transaction(function () use ($customer, $bundle, $activatedBy): CustomerPackageEntitlement {
                $this->lockCustomer($customer);

                $entitlement = null;

                foreach ($bundle as $key) {
                    $entitlement = $this->writeActive($customer, $key, $activatedBy);
                }

                return $entitlement;
            });
        }

        $package = $this->package($packageKey);

        if ($package === null || ! $package['orderable']) {
            throw new InvalidArgumentException("Package [{$packageKey}] cannot be ordered.");
        }

        return $this->writeActive($customer, $packageKey, $activatedBy);
    }

    /**
     * Cancel one option. The row is marked revoked — the order history stays on it — and only that
     * option's modules go out of reach (unless another active package carries them too, as Basis
     * carries Wiki for Anbud). Nothing the customer registered under it is deleted, and ordering it
     * again opens it all up as it was. Basis is not an option and cannot be cancelled.
     *
     * Returns false when the option was not active, so there was nothing to cancel.
     */
    public function cancelOption(Customer $customer, string $packageKey): bool
    {
        if (! $this->isOption($packageKey)) {
            throw new InvalidArgumentException("Package [{$packageKey}] is not an option that can be cancelled.");
        }

        return DB::transaction(function () use ($customer, $packageKey): bool {
            $this->lockCustomer($customer);

            return $customer->packageEntitlements()
                ->active()
                ->where('package_key', $packageKey)
                ->update([
                    'status' => CustomerPackageEntitlement::STATUS_REVOKED,
                    'deactivated_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]) > 0;
        });
    }

    /**
     * Give a newly created customer Basis (config `procynia_modules.default_package`).
     *
     * Called explicitly wherever a customer is created, so the grant is an ordinary active row and
     * never a read-time assumption. Re-running it is a no-op, so there is never a second row; an
     * option such as Anbud carries less than Basis, so it never stands in for it.
     */
    public function grantDefaultPackage(Customer $customer, ?User $grantedBy = null): ?CustomerPackageEntitlement
    {
        $defaultKey = (string) config('procynia_modules.default_package', '');

        if ($this->package($defaultKey) === null) {
            return null;
        }

        if ($this->hasPackage($customer, $defaultKey)) {
            return null;
        }

        return $this->activatePackage($customer, $defaultKey, $grantedBy);
    }

    /**
     * The catalog as the Abonnement page needs it: Basis and the options, each already told its
     * status and the one action it offers. The page renders this verdict; it does not compute one
     * of its own.
     *
     *  - Basis is `active` and offers nothing: it is never cancelled. Should a customer somehow lack
     *    it, it offers `order`, so the state is put right rather than hidden.
     *  - An option is `active` with `cancel`, or offers `order` (nothing while an order is pending).
     *
     * @return list<array{key: string, kind: string, orderable: bool, status: string, action: ?string, can_order: bool, modules: list<string>, requested_at: ?string, activated_at: ?string}>
     */
    public function overviewFor(Customer $customer): array
    {
        $entitlements = $customer->packageEntitlements()->get()->keyBy('package_key');
        $activeKeys = $this->activePackageKeys($customer);
        $overview = [];

        foreach ($this->packages() as $key => $package) {
            $entitlement = $entitlements->get($key);
            $isActive = in_array($key, $activeKeys, true);

            $status = match (true) {
                $isActive => 'active',
                $entitlement?->status === CustomerPackageEntitlement::STATUS_REQUESTED => 'requested',
                $entitlement?->status === CustomerPackageEntitlement::STATUS_DECLINED => 'declined',
                default => 'available',
            };

            $action = match (true) {
                ! $package['orderable'] || $status === 'requested' => null,
                $isActive => $package['kind'] === self::KIND_OPTION ? 'cancel' : null,
                default => 'order',
            };

            $overview[] = [
                'key' => $key,
                'kind' => $package['kind'],
                'orderable' => $package['orderable'],
                'status' => $status,
                'action' => $action,
                'can_order' => $action === 'order',
                'modules' => $package['modules'],
                'requested_at' => $entitlement?->requested_at?->toDateString(),
                'activated_at' => $isActive ? $entitlement?->activated_at?->toDateString() : null,
            ];
        }

        return $overview;
    }

    private function writeActive(Customer $customer, string $packageKey, ?User $activatedBy): CustomerPackageEntitlement
    {
        $entitlement = $customer->packageEntitlements()->firstOrNew(['package_key' => $packageKey]);

        if ($entitlement->exists && $entitlement->isActive()) {
            return $entitlement;
        }

        $now = Carbon::now();

        $entitlement->fill([
            'status' => CustomerPackageEntitlement::STATUS_ACTIVE,
            'requested_by' => $entitlement->requested_by ?? $activatedBy?->id,
            'requested_at' => $entitlement->requested_at ?? $now,
            'activated_at' => $now,
            'deactivated_at' => null,
        ])->save();

        return $entitlement->refresh();
    }

    /** Serialises package changes per customer, so two concurrent changes cannot interleave. */
    private function lockCustomer(Customer $customer): void
    {
        Customer::query()->whereKey($customer->getKey())->lockForUpdate()->first();
    }

    /**
     * Drops anything that is not in the module catalog, so a typo in a package's module list can
     * never invent an entitlement, and keeps catalog order.
     *
     * @param  list<string>  $modules
     * @return list<string>
     */
    private function knownModules(array $modules): array
    {
        $catalog = config('procynia_modules.modules', []);

        $known = array_values(array_filter(
            array_unique($modules),
            fn (string $module): bool => isset($catalog[$module]),
        ));

        usort(
            $known,
            fn (string $left, string $right): int => ($catalog[$left]['sort_order'] ?? 0) <=> ($catalog[$right]['sort_order'] ?? 0),
        );

        return $known;
    }
}
