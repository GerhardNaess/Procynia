import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * Where keyboard focus goes when something on the page appears or disappears.
 *
 * A keyboard user is wherever focus is. When the button they pressed unmounts — an inline form
 * replaces its own "Ny …" trigger, a confirm panel replaces "Slett", a dialog closes after the action
 * that removed its trigger — focus falls back to <body>, and their next Tab starts from the top of
 * the page (WCAG 2.4.3). These helpers put focus somewhere deliberate instead. ActionDialog uses them
 * for its own trap; inline panels use usePanelFocus.
 */

/** Everything the browser lets Tab land on, minus what is switched off. */
export const FOCUSABLE_SELECTOR = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled]):not([type="hidden"])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])',
].join(', ');

/** The focusable elements inside a container, in DOM (= Tab) order. */
export function focusableWithin(container) {
    return Array.from(container?.querySelectorAll(FOCUSABLE_SELECTOR) ?? []);
}

/**
 * The first element in `candidates` that is still in the document. A ref to a trigger that has
 * since been unmounted is skipped rather than focused into the void.
 *
 * @param {Array<HTMLElement|null|undefined>} candidates In order of preference.
 * @param {(element: HTMLElement) => boolean} isConnected Injected so the rule can be unit-tested.
 */
export function firstConnected(candidates, isConnected = (element) => element.isConnected) {
    return candidates.find((element) => element && typeof element.focus === 'function' && isConnected(element)) ?? null;
}

/**
 * Focus an element even if it is not focusable on its own — a panel or a heading becomes a
 * programmatic focus target (tabindex=-1) without joining the Tab order.
 */
export function focusElement(element, { preventScroll = false } = {}) {
    if (! element) {
        return false;
    }

    if (! element.matches?.(FOCUSABLE_SELECTOR) && ! element.hasAttribute?.('tabindex')) {
        element.setAttribute('tabindex', '-1');
    }

    element.focus({ preventScroll });

    return true;
}

/**
 * After a rejected submit, put the user on the first field that says it is wrong. Fields mark
 * themselves with aria-invalid (FormField and FilePickerField do), so this works for any form that
 * uses them, whatever its layout. Call it from Inertia's onError once the errors have rendered.
 */
export function focusFirstInvalidField(container = document) {
    const field = container?.querySelector?.('[aria-invalid="true"]');

    return focusElement(field);
}

/** Whether an Inertia/Laravel error bag holds anything. Pure, so it is unit-tested. */
export function hasErrors(errors) {
    return Boolean(errors) && Object.values(errors).some((message) => Boolean(message));
}

/**
 * Where focus goes after a submit was turned down (3.3.1, A11Y-013, A11Y-133): the first field that
 * says it is wrong, scrolled into view by the browser — so the error is seen and heard where it has
 * to be fixed, not in a banner at the top of a page scrolled out of sight. When no field carries the
 * error (it is about the form as a whole), the form's error summary takes focus instead: give it
 * `data-form-error-summary` and it is announced by its text.
 *
 * It moves focus only when asked: `requestFocus` from Inertia's onError, or on mount when the page
 * arrives with errors from a plain POST form (the redirect back *is* the answer to the submit). Errors
 * that appear or change while someone types never move focus — that was the cause of earlier
 * dropdown regressions, and is why this is not a global behaviour.
 *
 * The request is a counter in state rather than a direct call: Inertia calls onError before React
 * has rendered the new errors, and the aria-invalid attributes exist only after that render.
 *
 * @param {{ current: HTMLElement|null }} containerRef The form (or the dialog holding it).
 * @param {{ errorsOnMount?: object }} [options] The page's error bag, for forms that post natively.
 * @returns {() => void} requestFocus
 */
export function useFocusFirstError(containerRef, { errorsOnMount = null } = {}) {
    const [request, setRequest] = useState(() => (hasErrors(errorsOnMount) ? 1 : 0));

    useEffect(() => {
        if (request === 0) {
            return;
        }

        const container = containerRef?.current;

        if (! focusFirstInvalidField(container)) {
            focusElement(container?.querySelector?.('[data-form-error-summary]'));
        }
    }, [request, containerRef]);

    return useCallback(() => setRequest((count) => count + 1), []);
}

/**
 * Focus for an inline panel that opens in place — an "add" form, a confirm row, an edit section.
 *
 * When `isOpen` turns true, focus moves into the panel: its first field, or the panel itself if it
 * has none (give it an aria-labelledby so it is announced by name). When `isOpen` turns false, focus
 * returns to the trigger — usually a fresh element, because the trigger re-mounts as the panel goes —
 * or to `fallbackRef` if the trigger is gone for good (the action removed the row it lived in).
 *
 * Only transitions move focus. A panel that is open on first render does not steal focus from the
 * page.
 *
 * @param {boolean} isOpen
 * @param {{ panelRef: object, returnFocusRef?: object, fallbackRef?: object, initialFocus?: 'first-field'|'panel' }} options
 */
export function usePanelFocus(isOpen, { panelRef, returnFocusRef, fallbackRef, initialFocus = 'first-field' }) {
    const wasOpen = useRef(isOpen);

    useEffect(() => {
        if (isOpen === wasOpen.current) {
            return;
        }

        wasOpen.current = isOpen;

        if (isOpen) {
            const panel = panelRef?.current;
            const target = initialFocus === 'panel' ? panel : (focusableWithin(panel)[0] ?? panel);
            focusElement(target);

            return;
        }

        focusElement(firstConnected([returnFocusRef?.current, fallbackRef?.current]));
    }, [isOpen, panelRef, returnFocusRef, fallbackRef, initialFocus]);
}
