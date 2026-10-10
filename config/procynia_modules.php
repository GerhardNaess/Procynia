<?php

/**
 * The commercial package catalog and its mapping to technical modules.
 *
 * Two layers, deliberately kept apart:
 *
 *  - A **technical module** is what the product is built out of (`wiki`, `quality`, `risk`, ...).
 *    Code asks "does this customer have module X", never "did they buy package Y".
 *  - A **commercial package** is what a customer buys, and switches on one or more modules.
 *
 * The catalog is Basis plus independent options:
 *
 *  - Basis (`kind: base`) is the mandatory foundation — Wiki, Kvalitet and Avvik og forbedringer.
 *    Every customer is given it at creation (`default_package`) and it cannot be cancelled.
 *  - Each option (`kind: option`) carries one module and is ordered and cancelled on its own:
 *    Risiko, Mål og KPI, Etterlevelse og revisjon, Leverandøroppfølging and Anbud. No option
 *    requires another. Anbud also carries Wiki, because its requirement answers are drawn from the
 *    Enterprise Wiki — the one real technical dependency, and Basis carries Wiki anyway.
 *
 * Styring, ISO and GRC are not packages a customer holds. They survive only as `bundles`: named
 * selections of options, so that activating one is a shortcut for activating each option in it
 * (ModuleEntitlementService::activatePackage()). Afterwards the customer simply holds those
 * options, and can cancel any one of them alone.
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
        // Avvik og forbedringer. Classic quality management, so it is part of Basis. Its own module so
        // the rail and the route guard name it directly.
        'improvements' => ['sort_order' => 37],
        // Etterlevelse og revisjon (Krav and Revisjoner) — an option.
        'compliance' => ['sort_order' => 40],
        // Leverandøroppfølging — an option. Its routes are `app.supplier-management.`, never
        // `app.suppliers.`, which is Anbud's.
        'supplier' => ['sort_order' => 50],
        // Ledelsens gjennomgåelse. Part of Basis: every management system needs one, and it works
        // with whatever other modules the customer holds (docs/management-review-v1-plan.md §1).
        'management_review' => ['sort_order' => 55],
        'contracts' => ['sort_order' => 60],
    ],

    /**
     * The package a new customer is given when it is created. It is written as an ordinary active
     * entitlement row at creation (ModuleEntitlementService::grantDefaultPackage()), never assumed
     * at read time: a customer's modules are always exactly what its rows say.
     */
    'default_package' => 'basis',

    'packages' => [

        // Basis: the mandatory foundation.
        'basis' => [
            'kind' => 'base',
            'orderable' => true,
            'sort_order' => 10,
            'modules' => ['wiki', 'quality', 'improvements', 'management_review'],
        ],

        // The options, one module each, in product order.
        'risk' => [
            'kind' => 'option',
            'orderable' => true,
            'sort_order' => 20,
            'modules' => ['risk'],
        ],

        'objectives' => [
            'kind' => 'option',
            'orderable' => true,
            'sort_order' => 30,
            'modules' => ['objectives'],
        ],

        'compliance' => [
            'kind' => 'option',
            'orderable' => true,
            'sort_order' => 40,
            'modules' => ['compliance'],
        ],

        'supplier' => [
            'kind' => 'option',
            'orderable' => true,
            'sort_order' => 50,
            'modules' => ['supplier'],
        ],

        // Anbud carries Wiki because the bid engine answers requirements from it.
        'tender' => [
            'kind' => 'option',
            'orderable' => true,
            'sort_order' => 60,
            'modules' => ['wiki', 'tender'],
        ],

    ],

    /**
     * Named selections of options — never held as packages. Activating one activates Basis and each
     * option listed; nothing records that it was a bundle. No price, no discount: a grouping only.
     */
    'bundles' => [
        'governance' => ['risk', 'objectives'],
        'iso' => ['risk', 'objectives', 'compliance'],
        'grc' => ['risk', 'objectives', 'compliance', 'supplier'],
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
        'app.management-review.' => 'management_review',
    ],

];
