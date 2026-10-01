import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { APP_MODULES, activeModuleKey } from '../Support/appModules.js';

const here = dirname(fileURLToPath(import.meta.url));
const sidebar = readFileSync(join(here, '..', 'Components', 'App', 'ModuleSidebar.jsx'), 'utf8');
const layout = readFileSync(join(here, 'CustomerAppLayout.jsx'), 'utf8');

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
                'home', 'tenders', 'wiki', 'quality',
                'risk', 'suppliers', 'contracts', 'hse', 'compliance', 'services',
                'projects', 'competence', 'assets', 'reports', 'settings',
            ],
        );
    });

    test('exactly four are available, and they are the four that have pages', () => {
        const available = APP_MODULES.filter((module) => module.available);

        assert.deepEqual(available.map((module) => module.key), ['home', 'tenders', 'wiki', 'quality']);
        assert.deepEqual(
            available.map((module) => module.href),
            ['/app/dashboard', '/app/notices', '/app/wiki', '/app/quality'],
        );
    });

    test('a planned module carries no href at all, so there is nothing to click', () => {
        for (const module of APP_MODULES.filter((m) => ! m.available)) {
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
        const planned = sidebar.slice(sidebar.indexOf('{planned.map('));

        assert.ok(planned.includes('<span'), 'a planned module must not render a Link');
        assert.ok(! planned.includes('<Link'), 'a planned module must not render a Link');
        assert.match(planned, /aria-disabled="true"/);
        assert.match(planned, /cursor-not-allowed/);
        assert.match(planned, /text-slate-400/);
    });

    test('dimming alone would read as a bug, so the group is captioned', () => {
        assert.match(sidebar, /data-testid="module-sidebar-planned-caption"/);
        assert.match(sidebar, /modules\.planned_caption \?\? 'Planlagt'/);
        assert.match(sidebar, /modules\.planned_hint \?\? 'Ikke tilgjengelig ennå'/);
    });
});

describe('the selected module follows the page', () => {
    test('each area resolves to the module it belongs to', () => {
        assert.equal(activeModuleKey('overview'), 'home');
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

    test('/app/quality is what puts the rail on Kvalitet', () => {
        assert.match(layout, /if \(pathname\.startsWith\('\/app\/quality'\)\) \{\s*\n\s*return 'quality';/);
    });
});

describe('the rail does not take navigation away from anyone', () => {
    test('it is rendered beside the page, and stacks above it on a phone', () => {
        assert.match(layout, /<aside className="w-full shrink-0 lg:w-64">/);
        assert.match(layout, /flex max-w-\[1600px\] flex-col gap-6 .* lg:flex-row/);
    });

    test('the work areas only appear under the module that owns them', () => {
        assert.match(sidebar, /if \(moduleKey !== activeKey \|\| sections\.length === 0\) \{\s*\n\s*return null;/);
    });
});
