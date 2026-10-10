/**
 * The product structure, as a left-hand module rail.
 *
 * Procynia is being built out module by module, and the rail shows the whole planned structure —
 * not only the parts that exist. A module that is not built yet is still listed, dimmed and
 * inert, so people can see where the system is going instead of being surprised by it later.
 *
 * TWO INDEPENDENT GATES, AND NEITHER IS ENOUGH ON ITS OWN.
 *
 * `built` says the pages exist. It is a fact about this repository, so it lives here in code.
 * `module` names the technical module the entry needs, and whether the customer has that module
 * is a fact about the customer — it comes from the backend (`entitlements.modules`) and is never
 * decided here. A module is reachable only when it is both built and entitled, and a built module
 * the customer has not ordered is not on the rail at all — the rail names where a person works,
 * not what the virksomhet could buy.
 *
 * Keeping them apart is what makes a package behave. A package may carry a module before its pages
 * exist; such an entry stays dimmed as planned whatever the customer has bought. An entitlement can
 * never conjure a destination that does not exist.
 *
 * `module: null` means the entry is not something a customer buys — Hjem is the app itself, and
 * the unbuilt entries have no technical module assigned yet.
 *
 * A THIRD GATE, AND ONLY WHERE A MODULE HAS ONE.
 *
 * `permission` names a key from CustomerPermissionCatalog the person must hold to have anything to
 * do in the module at all. Where it is set, an entry the person has no permission in is dropped
 * from the rail entirely rather than dimmed: "Planlagt" is a statement about the product, and it
 * is not true of a module the virksomhet owns and simply has not given this person. Entries that declare no `permission` are untouched by this, which is why adding it to
 * one module changes nothing about the others.
 *
 * `workspace` puts a module under an arbeidsområde on the rail (see APP_WORKSPACES). It changes
 * where the module is listed, never whether it is: all three gates above still decide that.
 *
 * `subAreas` lists a module's work areas under it in the rail. Only Etterlevelse og revisjon has
 * them: its two areas, Krav and Revisjoner, are equal destinations, and the module entry alone only
 * ever led to the first. They are shown exactly when the module is — they are not entitlements or
 * permissions of their own, they inherit the module's.
 *
 * Packages never appear here. A package is product configuration (config/procynia_modules.php)
 * that resolves to technical modules on the server; the rail only ever sees those modules.
 */
