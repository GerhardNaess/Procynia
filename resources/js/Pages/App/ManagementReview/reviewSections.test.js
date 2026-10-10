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
    sectionOptionLabel,
    sectionStatus,
    statusDescription,
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

test('a section is open until a judgement is saved, and open again once it is removed', () => {
    assert.equal(sectionStatus(risks({ risks_open: 9 })).progress, 'open');
    assert.equal(sectionStatus(risks({ risks_open: 9 }, { judgement: 'satisfactory' })).progress, 'judged');
    assert.equal(sectionStatus(risks({ risks_open: 9 }, { judgement: 'not_satisfactory' })).progress, 'judged');
    // Opened but nothing saved — a comment alone is not an assessment.
    assert.equal(sectionStatus(risks({ risks_open: 9 }, { judgement: null, comment: 'Lest' })).progress, 'open');
});

test('attention is shown beside the progress, never instead of it', () => {
    const open = sectionStatus(risks({ level_high: 2, risks_open: 9 }));
    assert.equal(open.progress, 'open');
    assert.deepEqual(open.attention.map((item) => item.key), ['level_high']);

    const judged = sectionStatus(risks({ level_high: 2 }, { judgement: 'needs_improvement' }));
    assert.equal(judged.progress, 'judged');
    assert.deepEqual(judged.attention.map((item) => item.key), ['level_high']);

    assert.deepEqual(sectionStatus(risks({ level_high: 0 }, { judgement: 'satisfactory' })).attention, []);
});

test('a section the reader cannot see, or with no module, is unavailable and flags nothing', () => {
    assert.deepEqual(sectionStatus(risks({ level_high: 2 }, { state: 'no_access', basis: null })), { progress: 'unavailable', attention: [], optional: false });
    assert.equal(sectionStatus({ key: 'suppliers', state: 'module_unavailable', basis: null }).progress, 'unavailable');
    assert.equal(sectionStatus({ key: 'suppliers', state: 'not_captured', basis: null }).progress, 'unavailable');
});

test('the status reads as words — the tooltip, the screen reader and the phone select say the same', () => {
    const t = { sections: { risks: { title: 'Risiko' } } };
    const both = risks({ level_high: 2 }, { judgement: 'satisfactory' });
    assert.equal(statusDescription(sectionStatus(both), t), 'Vurdering gjennomført. Forhold krever oppmerksomhet: level_high 2.');
    assert.equal(statusDescription(sectionStatus(risks({ risks_open: 1 })), t), 'Ikke vurdert.');
    assert.equal(sectionOptionLabel(both, t), 'Risiko (vurdert · krever oppmerksomhet)');
    assert.equal(sectionOptionLabel(risks({}), t), 'Risiko (ikke vurdert)');
    // Open but not asked for by «Klar for ferdigstilling»: said so, read from the checklist itself.
    const readiness = { items: [{ key: 'judgements', sections: ['resources'] }] };
    assert.equal(sectionStatus(risks({}), readiness).optional, true);
    assert.equal(sectionStatus({ key: 'resources', state: 'available' }, readiness).optional, false);
    assert.equal(sectionStatus(risks({}, { judgement: 'satisfactory' }), readiness).optional, false);
    assert.equal(sectionStatus(risks({})).optional, false);
    assert.equal(statusDescription(sectionStatus(risks({}), readiness), t), 'Ikke vurdert. Ikke påkrevd for ferdigstilling.');
    const en = { section: { markers: { judged: 'Assessment completed' }, markers_short: { judged: 'assessed' } } };
    assert.equal(statusDescription(sectionStatus(risks({}, { judgement: 'satisfactory' })), en), 'Assessment completed.');
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
