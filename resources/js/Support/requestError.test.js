import assert from 'node:assert/strict';
import test from 'node:test';
import { describeRequestError } from './requestError.js';

const failed = (status, data) => Object.assign(new Error(`Request failed with status code ${status}`), { response: { status, data } });

test('a 422 with Laravel errors gives the field its own message', () => {
    const result = describeRequestError(failed(422, {
        message: 'The answer text field must not be greater than 20000 characters.',
        errors: { answer_text: ['Svaret kan ha maks 20000 tegn.'], other: ['Annen feil.'] },
    }));

    assert.equal(result.kind, 'validation');
    assert.equal(result.message, 'Svaret kan ha maks 20000 tegn.');
    assert.deepEqual(result.fieldErrors, { answer_text: 'Svaret kan ha maks 20000 tegn.', other: 'Annen feil.' });
});

test('a 422 the server explained without fields is said in its own words', () => {
    assert.deepEqual(describeRequestError(failed(422, { error: 'Kunne ikke generere Wiki-svar. Prøv igjen senere.' })), {
        kind: 'refused',
        message: 'Kunne ikke generere Wiki-svar. Prøv igjen senere.',
        fieldErrors: {},
    });
    assert.equal(describeRequestError(failed(422, { message: 'AI-kvoten er brukt opp.' })).message, 'AI-kvoten er brukt opp.');
});

test('authorization, missing and expired are told apart and never read Laravel\'s English body', () => {
    const texts = { forbidden: 'Ingen tilgang.', not_found: 'Finnes ikke.', session_expired: 'Økten er utløpt.' };

    assert.deepEqual(describeRequestError(failed(403, { message: 'This action is unauthorized.' }), { texts }), { kind: 'forbidden', message: 'Ingen tilgang.', fieldErrors: {} });
    assert.equal(describeRequestError(failed(404, { message: 'Not Found' }), { texts }).kind, 'not_found');
    assert.equal(describeRequestError(failed(419, { message: 'CSRF token mismatch.' }), { texts }).message, 'Økten er utløpt.');
    assert.equal(describeRequestError(failed(401, {}), { texts }).kind, 'session_expired');
});

test('a server error or timeout never shows the technical text', () => {
    for (const error of [
        failed(500, { message: 'Server Error', exception: 'QueryException', trace: [] }),
        failed(503, '<html>Service Unavailable</html>'),
        Object.assign(new Error('timeout of 120000ms exceeded'), { code: 'ECONNABORTED' }),
        new Error('Network Error'),
    ]) {
        const result = describeRequestError(error, { fallback: 'Svaret ble ikke lagret. Prøv igjen.' });

        assert.doesNotMatch(result.message, /Request failed|status code|timeout|Network Error|Server Error|Exception/);
        assert.notEqual(result.kind, 'validation');
    }

    assert.equal(describeRequestError(failed(500, { message: 'Server Error' }), { fallback: 'Svaret ble ikke lagret.' }).message, 'Svaret ble ikke lagret.');
    assert.equal(describeRequestError(new Error('Network Error')).kind, 'network');
});

test('without translations the messages are Norwegian', () => {
    assert.match(describeRequestError(failed(500, {})).message, /Prøv igjen/);
    assert.match(describeRequestError(failed(403, {})).message, /tilgang/);
});