export const APP_MODULES = [
    {
        key: 'home',
        href: '/app/dashboard',
        built: true,
        module: null,
        label: (m) => m.home ?? 'Hjem',
        areas: ['overview'],
    },
    {
        key: 'wiki',
        href: '/app/wiki',
        built: true,
        // Every package carries Wiki — the whole Styring ladder and Anbud — but it is still
        // resolved through entitlements rather than asserted here.
        module: 'wiki',
        // A customer holding the module has not necessarily given every person work in it. The
        // Wiki controllers refuse the page without this key, so the rail must not offer it either.
        permission: 'wiki.view',
        label: (m) => m.wiki ?? 'Wiki',
        areas: ['wiki', 'wiki-ask'],
    },
    {
        key: 'tenders',
        href: '/app/notices',
        built: true,
        module: 'tender',
        label: (m) => m.tenders ?? 'Anbud',
        areas: ['bid-status', 'procurements', 'worklist', 'ai', 'suppliers'],
    },
    {
        key: 'quality',
        href: '/app/quality',
        built: true,
        // Reached through Styring, not as a module of its own on the rail. See APP_WORKSPACES.
        workspace: 'governance',
        module: 'quality',
        // Kvalitet is the first module gated by the customer's own roles. QualityController
        // refuses the page without this key, so the rail must not offer it either.
        permission: 'quality.view',
        label: (m) => m.quality ?? 'Kvalitet',
        areas: ['quality'],
    },
    {
        key: 'risk',
        href: '/app/risk',
        built: true,
        workspace: 'governance',
        module: 'risk',
        // RiskController refuses the page without this key. Which risks the person then sees is a
        // second, server-side question (fagområder) the rail never answers.
        permission: 'risk.view',
        label: (m) => m.risk ?? 'Risiko',
        areas: ['risk'],
    },
    {
        key: 'objectives',
        href: '/app/objectives',
        built: true,
        workspace: 'governance',
        module: 'objectives',
        // ObjectiveController refuses the page without this key. Which objectives the person then
        // sees is a second, server-side question (fagområder) the rail never answers.
        permission: 'objective.view',
        label: (m) => m.objectives ?? 'Mål og KPI',
        areas: ['objectives'],
    },
    {
        key: 'improvements',
        href: '/app/improvements',
        built: true,
        workspace: 'governance',
        module: 'improvements',
        // ImprovementCaseController refuses the page without this key. Which cases the person then
        // sees is a second, server-side question (fagområder) the rail never answers.
        permission: 'improvement.view',
        label: (m) => m.improvements ?? 'Avvik og forbedringer',
        areas: ['improvements'],
    },
    {
        key: 'compliance',
        href: '/app/compliance/requirements',
        built: true,
        workspace: 'governance',
        module: 'compliance',
        // ComplianceRequirementController refuses the page without this key. compliance is an
        // explicit-grant domain: System Owner holds it only through a role of their own, so the rail
        // does not offer the module to a System Owner without one.
        permission: 'compliance.view',
        label: (m) => m.compliance ?? 'Etterlevelse og revisjon',
        areas: ['compliance'],
        // Its two work areas, listed under it in the rail as well as in the header, so Revisjoner is
        // one click away from anywhere. Keys match the header's (CustomerAppLayout).
        subAreas: [
            { key: 'compliance-requirements', href: '/app/compliance/requirements', label: (m) => m.compliance_requirements ?? 'Krav' },
            { key: 'compliance-audits', href: '/app/compliance/audits', label: (m) => m.compliance_audits ?? 'Revisjoner' },
        ],
    },
    {
        key: 'suppliers',
        href: '/app/supplier-management',
        built: true,
        workspace: 'governance',
        module: 'supplier',
        // SupplierManagementController refuses the page without this key. supplier is an
        // explicit-grant domain: System Owner holds it only through a role of their own, so the rail
        // does not offer the module to a System Owner without one.
        permission: 'supplier.view',
        label: (m) => m.suppliers ?? 'Leverandører',
        // Never `suppliers`: that area is Anbud's Doffin competitor view (/app/suppliers).
        areas: ['supplier-management'],
    },
    {
        key: 'management_review',
        href: '/app/management-reviews',
        built: true,
        workspace: 'governance',
        module: 'management_review',
        // ManagementReviewController refuses the page without this key. What a review shows from the
        // other modules is gated again, per section, on the server.
        permission: 'management_review.view',
        label: (m) => m.management_review ?? 'Ledelsens gjennomgåelse',
        areas: ['management-review'],
    },
    { key: 'contracts', built: false, module: 'contracts', label: (m) => m.contracts ?? 'Kontrakter' },
    { key: 'hse', built: false, module: null, label: (m) => m.hse ?? 'HMS' },
    { key: 'services', built: false, module: null, label: (m) => m.services ?? 'Tjenester & SLA' },
    { key: 'projects', built: false, module: null, label: (m) => m.projects ?? 'Prosjekter' },
    { key: 'competence', built: false, module: null, label: (m) => m.competence ?? 'Kompetanse' },
    { key: 'assets', built: false, module: null, label: (m) => m.assets ?? 'Utstyr & Eiendeler' },
    { key: 'reports', built: false, module: null, label: (m) => m.reports ?? 'Rapporter' },
    { key: 'settings', built: false, module: null, label: (m) => m.settings ?? 'Innstillinger' },
];

/**
 * What the rail should do with one entry, given the modules the backend says are active.
 *
 * - `active`    — built and entitled. A link.
 * - `not_ordered` — built, but this customer has not bought it. Not shown at all: the rail shows
 *                 the places a person works, not the product catalog. What could be ordered is
 *                 the Abonnement page's business.
 * - `planned`   — not built. Dimmed under Planlagt, whether or not an entitlement happens to
 *                 cover it.
 * - `not_permitted` — built and entitled, but this person holds none of the module's permissions.
 *                 Not shown at all: see the note on `permission` above.
 *
 * `planned` is checked first on purpose. That single ordering is what stops a package from
 * advertising a destination that does not exist.
 */
