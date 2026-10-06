<?php

namespace App\Services\Modules;

use App\Models\Customer;
use App\Models\CustomerPackageEntitlement;
use App\Models\User;
use Illuminate\Support\Carbon;
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
 */
class ModuleEntitlementService
{
    /**
     * The commercial catalog, sorted, with each package's technical modules resolved.
     *
     * @return array<string, array{key: string, orderable: bool, sort_order: int, modules: list<string>}>
     */
    public function packages(): array
    {
        $packages = [];

        foreach (config('procynia_modules.packages', []) as $key => $package) {
            $packages[$key] = [
                'key' => (string) $key,
                'orderable' => (bool) ($package['orderable'] ?? false),
                'sort_order' => (int) ($package['sort_order'] ?? 0),
                'modules' => $this->knownModules(array_values($package['modules'] ?? [])),
            ];
        }

        uasort($packages, fn (array $left, array $right): int => $left['sort_order'] <=> $right['sort_order']);

        return $packages;
    }

    /**
     * @return array{key: string, orderable: bool, sort_order: int, modules: list<string>}|null
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
     */
    public function activatePackage(Customer $customer, string $packageKey, ?User $activatedBy = null): CustomerPackageEntitlement
    {
        $package = $this->package($packageKey);

        if ($package === null || ! $package['orderable']) {
            throw new InvalidArgumentException("Package [{$packageKey}] cannot be ordered.");
        }

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

    /**
     * The catalog as the Abonnement page needs it: one entry per package, each already told
     * whether it is active, ordered or orderable. The page renders this verdict; it does
     * not compute one of its own.
     *
     * @return list<array{key: string, orderable: bool, status: string, can_order: bool, modules: list<string>, requested_at: ?string, activated_at: ?string}>
     */
    public function overviewFor(Customer $customer): array
    {
        $entitlements = $customer->packageEntitlements()->get()->keyBy('package_key');
        $activeKeys = $this->activePackageKeys($customer);
        $overview = [];

        foreach ($this->packages() as $key => $package) {
            $entitlement = $entitlements->get($key);

            $status = match (true) {
                in_array($key, $activeKeys, true) => 'active',
                $entitlement?->status === CustomerPackageEntitlement::STATUS_REQUESTED => 'requested',
                $entitlement?->status === CustomerPackageEntitlement::STATUS_DECLINED => 'declined',
                default => 'available',
            };

            $overview[] = [
                'key' => $key,
                'orderable' => $package['orderable'],
                'status' => $status,
                'can_order' => $package['orderable'] && in_array($status, ['available', 'declined'], true),
                'modules' => $package['modules'],
                'requested_at' => $entitlement?->requested_at?->toDateString(),
                'activated_at' => $entitlement?->activated_at?->toDateString(),
            ];
        }

        return $overview;
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
