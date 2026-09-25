import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { isStaleStateRefusal } from './wikiReviewRefusal.js';

/**
 * Two kinds of refusal that need opposite handling.
 *
 * A page that changed under the tab makes the open dialog meaningless — its action no longer
 * exists — so the page is re-read and the dialog closes. A validation failure is about what was
 * typed: the dialog has to stay, with the field's message, so it can be corrected. Treating the
 * second as the first would throw away a half-filled form and reload the page under somebody who
 * simply forgot to pick a reviewer.
 */
describe('telling a moved-on page from a bad form', () => {
    test('a conflict is always the page having moved on', () => {
        assert.equal(isStaleStateRefusal(409, undefined), true);
        assert.equal(isStaleStateRefusal(409, {}), true);
    });

    /** The review guards abort with 422 and no errors: not in draft, already decided, no version. */
    test('a 422 with no field errors is a review guard refusing', () => {
        assert.equal(isStaleStateRefusal(422, undefined), true);
        assert.equal(isStaleStateRefusal(422, {}), true);
        assert.equal(isStaleStateRefusal(422, { errors: {} }), true, 'an empty errors bag says nothing');
        assert.equal(isStaleStateRefusal(422, { message: 'Versjonen er ikke sendt til gjennomgang.' }), true);
    });

    /** The example that must not be read as a stale page: a missing or invalid reviewer. */
    test('a 422 carrying field errors is validation', () => {
        assert.equal(
            isStaleStateRefusal(422, { errors: { reviewer_user_id: ['Velg en kontrollør.'] } }),
            false,
        );
        assert.equal(isStaleStateRefusal(422, { errors: { reason: ['Begrunnelsen er for kort.'] } }), false);
    });

    /** Everything else is about the person or the session, and never reloads the page. */
    test('other refusals are not stale state', () => {
        for (const status of [400, 403, 404, 419, 429, 500, 503]) {
            assert.equal(isStaleStateRefusal(status, undefined), false, `${status}`);
        }
    });

    test('a success never reaches this at all, and is not stale either', () => {
        assert.equal(isStaleStateRefusal(200, undefined), false);
        assert.equal(isStaleStateRefusal(303, undefined), false);
        assert.equal(isStaleStateRefusal(undefined, undefined), false);
    });

    /** Defensive: a malformed payload must not be mistaken for a validation bag. */
    test('a non-object errors value is not validation', () => {
        assert.equal(isStaleStateRefusal(422, { errors: null }), true);
        assert.equal(isStaleStateRefusal(422, { errors: 'nope' }), true);
    });
});
