import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { APP_MODULES, activeModuleKey, moduleAvailability } from '../Support/appModules.js';

const here = dirname(fileURLToPath(import.meta.url));
const sidebar = readFileSync(join(here, '..', 'Components', 'App', 'ModuleSidebar.jsx'), 'utf8');
const layout = readFileSync(join(here, 'CustomerAppLayout.jsx'), 'utf8');
const state = readFileSync(join(here, '..', 'Support', 'moduleSidebarState.js'), 'utf8');

/**
 * The rail is the product structure, so the thing worth guarding is that it keeps showing all of
 * it — the modules that are built and the ones that are not — and that the not-built ones cannot
 * be clicked into a 404.
 */
describe('the rail shows the whole planned product structure', () => {
    test('every module is listed, in order', () => {
        assert.deepEqual(
            APP_MODULES.map((module) => module.key),
            [
                'home', 'wiki', 'tenders', 'quality',
                'risk', 'objectives', 'improvements', 'suppliers', 'contracts', 'hse', 'compliance', 'services',
                'projects', 'competence', 'assets', 'reports', 'settings',
            ],
        );
    });

    test('exactly seven are built, and they are the seven that have pages', () => {
        const available = APP_MODULES.filter((module) => module.built);

        assert.deepEqual(available.map((module) => module.key), ['home', 'wiki', 'tenders', 'quality', 'risk', 'objectives', 'improvements']);
        assert.deepEqual(
            available.map((module) => module.href),
            ['/app/dashboard', '/app/wiki', '/app/notices', '/app/quality', '/app/risk', '/app/objectives', '/app/improvements'],
        );
    });

    test('a planned module carries no href at all, so there is nothing to click', () => {
        for (const module of APP_MODULES.filter((m) => ! m.built)) {
            assert.equal(module.href, undefined, `${module.key} must not be reachable`);
            assert.deepEqual(module.areas, undefined, `${module.key} cannot be an active area`);
        }
    });

    test('every module has a Norwegian fallback label', () => {
        for (const module of APP_MODULES) {
            assert.ok(module.label({}).length > 0, module.key);
        }
    });
});

