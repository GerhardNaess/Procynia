import { test, describe, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { APP_MODULES, railEntries } from './appModules.js';
import { hasChildren, isGroupOpen, readOpenGroups, requiredOpenGroups, toggleGroup, withGroupsOpen, writeOpenGroups } from './navigationGroups.js';

const here = dirname(fileURLToPath(import.meta.url));
const sidebar = readFileSync(join(here, '..', 'Components', 'App', 'ModuleSidebar.jsx'), 'utf8');

const ALL = ['wiki_core', 'tender', 'quality', 'risk', 'objectives', 'improvements', 'compliance'];
const VIEW_ALL = ['wiki.view', 'quality.view', 'risk.view', 'objective.view', 'improvement.view', 'compliance.view'];
const entries = railEntries(ALL, VIEW_ALL).entries;

/**
 * A rail group is anything with something under it: Styring (its modules) and Etterlevelse og
 * revisjon (Krav and Revisjoner). These guard the rules that make folding safe — the chevron folds
 * and the name navigates, and the page you are on is never hidden under a closed parent.
 */
describe('which entries are groups', () => {
    test('Styring and Etterlevelse og revisjon have chevrons; nothing else does', () => {
        const governance = entries.find((entry) => entry.key === 'governance');
        const withChevron = [...entries, ...governance.children].filter(hasChildren).map((entry) => entry.key);

        assert.deepEqual(withChevron, ['governance', 'compliance']);
    });

    test('a node without children has no chevron', () => {
        for (const key of ['home', 'wiki', 'tenders', 'quality', 'risk', 'objectives', 'improvements']) {
            assert.equal(hasChildren(APP_MODULES.find((module) => module.key === key)), false, key);
        }
    });

    test('the sidebar renders a chevron only for an entry with children', () => {
        assert.match(sidebar, /\{hasChildren\(child\) && renderGroupToggle\(child\.key, childLabel, `module-\$\{child\.key\}-areas`\)\}/);
        assert.ok(! /renderLink[\s\S]*?renderGroupToggle\(entry/.test(sidebar), 'a plain module row must not get a chevron');
    });
});

describe('folding and unfolding', () => {
    test('every group starts open', () => {
        assert.equal(isGroupOpen({}, 'governance'), true);
        assert.equal(isGroupOpen({}, 'compliance'), true);
        assert.equal(isGroupOpen(undefined, 'governance'), true);
    });

    test('Styring collapses, and opens again', () => {
        const closed = toggleGroup({}, 'governance');

        assert.equal(isGroupOpen(closed, 'governance'), false);
        assert.equal(isGroupOpen(toggleGroup(closed, 'governance'), 'governance'), true);
    });

    test('Etterlevelse og revisjon collapses and opens on its own, without touching Styring', () => {
        const closed = toggleGroup({}, 'compliance');

        assert.equal(isGroupOpen(closed, 'compliance'), false);
        assert.equal(isGroupOpen(closed, 'governance'), true);

        const reopened = toggleGroup(closed, 'compliance');
        assert.equal(isGroupOpen(reopened, 'compliance'), true);
    });

    test('toggling never mutates the state it was given', () => {
        const state = Object.freeze({ governance: false });

        assert.deepEqual(toggleGroup(state, 'governance'), { governance: true });
        assert.deepEqual(state, { governance: false });
    });
});

describe('the current page opens its parents', () => {
    test('on Krav or Revisjoner both Styring and Etterlevelse og revisjon are opened', () => {
        // The layout resolves /app/compliance/requirements and /app/compliance/audits to the module
        // key `compliance` inside the workspace `governance`.
        const required = requiredOpenGroups(entries, { activeWorkspace: 'governance', activeKey: 'compliance' });

        assert.deepEqual(required, ['governance', 'compliance']);

        const opened = withGroupsOpen({ governance: false, compliance: false }, required);
        assert.equal(isGroupOpen(opened, 'governance'), true);
        assert.equal(isGroupOpen(opened, 'compliance'), true);
    });

    test('inside another Styring module only Styring is opened', () => {
        const required = requiredOpenGroups(entries, { activeWorkspace: 'governance', activeKey: 'risk' });

        assert.deepEqual(required, ['governance']);
        assert.equal(isGroupOpen(withGroupsOpen({ governance: false, compliance: false }, required), 'compliance'), false);
    });

    test('outside Styring nothing is forced open, so a closed Styring stays closed', () => {
        assert.deepEqual(requiredOpenGroups(entries, { activeWorkspace: null, activeKey: 'wiki' }), []);
        assert.deepEqual(withGroupsOpen({ governance: false }, []), { governance: false });
    });

    test('a group the person cannot see is never required', () => {
        const withoutCompliance = railEntries(ALL, ['quality.view']).entries;

        assert.deepEqual(requiredOpenGroups(withoutCompliance, { activeWorkspace: 'governance', activeKey: 'compliance' }), ['governance']);
    });

    test('the sidebar forces the required groups open on arrival and whenever the page changes', () => {
        assert.match(sidebar, /useState\(\(\) => withGroupsOpen\(readOpenGroups\(\), requiredKeys \? requiredKeys\.split\('\|'\) : \[\]\)\)/);
        assert.match(sidebar, /useEffect\(\(\) => \{\s*\n\s*setOpenGroups\(\(current\) => withGroupsOpen\(current, requiredKeys/);
    });
});

describe('the active state', () => {
    test('one current page: an open work area carries aria-current, the module keeps its pill', () => {
        assert.match(sidebar, /aria-current=\{isActive && ! areaOpen \? 'page' : undefined\}/);
        assert.match(sidebar, /const isActive = activeAreaKey === area\.key;/);
        assert.match(sidebar, /isActive \? 'font-semibold text-violet-700'/);
    });

    test('the chevron says what it will do, and which list it controls', () => {
        assert.match(sidebar, /aria-expanded=\{open\}/);
        assert.match(sidebar, /aria-controls=\{controlsId\}/);
        assert.match(sidebar, /modules\.collapse_group \?\? 'Skjul :label'/);
        assert.match(sidebar, /modules\.expand_group \?\? 'Vis :label'/);
    });

    test('folded lists leave the layout, but never in the icon rail where the chevrons are gone', () => {
        assert.match(sidebar, /isGroupOpen\(openGroups, key\) \? '' : classNames\('hidden', collapsed \? 'lg:block' : ''\)/);
        assert.match(sidebar, /collapsed \? 'lg:hidden' : '',\n\s*\)\}\n\s*>\n\s*<GroupChevron/);
    });

    test('both languages name the chevron', () => {
        for (const [lang, expected] of [['no', ['Skjul :label', 'Vis :label']], ['en', ['Hide :label', 'Show :label']]]) {
            const file = readFileSync(join(here, '..', '..', '..', 'lang', lang, 'procynia.php'), 'utf8');

            assert.ok(file.includes(`'collapse_group' => '${expected[0]}'`), `${lang} collapse_group`);
            assert.ok(file.includes(`'expand_group' => '${expected[1]}'`), `${lang} expand_group`);
        }
    });
});

describe('the choice is remembered in the browser, and survives storage being unavailable', () => {
    let store;

    beforeEach(() => {
        store = new Map();
        globalThis.window = {
            localStorage: {
                getItem: (key) => (store.has(key) ? store.get(key) : null),
                setItem: (key, value) => store.set(key, String(value)),
            },
        };
    });

    test('what is written is read back', () => {
        writeOpenGroups({ governance: false, compliance: true });

        assert.deepEqual(readOpenGroups(), { governance: false, compliance: true });
    });

    test('nothing stored, or something unreadable, reads as every group open', () => {
        assert.deepEqual(readOpenGroups(), {});

        store.set('procynia.app.navigationGroups', 'not json');
        assert.deepEqual(readOpenGroups(), {});

        store.set('procynia.app.navigationGroups', '[1,2]');
        assert.deepEqual(readOpenGroups(), {});
    });

    test('a throwing localStorage is swallowed on both read and write', () => {
        globalThis.window = {
            localStorage: {
                getItem: () => { throw new Error('blocked'); },
                setItem: () => { throw new Error('blocked'); },
            },
        };

        assert.deepEqual(readOpenGroups(), {});
        assert.doesNotThrow(() => writeOpenGroups({ governance: false }));
    });

    test('only a click writes; arriving on a page does not', () => {
        assert.match(sidebar, /const onToggleGroup = \(key\) => \{[\s\S]*?writeOpenGroups\(next\);/);
        assert.equal((sidebar.match(/writeOpenGroups\(/g) ?? []).length, 1);
    });
});
