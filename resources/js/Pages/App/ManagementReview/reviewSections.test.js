import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
    attentionItems,
    formatDay,
    neighbours,
    ownersForArea,
    paneFromSearch,
    paneKeys,
    reviewHelp,
    sectionMarker,
} from './reviewSections.js';

const sections = [{ key: 'previous_decisions' }, { key: 'risks' }, { key: 'resources' }];

const risks = (values, extra = {}) => ({
    key: 'risks',
    state: 'available',
    judgement: null,
    basis: {
        groups: [{ key: 'status', metrics: Object.entries(values).map(([key, value]) => ({ key, label: key, value })) }],
    },
    ...extra,
});

test('a section with flagged numbers asks for attention until it is assessed', () => {
    assert.equal(sectionMarker(risks({ level_high: 2, risks_open: 9 })), 'attention');
    assert.equal(sectionMarker(risks({ level_high: 0, risks_open: 9 })), 'open');
    assert.equal(sectionMarker(risks({ level_high: 2 }, { judgement: 'needs_improvement' })), 'judged');
});

test('a section the reader cannot see, or with no module, is never «open» or «attention»', () => {
    assert.equal(sectionMarker(risks({ level_high: 2 }, { state: 'no_access', basis: null })), 'unavailable');
    assert.equal(sectionMarker({ key: 'suppliers', state: 'module_unavailable', basis: null }), 'unavailable');
    assert.equal(sectionMarker({ key: 'suppliers', state: 'not_captured', basis: null }), 'unavailable');
});

test('only the attention numbers of the section count, once each, and never zeros', () => {
    const items = attentionItems(risks({ level_high: 2, risks_open: 9, actions_overdue: 0 }));
    assert.deepEqual(items.map((item) => item.key), ['level_high']);
    assert.deepEqual(attentionItems({ key: 'resources', basis: null }), []);
});

test('the panes are Oversikt, the sections, Beslutninger and Historikk, and the URL picks one', () => {
    assert.deepEqual(paneKeys(sections), ['overview', 'previous_decisions', 'risks', 'resources', 'decisions', 'history']);
    assert.equal(paneFromSearch('?section=risks', sections), 'risks');
    assert.equal(paneFromSearch('?section=decisions', sections), 'decisions');
    assert.equal(paneFromSearch('?section=nope', sections), 'overview');
    assert.equal(paneFromSearch('', sections), 'overview');
});

test('Forrige and Neste walk the sections only', () => {
    assert.deepEqual(neighbours(sections, 'previous_decisions'), { previous: null, next: 'risks' });
    assert.deepEqual(neighbours(sections, 'risks'), { previous: 'previous_decisions', next: 'resources' });
    assert.deepEqual(neighbours(sections, 'resources'), { previous: 'risks', next: null });
});

test('a case owner is offered only where they can read cases', () => {
    const options = [{ id: 1, area_ids: [5] }, { id: 2, area_ids: [6] }];
    assert.deepEqual(ownersForArea(options, '5').map((person) => person.id), [1]);
    assert.deepEqual(ownersForArea(options, ''), []);
});

test('dates read as dd.mm.yyyy', () => {
    assert.equal(formatDay('2026-10-07'), '07.10.2026');
    assert.equal(formatDay(null), '—');
});

test('both pages carry PageHelp, in both languages', () => {
    const no = readFileSync(new URL('../../../../../lang/no/procynia.php', import.meta.url), 'utf8');
    const en = readFileSync(new URL('../../../../../lang/en/procynia.php', import.meta.url), 'utf8');

    for (const page of ['Index', 'Show']) {
        const source = readFileSync(new URL(`./${page}.jsx`, import.meta.url), 'utf8');
        assert.match(source, /<PageHelpButton \{\.\.\.reviewHelp\(t, '(index|review)'\)\} \/>/);
    }

    for (const lang of [no, en]) {
        assert.match(lang, /'management_review' => \[\n {8}'module_name'/);
        assert.match(lang, /'help' => \[\n {12}'button' =>/);
    }

    assert.equal(reviewHelp({}, 'index').buttonLabel, 'Hjelp');
});
