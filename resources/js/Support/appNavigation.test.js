import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { finalizeTrail, resolveNavigation, withWorkView } from './appNavigation.js';

const here = dirname(fileURLToPath(import.meta.url));
const component = readFileSync(join(here, '..', 'Components', 'App', 'Breadcrumbs.jsx'), 'utf8');
const layout = readFileSync(join(here, '..', 'Layouts', 'CustomerAppLayout.jsx'), 'utf8');

const ALL_MODULES = ['wiki', 'tender', 'quality', 'risk', 'objectives', 'improvements', 'compliance', 'supplier', 'management_review'];
const ALL_PERMISSIONS = ['wiki.view', 'quality.view', 'risk.view', 'objective.view', 'improvement.view', 'compliance.view', 'supplier.view', 'management_review.view'];

/** A page's props as the layout receives them; only what navigation reads. */
function props(extra = {}, { modules = ALL_MODULES, permissions = ALL_PERMISSIONS, user = {} } = {}) {
    return {
        translations: {},
        auth: { user: { can_manage_customer_users: true, can_manage_watch_profiles: true, ...user } },
        entitlements: { modules },
        access: { permissions },
        ...extra,
    };
}

const trail = (url, { title = '', workView = null, extra = {}, ...options } = {}) => resolveNavigation({ url, props: props(extra, options), title, workView }).breadcrumbs;
const labels = (items) => items.map((item) => item.label);
const links = (items) => items.map((item) => item.href);

/**
 * The trail names where the page lives, not how the person got there — so every assertion below is
 * made from the URL and the page's own props alone, exactly as a direct visit or a reload sees it.
 */
