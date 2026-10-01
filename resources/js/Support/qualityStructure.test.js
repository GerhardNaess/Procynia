import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import {
    candidatesForRelationEnd,
    documentLabel,
    relationTypeIsUsable,
} from './qualityStructure.js';

const here = dirname(fileURLToPath(import.meta.url));
const page = readFileSync(join(here, '..', 'Pages', 'App', 'Quality', 'Index.jsx'), 'utf8');

/**
 * The matrix shipped from PHP is what the form reads. These tests hold the client to it — not as
 * the rule (QualityStructureService is), but so the form never proposes a pair the service will
 * reject and then blames the user for it.
 */
const RELATION_TYPES = [
    { key: 'governs', from_types: ['policy'], to_types: ['process'] },
    { key: 'uses', from_types: ['process'], to_types: ['checklist'] },
    { key: 'verifies', from_types: ['control'], to_types: ['process'] },
    { key: 'depends_on', from_types: ['process'], to_types: ['process'] },
];

const DOCUMENTS = [
    { page_id: 1, title: 'Innkjøpspolicy', quality_type: 'policy', quality_code: 'POL-01' },
    { page_id: 2, title: 'Anskaffelsesprosess', quality_type: 'process', quality_code: null },
    { page_id: 3, title: 'Tilbudssjekkliste', quality_type: 'checklist', quality_code: null },
];

describe('the relation form offers only pairs the matrix allows', () => {
    test('each end is narrowed to its own allowed types', () => {
        assert.deepEqual(
            candidatesForRelationEnd(DOCUMENTS, RELATION_TYPES, 'governs', 'from').map((d) => d.page_id),
            [1],
        );
        assert.deepEqual(
            candidatesForRelationEnd(DOCUMENTS, RELATION_TYPES, 'governs', 'to').map((d) => d.page_id),
            [2],
        );
    });

    test('direction is not symmetric — a process is never the governing end', () => {
        const governingEnd = candidatesForRelationEnd(DOCUMENTS, RELATION_TYPES, 'governs', 'from');

        assert.equal(governingEnd.some((d) => d.quality_type === 'process'), false);
    });

    test('an unknown relation type offers nothing rather than everything', () => {
        assert.deepEqual(candidatesForRelationEnd(DOCUMENTS, RELATION_TYPES, 'supersedes', 'from'), []);
    });

    test('a relation with no document at one end is not offered at all', () => {
        // No control is classified, so "control verifies process" is unusable and saying so beats
        // an empty select.
        assert.equal(relationTypeIsUsable(DOCUMENTS, RELATION_TYPES, 'verifies'), false);
        assert.equal(relationTypeIsUsable(DOCUMENTS, RELATION_TYPES, 'governs'), true);
    });

    test('one process alone cannot depend on another', () => {
        const single = [DOCUMENTS[1]];

        // Both ends are populated, so the helper allows it; the self-reference is the backend's
        // refusal, which is exactly why this helper is not the rule.
        assert.equal(relationTypeIsUsable(single, RELATION_TYPES, 'depends_on'), true);
    });
});

describe('documents are labelled the way people cite them', () => {
    test('the document number leads when there is one', () => {
        assert.equal(documentLabel(DOCUMENTS[0]), 'POL-01 — Innkjøpspolicy');
        assert.equal(documentLabel(DOCUMENTS[1]), 'Anskaffelsesprosess');
    });

    test('a missing document is an empty label, not a crash', () => {
        assert.equal(documentLabel(null), '');
    });
});

describe('the Kvalitet page stays a view onto Wiki', () => {
    test('every row leads back to the Wiki page rather than rendering content', () => {
        assert.match(page, /document\.wiki_url/);
        assert.equal(/react-markdown/.test(page), false, 'Kvalitet must not become a second place to read a page.');
    });

    test('the write affordances are hidden without can_manage', () => {
        // The backend refuses regardless; this keeps the UI from offering work it knows will fail.
        assert.match(page, /canManage &&/);
    });
});
