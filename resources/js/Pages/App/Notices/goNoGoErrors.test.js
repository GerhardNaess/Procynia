import assert from 'node:assert/strict';
import test from 'node:test';
import { COMMENT_MAX_LENGTH, criterionErrors, saveErrorMessage } from './goNoGoErrors.js';

const criteria = [
    { id: 11, title: 'Strategisk match' },
    { id: 12, title: 'Kapasitet' },
    { id: 13, title: 'Konkurransebilde' },
];

test('a refused reasoning lands on the criterion it was sent for', () => {
    const refused = criterionErrors({ 'answers.2.comment': 'Begrunnelsen kan ha maks 2000 tegn.' }, criteria);

    assert.deepEqual(refused, { byCriterion: { 13: 'Begrunnelsen kan ha maks 2000 tegn.' }, general: null });
    assert.equal(
        saveErrorMessage(refused, criteria),
        'Vurderingen ble ikke lagret. Rett opp det som er markert ved: «Konkurransebilde». Det du har fylt ut, er beholdt.',
    );
});

test('several criteria are named in the order they are shown', () => {
    const refused = criterionErrors({ 'answers.2.comment': 'For lang.', 'answers.0.selected_value': 'Velg Lav, Middels eller Høy.' }, criteria);

    assert.deepEqual(Object.keys(refused.byCriterion), ['11', '13']);
    assert.match(saveErrorMessage(refused, criteria), /«Strategisk match», «Konkurransebilde»/);
});

test('a refusal of the whole assessment is said as the server said it', () => {
    const refused = criterionErrors({ template_id: 'Vurderingsmalen er deaktivert og kan ikke brukes til nye vurderinger.' }, criteria);

    assert.deepEqual(refused.byCriterion, {});
    assert.equal(saveErrorMessage(refused, criteria), 'Vurderingsmalen er deaktivert og kan ikke brukes til nye vurderinger.');
});

test('an index outside the list is not pinned on the wrong criterion', () => {
    const refused = criterionErrors({ 'answers.7.comment': 'For lang.' }, criteria);

    assert.deepEqual(refused, { byCriterion: {}, general: 'For lang.' });
});

test('no detail from the server falls back to the plain message', () => {
    assert.equal(saveErrorMessage(criterionErrors({}, criteria), criteria, { save_failed: 'Ikke lagret.' }), 'Ikke lagret.');
});

test('the limit is the server\'s', () => {
    assert.equal(COMMENT_MAX_LENGTH, 2000);
});
