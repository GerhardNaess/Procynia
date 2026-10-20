/**
 * Pure, dependency-free logic shared by the Wiki review panel — extracted so it can be unit-tested
 * with Node's built-in test runner, the same way runFindingsLogic.js is. Node cannot import JSX, so
 * a rule worth testing for real has to live outside the component.
 */

/**
 * Does this refusal mean the page moved on, rather than that the form was wrong?
 *
 * The two need opposite handling. A page that has changed under the tab makes the open dialog
 * meaningless — its action no longer exists — so the page is re-read and the dialog closes. A
 * validation failure is about what was typed: the dialog must stay, with the field's own message,
 * so it can be corrected.
 *
 * Laravel hands a failed validation to Inertia as a redirect back with errors, so validation
 * normally arrives through onError and never reaches this at all. The errors check is the belt to
 * that braces: if a validation payload ever does arrive here — a JSON 422 from a client configured
 * differently, say — it is still read as validation rather than as a stale page.
 *
 * 409 is always a conflict. A 422 without errors is one of the review guards refusing: not in
 * draft, already decided, no working version. Every one of those is a statement about the page.
 */
export function isStaleStateRefusal(status, data) {
    if (status !== 409 && status !== 422) {
        return false;
    }

    const errors = data?.errors;

    return ! (errors && typeof errors === 'object' && Object.keys(errors).length > 0);
}

/**
 * What a refused claim decision (Behold teksten, Fjern teksten, Angre beslutning, blocking) means
 * for the card it was made on.
 *
 * `key` names the message (translations wiki.claim_action_error_*, or the server's own validation
 * text when `key` is 'validation'). `reload` says whether the claim moved on under the tab — decided
 * by somebody else, gone, or no longer in a state that allows the action — so the page is re-read
 * from the server and shows what is actually true; the typed text stays, since a reload keeps the
 * page's own state. A refusal about access, the session or a technical fault leaves the page as it
 * is, so the person can retry with nothing lost.
 *
 * @param {number|undefined} status
 * @param {object|undefined} data
 * @returns {{key: 'validation'|'forbidden'|'not_found'|'conflict'|'state'|'expired'|'failed', message: string|null, reload: boolean}}
 */
export function describeClaimActionRefusal(status, data) {
    const errors = data?.errors;
    const firstError = errors && typeof errors === 'object'
        ? Object.values(errors).map((value) => (Array.isArray(value) ? value[0] : value)).find((value) => typeof value === 'string' && value.trim() !== '')
        : undefined;

    if (firstError !== undefined) {
        return { key: 'validation', message: firstError, reload: false };
    }

    switch (status) {
        case 403:
            return { key: 'forbidden', message: null, reload: false };
        case 404:
            return { key: 'not_found', message: null, reload: true };
        case 409:
            return { key: 'conflict', message: null, reload: true };
        case 401:
        case 419:
            return { key: 'expired', message: null, reload: false };
        case 422:
            return { key: 'state', message: null, reload: true };
        default:
            return { key: 'failed', message: null, reload: false };
    }
}

/**
 * Whether a navigation should ask before discarding unsaved Wiki text: only when something is
 * unsaved, and only for a visit that leaves this page. A claim decision (PATCH), a reload of the same
 * page or a ?claim_id= jump within it keeps the component and the typed text, so it never asks.
 *
 * @param {{method?: string, url?: URL|string}} visit
 * @param {string} currentPathname
 * @param {boolean} hasUnsaved
 * @returns {boolean}
 */
export function shouldConfirmLeavingWikiPage(visit, currentPathname, hasUnsaved) {
    if (! hasUnsaved || String(visit?.method ?? 'get').toLowerCase() !== 'get') {
        return false;
    }

    let pathname;

    try {
        pathname = visit.url instanceof URL ? visit.url.pathname : new URL(String(visit.url), 'http://localhost').pathname;
    } catch {
        return true;
    }

    return pathname !== currentPathname;
}
