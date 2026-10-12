import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { activeAreaTabKey, aiCaseHrefs, areaTabsFor, moduleSectionsFor, resolveArea, resolveNavigation } from '../Support/appNavigation.js';

const here = dirname(fileURLToPath(import.meta.url));
const layout = readFileSync(join(here, 'CustomerAppLayout.jsx'), 'utf8');

/** The whole element carrying a test id, including the attributes written above it. */
const element = (testId, end) => {
    const at = layout.indexOf(`data-testid="${testId}"`);
    assert.ok(at > -1, `${testId} must exist`);

    return layout.slice(layout.lastIndexOf('<', at), layout.indexOf(end, at));
};

const keys = (items) => items.map((item) => item.key);
const hrefs = (items) => items.map((item) => item.href);
const navigationAt = (url, props = {}) => resolveNavigation({ url, props });

/**
 * The top bar had eleven items in one row, and only six of them were steps in the bid workflow.
 *
 * Watch lists is how Kunngjøringer is configured. Kundemiljø and Abonnement are administration.
 * Oppfølging is follow-up across every case at once. None of those is a stage a bid passes
 * through. The workflow lives in Anbud's module navigation; Oppfølging is on the rail; Kundemiljø
 * and Abonnement are in the user menu. Routes and permissions are untouched throughout.
 */
describe('the workflow moved into the Anbud module, and only moved', () => {
    test('the four work areas are there, in the order the work happens, behind Bid Status', () => {
        // Bid Status leads because it is the view across the four, not a fifth stage.
        assert.deepEqual(keys(moduleSectionsFor('tenders')), ['bid-status', 'procurements', 'worklist', 'ai', 'suppliers']);
    });

    test('nothing that is not a step in the work is in it', () => {
        for (const key of ['info-center', 'watch-profiles', 'environment', 'billing', 'wiki-ask', 'wiki']) {
            assert.ok(! keys(moduleSectionsFor('tenders')).includes(key), `${key} must not be a work area`);
        }
    });

    test('every item keeps the href it always had', () => {
        assert.deepEqual(hrefs(moduleSectionsFor('tenders')), ['/app/bid-status', '/app/notices', '/app/notices?mode=saved', '/app/ai', '/app/suppliers']);
    });

    test('no other module has work areas', () => {
        for (const module of ['wiki', 'quality', 'risk', 'compliance', 'suppliers', 'follow_up', 'home']) {
            assert.deepEqual(moduleSectionsFor(module), [], module);
        }
    });

    test('the header no longer carries the workflow row', () => {
        assert.ok(! layout.includes('const mainNavigation = ['), 'the workflow row moved to the rail');
    });

    test('the active pill is unchanged', () => {
        assert.match(layout, /\? 'bg-violet-50 text-violet-700 ring-1 ring-inset ring-violet-200'/);
    });
});

/**
 * Oppfølging used to be a button in the top bar — the one place a person works that the rail did not
 * name. It is on the rail now, beside Hjem; the top bar keeps search, the bell and the user.
 */
describe('Oppfølging is on the rail, not in the top bar', () => {
    test('the top bar no longer carries a follow-up button or its divider', () => {
        assert.ok(! layout.includes('data-testid="header-follow-up"'));
        assert.ok(! layout.includes('data-testid="header-follow-up-divider"'));
        assert.ok(! layout.includes('followUpNavigation'));
    });

    test('the order across the bar is search, bell, user', () => {
        const order = [
            'href={askWikiNavigation.href}',
            '<NotificationBell',
            'aria-haspopup="menu"',
        ].map((needle) => layout.indexOf(needle));

        assert.ok(order.every((index) => index > -1), 'every part of the bar must be present');

        for (let i = 1; i < order.length; i += 1) {
            assert.ok(order[i] > order[i - 1], `part ${i} is out of order`);
        }
    });

    test('the page lights Oppfølging on the rail, and nothing under Styring', () => {
        for (const url of ['/app/info-center', '/app/info-center?view=awaiting_response']) {
            const navigation = navigationAt(url);

            assert.equal(navigation.area, 'info-center', url);
            assert.equal(navigation.moduleKey, 'follow_up', url);
            assert.equal(navigation.workspaceKey, null, url);
            assert.deepEqual(navigation.moduleNavigation, [], url);
        }
    });
});