describe('the examples the navigation was specified with', () => {
    test('Oppfølging: Oppfølging › the open view, and Hjem › Oppfølging without one', () => {
        const infoCenter = { active_view: 'my_tasks', view_options: [{ value: 'my_tasks', label: 'Mine oppgaver' }, { value: 'awaiting_response', label: 'Venter på svar' }] };

        assert.deepEqual(labels(trail('/app/info-center', { extra: { infoCenter } })), ['Oppfølging', 'Mine oppgaver']);
        assert.deepEqual(links(trail('/app/info-center', { extra: { infoCenter } })), ['/app/info-center', null]);
        assert.deepEqual(labels(trail('/app/info-center?view=awaiting_response', { extra: { infoCenter: { ...infoCenter, active_view: 'awaiting_response' } } })), ['Oppfølging', 'Venter på svar']);
        assert.deepEqual(labels(trail('/app/info-center')), ['Hjem', 'Oppfølging']);
    });

    test('Leverandører: the overview, Kontrollkrav, the import and one supplier', () => {
        assert.deepEqual(labels(trail('/app/supplier-management')), ['Styring', 'Leverandører']);
        assert.deepEqual(links(trail('/app/supplier-management')), ['/app/governance', null]);
        assert.deepEqual(labels(trail('/app/supplier-management/control-requirements')), ['Styring', 'Leverandører', 'Kontrollkrav']);
        assert.deepEqual(links(trail('/app/supplier-management/control-requirements')), ['/app/governance', '/app/supplier-management', null]);
        assert.deepEqual(labels(trail('/app/supplier-management/import', { title: 'Importer leverandører' })), ['Styring', 'Leverandører', 'Importer leverandører']);
        assert.deepEqual(labels(trail('/app/supplier-management/deleted', { title: 'Slettede leverandører' })), ['Styring', 'Leverandører', 'Slettede leverandører']);
        assert.deepEqual(links(trail('/app/supplier-management/deleted', { title: 'Slettede leverandører' })), ['/app/governance', '/app/supplier-management', null]);
        assert.deepEqual(labels(trail('/app/supplier-management/12', { title: 'Acme AS' })), ['Styring', 'Leverandører', 'Acme AS']);
        assert.deepEqual(links(trail('/app/supplier-management/12', { title: 'Acme AS' })), ['/app/governance', '/app/supplier-management', null]);
    });

    test('a supplier\'s own tabs are views of the same page, never levels of their own', () => {
        assert.deepEqual(labels(trail('/app/supplier-management/12?tab=requirements', { title: 'Acme AS' })), ['Styring', 'Leverandører', 'Acme AS']);
    });

    test('Etterlevelse og revisjon: each work area, and an audit under Revisjon', () => {
        assert.deepEqual(labels(trail('/app/compliance/library')), ['Styring', 'Etterlevelse og revisjon', 'Kravbibliotek']);
        assert.deepEqual(labels(trail('/app/compliance/audits')), ['Styring', 'Etterlevelse og revisjon', 'Revisjon']);
        assert.deepEqual(labels(trail('/app/compliance/audits/4', { title: 'Internrevisjon 2026' })), ['Styring', 'Etterlevelse og revisjon', 'Revisjon', 'Internrevisjon 2026']);
        assert.deepEqual(links(trail('/app/compliance/audits/4', { title: 'Internrevisjon 2026' })), ['/app/governance', '/app/compliance', '/app/compliance/audits', null]);
        assert.deepEqual(labels(trail('/app/compliance/library/2', { title: 'ISO 9001' })), ['Styring', 'Etterlevelse og revisjon', 'Kravbibliotek', 'ISO 9001']);
        assert.deepEqual(labels(trail('/app/compliance/requirements/9', { title: 'Ledelsens ansvar' })), ['Styring', 'Etterlevelse og revisjon', 'Kravregister', 'Ledelsens ansvar']);
        // The worklist marks the requirement it opened; the URL says so, so a reload agrees.
        assert.deepEqual(labels(trail('/app/compliance/requirements/9?from=assessments', { title: 'Ledelsens ansvar' })), ['Styring', 'Etterlevelse og revisjon', 'Etterlevelsesvurdering', 'Ledelsens ansvar']);
    });

    test('Kvalitet: a process sits under Prosesser, a control under Kontroller', () => {
        const at = (type) => labels(trail('/app/quality/items/5?tab=flow', { title: 'Innkjøp', extra: { item: { quality_type: type } } }));

        assert.deepEqual(at('process'), ['Styring', 'Kvalitet', 'Prosesser', 'Innkjøp']);
        assert.deepEqual(at('control'), ['Styring', 'Kvalitet', 'Kontroller', 'Innkjøp']);
        assert.deepEqual(at('policy'), ['Styring', 'Kvalitet', 'Oversikt', 'Innkjøp']);
        assert.deepEqual(labels(trail('/app/quality?tab=processes')), ['Styring', 'Kvalitet', 'Prosesser']);
        assert.deepEqual(labels(trail('/app/quality')), ['Styring', 'Kvalitet', 'Oversikt']);
    });

    test('Risiko, Mål og KPI, Avvik og forbedringer and Ledelsens gjennomgang, with their objects', () => {
        assert.deepEqual(labels(trail('/app/risk')), ['Styring', 'Risiko']);
        assert.deepEqual(labels(trail('/app/risk/risks/3', { title: 'Leverandørsvikt' })), ['Styring', 'Risiko', 'Leverandørsvikt']);
        assert.deepEqual(labels(trail('/app/objectives/2', { title: 'Færre avvik' })), ['Styring', 'Mål og KPI', 'Færre avvik']);
        assert.deepEqual(labels(trail('/app/improvements/8', { title: 'Feil i rutine' })), ['Styring', 'Avvik og forbedringer', 'Feil i rutine']);
        assert.deepEqual(labels(trail('/app/management-reviews/1', { title: 'Gjennomgang 2026' })), ['Styring', 'Ledelsens gjennomgang', 'Gjennomgang 2026']);
    });

    test('a KPI sits under its objective, which links to its own page', () => {
        const kpi = trail('/app/objectives/2/kpis/7', { title: 'Avvik per måned', extra: { objective: { title: 'Færre avvik', url: '/app/objectives/2' } } });

        assert.deepEqual(labels(kpi), ['Styring', 'Mål og KPI', 'Færre avvik', 'Avvik per måned']);
        assert.deepEqual(links(kpi), ['/app/governance', '/app/objectives', '/app/objectives/2', null]);
    });

    test('Styring and Hjem', () => {
        assert.deepEqual(labels(trail('/app/governance')), ['Hjem', 'Styring']);
        assert.deepEqual(trail('/app/dashboard'), [], 'Hjem is the top and has no trail');
    });

    test('Wiki: the tab that is open, and an article under Wiki-sider', () => {
        assert.deepEqual(labels(trail('/app/wiki')), ['Wiki', 'Wiki-sider']);
        assert.deepEqual(labels(trail('/app/wiki?tab=sources')), ['Wiki', 'Kildedokumenter']);
        assert.deepEqual(labels(trail('/app/wiki/graph')), ['Wiki', 'Grafvisning']);
        assert.deepEqual(labels(trail('/app/wiki/ask')), ['Wiki', 'Spør Wiki']);
        assert.deepEqual(labels(trail('/app/wiki/tilbudsprosess', { title: 'Tilbudsprosess' })), ['Wiki', 'Wiki-sider', 'Tilbudsprosess']);
        assert.deepEqual(links(trail('/app/wiki/tilbudsprosess', { title: 'Tilbudsprosess' })), ['/app/wiki', '/app/wiki?tab=pages', null]);
    });

    test('Anbud: its work area, the level inside it, and a case', () => {
        assert.deepEqual(labels(trail('/app/bid-status')), ['Anbud', 'Bid Status']);
        assert.deepEqual(labels(trail('/app/notices')), ['Anbud', 'Kunngjøringer', 'Live søk']);
        assert.deepEqual(labels(trail('/app/notices/44', { title: 'Rammeavtale IT' })), ['Anbud', 'Kunngjøringer', 'Rammeavtale IT']);
        // The tab shows a count; the trail names the level without it.
        assert.deepEqual(labels(trail('/app/notices?mode=saved', { extra: { worklist: { saved_count: 4 } } })), ['Anbud', 'Saksliste', 'Lagrede kunngjøringer']);
        assert.deepEqual(labels(trail('/app/notices?mode=history')), ['Anbud', 'Saksliste', 'Historikk']);
        assert.deepEqual(labels(trail('/app/notices/saved/12', { title: 'Rammeavtale IT' })), ['Anbud', 'Saksliste', 'Rammeavtale IT']);
        assert.deepEqual(labels(trail('/app/watch-profiles/create', { title: 'Legg til Watch Profile' })), ['Anbud', 'Kunngjøringer', 'Watch lists', 'Legg til Watch Profile']);
        assert.deepEqual(labels(trail('/app/suppliers/5', { title: 'Konkurrent AS' })), ['Anbud', 'Konkurrenter', 'Konkurrent AS']);
    });

    test('Besvarelse: a case is named by its title, and its instructions sit under it', () => {
        const extra = { case: { id: 7, title: 'Rammeavtale IT' } };

        assert.deepEqual(labels(trail('/app/ai/7', { title: 'I arbeid · Rammeavtale IT', extra })), ['Anbud', 'Besvarelse', 'Rammeavtale IT']);
        const instructions = trail('/app/ai/7/instructions', { extra });

        assert.deepEqual(links(instructions).slice(0, 3), ['/app/notices', '/app/ai', '/app/ai/7']);
        assert.deepEqual(labels(instructions), ['Anbud', 'Besvarelse', 'Rammeavtale IT', 'AI instrukser']);
    });

    test('Kundemiljø: its tabs, and the pages under Brukere and Avdelinger', () => {
        assert.deepEqual(labels(trail('/app/customer-environment')), ['Kundemiljø', 'Avdelinger']);
        assert.deepEqual(labels(trail('/app/customer-environment?tab=users')), ['Kundemiljø', 'Brukere']);
        assert.deepEqual(labels(trail('/app/users/create', { title: 'Legg til bruker' })), ['Kundemiljø', 'Brukere', 'Legg til bruker']);
        assert.deepEqual(links(trail('/app/users/create', { title: 'Legg til bruker' })), ['/app/customer-environment', '/app/customer-environment?tab=users', null]);
        assert.deepEqual(labels(trail('/app/departments/3/edit', { title: 'Rediger avdeling' })), ['Kundemiljø', 'Avdelinger', 'Rediger avdeling']);
        assert.deepEqual(labels(trail('/app/billing')), ['Hjem', 'Abonnement']);
    });
});

