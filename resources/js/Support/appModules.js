/**
 * The product structure, as a left-hand module rail.
 *
 * Procynia is being built out module by module, and the rail shows the whole planned structure —
 * not only the parts that exist. A module that is not built yet is still listed, dimmed and
 * inert, so people can see where the system is going instead of being surprised by it later.
 *
 * `available: false` is the only thing that makes a module unreachable here. It carries no href
 * and no route, so there is nothing to permission-gate: the pages do not exist yet. When one is
 * built, it gets an `href`, `available: true`, and — if it has work areas of its own — `sections`.
 *
 * Sections are the level below a module (Kunngjøringer, I arbeid, Besvarelse, Konkurrenter inside
 * Anbud). Tabs inside a section stay where they always were, in the header's second row.
 */
export const APP_MODULES = [
    {
        key: 'home',
        href: '/app/dashboard',
        available: true,
        label: (m) => m.home ?? 'Hjem',
        areas: ['overview'],
    },
    {
        key: 'tenders',
        href: '/app/notices',
        available: true,
        label: (m) => m.tenders ?? 'Anbud',
        areas: ['procurements', 'worklist', 'ai', 'suppliers'],
    },
    {
        key: 'wiki',
        href: '/app/wiki',
        available: true,
        label: (m) => m.wiki ?? 'Wiki',
        areas: ['wiki', 'wiki-ask'],
    },
    {
        key: 'quality',
        href: '/app/quality',
        available: true,
        label: (m) => m.quality ?? 'Kvalitet',
        areas: ['quality'],
    },
    { key: 'risk', available: false, label: (m) => m.risk ?? 'Risiko' },
    { key: 'suppliers', available: false, label: (m) => m.suppliers ?? 'Leverandører' },
    { key: 'contracts', available: false, label: (m) => m.contracts ?? 'Kontrakter' },
    { key: 'hse', available: false, label: (m) => m.hse ?? 'HMS' },
    { key: 'compliance', available: false, label: (m) => m.compliance ?? 'Revisjon & Compliance' },
    { key: 'services', available: false, label: (m) => m.services ?? 'Tjenester & SLA' },
    { key: 'projects', available: false, label: (m) => m.projects ?? 'Prosjekter' },
    { key: 'competence', available: false, label: (m) => m.competence ?? 'Kompetanse' },
    { key: 'assets', available: false, label: (m) => m.assets ?? 'Utstyr & Eiendeler' },
    { key: 'reports', available: false, label: (m) => m.reports ?? 'Rapporter' },
    { key: 'settings', available: false, label: (m) => m.settings ?? 'Innstillinger' },
];

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
