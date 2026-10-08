<?php

/**
 * The commercial package catalog and its mapping to technical modules.
 *
 * Two layers, deliberately kept apart:
 *
 *  - A **technical module** is what the product is built out of (`wiki`, `quality`, `risk`, ...).
 *    Code asks "does this customer have module X", never "did they buy package Y".
 *  - A **commercial package** is what a customer buys. One package switches on several modules,
 *    and the same module is reached through more than one package.
 *
 * The governance packages form a ladder — Basis → Styring → ISO → GRC — and each one lists every
 * module it carries, including those of the step below. That is written out rather than inherited:
 * the mapping stays one plain list per package, and nothing has to resolve a chain to answer it.
 * Anbud (`tender`) is not on the ladder. It is an add-on that combines with any step, or stands
 * alone, and no governance package carries it.
 *
 * `kind` says which of the two a package is. A customer holds at most one `main` package — the step
 * of the ladder it is on; moving to another step replaces it — and any number of `addon` packages
 * beside it. Which step is the customer's is answered by
 * ModuleEntitlementService::effectiveMainPackage(), and nowhere else.
 *
 * There is no mandatory package. Wiki is an ordinary module, carried by every package that needs
 * it: the whole ladder, and Anbud, whose requirement answers are drawn from the Enterprise Wiki. A
 * customer with no package holds no module at all — which is why a new customer is handed
 * `default_package` when it is created.
 *
 * Because entitlements are stored per package and resolved through this mapping at read time,
 * extending a package here reaches every customer who already holds it, with no data migration.
 */

return [

    // `sort_order` is the product order of the modules, and the one place it is declared: the
    // left rail and the Styring landing page both list modules in this order
    // (ModuleEntitlementService::modulesFor() hands it to the rail; GovernanceController reads it).
    'modules' => [
        'wiki' => ['sort_order' => 0],
        'tender' => ['sort_order' => 10],
        'quality' => ['sort_order' => 20],
        'risk' => ['sort_order' => 30],
        // Mål og KPI. A general management area, not a part of Risiko or Kvalitet — which is why it
        // is its own module.
        'objectives' => ['sort_order' => 35],
        // Avvik og forbedringer. Classic quality management, so it is on every step of the ladder
        // from Basis up. Its own module so the rail and the route guard name it directly.
        'improvements' => ['sort_order' => 37],
        // Etterlevelse og revisjon (Krav and Revisjoner) — from ISO up.
        'compliance' => ['sort_order' => 40],
        // Leverandøroppfølging — GRC only. Its routes are `app.supplier-management.`, never
        // `app.suppliers.`, which is Anbud's.
        'supplier' => ['sort_order' => 50],
        'contracts' => ['sort_order' => 60],
    ],

    /**
     * The package a new customer is given when it is created. It is written as an ordinary active
     * entitlement row at creation (ModuleEntitlementService::grantDefaultPackage()), never assumed
     * at read time: a customer's modules are always exactly what its rows say.
     */
    'default_package' => 'basis',

    'packages' => [

        // Basis.
        'basis' => [
            'kind' => 'main',
            'orderable' => true,
            'sort_order' => 10,
            'modules' => ['wiki', 'quality', 'improvements'],
        ],

        // Styring: Basis, plus Risiko and Mål og KPI.
        'governance' => [
            'kind' => 'main',
            'orderable' => true,
            'sort_order' => 20,
            'modules' => ['wiki', 'quality', 'improvements', 'risk', 'objectives'],
        ],

        // ISO: Styring, plus Etterlevelse og revisjon.
        'iso' => [
            'kind' => 'main',
            'orderable' => true,
            'sort_order' => 30,
            'modules' => ['wiki', 'quality', 'improvements', 'risk', 'objectives', 'compliance'],
        ],

        // GRC: ISO, plus Leverandøroppfølging — the module that makes GRC more than ISO.
        'grc' => [
            'kind' => 'main',
            'orderable' => true,
            'sort_order' => 40,
            'modules' => ['wiki', 'quality', 'improvements', 'risk', 'objectives', 'compliance', 'supplier'],
        ],

        // Anbud: the add-on. It carries Wiki because the bid engine answers requirements from it.
        'tender' => [
            'kind' => 'addon',
            'orderable' => true,
            'sort_order' => 50,
            'modules' => ['wiki', 'tender'],
        ],

    ],

    /**
     * Which route names require which technical module, so the guard reads as one list instead of
     * being scattered across the route file as repeated middleware arguments.
     *
     * A key ending in "." is a route-name prefix and covers everything under it; anything else is
     * an exact route name. Matching on the name namespace rather than the URL is what makes this
     * hold up: a new route added under `app.ai.` or `app.notices.` is gated the day it is written,
     * without anyone remembering to come back here.
     *
     * Routes that are not listed are ungated. That is deliberate for `app.dashboard` (Hjem is
     * where a blocked request is sent, so it can never be blocked itself) and for administration
     * (Kundemiljø, brukere, Abonnement — those are account functions, not product modules, and
     * Abonnement is where a customer without any package orders one).
     */
    'route_modules' => [
        'app.wiki.' => 'wiki',
        'app.bid-status' => 'tender',
        'app.notices.' => 'tender',
        'app.suppliers.' => 'tender',
        'app.ai.' => 'tender',
        'app.quality.' => 'quality',
        'app.risk.' => 'risk',
        'app.objectives.' => 'objectives',
        'app.improvements.' => 'improvements',
        'app.compliance.' => 'compliance',
        // Leverandøroppfølging. Not `app.suppliers.`: that name, and /app/suppliers, are Anbud's
        // Doffin competitor view (Konkurrenter) above.
        'app.supplier-management.' => 'supplier',
    ],

];
