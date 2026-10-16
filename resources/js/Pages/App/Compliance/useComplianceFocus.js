import { useEffect, useRef } from 'react';
import { announce } from '../../../Support/announcer.js';
import { focusElement, focusableWithin, refocusIfLost, useFocusFirstError } from '../../../Support/focusManagement.js';
import { inPageTargetId, panelTransition, rejectedSubmitStep } from './complianceA11y.js';

/**
 * Focus for the inline panels of Etterlevelse og revisjon (WCAG 2.4.3, A11Y-014), where one state
 * value says which panel is open — 'edit', 'start', 'create', 'edit-12', 'handoff-12' … — and the
 * button that opened it is hidden while it is open.
 *
 * When a panel opens, focus moves to its first field: the element inside `containerRef` marked
 * data-inline-panel="<key>". When it closes — Avbryt, or saved — focus goes back to the first of
 * `returnTargets(key)` that exists, but only if focus was really lost with the panel. Only changes
 * move focus; the first render and re-renders with the same panel leave it where it is.
 *
 * @param {string|null} panel
 * @param {{ containerRef: object, returnTargets: (key: string) => string[] }} options
 */
export function usePanelSwitchFocus(panel, { containerRef, returnTargets }) {
    const previous = useRef(panel);
    const targets = useRef(returnTargets);
    targets.current = returnTargets;

    useEffect(() => {
        const change = panelTransition(previous.current, panel);
        previous.current = panel;

        if (! change) {
            return;
        }

        if (change.open !== undefined) {
            const element = containerRef.current?.querySelector?.(`[data-inline-panel="${change.open}"]`);

            if (element) {
                focusElement(focusableWithin(element)[0] ?? element);
            }

            return;
        }

        refocusIfLost(targets.current(change.close) ?? []);
    }, [panel, containerRef]);
}

/**
 * An in-page link (#finding-12, #check-3) that scrolls must also move focus there (2.4.3, A11Y-015):
 * otherwise the next Tab starts from the link, far from what the user went to. The browser scrolls;
 * this focuses the target once it is in place, without scrolling a second time.
 */
export function focusInPageTarget(hash) {
    const id = inPageTargetId(hash);

    if (! id || typeof window === 'undefined') {
        return;
    }

    window.requestAnimationFrame(() => focusElement(document.getElementById(id), { preventScroll: true }));
}

/**
 * Speaks `message` once the user has stopped typing for `delay` ms, when `key` (the search and
 * filter) has changed since the last time — never on the first render (4.1.3, A11Y-111).
 */
export function useSettledAnnouncement(key, message, delay = 800) {
    const announced = useRef(key);

    useEffect(() => {
        if (key === announced.current) {
            return undefined;
        }

        const timer = window.setTimeout(() => {
            announced.current = key;

            if (message) {
                announce(message);
            }
        }, delay);

        return () => window.clearTimeout(timer);
    }, [key, message, delay]);
}

/**
 * After a submit the server turned down, focus the first field that says it is wrong (3.3.1,
 * A11Y-013) — for an Inertia useForm whose post the page (or a parent) makes, without touching its
 * visit options. A submit is the processing flag going on; its answer is the flag going off with
 * errors. Errors only ever arrive that way, so typing, re-renders and clearErrors never move focus.
 *
 * @param {{ processing: boolean, errors: object }} form  An Inertia useForm.
 * @param {{ current: HTMLElement|null }} containerRef      The <form>.
 */
export function useFocusOnRejectedSubmit(form, containerRef) {
    const requestFocus = useFocusFirstError(containerRef);
    const pending = useRef(false);

    useEffect(() => {
        const step = rejectedSubmitStep(pending.current, form.processing, form.errors);
        pending.current = step.pending;

        if (step.focus) {
            requestFocus();
        }
    }, [form.processing, form.errors, requestFocus]);
}
