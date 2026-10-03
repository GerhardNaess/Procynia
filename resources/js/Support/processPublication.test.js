import { test } from 'node:test';
import assert from 'node:assert/strict';

import { publicationLabel, publicationTone } from './processPublication.js';

test('an unpublished process says so, whatever its stored status', () => {
    assert.equal(publicationLabel({ state: 'unpublished', revision_number: null }), 'Ikke publisert');
    assert.equal(publicationTone({ state: 'unpublished' }), 'slate');
});

test('a published process names the revision in force', () => {
    assert.equal(publicationLabel({ state: 'current', revision_number: 3 }), 'Gjeldende, revisjon 3');
    assert.equal(publicationTone({ state: 'current' }), 'green');
});

test('unpublished changes and retirement are distinct states', () => {
    assert.equal(
        publicationLabel({ state: 'current_with_changes', revision_number: 2 }),
        'Gjeldende, med upubliserte endringer',
    );
    assert.equal(publicationLabel({ state: 'retired', revision_number: 4 }), 'Utgått');
});

test('translated labels win over the fallbacks', () => {
    assert.equal(
        publicationLabel({ state: 'current', revision_number: 1 }, { current: 'In force, revision :number' }),
        'In force, revision 1',
    );
});

test('a non-process has no publication label', () => {
    assert.equal(publicationLabel(null), null);
});
