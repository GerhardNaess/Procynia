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
