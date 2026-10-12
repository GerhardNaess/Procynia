import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { VIEW_GROUPED, VIEW_LIST, groupHeading, isFiltered, registerQuery, toggleOpen } from './complianceRegister.js';

const source = (file) => readFileSync(new URL(file, import.meta.url), 'utf8');

describe('registerQuery', () => {
    test('Gruppert is the default and is not written; empty values are left out', () => {
        assert.deepEqual(registerQuery({}), {});
        assert.deepEqual(registerQuery({ view: VIEW_GROUPED, search: '  ', source: '', open: [] }), {});
    });

    test('carries search, filters, Liste and what is open', () => {
        assert.deepEqual(
            registerQuery({ search: ' leverandør ', source: 12, status: 'active', applicability: 'not_assessed', view: VIEW_LIST, open: ['12', '12:345'] }),
            { search: 'leverandør', source: '12', status: 'active', applicability: 'not_assessed', view: 'list', open: '12,12:345' },
        );
    });

    test('clearing the search keeps the chosen view and what is open', () => {
        const state = { search: 'leverandør', view: VIEW_LIST, open: ['3'] };

        assert.deepEqual(registerQuery({ ...state, search: '' }), { view: 'list', open: '3' });
    });
});

describe('toggleOpen', () => {
    test('opens and closes one key, leaving the others', () => {
        assert.deepEqual(toggleOpen([], '12'), ['12']);
        assert.deepEqual(toggleOpen(['12', '12:345'], '12'), ['12:345']);
        assert.deepEqual(toggleOpen(['12:345'], '12'), ['12:345', '12']);
    });
});

describe('groupHeading', () => {
    test('a numbered chapter as Kravbiblioteket names it, a theme with its reference', () => {
        assert.equal(groupHeading({ reference: '4', title: 'Organisasjonens kontekst' }), '4. Organisasjonens kontekst');
        assert.equal(groupHeading({ reference: 'A.5', title: 'Organisatoriske kontroller' }), 'A.5 Organisatoriske kontroller');
        assert.equal(groupHeading({ reference: '', title: 'Uten nummer' }), 'Uten nummer');
    });
});

describe('isFiltered', () => {
    test('a search or any filter narrows the register; the view and what is open do not', () => {
        assert.equal(isFiltered({}), false);
        assert.equal(isFiltered({ open: ['1'] }), false);
        assert.equal(isFiltered({ search: 'x' }), true);
        assert.equal(isFiltered({ applicability: 'applies' }), true);
    });
});

describe('The register page', () => {
    test('offers Gruppert | Liste and renders the groups from the same filtered register', () => {
        const code = source('./Index.jsx');

        assert.match(code, /<ViewSwitch /);
        assert.match(code, /<RegisterGroups /);
        assert.match(code, /aria-pressed=\{active\}/);
        assert.match(code, /rememberRegister\(pageUrl\)/);
    });

    test('a chapter and a source open through one button each, with aria-expanded', () => {
        const code = source('./RegisterGroups.jsx');

        assert.equal((code.match(/aria-expanded=/g) ?? []).length, 2);
        // The requirement text is the requirement page's, never the overview's.
        assert.doesNotMatch(code, /requirement_text/);
    });

    test('«Tilbake til kravregisteret» returns to the register as it was left', () => {
        assert.match(source('./Show.jsx'), /registerUrl\(\)/);
    });
});
