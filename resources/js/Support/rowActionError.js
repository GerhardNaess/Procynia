import { useSyncExternalStore } from 'react';

/**
 * Where the answer to a refused row action is shown (WCAG 3.3.1; A11Y-137).
 *
 * «Deaktiver» on a row far down a table can be refused by the server — «Kunden må ha minst én aktiv
 * systemeier» — and the refusal comes back as a flash error. The layout shows it as a banner at the
 * top of the content (FlashMessages, role="alert"): a screen reader hears it at once, but a sighted
 * user who pressed the button lower down sees nothing happen. This puts the same message next to the
 * button that was pressed, and ties it to that button with aria-describedby.
 *
 * What it deliberately does not do:
 *   - announce anything. The banner is already the one role="alert" for this event; a second live
 *     region here would make a screen reader say it twice. The local text is the visible context,
 *     and the description a screen reader hears when it returns to the button;
 *   - move focus or scroll. Focus stays on the button (Support/routeFocus.js puts it back if the
 *     page re-renders the row), and the message appears where the user already is.
 *
 * The record lives outside React, because a non-GET Inertia visit re-mounts the page: component
 * state from before the request would be gone when the answer arrives. A message is shown only
 * while the page's flash still carries it, so the next visit — success or anything else — clears it.
 */

let current = null; // { key, message }
const listeners = new Set();

function emit() {
    for (const listener of listeners) {
        listener();
    }
}

function subscribe(listener) {
    listeners.add(listener);

    return () => listeners.delete(listener);
}

const snapshot = () => current;

/**
 * Visit options for a row action identified by `key`. Spread them into the router call; an
 * onSuccess of your own is still called.
 *
 * @param {string} key   Unique per row and action, e.g. `user-toggle-12`.
 * @param {object} [options] The visit's own options.
 */
export function trackRowAction(key, options = {}) {
    current = null;
    emit();

    return {
        ...options,
        onSuccess: (page) => {
            const message = page?.props?.flash?.error;

            current = message ? { key, message } : null;
            emit();
            options.onSuccess?.(page);
        },
    };
}

/** Pure: the message for `key`, if it is the refusal the current page still shows. Unit-tested. */
export function rowErrorFor(record, key, flashError) {
    return record && record.key === key && flashError && record.message === flashError ? record.message : null;
}

/** For tests: forget what was recorded. */
export function resetRowActionError() {
    current = null;
    emit();
}

/**
 * @param {string|null} flashError The current page's `flash.error` (usePage().props.flash?.error).
 * @returns {(key: string) => string|null} The refusal to show beside the row action `key`, or null.
 */
export function useRowActionError(flashError) {
    const record = useSyncExternalStore(subscribe, snapshot, snapshot);

    return (key) => rowErrorFor(record, key, flashError);
}

/** The id the message gets, for the button's aria-describedby. */
export const rowErrorId = (key) => `row-action-error-${key}`;