describe('a trail only links where the person can go', () => {
    test('the last level is never a link', () => {
        for (const url of ['/app/supplier-management/12', '/app/compliance/audits', '/app/governance', '/app/risk/risks/3']) {
            const items = trail(url, { title: 'Objekt' });

            assert.equal(items[items.length - 1].href, null, url);
        }
    });

    test('a module the rail does not offer this person is named, not linked', () => {
        // A person who reached a supplier through a direct link without supplier.view: the page
        // itself refuses them, but the trail must not offer the module either.
        const items = trail('/app/supplier-management/12', { title: 'Acme AS', permissions: ['quality.view'] });

        assert.deepEqual(labels(items), ['Styring', 'Leverandører', 'Acme AS']);
        assert.equal(items[1].href, null);
    });

    test('Styring is plain text when the rail has no Styring for this person', () => {
        const items = trail('/app/supplier-management/12', { title: 'Acme AS', permissions: [] });

        assert.equal(items[0].href, null);
    });

    test('Kundemiljø without the permission to manage it is plain text', () => {
        const items = trail('/app/go-no-go-templates', { user: { can_manage_customer_users: false } });

        assert.equal(items[0].label, 'Kundemiljø');
        assert.equal(items[0].href, null);
    });

    test('an AI tab without a case carries no link', () => {
        const items = trail('/app/ai');

        assert.deepEqual(labels(items), ['Anbud', 'Besvarelse', 'Oversikt']);
    });
});