describe('watch lists belongs to Kunngjøringer', () => {
    const owner = { auth: { user: { can_manage_watch_profiles: true } } };

    test('being on the page lights up Kunngjøringer', () => {
        assert.equal(resolveArea('/app/watch-profiles'), 'procurements');
        assert.equal(resolveArea('/app/watch-profiles/create'), 'procurements');
    });

    test('it is a tab in that area, behind the same permission as before', () => {
        assert.deepEqual(keys(areaTabsFor('procurements', { user: owner.auth.user })), ['live', 'alerts', 'watch-profiles']);
        assert.deepEqual(keys(areaTabsFor('procurements', { user: { can_manage_watch_profiles: false } })), ['live', 'alerts']);
    });

    test('the tab shows as selected when the page is open', () => {
        assert.equal(navigationAt('/app/watch-profiles/3/edit', owner).activePageNavigationKey, 'watch-profiles');
    });
});

describe('administration moved to the user menu, and only moved', () => {
    test('both entries are there, at their existing routes', () => {
        const admin = element('user-menu-admin', '</div>\n                                            ) : null}');

        assert.match(admin, /href=\{environmentHref\}/);
        assert.match(admin, /href=\{billingHref\}/);
        assert.match(admin, /navigation\.customer_environment \?\? 'Kundemiljø'/);
        assert.match(admin, /translations\.billing\?\.nav \?\? 'Abonnement'/);
    });

    test('each is gated by the flag that gated it in the main menu', () => {
        assert.match(layout, /const environmentHref = user\?\.can_manage_customer_users \? '\/app\/customer-environment' : null;/);
        assert.match(layout, /const billingHref = user\?\.can_manage_customer_billing \? '\/app\/billing' : null;/);
        assert.match(layout, /\{environmentHref \? \(/);
        assert.match(layout, /\{billingHref \? \(/);
    });

    test('a user with neither sees no empty section', () => {
        assert.match(layout, /\{environmentHref \|\| billingHref \? \(/);
    });

    test('Kundemiljø keeps its own tabs when opened, gated as before', () => {
        const owner = { can_manage_customer_users: true, is_system_owner: true };

        assert.deepEqual(keys(areaTabsFor('environment', { user: owner })), ['env-settings', 'go-no-go-templates']);
        assert.deepEqual(keys(areaTabsFor('environment', { user: { can_manage_customer_users: true } })), ['env-settings']);
        assert.equal(activeAreaTabKey('environment', '/app/go-no-go-templates/4/edit'), 'go-no-go-templates');
    });
});

describe('"AI" is only the menu label that changed', () => {
    test('the item still points at the AI workspace', () => {
        assert.equal(moduleSectionsFor('tenders').find((item) => item.key === 'ai').href, '/app/ai');
    });

    test('the AI tabs follow the case on the page, else the last one, else the first on the list', () => {
        assert.deepEqual(aiCaseHrefs({ case: { id: 7 } }), { currentAiCaseId: 7, aiWorkHref: '/app/ai/7', aiInstructionsHref: '/app/ai/7/instructions' });
        assert.equal(aiCaseHrefs({ analysisCases: [{ id: 2 }, { id: 5 }] }, '5').aiWorkHref, '/app/ai/5');
        assert.equal(aiCaseHrefs({ analysisCases: [{ id: 2 }] }, '9').aiWorkHref, '/app/ai/2');
        assert.equal(aiCaseHrefs({}, null).aiWorkHref, null);
        assert.deepEqual(keys(areaTabsFor('ai')), ['ai-overview', 'ai-work', 'ai-instructions']);
        assert.equal(activeAreaTabKey('ai', '/app/ai/7/instructions'), 'ai-instructions');
        assert.equal(activeAreaTabKey('ai', '/app/ai/7'), 'ai-work');
        assert.equal(activeAreaTabKey('ai', '/app/ai'), 'ai-overview');
    });
});

describe('the header does not grow to fit the change', () => {
    test('the right-hand group may wrap on a phone, and does not on a laptop', () => {
        // Measured: at 390px the user button was pushed 33px past the viewport before this.
        assert.match(layout, /className="flex flex-wrap items-center justify-between gap-x-3 gap-y-2 lg:flex-nowrap lg:justify-end"/);
    });

    test('the search icon never shrinks away', () => {
        assert.match(layout, /flex h-10 w-10 shrink-0 items-center justify-center rounded-xl transition/);
    });
});

/**
 * Three levels, and each one named in exactly one place.
 *
 * Wiki is the reference: the rail picks the module, the header names what is inside it, and the
 * page carries whatever is inside that. Anbud's areas sit in the header and the area's own tabs on
 * the page. Driven in a browser at 1680, 1280, 1024 and 390 px on /app/notices, /app/dashboard and
 * /app/wiki.
 */
describe('the navigation hierarchy is the same shape in every module', () => {
    test('a module with work areas shows them in the header, and its tabs on the page', () => {
        const navigation = navigationAt('/app/notices?tab=alerts');

        assert.deepEqual(keys(navigation.moduleNavigation), ['bid-status', 'procurements', 'worklist', 'ai', 'suppliers']);
        assert.equal(navigation.activeModuleNavigationKey, 'procurements');
        assert.deepEqual(keys(navigation.pageNavigation), ['live', 'alerts']);
        assert.equal(navigation.activePageNavigationKey, 'alerts');
    });

    test('a module without them — Wiki — keeps its tabs in the header, untouched', () => {
        const navigation = navigationAt('/app/wiki');

        assert.deepEqual(keys(navigation.moduleNavigation), ['wiki-sources', 'wiki-runs', 'wiki-pages', 'wiki-graph']);
        assert.equal(navigation.activeModuleNavigationKey, 'wiki-pages');
        assert.deepEqual(navigation.pageNavigation, []);
        assert.equal(navigationAt('/app/wiki?tab=runs').activeModuleNavigationKey, 'wiki-runs');
        assert.equal(navigationAt('/app/wiki/some-page').activeModuleNavigationKey, 'wiki-pages');
        assert.equal(navigationAt('/app/wiki/graph').activeModuleNavigationKey, 'wiki-graph');
    });

    test('both levels are the same control, so they cannot drift apart', () => {
        assert.match(layout, /function NavigationRow\(\{ items, activeKey, disabledHint = '' \}\)/);
        assert.match(layout, /<NavigationRow\s*\n\s*items=\{moduleNavigation\}/);
        assert.match(layout, /<NavigationRow\s*\n\s*items=\{pageNavigation\}/);
        assert.match(layout, /data-testid="module-navigation"/);
        assert.match(layout, /data-testid="page-navigation"/);
    });

    test('active state is read off the URL on both levels', () => {
        assert.equal(navigationAt('/app/notices').activePageNavigationKey, 'live');
        assert.equal(navigationAt('/app/notices?mode=saved').activeModuleNavigationKey, 'worklist');
        assert.equal(navigationAt('/app/notices?mode=history').activePageNavigationKey, 'history');
        assert.equal(navigationAt('/app/notices/saved/12').activeModuleNavigationKey, 'worklist');
        assert.equal(navigationAt('/app/notices/44').activeModuleNavigationKey, 'procurements');
        assert.match(layout, /resolveNavigation\(\{ url: currentUrl, props: page\.props, title/);
    });

    test('the page level stacks above the page content, and wraps on a phone', () => {
        const row = layout.slice(layout.indexOf('data-testid="page-navigation"'));

        assert.match(row, /mb-6 rounded-2xl border border-slate-200\/80 bg-white/);
        assert.match(layout, /<nav className="flex flex-wrap items-center gap-2">/);
    });

    test('the AI case hint followed the AI tabs down to the page', () => {
        const row = layout.slice(layout.indexOf('data-testid="page-navigation"'));

        assert.match(row, /disabledHint=\{aiCaseNavigationHint\}/);
    });
});

/**
 * The navigation contract: the rail picks the workspace and module (level 1), the header names the
 * module's main areas (level 2), and a row on the page exists only for a real level below the
 * active area (level 3) — Live søk / Varsler / Watch lists under Kunngjøringer, Dokument / Flyt on a
 * process. The same choices are never offered twice on one page.
 */
describe('one navigation choice is rendered in one place', () => {
    const quality = readFileSync(join(here, '..', 'Pages', 'App', 'Quality', 'Index.jsx'), 'utf8');
    const qualityItem = readFileSync(join(here, '..', 'Pages', 'App', 'Quality', 'Item.jsx'), 'utf8');

    test('Kvalitet\'s four main areas live in the header only', () => {
        assert.deepEqual(keys(areaTabsFor('quality')), ['quality-overview', 'quality-processes', 'quality-controls', 'quality-tools']);

        // The page used to draw the same four as a strip of its own, from before the header had them.
        assert.ok(! quality.includes('function Tabs('), 'Kvalitet must not render its own copy of the header areas');
        assert.ok(! quality.includes('<nav'), 'Kvalitet\'s index has no level below its main areas');
        assert.ok(! /\/app\/quality\?tab=\$\{/.test(quality), 'no tab links built on the page');
    });

    test('Leverandører | Kontrollkrav live in the header only, the way Kvalitet\'s areas do', () => {
        assert.deepEqual(hrefs(areaTabsFor('supplier-management')), ['/app/supplier-management', '/app/supplier-management/control-requirements']);

        // A supplier's own page and the import stay under Leverandører.
        assert.equal(activeAreaTabKey('supplier-management', '/app/supplier-management/12'), 'supplier-suppliers');
        assert.equal(activeAreaTabKey('supplier-management', '/app/supplier-management/import'), 'supplier-suppliers');
        assert.equal(activeAreaTabKey('supplier-management', '/app/supplier-management/control-requirements'), 'supplier-control-requirements');

        for (const file of ['Index.jsx', 'ControlRequirements.jsx', 'Import.jsx']) {
            const page = readFileSync(join(here, '..', 'Pages', 'App', 'SupplierManagement', file), 'utf8');
            assert.ok(! page.includes('SupplierTabs'), `${file} must not render its own copy of the header tabs`);
            assert.ok(! page.includes('tr.module_name'), `${file} carries no module name above its heading`);
        }
    });

    test('a process keeps Dokument / Flyt, a real level below Prosesser', () => {
        assert.match(qualityItem, /function DetailTabs\(/);
        assert.match(qualityItem, /\['document', td\.tab_document/);
        assert.match(qualityItem, /\['flow', td\.tab_flow/);
    });

    test('an item page lights the main area its type belongs to, not its own ?tab=', () => {
        const at = (type) => activeAreaTabKey('quality', '/app/quality/items/5', new URLSearchParams('tab=flow'), { item: { quality_type: type } });

        assert.equal(at('process'), 'quality-processes');
        assert.equal(at('control'), 'quality-controls');
        assert.equal(at('policy'), 'quality-overview');
        assert.equal(activeAreaTabKey('quality', '/app/quality', new URLSearchParams('tab=tools')), 'quality-tools');
    });

    test('Risiko, Mål og KPI and Avvik og forbedringer have one main area, so no header row is made up for them', () => {
        for (const area of ['risk', 'objectives', 'improvements', 'management-review']) {
            assert.deepEqual(areaTabsFor(area), [], area);
        }
    });

    test('Etterlevelse og revisjon has four work areas, in the order the work runs — like Anbud, in the header', () => {
        const areas = areaTabsFor('compliance');

        assert.deepEqual(keys(areas), ['compliance-library', 'compliance-requirements', 'compliance-assessments', 'compliance-audits']);
        assert.deepEqual(hrefs(areas), ['/app/compliance/library', '/app/compliance/requirements', '/app/compliance/assessments', '/app/compliance/audits']);
        assert.equal(areas[2].label, 'Etterlevelsesvurdering');
        // The active area is decided in one place, shared with the pages.
        assert.equal(activeAreaTabKey('compliance', '/app/compliance/audits/3'), 'compliance-audits');
        assert.equal(activeAreaTabKey('compliance', '/app/compliance/requirements/3', new URLSearchParams('from=assessments')), 'compliance-assessments');
    });
});
