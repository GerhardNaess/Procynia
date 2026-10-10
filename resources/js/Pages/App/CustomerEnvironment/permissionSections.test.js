import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { BASE_SECTION_KEY, allOpen, countLabel, sectionKeys, toggleSection } from './permissionSections.js';

const DOMAINS = [{ key: 'quality' }, { key: 'wiki' }, { key: 'risk' }];

describe('the sections on Tilganger', () => {
    test('one per domain, after the bid-role section', () => {
        assert.deepEqual(sectionKeys(DOMAINS), [BASE_SECTION_KEY, 'quality', 'wiki', 'risk']);
    });

    test('no bid-role section when the page has no bid-role matrix', () => {
        assert.deepEqual(sectionKeys(DOMAINS, false), ['quality', 'wiki', 'risk']);
    });

    test('no domains still leaves the bid-role section', () => {
        assert.deepEqual(sectionKeys(undefined), [BASE_SECTION_KEY]);
    });
});

describe('opening and closing', () => {
    test('a section opens and closes on its own', () => {
        const opened = toggleSection([], 'risk');

        assert.deepEqual(opened, ['risk']);
        assert.deepEqual(toggleSection(opened, 'risk'), []);
    });

    test('several sections stay open together', () => {
        const open = toggleSection(toggleSection([], 'wiki'), 'quality');

        assert.deepEqual(open, ['quality', 'wiki']);
        assert.deepEqual(toggleSection(open, 'wiki'), ['quality']);
    });

    test('toggling never mutates the previous set', () => {
        const before = ['quality'];
        toggleSection(before, 'wiki');
        toggleSection(before, 'quality');

        assert.deepEqual(before, ['quality']);
    });

    test('everything is open only when every section is', () => {
        const keys = sectionKeys(DOMAINS);

        assert.equal(allOpen(keys, keys), true);
        assert.equal(allOpen(['quality', 'wiki'], keys), false);
        assert.equal(allOpen([], []), false);
    });
});

describe('the counts on a section header', () => {
    test('singular and plural', () => {
        const forms = { one: ':count rolle', other: ':count roller' };

        assert.equal(countLabel(1, forms), '1 rolle');
        assert.equal(countLabel(0, forms), '0 roller');
        assert.equal(countLabel(4, forms), '4 roller');
    });

    test('a missing form still shows the number', () => {
        assert.equal(countLabel(2, {}), '2');
    });
});

/**
 * Opening a section is page state in Index. A matrix save that remounted the page would close
 * every section after each click, so the saves on this page must keep the page's state.
 */
describe('a save keeps the open sections open', () => {
    const here = dirname(fileURLToPath(import.meta.url));
    const sources = ['Index.jsx', 'CustomerRolesPanel.jsx'].map((file) => readFileSync(join(here, file), 'utf8'));
    const tilganger = sources[0].slice(sources[0].indexOf('const baseSection'));

    test('no matrix or role save on Tilganger discards page state', () => {
        assert.ok(! /preserveState: false/.test(sources[1]), 'CustomerRolesPanel');
        assert.ok(! /preserveState: false/.test(tilganger.slice(0, tilganger.indexOf('</PermissionSection>'))), 'bid-role matrix');
    });

    test('every section starts closed', () => {
        assert.match(sources[0], /const \[openSections, setOpenSections\] = useState\(\[\]\);/);
    });
});
