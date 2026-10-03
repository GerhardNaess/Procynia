import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { changeBadge, describeChange, fill } from './processFlowChanges.js';

describe('changeBadge', () => {
    test('says whether a change adds, changes or removes', () => {
        assert.equal(changeBadge('add_step'), 'add');
        assert.equal(changeBadge('add_role'), 'add');
        assert.equal(changeBadge('update_flow'), 'update');
        assert.equal(changeBadge('remove_step'), 'remove');
        assert.equal(changeBadge('remove_flow'), 'remove');
    });
});

describe('fill', () => {
    test('replaces every occurrence of each placeholder', () => {
        assert.equal(fill(':a og :a, :b', { a: 'x', b: 'y' }), 'x og x, y');
    });
});

describe('describeChange', () => {
    test('a new activity names its role', () => {
        const line = describeChange({ kind: 'add_step', type: 'activity', label: 'Gjennomfør sikkerhetskontroll', role: 'Sikkerhetsansvarlig', description: null });

        assert.equal(line.badge, 'add');
        assert.equal(line.title, 'Ny aktivitet «Gjennomfør sikkerhetskontroll»');
        assert.deepEqual(line.details, ['Rolle: Sikkerhetsansvarlig']);
    });

    test('a new decision is called a decision', () => {
        assert.equal(
            describeChange({ kind: 'add_step', type: 'decision', label: 'Er leverandøren godkjent?' }).title,
            'Ny beslutning «Er leverandøren godkjent?»',
        );
    });

    test('an edited step is named by what it was called before', () => {
        const line = describeChange({
            kind: 'update_step',
            previous_label: 'Godkjenn',
            label: 'Godkjenn leverandøren',
            fields: [
                { field: 'label', from: 'Godkjenn', to: 'Godkjenn leverandøren' },
                { field: 'role', from: 'Innkjøper', to: 'Økonomi' },
            ],
        });

        assert.equal(line.badge, 'update');
        assert.equal(line.title, 'Endre «Godkjenn»');
        assert.deepEqual(line.details, ['Tekst: «Godkjenn» → «Godkjenn leverandøren»', 'Rolle: Innkjøper → Økonomi']);
    });

    test('a removed step says its connections go with it', () => {
        const line = describeChange({ kind: 'remove_step', label: 'Arkiver' });

        assert.equal(line.badge, 'remove');
        assert.equal(line.details.length, 1);
    });

    test('connections name both ends and the outcome', () => {
        assert.deepEqual(
            describeChange({ kind: 'add_flow', from: 'Kritisk?', to: 'Kontroller', condition: 'Ja' }),
            { badge: 'add', title: 'Ny forbindelse «Kritisk?» → «Kontroller»', details: ['Utfall: Ja'] },
        );

        assert.deepEqual(
            describeChange({ kind: 'update_flow', from: 'A', to: 'B', previous_condition: null, condition: 'Nei' }).details,
            ['Utfall: uten utfall → Nei'],
        );
    });

    test('translations replace the fallbacks', () => {
        assert.equal(
            describeChange({ kind: 'remove_flow', from: 'A', to: 'B', condition: null }, { change_remove_flow: 'Remove ":from" → ":to"' }).title,
            'Remove "A" → "B"',
        );
    });
});
