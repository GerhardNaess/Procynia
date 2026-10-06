<?php

/**
 * The commercial package catalog and its mapping to technical modules.
 *
 * Two layers, deliberately kept apart:
 *
 *  - A **technical module** is what the product is built out of (`tender`, `quality`, `risk`, ...).
 *    Code asks "does this customer have module X", never "did they buy package Y".
 *  - A **commercial package** is what a customer buys. One package can switch on several modules,
 *    and the same module can be reached through more than one package (GRC and Quality both carry
 *    `quality`).
 *
 * Because entitlements are stored per package and resolved through this mapping at read time,
 * extending a package here — GRC later gaining `supplier` and `contracts` — reaches every customer
 * who already holds it, with no data migration.
 *
 * `wiki_core` is the Wiki/Core module. It belongs to the mandatory `core` package, which is never
 * orderable and never stored as an entitlement row: every customer has it by definition.
 */

return [

    // `sort_order` is the product order of the modules, and the one place it is declared: the
    // left rail and the Styring landing page both list modules in this order
    // (ModuleEntitlementService::modulesFor() hands it to the rail; GovernanceController reads it).
    'modules' => [
        'wiki_core' => ['sort_order' => 0],
        'tender' => ['sort_order' => 10],
        'quality' => ['sort_order' => 20],
        'risk' => ['sort_order' => 30],
        // Mål og KPI. A general management area, not a part of Risiko or Kvalitet — which is why it
        // is its own module, carried by both packages below.
        'objectives' => ['sort_order' => 35],
        // Avvik og forbedringer. Classic quality management, so Kvalitet carries it — and GRC,
        // which carries Kvalitet. Its own module so the rail and the route guard name it directly.
        'improvements' => ['sort_order' => 37],
        // Etterlevelse og revisjon. Krav (and later revisjoner) — sold with GRC only.
        'compliance' => ['sort_order' => 40],
        'supplier' => ['sort_order' => 50],
        'contracts' => ['sort_order' => 60],
    ],

    'packages' => [

        'core' => [
            'mandatory' => true,
            'orderable' => false,
            'sort_order' => 0,
            'modules' => ['wiki_core'],
        ],

        'tender' => [
            'mandatory' => false,
            'orderable' => true,
            'sort_order' => 10,
            'modules' => ['tender'],
        ],

        'quality' => [
            'mandatory' => false,
            'orderable' => true,
            'sort_order' => 20,
            'modules' => ['quality', 'objectives', 'improvements'],
        ],

        // GRC is the compound package: governance, risk and compliance are sold as one, and the
        // module list is where it grows. `supplier` and `contracts` are expected to join it once
        // those modules exist — adding them here is the whole change.
        'grc' => [
            'mandatory' => false,
            'orderable' => true,
            'sort_order' => 30,
            'modules' => ['quality', 'risk', 'objectives', 'improvements', 'compliance'],
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
     * where a blocked request is sent, so it can never be blocked itself), for administration
     * (Kundemiljø, brukere, Abonnement — those are account functions, not product modules) and
     * for Wiki, whose module is mandatory and therefore has nothing to refuse.
     */
    'route_modules' => [
        'app.bid-status' => 'tender',
        'app.notices.' => 'tender',
        'app.suppliers.' => 'tender',
        'app.ai.' => 'tender',
        'app.quality.' => 'quality',
        'app.risk.' => 'risk',
        'app.objectives.' => 'objectives',
        'app.improvements.' => 'improvements',
        'app.compliance.' => 'compliance',
    ],

];