describe('the finishing rules', () => {
    test('a level that repeats the one above it is left out', () => {
        assert.deepEqual(labels(finalizeTrail([{ label: 'Leverandører', href: '/a' }, { label: 'Leverandører', href: '/a' }, { label: 'Acme', href: '/b' }])), ['Leverandører', 'Acme']);
    });

    test('empty levels are left out, and a lone level is placed under Hjem', () => {
        const home = { label: 'Hjem', href: '/app/dashboard' };

        assert.deepEqual(finalizeTrail([{ label: 'Risiko', href: '/app/risk' }, { label: '', href: null }], home), [home, { label: 'Risiko', href: null }]);
        assert.deepEqual(finalizeTrail([], home), []);
    });

    test('the same URL gives the same trail and the same menu state, however it was reached', () => {
        const first = resolveNavigation({ url: '/app/supplier-management/12', props: props(), title: 'Acme AS' });
        const second = resolveNavigation({ url: '/app/supplier-management/12', props: props(), title: 'Acme AS' });

        assert.deepEqual(first.breadcrumbs, second.breadcrumbs);
        assert.equal(first.moduleKey, 'suppliers');
        assert.equal(first.workspaceKey, 'governance');
        assert.equal(first.activeModuleNavigationKey, 'supplier-suppliers');
    });

    test('translated labels are used when they are shared', () => {
        const english = props({ translations: { navigation: { modules: { governance: 'Governance', suppliers: 'Suppliers' } }, supplier_management: { tabs: { suppliers: 'Suppliers', control_requirements: 'Control requirements' } } } });

        assert.deepEqual(labels(resolveNavigation({ url: '/app/supplier-management/control-requirements', props: english }).breadcrumbs), ['Governance', 'Suppliers', 'Control requirements']);
    });
});

/**
 * A work view the person opened with a button — often without a URL of its own — is the last level
 * while it is open. The page hands its own state to the layout as `workView`; the trail never
 * guesses from what is on screen.
 */
describe('a work view opened on the page is the last level while it is open', () => {
    test('Registrer leverandør: Leverandører becomes the way back to the overview', () => {
        const items = trail('/app/supplier-management', { workView: 'Registrer leverandør' });

        assert.deepEqual(labels(items), ['Styring', 'Leverandører', 'Registrer leverandør']);
        assert.deepEqual(links(items), ['/app/governance', '/app/supplier-management', null]);
    });

    test('closing the view gives the overview its own trail back', () => {
        assert.deepEqual(labels(trail('/app/supplier-management', { workView: null })), ['Styring', 'Leverandører']);
        assert.deepEqual(labels(trail('/app/supplier-management', { workView: '' })), ['Styring', 'Leverandører']);
    });

    test('the way back keeps the overview as it was opened, filters included', () => {
        const items = trail('/app/supplier-management?status=active&search=acme', { workView: 'Registrer leverandør' });

        assert.equal(items[1].href, '/app/supplier-management?status=active&search=acme');
    });

    test('the same rule in every module', () => {
        assert.deepEqual(labels(trail('/app/compliance/audits', { workView: 'Ny revisjon' })), ['Styring', 'Etterlevelse og revisjon', 'Revisjon', 'Ny revisjon']);
        assert.deepEqual(links(trail('/app/compliance/audits', { workView: 'Ny revisjon' })), ['/app/governance', '/app/compliance', '/app/compliance/audits', null]);
        assert.deepEqual(labels(trail('/app/improvements', { workView: 'Registrer avvik' })), ['Styring', 'Avvik og forbedringer', 'Registrer avvik']);
        assert.deepEqual(labels(trail('/app/supplier-management/control-requirements', { workView: 'Nytt kontrollkrav' })), ['Styring', 'Leverandører', 'Kontrollkrav', 'Nytt kontrollkrav']);
        assert.deepEqual(labels(trail('/app/risk', { workView: 'Ny risiko' })), ['Styring', 'Risiko', 'Ny risiko']);
    });

    test('Rediger on a detail page sits under the object, which links back to it', () => {
        const items = trail('/app/supplier-management/12', { title: 'Acme AS', workView: 'Rediger' });

        assert.deepEqual(labels(items), ['Styring', 'Leverandører', 'Acme AS', 'Rediger']);
        assert.deepEqual(links(items), ['/app/governance', '/app/supplier-management', '/app/supplier-management/12', null]);
    });

    test('the menus do not move: the work view is a level of the page, not another page', () => {
        const navigation = resolveNavigation({ url: '/app/supplier-management', props: props(), workView: 'Registrer leverandør' });

        assert.equal(navigation.moduleKey, 'suppliers');
        assert.equal(navigation.activeModuleNavigationKey, 'supplier-suppliers');
    });

    test('permissions still decide the levels above it', () => {
        const items = trail('/app/supplier-management', { workView: 'Registrer leverandør', permissions: [] });

        // Styring is not offered to this person, so it stays text; the way back to the page they are
        // already on is always theirs.
        assert.equal(items[0].href, null);
        assert.equal(items[1].href, '/app/supplier-management');
    });

    test('Hjem has no trail, work view or not', () => {
        assert.deepEqual(trail('/app/dashboard', { workView: 'Noe' }), []);
    });

    test('withWorkView leaves a trail alone without a view', () => {
        const levels = [{ label: 'A', href: '/a' }, { label: 'B', href: null }];

        assert.equal(withWorkView(levels, null, '/b'), levels);
    });
});

