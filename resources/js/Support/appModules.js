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
 * decided here. A module is reachable only when it is both built and entitled.
 *
 * Keeping them apart is what makes the GRC case behave. GRC grants `quality`, `risk` and
 * `audit_compliance`; only `quality` and `risk` have pages, so buying GRC lights up Kvalitet and
 * Risiko and leaves Revisjon & Compliance exactly as planned as it was. An entitlement can never conjure a
 * destination that does not exist.
 *
 * `module: null` means the entry is not something a customer buys — Hjem is the app itself, and
 * the unbuilt entries have no technical module assigned yet.
 *
 * A THIRD GATE, AND ONLY WHERE A MODULE HAS ONE.
 *
 * `permission` names a key from CustomerPermissionCatalog the person must hold to have anything to
 * do in the module at all. Where it is set, an entry the person has no permission in is dropped
 * from the rail entirely rather than dimmed: "Ikke bestilt" and "Planlagt" are statements about
 * the product, and neither is true of a module the virksomhet owns and simply has not given this
 * person. Entries that declare no `permission` are untouched by this, which is why adding it to
 * one module changes nothing about the others.
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
        // Wiki/Core is the mandatory module every customer holds, so this entry can never be
        // dimmed — but it is still resolved through entitlements rather than asserted here.
        module: 'wiki_core',
        // Every customer holds the module; not every person has been given work in it. The Wiki
        // controllers refuse the page without this key, so the rail must not offer it either.
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
        module: 'risk',
        // RiskController refuses the page without this key. Which risks the person then sees is a
        // second, server-side question (tilgangsområder) the rail never answers.
        permission: 'risk.view',
        label: (m) => m.risk ?? 'Risiko',
        areas: ['risk'],
    },
    { key: 'suppliers', built: false, module: 'supplier', label: (m) => m.suppliers ?? 'Leverandører' },
    { key: 'contracts', built: false, module: 'contracts', label: (m) => m.contracts ?? 'Kontrakter' },
    { key: 'hse', built: false, module: null, label: (m) => m.hse ?? 'HMS' },
    { key: 'compliance', built: false, module: 'audit_compliance', label: (m) => m.compliance ?? 'Revisjon & Compliance' },
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
 * - `not_ordered` — built, but this customer has not bought it. Dimmed, and says so: the module
 *                 is finished, the customer simply does not have it, which is a different message
 *                 from "not built yet" and leads somewhere different (Abonnement).
 * - `planned`   — not built. Dimmed, whether or not an entitlement happens to cover it.
 *
 * - `not_permitted` — built and entitled, but this person holds none of the module's permissions.
 *                 Not shown at all: see the note on `permission` above.
 *
 * `planned` is checked first on purpose. That single ordering is what stops a package from
 * advertising a destination that does not exist. `not_permitted` is checked last, after the
 * module is known to be both built and bought — a person's permissions are not a reason to hide
 * that the product has Risiko, or that Kvalitet could be ordered.
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
