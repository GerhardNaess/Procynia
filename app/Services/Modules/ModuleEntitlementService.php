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
 * Changing packages is an entitlement change and nothing more. Moving down the ladder or cancelling
 * Anbud marks rows revoked; it never deletes a module's data, a role or a permission. The data is
 * simply out of reach until a package carrying its module is active again — and then it is all
 * still there.
 */
class ModuleEntitlementService
{
    /** A step of the ladder Basis → Styring → ISO → GRC. A customer is on at most one. */
    public const KIND_MAIN = 'main';

    /** Held beside the main package, and cancelled on its own (Anbud). */
    public const KIND_ADDON = 'addon';

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
                'kind' => ($package['kind'] ?? null) === self::KIND_MAIN ? self::KIND_MAIN : self::KIND_ADDON,
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

    public function isMainPackage(string $packageKey): bool
    {
        return ($this->package($packageKey)['kind'] ?? null) === self::KIND_MAIN;
    }

    public function isAddOn(string $packageKey): bool
    {
        return ($this->package($packageKey)['kind'] ?? null) === self::KIND_ADDON;
    }

    /**
     * The step of the ladder the customer is on: the highest active main package, so GRC over ISO
     * over Styring over Basis. The one place that ranking is applied — the Abonnement page, the
     * actions it offers and the package change below all read it from here.
     *
     * Rows below the effective step can still be active: before package changes replaced the main
     * package, ordering a higher step left the lower row standing. They grant nothing the effective
     * step does not already carry, and the next package change revokes them. Null when the customer
     * holds no main package at all (an Anbud-only customer).
     */
    public function effectiveMainPackage(Customer $customer): ?string
    {
        $main = array_values(array_filter(
            $this->activePackageKeys($customer),
            fn (string $key): bool => $this->isMainPackage($key),
        ));

        // activePackageKeys() is in catalog order, which is ladder order.
        return $main === [] ? null : $main[array_key_last($main)];
    }

