import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const layout = readFileSync(join(here, 'CustomerAppLayout.jsx'), 'utf8');

const block = (start, end) => {
    const from = layout.indexOf(start);
    assert.ok(from > -1, `${start} must exist`);

    return layout.slice(from, layout.indexOf(end, from));
};

/** The whole element carrying a test id, including the attributes written above it. */
const element = (testId, end) => {
    const at = layout.indexOf(`data-testid="${testId}"`);
    assert.ok(at > -1, `${testId} must exist`);

    return layout.slice(layout.lastIndexOf('<', at), layout.indexOf(end, at));
};

/**
 * The top bar had eleven items in one row, and only six of them were steps in the bid workflow.
 *
 * Watch lists is how Kunngjøringer is configured. Kundemiljø and Abonnement are administration.
 * Infosenter is follow-up across every case at once. None of those is a stage a bid passes
 * through, so all four read as peers of "Saksliste" without being anything like it — and the row
 * was long enough that the workflow itself stopped being legible in it.
 *
 * Routes, permissions and pages are untouched throughout: every item below is gated by exactly the
 * flag that gated it before, and moving a label cannot grant access. Source-level guards, the idiom
 * used elsewhere in this suite; the header itself was driven in a browser at 1680, 1280, 1024 and
 * 390 px.
 */
describe('the workflow moved into the Anbud module, and only moved', () => {
    test('the four work areas are there, in the order the work happens, behind Bid Status', () => {
        const sections = block('const moduleSections = activeModule === \'tenders\'', '];');
        const keys = [...sections.matchAll(/key: '([^']+)'/g)].map((m) => m[1]);

        // Bid Status leads because it is the view across the four, not a fifth stage. It was the
        // home page until Hjem became cross-module; Anbud is where its numbers mean something.
        assert.deepEqual(keys, ['bid-status', 'procurements', 'worklist', 'ai', 'suppliers']);
    });

    test('nothing that is not a step in the work is in it', () => {
        const sections = block('const moduleSections = activeModule === \'tenders\'', '];');

        for (const key of ['info-center', 'watch-profiles', 'environment', 'billing', 'wiki-ask', 'wiki']) {
            assert.ok(! sections.includes(`key: '${key}'`), `${key} must not be a work area`);
        }
    });

    test('every item keeps the href it always had', () => {
        const sections = block('const moduleSections = activeModule === \'tenders\'', '];');

        for (const href of ["'/app/notices'", "'/app/ai'", "'/app/suppliers'", "'/app/bid-status'"]) {
            assert.ok(sections.includes(href), href);
        }

        assert.match(sections, /buildHref\('\/app\/notices', \{ mode: 'saved' \}\)/);
    });

    test('the header no longer carries the workflow row', () => {
        assert.ok(! layout.includes('const mainNavigation = ['), 'the workflow row moved to the rail');
    });

    test('the active pill is unchanged', () => {
        assert.match(layout, /\? 'bg-violet-50 text-violet-700 ring-1 ring-inset ring-violet-200'/);
    });
});

describe('follow-up sits beside the workflow, not inside it', () => {
    test('it is its own entry, pointing at the same page as before', () => {
        const followUp = block('const followUpNavigation = {', '};');

        assert.match(followUp, /key: 'info-center'/);
        assert.match(followUp, /href: '\/app\/info-center'/);
        assert.match(followUp, /label: translations\.frontend\.infosenter_nav/);
    });

    test('a divider separates it, and goes away when the header wraps', () => {
        const divider = element('header-follow-up-divider', '/>');

        assert.match(divider, /aria-hidden="true"/);
        assert.match(divider, /hidden h-6 w-px shrink-0 bg-slate-200 lg:block/);
    });

    test('the order across the bar is search, divider, follow-up, bell, user', () => {
        const order = [
            'href={askWikiNavigation.href}',
            'data-testid="header-follow-up-divider"',
            'data-testid="header-follow-up"',
            '<NotificationBell',
            'aria-haspopup="menu"',
        ].map((needle) => layout.indexOf(needle));

        assert.ok(order.every((index) => index > -1), 'every part of the bar must be present');

        for (let i = 1; i < order.length; i += 1) {
            assert.ok(order[i] > order[i - 1], `part ${i} is out of order`);
        }
    });

    test('it reports its own active state rather than borrowing the workflow nav\'s', () => {
        const link = element('header-follow-up', '</Link>');

        assert.match(link, /aria-current=\{activeMainArea === followUpNavigation\.key \? 'page' : undefined\}/);
    });
});

