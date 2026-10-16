import { useEffect, useRef } from 'react';
import { focusableWithin, focusElement, refocusIfLost } from '../../../Support/focusManagement.js';

/**
 * Focus for an inline panel in Risiko and Avvik og forbedringer (2.4.3, A11Y-014): an add form, an
 * edit section, a lifecycle form. Like usePanelFocus, but the way back is a list of element ids,
 * resolved after the panel has closed. The trigger that opened the panel is hidden while it is open
 * and comes back as a new element — or not at all, when the action changed what the item offers
 * («Marker som fullført» becomes «Gjenåpne») — so the first id still in the page wins.
 *
 *   - Opening moves focus into the panel: its first field, or the panel itself (`initialFocus:
 *     'panel'`, for a form that starts with a choice the user should hear in context first).
 *   - Closing — Avbryt, or a save that closes it — puts focus on the first of `returnIds` still in
 *     the page, but only if focus was lost with the panel. Someone who has moved on is left alone.
 *   - A panel open on the first render does not take focus.
 *
 * @param {boolean} isOpen
 * @param {{ panelRef: object, returnIds?: string[], initialFocus?: 'first-field'|'panel' }} options
 */
export default function useInlinePanelFocus(isOpen, { panelRef, returnIds = [], initialFocus = 'first-field' }) {
    const wasOpen = useRef(isOpen);
    const returnIdsRef = useRef(returnIds);
    returnIdsRef.current = returnIds;

    useEffect(() => {
        if (isOpen === wasOpen.current) {
            return;
        }

        wasOpen.current = isOpen;

        if (isOpen) {
            const panel = panelRef?.current;
            focusElement(initialFocus === 'panel' ? panel : (focusableWithin(panel)[0] ?? panel));

            return;
        }

        refocusIfLost(returnIdsRef.current);
    }, [isOpen, panelRef, initialFocus]);
}