describe('available and planned look different, and say why', () => {
    test('available modules are links with the app\'s own active pill', () => {
        assert.match(sidebar, /isActive\s*\n\s*\? 'bg-violet-50 text-violet-700 ring-1 ring-inset ring-violet-200'/);
        assert.match(sidebar, /: 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'/);
    });

    test('planned modules are not links, are dimmed, and are marked disabled', () => {
        // "Ikke bestilt" and "Planlagt" share one renderer, so its markup is what both groups get.
        const start = sidebar.indexOf('const renderUnavailableGroup');
        const unavailable = sidebar.slice(start, sidebar.indexOf('return (', sidebar.indexOf('));', start)));

        assert.ok(start > -1, 'the unavailable groups must have one shared renderer');
        assert.ok(unavailable.includes('<span'), 'a planned module must not render a Link');
        assert.ok(! unavailable.includes('<Link'), 'a planned module must not render a Link');
        assert.match(unavailable, /aria-disabled="true"/);
        assert.match(unavailable, /cursor-not-allowed/);
        assert.match(unavailable, /text-slate-400/);
        assert.match(sidebar, /renderUnavailableGroup\(groups\.planned,/);
        assert.match(sidebar, /renderUnavailableGroup\(groups\.not_ordered,/);
    });

    test('dimming alone would read as a bug, so the group is captioned', () => {
        assert.match(sidebar, /data-testid=\{testId\}/);
        assert.match(sidebar, /testId: 'module-sidebar-planned-caption'/);
        assert.match(sidebar, /testId: 'module-sidebar-not-ordered-caption'/);
        assert.match(sidebar, /modules\.planned_caption \?\? 'Planlagt'/);
        assert.match(sidebar, /modules\.planned_hint \?\? 'Ikke tilgjengelig ennå'/);
        assert.match(sidebar, /modules\.not_ordered_caption \?\? 'Ikke bestilt'/);
    });
});

describe('the selected module follows the page', () => {
    test('each area resolves to the module it belongs to', () => {
        assert.equal(activeModuleKey('overview'), 'home');
        assert.equal(activeModuleKey('bid-status'), 'tenders');
        assert.equal(activeModuleKey('procurements'), 'tenders');
        assert.equal(activeModuleKey('worklist'), 'tenders');
        assert.equal(activeModuleKey('ai'), 'tenders');
        assert.equal(activeModuleKey('suppliers'), 'tenders');
        assert.equal(activeModuleKey('wiki'), 'wiki');
        assert.equal(activeModuleKey('wiki-ask'), 'wiki');
        assert.equal(activeModuleKey('quality'), 'quality');
    });

    test('administration and follow-up are not modules, so nothing is selected there', () => {
        for (const area of ['environment', 'billing', 'info-center']) {
            assert.equal(activeModuleKey(area), null, area);
        }
    });

    test('Hjem is its own page, not the bid cockpit it used to be', () => {
        // The rail still points at /app/dashboard — only what that path renders changed — and the
        // cockpit resolves to an Anbud area so clicking Hjem can never land inside Anbud again.
        assert.match(layout, /if \(pathname === '\/app\/dashboard'\) \{\s*\n\s*return 'overview';/);
        assert.match(layout, /if \(pathname === '\/app\/bid-status'\) \{\s*\n\s*return 'bid-status';/);
        assert.deepEqual(APP_MODULES.find((module) => module.key === 'home').areas, ['overview']);
    });

    test('/app/quality is what puts the rail on Kvalitet', () => {
        assert.match(layout, /if \(pathname\.startsWith\('\/app\/quality'\)\) \{\s*\n\s*return 'quality';/);
    });
});

describe('the rail does not take navigation away from anyone', () => {
    test('it is rendered beside the page, and stacks above it on a phone', () => {
        assert.match(layout, /sidebarCollapsed \? 'lg:w-\[4\.5rem\]' : 'lg:w-64',/);
        assert.match(layout, /flex max-w-\[1600px\] flex-col gap-6 .* lg:flex-row/);
    });

    test('the rail is a module picker and nothing else', () => {
        // Anbud used to nest its four work areas here, which made one module structurally unlike
        // every other. Wiki never did, and Wiki is the pattern.
        assert.ok(! sidebar.includes('renderSections'), 'the rail must not render a second level');
        assert.ok(! sidebar.includes('activeSectionKey'), 'the rail takes no section state');
        assert.match(sidebar, /function ModuleSidebar\(\{ modules = \{\}, activeModules = \[\], permissions = \[\], activeKey = null, collapsed = false, onToggleCollapsed = null \}\)/);
        assert.match(layout, /<ModuleSidebar\s*\n\s*modules=\{modules\}\s*\n\s*activeModules=\{activeModules\}\s*\n\s*permissions=\{userPermissions\}\s*\n\s*activeKey=\{activeModule\}/);
    });
});

/**
 * Collapsing is a width change, not a different rail.
 *
 * The thing that can quietly break here is the one that matters most: a collapsed rail that also
 * collapses on a phone, where it is the only module navigation there is. That is why the test
 * below checks the breakpoint prefixes rather than only that labels can hide — `sr-only` with no
 * `lg:` in front of it would hide the labels everywhere.
 */
describe('the rail can be collapsed to icons, on desktop only', () => {
    test('the layout hands the rail its state and a way to change it', () => {
        assert.match(layout, /const \[sidebarCollapsed, setSidebarCollapsed\] = useState\(readModuleSidebarCollapsed\)/);
        assert.match(layout, /collapsed=\{sidebarCollapsed\}/);
        assert.match(layout, /onToggleCollapsed=\{toggleSidebarCollapsed\}/);
    });

    test('the choice is remembered in localStorage, and survives storage being unavailable', () => {
        assert.match(state, /const STORAGE_KEY = 'procynia\.app\.moduleSidebarCollapsed'/);
        assert.match(state, /window\.localStorage\.getItem\(STORAGE_KEY\) === '1'/);
        assert.match(state, /window\.localStorage\.setItem\(STORAGE_KEY, collapsed \? '1' : '0'\)/);

        // Both accessors swallow a throwing localStorage; reading falls back to expanded.
        assert.equal((state.match(/catch \{/g) ?? []).length, 2);
        assert.match(state, /\} catch \{\s*\n\s*return false;/);
    });

    test('toggling writes through, so a reload comes back the same way', () => {
        assert.match(layout, /const toggleSidebarCollapsed = \(\) => \{[\s\S]*?writeModuleSidebarCollapsed\(next\);/);
    });

    test('collapsing takes the labels out of the layout from lg up, and nowhere else', () => {
        assert.match(sidebar, /const labelClass = collapsed \? 'leading-snug lg:sr-only' : 'leading-snug';/);
        assert.ok(! /(?<!lg:)\bsr-only/.test(sidebar.replace(/lg:sr-only/g, '')), 'no label may be hidden below lg');
    });

    test('a collapsed row centres its icon instead of shrinking the label', () => {
        assert.match(sidebar, /lg:justify-center lg:gap-0 lg:px-0/);
    });

    test('the icons carry a tooltip once the labels are gone', () => {
        assert.match(sidebar, /title=\{collapsed \? label : undefined\}/);
        assert.match(sidebar, /title=\{collapsed \? `\$\{label\} — \$\{hint\}` : hint\}/);
    });

    test('the active module keeps its pill, and planned modules keep their dimming', () => {
        // Both states are driven by the same expressions in either width, so collapsing cannot
        // silently drop one: rowClass only adds spacing, never colour.
        assert.match(sidebar, /rowClass,\s*\n\s*'text-base font-medium transition',\s*\n\s*isActive/);
        assert.match(sidebar, /rowClass,\s*\n\s*'cursor-not-allowed select-none text-base font-medium text-slate-400',/);
    });

    test('the control is discreet, labelled, and absent on a phone', () => {
        assert.match(sidebar, /data-testid="module-sidebar-toggle"/);
        assert.match(sidebar, /aria-expanded=\{! collapsed\}/);
        assert.match(sidebar, /modules\.expand \?\? 'Utvid menyen'/);
        assert.match(sidebar, /modules\.collapse \?\? 'Slå sammen menyen'/);
        assert.match(sidebar, /'mt-3 hidden border-t border-slate-200\/80 pt-2 lg:flex',/);
    });

    test('the planned caption folds away with the labels rather than overflowing the narrow rail', () => {
        assert.match(sidebar, /collapsed \? 'lg:sr-only' : '',/);
    });

    test('both translations exist in both languages', () => {
        for (const [lang, expected] of [['no', ['Slå sammen menyen', 'Utvid menyen']], ['en', ['Collapse menu', 'Expand menu']]]) {
            const file = readFileSync(join(here, '..', '..', '..', 'lang', lang, 'procynia.php'), 'utf8');

            assert.ok(file.includes(`'collapse' => '${expected[0]}'`), `${lang} collapse`);
            assert.ok(file.includes(`'expand' => '${expected[1]}'`), `${lang} expand`);
        }
    });
});

describe('Mål og KPI is on the rail once it has pages', () => {
    const objectives = APP_MODULES.find((module) => module.key === 'objectives');

    test('it has its own icon on the rail', () => {
        assert.match(sidebar, /\n    objectives: 'M/);
    });

    test('it is its own module, gated by objective.view', () => {
        assert.equal(objectives.module, 'objectives');
        assert.equal(objectives.permission, 'objective.view');
        assert.equal(objectives.built, true);
        assert.equal(objectives.label({}), 'Mål og KPI');
    });

    test('it is a link only with both the module and the permission', () => {
        assert.equal(moduleAvailability(objectives, ['objectives'], ['objective.view']), 'active');
        assert.equal(moduleAvailability(objectives, [], ['objective.view']), 'not_ordered');
        assert.equal(moduleAvailability(objectives, ['objectives'], ['risk.view']), 'not_permitted');
    });

    test('/app/objectives is what puts the rail on Mål og KPI', () => {
        assert.match(layout, /if \(pathname\.startsWith\('\/app\/objectives'\)\) \{\s*\n\s*return 'objectives';/);
        assert.equal(activeModuleKey('objectives'), 'objectives');
    });
});

describe('Avvik og forbedringer is on the rail once it has pages', () => {
    const improvements = APP_MODULES.find((module) => module.key === 'improvements');

    test('it has its own icon on the rail', () => {
        assert.match(sidebar, /\n    improvements: 'M/);
    });

    test('it is its own module, gated by improvement.view', () => {
        assert.equal(improvements.module, 'improvements');
        assert.equal(improvements.permission, 'improvement.view');
        assert.equal(improvements.built, true);
        assert.equal(improvements.label({}), 'Avvik og forbedringer');
    });

    test('it is a link only with both the module and the permission, and hidden without the permission', () => {
        assert.equal(moduleAvailability(improvements, ['improvements'], ['improvement.view']), 'active');
        assert.equal(moduleAvailability(improvements, [], ['improvement.view']), 'not_ordered');
        assert.equal(moduleAvailability(improvements, ['improvements'], ['objective.view']), 'not_permitted');
    });

    test('/app/improvements is what puts the rail on Avvik og forbedringer', () => {
        assert.match(layout, /if \(pathname\.startsWith\('\/app\/improvements'\)\) \{\s*\n\s*return 'improvements';/);
        assert.equal(activeModuleKey('improvements'), 'improvements');
    });
});