describe('watch lists belongs to Kunngjøringer', () => {
    test('being on the page lights up Kunngjøringer', () => {
        assert.match(layout, /if \(pathname\.startsWith\('\/app\/watch-profiles'\)\) \{\s*\n\s*return 'procurements';/);
    });

    test('it is a tab in that area, behind the same permission as before', () => {
        const sub = block("if (activeMainArea === 'procurements') {\n            return [", '];');

        assert.match(sub, /key: 'live'/);
        assert.match(sub, /key: 'alerts'/);
        assert.match(sub, /\.\.\.\(watchProfilesHref\s*\n\s*\? \[\{ key: 'watch-profiles', label: navigation\.watch_lists, href: watchProfilesHref \}\]\s*\n\s*: \[\]\),/);
    });

    test('the tab shows as selected when the page is open', () => {
        assert.match(layout, /if \(pathname\.startsWith\('\/app\/watch-profiles'\)\) \{\s*\n\s*return 'watch-profiles';/);
    });

    test('the permission itself is untouched', () => {
        assert.match(layout, /const watchProfilesHref = user\?\.can_manage_watch_profiles \? '\/app\/watch-profiles' : null;/);
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

    test('Kundemiljø keeps its own tabs when opened', () => {
        // The area is no longer in the main menu, but it is still an area.
        assert.match(layout, /if \(activeMainArea === 'environment'\) \{/);
        assert.match(layout, /key: 'go-no-go-templates'/);
    });
});

describe('"AI" is only the menu label that changed', () => {
    test('the item still points at the AI workspace', () => {
        const sections = block('const moduleSections = activeModule === \'tenders\'', '];');

        assert.match(sections, /\{ key: 'ai', label: navigation\.ai, href: '\/app\/ai' \}/);
    });

    test('the AI area, its tabs and its case routing are untouched', () => {
        assert.match(layout, /if \(activeMainArea === 'ai'\) \{/);
        assert.match(layout, /key: 'ai-work', label: navigation\.worklist, href: aiWorkHref/);
        assert.match(layout, /key: 'ai-instructions', label: navigation\.ai_instructions, href: aiInstructionsHref/);
        assert.match(layout, /aiWorkHref = currentAiCaseId !== null/);
    });
});

describe('the header does not grow to fit the change', () => {
    test('the right-hand group may wrap on a phone, and does not on a laptop', () => {
        // Measured: at 390px the user button was pushed 33px past the viewport before this.
        assert.match(layout, /className="flex flex-wrap items-center justify-between gap-x-3 gap-y-2 lg:flex-nowrap lg:justify-end"/);
    });

    test('the follow-up link and the search icon never shrink away', () => {
        assert.match(layout, /flex h-10 w-10 shrink-0 items-center justify-center rounded-xl transition/);
        assert.match(layout, /'shrink-0 rounded-xl px-3 py-2 text-base font-medium transition'/);
    });
});

/**
 * Three levels, and each one named in exactly one place.
 *
 * Wiki is the reference: the rail picks the module, the header names what is inside it, and the
 * page carries whatever is inside that. Anbud used to put its four work areas in the rail, which
 * made Anbud the only module with a second level in the left-hand column — Wiki's equivalent four
 * were in the header all along. The areas moved up to the header and the area's own tabs moved
 * down onto the page; no route, label or permission changed with them. Driven in a browser at
 * 1680, 1280, 1024 and 390 px on /app/notices, /app/dashboard and /app/wiki.
 */
describe('the navigation hierarchy is the same shape in every module', () => {
    test('one fact decides the split: does the module have work areas', () => {
        assert.match(layout, /const hasModuleAreas = moduleSections\.length > 0;/);
    });

    test('a module with work areas shows them in the header, and its tabs on the page', () => {
        assert.match(layout, /const moduleNavigation = hasModuleAreas \? moduleSections : secondaryNavigation;/);
        assert.match(layout, /const pageNavigation = hasModuleAreas \? secondaryNavigation : \[\];/);
    });

    test('a module without them — Wiki — keeps its tabs in the header, untouched', () => {
        const wiki = block("if (activeMainArea === 'wiki') {\n            return [", '];');
        const keys = [...wiki.matchAll(/key: '([^']+)'/g)].map((m) => m[1]);

        assert.deepEqual(keys, ['wiki-sources', 'wiki-runs', 'wiki-pages', 'wiki-graph']);
    });

    test('Anbud shows its overview and its four areas in the header', () => {
        const sections = block("const moduleSections = activeModule === 'tenders'", '];');
        const keys = [...sections.matchAll(/key: '([^']+)'/g)].map((m) => m[1]);

        assert.deepEqual(keys, ['bid-status', 'procurements', 'worklist', 'ai', 'suppliers']);
        assert.match(layout, /data-testid="module-navigation"/);
    });

    test('Kunngjøringer keeps its own three, one level further down', () => {
        const sub = block("if (activeMainArea === 'procurements') {\n            return [", '];');
        const keys = [...sub.matchAll(/key: '([^']+)'/g)].map((m) => m[1]);

        assert.deepEqual(keys, ['live', 'alerts', 'watch-profiles']);
        assert.match(layout, /data-testid="page-navigation"/);
    });

    test('both levels are the same control, so they cannot drift apart', () => {
        assert.match(layout, /function NavigationRow\(\{ items, activeKey, disabledHint = '' \}\)/);
        assert.match(layout, /<NavigationRow\s*\n\s*items=\{moduleNavigation\}/);
        assert.match(layout, /<NavigationRow\s*\n\s*items=\{pageNavigation\}/);
    });

    test('active state is read off the URL on both levels', () => {
        assert.match(layout, /const activeModuleNavigationKey = hasModuleAreas \? activeMainArea : activeSecondaryKey;/);
        assert.match(layout, /const activePageNavigationKey = hasModuleAreas \? activeSecondaryKey : null;/);
        // activeMainArea and activeSecondaryKey are both derived from pathname/searchParams.
        assert.match(layout, /const \{ pathname, searchParams \} = splitUrl\(currentUrl\);/);
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
        const areas = block('if (activeMainArea === \'quality\') {', '];');

        for (const tab of ['overview', 'processes', 'controls', 'tools']) {
            assert.match(areas, new RegExp(`key: 'quality-${tab}'`), tab);
        }

        // The page used to draw the same four as a strip of its own, from before the header had them.
        assert.ok(! quality.includes('function Tabs('), 'Kvalitet must not render its own copy of the header areas');
        assert.ok(! quality.includes('<nav'), 'Kvalitet\'s index has no level below its main areas');
        assert.ok(! /\/app\/quality\?tab=\$\{/.test(quality), 'no tab links built on the page');
    });

    test('a process keeps Dokument / Flyt, a real level below Prosesser', () => {
        assert.match(qualityItem, /function DetailTabs\(/);
        assert.match(qualityItem, /\['document', td\.tab_document/);
        assert.match(qualityItem, /\['flow', td\.tab_flow/);
    });

    test('an item page lights the main area its type belongs to, not its own ?tab=', () => {
        const resolver = block('const qualityTab = (() => {', '})();');

        assert.match(resolver, /pathname\.startsWith\('\/app\/quality\/items\/'\)/);
        assert.match(resolver, /type === 'process' \? 'processes' : \(type === 'control' \? 'controls' : 'overview'\)/);
    });

    test('Kunngjøringer keeps its own level below the header — Live søk, Varsler, Watch lists', () => {
        const procurements = block('const secondaryNavigation = (() => {', 'if (activeMainArea === \'worklist\')');

        assert.match(procurements, /key: 'live'/);
        assert.match(procurements, /key: 'alerts'/);
        assert.match(procurements, /key: 'watch-profiles'/);
        assert.match(layout, /data-testid="page-navigation"/);
    });

    test('Risiko, Mål og KPI and Avvik og forbedringer have one main area, so no header row is made up for them', () => {
        const secondary = block('const secondaryNavigation = (() => {', 'return [];\n    })();');

        for (const area of ['risk', 'objectives', 'improvements']) {
            assert.ok(! secondary.includes(`activeMainArea === '${area}'`), area);
        }
    });
});
