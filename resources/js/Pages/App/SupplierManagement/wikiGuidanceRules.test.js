import { describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { searchRows, wikiGuidanceVisible, wikiNoteLabel, wikiSearchUrl } from './wikiGuidanceRules.js';

const source = (file) => readFileSync(new URL(file, import.meta.url), 'utf8');

describe('Veiledning fra Enterprise Wiki on a control requirement (supplier-assurance-v2-plan §28)', () => {
    test('nothing is shown without Wiki access, and no empty section to someone who can only read', () => {
        assert.equal(wikiGuidanceVisible(null, true), false);
        assert.equal(wikiGuidanceVisible(undefined, false), false);
        assert.equal(wikiGuidanceVisible([], false), false);
        assert.equal(wikiGuidanceVisible([], true), true);
        assert.equal(wikiGuidanceVisible([{ id: 1 }], false), true);
    });

    test('a page says only what matters: archived, or not published yet', () => {
        assert.equal(wikiNoteLabel('archived', {}), 'Arkivert i Wiki');
        assert.equal(wikiNoteLabel('not_published', { wiki_guidance: { notes: { not_published: 'Not published yet' } } }), 'Not published yet');
        assert.equal(wikiNoteLabel(null, {}), null);
    });

    test('search results keep what is already linked, marked as such, and the term is sent encoded', () => {
        assert.deepEqual(searchRows([{ id: 1 }, { id: 2 }, { id: 3 }], [{ id: 2 }]).map((row) => [row.page.id, row.linked]), [[1, false], [2, true], [3, false]]);
        assert.deepEqual(searchRows(null, null), []);
        assert.equal(wikiSearchUrl('  '), '/app/supplier-management/control-requirements/wiki-pages');
        assert.equal(wikiSearchUrl('lønn & HMS'), '/app/supplier-management/control-requirements/wiki-pages?search=l%C3%B8nn%20%26%20HMS');
    });

    test('the section is in the catalogue and in the control work, opens the Wiki, and is at least 16 px', () => {
        const component = source('./WikiGuidance.jsx');
        assert.match(component, /href=\{page\.url\}/);
        assert.match(component, /target="_blank"/);
        assert.match(component, /rel="noopener noreferrer"/);
        assert.doesNotMatch(component, /text-(xs|sm)\b/);
        // More than 20 matches: the person is asked to narrow the search, never paged.
        assert.match(component, /state\.hasMore && <p/);
        assert.match(source('./ControlRequirements.jsx'), /<WikiGuidance requirementId=\{row\.id\} guidance=\{row\.wiki_guidance\} canManage=\{canManageGuidance\}/);
        // On the supplier page only a requirement for that supplier is managed there.
        assert.match(source('./SupplierControlRequirements.jsx'), /can_manage_wiki_guidance \?\? false\) && row\.supplier_specific/);
    });
});