export function moduleAvailability(module, activeModules = [], permissions = []) {
    if (! module.built) {
        return 'planned';
    }

    if (module.module === null) {
        return 'active';
    }

    if (! activeModules.includes(module.module)) {
        return 'not_ordered';
    }

    if (module.permission && ! permissions.includes(module.permission)) {
        return 'not_permitted';
    }

    return 'active';
}

/**
 * The rail's three groups, in render order, from one pass over the catalog.
 */
export function partitionModules(activeModules = [], permissions = []) {
    const groups = { active: [], not_ordered: [], planned: [], not_permitted: [] };

    for (const module of APP_MODULES) {
        groups[moduleAvailability(module, activeModules, permissions)].push(module);
    }

    return groups;
}

/**
 * Arbeidsområder: what the rail groups modules under, so it names where a person works rather than
 * listing every internal module at the top level.
 *
 * Styring holds Kvalitet, Risiko, Mål og KPI, Avvik og forbedringer and Etterlevelse og revisjon. It
 * is not a module: no package grants it as such (the Styring package grants its modules, like the
 * others on the ladder), no permission gates it, and it owns no data. It is shown
 * exactly when at least one of its modules is `active` for this person, and it shows only those —
 * so it can never offer more than the modules themselves already would. A module in the workspace
 * that is not ordered, or that the person has no permission in, stays off the rail.
 */
export const APP_WORKSPACES = [
    {
        key: 'governance',
        href: '/app/governance',
        label: (m) => m.governance ?? 'Styring',
        areas: ['governance'],
    },
];

/**
 * The rail's top level, in render order: modules and workspaces, each workspace carrying the
 * modules of its own that are `active`. A workspace takes the place of its first module in the
 * catalog, which keeps the rail in product order; one with no active module is left out entirely,
 * so a person with nothing in Styring never sees an empty Styring.
 *
 * Inside a workspace the modules follow `activeModules`, which the backend hands over already in
 * the module order of config/procynia_modules.php (`sort_order`). That is the one place the order
 * Kvalitet, Risiko, Mål og KPI, Avvik og forbedringer, Etterlevelse og revisjon is declared, and
 * the Styring landing page (GovernanceController) reads the same one.
 *
 * Modules the customer has not ordered are not returned: the rail is built from what the customer
 * holds and the person may open, never from what merely exists in code.
 */
export function railEntries(activeModules = [], permissions = []) {
    const groups = partitionModules(activeModules, permissions);
    const entries = [];

    for (const module of groups.active) {
        if (! module.workspace) {
            entries.push(module);
            continue;
        }

        let workspace = entries.find((entry) => entry.key === module.workspace);

        if (! workspace) {
            workspace = { ...APP_WORKSPACES.find((candidate) => candidate.key === module.workspace), children: [] };
            entries.push(workspace);
        }

        workspace.children.push(module);
    }

    const position = (module) => activeModules.indexOf(module.module);

    for (const entry of entries) {
        entry.children?.sort((left, right) => position(left) - position(right));
    }

    return { entries, planned: groups.planned };
}

/**
 * Which workspace the current area sits in — its own landing page, or any page of one of its
 * modules. This is what keeps Styring lit on a detail, create or edit page, not only on an index:
 * the area is resolved from the path prefix, and the workspace from the area.
 */
export function activeWorkspaceKey(activeMainArea) {
    const own = APP_WORKSPACES.find((workspace) => workspace.areas.includes(activeMainArea));

    if (own) {
        return own.key;
    }

    const module = APP_MODULES.find((candidate) => (candidate.areas ?? []).includes(activeMainArea));

    return module?.workspace ?? null;
}

/**
 * Which module the current area belongs to.
 *
 * Administration (Kundemiljø, Abonnement) and Oppfølging are reached from the top bar and are not
 * modules, so being in one leaves the rail with nothing selected rather than lighting up a module
 * the person is not in.
 */
export function activeModuleKey(activeMainArea) {
    const match = APP_MODULES.find((module) => (module.areas ?? []).includes(activeMainArea));

    return match ? match.key : null;
}