    /**
     * Put the customer on another step of the ladder, upwards or downwards.
     *
     * The target is activated and every other active main package revoked in one transaction,
     * under a lock on the customer row, so no request ever sees the customer on two steps or on
     * none. Nothing else is touched: no module data, no role, no permission. Whatever a lower step
     * no longer carries is out of reach, not gone, and moving back up finds it intact.
     */
    public function changeMainPackage(Customer $customer, string $packageKey, ?User $changedBy = null): CustomerPackageEntitlement
    {
        $package = $this->package($packageKey);

        if ($package === null || ! $package['orderable'] || $package['kind'] !== self::KIND_MAIN) {
            throw new InvalidArgumentException("Package [{$packageKey}] is not a main package that can be ordered.");
        }

        return DB::transaction(function () use ($customer, $packageKey, $changedBy): CustomerPackageEntitlement {
            $this->lockCustomer($customer);

            $entitlement = $this->writeActive($customer, $packageKey, $changedBy);

            $customer->packageEntitlements()
                ->active()
                ->where('package_key', '!=', $packageKey)
                ->whereIn('package_key', $this->mainPackageKeys())
                ->update([
                    'status' => CustomerPackageEntitlement::STATUS_REVOKED,
                    'deactivated_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);

            return $entitlement;
        });
    }

    /**
     * Cancel an add-on (Anbud). The row is marked revoked — the order history stays on it — and the
     * add-on's modules are out of reach unless the main package carries them too (Wiki). Nothing the
     * customer registered under it is deleted, and ordering it again opens it all up as it was.
     *
     * Returns false when the add-on was not active, so there was nothing to cancel.
     */
    public function cancelAddOn(Customer $customer, string $packageKey): bool
    {
        if (! $this->isAddOn($packageKey)) {
            throw new InvalidArgumentException("Package [{$packageKey}] is not an add-on.");
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

        // A step of the ladder replaces the one the customer is on rather than piling on top of it.
        if ($package['kind'] === self::KIND_MAIN) {
            return $this->changeMainPackage($customer, $packageKey, $activatedBy);
        }

        return $this->writeActive($customer, $packageKey, $activatedBy);
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

    /** @return list<string> */
    private function mainPackageKeys(): array
    {
        return array_keys(array_filter(
            $this->packages(),
            fn (array $package): bool => $package['kind'] === self::KIND_MAIN,
        ));
    }

    /**
     * Give a newly created customer the default package (config `procynia_modules.default_package`).
     *
     * Called explicitly wherever a customer is created, so the grant is an ordinary active row and
     * never a read-time assumption. It never overrides an explicit choice: if the customer already
     * holds a package that carries every module of the default one (Styring, ISO, GRC — or the
     * default itself), nothing is written. An add-on such as Anbud carries less, so it does not
     * stand in for the default. Re-running it is a no-op, so there is never a second row.
     */
    public function grantDefaultPackage(Customer $customer, ?User $grantedBy = null): ?CustomerPackageEntitlement
    {
        $defaultKey = (string) config('procynia_modules.default_package', '');
        $defaultModules = $this->modulesForPackage($defaultKey);

        if ($defaultModules === []) {
            return null;
        }

        foreach ($this->activePackageKeys($customer) as $heldKey) {
            if (array_diff($defaultModules, $this->modulesForPackage($heldKey)) === []) {
                return null;
            }
        }

        return $this->activatePackage($customer, $defaultKey, $grantedBy);
    }

    /**
     * The catalog as the Abonnement page needs it: one entry per package, each already told its
     * status and the one action it offers. The page renders this verdict; it does not compute one
     * of its own.
     *
     *  - The effective main package is `active` and offers `change` (Endre pakke). It is never
     *    cancelled: the customer moves to another step instead.
     *  - Steps below it are `included` in it and offer nothing, and carry no date of their own.
     *  - Steps above it offer `upgrade`; with no main package at all, every step offers `order`.
     *  - An add-on is `active` with `cancel`, or offers `order`.
     *
     * `modules_lost` / `modules_gained` say what taking an entry's action would change for the
     * customer — the consequence the confirmation spells out before a downgrade or a cancellation.
     *
     * @return list<array{key: string, kind: string, orderable: bool, status: string, included_in: ?string, action: ?string, direction: ?string, can_order: bool, modules: list<string>, modules_lost: list<string>, modules_gained: list<string>, requested_at: ?string, activated_at: ?string}>
     */
    public function overviewFor(Customer $customer): array
    {
        $catalog = $this->packages();
        $entitlements = $customer->packageEntitlements()->get()->keyBy('package_key');
        $activeKeys = $this->activePackageKeys($customer);
        $effective = $this->effectiveMainPackage($customer);
        $effectiveOrder = $effective === null ? null : $catalog[$effective]['sort_order'];
        $activeAddOns = array_values(array_filter($activeKeys, fn (string $key): bool => $this->isAddOn($key)));
        $current = $this->modulesFor($customer);
        $overview = [];

        foreach ($catalog as $key => $package) {
            $entitlement = $entitlements->get($key);
            $isMain = $package['kind'] === self::KIND_MAIN;
            $isActive = $isMain ? $key === $effective : in_array($key, $activeKeys, true);
            $isIncluded = $isMain && ! $isActive && $effectiveOrder !== null && $package['sort_order'] < $effectiveOrder;

            $status = match (true) {
                $isActive => 'active',
                $isIncluded => 'included',
                $entitlement?->status === CustomerPackageEntitlement::STATUS_REQUESTED => 'requested',
                $entitlement?->status === CustomerPackageEntitlement::STATUS_DECLINED => 'declined',
                default => 'available',
            };

            $action = match (true) {
                ! $package['orderable'] => null,
                $isMain && $isActive => 'change',
                $isMain && $isIncluded => null,
                $isMain && $effective !== null => 'upgrade',
                ! $isMain && $isActive => 'cancel',
                $status === 'requested' => null,
                default => 'order',
            };

            // What the customer would hold after this entry's action (or after moving to this step).
            $after = null;

            if ($isMain && ! $isActive) {
                $after = $this->modulesForPackages([$key, ...$activeAddOns]);
            } elseif (! $isMain) {
                $after = $isActive
                    ? $this->modulesForPackages(array_filter([$effective, ...array_diff($activeAddOns, [$key])]))
                    : $this->modulesForPackages(array_filter([$effective, ...$activeAddOns, $key]));
            }

            $direction = null;

            if ($isMain && ! $isActive && $effectiveOrder !== null) {
                $direction = $package['sort_order'] > $effectiveOrder ? 'upgrade' : 'downgrade';
            }

            $overview[] = [
                'key' => $key,
                'kind' => $package['kind'],
                'orderable' => $package['orderable'],
                'status' => $status,
                'included_in' => $isIncluded ? $effective : null,
                'action' => $action,
                'direction' => $direction,
                'can_order' => in_array($action, ['order', 'upgrade'], true),
                'modules' => $package['modules'],
                'modules_lost' => $after === null ? [] : array_values(array_diff($current, $after)),
                'modules_gained' => $after === null ? [] : array_values(array_diff($after, $current)),
                'requested_at' => $entitlement?->requested_at?->toDateString(),
                // Only the row that actually holds the access has a date worth showing; an included
                // step's own row, if any, dates an order the customer has since moved past.
                'activated_at' => $isActive ? $entitlement?->activated_at?->toDateString() : null,
            ];
        }

        return $overview;
    }

    /**
     * @param  iterable<string>  $packageKeys
     * @return list<string>
     */
    private function modulesForPackages(iterable $packageKeys): array
    {
        $modules = [];

        foreach ($packageKeys as $packageKey) {
            array_push($modules, ...$this->modulesForPackage($packageKey));
        }

        return $this->knownModules($modules);
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