/**
 * Which pages declare a work view, and with which of their own states. A permanent form (Kvalitet's
 * «Registrer i kvalitetssystemet» on Oversikt) and dialogs are deliberately absent.
 */
describe('the pages hand their open work view to the layout', () => {
    const page = (file) => readFileSync(join(here, '..', 'Pages', 'App', file), 'utf8');

    for (const [file, state] of [
        ['SupplierManagement/Index.jsx', /workView=\{creating \? \(tr\.create \?\? 'Registrer leverandør'\) : null\}/],
        ['SupplierManagement/ControlRequirements.jsx', /workView=\{editing === 'new' \?/],
        ['Compliance/Audits/Index.jsx', /workView=\{creating \? \(ta\.create \?\? 'Ny revisjon'\) : null\}/],
        ['Compliance/Requirements/Index.jsx', /workView=\{creating \?/],
        ['Improvements/Index.jsx', /workView=\{creating \?/],
        ['Risk/Index.jsx', /workView=\{creating \?/],
        ['Objectives/Index.jsx', /workView=\{creating \?/],
        ['ManagementReview/Index.jsx', /workView=\{creating \?/],
        ['SupplierManagement/Show.jsx', /workView=\{panel === 'edit' \?/],
        ['Compliance/Audits/Show.jsx', /workView=\{panel === 'edit' \?/],
        ['Compliance/Requirements/Show.jsx', /workView=\{panel === 'edit' \?/],
        ['Improvements/Show.jsx', /workView=\{panel === 'edit' \?/],
        ['ManagementReview/Show.jsx', /workView=\{panel === 'edit' \?/],
        ['Objectives/KpiShow.jsx', /workView=\{panel === 'edit' \?/],
        ['Objectives/Show.jsx', /workView=\{editing \?/],
        ['Risk/Show.jsx', /workView=\{editing \?/],
    ]) {
        test(file, () => {
            assert.match(page(file), state);
        });
    }

    test('a permanent form is not a work view', () => {
        assert.ok(! page('Quality/Index.jsx').includes('workView='));
    });

    test('the layout passes the page\'s state to the shared resolver', () => {
        assert.match(layout, /export default function CustomerAppLayout\(\{ children, title, showPageTitle = true, workView = null \}\)/);
        assert.match(layout, /resolveNavigation\(\{ url: currentUrl, props: page\.props, title, workView,/);
    });
});

describe('one breadcrumb component, drawn by the layout', () => {
    test('a labelled nav with an ordered list, the current page marked', () => {
        assert.match(component, /<nav aria-label=\{ariaLabel\} data-testid="breadcrumbs"/);
        assert.match(component, /<ol /);
        assert.match(component, /aria-current="page"/);
    });

    test('long names are cut visually and keep their full text', () => {
        assert.match(component, /truncate/);
        assert.match(component, /title=\{item\.label\}/);
    });

    test('on a phone the levels above the nearest parent fold behind a button', () => {
        assert.match(component, /hidden sm:flex/);
        assert.match(component, /data-testid="breadcrumbs-expand"/);
        assert.match(component, /aria-label=\{showAllLabel\}/);
    });

    test('the layout draws it once, at the top of the page, from the central resolver', () => {
        assert.equal(layout.split('<Breadcrumbs').length - 1, 1);
        assert.ok(layout.indexOf('<Breadcrumbs') > layout.indexOf('<main'));
        assert.ok(layout.indexOf('<Breadcrumbs') < layout.indexOf('data-testid="page-navigation"'));
        assert.ok(layout.indexOf('<Breadcrumbs') < layout.indexOf('{children}'));
    });

    test('no text-xs or text-sm, like the rest of the layout', () => {
        assert.ok(! /text-(xs|sm)\b/.test(component));
    });
});
